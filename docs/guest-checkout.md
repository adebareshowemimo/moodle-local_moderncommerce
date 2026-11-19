# Guest checkout

## Implementation plan

1. Add an opt-in checkout setting, disabled by default.
2. Isolate anonymous carts by a random server-session token.
3. Provide a public, CSRF-protected cart and checkout form using existing storefront components.
4. Freeze items, prices, tax, buyer details and the selected gateway before starting hosted checkout.
5. Verify signed payment notifications and retrieve authoritative provider payment state before fulfilment.
6. After payment, create a manual Moodle account for a new email. Existing accounts must sign in before claiming.
7. Create the normal commerce order from the frozen snapshot and reuse paid-order enrolment.
8. Retry incomplete fulfilment and email delivery through cron; expose recovery on the confirmation page.
9. Cover isolation, settings, verification, repeated events, account claiming, and retries with focused tests.

## Scope

The guest page uses the same blue header, cart rows, and order summary as the signed-in cart.
Buyer details occupy the left sidebar in place of account navigation. The summary's payment button targets
the native Moodle form in that sidebar, including its session key, validation, consent, and CAPTCHA when configured.
On narrower screens the details, items, and summary stack in reading order.

Courses and bundles support enabled, configured Stripe, PayPal, Paystack, Flutterwave and manual payment methods.
The payment selector respects gateway display order and currency support. Unavailable gateways are hidden.
Delayed payment methods remain pending until the provider confirms payment; no account or enrolment is created before that.
Guest coupons, programs, subscriptions, enrolment-key redemption,
and zero-total purchases require signing in. Existing authenticated checkout remains available.

## Operation

Enable **Allow guest checkout** in Modern Commerce checkout settings and configure the desired gateways.
Stripe requires its webhook signing secret.
Register `checkout.session.completed` and `checkout.session.async_payment_succeeded`
alongside the existing charge/refund events in Stripe's webhook destination.
Use Snapshot events from Your account; paste the destination's `whsec_` signing secret into the gateway settings.
PayPal requires its client ID, secret and webhook ID; use order-approved, capture-completed and refund events.
Paystack and Flutterwave use their existing gateway credentials, signing configuration and webhook endpoints.
The gateway test/live environment must match the purchase snapshot during verification.
PayPal callback URLs use `guesttoken` to avoid colliding with PayPal's appended `token` order ID.
Paystack verifies the stored reference, amount, currency, metadata and API environment. Flutterwave verifies the
stored transaction reference, amount and currency. PayPal verifies the approved order before capture and the
completed capture before fulfilment. Callback query values never establish that payment succeeded.

Manual payments show the amount and unique payment reference. The buyer arranges payment with site support.
An administrator with **Manage commerce orders** opens **Guest purchase recovery**, checks that payment was
received, and selects **Confirm payment received**. This action requires a session key and records the approver.
Only then are the account, paid order and enrolments created. Repeated approvals reuse the same order.
Manual methods must be enabled in gateway settings. Enrolment keys continue through their separate signed-in redemption flow.
The switch controls new guest operations only: turning it off never prevents a paid purchase from being fulfilled.
Run Moodle cron for fulfilment and mail retries. Check the guest checkout recovery report for pending paid purchases.
No Moodle account is created for abandoned or failed payment attempts.

New guest accounts use the checkout email address as their username, trimmed and converted to lowercase.
The checkout form checks availability while the buyer types and again before payment. If the email or
username is already in use, the buyer must sign in to continue. This includes older accounts whose
generated username differs from their email. Existing usernames are not renamed.
The public feedback service requires a browser session key, guest checkout enabled, catalog access,
and rate limits per browser and network address. It returns no account details.
Server form validation and payment initiation repeat the check even if JavaScript is disabled.
Emails must satisfy Moodle's configured username rules; characters are never silently removed.
For example, plus-addressed emails require Moodle's extended username characters setting.
After payment, existing-email ownership claims still require login. A late username collision with
another email keeps the paid purchase recoverable for administrator assistance and never attaches it to that account.

Payment status is verified using the selected gateway's API as well as the signed event. Repeated events and concurrent attempts
are protected by Moodle locks. The browser confirmation page never establishes a login session.
New learners receive the **Guest account welcome** email, with the site name, account-creation confirmation,
and Moodle's expiring password-setting link. The subject and HTML content are editable in
**Email Templates → Outgoing emails → Guest account welcome**. The bundled template also appears in the template list.
Use `{set_password_url}` for the secure link and `{password_expiry_minutes}` for its validity period, which follows
Moodle's password-reset expiry setting. `{firstname}`, `{sitename}`, `{login_url}`, and `{forgot_password_url}` are also available.
Preview links never contain real password tokens. No temporary password is generated or sent.
The **Set your password** button opens `/local/moderncommerce/set_password.php`.
This dedicated page contains the password and confirmation fields, reuses Moodle's native
password form and validation hooks, and writes passwords through the native manual authentication API.
Native token expiry, password policy/history, other-session logout and required-profile checks apply.
Successful changes consume all outstanding reset tokens for the account and redirect to My learning.
Password saves are serialised per account to prevent concurrent reuse; a different signed-in account
cannot be switched by submitting a buyer's link. Existing valid native Moodle links for these guest
accounts are forwarded to the branded page. Other accounts retain Moodle's original page.
New email links carry a separate email-only renewal capability so expired links can offer the
dedicated renewal page. Tokens are moved out of the address bar before the form is rendered.
The welcome email shows the recipient's actual `{username}` (the checkout email for new accounts),
explains the password-setup destination, and includes a `{learning_url}` link to My learning.
Upgrades refresh the unchanged original bundled copy, including an unchanged outgoing-email override.
Customised bodies, subjects and alternative selected templates are preserved.
Use `{password_request_url}` for the dedicated **Request a new password link** page.
Its separate renewal token identifies the paid guest account for seven days without asking for an email.
Opening the page sends nothing; the buyer presses **Send a new link**, protected by a session key.
The fresh password-setting link is emailed only to the registered address and never returned in the browser.
The renewal token is stored only as a hash and rotates after successful email delivery.
Requests have a five-minute cooldown plus per-browser/network limits. Expired, replaced or refunded
purchase links and suspended/deleted accounts cannot renew; users can contact support if renewal is unavailable.
Renewal works after guest checkout is disabled, while the welcome email delivery switch must remain enabled.
During a paid guest account's first password setup, Modern Commerce replaces any stale login destination
with `/local/moderncommerce/learner/index.php`. This also works for previously emailed native Moodle links.
Moodle still validates the expiring token, password policy, login session, and required profile details.
Accounts unrelated to paid guest purchases retain Moodle's normal password page and destination.
Administrator customisations survive upgrades. As with other commerce emails, disabling the notification suppresses it;
the learner can still request a password link from Moodle's sign-in page.
Existing learners claim through a link and their normal login; they do not receive a new-account welcome or password token.
An entered payment email is never treated as proof of ownership of an existing Moodle account.
Checkout collects consent to create the account. Moodle's configured site policies remain enforced on first login.
Anonymous initiation is limited by browser session, email address, and a keyed hash of the network address.

Pending guest purchases are retained for 30 days. Paid purchases remain available for recovery and are included
in the privacy provider once associated with a user. Never log API keys, password reset links, or claim tokens.

## Administrative recovery

The Checkout settings tab links to `/local/moderncommerce/admin/guest_checkouts.php`.
The recovery link is spaced separately from the guest checkout switch and wraps onto its own line on phones.
Administrators with the commerce settings or order-management capability can inspect pending, paid, claim, fulfilled, expired,
and refunded attempts, retry processing, or resend account access. The report never displays payment secrets
or account claim tokens. Existing-account claims are completed by that account's owner through normal login.
For ambiguous email addresses, suspended/deleted users, or unavailable course enrolment, resolve the underlying
account/course issue and retry the purchase. Do not charge the buyer again.

## Validation

Run the guest checkout PHPUnit tests, PHP syntax checks, React build, Moodle CLI upgrade and cache purge.
Before production enablement, complete a sandbox purchase for each enabled provider and verify the payment, account email, enrolment,
repeated webhook delivery, cancellation, and existing-account claim flow.

### Multi-gateway verification — 18 November 2026

- The isolated Moodle test environment includes clean-install coverage for the added provider fields and privacy declarations.
- The full guest checkout/password regression suite passed: **51 tests, 371 assertions**; five JavaScript submission/race tests passed.
- Chrome displayed all five configured methods on desktop and at 390px mobile width without horizontal overflow.
- Regression tests cover hosted initiation, provider amount/reference/currency checks, PayPal approval/capture,
  repeat fulfilment, gateway availability, manual receipt approval, welcome emails and password setup.
- Provider responses are simulated in automated tests. Real sandbox purchases and email delivery still need
  verification for each enabled provider against the deployment environment before enabling this feature for customers.
- The normal local site's official CLI upgrade was attempted but stopped on an unreadable
  `theme/boost_union/db/access.php`. The official language-cache purge succeeded.
  The unrelated theme permissions must be resolved before completing the upgrade on that site.

After deploying the plugin files, run the official commands from the Moodle repository root:

```powershell
C:\xampp\php\php.exe admin/cli/upgrade.php --non-interactive
C:\xampp\php\php.exe admin/cli/purge_caches.php
```

Then configure the enabled gateway webhooks, complete sandbox purchases, and enable guest checkout through the Checkout
settings tab. This implementation does not enable the feature or deploy files to the hosted site automatically.
