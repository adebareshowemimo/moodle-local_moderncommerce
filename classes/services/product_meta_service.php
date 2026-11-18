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

/**
 * Catalog metadata shared between the pricing screen and the advanced-features pages.
 *
 * The same five merchandising fields live in two different tables depending on what
 * the product is: a course product stores them against its linked Moodle course in
 * course_meta, while a bundle or program stores them against the product row itself
 * in bundle_meta. Both tables also carry columns owned by the advanced-features
 * pages (pass grades, certificates, outlines), so every write here is deliberately
 * partial - it touches only the fields below and leaves the rest untouched.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class product_meta_service {
    /**
     * @var string Course products keep catalog metadata against the linked course.
     */
    private const TABLE_COURSE = 'local_moderncommerce_course_meta';

    /**
     * @var string Bundles and programs keep it against the product row.
     */
    private const TABLE_BUNDLE = 'local_moderncommerce_bundle_meta';

    /**
     * @var string[] Product types that store metadata in the bundle table.
     */
    private const BUNDLE_TYPES = ['bundle', 'program'];

    /**
     * @var string[] Accepted skill levels; anything else is stored as unset.
     */
    public const SKILL_LEVELS = ['Beginner', 'Intermediate', 'Advanced', 'All Levels'];

    /**
     * @var int Longest language name the metadata columns can hold.
     */
    private const LANGUAGE_MAX_LENGTH = 50;

    /**
     * Languages offered for catalog metadata.
     *
     * Built from Moodle's own language list so the pricing screen and the
     * advanced-features pages always offer exactly the same set. Values are stored
     * as the language name, so anything too long for the column is skipped rather
     * than silently truncated into a value nothing can match.
     *
     * @return array List of value/label pairs.
     */
    public static function language_options(): array {
        $languages = get_string_manager()->get_list_of_languages();
        \core_collator::asort($languages);

        $options = [];
        $seen = [];
        foreach ($languages as $name) {
            $name = trim((string) $name);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            if (\core_text::strlen($name) > self::LANGUAGE_MAX_LENGTH) {
                continue;
            }
            $seen[$name] = true;
            $options[] = ['value' => $name, 'label' => $name];
        }

        return $options;
    }

    /**
     * Neutral values used when a product has no metadata row, or has no home for one.
     *
     * @return array
     */
    public static function empty_meta(): array {
        return [
            'skilllevel' => '',
            'language' => '',
            'durationminutes' => 0,
            'metafeatured' => false,
            'bestseller' => false,
            'trending' => false,
            'autoduration' => false,
            'supportsautoduration' => false,
            'autodurationminutes' => 0,
            'autodurationavailable' => false,
        ];
    }

    /**
     * Work out where a product's catalog metadata lives.
     *
     * @param string $producttype Product type.
     * @param int $productid Product ID.
     * @param int $primarycourseid Linked Moodle course ID for course products.
     * @return array|null [table, key field, key value], or null when the type has no home.
     */
    public static function resolve_target(string $producttype, int $productid, int $primarycourseid): ?array {
        if ($producttype === 'course') {
            return $primarycourseid > 0 ? [self::TABLE_COURSE, 'courseid', $primarycourseid] : null;
        }

        if (in_array($producttype, self::BUNDLE_TYPES, true)) {
            return $productid > 0 ? [self::TABLE_BUNDLE, 'bundleid', $productid] : null;
        }

        // Subscriptions and digital products have neither table.
        return null;
    }

    /**
     * Whether a product type can carry catalog metadata at all.
     *
     * @param string $producttype Product type.
     * @return bool
     */
    public static function supports_meta(string $producttype): bool {
        return $producttype === 'course' || in_array($producttype, self::BUNDLE_TYPES, true);
    }

    /**
     * Load catalog metadata for a page of products using one query per table.
     *
     * @param array $products Rows with id, producttype and primarycourseid.
     * @return array Normalised metadata keyed by product ID.
     */
    public static function get_bulk(array $products): array {
        global $DB;

        $courseidbyproduct = [];
        $bundleproductids = [];
        $meta = [];

        foreach ($products as $product) {
            $productid = (int) ($product['id'] ?? 0);
            $producttype = (string) ($product['producttype'] ?? '');
            $primarycourseid = (int) ($product['primarycourseid'] ?? 0);
            $meta[$productid] = self::empty_meta();

            $target = self::resolve_target($producttype, $productid, $primarycourseid);
            if ($target === null) {
                continue;
            }
            if ($target[0] === self::TABLE_COURSE) {
                $courseidbyproduct[$productid] = $target[2];
            } else {
                $bundleproductids[$productid] = $productid;
            }
        }

        if (!empty($courseidbyproduct)) {
            $courseids = array_values(array_unique($courseidbyproduct));
            [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'courseid');
            $rows = $DB->get_records_select(
                self::TABLE_COURSE,
                "courseid {$insql}",
                $inparams,
                '',
                'id, courseid, durationminutes, auto_duration, skilllevel, language, featured, bestseller, trending'
            );

            $bycourse = [];
            foreach ($rows as $row) {
                $bycourse[(int) $row->courseid] = $row;
            }
            foreach ($courseidbyproduct as $productid => $courseid) {
                if (isset($bycourse[$courseid])) {
                    $meta[$productid] = self::normalise_course_row($bycourse[$courseid]);
                }
            }
        }

        if (!empty($bundleproductids)) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_values($bundleproductids), SQL_PARAMS_NAMED, 'bundleid');
            $rows = $DB->get_records_select(
                self::TABLE_BUNDLE,
                "bundleid {$insql}",
                $inparams,
                '',
                'id, bundleid, dur_hours, dur_mins, auto_duration, skill_level, language, ' .
                    'badge_featured, badge_bestseller, badge_trending'
            );

            foreach ($rows as $row) {
                $meta[(int) $row->bundleid] = self::normalise_bundle_row($row);
            }
        }

        return $meta;
    }

    /**
     * Write the five catalog fields, leaving every other column in the row alone.
     *
     * @param int $productid Product ID.
     * @param string $producttype Product type.
     * @param int $primarycourseid Linked Moodle course ID for course products.
     * @param array $data Normalised field values.
     */
    public static function save(int $productid, string $producttype, int $primarycourseid, array $data): void {
        global $DB, $USER;

        $target = self::resolve_target($producttype, $productid, $primarycourseid);
        if ($target === null) {
            return;
        }

        [$table, $keyfield, $keyvalue] = $target;
        $now = time();
        $isbundle = $table === self::TABLE_BUNDLE;

        $skilllevel = self::clean_choice((string) ($data['skilllevel'] ?? ''), self::SKILL_LEVELS);
        $language = self::clean_choice((string) ($data['language'] ?? ''), []);
        $durationminutes = max(0, (int) ($data['durationminutes'] ?? 0));
        $featured = !empty($data['metafeatured']) ? 1 : 0;
        $bestseller = !empty($data['bestseller']) ? 1 : 0;
        $trending = !empty($data['trending']) ? 1 : 0;

        if ($isbundle) {
            // Bundles can derive their duration from the courses they contain, in which
            // case the hours and minutes below are ignored by every screen that shows a
            // duration. The toggle is the admin's choice, so it is stored as submitted.
            $autoduration = !empty($data['autoduration']) ? 1 : 0;
            $fields = [
                'skill_level' => $skilllevel,
                'language' => $language,
                'dur_hours' => intdiv($durationminutes, 60),
                'dur_mins' => $durationminutes % 60,
                'auto_duration' => $autoduration,
                'badge_featured' => $featured,
                'badge_bestseller' => $bestseller,
                'badge_trending' => $trending,
            ];
        } else {
            $fields = [
                'skilllevel' => $skilllevel,
                'language' => $language,
                'durationminutes' => $durationminutes,
                'auto_duration' => !empty($data['autoduration']) ? 1 : 0,
                'featured' => $featured,
                'bestseller' => $bestseller,
                'trending' => $trending,
            ];
        }

        $existing = $DB->get_record($table, [$keyfield => $keyvalue], 'id', IGNORE_MULTIPLE);

        if ($existing) {
            // Only the fields above are set on the update object, so columns owned by the
            // advanced-features pages (pass grade, certificate, overview, outline flags,
            // bundle pricing) are never written here.
            $record = (object) ($fields + [
                'id' => (int) $existing->id,
                'usermodified' => $USER->id ?? null,
                'timemodified' => $now,
            ]);

            $DB->update_record($table, $record);
            return;
        }

        $defaults = $isbundle ? self::bundle_insert_defaults() : self::course_insert_defaults();
        $record = (object) ($fields + $defaults + [
            $keyfield => $keyvalue,
            'usermodified' => $USER->id ?? null,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);

        $DB->insert_record($table, $record);
    }

    /**
     * Column defaults for a new course metadata row.
     *
     * Mirrors the schema so a row created here is indistinguishable from one the
     * course advanced-features page has never been used on.
     *
     * @return array
     */
    private static function course_insert_defaults(): array {
        return [
            'passgrade' => null,
            'certificateenabled' => 0,
            'overview' => null,
        ];
    }

    /**
     * Column defaults for a new bundle metadata row.
     *
     * @return array
     */
    private static function bundle_insert_defaults(): array {
        return [
            'visibility' => 'Public',
            'availstart' => null,
            'availend' => null,
            'bundle_price' => null,
            'compare_at' => null,
            'has_prereq' => 0,
            'auto_assessments' => 1,
            'assessments_count' => 0,
            'auto_outline' => 1,
            'pass_grade' => null,
            'pass_policy' => 'all_must_pass',
            'bundle_cert' => 0,
            'certificate_template_id' => null,
        ];
    }

    /**
     * Read the learning hours recorded on a Moodle course.
     *
     * Uses the same "learninghours" course custom field the bundle duration
     * aggregator sums, so a course product and a bundle containing it agree on
     * where the number comes from. Values are stored in hours and may be decimal.
     *
     * @param int $courseid Course ID.
     * @return array Whether the field exists, whether a value is set, and the minutes.
     */
    public static function course_learning_hours(int $courseid): array {
        $summary = ['fieldexists' => false, 'isset' => false, 'minutes' => 0];

        if ($courseid <= 0) {
            return $summary;
        }

        $handler = \core_course\customfield\course_handler::create();
        $field = null;
        foreach ($handler->get_fields() as $candidate) {
            if (strtolower(trim((string) $candidate->get('shortname'))) === 'learninghours') {
                $field = $candidate;
                break;
            }
        }

        if ($field === null) {
            return $summary;
        }

        $summary['fieldexists'] = true;
        $data = $handler->get_instance_data($courseid, true)[$field->get('id')] ?? null;

        // The handler supplies defaults too, so require a value actually saved
        // against the course rather than one the handler invented.
        if ($data === null || (int) $data->get('id') <= 0) {
            return $summary;
        }

        $value = $data->get_value();
        if ($value === null || $value === '' || !is_numeric($value) || (float) $value < 0) {
            return $summary;
        }

        $summary['isset'] = true;
        $summary['minutes'] = (int) round((float) $value * 60);

        return $summary;
    }

    /**
     * Convert a course metadata row to the shared field names.
     *
     * @param \stdClass $row Course meta record.
     * @return array
     */
    private static function normalise_course_row(\stdClass $row): array {
        $learninghours = self::course_learning_hours((int) $row->courseid);

        return [
            'skilllevel' => (string) ($row->skilllevel ?? ''),
            'language' => (string) ($row->language ?? ''),
            'durationminutes' => max(0, (int) ($row->durationminutes ?? 0)),
            'metafeatured' => !empty($row->featured),
            'bestseller' => !empty($row->bestseller),
            'trending' => !empty($row->trending),
            'autoduration' => !empty($row->auto_duration),
            'supportsautoduration' => true,
            'autodurationminutes' => $learninghours['minutes'],
            'autodurationavailable' => $learninghours['isset'],
        ];
    }

    /**
     * Convert a bundle metadata row to the shared field names.
     *
     * @param \stdClass $row Bundle meta record.
     * @return array
     */
    private static function normalise_bundle_row(\stdClass $row): array {
        $hours = max(0, (int) ($row->dur_hours ?? 0));
        $minutes = max(0, (int) ($row->dur_mins ?? 0));
        $autominutes = bundle_meta_service::get_total_duration_minutes((int) $row->bundleid);

        return [
            'skilllevel' => (string) ($row->skill_level ?? ''),
            'language' => (string) ($row->language ?? ''),
            'durationminutes' => ($hours * 60) + $minutes,
            'metafeatured' => !empty($row->badge_featured),
            'bestseller' => !empty($row->badge_bestseller),
            'trending' => !empty($row->badge_trending),
            'autoduration' => !empty($row->auto_duration),
            'supportsautoduration' => true,
            'autodurationminutes' => $autominutes,
            'autodurationavailable' => $autominutes > 0,
        ];
    }

    /**
     * Normalise a submitted choice.
     *
     * Values outside the offered options are kept rather than discarded: some
     * existing records use levels this plugin never offered (for example
     * "Professional"), and dropping them on an unrelated save would lose data the
     * admin never touched. The drawer re-offers such a value so it survives a
     * round trip.
     *
     * @param string $value Submitted value.
     * @param array $allowed Offered values.
     * @return string|null Null when empty, so the column stores no value.
     */
    private static function clean_choice(string $value, array $allowed): ?string {
        unset($allowed);
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
