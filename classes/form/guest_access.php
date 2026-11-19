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
require_once($CFG->libdir . '/formslib.php');

/**
 * Session-protected confirmation to email a fresh guest account password link.
 *
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guest_access extends \moodleform {
    /**
     * Define the token and confirmation button without asking for an email address.
     */
    public function definition(): void {
        $form = $this->_form;
        $form->addElement('hidden', 'token', $this->_customdata['token']);
        $form->setType('token', PARAM_ALPHANUM);
        $form->addElement('submit', 'submitbutton', get_string('guestaccesssend', 'local_moderncommerce'));
    }
}
