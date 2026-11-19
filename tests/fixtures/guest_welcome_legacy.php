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

/**
 * Original guest welcome body used to verify safe template upgrades.
 *
 * @package local_moderncommerce
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

// phpcs:disable moodle.Files.LineLength -- Exact legacy template fixture.
return '<h2>Welcome to {sitename}</h2>
<p>Hi {firstname},</p>
<p>Thank you for your purchase. We have created a learning account for you at <strong>{sitename}</strong> and enrolled you in your purchased courses.</p>
<p>Please set your password to sign in and start learning.</p>
<p><a class="mc-button" href="{set_password_url}">Set your password</a></p>
<p>This secure link is valid for {password_expiry_minutes} minutes. If it expires, <a href="{forgot_password_url}">request a new link</a>.</p>
<p>If you have already set your password, <a href="{login_url}">sign in</a>.</p>
<p>Need help? Contact <a href="mailto:{supportemail}">{supportemail}</a>.</p>';
