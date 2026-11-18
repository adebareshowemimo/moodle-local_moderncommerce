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
 * Tests for the enrolment-key expiry task.
 *
 * @package    local_moderncommerce
 * @category   test
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_moderncommerce\task\expire_keys;

/**
 * Verifies enrolment keys are expired using the canonical expirydate field.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(expire_keys::class)]
final class expire_keys_task_test extends advanced_testcase {
    /**
     * Only active keys with an expiry date in the past are expired.
     */
    public function test_execute_expires_only_past_active_keys(): void {
        global $DB;

        $this->resetAfterTest();
        $now = time();

        $expiredid = $this->create_key('PAST-ACTIVE', 'active', $now - HOURSECS);
        $futureid = $this->create_key('FUTURE-ACTIVE', 'active', $now + HOURSECS);
        $noexpiryid = $this->create_key('NO-EXPIRY', 'active', null);
        $inactiveid = $this->create_key('PAST-INACTIVE', 'inactive', $now - HOURSECS);

        ob_start();
        (new expire_keys())->execute();
        ob_end_clean();

        $this->assertSame('expired', $DB->get_field('local_moderncommerce_enrollkeys', 'status', ['id' => $expiredid]));
        $this->assertSame('active', $DB->get_field('local_moderncommerce_enrollkeys', 'status', ['id' => $futureid]));
        $this->assertSame('active', $DB->get_field('local_moderncommerce_enrollkeys', 'status', ['id' => $noexpiryid]));
        $this->assertSame('inactive', $DB->get_field('local_moderncommerce_enrollkeys', 'status', ['id' => $inactiveid]));
    }

    /**
     * Creates an enrolment key fixture.
     *
     * @param string $keycode Key code.
     * @param string $status Initial status.
     * @param int|null $expirydate Expiry timestamp, or null for no expiry.
     * @return int Record ID.
     */
    private function create_key(string $keycode, string $status, ?int $expirydate): int {
        global $DB;

        $now = time();
        return (int) $DB->insert_record('local_moderncommerce_enrollkeys', (object) [
            'keycode' => $keycode,
            'keytype' => 'single',
            'value' => 0,
            'currency' => 'USD',
            'remainingvalue' => 0,
            'usedcount' => 0,
            'maxusesperuser' => 1,
            'status' => $status,
            'expirydate' => $expirydate,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }
}
