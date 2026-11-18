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
 * Learner Company Learning page.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use local_moderncommerce\output\company_page;
use local_moderncommerce\output\learner_shell;

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moderncommerce/learner/company.php'));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('companylearning', 'local_moderncommerce'));
$PAGE->set_heading(get_string('companylearning', 'local_moderncommerce'));
if (company_page::available()) {
    require_capability('local/moderncompany:viewowncompany', $context);
}
$content = html_writer::tag('h1', get_string('companylearning', 'local_moderncommerce'))
    . html_writer::tag('p', get_string('companylearningdesc', 'local_moderncommerce'), ['class' => 'text-muted'])
    . company_page::render($OUTPUT, 'learning');
$shell = learner_shell::create('companylearning')->set_content($content);
echo $OUTPUT->header();
echo $shell->render($OUTPUT);
echo $OUTPUT->footer();
