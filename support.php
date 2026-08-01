<?php
// This file is part of Moodle and is licensed under the
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
 * Public support page for Modern Commerce buyers.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');
// phpcs:enable moodle.Files.RequireLogin.Missing

use local_moderncommerce\output\public_page;
use local_moderncommerce\services\captcha_service;
$context = context_system::instance();
public_page::require_enabled('support');
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moderncommerce/support.php'));
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('mc-public-storefront-page');
$PAGE->set_title(public_page::setting('support', 'title', 'Course purchase support'));
$PAGE->set_heading(public_page::setting('support', 'title', 'Course purchase support'));
$PAGE->set_blocks_editing_capability('local/moderncommerce:managestorefront');

$supportemail = public_page::support_email();
$categories = [
    'payment' => get_string('p1_support_category_payment', 'local_moderncommerce'),
    'access' => get_string('p1_support_category_access', 'local_moderncommerce'),
    'invoice' => get_string('p1_support_category_invoice', 'local_moderncommerce'),
    'refund' => get_string('p1_support_category_refund', 'local_moderncommerce'),
    'key' => get_string('p1_support_category_key', 'local_moderncommerce'),
    'subscription' => get_string('p1_support_category_subscription', 'local_moderncommerce'),
    'general' => get_string('p1_support_category_general', 'local_moderncommerce'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $captcharesult = captcha_service::verify(captcha_service::request_response());
    if ($captcharesult !== true) {
        \core\notification::error($captcharesult);
    } else {
        $name = required_param('name', PARAM_TEXT);
        $email = required_param('email', PARAM_EMAIL);
        $category = optional_param('category', 'general', PARAM_ALPHANUMEXT);
        $ordernumber = optional_param('ordernumber', '', PARAM_TEXT);
        $message = required_param('message', PARAM_TEXT);
        $categorylabel = $categories[$category] ?? $categories['general'];

        if ($supportemail === '') {
            \core\notification::error(get_string('p1_support_missingemail', 'local_moderncommerce'));
        } else {
            $recipient = \core_user::get_noreply_user();
            $recipient->email = $supportemail;
            $recipient->firstname = get_string('p1_support_recipient_firstname', 'local_moderncommerce');
            $recipient->lastname = '';

            $sender = isloggedin() && !isguestuser() ? $USER : \core_user::get_noreply_user();
            $subject = get_string('p1_support_email_subject', 'local_moderncommerce', (object) [
                'sitename' => format_string($SITE->shortname),
                'category' => $categorylabel,
            ]);
            $ordervalue = $ordernumber !== '' ? $ordernumber : '-';
            $messagetext = get_string('p1_support_email_heading', 'local_moderncommerce') . "\n\n"
                . get_string('p1_support_name', 'local_moderncommerce') . ": {$name}\n"
                . get_string('p1_support_email', 'local_moderncommerce') . ": {$email}\n"
                . get_string('p1_support_category', 'local_moderncommerce') . ": {$categorylabel}\n"
                . get_string('ordernumber', 'local_moderncommerce') . ": " . $ordervalue . "\n\n"
                . $message;
            $messagehtml = html_writer::tag('h2', get_string('p1_support_email_heading', 'local_moderncommerce'))
                . html_writer::tag('p', html_writer::tag(
                    'strong',
                    get_string('p1_support_name', 'local_moderncommerce') . ': '
                ) . s($name))
                . html_writer::tag('p', html_writer::tag(
                    'strong',
                    get_string('p1_support_email', 'local_moderncommerce') . ': '
                ) . s($email))
                . html_writer::tag('p', html_writer::tag(
                    'strong',
                    get_string('p1_support_category', 'local_moderncommerce') . ': '
                ) . s($categorylabel))
                . html_writer::tag('p', html_writer::tag(
                    'strong',
                    get_string('ordernumber', 'local_moderncommerce') . ': '
                ) . s($ordervalue))
                . html_writer::tag('hr', '')
                . html_writer::tag('p', nl2br(s($message)));

            $sent = email_to_user($recipient, $sender, $subject, $messagetext, $messagehtml, '', '', true, $email, $name);
            if ($sent) {
                \core\notification::success(get_string('p1_support_sent', 'local_moderncommerce'));
                redirect(new moodle_url('/local/moderncommerce/support.php'));
            } else {
                \core\notification::error(get_string('p1_support_sendfailed', 'local_moderncommerce'));
            }
        }
    }
}

echo $OUTPUT->header();
echo public_page::render_widget_page('support', $OUTPUT);
echo $OUTPUT->footer();
