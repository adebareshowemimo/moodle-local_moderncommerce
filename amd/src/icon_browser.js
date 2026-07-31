/**
 * This file is part of Moodle - http://moodle.org/
 *
 * Moodle is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Moodle is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Moodle.  If not, see <http://www.gnu.org/licenses/>.
 *
 * Bootstrap icon browser interactions.
 *
 * @module     local_moderncommerce/icon_browser
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Initialize the icon browser.
 */
export const init = () => {
    const search = document.getElementById('iconSearch');
    if (search) {
        search.addEventListener('input', (event) => {
            const term = event.target.value.toLowerCase();
            document.querySelectorAll('.mc-icon-browser__item').forEach((item) => {
                const label = item.getAttribute('data-label').toLowerCase();
                item.style.display = label.includes(term) ? 'flex' : 'none';
            });
        });
    }

    document.querySelectorAll('[data-icon]').forEach((element) => {
        element.addEventListener('click', () => {
            const iconClass = element.getAttribute('data-icon');
            navigator.clipboard.writeText(iconClass).then(() => {
                const notification = document.getElementById('copyNotification');
                if (notification) {
                    notification.style.display = 'block';
                    setTimeout(() => {
                        notification.style.display = 'none';
                    }, 2000);
                }
            }).catch(() => {
                // Clipboard permissions vary by browser; the copy action is best-effort.
            });
        });
    });
};
