// This file is part of Moodle - https://moodle.org/
// Moodle is free software under the GNU GPL v3 or later.
// @copyright 2026 Adebare Showemimo
// @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../amd/src/guest_checkout.js', import.meta.url), 'utf8')
    .replace("import Ajax from 'core/ajax';", '')
    .replace('export const init', 'const init');

function checkout() {
    const listeners = new Map();
    const attributes = new Map();
    const feedback = {};
    const requests = [];
    const email = {
        value: 'buyer@example.com', dataset: {}, validity: {valid: true},
        after() {}, focus() {},
        getAttribute: (name) => attributes.get(name),
        setAttribute: (name, value) => attributes.set(name, value),
        addEventListener: (name, fn) => listeners.set(name, fn),
    };
    let submit;
    let purchases = 0;
    const form = {
        querySelector: (selector) => selector === '[name="email"]' ? email : null,
        addEventListener: (name, fn) => { submit = fn; },
        requestSubmit: async() => {
            const event = {prevented: false, preventDefault() { this.prevented = true; }, stopImmediatePropagation() {}};
            await submit(event);
            if (!event.prevented) {
                purchases++;
            }
        },
    };
    vm.runInNewContext(`${source}\ninit(config);`, {
        document: {getElementById: id => id === 'mc-guest-details-form' ? form : null,
            createElement: () => ({...feedback, setAttribute() {}})},
        config: {checking: 'Checking', failed: 'Try again', invalid: 'Invalid email'},
        Promise,
        setTimeout: () => 1,
        clearTimeout() {},
        Ajax: {call: (calls) => [new Promise((resolve, reject) => {
            requests.push({address: calls[0].args.email, resolve, reject});
        })]},
    });
    return {
        email, requests,
        submit: () => form.requestSubmit(),
        blur: () => listeners.get('blur')(),
        change: (value) => { email.value = value; listeners.get('input')(); },
        purchases: () => purchases,
    };
}

test('a quick submit waits for availability before payment', async() => {
    const page = checkout();
    const pending = page.submit();
    assert.equal(page.purchases(), 0);
    page.requests[0].resolve({available: true, message: 'Available'});
    await pending;
    await Promise.resolve();
    assert.equal(page.purchases(), 1);
});

test('an existing identity blocks payment', async() => {
    const page = checkout();
    const pending = page.submit();
    page.requests[0].resolve({available: false, message: 'Please sign in'});
    await pending;
    assert.equal(page.purchases(), 0);
    await page.submit();
    assert.equal(page.requests.length, 1);
    assert.equal(page.purchases(), 0);
});

test('a stale response cannot approve a changed email', async() => {
    const page = checkout();
    const first = page.submit();
    page.change('other@example.com');
    page.requests[0].resolve({available: true, message: 'Available'});
    await first;
    assert.equal(page.purchases(), 0);
    const second = page.submit();
    assert.equal(page.requests[1].address, 'other@example.com');
    page.requests[1].resolve({available: false, message: 'Please sign in'});
    await second;
    assert.equal(page.purchases(), 0);
});

test('a failed request permits a fresh retry without approving payment', async() => {
    const page = checkout();
    const first = page.submit();
    page.requests[0].reject(new Error('Offline'));
    await first;
    assert.equal(page.purchases(), 0);
    const second = page.submit();
    page.requests[1].resolve({available: true, message: 'Available'});
    await second;
    await Promise.resolve();
    assert.equal(page.purchases(), 1);
});

test('duplicate submissions share the pending lookup', async() => {
    const page = checkout();
    const first = page.submit();
    await page.submit();
    assert.equal(page.requests.length, 1);
    page.requests[0].resolve({available: true, message: 'Available'});
    await first;
    await Promise.resolve();
    assert.equal(page.purchases(), 1);
});
