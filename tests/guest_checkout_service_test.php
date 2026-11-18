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

use local_moderncommerce\services\guest_checkout_service as guest;
use local_moderncommerce\api\cart_api;
use local_moderncommerce\api\order_api;
use local_moderncommerce\payment\gateway_manager;
use local_moderncommerce\payment\stripe_gateway;

/**
 * Payment/account boundaries and private anonymous carts.
 * @package local_moderncommerce
 * @category test
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(guest::class)]
final class guest_checkout_service_test extends advanced_testcase {
    /**
     * Guest sessions let Stripe select methods while preserving totals and retry identity.
     */
    public function test_guest_session_uses_dashboard_payment_methods(): void {
        $gateway = $this->getMockBuilder(stripe_gateway::class)->onlyMethods(['make_request'])->getMock();
        $gateway->expects($this->once())->method('make_request')->with(
            'POST',
            '/checkout/sessions',
            $this->callback(function (array $data): bool {
                foreach (array_keys($data) as $key) {
                    $this->assertFalse(str_starts_with($key, 'payment_method_types'));
                }
                $this->assertSame('payment', $data['mode']);
                $this->assertSame(2500, $data['line_items[0][price_data][unit_amount]']);
                $this->assertSame('moderncommerce-guest-42', $data['_idempotency_key']);
                $this->assertSame(42, $data['metadata[guest_checkout_id]']);
                return true;
            })
        )->willReturn(['id' => 'cs_test_dynamic', 'url' => 'https://checkout.stripe.com/test']);
        $result = $gateway->create_guest_session(
            (object)['id' => 42, 'timecreated' => time(), 'email' => 'buyer@example.invalid'],
            ['totals' => ['total' => 25, 'currency' => 'USD']],
            str_repeat('a', 64)
        );
        $this->assertSame('cs_test_dynamic', $result['id']);
    }

    /**
     * Signed-in and subscription checkouts omit the retired parameter too.
     */
    public function test_authenticated_sessions_use_dashboard_payment_methods(): void {
        $buyer = $this->getDataGenerator()->create_user();
        foreach ([false, true] as $subscription) {
            $gateway = $this->getMockBuilder(stripe_gateway::class)->onlyMethods([
                'make_request', 'is_enabled', 'get_config', 'log_transaction',
            ])->getMock();
            $gateway->method('is_enabled')->willReturn(true);
            $gateway->method('get_config')->willReturn([]);
            $gateway->expects($this->once())->method('make_request')->with(
                'POST',
                '/checkout/sessions',
                $this->callback(function (array $data) use ($subscription): bool {
                    foreach (array_keys($data) as $key) {
                        $this->assertFalse(str_starts_with($key, 'payment_method_types'));
                    }
                    $this->assertSame('payment', $data['mode']);
                    $this->assertSame(2500, $data['line_items[0][price_data][unit_amount]']);
                    if ($subscription) {
                        $this->assertSame('off_session', $data['payment_intent_data[setup_future_usage]']);
                        $this->assertSame('always', $data['customer_creation']);
                    }
                    return true;
                })
            )->willReturn(['id' => 'cs_test_dynamic', 'url' => 'https://checkout.stripe.com/test']);
            $result = $gateway->initialize_payment((object)[
                'id' => 999, 'userid' => $buyer->id, 'ordernumber' => 'DYNAMIC-TEST', 'total' => 25, 'currency' => 'USD',
                'notes' => $subscription ? json_encode(['subscription' => ['planid' => 1]]) : '',
            ]);
            $this->assertTrue($result['success']);
        }
    }

    /**
     * Acknowledged delayed sessions do not provision until a verified success arrives.
     */
    public function test_delayed_webhook_waits_for_confirmed_payment(): void {
        global $CFG, $DB;
        [$record, $session, $course] = $this->purchase();
        gateway_manager::save_gateway_config('stripe', ['enabled' => 1, 'testmode' => 1,
            'secretkey' => 'sk_test_fixture', 'webhooksecret' => 'whsec_fixture']);
        require_once($CFG->libdir . '/filelib.php');
        $sink = $this->redirectEmails();
        $users = $DB->count_records('user');
        foreach (
            ['checkout.session.completed' => 'unpaid', 'checkout.session.async_payment_succeeded' => 'paid'] as $type => $status
        ) {
            $session['payment_status'] = $status;
            curl::mock_response(json_encode($session));
            $event = ['type' => $type, 'data' => ['object' => $session]];
            $raw = json_encode($event);
            $now = time();
            $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $raw, 'whsec_fixture');
            $this->assertTrue((new stripe_gateway())->process_webhook($event, ['Stripe-Signature' => $signature], $raw));
            $saved = $DB->get_record(guest::TABLE, ['id' => $record->id]);
            if ($status === 'unpaid') {
                $this->assertSame('pending', $saved->status);
                $this->assertEmpty($saved->userid);
                $this->assertSame($users, $DB->count_records('user'));
            } else {
                $this->assertSame('fulfilled', $saved->status);
                $this->assertTrue(is_enrolled(context_course::instance($course->id), $saved->userid, '', true));
            }
        }
        $sink->close();
    }

    /**
     * Signed-in orders also wait for the delayed payment success event.
     */
    public function test_authenticated_delayed_payment_is_fulfilled_only_after_success(): void {
        global $DB;
        [$fixture, , $course] = $this->purchase();
        $snapshot = json_decode($fixture->payload);
        $DB->delete_records(guest::TABLE, ['id' => $fixture->id]);
        $DB->insert_record('local_moderncommerce_product_prices', (object)[
            'productid' => $snapshot->items[0]->productid, 'pricetype' => 'regular',
            'amount' => 25, 'enabled' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $buyer = $this->getDataGenerator()->create_user();
        $this->setUser($buyer);
        gateway_manager::save_gateway_config('stripe', ['enabled' => 1, 'testmode' => 1,
            'secretkey' => 'sk_test_fixture', 'webhooksecret' => 'whsec_fixture']);
        $sink = $this->redirectEmails();
        cart_api::add_to_cart($buyer->id, $course->id);
        $order = order_api::create_order($buyer->id, 'stripe');
        $session = [
            'id' => 'cs_test_authenticated_delayed', 'client_reference_id' => $order->ordernumber,
            'metadata' => ['order_id' => $order->id], 'payment_intent' => 'pi_test_authenticated_delayed',
            'amount_total' => 2500, 'currency' => 'usd', 'created' => time(),
        ];
        foreach (
            ['checkout.session.completed' => 'unpaid', 'checkout.session.async_payment_succeeded' => 'paid'] as $type => $status
        ) {
            $session['payment_status'] = $status;
            $event = ['type' => $type, 'data' => ['object' => $session]];
            $raw = json_encode($event);
            $now = time();
            $signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $raw, 'whsec_fixture');
            $this->assertTrue((new stripe_gateway())->process_webhook($event, ['Stripe-Signature' => $signature], $raw));
            $this->assertSame($status === 'paid' ? 'paid' : 'pending', order_api::get_order($order->id)->status);
            $this->assertSame($status === 'paid', is_enrolled(context_course::instance($course->id), $buyer->id, '', true));
        }
        $sink->close();
    }

    /**
     * Every guest purchase field is declared, including pseudonymous identifiers.
     */
    public function test_guest_purchase_privacy_metadata_is_complete(): void {
        global $DB;
        $collection = new \core_privacy\local\metadata\collection('local_moderncommerce');
        $collection = \local_moderncommerce\privacy\provider::get_metadata($collection);
        foreach ($collection->get_collection() as $entry) {
            if ($entry->get_name() !== guest::TABLE) {
                continue;
            }
            $fields = $entry->get_privacy_fields();
            $expected = array_keys($DB->get_columns(guest::TABLE));
            $expected = array_values(array_diff($expected, ['id']));
            $this->assertEqualsCanonicalizing($expected, array_keys($fields));
            foreach ($fields as $stringid) {
                $this->assertTrue(get_string_manager()->string_exists($stringid, 'local_moderncommerce'));
            }
            return;
        }
        $this->fail('Guest purchase privacy metadata was not registered.');
    }

    /**
     * Prepare real Moodle users, courses and enrolment infrastructure.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('primary_currency', 'USD', 'local_moderncommerce');
        set_config('enrol_plugins_enabled', 'manual');
    }

    /**
     * Anonymous ownership never falls back to a globally shared user zero cart.
     */
    public function test_anonymous_carts_are_isolated_including_clear(): void {
        global $SESSION, $DB;
        $SESSION->moderncommerce_guest_cart = 'browser-one';
        $first = cart_api::get_active_cart(0);
        $SESSION->moderncommerce_guest_cart = 'browser-two';
        $second = cart_api::get_active_cart(0);
        $this->assertNotEquals($first->id, $second->id);
        cart_api::clear_cart(0);
        $this->assertSame('active', $DB->get_field('local_moderncommerce_carts', 'status', ['id' => $first->id]));
        $this->assertSame('converted', $DB->get_field('local_moderncommerce_carts', 'status', ['id' => $second->id]));
        $SESSION->moderncommerce_guest_cart = 'browser-one';
        $this->assertEquals($first->id, cart_api::get_active_cart(0, false)->id);
    }

    /**
     * New operations are disabled by default, without allocating an account.
     */
    public function test_setting_defaults_off(): void {
        global $DB;
        $this->assertFalse(guest::enabled());
        $before = $DB->count_records('user');
        try {
            guest::start(['firstname' => 'A', 'lastname' => 'Buyer', 'email' => 'new@example.com']);
            $this->fail('Disabled guest checkout must reject the start.');
        } catch (moodle_exception $e) {
            $this->assertSame('guestcheckoutdisabled', $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('user'));
    }

    /**
     * Starting hosted checkout snapshots server prices and reuses a repeated submit without an account.
     */
    public function test_start_snapshots_guest_cart_and_reuses_stripe_session(): void {
        global $DB, $CFG, $SESSION;
        [$fixture, , $course] = $this->purchase();
        $snapshot = json_decode($fixture->payload);
        $DB->delete_records(guest::TABLE, ['id' => $fixture->id]);
        $DB->insert_record('local_moderncommerce_product_prices', (object)[
            'productid' => $snapshot->items[0]->productid, 'pricetype' => 'regular',
            'amount' => 25, 'enabled' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        set_config('guest_checkout_enabled', 1, 'local_moderncommerce');
        gateway_manager::save_gateway_config('stripe', ['enabled' => 1, 'testmode' => 1,
            'secretkey' => 'sk_test_fixture', 'webhooksecret' => 'whsec_fixture']);
        $this->setUser(null);
        $_POST['sesskey'] = sesskey();
        unset($SESSION->moderncommerce_guest_payment);
        cart_api::add_to_cart(0, $course->id);
        require_once($CFG->libdir . '/filelib.php');
        $response = json_encode(['id' => 'cs_test_start', 'status' => 'open', 'url' => 'https://checkout.stripe.com/test']);
        $users = $DB->count_records('user');
        curl::mock_response($response);
        $billing = ['firstname' => 'Test', 'lastname' => 'Buyer', 'email' => 'new@example.com'];
        $first = guest::start($billing);
        $saved = $DB->get_record(guest::TABLE, ['id' => $first['record']->id]);
        $this->assertSame('pending', $saved->status);
        $this->assertSame(25, json_decode($saved->payload)->totals->total);
        $this->assertSame('cs_test_start', $saved->stripeid);
        curl::mock_response($response);
        $second = guest::start($billing);
        $this->assertEquals($first['record']->id, $second['record']->id);
        $this->assertSame(1, $DB->count_records(guest::TABLE));
        $this->assertSame($users, $DB->count_records('user'));
    }

    /**
     * A signed event fetches authoritative Stripe state before fulfilling the immutable snapshot.
     */
    public function test_signed_webhook_uses_verified_stripe_session(): void {
        global $CFG, $DB;
        [$record, $session] = $this->purchase();
        gateway_manager::save_gateway_config('stripe', ['enabled' => 1, 'testmode' => 1,
            'secretkey' => 'sk_test_fixture', 'webhooksecret' => 'whsec_fixture']);
        require_once($CFG->libdir . '/filelib.php');
        curl::mock_response(json_encode($session));
        $event = ['type' => 'checkout.session.completed', 'data' => ['object' => $session]];
        $raw = json_encode($event);
        $time = time();
        $signature = 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $raw, 'whsec_fixture');
        $sink = $this->redirectEmails();
        $this->assertTrue((new stripe_gateway())->process_webhook($event, ['Stripe-Signature' => $signature], $raw));
        $this->assertSame('fulfilled', $DB->get_field(guest::TABLE, 'status', ['id' => $record->id]));
        $sink->close();
    }

    /**
     * A paid purchase provisions once even when browser return and webhook both arrive.
     */
    public function test_paid_purchase_creates_one_account_order_and_enrolment(): void {
        global $DB;
        [$record, $session, $course] = $this->purchase();
        $sink = $this->redirectEmails();
        $this->assertTrue(guest::confirm_session($session));
        $first = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertSame('fulfilled', $first->status);
        $this->assertSame(1, (int)$first->newaccount);
        $buyer = core_user::get_user($first->userid, '*', MUST_EXIST);
        $this->assertFalse(validate_internal_user_password($buyer, AUTH_PASSWORD_NOT_CACHED));
        $this->assertSame(1, $DB->count_records('user_password_resets', ['userid' => $first->userid]));
        $this->assertTrue(is_enrolled(context_course::instance($course->id), $first->userid, '', true));
        $this->assertSame('paid', order_api::get_order($first->orderid)->status);
        $mailcount = $sink->count();
        $this->assertGreaterThan(0, $mailcount);
        $this->assertTrue(guest::confirm_session($session));
        $second = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertEquals($first->orderid, $second->orderid);
        $this->assertSame(1, $DB->count_records('user', ['email' => $record->email]));
        $this->assertSame(1, $DB->count_records('local_moderncommerce_orders', ['userid' => $first->userid]));
        $this->assertSame($mailcount, $sink->count());
        $sink->close();
    }

    /**
     * An unpaid Checkout Session cannot create a Moodle account.
     */
    public function test_unpaid_payment_never_provisions(): void {
        global $DB;
        [$record, $session] = $this->purchase();
        $session['payment_status'] = 'unpaid';
        $session['status'] = 'open';
        $this->assertFalse(guest::confirm_session($session));
        $this->assertFalse($DB->record_exists('user', ['email' => $record->email]));
        $this->assertEmpty($DB->get_field(guest::TABLE, 'orderid', ['id' => $record->id]));
    }

    /**
     * Amount, currency, provider mode, and session ownership must all match the snapshot.
     */
    public function test_mismatched_payment_is_rejected_without_account_creation(): void {
        global $DB;
        [$record, $session] = $this->purchase();
        foreach (
            ['amount_total' => 1, 'currency' => 'eur', 'livemode' => true,
                'id' => 'cs_test_someoneelse', 'client_reference_id' => 'guest:999'] as $field => $value
        ) {
            $bad = $session;
            $bad[$field] = $value;
            try {
                guest::confirm_session($bad);
                $this->fail('Mismatched ' . $field . ' was accepted.');
            } catch (moodle_exception $e) {
                $this->assertSame('guestcheckoutverification', $e->errorcode);
            }
        }
        $this->assertFalse($DB->record_exists('user', ['email' => $record->email]));
    }

    /**
     * Existing email addresses never create duplicate users or automatically attach accounts.
     */
    public function test_existing_user_requires_ownership_claim(): void {
        global $DB;
        $existing = $this->getDataGenerator()->create_user(['email' => 'buyer@example.com']);
        $other = $this->getDataGenerator()->create_user(['email' => 'other@example.com']);
        [$record, $session, $course] = $this->purchase('buyer@example.com');
        $sink = $this->redirectEmails();
        guest::confirm_session($session);
        $this->assertSame('claim', $DB->get_field(guest::TABLE, 'status', ['id' => $record->id]));
        $this->assertEmpty($DB->get_field(guest::TABLE, 'userid', ['id' => $record->id]));
        guest::fulfil($record->id, $other->id);
        $this->assertEmpty($DB->get_field(guest::TABLE, 'userid', ['id' => $record->id]));
        guest::fulfil($record->id, $existing->id);
        $saved = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertSame('fulfilled', $saved->status);
        $this->assertEquals($existing->id, $saved->userid);
        $this->assertTrue(is_enrolled(context_course::instance($course->id), $existing->id, '', true));
        $this->assertSame(1, $DB->count_records('user', ['email' => $record->email]));
        $sink->close();
    }

    /**
     * Disabling guest checkout never strands an already-paid purchase.
     */
    public function test_paid_recovery_continues_while_setting_is_off(): void {
        global $DB;
        [$record, $session] = $this->purchase();
        set_config('guest_checkout_enabled', 0, 'local_moderncommerce');
        $sink = $this->redirectEmails();
        guest::confirm_session($session);
        $this->assertSame('fulfilled', $DB->get_field(guest::TABLE, 'status', ['id' => $record->id]));
        $sink->close();
    }

    /**
     * A forged webhook must fail before fetching or creating anything.
     */
    public function test_forged_signature_is_rejected(): void {
        gateway_manager::save_gateway_config('stripe', ['enabled' => 1, 'testmode' => 1, 'webhooksecret' => 'whsec_test']);
        $this->expectException(moodle_exception::class);
        (new stripe_gateway())->process_webhook(
            ['type' => 'checkout.session.completed'],
            ['Stripe-Signature' => 't=' . time() . ',v1=forged'],
            '{}'
        );
    }

    /**
     * Bearer references are long random tokens; sequential record IDs are not accepted.
     */
    public function test_confirmation_requires_a_valid_token(): void {
        [$record] = $this->purchase();
        $this->assertNull(guest::by_token((string)$record->id));
        $this->assertNull(guest::by_token(str_repeat('a', 64)));
        $this->assertEquals($record->id, guest::by_token(str_repeat('b', 64))->id);
    }

    /**
     * A pre-existing personal cart survives a guest purchase claim.
     */
    public function test_claim_does_not_clear_the_existing_users_cart(): void {
        global $DB;
        $existing = $this->getDataGenerator()->create_user(['email' => 'buyer@example.com']);
        $cart = cart_api::get_active_cart($existing->id);
        [$record, $session] = $this->purchase('buyer@example.com');
        $sink = $this->redirectEmails();
        guest::confirm_session($session);
        guest::fulfil($record->id, $existing->id);
        $this->assertSame('active', $DB->get_field('local_moderncommerce_carts', 'status', ['id' => $cart->id]));
        $sink->close();
    }

    /**
     * Refund notification before checkout completion must prevent account provisioning.
     */
    public function test_refund_before_claim_does_not_create_an_account(): void {
        global $DB;
        [$record, $session] = $this->purchase();
        guest::record_refund(['metadata' => ['guest_checkout_id' => $record->id], 'refunded' => true]);
        $this->assertTrue(guest::confirm_session($session));
        $this->assertSame('refunded', $DB->get_field(guest::TABLE, 'status', ['id' => $record->id]));
        $this->assertFalse($DB->record_exists('user', ['email' => $record->email]));
    }

    /**
     * Enrolment failure keeps a paid order recoverable without creating a second account or charge.
     */
    public function test_enrolment_failure_can_be_retried(): void {
        global $DB;
        [$record, $session, $course] = $this->purchase();
        $sink = $this->redirectEmails();
        set_config('enrol_plugins_enabled', '');
        try {
            guest::confirm_session($session);
            $this->fail('Missing enrolment plugin should leave a recoverable paid purchase.');
        } catch (moodle_exception $e) {
            $this->assertSame('fulfillmentenrolpluginmissing', $e->errorcode);
        }
        $before = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertSame('paid', $before->status);
        $this->assertNotEmpty($before->orderid);
        set_config('enrol_plugins_enabled', 'manual');
        guest::fulfil($record->id);
        $after = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertSame('fulfilled', $after->status);
        $this->assertEquals($before->userid, $after->userid);
        $this->assertEquals($before->orderid, $after->orderid);
        $this->assertTrue(is_enrolled(context_course::instance($course->id), $after->userid, '', true));
        $sink->close();
        $this->resetDebugging();
    }

    /**
     * The existing signed-in order and enrolment workflow remains compatible.
     */
    public function test_authenticated_checkout_remains_compatible(): void {
        global $DB;
        [$fixture, , $course] = $this->purchase();
        $snapshot = json_decode($fixture->payload);
        $DB->delete_records(guest::TABLE, ['id' => $fixture->id]);
        $DB->insert_record('local_moderncommerce_product_prices', (object)[
            'productid' => $snapshot->items[0]->productid, 'pricetype' => 'regular',
            'amount' => 25, 'enabled' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $buyer = $this->getDataGenerator()->create_user();
        $this->setUser($buyer);
        $sink = $this->redirectEmails();
        cart_api::add_to_cart($buyer->id, $course->id);
        $order = order_api::create_order($buyer->id, 'stripe');
        $this->assertSame('pending', $order->status);
        $this->assertEquals(25, $order->total);
        $this->assertEquals($buyer->id, $order->userid);
        order_api::update_order_status($order->id, 'paid');
        $this->assertSame(0, cart_api::get_cart_count($buyer->id));
        $this->assertTrue(is_enrolled(context_course::instance($course->id), $buyer->id, '', true));
        $sink->close();
    }

    /**
     * Create a server snapshot matching a successful Stripe session without making external requests.
     */
    private function purchase(string $email = 'buyer@example.com'): array {
        global $DB;
        $course = $this->getDataGenerator()->create_course();
        $productid = $DB->insert_record('local_moderncommerce_products', (object)[
            'name' => $course->fullname, 'producttype' => 'course', 'slug' => 'test-' . $course->id,
            'status' => 'active', 'visible' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('local_moderncommerce_product_courses', (object)[
            'productid' => $productid, 'courseid' => $course->id, 'relationtype' => 'included',
            'sortorder' => 0, 'required' => 1, 'timecreated' => time(),
        ]);
        $payload = [
            'billing' => ['firstname' => 'Test', 'lastname' => 'Buyer', 'email' => $email],
            'items' => [(object)['productid' => $productid, 'courseid' => $course->id,
                'unitprice' => 25, 'quantity' => 1, 'itemtype' => 'course', 'itemname' => $course->fullname]],
            'totals' => ['subtotal' => 25, 'discount' => 0, 'tax' => 0, 'total' => 25, 'currency' => 'USD', 'coupon' => null],
            'courses' => [['courseid' => $course->id, 'productid' => $productid, 'enrolduration' => 0]],
            'testmode' => true,
        ];
        $record = (object)[
            'email' => $email, 'tokenhash' => hash('sha256', str_repeat('b', 64)),
            'sessionhash' => hash('sha256', 'browser'), 'payload' => json_encode($payload),
            'stripeid' => 'cs_test_guest', 'status' => 'pending', 'timecreated' => time(), 'timemodified' => time(),
        ];
        $record->id = $DB->insert_record(guest::TABLE, $record);
        return [$record, [
            'id' => 'cs_test_guest', 'metadata' => ['guest_checkout_id' => $record->id],
            'client_reference_id' => 'guest:' . $record->id, 'livemode' => false, 'mode' => 'payment',
            'payment_status' => 'paid', 'status' => 'complete', 'amount_total' => 2500,
            'currency' => 'usd', 'payment_intent' => 'pi_test_guest',
        ], $course];
    }
}
