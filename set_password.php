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
 * Branded password setup for paid guest purchasers, using native Moodle reset tokens.
 *
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// A validated email token authorises this public form before the learner can sign in.
// phpcs:disable moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/login/lib.php');

use local_moderncommerce\services\guest_password_service;

$token = optional_param('token', '', PARAM_ALPHANUM);
$renew = optional_param('renew', '', PARAM_ALPHANUM);
$url = new moodle_url('/local/moderncommerce/set_password.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('mcpasswordtitle', 'local_moderncommerce'));
$PAGE->set_heading(get_string('mcpasswordtitle', 'local_moderncommerce'));
$PAGE->set_cacheable(false);
$PAGE->add_body_class('mc-learner-layout-wide');
$PAGE->add_body_class('mc-learner-dashboard-layout');
$PAGE->add_body_class('mc-guest-checkout-layout');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

// Remove capabilities from the address bar before rendering any form or page assets.
if ($token && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $SESSION->moderncommerce_password_setup = ['token' => $token, 'renew' => $renew];
    redirect($url);
}
if (!$token && !empty($SESSION->moderncommerce_password_setup)) {
    $token = $SESSION->moderncommerce_password_setup['token'];
    $renew = $SESSION->moderncommerce_password_setup['renew'];
}
$inspection = guest_password_service::inspect($token);
$state = $inspection['state'];
$user = $inspection['user'];
if ($user && isloggedin() && !isguestuser() && (int)$USER->id !== (int)$user->id) {
    $state = 'differentuser';
    $user = null;
}
$form = $user ? new \local_moderncommerce\form\set_password($url, $user) : null;
$error = '';
if ($form) {
    $form->set_data((object)[
        'username' => $user->username, 'username2' => s($user->username), 'token' => $token, 'renew' => $renew,
    ]);
    if ($form->is_cancelled()) {
        unset($SESSION->moderncommerce_password_setup);
        redirect(new moodle_url('/local/moderncommerce/learner/index.php'));
    }
    if ($data = $form->get_data()) {
        try {
            $user = guest_password_service::save(
                $data->token,
                $data->password,
                $data->password2,
                !empty($data->logoutothersessions)
            );
            unset($SESSION->moderncommerce_password_setup, $SESSION->lang);
            complete_user_login($user);
            \core\session\manager::apply_concurrent_login_limit($user->id, session_id());
            $SESSION->wantsurl = (new moodle_url('/local/moderncommerce/learner/index.php'))->out(false);
            $destination = core_login_get_return_url();
            core_login_post_set_password_requests($data, $user);
            redirect($destination, get_string('passwordset'), 1);
        } catch (moodle_exception $e) {
            $error = $e->getMessage();
        }
    }
}
$view = [
    'available' => $state === 'valid',
    'unavailable' => $state !== 'valid',
    'statetitle' => get_string('mcpasswordstate_' . $state, 'local_moderncommerce'),
    'statemessage' => $state === 'valid' ? '' : get_string('mcpasswordmessage_' . $state, 'local_moderncommerce'),
    'formhtml' => $form ? $form->render() : '',
    'errorhtml' => $error ? $OUTPUT->notification($error, 'error') : '',
    'renewalurl' => guest_password_service::renewal_url($token, $renew),
    'supporturl' => (new moodle_url('/local/moderncommerce/support.php'))->out(false),
    'learningurl' => (new moodle_url('/local/moderncommerce/learner/index.php'))->out(false),
];
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_moderncommerce/set_password', $view);
echo $OUTPUT->footer();
