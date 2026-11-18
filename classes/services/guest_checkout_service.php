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

namespace local_moderncommerce\services;

use local_moderncommerce\api\cart_api;
use local_moderncommerce\api\order_api;
use local_moderncommerce\api\pricing_api;
use local_moderncommerce\payment\gateway_manager;
use local_moderncommerce\payment\stripe_gateway;

/**
 * Anonymous cart ownership, payment snapshots, and recoverable fulfilment.
 *
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guest_checkout_service {
    /**
     * Guest payments never use Moodle's shared guest user as buyer.
     */
    public const TABLE = 'local_moderncommerce_guestpay';

    /**
     * Whether NEW anonymous purchases are allowed. Paid recovery ignores this switch.
     */
    public static function enabled(): bool {
        return (bool)get_config('local_moderncommerce', 'guest_checkout_enabled');
    }

    /**
     * A random server-session owner, unrelated to Moodle's authentication session ID.
     */
    public static function cart_token(): string {
        global $SESSION;
        if (empty($SESSION->moderncommerce_guest_cart)) {
            $SESSION->moderncommerce_guest_cart = bin2hex(random_bytes(32));
        }
        return hash('sha256', $SESSION->moderncommerce_guest_cart);
    }

    /**
     * Validate public write access or return the authenticated buyer's ID.
     */
    public static function cart_userid(): int {
        global $USER, $CFG;
        require_sesskey();
        if (isloggedin() && !isguestuser()) {
            return (int)$USER->id;
        }
        if (!self::enabled()) {
            throw new \moodle_exception('guestcheckoutdisabled', 'local_moderncommerce');
        }
        require_capability('local/moderncommerce:viewcatalog', \context_system::instance(), $CFG->siteguest);
        return 0;
    }

    /**
     * Resolve and freeze the current cart. No client-supplied prices are accepted.
     */
    public static function snapshot(): array {
        cart_api::refresh_cart_prices(0, true);
        $items = array_values(array_merge(cart_api::get_cart_items(0), cart_api::get_bundle_cart_items(0)));
        if (!$items || count($items) > 50) {
            throw new \moodle_exception('emptycart', 'local_moderncommerce');
        }
        foreach ($items as $item) {
            $price = price_resolver::resolve_for_product((int)$item->productid, 1, true);
            if (!$price || !in_array($price->producttype, ['course', 'bundle'], true) || !$price->has_stock) {
                throw new \moodle_exception('guestcheckoutunavailable', 'local_moderncommerce');
            }
        }
        $totals = pricing_api::calculate_cart_totals($items);
        foreach ($items as $item) {
            if (strtoupper($item->currency) !== strtoupper($totals['currency'])) {
                throw new \moodle_exception('guestcheckoutunavailable', 'local_moderncommerce');
            }
        }
        if ((float)$totals['total'] <= 0) {
            throw new \moodle_exception('guestcheckoutsignin', 'local_moderncommerce');
        }
        $courses = [];
        global $DB;
        foreach ($items as $item) {
            foreach (
                $DB->get_records(
                    'local_moderncommerce_product_courses',
                    ['productid' => $item->productid, 'relationtype' => 'included']
                ) as $course
            ) {
                $courses[] = ['courseid' => (int)$course->courseid, 'productid' => (int)$item->productid,
                    'enrolduration' => (int)($item->enrolduration ?? 0)];
            }
        }
        if (!$courses) {
            throw new \moodle_exception('guestcheckoutunavailable', 'local_moderncommerce');
        }
        return ['items' => $items, 'totals' => $totals, 'courses' => $courses,
            'cartid' => (int)cart_api::get_active_cart(0, false)->id];
    }

    /**
     * Stripe amounts use currency minor units, independent of display preferences.
     */
    public static function minor_amount(float $amount, string $currency): int {
        $zero = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];
        $three = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];
        $currency = strtoupper($currency);
        $factor = in_array($currency, $zero, true) ? 1 : (in_array($currency, $three, true) ? 1000 : 100);
        return (int)round($amount * $factor);
    }

    /**
     * Start one pending purchase per browser attempt; identity is created only after payment.
     */
    public static function start(array $billing): array {
        global $DB, $SESSION;
        if (!self::enabled()) {
            throw new \moodle_exception('guestcheckoutdisabled', 'local_moderncommerce');
        }
        self::cart_userid();
        $billing = [
            'firstname' => trim(clean_param($billing['firstname'] ?? '', PARAM_NOTAGS)),
            'lastname' => trim(clean_param($billing['lastname'] ?? '', PARAM_NOTAGS)),
            'email' => \core_text::strtolower(trim($billing['email'] ?? '')),
        ];
        if (
            !$billing['firstname'] || !$billing['lastname'] || !validate_email($billing['email']) ||
                \core_text::strlen($billing['email']) > 100 || email_is_not_allowed($billing['email'])
        ) {
            throw new \moodle_exception('guestcheckoutdetails', 'local_moderncommerce');
        }
        $config = gateway_manager::get_gateway_config('stripe');
        if (empty($config['enabled']) || empty($config['secret_key']) || empty($config['webhook_secret'])) {
            throw new \moodle_exception('guestcheckoutunavailable', 'local_moderncommerce');
        }
        if (!is_enabled_auth('manual') || (!empty($config['testmode']) && !str_contains($config['secret_key'], '_test_'))) {
            throw new \moodle_exception('guestcheckoutunavailable', 'local_moderncommerce');
        }
        $lock = self::lock('start:' . self::cart_token());
        try {
            $snapshot = self::snapshot();
            $snapshot['billing'] = $billing;
            $snapshot['testmode'] = str_contains($config['secret_key'], '_test_');
            $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
            // A repeated submit reuses the exact same Stripe session instead of charging twice.
            if (!empty($SESSION->moderncommerce_guest_payment)) {
                $previous = self::by_token($SESSION->moderncommerce_guest_payment);
                if (
                    $previous && $previous->status === 'pending' && $previous->payload === $json &&
                        $previous->timecreated > time() - 50 * MINSECS
                ) {
                    $session = $previous->stripeid
                        ? (new stripe_gateway())->retrieve_guest_session($previous->stripeid)
                        : (new stripe_gateway())->create_guest_session(
                            $previous,
                            $snapshot,
                            $SESSION->moderncommerce_guest_payment
                        );
                    if (($session['status'] ?? '') === 'open') {
                        $DB->set_field(self::TABLE, 'stripeid', $session['id'], ['id' => $previous->id]);
                        return ['record' => $previous, 'url' => $session['url']];
                    }
                }
            }
            if (
                $DB->count_records_select(
                    self::TABLE,
                    'iphash = :ip AND timecreated > :since',
                    ['ip' => self::ip_hash(), 'since' => time() - HOURSECS]
                ) >= 20 ||
                    $DB->count_records_select(
                        self::TABLE,
                        'sessionhash = :owner AND timecreated > :since',
                        ['owner' => self::cart_token(), 'since' => time() - HOURSECS]
                    ) >= 10 ||
                    $DB->count_records_select(
                        self::TABLE,
                        'email = :email AND timecreated > :since',
                        ['email' => $billing['email'], 'since' => time() - HOURSECS]
                    ) >= 10
            ) {
                throw new \moodle_exception('guestcheckoutlimited', 'local_moderncommerce');
            }
            $token = bin2hex(random_bytes(32));
            $record = (object)[
                'tokenhash' => hash('sha256', $token), 'sessionhash' => self::cart_token(),
                'iphash' => self::ip_hash(),
                'email' => $billing['email'], 'payload' => $json, 'status' => 'pending',
                'timecreated' => time(), 'timemodified' => time(),
            ];
            $record->id = $DB->insert_record(self::TABLE, $record);
            $SESSION->moderncommerce_guest_payment = $token;
            $session = (new stripe_gateway())->create_guest_session($record, $snapshot, $token);
            $DB->set_field(self::TABLE, 'stripeid', $session['id'], ['id' => $record->id]);
            return ['record' => $record, 'url' => $session['url']];
        } finally {
            $lock->release();
        }
    }

    /**
     * Public confirmation is a bearer token, not a login or an order ID.
     */
    public static function by_token(string $token): ?object {
        global $DB;
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        return $DB->get_record_select(
            self::TABLE,
            'tokenhash = :token OR claimhash = :claim',
            ['token' => hash('sha256', $token), 'claim' => hash('sha256', $token)]
        ) ?: null;
    }

    /**
     * Consume only Stripe session data fetched by our gateway using our API key.
     */
    public static function confirm_session(array $session): bool {
        global $DB;
        $id = (int)($session['metadata']['guest_checkout_id'] ?? 0);
        $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        $snapshot = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
        if (
            ($session['id'] ?? '') !== $record->stripeid ||
                ($session['client_reference_id'] ?? '') !== 'guest:' . $record->id ||
                (bool)($session['livemode'] ?? false) === (bool)$snapshot['testmode'] ||
                ($session['mode'] ?? '') !== 'payment' ||
                (int)($session['amount_total'] ?? -1) !== self::minor_amount(
                    $snapshot['totals']['total'],
                    $snapshot['totals']['currency']
                ) ||
                strtoupper($session['currency'] ?? '') !== strtoupper($snapshot['totals']['currency'])
        ) {
            throw new \moodle_exception('guestcheckoutverification', 'local_moderncommerce');
        }
        if (($session['payment_status'] ?? '') !== 'paid' || ($session['status'] ?? '') !== 'complete') {
            return false;
        }
        $lock = self::lock('payment:' . $record->id);
        try {
            $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            if ($record->status === 'refunded') {
                return true;
            }
            $record->paymentdata = json_encode([
                'id' => $session['id'], 'payment_intent' => $session['payment_intent'],
                'amount_total' => $session['amount_total'], 'currency' => $session['currency'],
            ], JSON_THROW_ON_ERROR);
            $firstpayment = $record->status === 'pending';
            if ($firstpayment) {
                $record->status = 'paid';
            }
            $record->timemodified = time();
            $DB->update_record(self::TABLE, $record);
            // Remove only purchased lines; a buyer may have added other courses
            // in a second tab while Stripe Checkout was open.
            $cart = !empty($snapshot['cartid']) ? $DB->get_record('local_moderncommerce_carts', [
                'id' => (int)$snapshot['cartid'], 'userid' => 0, 'sessionid' => $record->sessionhash,
            ]) : null;
            if ($cart && $firstpayment) {
                foreach ($snapshot['items'] as $item) {
                    $DB->delete_records(
                        'local_moderncommerce_cart_items',
                        ['cartid' => $cart->id, 'productid' => (int)$item['productid']]
                    );
                }
            }
        } finally {
            $lock->release();
        }
        self::fulfil($id);
        return true;
    }

    /**
     * Reconcile a browser return or cron recovery with Stripe; no browser status is trusted.
     */
    public static function reconcile(object $record): bool {
        if (!$record->stripeid) {
            return false;
        }
        $session = (new stripe_gateway())->retrieve_guest_session($record->stripeid);
        if (($session['status'] ?? '') === 'expired' && $record->status === 'pending') {
            global $DB;
            $DB->set_field(self::TABLE, 'status', 'expired', ['id' => $record->id, 'status' => 'pending']);
            return false;
        }
        return self::confirm_session($session);
    }

    /**
     * Merge a private anonymous cart when its owner voluntarily signs in.
     */
    public static function merge_cart(int $userid): void {
        $items = array_merge(cart_api::get_cart_items(0), cart_api::get_bundle_cart_items(0));
        foreach ($items as $item) {
            try {
                if (!empty($item->courseid)) {
                    cart_api::add_to_cart($userid, (int)$item->courseid);
                } else {
                    cart_api::add_bundle_to_cart($userid, (int)$item->productid);
                }
            } catch (\moodle_exception $e) {
                if (
                    !in_array($e->errorcode, ['coursealreadyincart', 'bundlealreadyincart', 'alreadyenrolled',
                        'coursealreadyaccessible', 'productalreadyaccessible', 'coursealreadypurchased',
                        'bundlealreadypurchased'], true)
                ) {
                    throw $e;
                }
            }
        }
        cart_api::clear_cart(0);
    }

    /**
     * Retry account email delivery, with a server-side cooldown that also covers bearer links.
     */
    public static function resend(int $id): void {
        global $DB;
        $lock = self::lock('payment:' . $id);
        try {
            $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            if (!in_array($record->status, ['paid', 'claim', 'fulfilled'], true)) {
                return;
            }
            if (max($record->mailsent, $record->timemodified) > time() - MINSECS) {
                throw new \moodle_exception('guestcheckoutlimited', 'local_moderncommerce');
            }
            $record->mailsent = 0;
            $record->timemodified = time();
            $DB->update_record(self::TABLE, $record);
        } finally {
            $lock->release();
        }
        self::fulfil($id);
    }

    /**
     * Serialise full refunds with provisioning so an unclaimed refund never grants access.
     */
    public static function record_refund(array $charge): ?int {
        global $DB;
        $id = (int)($charge['metadata']['guest_checkout_id'] ?? 0);
        $lock = self::lock('payment:' . $id);
        try {
            $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            if (!empty($charge['refunded'])) {
                $DB->set_field(self::TABLE, 'status', 'refunded', ['id' => $id]);
            }
            return $record->orderid ? (int)$record->orderid : null;
        } finally {
            $lock->release();
        }
    }

    /**
     * Persisted payment precedes this method, so exceptions remain recoverable by cron.
     */
    public static function fulfil(int $id, ?int $claimuserid = null): void {
        global $DB, $CFG;
        $lock = self::lock('payment:' . $id);
        $emaillock = null;
        try {
            $record = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            if (!in_array($record->status, ['paid', 'claim', 'fulfilled'], true)) {
                return;
            }
            if ($record->status === 'fulfilled') {
                self::send_access_email($record);
                return;
            }
            $emaillock = self::lock('email:' . hash('sha256', $record->email));
            $snapshot = json_decode($record->payload, false, 512, JSON_THROW_ON_ERROR);
            if (!$record->userid) {
                $users = $DB->get_records_select(
                    'user',
                    'LOWER(email) = :email AND deleted = 0 AND mnethostid = :host',
                    ['email' => $record->email, 'host' => $CFG->mnet_localhost_id]
                );
                if ($users) {
                    if (
                        !$claimuserid || !isset($users[$claimuserid]) || $users[$claimuserid]->suspended ||
                            empty($users[$claimuserid]->confirmed)
                    ) {
                        $record->status = 'claim';
                        $DB->update_record(self::TABLE, $record);
                        self::send_access_email($record);
                        return;
                    }
                    $record->userid = $claimuserid;
                } else {
                    if (!is_enabled_auth('manual')) {
                        throw new \moodle_exception('guestcheckoutmanualauth', 'local_moderncommerce');
                    }
                    require_once($CFG->dirroot . '/user/lib.php');
                    $transaction = $DB->start_delegated_transaction();
                    $record->userid = user_create_user((object)[
                        'auth' => 'manual', 'confirmed' => 1, 'mnethostid' => $CFG->mnet_localhost_id,
                        'username' => 'buyer_' . bin2hex(random_bytes(16)),
                        // Core rejects this sentinel for every password login. The
                        // emailed reset link establishes the first usable password.
                        'password' => AUTH_PASSWORD_NOT_CACHED, 'email' => $record->email,
                        'firstname' => $snapshot->billing->firstname, 'lastname' => $snapshot->billing->lastname,
                        'lang' => $CFG->lang ?: 'en', 'maildisplay' => 0,
                    ], false);
                    $record->newaccount = 1;
                    $DB->update_record(self::TABLE, $record);
                    $transaction->allow_commit();
                }
                $DB->update_record(self::TABLE, $record);
            }
            if (!$record->orderid) {
                $buyer = \core_user::get_user((int)$record->userid, '*', MUST_EXIST);
                if ($buyer->deleted || $buyer->suspended) {
                    throw new \moodle_exception('guestcheckoutclaimaccount', 'local_moderncommerce');
                }
                $transaction = $DB->start_delegated_transaction();
                $totals = (array)$snapshot->totals;
                $order = order_api::create_snapshot_order(
                    (int)$record->userid,
                    'stripe',
                    (array)$snapshot->billing,
                    $snapshot->items,
                    $totals
                );
                $record->orderid = $order->id;
                $record->status = 'paid';
                $DB->update_record(self::TABLE, $record);
                $transaction->allow_commit();
            }
            $order = order_api::get_order((int)$record->orderid);
            $payment = json_decode($record->paymentdata);
            gateway_manager::record_payment_result($order, 'stripe', (object)[
                'status' => 'success', 'orderid' => $order->id, 'orderreference' => $order->ordernumber,
                'gatewayreference' => $payment->id, 'gatewaytransactionid' => $payment->payment_intent,
                'amount' => (float)$snapshot->totals->total, 'currency' => $payment->currency,
                'source' => 'webhook', 'eventtype' => 'checkout.session.completed', 'gatewayeventid' => $payment->id,
            ]);
            if (!in_array($order->status, ['paid', 'completed'], true)) {
                // Money has already settled. Never cancel this paid purchase because
                // the existing account already owns a course, or clear its unrelated cart.
                order_api::update_order_status($order->id, 'paid', true);
            } else {
                // The observer skips fulfilled items and safely retries any failed enrolments.
                $event = \local_moderncommerce\event\order_paid::create([
                    'objectid' => $order->id, 'context' => \context_system::instance(),
                    'relateduserid' => (int)$record->userid,
                    'other' => ['ordernumber' => $order->ordernumber, 'total' => $order->total],
                ]);
                \local_moderncommerce\observer\enrollment_observer::order_paid($event);
            }
            foreach ($snapshot->courses as $course) {
                enrolment_service::enrol_user_in_course((int)$record->userid, (int)$course->courseid, [
                    'orderid' => $order->id, 'productid' => (int)$course->productid,
                    'enrolduration' => (int)$course->enrolduration,
                ]);
            }
            $record->status = 'fulfilled';
            $record->lasterror = null;
            $record->timemodified = time();
            $DB->update_record(self::TABLE, $record);
            self::send_access_email($record);
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            $DB->set_field(self::TABLE, 'lasterror', get_class($e), ['id' => $id]);
            $DB->execute(
                'UPDATE {' . self::TABLE . '} SET attempts = attempts + 1, timemodified = :now WHERE id = :id',
                ['now' => time(), 'id' => $id]
            );
            throw $e;
        } finally {
            if ($emaillock) {
                $emaillock->release();
            }
            $lock->release();
        }
    }

    /**
     * Deliver Moodle's password setup for new users, or a login-required claim link for existing users.
     */
    private static function send_access_email(object $record): void {
        global $CFG, $DB;
        if ($record->mailsent) {
            return;
        }
        if ($record->newaccount && $record->userid) {
            require_once($CFG->dirroot . '/login/lib.php');
            $user = \core_user::get_user($record->userid, '*', MUST_EXIST);
            $reset = core_login_generate_password_reset($user);
            $sent = send_password_change_confirmation_email($user, $reset);
        } else {
            $token = bin2hex(random_bytes(32));
            $DB->set_field(self::TABLE, 'claimhash', hash('sha256', $token), ['id' => $record->id]);
            $url = new \moodle_url('/local/moderncommerce/guest_checkout.php', ['token' => $token, 'claim' => 1]);
            $user = (object)['id' => -1, 'email' => $record->email, 'firstname' => '', 'lastname' => '',
                'firstnamephonetic' => '', 'lastnamephonetic' => '', 'middlename' => '', 'alternatename' => '',
                'mailformat' => 1, 'maildisplay' => 0, 'emailstop' => 0];
            $sent = email_to_user(
                $user,
                \core_user::get_support_user(),
                get_string('guestcheckoutemailsubject', 'local_moderncommerce'),
                get_string('guestcheckoutemailbody', 'local_moderncommerce', $url->out(false))
            );
        }
        if (!$sent) {
            throw new \moodle_exception('guestcheckoutmailpending', 'local_moderncommerce');
        }
        $DB->set_field(self::TABLE, 'mailsent', time(), ['id' => $record->id]);
    }

    /**
     * Serialise payment provisioning and email identity decisions across workers.
     */
    private static function lock(string $key): \core\lock\lock {
        $lock = \core\lock\lock_config::get_lock_factory('local_moderncommerce_guest')->get_lock($key, 10);
        if (!$lock) {
            throw new \moodle_exception('guestcheckoutbusy', 'local_moderncommerce');
        }
        return $lock;
    }

    /**
     * A non-reversible, site-keyed address bucket for anonymous payment rate limiting.
     */
    private static function ip_hash(): string {
        $key = get_config('local_moderncommerce', 'guest_checkout_ip_secret');
        if (!$key) {
            $lock = self::lock('ip-secret');
            try {
                $key = get_config('local_moderncommerce', 'guest_checkout_ip_secret');
                if (!$key) {
                    $key = bin2hex(random_bytes(32));
                    set_config('guest_checkout_ip_secret', $key, 'local_moderncommerce');
                }
            } finally {
                $lock->release();
            }
        }
        return hash_hmac('sha256', getremoteaddr(), $key);
    }
}
