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

use local_moderncommerce\form\guest_checkout;

/**
 * Native guest gateway cards retain form submission and server validation.
 * @package local_moderncommerce
 * @category test
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(guest_checkout::class)]
final class guest_checkout_form_test extends advanced_testcase {
    /**
     * Prepare an anonymous shopper without real provider calls.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setUser(null);
        set_config('guest_checkout_enabled', 1, 'local_moderncommerce');
    }

    /**
     * Radio cards contain safe icons and descriptions, with a native association to the details form.
     */
    public function test_cards_render_without_a_dropdown_and_escape_provider_labels(): void {
        $methods = $this->methods();
        $methods['paypal']['description'] = '<script>unsafe()</script>';
        $form = new guest_checkout(null, ['gateways' => $methods], 'post', '', ['id' => 'mc-guest-details-form']);
        $html = $form->render();
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringContainsString('type="radio"', $html);
        $this->assertStringContainsString('name="gateway"', $html);
        $this->assertStringContainsString('form="mc-guest-details-form"', $html);
        $this->assertStringContainsString('mc-payment-option', $html);
        $this->assertStringContainsString('bi bi-stripe', $html);
        $this->assertStringContainsString('&lt;script&gt;unsafe()&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>unsafe()', $html);
    }

    /**
     * A submitted nondefault gateway survives Moodle's native form data export.
     */
    public function test_submitted_radio_selection_is_retained(): void {
        guest_checkout::mock_submit(['firstname' => 'Test', 'lastname' => 'Buyer',
            'email' => 'newbuyer@example.com', 'consent' => 1, 'action' => 'pay', 'gateway' => 'paypal']);
        $form = new guest_checkout(null, ['gateways' => $this->methods()]);
        $data = $form->get_data();
        $this->assertNotNull($data);
        $this->assertSame('paypal', $data->gateway);
        $this->assertSame('newbuyer@example.com', $data->email);
    }

    /**
     * Forged or disabled method values cannot pass native form validation.
     */
    public function test_unavailable_gateway_is_rejected(): void {
        guest_checkout::mock_submit(['firstname' => 'Test', 'lastname' => 'Buyer',
            'email' => 'newbuyer@example.com', 'consent' => 1, 'action' => 'pay', 'gateway' => 'unavailable']);
        $form = new guest_checkout(null, ['gateways' => $this->methods()]);
        $this->assertNull($form->get_data());
    }

    /**
     * Public registry-shaped fixtures without credentials.
     * @return array
     */
    private function methods(): array {
        return ['stripe' => ['name' => 'Stripe', 'icon' => 'bi bi-stripe', 'description' => 'Card payment'],
            'paypal' => ['name' => 'PayPal', 'icon' => 'fa fa-cc-paypal', 'description' => 'PayPal checkout']];
    }
}
