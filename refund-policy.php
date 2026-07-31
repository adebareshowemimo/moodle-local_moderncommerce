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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Public Refund and Cancellation Policy page for Modern Commerce.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');
// phpcs:enable moodle.Files.RequireLogin.Missing

use local_moderncommerce\output\public_page;
$context = context_system::instance();
public_page::require_enabled('refund-policy');
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/moderncommerce/refund-policy.php'));
$PAGE->set_pagelayout('standard');
$PAGE->add_body_class('mc-public-storefront-page');
$PAGE->set_title(public_page::setting('refund-policy', 'title', 'Refund and cancellation policy'));
$PAGE->set_heading(public_page::setting('refund-policy', 'title', 'Refund and cancellation policy'));
$PAGE->set_blocks_editing_capability('local/moderncommerce:managestorefront');

echo $OUTPUT->header();
echo public_page::render_widget_page('refund-policy', $OUTPUT);
echo $OUTPUT->footer();
