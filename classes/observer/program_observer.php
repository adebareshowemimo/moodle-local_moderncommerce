<?php
// This file is part of Moodle and is licensed under the GNU GPL v3 or later.

namespace local_moderncommerce\observer;

use local_moderncommerce\services\program_progress_service;

/** Refresh ordered programs when Moodle marks a course complete. */
class program_observer {
    public static function course_completed(\core\event\course_completed $event): void {
        program_progress_service::sync_for_course((int)$event->relateduserid, (int)$event->courseid);
    }
}
