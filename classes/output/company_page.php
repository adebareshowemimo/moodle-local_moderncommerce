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

namespace local_moderncommerce\output;

/**
 * Optional Modern Company React page configuration.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class company_page {
    /**
     * Check whether the optional company add-on is installed and upgraded.
     *
     * @return bool
     */
    public static function available(): bool {
        $info = \core_plugin_manager::instance()->get_plugin_info('local_moderncompany');
        return $info !== null && $info->is_installed_and_upgraded();
    }

    /**
     * Render a gated company React surface.
     *
     * Callers build this content BEFORE $OUTPUT->header(), when $OUTPUT is still core's
     * pre-header bootstrap_renderer -- which does not extend renderer_base -- so the
     * parameter is typed `object` here, matching admin_shell::render().
     *
     * @param object $output Moodle renderer.
     * @param string $mode Surface mode.
     * @param array $extra Additional React properties.
     * @return string
     */
    public static function render(object $output, string $mode, array $extra = []): string {
        if (!self::available()) {
            return '<section class="mc-card"><div class="mc-card-body"><div class="mc-empty mc-empty--centered">'
                . '<span class="mc-empty__icon"><i class="bi bi-buildings" aria-hidden="true"></i></span>'
                . '<p class="mc-empty__title">' . s(get_string('companyaddonrequired', 'local_moderncommerce')) . '</p>'
                . '<p class="mc-empty__text">' . s(get_string('companyaddonrequireddesc', 'local_moderncommerce')) . '</p>'
                . '</div></div></section>';
        }
        $config = json_encode([
            'component' => '@moodle/lms/local_moderncommerce/company_licensing',
            'id' => 'moderncommerce-company-licensing-' . clean_param($mode, PARAM_ALPHANUMEXT),
            'class' => 'local-moderncommerce-company-licensing',
            'props' => array_merge([
                'mode' => $mode,
                'methods' => self::methods(),
                'labels' => self::labels(),
            ], $extra),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $output->render_from_template('local_moderncommerce/company_licensing', [
            'companyreactconfig' => $config,
        ]);
    }

    /**
     * Return add-on AJAX method names.
     *
     * @return array
     */
    private static function methods(): array {
        return [
            'listCompanies' => 'local_moderncompany_admin_list_companies',
            'saveCompany' => 'local_moderncompany_admin_save_company',
            'getCompany' => 'local_moderncompany_get_company',
            'approveDomain' => 'local_moderncompany_admin_approve_domain',
            'addDomain' => 'local_moderncompany_admin_add_domain',
            'companyAction' => 'local_moderncompany_admin_company_action',
            'addLicense' => 'local_moderncompany_admin_add_license',
            'licenseAction' => 'local_moderncompany_admin_license_action',
            'memberAction' => 'local_moderncompany_member_action',
            'invite' => 'local_moderncompany_invite_member',
            'assignOptional' => 'local_moderncompany_assign_optional',
            'listOffers' => 'local_moderncompany_admin_list_offers',
            'saveOffer' => 'local_moderncompany_admin_save_offer',
            'getLearning' => 'local_moderncompany_get_learning',
            'setOptional' => 'local_moderncompany_set_optional',
            'purchaseOffers' => 'local_moderncompany_list_purchase_offers',
            'startPurchase' => 'local_moderncompany_start_purchase',
        ];
    }

    /**
     * Return localized React labels.
     *
     * @return array
     */
    private static function labels(): array {
        $keys = [
            'action', 'actions', 'activate', 'activateseat', 'additionallicense', 'adddomain', 'addlicense',
            'allocatelearnerseat', 'apply', 'assign',
            'approve', 'auditlog', 'actor', 'company', 'companydomain', 'companyname', 'continuetocheckout',
            'courseaccessupdated', 'coursemapping', 'coursemappinghelp', 'courses', 'create', 'createcompany',
            'createoffer', 'date', 'days', 'demote', 'domain', 'domains', 'duration', 'durationdays',
            'edit', 'email', 'enddate', 'enrol', 'errorgeneric', 'invitemember', 'licenseoffer', 'licenseoffers',
            'licensesandcourses', 'loading', 'manage', 'manager', 'maxseats', 'member', 'members', 'minseats',
            'nocompanymembership', 'nocompanymembershipdesc', 'object', 'offer', 'offerid', 'offername',
            'opencourse', 'optionalcourse', 'owneruserid', 'product', 'productid', 'promote', 'purchasetype', 'refresh', 'remove',
            'renewal', 'role', 'save', 'searchcompanies', 'seat', 'seatcount', 'seats', 'seatstate',
            'seatusage', 'selectoffer', 'sendinvite', 'startdate', 'status', 'system', 'total', 'waitlistmessage',
            'withdraw', 'accessuntil', 'suspend', 'cancel',
        ];
        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = get_string($key, 'local_moderncommerce');
        }
        return $labels;
    }
}
