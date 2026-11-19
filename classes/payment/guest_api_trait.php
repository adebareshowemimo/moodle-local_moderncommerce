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

namespace local_moderncommerce\payment;

/**
 * Authenticated provider transport for the internal guest payment adapter.
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait guest_api_trait {
    /**
     * Request a provider-relative endpoint without creating a premature Moodle order.
     * @param string $method HTTP method.
     * @param string $path API path.
     * @param array $data Payload.
     * @param string $requestid PayPal idempotency key.
     * @return array
     */
    public function guest_request(string $method, string $path, array $data = [], string $requestid = ''): array {
        global $CFG;
        if ($this->get_name() !== 'paypal') {
            $response = $this->make_request($method, $path, $data);
            if (!is_array($response)) {
                throw new \moodle_exception('guestcheckoutverification', 'local_moderncommerce');
            }
            return $response;
        }
        require_once($CFG->libdir . '/filelib.php');
        $token = $this->request_access_token();
        if ($token === '') {
            throw new \moodle_exception('gatewaynotconfigured', 'local_moderncommerce', '', 'PayPal');
        }
        $curl = new \curl();
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        if ($requestid !== '') {
            $headers[] = 'PayPal-Request-Id: ' . $requestid;
        }
        $curl->setHeader($headers);
        $url = $this->paypal_api_base_url() . $path;
        $options = ['CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_TIMEOUT' => 30];
        $raw = $method === 'POST'
            ? $curl->post($url, $data ? json_encode($data) : '{}', $options)
            : $curl->get($url, [], $options);
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        if ($curl->get_errno() || $code < 200 || $code >= 300) {
            throw new \moodle_exception('paymentverifyfailed', 'local_moderncommerce');
        }
        $response = json_decode((string)$raw, true);
        if (!is_array($response)) {
            throw new \moodle_exception('paymentverifyfailed', 'local_moderncommerce');
        }
        return $response;
    }
}
