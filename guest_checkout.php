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
 * Public guest cart, checkout, payment confirmation, and authenticated purchase claim.
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// This page intentionally accepts anonymous buyers. Writes require a session key.
// phpcs:disable moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

use local_moderncommerce\services\guest_checkout_service as guest;
use local_moderncommerce\services\pricing_service;
use local_moderncommerce\api\cart_api;

$token = optional_param('token', '', PARAM_ALPHANUM);
$claim = optional_param('claim', 0, PARAM_BOOL);
$courseid = optional_param('courseid', 0, PARAM_INT);
$bundleid = optional_param('bundleid', 0, PARAM_INT);
$url = new moodle_url(
    '/local/moderncommerce/guest_checkout.php',
    array_filter(['token' => $token, 'claim' => $claim, 'courseid' => $courseid, 'bundleid' => $bundleid])
);
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('guestcheckout', 'local_moderncommerce'));
$PAGE->set_heading(get_string('guestcheckout', 'local_moderncommerce'));
$PAGE->set_cacheable(false);
$PAGE->add_body_class('mc-learner-layout-wide');
$PAGE->add_body_class('mc-learner-dashboard-layout');
$PAGE->add_body_class('mc-guest-checkout-layout');
header('Referrer-Policy: no-referrer');

$postbutton = static function (string $action, string $label, array $params = []) use ($url): string {
    $inputs = html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    foreach (array_merge(['action' => $action], $params) as $name => $value) {
        $inputs .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    return html_writer::tag('form', $inputs . html_writer::tag(
        'button',
        s($label),
        ['type' => 'submit', 'class' => 'mc-button mc-btn-soft']
    ), ['method' => 'post', 'action' => $url->out(false)]);
};

$view = [
    'actionurl' => $url->out(false),
    'catalogurl' => (new moodle_url('/local/moderncommerce/index.php'))->out(false),
    'loginurl' => (new moodle_url('/login/index.php', ['wantsurl' => $url->out(false)]))->out(false),
    'sesskey' => sesskey(),
];

$error = '';
$record = $token ? guest::by_token($token) : null;
if ($token && !$record) {
    throw new moodle_exception('guestcheckoutinvalidlink', 'local_moderncommerce');
}
if ($claim) {
    require_login();
    if (
        !$record || $record->mailsent < time() - 7 * DAYSECS ||
            core_text::strtolower($USER->email) !== $record->email
    ) {
        throw new moodle_exception('guestcheckoutclaimaccount', 'local_moderncommerce');
    }
}

if ($record) {
    try {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            require_sesskey();
            $action = required_param('action', PARAM_ALPHA);
            if ($action === 'claim' && $claim) {
                guest::fulfil((int)$record->id, (int)$USER->id);
            } else if ($action === 'resend') {
                guest::resend((int)$record->id);
            }
            redirect($url);
        }
        if ($record->status === 'pending') {
            guest::reconcile($record);
        }
    } catch (Throwable $e) {
        $error = get_string('guestcheckoutrecovering', 'local_moderncommerce');
    }
    $record = $DB->get_record(guest::TABLE, ['id' => $record->id], '*', MUST_EXIST);
    $view['ispaymentstatus'] = true;
    $view['statustitle'] = get_string('guestcheckoutstatus_' . $record->status, 'local_moderncommerce');
    $view['errorhtml'] = $error ? $OUTPUT->notification($error, 'warning') : '';
    $view['refreshurl'] = $url->out(false);
    $view['statusactions'] = '';
    if ($record->status === 'claim') {
        if ($claim) {
            $view['statusactions'] .= $postbutton('claim', get_string('guestcheckoutclaim', 'local_moderncommerce'));
        } else {
            $view['claimmessage'] = get_string('guestcheckoutcheckemail', 'local_moderncommerce');
        }
    }
    if (in_array($record->status, ['paid', 'claim', 'fulfilled'], true)) {
        $view['statusactions'] .= $postbutton('resend', get_string('guestcheckoutresend', 'local_moderncommerce'));
    }
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_moderncommerce/guest_checkout', $view);
    echo $OUTPUT->footer();
    exit;
}

if (!guest::enabled()) {
    redirect(new moodle_url('/login/index.php', [
        'wantsurl' => (new moodle_url('/local/moderncommerce/checkout.php'))->out(false),
    ]));
}
require_capability('local/moderncommerce:viewcatalog', context_system::instance(), $CFG->siteguest);
if (isloggedin() && !isguestuser()) {
    // Preserve the anonymous cart through a voluntary login; never share it across sessions.
    guest::merge_cart((int)$USER->id);
    redirect(new moodle_url('/local/moderncommerce/checkout.php', array_filter([
        'courseid' => $courseid, 'bundleid' => $bundleid,
    ])));
}
$form = new \local_moderncommerce\form\guest_checkout($url, null, 'post', '', [
    'id' => 'mc-guest-details-form',
]);
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        guest::cart_userid();
        $action = required_param('action', PARAM_ALPHA);
        if ($action === 'add') {
            if ($courseid) {
                cart_api::add_to_cart(0, $courseid);
            } else if ($bundleid) {
                cart_api::add_bundle_to_cart(0, $bundleid);
            }
            redirect(new moodle_url('/local/moderncommerce/guest_checkout.php'));
        } else if ($action === 'remove') {
            \local_moderncommerce\external\checkout\checkout_helper::remove_cart_item(0, required_param('itemid', PARAM_INT));
            redirect($url);
        } else if ($action === 'clear') {
            cart_api::clear_cart(0);
            redirect($url);
        } else if ($action === 'pay' && ($data = $form->get_data())) {
            $payment = guest::start((array)$data);
            redirect($payment['url']);
        }
    }
} catch (Throwable $e) {
    $error = $e instanceof moodle_exception ? $e->getMessage() : get_string('guestcheckoutunavailable', 'local_moderncommerce');
}
$cart = \local_moderncommerce\external\checkout\checkout_helper::cart_data(0, true);
$view += $cart;
$view['hasitems'] = !$cart['isempty'];
$view['hastax'] = (float)$cart['totals']['taxraw'] > 0;
$view['errorhtml'] = $error ? $OUTPUT->notification($error, 'error') : '';
$view['formhtml'] = $view['hasitems'] ? $form->render() : '';
foreach ($view['items'] as &$item) {
    $item['actionurl'] = $url->out(false);
    $item['sesskey'] = sesskey();
    $item['removelabel'] = get_string('removefromcart', 'local_moderncommerce') . ': ' . $item['name'];
}
unset($item);
if ($courseid || $bundleid) {
    $incart = false;
    foreach ($view['items'] as $item) {
        $incart = $incart || ($courseid && (int)$item['courseid'] === $courseid)
            || ($bundleid && (int)$item['productid'] === $bundleid);
    }
    $price = $courseid
        ? \local_moderncommerce\services\price_resolver::resolve_for_course($courseid, 1, true)
        : \local_moderncommerce\services\price_resolver::resolve_for_product($bundleid, 1, true);
    if ($price && !$incart) {
        $view['directitem'] = [
            'name' => format_string($price->productname, true, ['escape' => false]),
            'price' => pricing_service::format_price($price->unitprice),
            'actionurl' => $url->out(false),
            'sesskey' => sesskey(),
        ];
    }
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_moderncommerce/guest_checkout', $view);
echo $OUTPUT->footer();
