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
