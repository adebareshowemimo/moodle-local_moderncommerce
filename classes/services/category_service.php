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

/**
 * Product category management (flat list) for the admin "Manage categories" screen.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class category_service {
    /** @var string Categories table. */
    private const TABLE = 'local_moderncommerce_product_categories';

    /** @var string Product-to-category map table. */
    private const MAP_TABLE = 'local_moderncommerce_product_category_map';

    /**
     * List all product categories with their mapped product counts.
     *
     * @return array List of category rows as plain arrays.
     */
    public static function list_categories(): array {
        global $DB;

        $context = context_system::instance();
        $sql = "SELECT pc.id,
                       pc.name,
                       pc.slug,
                       pc.description,
                       pc.visible,
                       pc.sortorder,
                       COUNT(pcm.productid) AS productcount
                  FROM {" . self::TABLE . "} pc
             LEFT JOIN {" . self::MAP_TABLE . "} pcm ON pcm.categoryid = pc.id
              GROUP BY pc.id, pc.name, pc.slug, pc.description, pc.visible, pc.sortorder
              ORDER BY pc.sortorder ASC, pc.name ASC";

        $rows = [];
        foreach ($DB->get_records_sql($sql) as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'name' => format_string($record->name, true, ['context' => $context]),
                'slug' => (string) $record->slug,
                'description' => (string) ($record->description ?? ''),
                'visible' => !empty($record->visible),
                'sortorder' => (int) $record->sortorder,
                'productcount' => (int) $record->productcount,
            ];
        }

        return $rows;
    }

    /**
     * List the visible product categories in the admin's saved display order.
     *
     * This is the single source of truth for the storefront: the catalog filter list and the
     * category tiles widget both read it, so hidden categories never reach a public page and the
     * drag-and-drop order set on the admin screen is the order shoppers see.
     *
     * @return array List of ['id', 'name', 'slug'] rows.
     */
    public static function get_visible_categories(): array {
        global $DB;

        $context = context_system::instance();
        $records = $DB->get_records(
            self::TABLE,
            ['visible' => 1],
            'sortorder ASC, name ASC',
            'id, name, slug'
        );

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'id' => (int) $record->id,
                'name' => format_string($record->name, true, ['context' => $context]),
                'slug' => (string) $record->slug,
            ];
        }

        return $rows;
    }

    /**
     * Map products to their primary product category in one query.
     *
     * Products may map to several categories, but the write path stores exactly one primary row.
     * Legacy and seeded rows can still carry more than one mapping - and even more than one row
     * flagged primary - so the tie is broken deterministically: primary first, then the admin's
     * display order, then name. Hidden categories are excluded so a hidden category can neither be
     * shown on a product nor be filtered on from the storefront.
     *
     * @param int[] $productids Product ids to resolve.
     * @return array Product id => ['id' => int, 'name' => string, 'slug' => string].
     */
    public static function get_primary_category_map(array $productids): array {
        global $DB;

        $clean = [];
        foreach ($productids as $productid) {
            $productid = (int) $productid;
            if ($productid > 0) {
                $clean[$productid] = $productid;
            }
        }

        if (empty($clean)) {
            return [];
        }

        $context = context_system::instance();
        [$insql, $inparams] = $DB->get_in_or_equal($clean, SQL_PARAMS_NAMED, 'pid');
        $sql = "SELECT pcm.id AS mapid,
                       pcm.productid,
                       pc.id AS categoryid,
                       pc.name,
                       pc.slug
                  FROM {" . self::MAP_TABLE . "} pcm
                  JOIN {" . self::TABLE . "} pc ON pc.id = pcm.categoryid
                 WHERE pcm.productid $insql
                   AND pc.visible = 1
              ORDER BY pcm.productid ASC, pcm.isprimary DESC, pc.sortorder ASC, pc.name ASC";

        $map = [];
        foreach ($DB->get_records_sql($sql, $inparams) as $record) {
            $productid = (int) $record->productid;
            // Ordered primary-first, so the first row seen for a product is the one to keep.
            if (isset($map[$productid])) {
                continue;
            }
            $map[$productid] = [
                'id' => (int) $record->categoryid,
                'name' => format_string($record->name, true, ['context' => $context]),
                'slug' => (string) $record->slug,
            ];
        }

        return $map;
    }

    /**
     * Count the sellable products mapped to each visible category.
     *
     * "Sellable" means what a shopper can actually reach from the catalog: an active, visible
     * product of a type the catalog renders. Courses additionally need an enabled regular price
     * and a visible, non-site course behind them, matching the catalog's own product query.
     *
     * @return array Category id => product count. Visible categories with no products are present as 0.
     */
    public static function count_visible_products(): array {
        global $DB;

        $counts = [];
        foreach ($DB->get_records(self::TABLE, ['visible' => 1], '', 'id') as $record) {
            $counts[(int) $record->id] = 0;
        }

        if (empty($counts)) {
            return [];
        }

        $sql = "SELECT pcm.categoryid, COUNT(DISTINCT p.id) AS productcount
                  FROM {" . self::MAP_TABLE . "} pcm
                  JOIN {" . self::TABLE . "} pc ON pc.id = pcm.categoryid
                  JOIN {local_moderncommerce_products} p ON p.id = pcm.productid
                 WHERE pc.visible = 1
                   AND p.status = :status
                   AND p.visible = 1
                   AND p.producttype IN (:typecourse, :typebundle, :typeprogram)
                   AND (
                        p.producttype <> :typecoursecheck
                        OR EXISTS (
                               SELECT 1
                                 FROM {local_moderncommerce_product_prices} pr
                                WHERE pr.productid = p.id
                                  AND pr.enabled = 1
                                  AND pr.pricetype = :pricetype
                           )
                       )
              GROUP BY pcm.categoryid";

        $params = [
            'status' => 'active',
            'typecourse' => 'course',
            'typebundle' => 'bundle',
            'typeprogram' => 'program',
            'typecoursecheck' => 'course',
            'pricetype' => 'regular',
        ];

        foreach ($DB->get_records_sql($sql, $params) as $record) {
            $counts[(int) $record->categoryid] = (int) $record->productcount;
        }

        return $counts;
    }

    /**
     * Set a product's primary category, replacing any existing assignment.
     *
     * Shared by every product editor (courses, bundles, programs) so all types categorise
     * identically. Passing 0 clears the product's category.
     *
     * @param int $productid Product id.
     * @param int $categoryid Category id, or 0 to clear.
     * @return void
     */
    public static function set_primary_category(int $productid, int $categoryid): void {
        global $DB;

        if ($productid <= 0) {
            return;
        }

        $DB->delete_records(self::MAP_TABLE, ['productid' => $productid]);

        if ($categoryid <= 0) {
            return;
        }

        $DB->insert_record(self::MAP_TABLE, (object) [
            'productid' => $productid,
            'categoryid' => $categoryid,
            'isprimary' => 1,
            'timecreated' => time(),
        ]);
    }

    /**
     * Whether a product category exists.
     *
     * @param int $categoryid Category id.
     * @return bool
     */
    public static function category_exists(int $categoryid): bool {
        global $DB;

        return $categoryid > 0 && $DB->record_exists(self::TABLE, ['id' => $categoryid]);
    }

    /**
     * Create or update a product category.
     *
     * @param int $id Category id, or 0 to create.
     * @param string $name Category name.
     * @param string $description Optional description.
     * @param bool $visible Whether the category is visible.
     * @return array ['success' => bool, 'id' => int, 'message' => string].
     */
    public static function save_category(
        int $id,
        string $name,
        string $description,
        bool $visible
    ): array {
        global $DB;

        $id = max(0, $id);
        $name = trim($name);
        $description = trim($description);

        if ($name === '') {
            return ['success' => false, 'id' => 0, 'message' => get_string('categorynamerequired', 'local_moderncommerce')];
        }

        if ($id > 0 && !$DB->record_exists(self::TABLE, ['id' => $id])) {
            return ['success' => false, 'id' => 0, 'message' => get_string('categorynotfound', 'local_moderncommerce')];
        }

        $now = time();
        $slug = self::unique_slug(self::slugify($name), $id);

        // Display order is managed by drag-and-drop, never edited here. Updates leave the existing
        // order untouched; new categories are appended to the end.
        $record = (object) [
            'name' => $name,
            'slug' => $slug,
            'description' => $description !== '' ? $description : null,
            'visible' => $visible ? 1 : 0,
            'timemodified' => $now,
        ];

        if ($id > 0) {
            $record->id = $id;
            $DB->update_record(self::TABLE, $record);
            $message = get_string('categorysaved', 'local_moderncommerce');
        } else {
            // Flat categories: no parent/hierarchy from this screen.
            $record->parentid = null;
            $record->depth = 0;
            $record->path = null;
            $record->sortorder = self::next_sortorder();
            $record->timecreated = $now;
            $id = (int) $DB->insert_record(self::TABLE, $record);
            // Store a simple self-referential path so tree-aware consumers stay consistent.
            $DB->set_field(self::TABLE, 'path', '/' . $id . '/', ['id' => $id]);
            $message = get_string('categorycreated', 'local_moderncommerce');
        }

        return ['success' => true, 'id' => $id, 'message' => $message];
    }

    /**
     * Persist a new display order from a drag-and-drop arrangement.
     *
     * @param int[] $ids Category ids in the desired display order.
     * @return array ['success' => bool, 'message' => string].
     */
    public static function reorder_categories(array $ids): array {
        global $DB;

        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[] = $id;
            }
        }

        if (empty($clean)) {
            return ['success' => false, 'message' => get_string('categorynotfound', 'local_moderncommerce')];
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $position = 1;
        foreach ($clean as $id) {
            if (!$DB->record_exists(self::TABLE, ['id' => $id])) {
                continue;
            }
            $DB->update_record(self::TABLE, (object) [
                'id' => $id,
                'sortorder' => $position * 10,
                'timemodified' => $now,
            ]);
            $position++;
        }
        $transaction->allow_commit();

        return ['success' => true, 'message' => get_string('categoriesreordered', 'local_moderncommerce')];
    }

    /**
     * Next display-order value placing a new category at the end of the list.
     *
     * @return int
     */
    private static function next_sortorder(): int {
        global $DB;

        $max = (int) $DB->get_field_sql('SELECT MAX(sortorder) FROM {' . self::TABLE . '}');

        return $max + 10;
    }

    /**
     * Delete a product category, detaching it from products and orphaning any children.
     *
     * @param int $id Category id.
     * @return array ['success' => bool, 'id' => int, 'message' => string].
     */
    public static function delete_category(int $id): array {
        global $DB;

        $id = max(0, $id);
        if ($id <= 0 || !$DB->record_exists(self::TABLE, ['id' => $id])) {
            return ['success' => false, 'id' => 0, 'message' => get_string('categorynotfound', 'local_moderncommerce')];
        }

        $transaction = $DB->start_delegated_transaction();
        // Detach the category from any products.
        $DB->delete_records(self::MAP_TABLE, ['categoryid' => $id]);
        // Orphan any children to the top level so no rows dangle against a missing parent.
        $DB->set_field(self::TABLE, 'parentid', null, ['parentid' => $id]);
        $DB->delete_records(self::TABLE, ['id' => $id]);
        $transaction->allow_commit();

        return ['success' => true, 'id' => $id, 'message' => get_string('categorydeleted', 'local_moderncommerce')];
    }

    /**
     * Build a URL-safe slug from a source string.
     *
     * @param string $value Source value.
     * @return string
     */
    private static function slugify(string $value): string {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9_-]+/', '-', $slug);
        $slug = trim($slug, '-_');

        return $slug !== '' ? $slug : 'category';
    }

    /**
     * Build a slug unique across categories, ignoring the current row.
     *
     * @param string $base Base slug.
     * @param int $currentid Category id to ignore.
     * @return string
     */
    private static function unique_slug(string $base, int $currentid): string {
        global $DB;

        $base = trim($base) !== '' ? trim($base) : 'category';
        $candidate = $base;
        $counter = 2;
        while ($DB->record_exists_select(self::TABLE, 'slug = :slug AND id <> :id', ['slug' => $candidate, 'id' => $currentid])) {
            $candidate = $base . '-' . $counter;
            $counter++;
        }

        return $candidate;
    }
}
