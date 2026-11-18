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
 * External API generating enrollment keys for a target.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moderncommerce\external\enrollkeys;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;
use local_moderncommerce\services\key_redemption_service;
use local_moderncommerce\services\pricing_service;

/**
 * Generate one or more enrollment keys targeting a course or product.
 */
class generate_keys extends external_api {
    /**
     * @var int Maximum keys generated per request.
     */
    private const MAX_QUANTITY = 500;

    /**
     * @var string[] Sentinel target types for keys the learner redeems against a chosen item.
     */
    private const TARGET_ANY_TYPES = [
        key_redemption_service::TARGET_ANY_COURSE,
        key_redemption_service::TARGET_ANY_PRODUCT,
    ];

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'targettype' => new external_value(
                PARAM_ALPHANUMEXT,
                'Target type: product or anyproduct.'
            ),
            'targetid' => new external_value(PARAM_INT, 'Target product ID (ignored when targettype is anyproduct).'),
            'targetids' => new external_value(
                PARAM_SEQUENCE,
                'Comma separated target IDs for a multi-target key. Overrides targetid when set.',
                VALUE_DEFAULT,
                ''
            ),
            'quantity' => new external_value(PARAM_INT, 'Number of keys to generate.', VALUE_DEFAULT, 1),
            'keytype' => new external_value(PARAM_ALPHA, 'Key type: single or multi.', VALUE_DEFAULT, 'single'),
            'maxuses' => new external_value(PARAM_INT, 'Max uses per key (0 = unlimited).', VALUE_DEFAULT, 1),
            'validdays' => new external_value(PARAM_INT, 'Days until expiry (0 = no expiry).', VALUE_DEFAULT, 90),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $targettype Target type.
     * @param int $targetid Target ID.
     * @param string $targetids Comma separated target IDs (overrides $targetid when non-empty).
     * @param int $quantity Quantity.
     * @param string $keytype Key type.
     * @param int $maxuses Max uses.
     * @param int $validdays Valid days.
     * @return array
     */
    public static function execute(
        string $targettype,
        int $targetid,
        string $targetids = '',
        int $quantity = 1,
        string $keytype = 'single',
        int $maxuses = 1,
        int $validdays = 90
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'targettype' => $targettype,
            'targetid' => $targetid,
            'targetids' => $targetids,
            'quantity' => $quantity,
            'keytype' => $keytype,
            'maxuses' => $maxuses,
            'validdays' => $validdays,
        ]);

        require_login();
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/moderncommerce:generatekeys', $context);

        // An "any" key carries a single sentinel target with no target ID: the learner
        // picks the catalogue item at redemption time.
        if (in_array($params['targettype'], self::TARGET_ANY_TYPES, true)) {
            $targettype = (string) $params['targettype'];
            $targetids = [0];
        } else {
            // Modern Commerce sells products, not courses: every key targets a catalogue
            // product (course, bundle or program), which then resolves to its courses.
            $targettype = 'product';

            // The targetids list (multi-target) wins over the legacy scalar targetid when supplied.
            $targetids = array_values(array_unique(array_filter(array_map(
                'intval',
                explode(',', (string) $params['targetids'])
            ))));
            if (empty($targetids)) {
                $targetids = [(int) $params['targetid']];
            }

            foreach ($targetids as $id) {
                if (!self::target_exists($targettype, $id)) {
                    return self::failure(get_string('invalidkeytarget', 'local_moderncommerce'));
                }
            }
        }

        $quantity = max(1, min(self::MAX_QUANTITY, (int) $params['quantity']));
        $keytype = $params['keytype'] === 'multi' ? 'multi' : 'single';
        $maxuses = $keytype === 'single' ? 1 : max(0, (int) $params['maxuses']);
        $validdays = max(0, (int) $params['validdays']);
        $currency = pricing_service::get_currency_config()->currency;
        $now = time();
        $expirydate = $validdays > 0 ? $now + ($validdays * DAYSECS) : 0;

        $transaction = $DB->start_delegated_transaction();
        $generated = [];
        $keyids = [];

        try {
            for ($i = 0; $i < $quantity; $i++) {
                do {
                    // 128 bits of CSPRNG entropy keeps bulk-generated codes impractical to guess.
                    $keycode = 'KEY-' . strtoupper(bin2hex(random_bytes(16)));
                } while ($DB->record_exists('local_moderncommerce_enrollkeys', ['keycode' => $keycode]));
                $keyid = (int) $DB->insert_record('local_moderncommerce_enrollkeys', (object) [
                    'keycode' => $keycode,
                    'keytype' => $keytype,
                    'value' => 0,
                    'currency' => $currency,
                    'remainingvalue' => 0,
                    'status' => 'active',
                    'maxuses' => $maxuses,
                    'usedcount' => 0,
                    'maxusesperuser' => 1,
                    'startdate' => $now,
                    'expirydate' => $expirydate,
                    'createdby' => $USER->id,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $keyids[] = $keyid;

                foreach ($targetids as $id) {
                    $DB->insert_record('local_moderncommerce_enrollkey_targets', (object) [
                        'enrollkeyid' => $keyid,
                        'targettype' => $targettype,
                        // An "any" target is a sentinel: it names no course or product.
                        'targetid' => in_array($targettype, self::TARGET_ANY_TYPES, true) ? null : $id,
                        'targetvalue' => null,
                        'includemode' => 'include',
                        'timecreated' => $now,
                    ]);
                }

                $generated[] = $keycode;
            }

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
            return self::failure($e->getMessage());
        }

        \local_moderncommerce\audit\audit_service::record('enrollment_keys_generated', 'enrollment_key_batch', 0, [
            'newdata' => [
                'targettype' => $targettype,
                'targetids' => $targetids,
                'quantity' => count($generated),
                'keytype' => $keytype,
                'maxuses' => $maxuses,
                'validdays' => $validdays,
                'keyids' => $keyids,
            ],
            'severity' => 'warning',
        ]);

        self::notify_keys_generated($targettype, $targetids, count($generated));

        return [
            'success' => true,
            'generated' => count($generated),
            'keycodes' => $generated,
            'message' => get_string('keysgeneratedsuccess', 'local_moderncommerce', count($generated)),
            'warnings' => [],
        ];
    }

    /**
     * Notify the generating user that their key batch is ready, through the hub.
     *
     * @param string $targettype Target type (product|anyproduct).
     * @param int[] $targetids Target ids (empty/ignored for an "any" key).
     * @param int $count Number of keys generated.
     * @return void
     */
    private static function notify_keys_generated(string $targettype, array $targetids, int $count): void {
        global $USER, $CFG, $DB;

        if ($count <= 0) {
            return;
        }

        $targetname = self::describe_targets($targettype, $targetids);
        $dashurl = $CFG->wwwroot . '/local/moderncommerce/index.php';

        $notification = (new \local_moderncommerce\notifications\notification('local_moderncommerce', 'keys_generated'))
            ->category('transactional')
            ->template('moderncommerce_keys_generated')
            ->to_user((int) $USER->id)
            ->placeholders([
                'key_count' => $count,
                'key_target_name' => $targetname,
                'keys_csv_url' => $dashurl,
                'manager_dashboard_url' => $dashurl,
            ])
            ->context_url($dashurl)
            ->related((int) (reset($targetids) ?: 0));

        \local_moderncommerce\notifications\api::notify($notification);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Whether keys were generated.'),
            'generated' => new external_value(PARAM_INT, 'Number of keys generated.'),
            'keycodes' => new external_multiple_structure(new external_value(PARAM_TEXT, 'Generated key code.')),
            'message' => new external_value(PARAM_TEXT, 'Result message.'),
            'warnings' => new external_warnings(),
        ]);
    }

    /**
     * Check the target exists.
     *
     * @param string $targettype Target type.
     * @param int $targetid Target ID.
     * @return bool
     */
    private static function target_exists(string $targettype, int $targetid): bool {
        global $DB;

        if (in_array($targettype, self::TARGET_ANY_TYPES, true)) {
            // An "any" key names no target, so there is nothing to verify.
            return true;
        }

        return $DB->record_exists('local_moderncommerce_products', ['id' => $targetid]);
    }

    /**
     * Human readable description of what a key batch targets, for notifications.
     *
     * @param string $targettype Target type (course|product|any).
     * @param int[] $targetids Target ids.
     * @return string
     */
    private static function describe_targets(string $targettype, array $targetids): string {
        global $DB;

        if (in_array($targettype, self::TARGET_ANY_TYPES, true)) {
            return $targettype === key_redemption_service::TARGET_ANY_PRODUCT
                ? get_string('anybundleorprogram', 'local_moderncommerce')
                : get_string('anycourseproduct', 'local_moderncommerce');
        }

        $names = [];
        foreach ($targetids as $id) {
            $names[] = (string) $DB->get_field('local_moderncommerce_products', 'name', ['id' => $id]);
        }
        $names = array_values(array_filter($names));

        if (empty($names)) {
            return '';
        }
        if (count($names) === 1) {
            return $names[0];
        }

        // Name the first target and count the rest, so the message stays short for large batches.
        return get_string('targetplusmore', 'local_moderncommerce', (object) [
            'name' => $names[0],
            'count' => count($names) - 1,
        ]);
    }

    /**
     * Build a failure response.
     *
     * @param string $message Message.
     * @return array
     */
    private static function failure(string $message): array {
        return [
            'success' => false,
            'generated' => 0,
            'keycodes' => [],
            'message' => $message,
            'warnings' => [],
        ];
    }
}
