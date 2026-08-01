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

namespace local_moderncommerce\subscription\task;

use local_moderncommerce\subscription\services\subscription_service;
use local_moderncommerce\subscription\services\email_service;
use local_moderncommerce\subscription\recurring\recurring_payment_service;

/**
 * Task to process recurring subscription payments.
 *
 * This task handles:
 * - Processing subscriptions due for renewal
 * - Charging stored payment methods via Stripe/PayPal
 * - Handling failed payments with retry logic
 * - Sending payment reminders and failure notifications
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemmo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_recurring_payments extends \core\task\scheduled_task {
    /** @var int Maximum payment retry attempts before suspension */
    const MAX_RETRY_ATTEMPTS = 3;

    /** @var int Days between retry attempts */
    const RETRY_INTERVAL_DAYS = 3;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:processrecurringpayments', 'local_moderncommerce');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        mtrace('Processing recurring subscription payments...');

        // Process subscriptions due for billing.
        $this->process_due_subscriptions();

        // Retry failed payments.
        $this->retry_failed_payments();

        // Send upcoming billing reminders.
        $this->send_billing_reminders();

        mtrace('Recurring payment processing complete.');
    }

    /**
     * Process subscriptions that are due for billing.
     */
    private function process_due_subscriptions(): void {
        global $DB;

        $now = time();

        // Get active subscriptions with auto_renew that are due for billing.
        $sql = "SELECT s.*, p.name AS plan_name, p.price, p.billing_cycle, p.currency,
                       u.email, u.firstname, u.lastname
                FROM {local_moderncommerce_user_subscriptions} s
                JOIN {local_moderncommerce_subscription_plans} p ON p.id = s.planid
                JOIN {user} u ON u.id = s.userid
                WHERE s.status = 'active'
                  AND s.auto_renew = 1
                  AND s.cancel_at_period_end = 0
                  AND (
                      (s.next_billing_date IS NOT NULL AND s.next_billing_date <= :now1)
                      OR (s.next_billing_date IS NULL AND s.end_date <= :now2)
                  )
                  AND (s.stripe_subscription_id IS NOT NULL OR s.paypal_subscription_id IS NOT NULL)";

        $subscriptions = $DB->get_records_sql($sql, [
            'now1' => $now,
            'now2' => $now,
        ]);

        mtrace("Found " . count($subscriptions) . " subscriptions due for billing.");

        foreach ($subscriptions as $subscription) {
            try {
                $this->charge_subscription($subscription);
            } catch (\Exception $e) {
                mtrace("Error processing subscription {$subscription->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Charge a subscription using the stored payment method.
     *
     * @param object $subscription Subscription record with plan and user details.
     */
    private function charge_subscription(object $subscription): void {
        global $DB;

        mtrace("Charging subscription {$subscription->id} for user {$subscription->userid}...");

        $success = false;
        try {
            // Determine payment gateway.
            if (!empty($subscription->stripe_subscription_id)) {
                $success = $this->charge_via_stripe($subscription);
            } else if (!empty($subscription->paypal_subscription_id)) {
                $success = $this->charge_via_paypal($subscription);
            } else {
                throw new \Exception('No payment method configured');
            }

            if ($success) {
                $this->handle_successful_payment($subscription);
            } else {
                $this->handle_failed_payment($subscription, 'Payment declined');
            }
        } catch (\Exception $e) {
            $this->handle_failed_payment($subscription, $e->getMessage());
        }
    }

    /**
     * Charge via Stripe.
     *
     * @param object $subscription Subscription record.
     * @return bool Success.
     */
    private function charge_via_stripe(object $subscription): bool {
        // Check if Stripe gateway is available.
        if (!class_exists('\\local_moderncommerce\\payment\\stripe_gateway')) {
            mtrace("Stripe gateway not available.");
            return false;
        }

        $stripesecretkey = get_config('local_moderncommerce', 'stripe_secret_key');
        if (empty($stripesecretkey)) {
            mtrace("Stripe not configured.");
            return false;
        }

        // Use Stripe API to create a charge or invoice.
        try {
            \Stripe\Stripe::setApiKey($stripesecretkey);

            // If we have a Stripe subscription ID, the billing is handled by Stripe.
            // This is for subscriptions managed via Stripe Billing.
            if (!empty($subscription->stripe_subscription_id)) {
                // Check Stripe subscription status.
                $stripesubscription = \Stripe\Subscription::retrieve($subscription->stripe_subscription_id);

                if ($stripesubscription->status === 'active') {
                    mtrace("Stripe subscription {$subscription->stripe_subscription_id} is active.");
                    return true;
                } else if ($stripesubscription->status === 'past_due') {
                    mtrace("Stripe subscription {$subscription->stripe_subscription_id} is past due.");
                    return false;
                }
            }

            // For manual charging with stored payment method.
            if (!empty($subscription->stripe_customer_id) && !empty($subscription->stripe_payment_method_id)) {
                $amount = (int) ($subscription->price * 100); // Convert to cents.
                $currency = strtolower($subscription->currency ?: 'usd');

                $paymentintent = \Stripe\PaymentIntent::create([
                    'amount' => $amount,
                    'currency' => $currency,
                    'customer' => $subscription->stripe_customer_id,
                    'payment_method' => $subscription->stripe_payment_method_id,
                    'off_session' => true,
                    'confirm' => true,
                    'description' => "Subscription renewal: {$subscription->plan_name}",
                    'metadata' => [
                        'subscription_id' => $subscription->id,
                        'user_id' => $subscription->userid,
                        'plan_id' => $subscription->planid,
                    ],
                ]);

                if ($paymentintent->status === 'succeeded') {
                    mtrace("Stripe payment succeeded for subscription {$subscription->id}.");
                    return true;
                }
            }

            return false;
        } catch (\Stripe\Exception\CardException $e) {
            mtrace("Stripe card error: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            mtrace("Stripe error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Charge via PayPal.
     *
     * @param object $subscription Subscription record.
     * @return bool Success.
     */
    private function charge_via_paypal(object $subscription): bool {
        // PayPal subscriptions are managed by PayPal.
        // This checks the subscription status rather than charging directly.

        $paypalclientid = get_config('local_moderncommerce', 'paypal_client_id');
        $paypalsecret = get_config('local_moderncommerce', 'paypal_secret');

        if (empty($paypalclientid) || empty($paypalsecret)) {
            mtrace("PayPal not configured.");
            return false;
        }

        try {
            // Get access token.
            $sandbox = get_config('local_moderncommerce', 'paypal_sandbox');
            $apiurl = $sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';

            $curl = new \curl();
            $curl->setHeader(['Accept: application/json', 'Accept-Language: en_US']);
            $response = $curl->post(
                "{$apiurl}/v1/oauth2/token",
                'grant_type=client_credentials',
                [
                    'CURLOPT_USERPWD' => "{$paypalclientid}:{$paypalsecret}",
                    'CURLOPT_HTTPAUTH' => CURLAUTH_BASIC,
                    'CURLOPT_TIMEOUT' => 30,
                ]
            );

            $tokendata = json_decode($response, true);
            if (empty($tokendata['access_token'])) {
                mtrace("Failed to get PayPal access token.");
                return false;
            }

            // Check subscription status.
            $curl = new \curl();
            $curl->setHeader([
                'Authorization: Bearer ' . $tokendata['access_token'],
                'Content-Type: application/json',
            ]);
            $response = $curl->get(
                "{$apiurl}/v1/billing/subscriptions/{$subscription->paypal_subscription_id}",
                [],
                ['CURLOPT_TIMEOUT' => 30]
            );

            $subscriptiondata = json_decode($response, true);
            if (!empty($subscriptiondata['status']) && $subscriptiondata['status'] === 'ACTIVE') {
                mtrace("PayPal subscription {$subscription->paypal_subscription_id} is active.");
                return true;
            }

            mtrace("PayPal subscription status: " . ($subscriptiondata['status'] ?? 'unknown'));
            return false;
        } catch (\Exception $e) {
            mtrace("PayPal error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle successful payment.
     *
     * @param object $subscription Subscription record.
     */
    private function handle_successful_payment(object $subscription): void {
        global $DB;

        $now = time();
        $duration = subscription_service::get_cycle_duration($subscription->billing_cycle);

        // Update subscription.
        $DB->update_record('local_moderncommerce_user_subscriptions', (object)[
            'id' => $subscription->id,
            'end_date' => $subscription->end_date + $duration,
            'next_billing_date' => $subscription->end_date + $duration,
            'renewal_count' => $subscription->renewal_count + 1,
            'payment_failed_count' => 0,
            'last_payment_attempt' => $now,
            'timemodified' => $now,
        ]);

        // Log the renewal.
        $DB->insert_record('local_moderncommerce_subscription_log', [
            'subscriptionid' => $subscription->id,
            'userid' => $subscription->userid,
            'planid' => $subscription->planid,
            'action' => 'recurring_renewed',
            'details' => json_encode([
                'payment_method' => !empty($subscription->stripe_subscription_id) ? 'stripe' : 'paypal',
                'amount' => $subscription->price,
                'currency' => $subscription->currency,
            ]),
            'timecreated' => $now,
        ]);

        // Send renewal confirmation email.
        $this->send_renewal_confirmation($subscription);

        mtrace("Subscription {$subscription->id} renewed successfully.");
    }

    /**
     * Handle failed payment.
     *
     * @param object $subscription Subscription record.
     * @param string $reason Failure reason.
     */
    private function handle_failed_payment(object $subscription, string $reason): void {
        global $DB;

        $now = time();
        $failedcount = ($subscription->payment_failed_count ?? 0) + 1;

        // Update failure tracking.
        $DB->update_record('local_moderncommerce_user_subscriptions', (object)[
            'id' => $subscription->id,
            'payment_failed_count' => $failedcount,
            'last_payment_attempt' => $now,
            'timemodified' => $now,
        ]);

        // Log the failure.
        $DB->insert_record('local_moderncommerce_subscription_log', [
            'subscriptionid' => $subscription->id,
            'userid' => $subscription->userid,
            'planid' => $subscription->planid,
            'action' => 'payment_failed',
            'details' => json_encode([
                'reason' => $reason,
                'attempt' => $failedcount,
            ]),
            'timecreated' => $now,
        ]);

        // Check if max retries exceeded.
        if ($failedcount >= self::MAX_RETRY_ATTEMPTS) {
            // Suspend subscription.
            subscription_service::suspend($subscription->id, 'Payment failed after ' . $failedcount . ' attempts');
            $this->send_final_payment_failure($subscription);
            mtrace("Subscription {$subscription->id} suspended after {$failedcount} failed payment attempts.");
        } else {
            // Send payment failure notification.
            $this->send_payment_failure($subscription, $failedcount);
            mtrace("Payment failed for subscription {$subscription->id} (attempt {$failedcount}).");
        }
    }

    /**
     * Retry failed payments.
     */
    private function retry_failed_payments(): void {
        global $DB;

        $now = time();
        $retryafter = $now - (self::RETRY_INTERVAL_DAYS * DAYSECS);

        // Get subscriptions with failed payments that are due for retry.
        $sql = "SELECT s.*, p.name AS plan_name, p.price, p.billing_cycle, p.currency,
                       u.email, u.firstname, u.lastname
                FROM {local_moderncommerce_user_subscriptions} s
                JOIN {local_moderncommerce_subscription_plans} p ON p.id = s.planid
                JOIN {user} u ON u.id = s.userid
                WHERE s.status = 'active'
                  AND s.auto_renew = 1
                  AND s.payment_failed_count > 0
                  AND s.payment_failed_count < :maxretries
                  AND s.last_payment_attempt <= :retryafter
                  AND (s.stripe_subscription_id IS NOT NULL OR s.paypal_subscription_id IS NOT NULL)";

        $subscriptions = $DB->get_records_sql($sql, [
            'maxretries' => self::MAX_RETRY_ATTEMPTS,
            'retryafter' => $retryafter,
        ]);

        mtrace("Found " . count($subscriptions) . " subscriptions for payment retry.");

        foreach ($subscriptions as $subscription) {
            try {
                $this->charge_subscription($subscription);
            } catch (\Exception $e) {
                mtrace("Error retrying payment for subscription {$subscription->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Send billing reminders for upcoming charges.
     */
    private function send_billing_reminders(): void {
        global $DB;

        $now = time();
        $remindertime = $now + (3 * DAYSECS); // 3 days before billing.

        // Get subscriptions due for billing reminder.
        $sql = "SELECT s.*, p.name AS plan_name, p.price, p.billing_cycle, p.currency,
                       u.id AS userid, u.email, u.firstname, u.lastname
                FROM {local_moderncommerce_user_subscriptions} s
                JOIN {local_moderncommerce_subscription_plans} p ON p.id = s.planid
                JOIN {user} u ON u.id = s.userid
                WHERE s.status = 'active'
                  AND s.auto_renew = 1
                  AND s.next_billing_date > :now
                  AND s.next_billing_date <= :remindertime
                  AND (s.last_reminder_sent IS NULL OR s.last_reminder_sent < :lastreminder)";

        $subscriptions = $DB->get_records_sql($sql, [
            'now' => $now,
            'remindertime' => $remindertime,
            'lastreminder' => $now - (7 * DAYSECS), // Don't send more than once per week.
        ]);

        mtrace("Found " . count($subscriptions) . " subscriptions for billing reminder.");

        foreach ($subscriptions as $subscription) {
            try {
                $this->send_billing_reminder($subscription);
                $DB->set_field('local_moderncommerce_user_subscriptions', 'last_reminder_sent', $now, ['id' => $subscription->id]);
            } catch (\Exception $e) {
                mtrace("Error sending billing reminder for subscription {$subscription->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Send renewal confirmation email.
     *
     * @param object $subscription Subscription record.
     */
    private function send_renewal_confirmation(object $subscription): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $subscription->userid]);
        if (!$user) {
            return;
        }

        try {
            if (class_exists('\\local_moderncommerce\subscription\\services\\email_service')) {
                email_service::send('recurring_renewal_success', $user, [
                    'plan_name' => $subscription->plan_name,
                    'amount' => number_format($subscription->price, 2),
                    'currency' => $subscription->currency,
                    'next_date' => userdate(
                        $subscription->end_date
                            + subscription_service::get_cycle_duration($subscription->billing_cycle)
                    ),
                    'subscription_url' => (new \moodle_url('/local/moderncommerce/learner/subscription.php'))->out(false),
                ]);
            }
        } catch (\Exception $e) {
            mtrace("Failed to send renewal confirmation: " . $e->getMessage());
        }
    }

    /**
     * Send payment failure notification.
     *
     * @param object $subscription Subscription record.
     * @param int $attempt Attempt number.
     */
    private function send_payment_failure(object $subscription, int $attempt): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $subscription->userid]);
        if (!$user) {
            return;
        }

        try {
            if (class_exists('\\local_moderncommerce\subscription\\services\\email_service')) {
                email_service::send('payment_failed', $user, [
                    'plan_name' => $subscription->plan_name,
                    'amount' => number_format($subscription->price, 2),
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_RETRY_ATTEMPTS,
                    'update_url' => (new \moodle_url('/local/moderncommerce/learner/subscription.php'))->out(false),
                ]);
            }
        } catch (\Exception $e) {
            mtrace("Failed to send payment failure notification: " . $e->getMessage());
        }
    }

    /**
     * Send final payment failure notification (subscription suspended).
     *
     * @param object $subscription Subscription record.
     */
    private function send_final_payment_failure(object $subscription): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $subscription->userid]);
        if (!$user) {
            return;
        }

        try {
            if (class_exists('\\local_moderncommerce\subscription\\services\\email_service')) {
                email_service::send('subscription_suspended_payment', $user, [
                    'plan_name' => $subscription->plan_name,
                    'reactivate_url' => (new \moodle_url('/local/moderncommerce/learner/subscription.php', [
                        'id' => $subscription->id,
                    ]))->out(false),
                ]);
            }
        } catch (\Exception $e) {
            mtrace("Failed to send final payment failure notification: " . $e->getMessage());
        }
    }

    /**
     * Send billing reminder email.
     *
     * @param object $subscription Subscription record.
     */
    private function send_billing_reminder(object $subscription): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $subscription->userid]);
        if (!$user) {
            return;
        }

        try {
            if (class_exists('\\local_moderncommerce\subscription\\services\\email_service')) {
                email_service::send('upcoming_billing', $user, [
                    'plan_name' => $subscription->plan_name,
                    'amount' => number_format($subscription->price, 2),
                    'currency' => $subscription->currency,
                    'billing_date' => userdate(
                        $subscription->next_billing_date,
                        get_string('strftimedateshort', 'core_langconfig')
                    ),
                    'subscription_url' => (new \moodle_url('/local/moderncommerce/learner/subscription.php'))->out(false),
                ]);
            }
        } catch (\Exception $e) {
            mtrace("Failed to send billing reminder: " . $e->getMessage());
        }
    }
}
