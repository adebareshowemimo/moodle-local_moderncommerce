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
 * Native Moodle password tokens and authentication APIs for the commerce password page.
 *
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class guest_password_service {
    /**
     * Route the native form callback for a commerce account through the same single-use flow.
     *
     * Core has already validated the token when invoking this callback. Do not re-query it
     * here: even a concurrently consumed token must leave the native processing route.
     * The destination validates it again before displaying or accepting a password.
     *
     * @param object $user User supplied by core's native set-password form hook.
     * @return string Destination or an empty string for unrelated accounts.
     */
    public static function legacy_target(object $user): string {
        global $DB;
        if (
            empty($user->id) || !preg_match('/^[a-zA-Z0-9]{32}$/D', $user->token ?? '') ||
                !$DB->record_exists(guest_checkout_service::TABLE, [
                    'userid' => $user->id, 'newaccount' => 1, 'status' => 'fulfilled',
                ])
        ) {
            return '';
        }
        return (new \moodle_url('/local/moderncommerce/set_password.php', ['token' => $user->token]))->out(false);
    }

    /**
     * Validate a native reset token and restrict this flow to paid guest accounts.
     *
     * @param string $token Native Moodle password token.
     * @return array State and the user only when their token remains valid.
     */
    public static function inspect(string $token): array {
        global $DB, $CFG;
        $invalid = ['state' => 'invalid', 'user' => null];
        if (!preg_match('/^[a-zA-Z0-9]{32}$/D', $token)) {
            return $invalid;
        }
        $reset = $DB->get_record('user_password_resets', ['token' => $token]);
        if (!$reset) {
            return $invalid;
        }
        $user = \core_user::get_user($reset->userid);
        if (
            !$user || $user->deleted || $user->suspended || !$user->confirmed || isguestuser($user) ||
                $user->auth !== 'manual' || !is_enabled_auth('manual') ||
                !$DB->record_exists(guest_checkout_service::TABLE, [
                    'userid' => $user->id, 'newaccount' => 1, 'status' => 'fulfilled',
                ])
        ) {
            return $invalid;
        }
        if ($reset->timerequested < time() - ($CFG->pwresettime ?? 1800)) {
            return ['state' => 'expired', 'user' => null];
        }
        $user->token = $token;
        return ['state' => 'valid', 'user' => $user];
    }

    /**
     * Offer renewal only when its token belongs to the same native reset account.
     *
     * @param string $token Native password token, including recently expired tokens.
     * @param string $renew Separate email-only renewal token.
     * @return string Safe renewal URL or an empty string.
     */
    public static function renewal_url(string $token, string $renew): string {
        global $DB;
        if (!preg_match('/^[a-zA-Z0-9]{32}$/D', $token) || !guest_access_service::available($renew)) {
            return '';
        }
        $reset = $DB->get_record('user_password_resets', ['token' => $token]);
        if (
            $reset && !$DB->record_exists(guest_checkout_service::TABLE, [
            'userid' => $reset->userid, 'accesshash' => hash('sha256', $renew),
            ])
        ) {
            return '';
        }
        return (new \moodle_url('/local/moderncommerce/guest_access.php', ['token' => $renew]))->out(false);
    }

    /**
     * Set a password using core authentication, policy and history; consume tokens atomically.
     *
     * @param string $token Native password token.
     * @param string $password New password, never persisted or logged in plaintext.
     * @param string $confirmation Repeated password.
     * @param bool $logoutothers Revoke other sessions.
     * @return object Updated account for core session establishment by the page.
     */
    public static function save(string $token, string $password, string $confirmation, bool $logoutothers = true): object {
        global $DB, $CFG, $USER;
        require_sesskey();
        require_once($CFG->dirroot . '/login/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        $inspection = self::inspect($token);
        if ($inspection['state'] !== 'valid') {
            throw new \moodle_exception('mcpasswordinvalid', 'local_moderncommerce');
        }
        $user = $inspection['user'];
        if (isloggedin() && !isguestuser() && (int)$USER->id !== (int)$user->id) {
            throw new \moodle_exception('mcpassworddifferentuser', 'local_moderncommerce');
        }
        $lock = \core\lock\lock_config::get_lock_factory('local_moderncommerce_guest')
            ->get_lock('password-user:' . $user->id, 10);
        if (!$lock) {
            throw new \moodle_exception('guestcheckoutbusy', 'local_moderncommerce');
        }
        try {
            $inspection = self::inspect($token);
            if ($inspection['state'] !== 'valid') {
                throw new \moodle_exception('mcpasswordinvalid', 'local_moderncommerce');
            }
            $user = $inspection['user'];
            if ($password !== $confirmation) {
                throw new \moodle_exception('passwordsdiffer');
            }
            $message = '';
            if (
                $password === '' || \core_text::strlen($password) > MAX_PASSWORD_CHARACTERS ||
                    !check_password_policy($password, $message, $user)
            ) {
                throw new \moodle_exception('mcpasswordpolicy', 'local_moderncommerce');
            }
            if (user_is_previously_used_password($user->id, $password)) {
                throw new \moodle_exception('errorpasswordreused', 'core_auth');
            }
            $transaction = $DB->start_delegated_transaction();
            if (!get_auth_plugin($user->auth)->user_update_password($user, $password)) {
                throw new \moodle_exception('errorpasswordupdate', 'auth');
            }
            user_add_password_history($user->id, $password);
            // Every outstanding link for this account becomes unusable after a successful change.
            $DB->delete_records('user_password_resets', ['userid' => $user->id]);
            login_unlock_account($user);
            unset_user_preference('auth_forcepasswordchange', $user);
            unset_user_preference('create_password', $user);
            $transaction->allow_commit();
            if (!empty($CFG->passwordchangelogout) || $logoutothers) {
                \core\session\manager::destroy_user_sessions($user->id, session_id());
            }
            return \core_user::get_user($user->id, '*', MUST_EXIST);
        } catch (\Throwable $e) {
            if (isset($transaction) && !$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
