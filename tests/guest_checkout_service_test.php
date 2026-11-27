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
use local_moderncommerce\services\guest_password_service as password_service;

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
     * Provider verification creates one account/order and preserves the selected gateway on replay.
     */
    public function test_paystack_and_flutterwave_verified_payments_are_idempotent(): void {
        global $DB;
        foreach (['paystack', 'flutterwave'] as $gateway) {
            [$record, , $course] = $this->provider_purchase($gateway);
            $response = $this->provider_response($record);
            $sink = $this->redirectEmails();
            foreach ([1, 2] as $attempt) {
                curl::mock_response(json_encode($response));
                $this->assertTrue(guest::reconcile($record));
            }
            $saved = $DB->get_record(guest::TABLE, ['id' => $record->id]);
            $this->assertSame('fulfilled', $saved->status);
            $this->assertSame($gateway, $DB->get_field(
                'local_moderncommerce_payment_attempts',
                'gateway',
                ['orderid' => $saved->orderid],
                IGNORE_MULTIPLE
            ));
            $this->assertSame(1, $DB->count_records('user', ['email' => $record->email]));
            $this->assertSame(1, $DB->count_records('local_moderncommerce_orders', ['userid' => $saved->userid]));
            $this->assertTrue(is_enrolled(context_course::instance($course->id), $saved->userid, '', true));
            $sink->close();
        }
    }

    /**
     * An API success flag alone is insufficient: references, amounts, currency and mode must match.
     */
    public function test_provider_verification_rejects_mismatched_payment_details(): void {
        global $DB;
        foreach (['paystack', 'flutterwave'] as $gateway) {
            [$record] = $this->provider_purchase($gateway);
            $valid = $this->provider_response($record);
            $reference = $gateway === 'paystack' ? 'reference' : 'tx_ref';
            $invalid = [$reference => 'other-purchase', 'amount' => 1, 'currency' => 'EUR'];
            $invalid[$gateway === 'paystack' ? 'metadata' : 'meta'] = ['guest_checkout_id' => $record->id + 1];
            if ($gateway === 'paystack') {
                $invalid['domain'] = 'live';
            }
            foreach ($invalid as $field => $value) {
                $response = $valid;
                $response['data'][$field] = $value;
                curl::mock_response(json_encode($response));
                try {
                    guest::reconcile($record);
                    $this->fail('Mismatched payment must not provision a buyer.');
                } catch (moodle_exception $e) {
                    $this->assertSame('guestcheckoutverification', $e->errorcode);
                }
            }
            $response = $valid;
            $response['data']['status'] = 'pending';
            curl::mock_response(json_encode($response));
            $this->assertFalse(guest::reconcile($record));
            $this->assertFalse($DB->record_exists('user', ['email' => $record->email]));
            $this->assertSame('pending', $DB->get_field(guest::TABLE, 'status', ['id' => $record->id]));
        }
    }

    /**
     * PayPal approval is captured only after matching the immutable amount and guest reference.
     */
    public function test_paypal_approval_capture_and_replay(): void {
        global $DB;
        [$record, , $course] = $this->provider_purchase('paypal');
        $unit = ['custom_id' => 'guest:' . $record->id, 'reference_id' => 'guest:' . $record->id,
            'amount' => ['value' => '25.00', 'currency_code' => 'USD']];
        $approved = ['id' => $record->providerreference, 'status' => 'APPROVED', 'purchase_units' => [$unit]];
        $complete = $approved;
        $complete['status'] = 'COMPLETED';
        $complete['purchase_units'][0]['payments']['captures'] = [[
            'id' => 'CAPTURE-TEST', 'status' => 'COMPLETED', 'amount' => $unit['amount'],
        ]];
        $sink = $this->redirectEmails();
        foreach (array_reverse([$approved, ['id' => $record->providerreference], $complete]) as $response) {
            curl::mock_response(json_encode($response));
            curl::mock_response(json_encode(['access_token' => 'test-oauth']));
        }
        $this->assertTrue(guest::reconcile($record));
        curl::mock_response(json_encode($complete));
        curl::mock_response(json_encode(['access_token' => 'test-oauth']));
        $this->assertTrue(guest::reconcile($record));
        $saved = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertSame('CAPTURE-TEST', $saved->providertransactionid);
        $this->assertSame('fulfilled', $saved->status);
        $this->assertSame(1, $DB->count_records('local_moderncommerce_orders', ['userid' => $saved->userid]));
        $this->assertTrue(is_enrolled(context_course::instance($course->id), $saved->userid, '', true));
        $sink->close();
    }

    /**
     * A mismatched approved PayPal order must never reach capture or create a learner.
     */
    public function test_paypal_rejects_wrong_approved_total_before_capture(): void {
        global $DB;
        [$record] = $this->provider_purchase('paypal');
        curl::mock_response(json_encode(['id' => $record->providerreference, 'status' => 'APPROVED', 'purchase_units' => [[
            'custom_id' => 'guest:' . $record->id, 'reference_id' => 'guest:' . $record->id,
            'amount' => ['value' => '1.00', 'currency_code' => 'USD'],
        ]]]));
        curl::mock_response(json_encode(['access_token' => 'test-oauth']));
        try {
            guest::reconcile($record);
            $this->fail('A mismatched order must be rejected before capture.');
        } catch (moodle_exception $e) {
            $this->assertSame('guestcheckoutverification', $e->errorcode);
        }
        $this->assertFalse($DB->record_exists('user', ['email' => $record->email]));
        $this->assertSame('pending', $DB->get_field(guest::TABLE, 'status', ['id' => $record->id]));
    }

    /**
     * Manual payment is never provisioned by a browser return and requires order-management authority.
     */
    public function test_manual_payment_requires_authorized_confirmation(): void {
        global $DB;
        [$record, , $course] = $this->provider_purchase('manual');
        $this->assertFalse(guest::reconcile($record));
        $this->assertFalse($DB->record_exists('user', ['email' => $record->email]));
        $this->setUser($this->getDataGenerator()->create_user());
        $_POST['sesskey'] = sesskey();
        try {
            guest::confirm_manual($record->id);
            $this->fail('Learners cannot approve an offline payment.');
        } catch (required_capability_exception $e) {
            $this->assertSame('nopermissions', $e->errorcode);
        }
        $this->setAdminUser();
        $_POST['sesskey'] = sesskey();
        $sink = $this->redirectEmails();
        try {
            guest::confirm_manual($record->id);
            guest::confirm_manual($record->id);
        } finally {
            unset($_POST['sesskey']);
        }
        $saved = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertSame('fulfilled', $saved->status);
        $this->assertSame(1, $DB->count_records('local_moderncommerce_orders', ['userid' => $saved->userid]));
        $this->assertSame('guest.manual.approved', json_decode($saved->paymentdata)->eventtype);
        $this->assertTrue(is_enrolled(context_course::instance($course->id), $saved->userid, '', true));
        $sink->close();
    }

    /**
     * Hosted creation binds the callback to the guest capability, including PayPal's token-name collision.
     */
    public function test_all_provider_session_creation_preserves_guest_identity(): void {
        global $DB;
        foreach (['paystack', 'flutterwave', 'paypal', 'manual'] as $gateway) {
            [$record] = $this->provider_purchase($gateway);
            if ($gateway === 'paystack') {
                curl::mock_response(json_encode(['status' => true, 'data' => [
                    'reference' => $record->providerreference, 'authorization_url' => 'https://checkout.paystack.com/test',
                ]]));
            } else if ($gateway === 'flutterwave') {
                curl::mock_response(json_encode([
                    'status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/test'],
                ]));
            } else if ($gateway === 'paypal') {
                curl::mock_response(json_encode(['id' => 'PAYPAL-TEST', 'links' => [
                    ['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=PAYPAL-TEST'],
                ]]));
                curl::mock_response(json_encode(['access_token' => 'test-oauth']));
            }
            $session = \local_moderncommerce\services\guest_gateway_service::create(
                $record,
                json_decode($record->payload, true),
                str_repeat('b', 64)
            );
            $this->assertNotEmpty($session['id']);
            $this->assertStringContainsString($gateway === 'manual' ? 'guesttoken=' : 'https://', $session['url']);
            $this->assertFalse($DB->record_exists('user', ['email' => $record->email]));
        }
    }

    /**
     * Prepare a non-Stripe purchase with independent tokens and native gateway credentials.
     * @param string $gateway Gateway ID.
     * @return array
     */
    private function provider_purchase(string $gateway): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/filelib.php');
        $DB->delete_records(guest::TABLE, ['tokenhash' => hash('sha256', str_repeat('b', 64))]);
        $fixture = $this->purchase($gateway . '@example.com');
        $record = $fixture[0];
        $record->gateway = $gateway;
        $record->providerreference = 'MCG-TEST-' . $record->id;
        $record->stripeid = null;
        $record->tokenhash = hash('sha256', $gateway);
        $DB->update_record(guest::TABLE, $record);
        gateway_manager::save_gateway_config($gateway, [
            'enabled' => 1, 'testmode' => 1, 'secretkey' => 'sk_test_fixture', 'merchantid' => 'test-client',
            'publickey' => 'pk_test_fixture', 'webhooksecret' => 'test-signing',
        ]);
        return $fixture;
    }

    /**
     * Successful provider response for a frozen USD25 purchase.
     * @param object $record Purchase.
     * @return array
     */
    private function provider_response(object $record): array {
        return ['status' => $record->gateway === 'paystack' ? true : 'success', 'data' => [
            'id' => 12345, 'status' => $record->gateway === 'paystack' ? 'success' : 'successful',
            'reference' => $record->providerreference, 'tx_ref' => $record->providerreference,
            'amount' => $record->gateway === 'paystack' ? 2500 : 25, 'currency' => 'USD', 'domain' => 'test',
            'metadata' => ['guest_checkout_id' => $record->id], 'meta' => ['guest_checkout_id' => $record->id],
        ]];
    }

    /**
     * The selector includes every ready built-in gateway and excludes disabled or unsupported methods.
     */
    public function test_guest_gateway_methods_respect_configuration_and_currency(): void {
        foreach (['stripe', 'paystack', 'flutterwave', 'paypal', 'manual'] as $gateway) {
            gateway_manager::save_gateway_config($gateway, [
                'enabled' => 1, 'testmode' => 1, 'secretkey' => 'sk_test_fixture', 'merchantid' => 'test-client',
                'publickey' => 'pk_test_fixture', 'webhooksecret' => 'whsec_fixture',
                'methodtype' => $gateway === 'manual' ? 'offline' : 'redirect',
            ]);
        }
        $methods = \local_moderncommerce\services\guest_gateway_service::methods('USD');
        $this->assertEqualsCanonicalizing(['stripe', 'paystack', 'flutterwave', 'paypal', 'manual'], array_keys($methods));
        gateway_manager::save_gateway_config('paypal', ['enabled' => 0]);
        $this->assertArrayNotHasKey('paypal', \local_moderncommerce\services\guest_gateway_service::methods('USD'));
        $this->assertArrayNotHasKey('paystack', \local_moderncommerce\services\guest_gateway_service::methods('JPY'));
        $this->assertArrayNotHasKey('enrollkey', $methods);
    }

    /**
     * Offline submission stays pending, reuses its reference, and retains the anonymous cart.
     */
    public function test_manual_start_reuses_attempt_without_creating_an_account(): void {
        global $DB, $SESSION;
        [$fixture, , $course] = $this->purchase();
        $snapshot = json_decode($fixture->payload);
        $DB->delete_records(guest::TABLE, ['id' => $fixture->id]);
        $DB->insert_record('local_moderncommerce_product_prices', (object)[
            'productid' => $snapshot->items[0]->productid, 'pricetype' => 'regular',
            'amount' => 25, 'enabled' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        set_config('guest_checkout_enabled', 1, 'local_moderncommerce');
        gateway_manager::save_gateway_config('manual', ['enabled' => 1, 'methodtype' => 'offline']);
        $this->setUser(null);
        $_POST['sesskey'] = sesskey();
        unset($SESSION->moderncommerce_guest_payment);
        cart_api::add_to_cart(0, $course->id);
        $billing = ['firstname' => 'Test', 'lastname' => 'Buyer', 'email' => 'offline@example.com', 'gateway' => 'manual'];
        try {
            $first = guest::start($billing);
            $second = guest::start($billing);
        } finally {
            unset($_POST['sesskey']);
        }
        $saved = $DB->get_record(guest::TABLE, ['id' => $first['record']->id]);
        $this->assertEquals($saved->id, $second['record']->id);
        $this->assertSame('manual', $saved->gateway);
        $this->assertSame('pending', $saved->status);
        $this->assertSame($first['url'], $second['url']);
        $this->assertStringContainsString('guesttoken=', $first['url']);
        $this->assertFalse($DB->record_exists('user', ['email' => $saved->email]));
        $this->assertSame(1, cart_api::get_cart_count(0));
    }

    /**
     * Paystack signed events reach the guest adapter only after signature verification.
     */
    public function test_paystack_guest_webhook_requires_signature_and_queries_api(): void {
        global $DB;
        [$record] = $this->provider_purchase('paystack');
        $response = $this->provider_response($record);
        $event = ['event' => 'charge.success', 'data' => $response['data']];
        $raw = json_encode($event);
        $gateway = new \local_moderncommerce\payment\paystack_gateway();
        $this->assertFalse($gateway->process_webhook($event, ['x-paystack-signature' => 'forged'], $raw));
        $this->assertFalse($DB->record_exists('user', ['email' => $record->email]));
        curl::mock_response(json_encode($response));
        $sink = $this->redirectEmails();
        $this->assertTrue($gateway->process_webhook($event, [
            'x-paystack-signature' => hash_hmac('sha512', $raw, 'sk_test_fixture'),
        ], $raw));
        $this->assertSame('fulfilled', $DB->get_field(guest::TABLE, 'status', ['id' => $record->id]));
        $sink->close();
    }

    /**
     * Native authentication stores the password and consumes every outstanding link for the buyer.
     */
    public function test_commerce_password_save_consumes_tokens_and_keeps_native_hashing(): void {
        global $DB, $USER, $CFG;
        [$record, , $sink] = $this->renewal_fixture();
        $token = $DB->get_field('user_password_resets', 'token', ['userid' => $record->userid]);
        $this->assertSame('valid', password_service::inspect($token)['state']);
        $this->assertSame($record->email, password_service::inspect($token)['user']->username);
        $this->assertStringContainsString(
            '/local/moderncommerce/set_password.php?token=',
            password_service::legacy_target(password_service::inspect($token)['user'])
        );
        $reset = core_login_generate_password_reset(\core_user::get_user($record->userid));
        $CFG->passwordreuselimit = 3;
        set_user_preference('auth_forcepasswordchange', 1, $record->userid);
        $this->setUser();
        $_POST['sesskey'] = sesskey();
        try {
            $user = password_service::save($reset->token, 'Commerce-Finish!946', 'Commerce-Finish!946');
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertTrue(validate_internal_user_password($user, 'Commerce-Finish!946'));
        $this->assertSame(0, $DB->count_records('user_password_resets', ['userid' => $user->id]));
        $this->assertSame('invalid', password_service::inspect($token)['state']);
        $this->assertSame('invalid', password_service::inspect($reset->token)['state']);
        $this->assertEmpty(get_user_preferences('auth_forcepasswordchange', null, $user));
        $this->assertEmpty($USER->id);
        $second = core_login_generate_password_reset($user);
        $_POST['sesskey'] = sesskey();
        try {
            password_service::save($second->token, 'Commerce-Finish!946', 'Commerce-Finish!946');
            $this->fail('Password history must be enforced.');
        } catch (moodle_exception $e) {
            $this->assertSame('errorpasswordreused', $e->errorcode);
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertSame('valid', password_service::inspect($second->token)['state']);
        $sink->close();
    }

    /**
     * Bad confirmation and weak/empty passwords leave the native token available for correction.
     */
    public function test_commerce_password_validation_preserves_token_on_failure(): void {
        global $DB, $CFG;
        [$record, , $sink] = $this->renewal_fixture();
        $token = $DB->get_field('user_password_resets', 'token', ['userid' => $record->userid]);
        $CFG->passwordpolicy = 1;
        $CFG->minpasswordlength = 12;
        $this->setUser();
        $_POST['sesskey'] = sesskey();
        try {
            foreach (
                [['', '', 'mcpasswordpolicy'], ['abc', 'abc', 'mcpasswordpolicy'],
                ['Commerce-Finish!946', 'Different!123', 'passwordsdiffer']] as [$password, $confirm, $error]
            ) {
                try {
                    password_service::save($token, $password, $confirm);
                    $this->fail('Invalid password must be rejected.');
                } catch (moodle_exception $e) {
                    $this->assertSame($error, $e->errorcode);
                }
            }
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertSame('valid', password_service::inspect($token)['state']);
        $this->assertSame(AUTH_PASSWORD_NOT_CACHED, $DB->get_field('user', 'password', ['id' => $record->userid]));
        $sink->close();
    }

    /**
     * Expired and suspended accounts do not expose an account or accept a new password.
     */
    public function test_commerce_password_rejects_expired_and_ineligible_accounts(): void {
        global $DB, $CFG;
        [$record, , $sink] = $this->renewal_fixture();
        $token = $DB->get_field('user_password_resets', 'token', ['userid' => $record->userid]);
        $DB->set_field(
            'user_password_resets',
            'timerequested',
            time() - ($CFG->pwresettime ?? 1800) - 1,
            ['userid' => $record->userid]
        );
        $this->assertSame('expired', password_service::inspect($token)['state']);
        $this->assertNull(password_service::inspect($token)['user']);
        $this->setUser();
        $_POST['sesskey'] = sesskey();
        try {
            password_service::save($token, 'Commerce-Finish!946', 'Commerce-Finish!946');
            $this->fail('An expired token must not change a password.');
        } catch (moodle_exception $e) {
            $this->assertSame('mcpasswordinvalid', $e->errorcode);
        } finally {
            unset($_POST['sesskey']);
        }
        $DB->set_field('user_password_resets', 'timerequested', time(), ['userid' => $record->userid]);
        $DB->set_field('user', 'suspended', 1, ['id' => $record->userid]);
        $this->assertSame('invalid', password_service::inspect($token)['state']);
        $DB->set_field('user', 'suspended', 0, ['id' => $record->userid]);
        $DB->set_field(guest::TABLE, 'status', 'refunded', ['id' => $record->id]);
        $this->assertSame('invalid', password_service::inspect($token)['state']);
        $this->assertSame(AUTH_PASSWORD_NOT_CACHED, $DB->get_field('user', 'password', ['id' => $record->userid]));
        $sink->close();
    }

    /**
     * A valid email token cannot switch an already signed-in different account.
     */
    public function test_commerce_password_requires_session_key_and_matching_signed_in_account(): void {
        global $DB, $USER;
        [$record, , $sink] = $this->renewal_fixture();
        $token = $DB->get_field('user_password_resets', 'token', ['userid' => $record->userid]);
        $USER->ignoresesskey = false;
        $_POST['sesskey'] = 'wrong-key';
        try {
            password_service::save($token, 'Commerce-Finish!946', 'Commerce-Finish!946');
            $this->fail('A session key is required.');
        } catch (moodle_exception $e) {
            $this->assertSame('invalidsesskey', $e->errorcode);
        }
        $_POST['sesskey'] = sesskey();
        try {
            password_service::save($token, 'Commerce-Finish!946', 'Commerce-Finish!946');
            $this->fail('A different signed-in user must not be switched.');
        } catch (moodle_exception $e) {
            $this->assertSame('mcpassworddifferentuser', $e->errorcode);
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertSame('valid', password_service::inspect($token)['state']);
        $sink->close();
    }

    /**
     * The branded form inherits the native validation and supports expired-link renewal.
     */
    public function test_commerce_password_form_and_expired_link_renewal(): void {
        global $DB;
        [$record, $renew, $sink] = $this->renewal_fixture();
        $token = $DB->get_field('user_password_resets', 'token', ['userid' => $record->userid]);
        $user = password_service::inspect($token)['user'];
        $form = new \local_moderncommerce\form\set_password(null, $user);
        $this->assertInstanceOf(\login_set_password_form::class, $form);
        $this->assertStringContainsString('name="password2"', $form->render());
        $this->assertStringContainsString('guest_access.php?token=' . $renew, password_service::renewal_url($token, $renew));
        $this->assertSame('', password_service::renewal_url($token, str_repeat('a', 64)));
        $other = $this->getDataGenerator()->create_user();
        $otherreset = core_login_generate_password_reset($other);
        $other->token = $otherreset->token;
        $this->assertSame('invalid', password_service::inspect($otherreset->token)['state']);
        $this->assertSame('', password_service::legacy_target($other));
        $DB->delete_records('user_password_resets', ['userid' => $record->userid]);
        $this->assertStringContainsString('guest_access.php?token=' . $renew, password_service::renewal_url($token, $renew));
        $sink->close();
    }

    /**
     * A separate renewal token emails a fresh link and never creates a login session or password.
     */
    public function test_guest_access_renewal_emails_and_rotates_without_signing_in(): void {
        global $DB, $USER;
        [$record, $token, $sink] = $this->renewal_fixture();
        $userid = $USER->id;
        $before = count($sink->get_messages());
        $this->assertFalse(guest::enabled());
        $this->assertTrue(\local_moderncommerce\services\guest_access_service::available($token));
        $this->assertSame(1, $DB->count_records('user_password_resets', ['userid' => $record->userid]));
        $original = $DB->get_field('user_password_resets', 'token', ['userid' => $record->userid], MUST_EXIST);
        $_POST['sesskey'] = sesskey();
        try {
            \local_moderncommerce\services\guest_access_service::request($token);
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertSame($before + 1, count($sink->get_messages()));
        $renewed = $DB->get_field_select(
            'user_password_resets',
            'token',
            'userid = :userid AND token <> :original',
            ['userid' => $record->userid, 'original' => $original],
            MUST_EXIST
        );
        $this->assertSame('valid', password_service::inspect($renewed)['state']);
        $this->assertFalse(\local_moderncommerce\services\guest_access_service::available($token));
        $this->assertSame($userid, $USER->id);
        $this->assertSame(AUTH_PASSWORD_NOT_CACHED, $DB->get_field('user', 'password', ['id' => $record->userid]));
        $messages = $sink->get_messages();
        $mail = end($messages);
        $this->assertSame($record->email, $mail->to);
        $this->assertStringContainsString(
            '/local/moderncommerce/set_password.php?token=' . $renewed,
            quoted_printable_decode($mail->body)
        );
        $this->assertStringNotContainsString('guest_access.php?token=' . $token, quoted_printable_decode($mail->body));
        $sink->close();
    }

    /**
     * Page views do not generate mail and a recent request cannot be repeated.
     */
    public function test_guest_access_view_and_cooldown_do_not_send_mail(): void {
        global $DB;
        [$record, $token, $sink] = $this->renewal_fixture();
        $before = count($sink->get_messages());
        $DB->set_field(guest::TABLE, 'mailsent', time(), ['id' => $record->id]);
        $this->assertTrue(\local_moderncommerce\services\guest_access_service::available($token));
        $_POST['sesskey'] = sesskey();
        try {
            \local_moderncommerce\services\guest_access_service::request($token);
            $this->fail('Recent renewal must be throttled.');
        } catch (moodle_exception $e) {
            $this->assertSame('guestaccesslimited', $e->errorcode);
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertSame($before, count($sink->get_messages()));
        $this->assertSame(1, $DB->count_records('user_password_resets', ['userid' => $record->userid]));
        $sink->close();
    }

    /**
     * Expired/native/forged links and ineligible accounts cannot renew access.
     */
    public function test_guest_access_rejects_invalid_expired_and_changed_accounts(): void {
        global $DB;
        [$record, $token, $sink] = $this->renewal_fixture();
        $service = \local_moderncommerce\services\guest_access_service::class;
        $this->assertFalse($service::available(str_repeat('a', 64)));
        $reset = $DB->get_field('user_password_resets', 'token', ['userid' => $record->userid]);
        $this->assertFalse($service::available($reset));
        $DB->set_field(guest::TABLE, 'accessexpires', time() - 1, ['id' => $record->id]);
        $this->assertFalse($service::available($token));
        $DB->set_field(guest::TABLE, 'accessexpires', time() + DAYSECS, ['id' => $record->id]);
        $DB->set_field('user', 'suspended', 1, ['id' => $record->userid]);
        $this->assertFalse($service::available($token));
        $DB->set_field('user', 'suspended', 0, ['id' => $record->userid]);
        $DB->set_field('user', 'email', 'changed@example.com', ['id' => $record->userid]);
        $this->assertFalse($service::available($token));
        $DB->set_field('user', 'email', $record->email, ['id' => $record->userid]);
        $DB->set_field(guest::TABLE, 'status', 'refunded', ['id' => $record->id]);
        $this->assertFalse($service::available($token));
        $_POST['sesskey'] = sesskey();
        try {
            $service::request($token);
            $this->fail('Refunded purchases cannot request access emails.');
        } catch (moodle_exception $e) {
            $this->assertSame('guestaccessinvalid', $e->errorcode);
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertSame(1, $DB->count_records('user_password_resets', ['userid' => $record->userid]));
        $sink->close();
    }

    /**
     * A leaked URL cannot trigger a password email through a cross-site POST.
     */
    public function test_guest_access_requires_a_valid_session_key(): void {
        global $USER;
        [, $token, $sink] = $this->renewal_fixture();
        $USER->ignoresesskey = false;
        $_POST['sesskey'] = 'incorrect-session-key';
        try {
            \local_moderncommerce\services\guest_access_service::request($token);
            $this->fail('Renewal requires a valid session key.');
        } catch (moodle_exception $e) {
            $this->assertSame('invalidsesskey', $e->errorcode);
        } finally {
            unset($_POST['sesskey']);
            $sink->close();
        }
    }

    /**
     * Upgrades replace only the original bundled copy, including a matching outgoing override.
     */
    public function test_guest_welcome_upgrade_preserves_customised_copy(): void {
        global $DB;
        $seed = \local_moderncommerce\email\commerce_seed::class;
        $key = $seed::GUEST_WELCOME;
        $seed::ensure_guest_welcome_template();
        $id = $DB->get_field('local_moderncommerce_emailtpl', 'id', ['template_key' => $key]);
        $legacy = require(__DIR__ . '/fixtures/guest_welcome_legacy.php');
        $DB->set_field('local_moderncommerce_emailtpl', 'body', $legacy, ['id' => $id]);
        set_config($key . '_body', $legacy, 'local_moderncommerce');
        set_config($key . '_subject', 'Administrator subject', 'local_moderncommerce');
        $seed::upgrade_guest_welcome_template();
        $current = get_string('emailtpl_moderncommerce_guest_welcome_body', 'local_moderncommerce');
        $this->assertSame($current, $DB->get_field('local_moderncommerce_emailtpl', 'body', ['id' => $id]));
        $this->assertSame($current, get_config('local_moderncommerce', $key . '_body'));
        $this->assertSame('Administrator subject', get_config('local_moderncommerce', $key . '_subject'));
        $DB->set_field('local_moderncommerce_emailtpl', 'body', '<p>Custom template</p>', ['id' => $id]);
        set_config($key . '_body', '<p>Custom outgoing email</p>', 'local_moderncommerce');
        $seed::upgrade_guest_welcome_template();
        $this->assertSame('<p>Custom template</p>', $DB->get_field('local_moderncommerce_emailtpl', 'body', ['id' => $id]));
        $this->assertSame('<p>Custom outgoing email</p>', get_config('local_moderncommerce', $key . '_body'));
        set_config($key . '_template', (int)$id + 100, 'local_moderncommerce');
        set_config($key . '_body', $legacy, 'local_moderncommerce');
        $seed::upgrade_guest_welcome_template();
        $this->assertSame($legacy, get_config('local_moderncommerce', $key . '_body'));
    }

    /**
     * Create a paid guest with a real delivered renewal link and a completed cooldown.
     *
     * @return array Purchase, renewal token and captured email sink.
     */
    private function renewal_fixture(): array {
        global $DB;
        [$record, $session] = $this->purchase();
        $sink = $this->redirectEmails();
        guest::confirm_session($session);
        $token = '';
        foreach ($sink->get_messages() as $mail) {
            if (preg_match('/guest_access\.php\?token=([a-f0-9]{64})/', quoted_printable_decode($mail->body), $match)) {
                $token = $match[1];
            }
        }
        $this->assertNotEmpty($token);
        $DB->update_record(guest::TABLE, (object)[
            'id' => $record->id, 'mailsent' => time() - 6 * MINSECS, 'timemodified' => time() - 6 * MINSECS,
        ]);
        $saved = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertSame(hash('sha256', $token), $saved->accesshash);
        return [$saved, $token, $sink];
    }

    /**
     * Core's validated first-password form replaces a stale admin destination with My learning.
     */
    public function test_first_password_setup_returns_guest_buyer_to_learning(): void {
        global $DB, $SESSION, $CFG;
        require_once($CFG->dirroot . '/login/lib.php');
        require_once($CFG->dirroot . '/local/moderncommerce/lib.php');
        [$record, $session] = $this->purchase();
        $sink = $this->redirectEmails();
        guest::confirm_session($session);
        $user = \core_user::get_user($DB->get_field(guest::TABLE, 'userid', ['id' => $record->id]));
        $this->setUser($user);
        $SESSION->wantsurl = $CFG->wwwroot . '/local/moderncommerce/admin/pricing.php';
        local_moderncommerce_extend_set_password_form(null, $user);
        $this->assertSame($CFG->wwwroot . '/local/moderncommerce/learner/index.php', core_login_get_return_url());
        $SESSION->wantsurl = $CFG->wwwroot . '/course/index.php';
        $user->password = 'already-established';
        local_moderncommerce_extend_set_password_form(null, $user);
        $this->assertSame($CFG->wwwroot . '/course/index.php', $SESSION->wantsurl);
        $sink->close();
    }

    /**
     * Ordinary users' password reset destinations are unchanged.
     */
    public function test_password_setup_does_not_redirect_unrelated_accounts(): void {
        global $SESSION;
        $user = $this->getDataGenerator()->create_user();
        $user->password = AUTH_PASSWORD_NOT_CACHED;
        $SESSION->wantsurl = '/original-destination';
        guest::prepare_password_setup($user);
        $this->assertSame('/original-destination', $SESSION->wantsurl);
    }

    /**
     * The exact normalized email becomes the username after a paid purchase.
     */
    public function test_paid_guest_username_is_the_email_address(): void {
        global $DB;
        [$record, $session] = $this->purchase();
        $sink = $this->redirectEmails();
        guest::confirm_session($session);
        $userid = $DB->get_field(guest::TABLE, 'userid', ['id' => $record->id]);
        $this->assertSame($record->email, $DB->get_field('user', 'username', ['id' => $userid]));
        $this->assertSame(AUTH_PASSWORD_NOT_CACHED, $DB->get_field('user', 'password', ['id' => $userid]));
        $sink->close();
    }

    /**
     * Both email and username collisions block checkout, including differing account emails.
     */
    public function test_email_check_handles_case_email_and_username_collisions(): void {
        $this->assertTrue(guest::check_email(' New.Buyer@Example.com ')['available']);
        $this->getDataGenerator()->create_user(['email' => 'buyer@example.com']);
        $this->assertSame('guestcheckoutemailinuse', guest::check_email(' BUYER@EXAMPLE.COM ')['errorcode']);
        $this->getDataGenerator()->create_user(['username' => 'reserved@example.com', 'email' => 'other@example.com']);
        $this->assertSame('guestcheckoutemailinuse', guest::check_email('reserved@example.com')['errorcode']);
    }

    /**
     * Do not silently alter valid email characters that Moodle disallows in usernames.
     */
    public function test_email_check_respects_moodle_username_rules(): void {
        global $CFG;
        $CFG->extendedusernamechars = false;
        $this->assertSame('guestcheckoutemailusername', guest::check_email('buyer+courses@example.com')['errorcode']);
        $CFG->extendedusernamechars = true;
        $this->assertTrue(guest::check_email('buyer+courses@example.com')['available']);
        $this->assertFalse(guest::check_email('invalid')['available']);
    }

    /**
     * Server submission rejects a taken username before any provider request or purchase record.
     */
    public function test_start_rechecks_identity_before_contacting_stripe(): void {
        global $DB;
        set_config('guest_checkout_enabled', 1, 'local_moderncommerce');
        $this->getDataGenerator()->create_user(['username' => 'reserved@example.com']);
        $_POST['sesskey'] = sesskey();
        try {
            guest::start(['firstname' => 'Test', 'lastname' => 'Buyer', 'email' => 'reserved@example.com']);
            $this->fail('A reserved username must not reach payment.');
        } catch (moodle_exception $e) {
            $this->assertSame('guestcheckoutemailinuse', $e->errorcode);
        } finally {
            unset($_POST['sesskey']);
        }
        $this->assertSame(0, $DB->count_records(guest::TABLE));
    }

    /**
     * A collision after payment never attaches a purchase to a username owned by another email.
     */
    public function test_late_username_collision_keeps_paid_purchase_recoverable(): void {
        global $DB;
        [$record, $session] = $this->purchase();
        $existing = $this->getDataGenerator()->create_user([
            'username' => $record->email, 'email' => 'different@example.com',
        ]);
        $sink = $this->redirectEmails();
        try {
            guest::confirm_session($session);
            $this->fail('A paid username collision must require recovery.');
        } catch (moodle_exception $e) {
            $this->assertSame('guestcheckoutemailinuse', $e->errorcode);
        }
        $saved = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertSame('paid', $saved->status);
        $this->assertEmpty($saved->userid);
        $this->assertEmpty($saved->orderid);
        $this->assertSame($record->email, $DB->get_field('user', 'username', ['id' => $existing->id]));
        $sink->close();
    }

    /**
     * Anonymous feedback requires the feature, session key, and bounded requests.
     */
    public function test_public_email_feedback_is_session_protected_and_rate_limited(): void {
        global $SESSION, $USER;
        set_config('guest_checkout_enabled', 1, 'local_moderncommerce');
        $this->setUser();
        $USER->ignoresesskey = false;
        $USER->sesskey = 'guestcheckfixture';
        $_POST['sesskey'] = 'guestcheckfixture';
        try {
            $reply = \local_moderncommerce\external\checkout\check_guest_email::execute('fresh@example.com');
            $this->assertTrue($reply['available']);
            $this->assertSame(['available', 'message'], array_keys($reply));
            $SESSION->moderncommerce_email_checks = ['bucket' => (int)floor(time() / (10 * MINSECS)), 'count' => 30];
            try {
                \local_moderncommerce\external\checkout\check_guest_email::execute('another@example.com');
                $this->fail('Session lookup limit must be enforced.');
            } catch (moodle_exception $e) {
                $this->assertSame('guestcheckoutlimited', $e->errorcode);
            }
            unset($_POST['sesskey']);
            $this->expectException(\moodle_exception::class);
            \local_moderncommerce\external\checkout\check_guest_email::execute('fresh@example.com');
        } finally {
            unset($_POST['sesskey']);
        }
    }

    /**
     * A new buyer receives a welcome and the native expiring password-setting link.
     */
    public function test_new_guest_receives_welcome_instead_of_password_reset_copy(): void {
        global $CFG, $DB, $SITE;
        $SITE->fullname = 'Training & Learning';
        $CFG->pwresettime = 45 * MINSECS;
        [$record, $session] = $this->purchase();
        $sink = $this->redirectEmails();
        guest::confirm_session($session);
        $saved = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $user = \core_user::get_user($saved->userid);
        $reset = $DB->get_record('user_password_resets', ['userid' => $user->id], '*', MUST_EXIST);
        $welcome = array_values(array_filter($sink->get_messages(), static fn($mail) =>
            $mail->subject === 'Welcome to Training & Learning — set your password'));
        $this->assertCount(1, $welcome);
        $body = quoted_printable_decode($welcome[0]->body);
        $this->assertSame($record->email, $welcome[0]->to);
        $this->assertStringContainsString('We have created a learning account for you', $body);
        $this->assertStringContainsString('45 minutes', $body);
        $this->assertStringContainsString('token=' . $reset->token, $body);
        $this->assertStringNotContainsString('A password reset was requested', $body);
        $this->assertStringContainsString($user->username, $body);
        $this->assertStringContainsString('After saving your password', $body);
        $this->assertStringContainsString('/local/moderncommerce/learner/index.php', $body);
        $this->assertStringContainsString('/local/moderncommerce/guest_access.php?token=', $body);
        $this->assertSame(AUTH_PASSWORD_NOT_CACHED, $user->password);
        $sink->close();
    }

    /**
     * The existing admin editor exposes the welcome and its saved content controls delivery.
     */
    public function test_admin_can_customize_guest_welcome_without_seed_overwriting_edits(): void {
        global $DB;
        $key = \local_moderncommerce\email\commerce_seed::GUEST_WELCOME;
        \local_moderncommerce\email\commerce_seed::ensure_guest_welcome_template();
        $id = $DB->get_field('local_moderncommerce_emailtpl', 'id', ['template_key' => $key]);
        $DB->set_field('local_moderncommerce_emailtpl', 'subject', 'Preserved subject', ['id' => $id]);
        \local_moderncommerce\email\commerce_seed::ensure_guest_welcome_template();
        $this->assertSame('Preserved subject', $DB->get_field('local_moderncommerce_emailtpl', 'subject', ['id' => $id]));
        $this->assertSame(1, $DB->count_records('local_moderncommerce_emailtpl', ['template_key' => $key]));
        $types = \local_moderncommerce\external\emails\list_emails::execute();
        $this->assertContains($key, array_column($types['items'], 'key'));
        $editor = \local_moderncommerce\external\emails\get_email::execute($key);
        $this->assertContains('{set_password_url}', $editor['placeholders']);
        $this->assertContains('{password_expiry_minutes}', $editor['placeholders']);
        \local_moderncommerce\external\emails\save_email::execute(
            $key,
            true,
            'Your new account at {sitename}',
            '<p>Welcome, {firstname}. <a href="{set_password_url}">Choose your password</a>.</p>'
        );
        [$record, $session] = $this->purchase();
        $sink = $this->redirectEmails();
        guest::confirm_session($session);
        $welcome = array_values(array_filter($sink->get_messages(), static fn($mail) =>
            str_starts_with($mail->subject, 'Your new account at ')));
        $this->assertCount(1, $welcome);
        $this->assertSame($record->email, $welcome[0]->to);
        $this->assertStringContainsString('Welcome, Test.', quoted_printable_decode($welcome[0]->body));
        $this->assertStringContainsString('Choose your password', quoted_printable_decode($welcome[0]->body));
        $sink->close();
    }

    /**
     * Disabling this notification uses the same delivery switch as other commerce emails.
     */
    public function test_disabled_welcome_does_not_generate_or_email_a_password_token(): void {
        global $DB;
        set_config('moderncommerce_guest_welcome_enabled', 0, 'local_moderncommerce');
        [$record, $session] = $this->purchase();
        $sink = $this->redirectEmails();
        guest::confirm_session($session);
        $saved = $DB->get_record(guest::TABLE, ['id' => $record->id]);
        $this->assertSame('fulfilled', $saved->status);
        $this->assertSame(0, $DB->count_records('user_password_resets', ['userid' => $saved->userid]));
        foreach ($sink->get_messages() as $mail) {
            $this->assertStringNotContainsString('forgot_password.php?token=', quoted_printable_decode($mail->body));
            $this->assertStringNotContainsString('set_password.php?token=', quoted_printable_decode($mail->body));
        }
        $sink->close();
    }

    /**
     * A buyer cannot edit the welcome template through the administrator service.
     */
    public function test_guest_welcome_editor_requires_administrator_permission(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        \local_moderncommerce\external\emails\save_email::execute(
            \local_moderncommerce\email\commerce_seed::GUEST_WELCOME,
            true,
            'Unauthorised subject',
            '<p>Unauthorised body</p>'
        );
    }

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
        $this->assertSame(0, $DB->count_records('user_password_resets', ['userid' => $existing->id]));
        foreach ($sink->get_messages() as $mail) {
            $this->assertStringNotContainsString('forgot_password.php?token=', quoted_printable_decode($mail->body));
            $this->assertStringNotContainsString('set_password.php?token=', quoted_printable_decode($mail->body));
        }
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
            'name' => $course->fullname, 'sku' => 'test-' . $course->id,
            'producttype' => 'course', 'slug' => 'test-' . $course->id,
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
