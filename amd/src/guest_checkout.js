// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Check the buyer's email before guest payment without reserving or creating an account.
 * @module local_moderncommerce/guest_checkout
 * @copyright 2026 Adebare Showemimo
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
import Ajax from 'core/ajax';

/**
 * Attach accessible feedback and a final pre-submit check.
 * @param {Object} config Localized messages.
 */
export const init = (config) => {
    const form = document.getElementById('mc-guest-details-form');
    const email = form?.querySelector('[name="email"]');
    if (!email || email.dataset.guestEmailCheck) {
        return;
    }
    email.dataset.guestEmailCheck = '1';
    email.type = 'email';
    const feedback = document.createElement('div');
    feedback.id = 'mc-guest-email-feedback';
    feedback.className = 'small mt-2';
    feedback.setAttribute('role', 'status');
    feedback.setAttribute('aria-live', 'polite');
    email.after(feedback);
    email.setAttribute('aria-describedby', `${email.getAttribute('aria-describedby') || ''} ${feedback.id}`.trim());
    let timer;
    let version = 0;
    let pending;
    let lastEmail = '';
    let result;
    const value = () => email.value.trim().toLowerCase();
    const show = (message, invalid = false) => {
        feedback.textContent = message;
        feedback.className = `small mt-2 ${invalid ? 'text-danger' : 'text-muted'}`;
        email.setAttribute('aria-invalid', invalid ? 'true' : 'false');
    };
    const check = () => {
        const address = value();
        if (!address || !email.validity.valid) {
            show(address ? config.invalid : '', Boolean(address));
            return Promise.resolve(false);
        }
        if (address === lastEmail && (pending || result !== undefined)) {
            return pending || Promise.resolve(result);
        }
        lastEmail = address;
        result = undefined;
        const current = version;
        show(config.checking);
        pending = Promise.resolve(Ajax.call([{
            methodname: 'local_moderncommerce_check_guest_email',
            args: {email: address}
        }])[0]).then((response) => {
            if (current !== version || address !== value()) {
                return false;
            }
            result = response.available;
            show(response.message, !result);
            return result;
        }).catch(() => {
            if (current === version) {
                show(config.failed, true);
                lastEmail = '';
            }
            return false;
        }).finally(() => {
            if (current === version) {
                pending = null;
            }
        });
        return pending;
    };
    email.addEventListener('input', () => {
        clearTimeout(timer);
        version++;
        lastEmail = '';
        pending = null;
        result = undefined;
        show('');
        timer = setTimeout(check, 600);
    });
    email.addEventListener('blur', () => {
        clearTimeout(timer);
        check();
    });
    let submitting = false;
    form.addEventListener('submit', async(event) => {
        if (!value()) {
            // Let Moodle's form validation display its normal required-field message.
            return;
        }
        if (value() === lastEmail && result === true) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        if (submitting) {
            return;
        }
        submitting = true;
        clearTimeout(timer);
        const address = value();
        const submitter = event.submitter;
        try {
            if (await check() && address === value()) {
                form.requestSubmit(submitter || undefined);
            } else {
                email.focus();
            }
        } finally {
            submitting = false;
        }
    }, true);
};
