<?php
// This file is part of Moodle - https://moodle.org/
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

namespace local_moderncommerce\form;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Minimal guest identity and account creation consent, using Moodle form protection.
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guest_checkout extends \moodleform {
    /**
     * Form fields.
     */
    public function definition(): void {
        global $CFG;
        $form = $this->_form;
        foreach (['firstname', 'lastname', 'email'] as $field) {
            $form->addElement('text', $field, get_string($field), [
                'autocomplete' => ['firstname' => 'given-name', 'lastname' => 'family-name', 'email' => 'email'][$field],
                'maxlength' => 100,
                'size' => 40,
            ]);
            $form->setType($field, $field === 'email' ? PARAM_RAW_TRIMMED : PARAM_NOTAGS);
            $form->addRule($field, get_string('required'), 'required', null, 'client');
        }
        $methods = $this->_customdata['gateways'] ?? [];
        $choices = array_map(static fn(array $method) => $method['name'], $methods);
        $form->addElement('select', 'gateway', get_string('guestcheckoutgateway', 'local_moderncommerce'), $choices);
        $form->setType('gateway', PARAM_ALPHANUMEXT);
        $form->addRule('gateway', get_string('required'), 'required', null, 'client');
        if (!$choices) {
            $form->addElement('static', 'nogateways', '', s(get_string('guestcheckoutnogateways', 'local_moderncommerce')));
        }
        $form->addElement('advcheckbox', 'consent', get_string('guestcheckoutconsent', 'local_moderncommerce'));
        $form->addElement('hidden', 'action', 'pay');
        $form->setType('action', PARAM_ALPHA);
        // Enable Moodle's configured challenge without inventing a separate CAPTCHA integration.
        if (!empty($CFG->recaptchapublickey) && !empty($CFG->recaptchaprivatekey)) {
            $form->addElement('recaptcha', 'recaptcha', get_string('security_question', 'auth'));
        }
        // The native submit button lives in the order summary and targets this
        // form by ID. All fields, sesskey and Moodle's form marker still post together.
    }

    /**
     * Validate identity and explicit account creation consent.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if (!isset(($this->_customdata['gateways'] ?? [])[$data['gateway'] ?? ''])) {
            $errors['gateway'] = get_string('guestcheckoutgatewayunavailable', 'local_moderncommerce');
        }
        if (!validate_email(trim($data['email'] ?? '')) || email_is_not_allowed(trim($data['email'] ?? ''))) {
            $errors['email'] = get_string('invalidemail');
        } else {
            \local_moderncommerce\services\guest_checkout_service::limit_email_checks();
            $check = \local_moderncommerce\services\guest_checkout_service::check_email($data['email']);
            if (!$check['available']) {
                $errors['email'] = $check['message'];
            }
        }
        if (empty($data['consent'])) {
            $errors['consent'] = get_string('required');
        }
        return $errors;
    }
}
