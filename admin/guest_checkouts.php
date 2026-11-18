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
 * Recovery report for anonymous purchases.
 *
 * @package    local_moderncommerce
 * @copyright  2026 Adebare Showemimo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

use local_moderncommerce\services\guest_checkout_service as guest;

require_login();
$PAGE->set_context(context_system::instance());
require_capability('local/moderncommerce:managesettings', context_system::instance());
$url = new moodle_url('/local/moderncommerce/admin/guest_checkouts.php');
$PAGE->set_url($url);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('guestcheckoutrecovery', 'local_moderncommerce'));
$PAGE->set_heading(get_string('guestcheckoutrecovery', 'local_moderncommerce'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $record = $DB->get_record(guest::TABLE, ['id' => required_param('id', PARAM_INT)], '*', MUST_EXIST);
    try {
        if (required_param('action', PARAM_ALPHA) === 'resend') {
            guest::resend((int)$record->id);
        } else if ($record->status === 'pending') {
            guest::reconcile($record);
        } else {
            guest::fulfil((int)$record->id);
        }
        redirect($url);
    } catch (Throwable $e) {
        \core\notification::error(get_string('guestcheckoutrecovering', 'local_moderncommerce'));
    }
}
ob_start();
echo html_writer::link(
    new moodle_url('/local/moderncommerce/admin/settings.php', ['tab' => 'checkout']),
    get_string('settings'),
    ['class' => 'mc-button mc-btn-soft mb-3']
);
$page = max(0, optional_param('page', 0, PARAM_INT));
$table = new html_table();
$table->head = [get_string('idnumber'), get_string('email'), get_string('status'), get_string('date'), get_string('actions')];
$table->attributes['class'] = 'table table-bordered';
$records = $DB->get_records(guest::TABLE, [], 'timecreated DESC', '*', $page * 50, 50);
foreach ($records as $record) {
    $actions = '';
    foreach (['retry' => 'refresh', 'resend' => 'guestcheckoutresend'] as $action => $label) {
        $actions .= html_writer::tag(
            'form',
            html_writer::input_hidden_params($url) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $record->id]) .
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => $action]) .
            html_writer::tag(
                'button',
                s(get_string($label, $action === 'retry' ? 'moodle' : 'local_moderncommerce')),
                ['type' => 'submit', 'class' => 'mc-button mc-btn-soft mc-btn-sm']
            ),
            ['method' => 'post', 'action' => $url->out(false), 'class' => 'd-inline-block me-2']
        );
    }
    $table->data[] = [(int)$record->id, s($record->email),
        s(get_string('guestcheckoutstatus_' . $record->status, 'local_moderncommerce')) .
            ($record->lasterror ? html_writer::tag('small', s($record->lasterror), ['class' => 'd-block']) : ''),
        userdate($record->timecreated), $actions];
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($DB->count_records(guest::TABLE), $page, 50, $url);
$content = ob_get_clean();
echo $OUTPUT->header();
echo \local_moderncommerce\output\admin_shell::render_page(
    $OUTPUT,
    'settings',
    get_string('guestcheckoutrecovery', 'local_moderncommerce'),
    $content
);
echo $OUTPUT->footer();
