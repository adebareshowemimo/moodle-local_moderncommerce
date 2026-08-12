<?php
// This file is part of Moodle and is licensed under the GNU GPL v3 or later.

namespace local_moderncommerce\observer;

use local_moderncommerce\notifications\api;
use local_moderncommerce\notifications\notification;

/** Queues learner communications for completed programs. */
class program_completion_observer {
    public static function program_completed(\local_moderncommerce\event\program_completed $event): void {
        global $DB;

        $progress = $DB->get_record('local_moderncommerce_program_progress', ['id' => $event->objectid]);
        $programid = (int)($event->other['programid'] ?? 0);
        $program = $DB->get_record('local_moderncommerce_products', [
            'id' => $programid,
            'producttype' => 'program',
        ], 'id, name');
        if (!$progress || !$program || $progress->status !== 'completed') {
            return;
        }

        $programurl = new \moodle_url('/local/moderncommerce/learner/index.php');
        $programurl->set_anchor('/access/program/' . $programid);
        $certificateurl = new \moodle_url('/local/moderncommerce/learner/certificates.php');

        api::notify(
            (new notification('local_moderncommerce', 'program_completed'))
                ->category(notification::CAT_CELEBRATORY)
                ->template('moderncommerce_program_completed')
                ->to_user((int)$event->relateduserid)
                ->placeholders([
                    'program_name' => format_string($program->name),
                    'completed_courses' => (int)$progress->completedcourses,
                    'average_grade' => format_float((float)$progress->averagegrade, 2) . '%',
                    'completion_date' => userdate((int)$progress->timecompleted),
                    'program_url' => $programurl->out(false),
                    'certificate_url' => $certificateurl->out(false),
                ])
                ->context_url($programurl->out(false))
                ->related((int)$progress->id)
                ->dedup_tag('program-completed')
        );
    }
}
