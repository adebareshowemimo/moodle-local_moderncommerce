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

use local_moderncommerce\payment\gateway_manager;

/**
 * Hosted guest payments without creating a user or an order before payment.
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guest_gateway_service {
    /**
     * Ready guest payment methods in the configured display order.
     * @param string $currency Cart currency.
     * @return array
     */
    public static function methods(string $currency): array {
        $methods = [];
        foreach (gateway_manager::get_payment_methods() as $id => $method) {
            if (!in_array($id, ['stripe', 'paypal', 'paystack', 'flutterwave', 'manual'], true)) {
                continue;
            }
            if (!gateway_manager::get_gateway_readiness($id, (object)['currency' => $currency, 'total' => 1])->ready) {
                continue;
            }
            $config = gateway_manager::get_gateway_config($id);
            if ($id === 'stripe' && empty($config['webhook_secret'])) {
                continue;
            }
            $methods[$id] = $method;
        }
        return $methods;
    }

    /**
     * Match the actual provider API environment rather than browser-supplied flags.
     * @param string $gateway Provider ID.
     * @return bool
     */
    public static function testmode(string $gateway): bool {
        $config = gateway_manager::get_gateway_config($gateway);
        if ($gateway === 'stripe') {
            return str_contains($config['secret_key'] ?? '', '_test_');
        }
        if ($gateway === 'manual') {
            return false;
        }
        return !empty($config['testmode']) || ($gateway === 'paypal' && !empty($config['sandbox_mode']));
    }

    /**
     * Initialise a provider session with an immutable reference and snapshot totals.
     * @param object $record Guest purchase.
     * @param array $snapshot Frozen prices and buyer details.
     * @param string $token Browser confirmation capability.
     * @return array
     */
    public static function create(object $record, array $snapshot, string $token): array {
        $id = $record->gateway;
        $return = (new \moodle_url('/local/moderncommerce/guest_checkout.php', ['guesttoken' => $token]))->out(false);
        if ($id === 'manual') {
            return ['id' => $record->providerreference, 'url' => $return];
        }
        $gateway = gateway_manager::get_gateway($id);
        $currency = strtoupper($snapshot['totals']['currency']);
        $amount = (float)$snapshot['totals']['total'];
        if ($id === 'paystack') {
            $response = $gateway->guest_request('POST', '/transaction/initialize', [
                'email' => $record->email, 'amount' => (int)round($amount * 100), 'currency' => $currency,
                'reference' => $record->providerreference, 'callback_url' => $return,
                'metadata' => ['guest_checkout_id' => $record->id],
            ]);
            if (empty($response['status']) || ($response['data']['reference'] ?? '') !== $record->providerreference) {
                throw new \moodle_exception('paymentinitfailed', 'local_moderncommerce');
            }
            return self::checkout($record->providerreference, $response['data']['authorization_url'] ?? '');
        }
        if ($id === 'flutterwave') {
            $response = $gateway->guest_request('POST', '/payments', [
                'tx_ref' => $record->providerreference, 'amount' => $amount, 'currency' => $currency,
                'redirect_url' => $return,
                'customer' => ['email' => $record->email,
                    'name' => $snapshot['billing']['firstname'] . ' ' . $snapshot['billing']['lastname']],
                'meta' => ['guest_checkout_id' => $record->id],
                'customizations' => ['title' => get_string('guestcheckoutpayment', 'local_moderncommerce')],
            ]);
            if (($response['status'] ?? '') !== 'success') {
                throw new \moodle_exception('paymentinitfailed', 'local_moderncommerce');
            }
            return self::checkout($record->providerreference, $response['data']['link'] ?? '');
        }
        if ($id === 'paypal') {
            $response = $gateway->guest_request('POST', '/v2/checkout/orders', [
                'intent' => 'CAPTURE', 'purchase_units' => [[
                    'reference_id' => 'guest:' . $record->id, 'custom_id' => 'guest:' . $record->id,
                    'amount' => ['currency_code' => $currency, 'value' => self::decimal($amount, $currency)],
                ]],
                'application_context' => ['return_url' => $return, 'cancel_url' => $return, 'user_action' => 'PAY_NOW'],
            ], 'mc-guest-' . $record->id . '-create');
            foreach ($response['links'] ?? [] as $link) {
                if (($link['rel'] ?? '') === 'approve') {
                    return self::checkout((string)($response['id'] ?? ''), $link['href'] ?? '');
                }
            }
        }
        throw new \moodle_exception('paymentinitfailed', 'local_moderncommerce');
    }

    /**
     * Query authoritative API state. Browser status, amount and transaction IDs are never used.
     * @param object $record Guest purchase.
     * @return array|null Verified normalized payment or null while unpaid.
     */
    public static function verify(object $record): ?array {
        $snapshot = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
        $id = $record->gateway;
        if ($id === 'manual') {
            return null;
        }
        if (self::testmode($id) !== (bool)$snapshot['testmode']) {
            throw new \moodle_exception('guestcheckoutverification', 'local_moderncommerce');
        }
        $gateway = gateway_manager::get_gateway($id);
        $ref = rawurlencode($record->providerreference);
        if ($id === 'paystack') {
            $response = $gateway->guest_request('GET', '/transaction/verify/' . $ref);
            $data = $response['data'] ?? [];
            if (empty($response['status'])) {
                return null;
            }
            $metadata = $data['metadata'] ?? [];
            if (is_string($metadata)) {
                $metadata = json_decode($metadata, true) ?: [];
            }
            self::check(
                ($data['reference'] ?? '') === $record->providerreference &&
                (int)($metadata['guest_checkout_id'] ?? 0) === (int)$record->id &&
                ($data['domain'] ?? '') === ($snapshot['testmode'] ? 'test' : 'live') &&
                (int)($data['amount'] ?? -1) === (int)round($snapshot['totals']['total'] * 100)
            );
            self::money($snapshot, (float)($data['amount'] ?? 0) / 100, $data['currency'] ?? '');
            return ($data['status'] ?? '') === 'success'
                ? self::payment($record, (string)$data['id'], $snapshot) : null;
        }
        if ($id === 'flutterwave') {
            $response = $gateway->guest_request('GET', '/transactions/verify_by_reference?tx_ref=' . $ref);
            if (($response['status'] ?? '') !== 'success') {
                return null;
            }
            $data = $response['data'] ?? [];
            self::check(($data['tx_ref'] ?? '') === $record->providerreference);
            self::money($snapshot, (float)($data['amount'] ?? -1), $data['currency'] ?? '');
            if (isset($data['meta']['guest_checkout_id'])) {
                self::check((int)$data['meta']['guest_checkout_id'] === (int)$record->id);
            }
            return ($data['status'] ?? '') === 'successful'
                ? self::payment($record, (string)$data['id'], $snapshot) : null;
        }
        if ($id === 'paypal') {
            $response = $gateway->guest_request('GET', '/v2/checkout/orders/' . $ref);
            self::paypal_order($record, $snapshot, $response);
            if (($response['status'] ?? '') === 'APPROVED') {
                // Capture only after buyer approval and verification of the approved amount/reference.
                $gateway->guest_request(
                    'POST',
                    '/v2/checkout/orders/' . $ref . '/capture',
                    [],
                    'mc-guest-' . $record->id . '-capture'
                );
                $response = $gateway->guest_request('GET', '/v2/checkout/orders/' . $ref);
                self::paypal_order($record, $snapshot, $response);
            }
            $captures = $response['purchase_units'][0]['payments']['captures'] ?? [];
            if (
                ($response['status'] ?? '') !== 'COMPLETED' || count($captures) !== 1 ||
                    !in_array($captures[0]['status'] ?? '', ['COMPLETED', 'REFUNDED'], true)
            ) {
                return null;
            }
            self::money(
                $snapshot,
                (float)($captures[0]['amount']['value'] ?? -1),
                $captures[0]['amount']['currency_code'] ?? ''
            );
            $payment = self::payment($record, (string)$captures[0]['id'], $snapshot);
            $payment['refunded'] = $captures[0]['status'] === 'REFUNDED';
            return $payment;
        }
        throw new \moodle_exception('guestcheckoutverification', 'local_moderncommerce');
    }

    /**
     * Dispatch only after each gateway's existing signature verification has succeeded.
     * @param string $gateway Gateway ID.
     * @param array $payload Verified webhook payload.
     * @return bool|null Null means this is a normal authenticated checkout event.
     */
    public static function webhook(string $gateway, array $payload): ?bool {
        global $DB;
        $data = $payload[$gateway === 'paypal' ? 'resource' : 'data'] ?? [];
        $reference = $gateway === 'paystack' ? ($data['reference'] ?? '') : ($data['tx_ref'] ?? '');
        if ($gateway === 'paypal') {
            $reference = $data['supplementary_data']['related_ids']['order_id'] ??
                (($payload['event_type'] ?? '') === 'CHECKOUT.ORDER.APPROVED' ? ($data['id'] ?? '') : '');
        }
        $record = $reference === '' ? false : $DB->get_record(
            guest_checkout_service::TABLE,
            ['gateway' => $gateway, 'providerreference' => $reference]
        );
        if (!$record) {
            $guestid = $data['metadata']['guest_checkout_id'] ?? $data['meta']['guest_checkout_id'] ?? 0;
            if ($gateway === 'paypal' && preg_match('/^guest:(\d+)$/D', $data['custom_id'] ?? '', $match)) {
                $guestid = (int)$match[1];
            }
            $record = $guestid ? $DB->get_record(
                guest_checkout_service::TABLE,
                ['id' => (int)$guestid, 'gateway' => $gateway]
            ) : false;
        }
        if (!$record && $gateway === 'paypal') {
            $capture = $data['supplementary_data']['related_ids']['capture_id'] ?? '';
            foreach ($data['links'] ?? [] as $link) {
                if (
                    ($link['rel'] ?? '') === 'up' && preg_match(
                        '~/v2/payments/captures/([a-zA-Z0-9]+)$~',
                        parse_url($link['href'] ?? '', PHP_URL_PATH) ?? '',
                        $match
                    )
                ) {
                    $capture = $match[1];
                }
            }
            $record = $capture ? $DB->get_record(
                guest_checkout_service::TABLE,
                ['gateway' => $gateway, 'providertransactionid' => $capture]
            ) : false;
        }
        if (!$record) {
            return null;
        }
        if ($gateway === 'paypal' && ($payload['event_type'] ?? '') === 'PAYMENT.CAPTURE.REFUNDED') {
            $snapshot = json_decode($record->payload, true, 512, JSON_THROW_ON_ERROR);
            self::check(($data['status'] ?? '') === 'COMPLETED');
            $amount = (float)($data['amount']['value'] ?? -1);
            $currency = $data['amount']['currency_code'] ?? '';
            self::check(strtoupper($currency) === strtoupper($snapshot['totals']['currency']) && $amount > 0);
            if (
                guest_checkout_service::minor_amount($amount, $currency) <
                    guest_checkout_service::minor_amount((float)$snapshot['totals']['total'], $currency)
            ) {
                // Partial refunds are audited without revoking a whole course purchase.
                \local_moderncommerce\logging\paylog_service::log(
                    $record->orderid ?: null,
                    $gateway,
                    'guest_partial_refund',
                    $data['id'] ?? '',
                    $data
                );
                return true;
            }
            self::money($snapshot, $amount, $currency);
            self::refund($record, (string)($payload['id'] ?? $data['id'] ?? ''));
            return true;
        }
        // API reconciliation, not webhook body fields, determines payment and fulfilment.
        guest_checkout_service::reconcile($record);
        return true;
    }

    /**
     * Apply an authenticated full refund while serializing against fulfilment.
     * @param object $record Guest purchase.
     * @param string $eventid Verified event identifier.
     */
    public static function refund(object $record, string $eventid): void {
        $orderid = guest_checkout_service::mark_provider_refund((int)$record->id, $record->gateway);
        if ($orderid) {
            $order = \local_moderncommerce\api\order_api::get_order($orderid);
            gateway_manager::record_payment_result($order, $record->gateway, (object)[
                'status' => 'refunded', 'orderid' => $orderid, 'orderreference' => $order->ordernumber,
                'gatewayreference' => $record->providerreference, 'gatewaytransactionid' => $record->providertransactionid,
                'amount' => (float)$order->total, 'currency' => $order->currency,
                'source' => 'gateway_api', 'eventtype' => 'guest.payment.refunded', 'gatewayeventid' => $eventid,
            ]);
            \local_moderncommerce\api\order_api::update_order_status($orderid, 'refunded');
        }
    }

    /**
     * Reject mismatched authoritative provider data.
     * @param bool $valid Validation result.
     */
    private static function check(bool $valid): void {
        if (!$valid) {
            throw new \moodle_exception('guestcheckoutverification', 'local_moderncommerce');
        }
    }

    /**
     * Compare authoritative totals with the frozen purchase.
     * @param array $snapshot Frozen totals.
     * @param float $amount Verified amount.
     * @param string $currency Currency.
     */
    private static function money(array $snapshot, float $amount, string $currency): void {
        self::check(strtoupper($currency) === strtoupper($snapshot['totals']['currency']) &&
            guest_checkout_service::minor_amount($amount, $currency) ===
                guest_checkout_service::minor_amount((float)$snapshot['totals']['total'], $currency));
    }

    /**
     * Validate a PayPal order before capture or fulfilment.
     * @param object $record Purchase.
     * @param array $snapshot Totals.
     * @param array $response PayPal order.
     */
    private static function paypal_order(object $record, array $snapshot, array $response): void {
        self::check(($response['id'] ?? '') === $record->providerreference && count($response['purchase_units'] ?? []) === 1);
        $unit = $response['purchase_units'][0];
        self::check(($unit['custom_id'] ?? '') === 'guest:' . $record->id &&
            ($unit['reference_id'] ?? '') === 'guest:' . $record->id);
        self::money($snapshot, (float)($unit['amount']['value'] ?? -1), $unit['amount']['currency_code'] ?? '');
    }

    /**
     * Normalize verified provider data.
     * @param object $record Purchase.
     * @param string $transaction Provider transaction.
     * @param array $snapshot Totals.
     * @return array
     */
    private static function payment(object $record, string $transaction, array $snapshot): array {
        self::check($transaction !== '');
        return ['id' => $record->providerreference, 'payment_intent' => $transaction,
            'amount_total' => guest_checkout_service::minor_amount(
                (float)$snapshot['totals']['total'],
                $snapshot['totals']['currency']
            ),
            'currency' => $snapshot['totals']['currency'], 'source' => 'gateway_api', 'eventtype' => 'guest.payment.verified'];
    }

    /**
     * Validate the hosted checkout destination.
     * @param string $id Provider reference.
     * @param string $url Hosted URL.
     * @return array
     */
    private static function checkout(string $id, string $url): array {
        self::check($id !== '' && parse_url($url, PHP_URL_SCHEME) === 'https');
        return ['id' => $id, 'url' => $url];
    }

    /**
     * Format a provider decimal amount.
     * @param float $amount Decimal amount.
     * @param string $currency Currency.
     * @return string
     */
    private static function decimal(float $amount, string $currency): string {
        $digits = guest_checkout_service::minor_amount(1.0, $currency) === 1 ? 0 :
            (guest_checkout_service::minor_amount(1.0, $currency) === 1000 ? 3 : 2);
        return number_format($amount, $digits, '.', '');
    }
}
