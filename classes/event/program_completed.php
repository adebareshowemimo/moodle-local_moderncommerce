<?php
// This file is part of Moodle and is licensed under the GNU GPL v3 or later.

namespace local_moderncommerce\event;

/** Event emitted once when a learner first satisfies every program completion rule. */
class program_completed extends \core\event\base {
    protected function init(): void {
        $this->data['objecttable'] = 'local_moderncommerce_program_progress';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    public static function get_name(): string {
        return get_string('event:programcompleted', 'local_moderncommerce');
    }

    public function get_description(): string {
        return "User {$this->relateduserid} completed program {$this->other['programid']} "
            . "with progress record {$this->objectid}.";
    }

    public function get_url(): \moodle_url {
        $url = new \moodle_url('/local/moderncommerce/learner/index.php');
        $url->set_anchor('/access/program/' . (int)$this->other['programid']);
        return $url;
    }

    protected function validate_data(): void {
        parent::validate_data();
        if (empty($this->relateduserid) || empty($this->other['programid'])) {
            throw new \coding_exception('Program completion events require relateduserid and other[programid].');
        }
    }
}
