<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Public email-only renewal of a paid guest's password setup link.
 *
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// Anonymous purchasers need this page before signing in. POST uses Moodle form and sesskey protection.
// phpcs:disable moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');

use local_moderncommerce\services\guest_access_service;

$token = optional_param('token', '', PARAM_ALPHANUM);
$url = new moodle_url('/local/moderncommerce/guest_access.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('guestaccesstitle', 'local_moderncommerce'));
$PAGE->set_heading(get_string('guestaccesstitle', 'local_moderncommerce'));
$PAGE->set_cacheable(false);
$PAGE->add_body_class('mc-learner-layout-wide');
$PAGE->add_body_class('mc-learner-dashboard-layout');
$PAGE->add_body_class('mc-guest-checkout-layout');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

$sent = !empty($SESSION->moderncommerce_guest_access_sent);
unset($SESSION->moderncommerce_guest_access_sent);
$available = !$sent && guest_access_service::available($token);
$form = $available ? new \local_moderncommerce\form\guest_access($url, ['token' => $token]) : null;
$error = '';
if ($form && ($data = $form->get_data())) {
    try {
        guest_access_service::request($data->token);
        $SESSION->moderncommerce_guest_access_sent = true;
        redirect($url);
    } catch (moodle_exception $e) {
        $error = $e->getMessage();
    }
}
$view = [
    'sent' => $sent,
    'available' => $available,
    'invalid' => !$available && !$sent,
    'formhtml' => $form ? $form->render() : '',
    'errorhtml' => $error ? $OUTPUT->notification($error, 'error') : '',
    'learningurl' => (new moodle_url('/local/moderncommerce/learner/index.php'))->out(false),
    'supporturl' => (new moodle_url('/local/moderncommerce/support.php'))->out(false),
];
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_moderncommerce/guest_access', $view);
echo $OUTPUT->footer();
