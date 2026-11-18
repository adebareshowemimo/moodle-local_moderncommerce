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
 * Company licensing administration page.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');

use local_moderncommerce\output\admin_shell;
use local_moderncommerce\output\company_page;

require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);
if (company_page::available()) {
    require_capability('local/moderncompany:managecompanies', $context);
}
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moderncommerce/admin/companies.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_secondary_navigation(false);
$PAGE->set_primary_active_tab('ccpadmin');
$PAGE->set_title(get_string('companylicensing', 'local_moderncommerce'));
$PAGE->set_heading(get_string('companylicensing', 'local_moderncommerce'));

$content = company_page::render($OUTPUT, 'admin', [
    'manageUrl' => (new moodle_url('/local/moderncommerce/company.php'))->out(false),
    'canAdmin' => true,
]);
$shell = admin_shell::render_page(
    $OUTPUT,
    'companies',
    get_string('companylicensing', 'local_moderncommerce'),
    $content,
    get_string('companylicensingdesc', 'local_moderncommerce')
);
echo $OUTPUT->header();
echo $shell;
echo $OUTPUT->footer();
