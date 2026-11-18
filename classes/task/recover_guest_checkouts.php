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

namespace local_moderncommerce\task;

use local_moderncommerce\services\guest_checkout_service as guest;

/**
 * Recover paid guest purchases independently of browser sessions and feature switches.
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recover_guest_checkouts extends \core\task\scheduled_task {
    /**
     * Task label.
     */
    public function get_name(): string {
        return get_string('guestcheckoutrecovery', 'local_moderncommerce');
    }

    /**
     * Retry in bounded batches; unpaid personal details expire after 30 days.
     */
    public function execute(): void {
        global $DB;
        $records = $DB->get_records_select(
            guest::TABLE,
            "(status IN ('paid', 'pending') OR (status IN ('claim', 'fulfilled') AND mailsent = 0))
             AND timemodified < :since",
            ['since' => time() - 2 * MINSECS],
            'timemodified ASC',
            '*',
            0,
            25
        );
        foreach ($records as $record) {
            if ($record->timemodified > time() - min(HOURSECS, 60 * (2 ** min(6, $record->attempts)))) {
                continue;
            }
            try {
                if ($record->status === 'pending') {
                    guest::reconcile($record);
                    $DB->set_field(guest::TABLE, 'timemodified', time(), ['id' => $record->id]);
                } else {
                    guest::fulfil((int)$record->id);
                }
            } catch (\Throwable $e) {
                mtrace('Guest purchase ' . $record->id . ': ' . get_class($e));
            }
        }
        $DB->delete_records_select(
            guest::TABLE,
            "status IN ('pending', 'expired') AND paymentdata IS NULL AND timecreated < :cutoff",
            ['cutoff' => time() - 30 * DAYSECS]
        );
    }
}
