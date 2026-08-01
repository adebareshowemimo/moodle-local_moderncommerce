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
 * Bundle form class
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moderncommerce\forms;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_moderncommerce\api\bundle_api;
use local_moderncommerce\services\pricing_service;
use html_writer;

/**
 * Bundle form
 */
class bundle_form extends \moodleform {
    /**
     * Define the form
     */
    public function definition() {
        global $DB;

        $mform = $this->_form;
        $bundleid = $this->_customdata['bundleid'] ?? 0;

        // Hidden field to preserve bundleid when editing.
        $mform->addElement('hidden', 'bundleid');
        $mform->setType('bundleid', PARAM_INT);
        $mform->setDefault('bundleid', $bundleid);

        // Bundle Information Section.
        $mform->addElement('header', 'bundleinfo', get_string('bundleinfo', 'local_moderncommerce'));

        // Name.
        $mform->addElement('text', 'name', get_string('bundlename', 'local_moderncommerce'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('bundlenamerequired', 'local_moderncommerce'), 'required', null, 'client');

        // Short description.
        $mform->addElement('textarea', 'shortdescription', get_string('shortdescription', 'local_moderncommerce'), [
            'rows' => 3,
            'cols' => 60,
        ]);
        $mform->setType('shortdescription', PARAM_TEXT);
        $mform->addHelpButton('shortdescription', 'shortdescription', 'local_moderncommerce');

        // Bundle Image.
        $mform->addElement('filemanager', 'bundleimage', get_string('bundleimage', 'local_moderncommerce'), null, [
            'subdirs' => 0,
            'maxbytes' => 5242880, // 5MB
            'maxfiles' => 1,
            'accepted_types' => ['web_image'],
        ]);
        $mform->addHelpButton('bundleimage', 'bundleimage', 'local_moderncommerce');

        // Status.
        $statusoptions = [
            'draft' => get_string('bundlestatus_draft', 'local_moderncommerce'),
            'active' => get_string('bundlestatus_active', 'local_moderncommerce'),
            'inactive' => get_string('bundlestatus_inactive', 'local_moderncommerce'),
        ];
        $mform->addElement('select', 'status', get_string('status', 'local_moderncommerce'), $statusoptions);
        $mform->setDefault('status', 'draft');

        // Visibility.
        $mform->addElement('advcheckbox', 'visible', get_string('visible', 'local_moderncommerce'));
        $mform->setDefault('visible', 1);

        // Featured/Bestseller badges are managed in Advanced Bundle Features.
        // Removed from basic bundle form.

        // Pricing Section.
        $mform->addElement('header', 'pricingsettings', get_string('pricingsettings', 'local_moderncommerce'));

        // Price.
        $mform->addElement('text', 'price', get_string('price', 'local_moderncommerce'), ['size' => 20]);
        $mform->setType('price', PARAM_FLOAT);
        $mform->addRule('price', get_string('bundlepricerequired', 'local_moderncommerce'), 'required', null, 'client');
        $mform->addRule('price', get_string('err_numeric', 'form'), 'numeric', null, 'client');

        // Sale price.
        $mform->addElement('text', 'saleprice', get_string('saleprice', 'local_moderncommerce'), ['size' => 20]);
        $mform->setType('saleprice', PARAM_FLOAT);
        $mform->addHelpButton('saleprice', 'saleprice', 'local_moderncommerce');

        // Sale start date.
        $mform->addElement('date_time_selector', 'salestartdate', get_string('salestartdate', 'local_moderncommerce'), [
            'optional' => true,
        ]);
        $mform->disabledIf('salestartdate', 'saleprice', 'eq', '');

        // Sale end date.
        $mform->addElement('date_time_selector', 'saleenddate', get_string('saleenddate', 'local_moderncommerce'), [
            'optional' => true,
        ]);
        $mform->disabledIf('saleenddate', 'saleprice', 'eq', '');

        // Max enrollment.
        $mform->addElement('text', 'maxenrollment', get_string('maxenrollment', 'local_moderncommerce'), ['size' => 10]);
        $mform->setType('maxenrollment', PARAM_INT);
        $mform->addHelpButton('maxenrollment', 'maxenrollment', 'local_moderncommerce');

        // Courses Section.
        $mform->addElement('header', 'coursessettings', get_string('coursessettings', 'local_moderncommerce'));

        // Get all courses with active canonical product pricing.
        $sql = "SELECT c.id, c.fullname, c.shortname, pr.amount AS price
                  FROM {course} c
                  JOIN {local_moderncommerce_product_courses} pc ON pc.courseid = c.id
                  JOIN {local_moderncommerce_products} p ON p.id = pc.productid
                  JOIN {local_moderncommerce_product_prices} pr ON pr.productid = p.id
                 WHERE c.id != :siteid
                   AND c.visible = 1
                   AND pc.relationtype = :relationtype
                   AND p.producttype = :producttype
                   AND p.status = :status
                   AND p.visible = 1
                   AND pr.pricetype = :pricetype
                   AND pr.enabled = 1
              ORDER BY c.fullname";
        $courses = $DB->get_records_sql($sql, [
            'siteid' => SITEID,
            'relationtype' => 'included',
            'producttype' => 'course',
            'status' => 'active',
            'pricetype' => 'regular',
        ]);
        $courseoptions = [];
        foreach ($courses as $course) {
            $pricestr = '';
            if ($course->price) {
                $pricestr = ' (' . pricing_service::format_price($course->price) . ')';
            }
            $courseoptions[$course->id] = format_string($course->fullname) . $pricestr;
        }

        if (empty($courseoptions)) {
            $mform->addElement(
                'static',
                'nocoursesavailable',
                '',
                html_writer::div(get_string('nocoursesavailable', 'local_moderncommerce'), 'alert alert-warning')
            );
        } else {
            $select = $mform->addElement(
                'autocomplete',
                'courseids',
                get_string('selectcourses', 'local_moderncommerce'),
                $courseoptions,
                ['multiple' => true]
            );
            $mform->addRule(
                'courseids',
                get_string('bundlemustincludecourses', 'local_moderncommerce'),
                'required',
                null,
                'client'
            );
            $mform->addHelpButton('courseids', 'selectcourses', 'local_moderncommerce');

            // Display course order hint.
            $mform->addElement(
                'static',
                'courseorderinfo',
                '',
                html_writer::div(get_string('courseorderinfo', 'local_moderncommerce'), 'text-muted small')
            );
        }

        // Show savings calculation if editing.
        if ($bundleid) {
            $bundle = bundle_api::get($bundleid);
            $savings = bundle_api::calculate_savings($bundleid);

            if ($savings && $savings['savings'] > 0) {
                $savingshtml = html_writer::div(
                    get_string('bundletotalprice', 'local_moderncommerce') . ': ' .
                    pricing_service::format_price($savings['total']) . '<br>' .
                    get_string('bundleprice', 'local_moderncommerce') . ': ' .
                    pricing_service::format_price($savings['bundle']) . '<br>' .
                    get_string('bundlesavings', 'local_moderncommerce') . ': ' .
                    html_writer::tag('strong', pricing_service::format_price($savings['savings']) .
                    ' (' . number_format($savings['percentage'], 1) . '%)', ['class' => 'text-success']),
                    'alert alert-info'
                );
                $mform->addElement('static', 'savingscalc', get_string('savingscalculation', 'local_moderncommerce'), $savingshtml);
            }
        }

        // Program Settings Section.
        $mform->addElement('header', 'programsettings', get_string('programsettings', 'local_moderncommerce'));

        // Is Program checkbox.
        $mform->addElement('advcheckbox', 'isprogram', get_string('isprogram', 'local_moderncommerce'));
        $mform->addHelpButton('isprogram', 'isprogram', 'local_moderncommerce');
        $mform->setDefault('isprogram', 0);

        // Warning for existing bundles.
        if ($bundleid) {
            $bundle = bundle_api::get($bundleid);
            if (!$bundle->isprogram && $bundle->currentenrollment > 0) {
                $warninghtml = html_writer::div(
                    get_string('convertbundlewarning', 'local_moderncommerce', $bundle->currentenrollment),
                    'alert alert-warning'
                );
                $mform->addElement('static', 'programwarning', '', $warninghtml);
            }
        }

        // Certificate template from Course Certificate plugin.
        // Check if tool_coursecertificate is installed.
        $certtemplateoptions = ['' => get_string('none', 'local_moderncommerce')];
        if ($DB->get_manager()->table_exists('tool_certificate_templates')) {
            $certtemplates = $DB->get_records_menu('tool_certificate_templates', null, 'name', 'id, name');
            if ($certtemplates) {
                // Preserve numeric template IDs as array keys; avoid array_merge which reindexes numeric keys.
                $certtemplateoptions = $certtemplateoptions + $certtemplates;
            }
        } else {
            // Plugin not installed, show info message.
            $mform->addElement(
                'static',
                'coursecertinfo',
                '',
                html_writer::div(
                    get_string('coursecertificatenotinstalled', 'local_moderncommerce'),
                    'alert alert-info'
                )
            );
        }

        if (count($certtemplateoptions) > 1) {
            $mform->addElement(
                'select',
                'certificatetemplateid',
                get_string('certificatetemplate', 'local_moderncommerce'),
                $certtemplateoptions
            );
            $mform->setType('certificatetemplateid', PARAM_INT);
            $mform->addHelpButton('certificatetemplateid', 'certificatetemplate', 'local_moderncommerce');
            $mform->disabledIf('certificatetemplateid', 'isprogram', 'notchecked');

            // Show template management link for users with manage capability.
            if (has_capability('tool/certificate:manage', \context_system::instance())) {
                global $CFG;
                $manageurl = new \moodle_url('/admin/tool/certificate/manage_templates.php');
                $linkhtml = html_writer::link(
                    $manageurl,
                    get_string('managecertificatetemplates', 'local_moderncommerce'),
                    ['target' => '_blank']
                );
                $infohtml = html_writer::div(
                    get_string('certificatetemplateinfo', 'local_moderncommerce', $linkhtml),
                    'alert alert-info'
                );
                $mform->addElement('static', 'certificatetemplateinfo', '', $infohtml);
            }

            // Preselect saved template when editing and program enabled.
            if (!empty($bundleid)) {
                $bundle = bundle_api::get($bundleid);
                if ($bundle && !empty($bundle->isprogram) && isset($bundle->certificatetemplateid)) {
                    $mform->setDefault('certificatetemplateid', (int)$bundle->certificatetemplateid);
                }
            }
        }

        // Action buttons.
        $this->add_action_buttons(true, get_string('savebundle', 'local_moderncommerce'));
    }

    /**
     * Validation
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // Validate sale price is less than regular price.
        if (!empty($data['saleprice']) && !empty($data['price'])) {
            if ($data['saleprice'] >= $data['price']) {
                $errors['saleprice'] = get_string('salepricemustbeless', 'local_moderncommerce');
            }
        }

        // Validate sale dates.
        if (!empty($data['salestartdate']) && !empty($data['saleenddate'])) {
            if ($data['saleenddate'] <= $data['salestartdate']) {
                $errors['saleenddate'] = get_string('saleenddateafterstartdate', 'local_moderncommerce');
            }
        }

        // Validate course selection.
        if (empty($data['courseids']) || count($data['courseids']) < 1) {
            $errors['courseids'] = get_string('bundlemustincludecourses', 'local_moderncommerce');
        }

        return $errors;
    }
}
