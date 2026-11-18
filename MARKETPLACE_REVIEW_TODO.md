# Moodle Plugins Directory Review TODO

Plugin: `local_moderncommerce`

This file records reviewer feedback as it is received. Items marked as having a
fix present must still pass the consolidated whole-plugin audit after all
feedback batches have been supplied.

## Batch 5

- [ ] **BLOCKER — licensing/IP:** include a package-level GPL `LICENSE` file in
      the plugin root and in the submitted ZIP.
  - Reviewer result: the submitted package had no root `LICENSE`.
  - Current checkout: `LICENSE` exists and was added by review-remediation
    commit `50aa694`; content and ZIP inclusion still need verification.

- [ ] **HIGH — external-system privacy blocker:** declare all external API
      destinations and exported personal fields with
      `add_external_location_link()`.
  - Reviewer citations include notification HTTP delivery, Paystack,
    Flutterwave, Stripe, PayPal, and recurring-payment integrations.
  - Current checkout: the provider contains multiple external-location
    declarations added across commits `50aa694` and `caf7aa2`; final outbound
    call inventory and metadata/string audit pending.

- [ ] **HIGH — Privacy API blocker:** declare, export, and delete all personal
      data stored across plugin tables.
  - Reviewer examples: audit-log actor/subject users, billing profiles, and
    other commerce tables containing user references.
  - Current checkout: the provider now declares and processes billing profiles,
    orders, payment records, webhook records, audit logs, and related tables;
    final `install.xml` versus provider reconciliation and privacy tests pending.

- [ ] **MEDIUM:** migrate legacy JavaScript into Moodle AMD ES6 modules and
      ship synchronized build artifacts.
  - Reviewer citations: `javascript/course_advanced_features.js`,
    `javascript/bundle_advanced_features.js`, and the non-AMD React build tree.
  - Current checkout: both cited legacy files were removed in `50aa694`; the AMD
    modules and build outputs remain to be checked comprehensively. The custom
    React/ESM runtime requires a separate compatibility and packaging decision,
    not an assumption that minified ESM is Moodle AMD.

- [ ] **MEDIUM:** replace hard-coded user-facing source strings with Moodle
      language strings and move inline JavaScript into a module.
  - Reviewer citations: `icons_bootstrap.php`, `privacy.php`, and `terms.php`.
  - Current checkout: commit `50aa694` moved the icon-browser script to AMD and
    replaced the cited page titles and icon-browser text with `get_string()`;
    final whole-plugin language scan pending.

- [ ] **MEDIUM:** replace direct `curl_init()` calls with Moodle's `curl`
      wrapper.
  - Reviewer citations: Paystack, Flutterwave, and Stripe gateways.
  - Current checkout: no direct `curl_init()` call was found in the plugin;
    final outbound HTTP behavior and regression tests pending.

- [ ] **MEDIUM:** add complete Moodle GPL boilerplate, package, copyright, and
      license markers to every PHP, AMD source/build, CSS, and Mustache file.
  - Reviewer citations: `amd/src/components_demo.js`, `catalog.js`, and
    `floating_notifications.js`.
  - Current checkout: the cited AMD sources now contain Moodle license headers;
    final source/build/template/style sweep pending.

- [ ] **MEDIUM:** rely on Moodle autoloading for plugin classes instead of
      manually including files from `classes/`.
  - Reviewer citations: `classes/demo/data_manager.php` and payment gateways
    manually loading service/API classes.
  - Current checkout: no matching manual class includes were found; final
    autoloading scan pending.

- [ ] Fix the Moodle Plugin CI upgrade-savepoint failure.
  - Reviewer result: upgrade block `2026061005` lacked a matching savepoint.
  - Current checkout: a `2026061005` savepoint is present, added by `50aa694`;
    the full savepoints check remains pending.

- [ ] **BLOCKER — issue tracker:** publish and submit a bug-tracker URL that
      users can access and use to report issues.
  - Submitted URL:
    `https://github.com/adebareshowemimo/moodle-local_coursecommercepro/issues`
    still returns HTTP 404.
  - Current repository tracker:
    `https://github.com/adebareshowemimo/moodle-local_moderncommerce/issues` is
    publicly readable, but GitHub reports that new issue creation is restricted.
  - Status: external blocker remains until users can create issues and the
    Moodle Plugins Directory listing points to the verified URL.

- [ ] **MEDIUM:** migrate remaining standalone `ajax/*.php` operations and
      jQuery `$.ajax()` calls to registered External Services and `core/ajax`.
  - Reviewer citations: catalog cart operations and coupon validation.
  - Current checkout: review-remediation commits added corresponding External
    Services, removed the legacy endpoints, and converted the cited AMD modules
    to `core/ajax`; full endpoint/service security scan and tests pending.

- [ ] Fix reports that use MySQL-specific SQL so they work on PostgreSQL.
  - Reviewer text is truncated after this finding's title; affected report/file
    excerpts were not supplied.
  - Current checkout: commit `50aa694` replaced MySQL date aggregation in
    `classes/services/report_service.php` with portable record retrieval and
    PHP-side bucketing. Final cross-database SQL scan and PostgreSQL test
    evidence remain pending.

## Existing working-tree protection

- [ ] Preserve the extensive unrelated uncommitted ModernCommerce feature,
      documentation, schema, React/ESM, service, template, style, and test
      changes during remediation and validation.

## Consolidated verification

- [ ] Complete the recurring Moodle Marketplace readiness audit after the final
      feedback batch is received.
- [ ] Reconcile every `install.xml` user-related field with Privacy API metadata,
      export, and deletion behavior.
- [ ] Inventory every outbound destination and exported field.
- [ ] Recheck all source/build pairs, AJAX/External Services, language strings,
      boilerplates, autoloading, cURL usage, savepoints, and portable SQL.
- [ ] Run focused privacy, permission, payment HTTP, report, PostgreSQL,
      savepoint, JavaScript build, and packaging checks and record evidence.
- [ ] Verify the release ZIP includes the license and all generated artifacts.
- [ ] Produce a final ready / ready with external actions / not ready verdict.

## Release 2.5.0 verification — 2026-11-18

This release task publishes the GitHub release and installable ZIP. It does not
submit or approve a Moodle Plugins Directory listing. Directory readiness remains
**not ready** until the outstanding review checklist is resolved. Heuristic scan
candidates below are not automatically confirmed security or performance defects.

| Priority | Finding / files | Required outcome | Status and evidence |
| --- | --- | --- | --- |
| P1 | Vulnerable transitive HTTP dependency; composer.lock, thirdpartylibs.xml | Upgrade within supported constraints and synchronize attribution | Fixed: symfony/http-foundation 7.4.23; Composer audit reports no advisories |
| P1 | Incomplete guest/program/storefront privacy coverage; provider.php | Declare fields, export linked purchases and progress, remove user data and anonymise page authors | Fixed locally; focused regression tests cover metadata, discovery, export, erasure and another user's isolation |
| P2 | Missing store-page cache language string and string ordering | Define cachedef_storepages and retain complete literal assignments | Fixed; string audit and PHP syntax pass |
| P2 | Recovery button classes, PHP documentation and source headers | Use the plugin design system and Moodle coding standards | Fixed; strict whole-plugin PHPCS and PHP lint pass |
| P2 | Release test assumes a root-only Moodle URL | Compare against Moodle's generated URL including any installation subdirectory | Corrected and verified in the isolated subdirectory installation |
| P1 | Version synchronization; version.php, README.md, release notes | Build 2026111801 / release 2.5.0 must match commit, remote, tag and ZIP | Source metadata synchronized; publication gate verified separately before upload |
| P2 | Main local upgrade blocked by unrelated theme files | Run the official CLI, do not change installed version records manually | Official CLI attempted; Boost Union db/access.php permission failure remains an environment blocker |

Validation commands: strict scripts/git-check.ps1 -Fast -SkipBuild -SkipPhpUnit;
node scripts/check-docs.mjs; node styles/tools/build-design-system.mjs --check;
Moodle Plugin CI validate/savepoints; Moodle Grunt AMD; isolated Moodle PHPUnit.
The React/ESM build was rebuilt during the guest checkout work and its source has
not changed since that successful build.

The full isolated run exercised 101 tests: 100 passed and one root-only URL fixture
failed. After correcting that fixture and adding the privacy regression, the final
focused run passed all 9 tests / 570 assertions (release URL, guest metadata,
privacy export/erasure, and widget contracts). No PHPUnit deprecations remained
in that focused run.

### Deterministic scan candidate ledger

The scan produced 164 candidates before the two missing privacy-table declarations
were fixed. The existing reviewer batches above remain open unless explicitly
supported by runtime and listing evidence. Generated icon-browser attribution is
verified in its officially rebuilt source map; it must not be hand-edited into
minified output. Remaining candidates require the dedicated Directory audit.

- [ ] **P1 PARAM_RAW_INPUT** `checkout.php:66` — Review the accepted input and use a narrower type where compatible. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_ENDPOINT** `storefront_upload.php` — Review the multipart file-upload contract, capabilities and session-key protection. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `cli/seed_sample_data.php:363` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `cli/seed_sample_data.php:427` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_INPUT** `contact/ajax_submit.php:48` — Review the accepted input and use a narrower type where compatible. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_INPUT** `contact/ajax_submit.php:50` — Review the accepted input and use a narrower type where compatible. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `db/upgrade.php:1490` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/api/bundle_enrollment_api.php:65` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/api/bundle_enrollment_api.php:332` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/api/coupon_api.php:203` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/demo/data_manager.php:242` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/demo/data_manager.php:255` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/observer/user_observer.php:155` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/observer/user_observer.php:205` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/observer/user_observer.php:215` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_INPUT** `classes/services/captcha_service.php:101` — Review the accepted input and use a narrower type where compatible. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/services/guest_checkout_service.php:485` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/services/key_redemption_service.php:308` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P2 RAW_DML_EXECUTE** `classes/task/expire_keys.php:49` — Review portability and whether atomic arithmetic requires this SQL statement. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/branding/save_branding.php:50` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/bundles/save_bundle.php:52` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/bundles/save_bundle_image.php:48` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/bundles/save_bundle_image.php:49` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/bundles/save_bundle_image.php:51` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/categories/save_category.php:52` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/contacts/submit.php:56` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/courses/save_course_features.php:55` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/emails/save_email.php:48` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/learner/save_profile.php:57` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/newsletter/subscribe.php:47` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/check_store_page_slug.php:47` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/get_storefront_gallery.php:54` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/get_storefront_page.php:50` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/list_widget_presets.php:47` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/save_storefront_widget.php:49` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/save_storefront_widget.php:50` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/save_storefront_widget.php:51` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/save_store_page.php:55` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/save_widget_preset.php:47` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/save_widget_preset.php:48` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/save_widget_preset.php:49` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/save_widget_preset.php:50` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/upload_slide_image.php:60` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P1 PARAM_RAW_SCHEMA_REVIEW** `classes/external/storefront/upload_slide_image.php:61` — Review payload type, semantic validation and capability/context boundaries. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/bundle_details.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/cart.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/checkout.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/company_licensing.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/email_templates_admin.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_app.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_app.js:2` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_bundles.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_calendar.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_certificates.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_courses.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_dashboard.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_grades.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_layout.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_library.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_order.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_orders.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_product_access.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_profile.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_subscription.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_subscription_access.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_success.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/learner_wishlist.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/redeem.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/settings_admin.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/storefront.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/storefront.js:3` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/storefront_gallery.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/storefront_gallery.js:3` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/subscriptions_admin.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/subscription_plans.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/storefront/newsletter.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [ ] **P2 LEGACY_AJAX_CLIENT** `js/esm/build/storefront/product_carousel.js:1` — Review native Moodle 5.2 ESM and registered External Service loading and permissions. Status: Directory review pending.
- [x] **P1 PRIVACY_TABLE_MISSING** `db/install.xml` — Declare, discover, export and erase/anonymise the table personal fields. Status: Fixed with regression coverage.
- [x] **P1 PRIVACY_TABLE_MISSING** `db/install.xml` — Declare, discover, export and erase/anonymise the table personal fields. Status: Fixed with regression coverage.
- [ ] **P2 DB_CALL_IN_LOOP** `bundle_details.php:136` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `course_details.php:172` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/seed_sample_data.php:572` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/seed_sample_data.php:594` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/seed_sample_data.php:676` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/seed_subscription_features.php:305` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/seed_subscription_features.php:387` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/seed_subscription_features.php:440` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/seed_subscription_features.php:441` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:270` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:271` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:296` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:300` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:323` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:401` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:407` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:408` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:473` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `cli/subscription_data.php:620` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:292` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:323` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:333` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:705` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:759` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:856` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:880` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:951` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:994` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:1120` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:1186` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:1219` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `db/upgrade.php:1441` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/api/bundle_api.php:177` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/api/bundle_api.php:294` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/api/cart_api.php:395` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/api/cart_api.php:431` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/api/order_api.php:427` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/demo/data_manager.php:246` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/demo/data_manager.php:1205` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/email/demo_seed.php:66` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/observer/enrollment_observer.php:64` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/observer/enrollment_observer.php:144` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/observer/user_observer.php:185` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/output/mycourses_page.php:86` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/output/mycourses_page.php:88` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/output/mycourses_page.php:119` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/output/orders_page.php:116` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/output/redeem_bundle_page.php:116` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/output/redeem_bundle_page.php:123` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/output/redeem_multiple_page.php:123` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/output/redeem_multiple_page.php:130` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/output/redeem_multiple_page.php:169` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/payment/gateway_manager.php:826` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/payment/gateway_manager.php:841` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/privacy/provider.php:506` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/privacy/provider.php:947` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/services/bundle_meta_service.php:706` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/services/category_service.php:474` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/services/guest_checkout_service.php:98` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/services/guest_checkout_service.php:277` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/services/key_redemption_service.php:265` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/services/order_validation_service.php:55` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/services/role_preset_service.php:238` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/services/role_preset_service.php:386` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/storefront/gallery_builder.php:1176` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/storefront/storepage_service.php:362` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/task/cancel_abandoned_orders.php:66` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/task/notify_daily_scan.php:340` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/task/recover_guest_checkouts.php:50` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/external/contacts/admin.php:542` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/external/enrollkeys/generate_keys.php:156` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/external/enrollkeys/generate_keys.php:308` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/external/redeem/search_redeemable_items.php:168` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/notifications/local/queue_manager.php:348` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/api/feature_api.php:149` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/api/plan_api.php:438` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/api/plan_api.php:625` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/services/access_service.php:61` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/services/access_service.php:140` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/services/notification_service.php:343` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/task/cleanup_old.php:139` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/task/process_recurring_payments.php:518` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/task/process_trials.php:94` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [ ] **P2 DB_CALL_IN_LOOP** `classes/subscription/task/sync_access.php:93` — Review bounds and query counts; batch only demonstrated redundant queries. Status: Directory review pending.
- [x] **P2 BOILERPLATE_MISSING** `amd/build/icon_browser.min.js` — Verify official builder provenance in the source and generated source map. Status: Verified official source-map provenance.
