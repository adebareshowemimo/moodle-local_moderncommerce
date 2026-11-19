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

namespace local_moderncommerce\external\checkout;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_moderncommerce\services\guest_checkout_service as guest;

/**
 * Session-protected, rate-limited eligibility feedback for anonymous buyers.
 *
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check_guest_email extends external_api {
    /**
     * Parameters.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email' => new external_value(PARAM_TEXT, 'Email to use as the new account username.'),
        ]);
    }

    /**
     * Return only eligibility feedback, without user records or identifiers.
     * @param string $email Email address.
     * @return array
     */
    public static function execute(string $email): array {
        global $PAGE;
        $params = self::validate_parameters(self::execute_parameters(), ['email' => $email]);
        if (!guest::enabled()) {
            throw new \moodle_exception('guestcheckoutdisabled', 'local_moderncommerce');
        }
        // This public checkout deliberately supports anonymous sessions. validate_context()
        // requires login; cart_userid() checks sesskey, feature enablement and guest catalog access instead.
        guest::cart_userid();
        $PAGE->set_context(\context_system::instance());
        guest::limit_email_checks();
        $result = guest::check_email($params['email']);
        return ['available' => $result['available'], 'message' => $result['message']];
    }

    /**
     * Returns.
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'available' => new external_value(PARAM_BOOL, 'Whether a new guest account can use this email.'),
            'message' => new external_value(PARAM_TEXT, 'Localized eligibility feedback.'),
        ]);
    }
}
