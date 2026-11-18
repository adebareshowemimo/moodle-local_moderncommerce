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
 * Tests for product and bundle enrollment key redemption.
 *
 * @package    local_moderncommerce
 * @category   test
 * @copyright  2026 Adebare Showemimo | adebareshowemimo@gmail.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_moderncommerce\services\key_redemption_service;

/**
 * Verifies key restrictions, compatibility, accounting, and provenance.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(key_redemption_service::class)]
final class key_redemption_service_test extends advanced_testcase {
    /**
     * Legacy direct-course targets remain resolvable and restrictions use key state.
     */
    public function test_legacy_target_and_restrictions_are_enforced(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user(['email' => 'learner@example.com']);
        $course = $this->getDataGenerator()->create_course();
        $keyid = $this->create_key([
            'keycode' => 'KEY-LEGACY',
            'requiredemail' => 'someone@example.com',
        ]);
        $this->create_target($keyid, 'course', (int) $course->id);

        $this->assertSame([(int) $course->id], key_redemption_service::get_target_courseids($keyid));
        $preview = key_redemption_service::preview(' key-legacy ', (int) $user->id);
        $this->assertFalse($preview['valid']);
        $this->assertSame('keynotforyou', $preview['errorkey']);

        $DB->set_field('local_moderncommerce_enrollkeys', 'requiredemail', '@example.com', ['id' => $keyid]);
        $DB->set_field('local_moderncommerce_enrollkeys', 'startdate', time() + HOURSECS, ['id' => $keyid]);
        $preview = key_redemption_service::preview('KEY-LEGACY', (int) $user->id);
        $this->assertFalse($preview['valid']);
        $this->assertSame('keynotstarted', $preview['errorkey']);

        // The authoritative counter continues to enforce the global limit even if
        // privacy deletion has removed personally identifiable usage rows.
        $DB->set_field('local_moderncommerce_enrollkeys', 'startdate', time() - HOURSECS, ['id' => $keyid]);
        $DB->set_field('local_moderncommerce_enrollkeys', 'usedcount', 1, ['id' => $keyid]);
        $preview = key_redemption_service::preview('KEY-LEGACY', (int) $user->id);
        $this->assertFalse($preview['valid']);
        $this->assertSame('keylimitreached', $preview['errorkey']);
    }

    /**
     * A bundle consumes one use and records product lineage for every entitlement.
     */
    public function test_bundle_redemption_records_one_use_and_product_provenance(): void {
        global $DB;

        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user(['email' => 'bundle@example.com']);
        $this->setUser($user);
        $courseone = $this->getDataGenerator()->create_course();
        $coursetwo = $this->getDataGenerator()->create_course();
        $productid = $this->create_product('bundle', 'Key Bundle');
        $this->add_product_course($productid, (int) $courseone->id, 0);
        $this->add_product_course($productid, (int) $coursetwo->id, 1);

        $roleid = create_role('Key learner', 'keylearner', 'Role assigned by an enrollment key');
        $keyid = $this->create_key([
            'keycode' => 'KEY-BUNDLE',
            'maxuses' => 5,
            'enrolduration' => HOURSECS,
            'roleid' => $roleid,
        ]);
        $this->create_target($keyid, 'product', $productid);

        $result = key_redemption_service::redeem(' key-bundle ', (int) $user->id);
        $this->assertCount(2, $result['courses']);
        $this->assertSame(0, $result['alreadyenrolled']);
        $this->assertSame(1, $DB->count_records('local_moderncommerce_key_usage', ['enrollkeyid' => $keyid]));
        $this->assertSame(1, (int) $DB->get_field('local_moderncommerce_enrollkeys', 'usedcount', ['id' => $keyid]));

        $usage = $DB->get_record('local_moderncommerce_key_usage', ['enrollkeyid' => $keyid], '*', MUST_EXIST);
        $this->assertSame($productid, (int) $usage->productid);
        $this->assertNull($usage->courseid);

        $entitlements = $DB->get_records('local_moderncommerce_entitlements', [
            'userid' => $user->id,
            'enrollkeyid' => $keyid,
        ]);
        $this->assertCount(2, $entitlements);
        foreach ($entitlements as $entitlement) {
            $this->assertSame($productid, (int) $entitlement->productid);
            $this->assertSame(HOURSECS, (int) $entitlement->timeend - (int) $entitlement->timestart);
            $coursecontext = context_course::instance((int) $entitlement->courseid);
            $this->assertTrue(user_has_role_assignment((int) $user->id, $roleid, $coursecontext->id));
        }
    }

    /**
     * Insert a complete enrollment key record.
     *
     * @param array $overrides Field overrides.
     * @return int Key ID.
     */
    private function create_key(array $overrides = []): int {
        global $DB;

        $now = time();
        $record = (object) array_merge([
            'keycode' => 'KEY-TEST',
            'keytype' => 'single',
            'value' => 0,
            'currency' => 'USD',
            'remainingvalue' => 0,
            'maxuses' => 1,
            'usedcount' => 0,
            'maxusesperuser' => 1,
            'status' => 'active',
            'startdate' => $now - 60,
            'expirydate' => $now + DAYSECS,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $overrides);

        return (int) $DB->insert_record('local_moderncommerce_enrollkeys', $record);
    }

    /**
     * Insert a key target.
     *
     * @param int $keyid Key ID.
     * @param string $targettype Target type.
     * @param int $targetid Target ID.
     */
    private function create_target(int $keyid, string $targettype, int $targetid): void {
        global $DB;

        $DB->insert_record('local_moderncommerce_enrollkey_targets', (object) [
            'enrollkeyid' => $keyid,
            'targettype' => $targettype,
            'targetid' => $targetid,
            'includemode' => 'include',
            'timecreated' => time(),
        ]);
    }

    /**
     * Insert a catalogue product.
     *
     * @param string $producttype Product type.
     * @param string $name Product name.
     * @return int Product ID.
     */
    private function create_product(string $producttype, string $name): int {
        global $DB;

        return (int) $DB->insert_record('local_moderncommerce_products', (object) [
            'producttype' => $producttype,
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)),
            'sku' => strtoupper(str_replace(' ', '-', $name)),
            'status' => 'active',
            'visible' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Attach a course to a product.
     *
     * @param int $productid Product ID.
     * @param int $courseid Course ID.
     * @param int $sortorder Sort order.
     */
    private function add_product_course(int $productid, int $courseid, int $sortorder): void {
        global $DB;

        $DB->insert_record('local_moderncommerce_product_courses', (object) [
            'productid' => $productid,
            'courseid' => $courseid,
            'relationtype' => 'included',
            'sortorder' => $sortorder,
            'required' => 1,
            'timecreated' => time(),
        ]);
    }
}
