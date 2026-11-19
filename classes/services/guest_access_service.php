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

namespace local_moderncommerce\services;

/**
 * Email-only renewal of password setup links for paid guest purchasers.
 *
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guest_access_service {
    /**
     * Prepare a separate seven-day renewal token without invalidating an older email.
     *
     * @return array Link and hashed persistence data; never log or queue this payload.
     */
    public static function prepare_link(): array {
        $token = bin2hex(random_bytes(32));
        return [
            'hash' => hash('sha256', $token), 'expires' => time() + 7 * DAYSECS,
            'url' => (new \moodle_url('/local/moderncommerce/guest_access.php', ['token' => $token]))->out(false),
        ];
    }

    /**
     * Activate a renewal token only after successful email delivery.
     *
     * @param int $id Guest purchase ID.
     * @param array $link Data returned by prepare_link().
     */
    public static function activate_link(int $id, array $link): void {
        global $DB;
        $DB->update_record(guest_checkout_service::TABLE, (object)[
            'id' => $id, 'accesshash' => $link['hash'], 'accessexpires' => $link['expires'],
        ]);
    }

    /**
     * Determine whether the renewal form may be offered without disclosing identity.
     *
     * @param string $token Separate renewal token, never a password-setting token.
     * @return bool
     */
    public static function available(string $token): bool {
        return self::resolve($token) !== null;
    }

    /**
     * Email a fresh native password-setting link, never return it to the browser.
     *
     * @param string $token Renewal token from the welcome email.
     */
    public static function request(string $token): void {
        require_sesskey();
        guest_checkout_service::limit_email_checks();
        $record = self::resolve($token);
        if (!$record) {
            throw new \moodle_exception('guestaccessinvalid', 'local_moderncommerce');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_moderncommerce_guest')->get_lock('access:' . $record->id, 10);
        if (!$lock) {
            throw new \moodle_exception('guestcheckoutbusy', 'local_moderncommerce');
        }
        try {
            $record = self::resolve($token);
            if (!$record) {
                throw new \moodle_exception('guestaccessinvalid', 'local_moderncommerce');
            }
            if (
                !\local_moderncommerce\email\notification_catalog::is_enabled(
                    \local_moderncommerce\email\commerce_seed::GUEST_WELCOME
                )
            ) {
                throw new \moodle_exception('guestaccessunavailable', 'local_moderncommerce');
            }
            if (max($record->mailsent, $record->timemodified) > time() - 5 * MINSECS) {
                throw new \moodle_exception('guestaccesslimited', 'local_moderncommerce');
            }
            // Resend serialises with payment fulfilment and rotates the renewal token in the new email.
            guest_checkout_service::resend((int)$record->id);
        } finally {
            $lock->release();
        }
    }

    /**
     * Resolve an eligible account without treating a renewal token as login authority.
     *
     * @param string $token Renewal token.
     * @return object|null
     */
    private static function resolve(string $token): ?object {
        global $DB;
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }
        $record = $DB->get_record(guest_checkout_service::TABLE, ['accesshash' => hash('sha256', $token)]);
        if (
            !$record || $record->status !== 'fulfilled' || !$record->newaccount || !$record->userid ||
                $record->accessexpires <= time()
        ) {
            return null;
        }
        $user = \core_user::get_user($record->userid);
        if (
            !$user || $user->deleted || $user->suspended || !$user->confirmed || $user->auth !== 'manual' ||
                !is_enabled_auth('manual') || \core_text::strtolower($user->email) !== $record->email
        ) {
            return null;
        }
        return $record;
    }
}
