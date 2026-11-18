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

/**
 * External API listing the catalogue items an "any" enrolment key can unlock.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moderncommerce\external\redeem;

use context_system;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\external_warnings;

/**
 * Search the courses and bundles a learner may unlock with an "any" key.
 *
 * This is the learner-facing counterpart to the admin typeahead: it deliberately
 * exposes only items that are actually on sale, so a key can never be spent on an
 * internal or staff-only course.
 */
class search_redeemable_items extends external_api {
    /**
     * @var int Maximum items returned per request.
     */
    private const MAX_RESULTS = 50;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'Search text.', VALUE_DEFAULT, ''),
            'limit' => new external_value(PARAM_INT, 'Maximum results.', VALUE_DEFAULT, 20),
            'itemtype' => new external_value(
                PARAM_ALPHA,
                'Restrict to one catalogue kind: course or product. Empty returns both.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Execute.
     *
     * @param string $query Search text.
     * @param int $limit Maximum results.
     * @param string $itemtype Restrict to 'course' or 'product'.
     * @return array
     */
    public static function execute(string $query = '', int $limit = 20, string $itemtype = ''): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'query' => $query,
            'limit' => $limit,
            'itemtype' => $itemtype,
        ]);

        require_login();
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/moderncommerce:redeemkey', $context);

        $limit = max(1, min(self::MAX_RESULTS, (int) $params['limit']));
        $search = trim((string) $params['query']);

        // A key is issued for one domain, so the picker only offers that domain.
        $itemtype = (string) $params['itemtype'];
        $items = array_merge(
            $itemtype === 'product' ? [] : self::search_courses($search, $limit),
            $itemtype === 'course' ? [] : self::search_bundles($search, $limit)
        );

        // Stable, predictable ordering once the two result sets are merged.
        usort($items, static function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        return [
            'items' => array_slice($items, 0, $limit),
            'warnings' => [],
        ];
    }

    /**
     * Active course-type products.
     *
     * @param string $search Search text.
     * @param int $limit Maximum results.
     * @return array
     */
    private static function search_courses(string $search, int $limit): array {
        return self::search_products(['course'], $search, $limit);
    }

    /**
     * Active bundle and program products.
     *
     * @param string $search Search text.
     * @param int $limit Maximum results.
     * @return array
     */
    private static function search_bundles(string $search, int $limit): array {
        return self::search_products(['bundle', 'program'], $search, $limit);
    }

    /**
     * Active products of the given types, with the number of courses each grants.
     *
     * Only products are offered: Modern Commerce grants access by selling a product,
     * so a raw Moodle course is never a redeemable choice.
     *
     * @param string[] $producttypes Product types to include.
     * @param string $search Search text.
     * @param int $limit Maximum results.
     * @return array
     */
    private static function search_products(array $producttypes, string $search, int $limit): array {
        global $DB;

        [$typesql, $sqlparams] = $DB->get_in_or_equal($producttypes, SQL_PARAMS_NAMED, 'pt');
        $where = '';
        if ($search !== '') {
            // The sql_like helper with $casesensitive = false already handles case folding.
            $like = $DB->sql_like('p.name', ':search', false);
            $where = " AND {$like}";
            $sqlparams['search'] = '%' . $DB->sql_like_escape($search) . '%';
        }

        $rows = $DB->get_records_sql(
            "SELECT p.id, p.name, p.producttype
               FROM {local_moderncommerce_products} p
              WHERE p.status = 'active'
                AND p.producttype {$typesql}
                    {$where}
           ORDER BY p.name ASC",
            $sqlparams,
            0,
            $limit
        );

        $items = [];
        $systemcontext = context_system::instance();
        foreach ($rows as $row) {
            $coursecount = $DB->count_records_sql(
                "SELECT COUNT(DISTINCT pc.courseid)
                   FROM {local_moderncommerce_product_courses} pc
                  WHERE pc.productid = :productid
                    AND pc.relationtype = 'included'",
                ['productid' => $row->id]
            );

            // A product that grants nothing would be a dead end for the learner.
            if ($coursecount <= 0) {
                continue;
            }

            $items[] = [
                'id' => (int) $row->id,
                'name' => format_string($row->name, true, ['context' => $systemcontext]),
                'type' => $row->producttype === 'course' ? 'course' : 'product',
                'coursecount' => (int) $coursecount,
            ];
        }

        return $items;
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'items' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course ID or product ID.'),
                'name' => new external_value(PARAM_TEXT, 'Display name.'),
                'type' => new external_value(PARAM_ALPHA, 'Item type: course or product.'),
                'coursecount' => new external_value(PARAM_INT, 'Number of courses the item grants.'),
            ])),
            'warnings' => new external_warnings(),
        ]);
    }
}
