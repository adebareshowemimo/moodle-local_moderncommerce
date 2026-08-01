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

namespace local_moderncommerce\subscription\observer;

use local_moderncommerce\subscription\services\subscription_service;
use local_moderncommerce\subscription\services\upgrade_service;

/**
 * Payment observer - creates/renews subscriptions when a Modern Commerce order is paid.
 *
 * Subscription orders are created by
 * local_moderncommerce\api\order_api::create_subscription_order(), which stores
 * the plan and intent in the order notes JSON under a "subscription" key:
 *   {"subscription": {"planid": N, "action": "new|upgrade|renewal", ...}}
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemmo
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class payment_observer {
    /**
     * Handle the Modern Commerce order_paid event.
     *
     * @param \local_moderncommerce\event\order_paid $event
     */
    public static function order_paid(\local_moderncommerce\event\order_paid $event) {
        global $DB;

        $orderid = (int) $event->get_data()['objectid'];
        $order = $DB->get_record('local_moderncommerce_orders', ['id' => $orderid]);
        if (!$order || empty($order->notes)) {
            return;
        }

        $notes = json_decode($order->notes, true);
        if (!is_array($notes) || empty($notes['subscription']) || empty($notes['subscription']['planid'])) {
            return;
        }

        self::process_subscription_payment($order, $notes['subscription']);
    }

    /**
     * Create, renew, upgrade, or reactivate a subscription for a paid order.
     *
     * @param object $order Modern Commerce order record.
     * @param array $sub Subscription intent from the order notes.
     */
    private static function process_subscription_payment(object $order, array $sub) {
        global $DB;

        $planid = (int) $sub['planid'];
        $userid = (int) $order->userid;
        $action = (string) ($sub['action'] ?? 'new');

        // Explicit upgrade of a known subscription.
        if ($action === 'upgrade' && !empty($sub['from_subscription_id'])) {
            $existing = $DB->get_record('local_moderncommerce_user_subscriptions', ['id' => (int) $sub['from_subscription_id']]);
            if ($existing && (int) $existing->userid === $userid) {
                upgrade_service::process_immediate_upgrade((int) $existing->id, $planid, (int) $order->id);
                return;
            }
        }

        // Explicit renewal of a known subscription.
        if ($action === 'renewal' && !empty($sub['subscription_id'])) {
            $existing = $DB->get_record('local_moderncommerce_user_subscriptions', ['id' => (int) $sub['subscription_id']]);
            if ($existing && (int) $existing->userid === $userid) {
                if (in_array($existing->status, ['active', 'trial', 'grace'], true)) {
                    subscription_service::renew((int) $existing->id, $planid ?: null, (int) $order->id);
                } else {
                    subscription_service::reactivate((int) $existing->id, (int) $order->id);
                }
                return;
            }
        }

        // New subscription. If the user already holds this plan, renew/reactivate it; otherwise create.
        $existing = $DB->get_record_sql(
            "SELECT * FROM {local_moderncommerce_user_subscriptions}
              WHERE userid = :userid AND planid = :planid
                AND status IN ('active', 'trial', 'grace', 'suspended')",
            ['userid' => $userid, 'planid' => $planid]
        );

        if ($existing) {
            if (in_array($existing->status, ['active', 'trial', 'grace'], true)) {
                subscription_service::renew((int) $existing->id, null, (int) $order->id);
            } else if ($existing->status === 'suspended') {
                subscription_service::reactivate((int) $existing->id, (int) $order->id);
            }
            return;
        }

        // Paid purchase, so no trial.
        subscription_service::activate($userid, $planid, (int) $order->id, false);
    }
}
