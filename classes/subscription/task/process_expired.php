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

use local_moderncommerce\subscription\api\subscription_api;
use local_moderncommerce\subscription\services\subscription_service;

/**
 * Task to process expired subscriptions.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemmo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class process_expired extends \core\task\scheduled_task {
    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task:processexpired', 'local_moderncommerce');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        mtrace('Processing expired subscriptions...');

        // Process subscriptions that have ended (move to grace period).
        $this->process_ended_subscriptions();

        // Process subscriptions with expired grace period.
        $this->process_grace_expired();

        mtrace('Expired subscription processing complete.');
    }

    /**
     * Process subscriptions that have ended their active period.
     */
    private function process_ended_subscriptions(): void {
        global $DB;

        $now = time();

        // Get active/trial subscriptions that have ended.
        $sql = "SELECT s.*, p.grace_period_days, p.name AS plan_name
                FROM {local_moderncommerce_user_subscriptions} s
                JOIN {local_moderncommerce_subscription_plans} p ON p.id = s.planid
                WHERE s.status IN ('active', 'trial')
                  AND s.end_date <= :now";

        $subscriptions = $DB->get_records_sql($sql, ['now' => $now]);

        mtrace("Found " . count($subscriptions) . " ended subscriptions to process.");

        foreach ($subscriptions as $subscription) {
            try {
                if ($subscription->grace_period_days > 0) {
                    // Enter grace period.
                    subscription_service::enter_grace_period($subscription->id);
                    mtrace("Subscription {$subscription->id} entered grace period.");

                    // Send grace period notification.
                    $this->send_grace_notification($subscription);
                } else {
                    // No grace period, suspend immediately.
                    subscription_service::suspend($subscription->id, 'Subscription period ended');
                    mtrace("Subscription {$subscription->id} suspended (no grace period).");

                    // Send expiration notification.
                    $this->send_expired_notification($subscription);
                }
            } catch (\Exception $e) {
                mtrace("Error processing subscription {$subscription->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Process subscriptions with expired grace period.
     */
    private function process_grace_expired(): void {
        global $DB;

        $now = time();

        // Get subscriptions in grace period that have expired.
        $sql = "SELECT s.*, p.name AS plan_name
                FROM {local_moderncommerce_user_subscriptions} s
                JOIN {local_moderncommerce_subscription_plans} p ON p.id = s.planid
                WHERE s.status = 'grace'
                  AND s.grace_end_date <= :now";

        $subscriptions = $DB->get_records_sql($sql, ['now' => $now]);

        mtrace("Found " . count($subscriptions) . " grace-expired subscriptions.");

        foreach ($subscriptions as $subscription) {
            try {
                subscription_service::suspend($subscription->id, 'Grace period ended');
                mtrace("Subscription {$subscription->id} suspended after grace period.");

                // Send final expiration notification.
                $this->send_expired_notification($subscription);
            } catch (\Exception $e) {
                mtrace("Error processing subscription {$subscription->id}: " . $e->getMessage());
            }
        }
    }

    /**
     * Send grace period notification.
     *
     * @param object $subscription Subscription record.
     */
    private function send_grace_notification(object $subscription): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $subscription->userid]);
        if (!$user) {
            return;
        }

        $placeholders = local_moderncommerce_subscription_prepare_placeholders($subscription);
        $placeholders['grace_end_date'] = userdate($subscription->grace_end_date);
        $placeholders['renew_url'] = (new \moodle_url('/local/moderncommerce/learner/subscription.php', [
            'id' => $subscription->id,
        ]))->out(false);
        $placeholders['renewal_url'] = $placeholders['renew_url'];

        // Send via Modern Commerce core email templates if available.
        if (class_exists('\local_moderncommerce\api\email_api')) {
            try {
                \local_moderncommerce\api\email_api::send(
                    'modernsubscription_grace_period',
                    $subscription->userid,
                    $placeholders
                );
            } catch (\Exception $e) {
                mtrace("Failed to send grace notification: " . $e->getMessage());
            }
        }
    }

    /**
     * Send expired notification.
     *
     * @param object $subscription Subscription record.
     */
    private function send_expired_notification(object $subscription): void {
        global $DB;

        $user = $DB->get_record('user', ['id' => $subscription->userid]);
        if (!$user) {
            return;
        }

        $placeholders = local_moderncommerce_subscription_prepare_placeholders($subscription);
        $placeholders['resubscribe_url'] = (new \moodle_url('/local/moderncommerce/learner/index.php'))->out(false)
            . '#/library';
        $placeholders['subscription_url'] = $placeholders['resubscribe_url'];

        // Send via Modern Commerce core email templates if available.
        if (class_exists('\local_moderncommerce\api\email_api')) {
            try {
                \local_moderncommerce\api\email_api::send(
                    'modernsubscription_expired',
                    $subscription->userid,
                    $placeholders
                );
            } catch (\Exception $e) {
                mtrace("Failed to send expiration notification: " . $e->getMessage());
            }
        }
    }
}
