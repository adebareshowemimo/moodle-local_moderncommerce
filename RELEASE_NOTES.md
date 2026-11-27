# ModernCommerce release notes

## ModernCommerce 2.6.2

Moodle build `2026112700`. Supports Moodle 5.2 and 5.3 and requires PHP 8.3 or later.

- Adds Moodle 5.3 to the plugin compatibility declaration.
- Adds automated installation and PHPUnit coverage for Moodle 5.2 and 5.3.

## ModernCommerce 2.5.5

Moodle build: `2026111807`. Requires Moodle 5.2 and PHP 8.3 or later.

- Adds `/local/moderncommerce/set_password.php`, a branded page with password and
  confirmation fields. Welcome and renewal emails now point directly to this page.
- Reuses Moodle's native password form, validation hooks, password policy/history,
  manual authentication API and session-management APIs.
- Serialises saves per account, revalidates tokens before saving, and consumes all
  outstanding password tokens after a successful change. Invalid, expired, refunded,
  suspended and unrelated-account tokens cannot change a password through this page.
- Prevents switching a different signed-in account. Successful setup signs in the learner
  through Moodle and returns to My learning, with required-profile checks retained.
- Carries a separate renewal capability so expired password links can offer the dedicated
  email-only renewal page. Secrets are removed from the address bar before displaying forms.
- Existing valid Moodle password links for paid guest accounts forward to this page,
  ensuring they share the same password-saving flow. Other accounts retain the native page.

Install and run Moodle's official CLI upgrade to refresh the plugin and class caches.
Existing template placeholders continue to work; no template content replacement is required.

## ModernCommerce 2.5.4

Moodle build: `2026111806`. Requires Moodle 5.2 and PHP 8.3 or later.

- Updates the guest welcome email itself to show the recipient's username, explain the
  first-password redirect to **My learning**, and link returning learners to that page.
- Adds `{username}` and `{learning_url}` to the welcome template editor and safe previews.
- Upgrades unchanged original welcome content stored in the template table or outgoing-email
  settings, while preserving administrator-customised bodies, subjects and template selections.
- Password links still use Moodle's secure native password setup page; the dashboard redirect
  happens after saving the first password.
- Adds a dedicated **Request a new password link** page. A separate seven-day token identifies
  the paid guest account without asking for an email address; a protected POST sends a fresh
  password-setting link to its registered email. Renewal tokens rotate after delivery, are stored
  only as hashes, and cannot log anyone in or return password-setting tokens to the browser.
- Enforces a five-minute request cooldown, network/browser limits, account status checks,
  and expiry/refund checks. Recovery remains available if new guest checkout is disabled.

## ModernCommerce 2.5.3

Moodle build: `2026111804`. Requires Moodle 5.2 and PHP 8.3 or later.

- New guest purchasers use their normalized checkout email as their username.
- Adds accessible AJAX availability feedback before payment. Existing emails and usernames
  ask the buyer to sign in. Server validation repeats the check when JavaScript is unavailable.
- Protects public checks with a session key, feature/access checks, and per-browser/network limits.
- Handles late account collisions without assigning a purchase to another email's account;
  paid purchases remain recoverable.
- Directs paid guest purchasers to **My learning** after their first native Moodle password setup,
  replacing stale browser destinations such as an administrator page. Already emailed links also benefit.
- Existing accounts and subsequent password resets retain their usual behavior.

Run Moodle's official CLI upgrade to register the new AJAX service and cache definition.
Emails must satisfy Moodle's configured username character rules; existing usernames are not renamed.

## ModernCommerce 2.5.2

Moodle build: `2026111803`. Requires Moodle 5.2 and PHP 8.3 or later.

- New guest purchasers receive a welcome email confirming their learning account
  has been created and inviting them to set their password. The message uses the
  Moodle site name and the recipient's details rather than password-reset request wording.
- Adds **Guest account welcome** to the email template manager, including an editable
  subject, visual/HTML body, reusable content selection, and delivery switch.
- Retains Moodle's native expiring password-setting tokens. Preview links never
  contain a real token, and tokens bypass notification logs and queues.
- Installs the bundled template on install/upgrade without overwriting administrator edits.

Customise it under **Email Templates → Outgoing emails → Guest account welcome**.
Use `{set_password_url}` for the secure link and `{password_expiry_minutes}` for its configured validity.
Existing-account purchase claims retain their normal sign-in flow.

## ModernCommerce 2.5.1

Moodle build: `2026111802`. Requires Moodle 5.2 and PHP 8.3 or later.

- Removes the legacy `payment_method_types` parameter from guest and signed-in
  Stripe Checkout Session requests. Eligible payment methods now follow the Stripe Dashboard configuration.
- Acknowledges valid guest sessions while delayed payments remain pending,
  and handles `checkout.session.async_payment_succeeded` for signed-in orders.
  Accounts, enrolments, and paid order status still require confirmed payment.
- Preserves purchase totals, metadata, retry identity, and subscription mandate setup.

Include `checkout.session.async_payment_succeeded` in the Stripe webhook destination
when enabling delayed payment methods. No API key changes are required for this fix.

## ModernCommerce 2.5.0

Moodle build: `2026111801`. Requires Moodle 5.2 and PHP 8.3 or later.

- Adds optional Stripe guest checkout with private carts, payment verification,
  account creation after payment, course enrolment, and purchase recovery.
  Guest checkout is disabled by default. Existing account holders sign in to claim purchases.
- Matches the guest checkout layout to the cart, including a details sidebar and responsive order summary.
- Includes the key-redemption reliability and company-licensing integrations described below.
- Improves course and bundle metadata, learning-duration detection, catalog cards,
  product editing, and learner navigation.
- Removes an orphaned legacy key-expiry task during upgrade.
- Updates the PayPal SDK's HTTP dependency to resolve reported security advisories.
- Completes guest-purchase privacy metadata and improves release source validation.

Configure Stripe API keys and a webhook signing secret before enabling guest checkout.
The separately installed company-licensing add-on remains optional.

## ModernCommerce 2.4.1

ModernCommerce 2.4.1 adds a capability-aware return link to the fixed admin
sidebar footer. Administrators and managers who can view Moodle's Site
administration tree can return there without restoring the global Moodle
navbar. The compact sidebar retains an icon-only return control, and users
without `moodle/site:configview` do not see the link.

## ModernCommerce 2.4.0

ModernCommerce 2.4.0 adds optional B2B company licensing through the separately
installed `local_moderncompany` add-on. It includes exact-domain approval,
additive pooled seats and course coverage, manager and learner workspaces,
company licence checkout, lifecycle reconciliation, and multi-source-safe
entitlement grant and revocation APIs. Modern Commerce remains fully usable when
the add-on is absent; company routes and navigation are gated at runtime.

The add-on requires Moodle 5.2 and ModernCommerce 2.3.1 or later.

## ModernCommerce 2.3.1

ModernCommerce 2.3.1 is a reliability and security update for product and
bundle enrollment keys.

### Correct bundle usage accounting

A bundle redemption now creates exactly one key-usage record, regardless of
how many courses the bundle contains. The key's `usedcount`, global usage
limit, and per-user usage limit therefore measure redemptions consistently.
The upgrade collapses legacy per-course duplicate usage rows and retains the
highest trustworthy usage count.

### Atomic and concurrency-safe redemption

Key validation, course enrollment, entitlement creation, usage logging, and
counter advancement now run in one database transaction protected by a
per-key lock. Concurrent requests cannot both consume the final available use,
and a failure while enrolling a multi-course bundle rolls back the complete
redemption instead of leaving partial access or inconsistent counters.

### Complete key restriction enforcement

Enrollment keys now enforce their configured start date, expiry date, global
usage limit, per-user usage limit, required email address or `@domain`
restriction, enrollment duration, and role. Invalid configured roles are
rejected safely.

### Product and bundle provenance

Redemptions now retain the selected product ID in the usage record and carry
the appropriate product ID into each course entitlement. This preserves the
commercial origin of access for course products, bundles, and programs while
keeping per-course entitlement records available for fulfillment and audit.

### Legacy compatibility and upgrade repair

Existing direct-course key targets remain redeemable. During upgrade, direct
course targets are migrated to course products when exactly one matching
product exists; ambiguous or unmatched targets are preserved and handled by
the compatibility path. Existing usage counters are repaired upward when
legacy rows prove that a redemption occurred.

### Stronger generated keys

Generated enrollment keys now use 128 bits of cryptographically secure random
entropy and check for a collision before insertion. Codes remain uppercase and
retain the familiar `KEY-` prefix.

### Release automation

Release packaging now excludes video-authoring projects and their nested
JavaScript dependencies. This prevents Windows path-length and executable-copy
failures while keeping all Moodle runtime files and production Composer
dependencies in the ZIP.

### Validation

- Product and bundle key suite: 2 tests, 20 assertions.
- Complete ModernCommerce suite: 83 tests, 907 assertions.
- Moodle PHP coding-standard scan: passed for all plugin PHP source files.
- PHP syntax validation: passed for all plugin PHP source files.
- GitHub fast-check command: passed, including whitespace, string, Composer,
  security-audit, autoload, coding-standard, and syntax checks.
- Release packaging and ZIP structure validation: passed.

### Compatibility

- Moodle component: `local_moderncommerce`
- Release: `2.3.1`
- Moodle build number: `2026081900`
- Requires Moodle 5.2 or later.
- Tested against Moodle 5.2.
- Maturity: Stable.

## ModernCommerce 2.3.0

ModernCommerce 2.3.0 is a major feature release focused on multi-page
storefronts, richer storefront design tools, and more reliable recurring
subscription processing.

### Multi-page storefront management

Administrators can now create and manage multiple public storefront pages
instead of relying on a single fixed storefront.

This release adds:

- Store page creation, editing, listing, and deletion.
- Unique, validated page slugs for clean public URLs.
- Page status and visibility controls.
- Public page routing through the new `page.php` endpoint.
- Store page lifecycle events for creation, updates, and deletion.
- New external services for page-management operations.
- Persistent database storage for storefront page definitions.
- Upgrade support for existing ModernCommerce installations.

These changes make it possible to build dedicated landing pages, campaign
pages, product collections, subscription pages, and other targeted storefront
experiences.

### New storefront widgets

Three new storefront widget types are included.

#### Feature Grid

Displays product benefits, platform capabilities, or service features in a
structured grid. Each item can include an icon, heading, and supporting
description.

#### Leader Message

Provides a prominent editorial section for a founder, instructor, executive,
or community leader. It supports portrait imagery, attribution details, calls
to action, and configurable presentation styles.

#### Subscription Plans

Displays available subscription plans directly within the storefront. The
widget resolves live plan information and supports pricing, billing-cycle,
feature, badge, and call-to-action presentation options.

The PHP rendering layer, React storefront renderer, widget editor, gallery,
schema definitions, presets, and documentation were updated together for all
three widgets.

### Improved widget design system

The storefront editor now provides a broader and more consistent set of visual
controls.

Enhancements include:

- Expanded widget field schemas.
- Additional typography, color, spacing, radius, border, and alignment
  controls.
- Improved preset sanitization and preservation.
- A reusable icon picker.
- Better preset compatibility between PHP and React rendering.
- Updated storefront and administrative gallery styling.
- Improved responsive presentation across storefront surfaces.

Automated contract tests verify that supported widgets remain synchronized
across the widget registry, schema, gallery, presets, and rendering layers.

### Recurring subscription improvements

ModernCommerce 2.3.0 significantly strengthens recurring payment and
subscription lifecycle handling.

Changes include:

- Reusable payment-mandate support.
- Improved recurring-payment processing for Stripe and Paystack.
- Better handling of trial expiration and subscription renewal states.
- More reliable processing of expired subscriptions.
- Improved payment-observer behavior.
- Subscription lifecycle email notifications.
- Additional subscription API operations.
- A public subscription-plan entry point.
- New CLI tooling for inspecting and managing subscription data.

The implementation keeps financial reconciliation tasks controllable through
Moodle scheduled-task configuration.

### Storefront performance and caching

A new storefront cache definition reduces repeated work when resolving public
storefront content. Cache invalidation is connected to page-management
operations so updated pages can be presented without retaining stale
definitions.

### Database and service changes

This release includes database schema and Moodle service-definition changes.

The upgrade introduces storage and metadata required for:

- Multiple storefront pages.
- Store page slugs and page configuration.
- New page-management web services.
- New storefront widgets.
- Subscription processing improvements.
- Storefront cache definitions.

Existing installations must complete Moodle's normal plugin upgrade process
after replacing the plugin files.

### Developer and administrative tooling

The release also includes:

- Store page lifecycle events for integrations and observers.
- Expanded storefront documentation.
- Updated widget reference documentation.
- Subscription data CLI utilities.
- Widget contract PHPUnit tests.
- A React/PHP editor parity audit.
- Regenerated production JavaScript and CSS assets.
- Improved Moodle coding-standard compliance.

### Compatibility

- Moodle component: `local_moderncommerce`
- Release: `2.3.0`
- Moodle build number: `2026081806`
- Requires Moodle 5.2 or later.
- Tested against Moodle 5.2.
- Maturity: Stable.

### Upgrade instructions

1. Back up the Moodle database and dataroot.
2. Replace `local/moderncommerce` with the contents of the release ZIP.
3. Visit **Site administration > Notifications**, or run:

   ```bash
   php admin/cli/upgrade.php --non-interactive
   ```

4. Purge Moodle caches:

   ```bash
   php admin/cli/purge_caches.php
   ```

5. Review scheduled tasks and payment-gateway settings.
6. Verify existing storefront pages, subscriptions, checkout, and recurring
   payment behavior in a test environment.

### Validation

The release passed:

- Git whitespace validation.
- Moodle PHP coding-standard checks.
- PHP syntax validation.
- Composer metadata validation.
- Composer security auditing.
- Optimized autoloader generation.
- Generated design-system CSS verification.
- Widget editor parity auditing.
- Focused PHPUnit testing: 6 tests and 541 assertions.
- GitHub Actions checks and release packaging.
- Release ZIP structure and metadata validation.
- Post-upload asset checksum verification.

### Release artifact

- Commit: `a479edc70aa22fea89a746774240480702e1b9b1`
- Tag: `v2.3.0`
- ZIP: `moderncommerce-v2.3.0.zip`
- SHA-256: `7B5ABA9CC5E6C9FB01D315C15B22F024D3371D24ACB4FEF8DD7AAC6BD7DC6AC4`
- [GitHub release](https://github.com/adebareshowemimo/moodle-local_moderncommerce/releases/tag/v2.3.0)
- [Successful GitHub Actions run](https://github.com/adebareshowemimo/moodle-local_moderncommerce/actions/runs/32141933655)

No known release-blocking issues were identified.
