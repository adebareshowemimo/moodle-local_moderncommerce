<?php
// This file is part of Moodle and is licensed under the
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

namespace local_moderncommerce\services;

use context_system;
use core_course\customfield\course_handler;
use core_customfield\api as customfield_api;
use core_customfield\category_controller;
use core_customfield\field_controller;

/**
 * Provisions the Moodle course custom fields Modern Commerce depends on.
 *
 * Bundle duration auto-detect reads a course custom field with the shortname
 * "learninghours" (see bundle_meta_service::get_learning_hours_summary). Without
 * that field the feature is inert and the admin only sees a message telling them
 * to create it by hand, so the plugin provisions it on install and upgrade.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_field_service {
    /**
     * @var string Shortname the bundle duration aggregator looks for.
     */
    public const LEARNING_HOURS_SHORTNAME = 'learninghours';

    /**
     * Create the "learninghours" course custom field when it does not already exist.
     *
     * Idempotent: safe to call from both install and upgrade, and safe to call
     * repeatedly. An existing field with this shortname is never modified, whatever
     * its type or category — the aggregator only needs a numeric value, so a field
     * an admin created themselves keeps working and must not be rewritten.
     *
     * Never throws: a provisioning failure must not abort a plugin upgrade.
     *
     * @return bool True when a field was created, false when one already existed or creation failed.
     */
    public static function ensure_learninghours_field(): bool {
        try {
            if (self::learninghours_field_exists()) {
                return false;
            }

            $category = self::get_or_create_category();
            $field = field_controller::create(0, (object) ['type' => 'number'], $category);

            customfield_api::save_field_configuration($field, (object) [
                'name' => get_string('learninghoursfieldname', 'local_moderncommerce'),
                'shortname' => self::LEARNING_HOURS_SHORTNAME,
                'type' => 'number',
                'description' => get_string('learninghoursfielddesc', 'local_moderncommerce'),
                'descriptionformat' => FORMAT_HTML,
                'configdata' => [
                    'required' => 0,
                    'uniquevalues' => 0,
                    // Decimal hours are supported: the aggregator multiplies by 60 and rounds.
                    'defaultvalue' => 0,
                    'decimalplaces' => 1,
                    'minimumvalue' => 0,
                    'maximumvalue' => 1000,
                    'display' => '{value}',
                    'locked' => 0,
                    'visibility' => 2,
                ],
            ]);

            return true;
        } catch (\Throwable $e) {
            debugging(
                'local_moderncommerce: could not provision the learninghours course custom field: ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
            return false;
        }
    }

    /**
     * Check whether a course custom field with the learning-hours shortname exists.
     *
     * Matched case-insensitively to mirror how bundle_meta_service resolves the field.
     *
     * @return bool
     */
    public static function learninghours_field_exists(): bool {
        $handler = course_handler::create();

        foreach ($handler->get_fields() as $field) {
            if (strtolower(trim((string) $field->get('shortname'))) === self::LEARNING_HOURS_SHORTNAME) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the plugin's course custom field category, creating it when absent.
     *
     * @return category_controller
     */
    private static function get_or_create_category(): category_controller {
        global $DB;

        $name = get_string('pluginname', 'local_moderncommerce');
        $existing = $DB->get_record('customfield_category', [
            'name' => $name,
            'component' => 'core_course',
            'area' => 'course',
            'itemid' => 0,
        ]);

        if ($existing) {
            return category_controller::create((int) $existing->id);
        }

        $category = category_controller::create(0, (object) [
            'name' => $name,
            'component' => 'core_course',
            'area' => 'course',
            'itemid' => 0,
            'contextid' => context_system::instance()->id,
        ]);
        customfield_api::save_category($category);

        return $category;
    }
}
