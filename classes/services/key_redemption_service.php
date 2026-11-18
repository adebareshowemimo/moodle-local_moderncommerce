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

use local_moderncommerce\api\order_api;

/**
 * Enrollment key redemption service.
 *
 * Resolves the courses a key grants (direct course targets plus product targets
 * expanded through product_courses), validates a key, and redeems it by enrolling
 * the buyer in every granted course.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class key_redemption_service {
    /**
     * @var string Target type for a key redeemable against any single course product.
     */
    public const TARGET_ANY_COURSE = 'anycourseproduct';

    /**
     * @var string Target type for a key redeemable against any bundle or program.
     */
    public const TARGET_ANY_PRODUCT = 'anyproduct';

    /**
     * The "any" sentinel target types, mapped to the product type each one accepts.
     *
     * Both resolve to a catalogue product: Modern Commerce grants access by selling a
     * product, never by naming a Moodle course directly.
     *
     * @return array<string, string> Sentinel target type => allowed product type.
     */
    public static function any_target_types(): array {
        return [
            self::TARGET_ANY_COURSE => 'course',
            self::TARGET_ANY_PRODUCT => 'bundle',
        ];
    }

    /**
     * The sentinel target type of a key that lets the learner choose, or '' when it names its own targets.
     *
     * Such a key carries a single sentinel target row and names no course of its
     * own, so the course set cannot be resolved until the learner picks an item.
     *
     * @param int $keyid Enrollment key ID.
     * @return string One of the TARGET_ANY_* constants, or '' when the key is not an "any" key.
     */
    public static function any_key_scope(int $keyid): string {
        global $DB;

        [$insql, $inparams] = $DB->get_in_or_equal(array_keys(self::any_target_types()), SQL_PARAMS_NAMED, 'any');
        $type = $DB->get_field_select(
            'local_moderncommerce_enrollkey_targets',
            'targettype',
            "enrollkeyid = :keyid AND includemode = 'include' AND targettype {$insql}",
            ['keyid' => $keyid] + $inparams,
            IGNORE_MULTIPLE
        );

        return $type === false ? '' : (string) $type;
    }

    /**
     * The UI-facing catalogue name for an "any" scope.
     *
     * @param string $anyscope One of the TARGET_ANY_* constants.
     * @return string 'product' for bundle keys, 'course' for course-product keys.
     */
    private static function choice_type_for_ui(string $anyscope): string {
        $producttype = self::any_target_types()[$anyscope] ?? '';
        if ($producttype === 'bundle') {
            return 'product';
        }

        return $producttype === '' ? '' : 'course';
    }

    /**
     * Whether a key lets the learner choose which catalogue item to unlock.
     *
     * @param int $keyid Enrollment key ID.
     * @return bool
     */
    public static function is_any_key(int $keyid): bool {
        return self::any_key_scope($keyid) !== '';
    }

    /**
     * Resolve every Moodle course ID a key grants access to.
     *
     * @param int $keyid Enrollment key ID.
     * @return int[] Distinct course IDs.
     */
    public static function get_target_courseids(int $keyid): array {

        global $DB;
        $courseids = [];
        // Keep legacy direct-course targets redeemable. New keys target catalogue
        // products, but existing installations may still contain these rows.
        $directcourseids = $DB->get_fieldset_select(
            'local_moderncommerce_enrollkey_targets',
            'targetid',
            "enrollkeyid = :keyid AND includemode = 'include' AND targettype = 'course'",
            ['keyid' => $keyid]
        );
        foreach ($directcourseids as $courseid) {
            $courseids[(int) $courseid] = true;
        }

        // Product targets (course product or bundle/program) expanded to included courses.
        $productids = self::get_target_productids($keyid);
        if (!empty($productids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($productids, SQL_PARAMS_NAMED, 'p');
            $rows = $DB->get_fieldset_sql(
                "SELECT courseid
                   FROM {local_moderncommerce_product_courses}
                  WHERE productid {$insql}
                    AND relationtype = 'included'",
                $inparams
            );
            foreach ($rows as $courseid) {
                $courseids[(int) $courseid] = true;
            }
        }

        return array_values(array_filter(array_keys($courseids), static function (int $id): bool {
            return $id > 1;
        }));
    }

    /**
     * Validate a key and preview what it grants for a user.
     *
     * @param string $keycode Key code.
     * @param int $userid User ID.
     * @return array ['valid' => bool, 'errorkey' => string|null, 'key' => \stdClass|null, 'courses' => array]
     */
    public static function preview(string $keycode, int $userid): array {

        global $DB;
        $keycode = key_resolver_service::normalise($keycode);
        $key = $DB->get_record('local_moderncommerce_enrollkeys', ['keycode' => $keycode]);
        if (!$key) {
            return ['valid' => false, 'errorkey' => 'invalidkey', 'key' => null, 'courses' => []];
        }

        $errorkey = self::validation_error($key, $userid);
        $anyscope = self::any_key_scope((int) $key->id);
        $requireschoice = $anyscope !== '';
        $courses = self::describe_courses(self::get_target_courseids((int) $key->id), $userid);

        // An "any" key legitimately grants no courses until the learner picks one, so the
        // empty-target check must not treat it as a broken key.
        if ($errorkey === null && empty($courses) && !$requireschoice) {
            $errorkey = 'invalidkey';
        }

        return [
            'valid' => $errorkey === null,
            'errorkey' => $errorkey,
            'key' => $key,
            'courses' => $courses,
            'requireschoice' => $requireschoice,
            // Tells the UI which catalogue to offer. The UI names the two catalogues
            // 'course' and 'product'; internally they are the 'course' and 'bundle'
            // product types, so map before crossing the boundary.
            'choicetype' => $requireschoice ? self::choice_type_for_ui($anyscope) : '',
        ];
    }

    /**
     * Redeem a key: enrol the user in every granted course not already enrolled.
     *
     * @param string $keycode Key code.
     * @param int $userid User ID.
     * @param int|null $orderid Optional originating order ID.
     * @param string $itemtype Chosen item type for an "any" key ('course' or 'product').
     * @param int $itemid Chosen item ID for an "any" key.
     * @return array ['courses' => array, 'alreadyenrolled' => int]
     * @throws \moodle_exception When the key cannot be redeemed.
     */
    public static function redeem(
        string $keycode,
        int $userid,
        ?int $orderid = null,
        string $itemtype = '',
        int $itemid = 0
    ): array {

        global $DB;
        $keycode = key_resolver_service::normalise($keycode);
        $lockfactory = \core\lock\lock_config::get_lock_factory('local_moderncommerce_key_redemption');
        $lock = $lockfactory->get_lock('key:' . hash('sha256', $keycode), 10);
        if (!$lock) {
            throw new \moodle_exception('keyredemptionbusy', 'local_moderncommerce');
        }

        $enrolled = [];
        $alreadyenrolled = 0;
        $firstcourse = null;
        $redeemedproductid = null;
        $key = null;
        try {
            $transaction = $DB->start_delegated_transaction();
            try {
                // Re-read and re-validate after taking the lock. This prevents two
                // concurrent requests from both consuming the final available use.
                $key = $DB->get_record('local_moderncommerce_enrollkeys', ['keycode' => $keycode]);
                if (!$key) {
                    throw new \moodle_exception('invalidkey', 'local_moderncommerce');
                }

                $errorkey = self::validation_error($key, $userid);
                if ($errorkey !== null) {
                    throw new \moodle_exception($errorkey, 'local_moderncommerce');
                }

                $targetproductids = self::get_target_productids((int) $key->id);
                $anyscope = self::any_key_scope((int) $key->id);
                if ($anyscope !== '') {
                    if ($itemid <= 0) {
                        throw new \moodle_exception('choosecoursetoredeem', 'local_moderncommerce');
                    }
                    $allowedtype = self::any_target_types()[$anyscope] ?? '';
                    $chosentype = $itemtype === 'product' ? 'bundle' : 'course';
                    if ($chosentype !== $allowedtype) {
                        throw new \moodle_exception('invalidkeychoice', 'local_moderncommerce');
                    }
                    $courseids = self::resolve_chosen_item($allowedtype, $itemid);
                    if (empty($courseids)) {
                        throw new \moodle_exception('invalidkeychoice', 'local_moderncommerce');
                    }
                    $targetproductids = [$itemid];
                    $redeemedproductid = $itemid;
                } else {
                    $courseids = self::get_target_courseids((int) $key->id);
                    if (empty($courseids)) {
                        throw new \moodle_exception('invalidkey', 'local_moderncommerce');
                    }
                    if (count($targetproductids) === 1) {
                        $redeemedproductid = reset($targetproductids);
                    }
                }

                $now = time();
                foreach ($courseids as $courseid) {
                    $course = $DB->get_record('course', ['id' => $courseid]);
                    if (!$course) {
                        continue;
                    }

                    $coursecontext = \context_course::instance($course->id);
                    if (is_enrolled($coursecontext, $userid)) {
                        $alreadyenrolled++;
                        continue;
                    }

                    enrolment_service::enrol_user_in_course($userid, $course->id, [
                        'orderid' => $orderid ?: 0,
                        'productid' => self::product_for_course((int) $course->id, $targetproductids),
                        'enrollkeyid' => (int) $key->id,
                        'enrolduration' => (int) ($key->enrolduration ?? 0),
                        'roleid' => (int) ($key->roleid ?? 0),
                        'method' => 'key',
                    ]);
                    $enrolled[] = $course;
                    if ($firstcourse === null) {
                            $firstcourse = $course;
                    }
                }

                if (empty($enrolled)) {
                    throw new \moodle_exception('alreadyenrolled', 'local_moderncommerce');
                }

                // One row represents one redemption, irrespective of how many courses
                // a bundle grants. Per-course lineage lives in the entitlement records.
                $DB->insert_record('local_moderncommerce_key_usage', (object) [
                    'enrollkeyid' => (int) $key->id,
                    'userid' => $userid,
                    'orderid' => $orderid ?: null,
                    'productid' => $redeemedproductid,
                    'courseid' => count($courseids) === 1 ? reset($courseids) : null,
                    'valueused' => 0,
                    'ipaddress' => getremoteaddr(),
                    'useragent' => self::user_agent(),
                    'timeredeemed' => $now,
                ]);
                $DB->execute("UPDATE {local_moderncommerce_enrollkeys}
                        SET usedcount = usedcount + 1, timemodified = :now
                      WHERE id = :id", ['now' => $now, 'id' => (int) $key->id]);
                $transaction->allow_commit();
            } catch (\Throwable $e) {
                $transaction->rollback($e);
            }
        } finally {
            $lock->release();
        }
        if ($orderid && self::is_order_fulfilled((int) $orderid, $userid)) {
            $order = $DB->get_record('local_moderncommerce_orders', ['id' => (int) $orderid]);
            if ($order && $order->status === 'pending') {
                order_api::update_order_status((int) $orderid, 'paid');
            }
        }

        if ($firstcourse !== null) {
            try {
                $user = $DB->get_record('user', ['id' => $userid]);
                \local_moderncommerce\email_notifications::send_key_redemption($user, $firstcourse, $keycode);
            } catch (\Throwable $e) {
                debugging('Failed to send key redemption email: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        \local_moderncommerce\audit\audit_service::record('enrollment_key_redeemed', 'enrollment_key', (int) $key->id, [
            'actoruserid' => $userid,
            'subjectuserid' => $userid,
            'newdata' => [
                'keyid' => (int) $key->id,
                'keycode' => $key->keycode,
                'orderid' => $orderid ?: 0,
                'productid' => $redeemedproductid,
                'courseids' => array_map(static function ($course): int {
                    return (int) $course->id;
                }, $enrolled),
                'alreadyenrolled' => $alreadyenrolled,
            ],
            'source' => 'redeem',
        ]);

        return [
            'courses' => self::describe_courses(array_map(static function ($c): int {
                return (int) $c->id;
            }, $enrolled), $userid),
            'alreadyenrolled' => $alreadyenrolled,
        ];
    }

    /**
     * Return the language error key for an invalid key, or null when redeemable.
     *
     * @param \stdClass $key Key record.
     * @param int $userid User ID.
     * @return string|null
     */
    private static function validation_error(\stdClass $key, int $userid): ?string {

        global $DB;
        if ($key->status !== 'active') {
            return 'keynotactive';
        }
        if (!empty($key->startdate) && (int) $key->startdate > time()) {
            return 'keynotstarted';
        }
        if (!empty($key->expirydate) && $key->expirydate < time()) {
            return 'keyexpired';
        }
        if ((int) $key->maxuses > 0 && (int) $key->usedcount >= (int) $key->maxuses) {
            return 'keylimitreached';
        }
        $maxusesperuser = (int) ($key->maxusesperuser ?? 1);
        $userusagecount = $DB->count_records('local_moderncommerce_key_usage', [
            'enrollkeyid' => $key->id, 'userid' => $userid,
        ]);
        if ($maxusesperuser > 0 && $userusagecount >= $maxusesperuser) {
            return 'keyalreadyused';
        }

        $requiredemail = trim((string) ($key->requiredemail ?? ''));
        if ($requiredemail !== '') {
            $useremail = (string) $DB->get_field('user', 'email', ['id' => $userid]);
            if (!self::email_matches($useremail, $requiredemail)) {
                return 'keynotforyou';
            }
        }

        if (!empty($key->roleid) && !$DB->record_exists('role', ['id' => (int) $key->roleid])) {
            return 'invalidkey';
        }

        return null;
    }

    /**
     * Product targets attached to a key.
     *
     * @param int $keyid Enrollment key ID.
     * @return int[] Product IDs.
     */
    private static function get_target_productids(int $keyid): array {

        global $DB;
        $productids = $DB->get_fieldset_select(
            'local_moderncommerce_enrollkey_targets',
            'targetid',
            "enrollkeyid = :keyid AND includemode = 'include' AND targettype = 'product'",
            ['keyid' => $keyid]
        );

        return array_values(array_unique(array_map('intval', $productids)));
    }

    /**
     * Find which selected target product grants a course.
     *
     * @param int $courseid Course ID.
     * @param int[] $productids Candidate product IDs.
     * @return int|null Product ID when provenance is unambiguous.
     */
    private static function product_for_course(int $courseid, array $productids): ?int {

        global $DB;
        if (empty($productids)) {
            return null;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($productids, SQL_PARAMS_NAMED, 'product');
        $matches = $DB->get_fieldset_sql("SELECT productid
               FROM {local_moderncommerce_product_courses}
              WHERE productid {$insql}
                AND courseid = :courseid
                AND relationtype = 'included'", $inparams + ['courseid' => $courseid]);
        $matches = array_values(array_unique(array_map('intval', $matches)));
        return count($matches) === 1 ? reset($matches) : null;
    }

    /**
     * Match a user's email to an exact address or an @domain restriction.
     *
     * @param string $useremail User email.
     * @param string $requiredemail Configured restriction.
     * @return bool Whether the restriction matches.
     */
    private static function email_matches(string $useremail, string $requiredemail): bool {

        $useremail = \core_text::strtolower(trim($useremail));
        $requiredemail = \core_text::strtolower(trim($requiredemail));
        if ($requiredemail === '' || $useremail === '') {
            return $requiredemail === '';
        }
        if ($requiredemail[0] === '@') {
            return substr($useremail, -strlen($requiredemail)) === $requiredemail;
        }

        return $useremail === $requiredemail;
    }

    /**
     * Sanitised request user agent for the redemption audit row.
     *
     * @return string|null User agent or null when unavailable.
     */
    private static function user_agent(): ?string {

        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return null;
        }

        return \core_text::substr(clean_param($_SERVER['HTTP_USER_AGENT'], PARAM_TEXT), 0, 255);
    }
    /**
     * Describe a set of courses with enrolment status and URLs.
     *
     * @param int[] $courseids Course IDs.
     * @param int $userid User ID.
     * @return array
     */
    private static function describe_courses(array $courseids, int $userid): array {
        global $DB;

        $courses = [];
        foreach (array_unique($courseids) as $courseid) {
            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname');
            if (!$course) {
                continue;
            }
            $coursecontext = \context_course::instance($course->id);
            $courses[] = [
                'id' => (int) $course->id,
                'fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
                'alreadyenrolled' => is_enrolled($coursecontext, $userid),
                'url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            ];
        }

        return $courses;
    }

    /**
     * Whether the user is enrolled in every course the order grants.
     *
     * @param int $orderid Order ID.
     * @param int $userid User ID.
     * @return bool
     */
    private static function is_order_fulfilled(int $orderid, int $userid): bool {
        $courseids = [];
        foreach (order_api::get_order_items($orderid) as $item) {
            if (!empty($item->courseid)) {
                $courseids[(int) $item->courseid] = true;
            }
            if (in_array((string) $item->itemtype, ['bundle', 'program'], true) && !empty($item->productid)) {
                foreach (self::product_courseids((int) $item->productid) as $courseid) {
                    $courseids[$courseid] = true;
                }
            }
        }

        $courseids = array_keys($courseids);
        if (empty($courseids)) {
            return false;
        }

        foreach ($courseids as $courseid) {
            if ($courseid <= 1) {
                continue;
            }
            if (!is_enrolled(\context_course::instance($courseid), $userid)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a learner-chosen catalogue product to the course IDs it grants.
     *
     * The chosen ID arrives from the browser, so it is re-validated here rather than
     * trusted: it must be an active product of the type the key was issued for. Modern
     * Commerce grants access by selling a product, so a raw Moodle course is never a
     * valid choice.
     *
     * @param string $producttype Product type the key allows ('course' or 'bundle').
     * @param int $productid Chosen product ID.
     * @return int[] Course IDs, empty when the product is not redeemable.
     */
    public static function resolve_chosen_item(string $producttype, int $productid): array {
        global $DB;

        if ($productid <= 0) {
            return [];
        }

        // A bundle key may be spent on a bundle or a program: both are multi-course products.
        $allowedtypes = $producttype === 'bundle' ? ['bundle', 'program'] : ['course'];
        [$insql, $inparams] = $DB->get_in_or_equal($allowedtypes, SQL_PARAMS_NAMED, 'pt');

        $exists = $DB->record_exists_sql(
            "SELECT 1
               FROM {local_moderncommerce_products} p
              WHERE p.id = :productid
                AND p.status = 'active'
                AND p.producttype {$insql}",
            ['productid' => $productid] + $inparams
        );
        if (!$exists) {
            return [];
        }

        return array_values(array_filter(self::product_courseids($productid), static function (int $id): bool {
            return $id > 1;
        }));
    }

    /**
     * Included course IDs for a product.
     *
     * @param int $productid Product ID.
     * @return int[]
     */
    private static function product_courseids(int $productid): array {
        global $DB;

        return array_map('intval', $DB->get_fieldset_select(
            'local_moderncommerce_product_courses',
            'courseid',
            "productid = :productid AND relationtype = 'included'",
            ['productid' => $productid]
        ));
    }
}
