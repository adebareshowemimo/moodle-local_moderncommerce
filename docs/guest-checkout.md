# Guest checkout

## Implementation plan

1. Add an opt-in checkout setting, disabled by default.
2. Isolate anonymous carts by a random server-session token.
3. Provide a public, CSRF-protected cart and checkout form using existing storefront components.
4. Freeze items, prices, tax, and buyer details before creating a Stripe Checkout Session.
5. Verify signed payment notifications and retrieve the Checkout Session before fulfilment.
6. After payment, create a manual Moodle account for a new email. Existing accounts must sign in before claiming.
7. Create the normal commerce order from the frozen snapshot and reuse paid-order enrolment.
8. Retry incomplete fulfilment and email delivery through cron; expose recovery on the confirmation page.
9. Cover isolation, settings, verification, repeated events, account claiming, and retries with focused tests.

## Scope

The guest page uses the same blue header, cart rows, and order summary as the signed-in cart.
Buyer details occupy the left sidebar in place of account navigation. The summary's payment button targets
the native Moodle form in that sidebar, including its session key, validation, consent, and CAPTCHA when configured.
On narrower screens the details, items, and summary stack in reading order.

Stripe hosted payments for courses and bundles, using payment methods enabled in the Stripe Dashboard.
Delayed payment methods remain pending until Stripe confirms payment; no account or enrolment is created before that.
Guest coupons, programs, subscriptions, manual payments,
and zero-total purchases require signing in. Existing authenticated checkout remains available.

## Operation

Enable **Allow guest checkout** in Modern Commerce checkout settings. Stripe must be enabled with a signing secret.
Register `checkout.session.completed` and `checkout.session.async_payment_succeeded`
alongside the existing charge/refund events in Stripe's webhook destination.
Use Snapshot events from Your account; paste the destination's `whsec_` signing secret into the gateway settings.
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

Payment status is verified using Stripe's API as well as the signed event. Repeated events and concurrent attempts
are protected by Moodle locks. The browser confirmation page never establishes a login session.
New learners receive the **Guest account welcome** email, with the site name, account-creation confirmation,
and Moodle's expiring password-setting link. The subject and HTML content are editable in
**Email Templates → Outgoing emails → Guest account welcome**. The bundled template also appears in the template list.
Use `{set_password_url}` for the secure link and `{password_expiry_minutes}` for its validity period, which follows
Moodle's password-reset expiry setting. `{firstname}`, `{sitename}`, `{login_url}`, and `{forgot_password_url}` are also available.
Preview links never contain real password tokens. No temporary password is generated or sent.
During a paid guest account's first password setup, Modern Commerce replaces any stale login destination
with `/local/moderncommerce/learner/index.php`. This also works for previously emailed native Moodle links.
Moodle still validates the expiring token, password policy, login session, and required profile details.
Other users' password resets and later password resets retain their existing destinations.
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
Administrators with the commerce settings capability can inspect pending, paid, claim, fulfilled, expired,
and refunded attempts, retry processing, or resend account access. The report never displays payment secrets
or account claim tokens. Existing-account claims are completed by that account's owner through normal login.
For ambiguous email addresses, suspended/deleted users, or unavailable course enrolment, resolve the underlying
account/course issue and retry the purchase. Do not charge the buyer again.

## Validation

Run the guest checkout PHPUnit tests, PHP syntax checks, React build, Moodle CLI upgrade and cache purge.
Before production enablement, complete a Stripe test purchase and verify the payment, account email, enrolment,
repeated webhook delivery, cancellation, and existing-account claim flow.

### Implementation verification — 18 November 2026

- Isolated Moodle PHPUnit environment: **15 tests, 65 assertions passed**, including existing authenticated checkout.
- PHP syntax checks passed for the changed PHP files; React production build and documentation checks passed.
- Browser checks passed for anonymous storefront add/remove, cart navigation, required consent, unavailable payment
  recovery, desktop layout, and a 390px mobile layout.
- Stripe requests were simulated in automated tests. A real Stripe test purchase and email delivery still need
  verification against the deployment environment before enabling this feature for customers.
- The normal local site's official CLI upgrade was attempted but stopped on an unreadable
  `theme/boost_union/db/access.php`. Cache clearing also failed with “The override class does not exist”.
  These environment issues must be resolved before completing the upgrade on that site.

After deploying the plugin files, run the official commands from the Moodle repository root:

```powershell
C:\xampp\php\php.exe admin/cli/upgrade.php --non-interactive
C:\xampp\php\php.exe admin/cli/purge_caches.php
```

Then configure the Stripe webhook, complete a sandbox purchase, and enable guest checkout through the Checkout
settings tab. This implementation does not enable the feature or deploy files to the hosted site automatically.
