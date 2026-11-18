<?php
// This file is part of Moodle - http://moodle.org/
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
 * Privacy coverage for learner progress and storefront authorship.
 *
 * @package    local_moderncommerce
 * @category   test
 * @copyright  2025 Adebare Showemimo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\writer;
use local_moderncommerce\privacy\provider;

/**
 * User exports and erasure preserve public pages while removing personal references.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Program and guest records remain discoverable after an email address changes.
     */
    public function test_export_and_erasure_cover_progress_pages_and_linked_guest_purchases(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $now = time();
        $programid = $DB->insert_record('local_moderncommerce_products', (object)[
            'name' => 'Privacy program', 'slug' => 'privacy-program', 'producttype' => 'program',
            'status' => 'active', 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $progressid = $DB->insert_record('local_moderncommerce_program_progress', (object)[
            'programid' => $programid, 'userid' => $user->id, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $otherprogressid = $DB->insert_record('local_moderncommerce_program_progress', (object)[
            'programid' => $programid, 'userid' => $other->id, 'timecreated' => $now, 'timemodified' => $now,
        ]);
        $pageid = $DB->insert_record('local_moderncommerce_storepage', (object)[
            'slug' => 'privacy-page', 'title' => 'Public page', 'usermodified' => $user->id,
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $guestid = $DB->insert_record('local_moderncommerce_guestpay', (object)[
            'tokenhash' => hash('sha256', 'privacy-purchase'), 'sessionhash' => hash('sha256', 'privacy-browser'),
            'email' => 'previous-address@example.invalid', 'userid' => $user->id, 'payload' => '{}',
            'timecreated' => $now, 'timemodified' => $now,
        ]);
        $context = context_system::instance();
        $contexts = provider::get_contexts_for_userid($user->id);
        $this->assertEquals([$context->id], $contexts->get_contextids());
        $approved = new approved_contextlist($user, 'local_moderncommerce', [$context->id]);
        provider::export_user_data($approved);
        $export = writer::with_context($context);
        $path = [get_string('pluginname', 'local_moderncommerce')];
        $progress = $export->get_related_data($path, 'program_progress');
        $pages = $export->get_related_data($path, 'storepage_admin_references');
        $guests = $export->get_related_data($path, 'guest_purchases_linked');
        $this->assertSame((int)$progressid, (int)$progress->records[0]['id']);
        $this->assertSame((int)$pageid, (int)$pages->records[0]['id']);
        $this->assertSame((int)$guestid, (int)$guests->records[0]['id']);
        provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists('local_moderncommerce_program_progress', ['id' => $progressid]));
        $this->assertFalse($DB->record_exists('local_moderncommerce_guestpay', ['id' => $guestid]));
        $this->assertTrue($DB->record_exists('local_moderncommerce_program_progress', ['id' => $otherprogressid]));
        $this->assertSame(0, (int)$DB->get_field('local_moderncommerce_storepage', 'usermodified', ['id' => $pageid]));
    }
}
