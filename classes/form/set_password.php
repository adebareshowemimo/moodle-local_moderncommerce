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

namespace local_moderncommerce\form;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/login/set_password_form.php');

/**
 * Core password form, including policy/history and plugin validation extensions.
 *
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class set_password extends \login_set_password_form {
    /**
     * Retain the native validation while carrying the separate renewal capability.
     */
    public function definition() {
        parent::definition();
        $this->_form->removeElement('setpassword');
        foreach (['password', 'password2'] as $field) {
            $this->_form->getElement($field)->updateAttributes(['autocomplete' => 'new-password']);
        }
        $this->_form->addElement('hidden', 'renew', '');
        $this->_form->setType('renew', PARAM_ALPHANUM);
    }

    /**
     * Keep native action-button handling with a clearer completion label.
     *
     * @param bool $cancel Whether to include cancellation.
     * @param string|null $submitlabel Native optional label.
     */
    public function add_action_buttons($cancel = true, $submitlabel = null) {
        parent::add_action_buttons($cancel, get_string('mcpasswordsave', 'local_moderncommerce'));
    }
}
