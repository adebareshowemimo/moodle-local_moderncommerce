# Storefront Pages and Widgets

The storefront is the public-facing Modern Commerce experience. It includes landing pages, catalog pages, detail pages, public content pages, and configurable widgets.

## Public Routes

- `/local/moderncommerce/index.php` - storefront/catalog entry
- `/local/moderncommerce/course_details.php?id=ID` - course detail
- `/local/moderncommerce/bundle_details.php?id=ID` - bundle/program detail
- `/local/moderncommerce/pricing.php` - Modern Commerce value/pricing page
- `/local/moderncommerce/about.php`
- `/local/moderncommerce/support.php`
- `/local/moderncommerce/privacy.php`
- `/local/moderncommerce/terms.php`
- `/local/moderncommerce/refund-policy.php`

## Course Detail Page

The course detail page uses a dedicated Modern Commerce layout:

- hero section with category/status badges, course title, summary, image, and quick course metrics
- main content column for overview, learning objectives, course outline, and review summary
- sticky purchase/sidebar column for price, add-to-cart/buy-now actions, product metadata, secure payment, and instant access indicators

The sidebar position is configurable for desktop screens from Modern Commerce settings. Set **Course detail sidebar position** to `Right` or `Left` depending on the storefront layout and reading flow. Mobile screens place the purchase/sidebar card before the main content so the purchase action is not pushed below long reviews or outlines.

## Spam Protection

Modern Commerce uses Moodle core reCAPTCHA v2 for public lead and support forms. The plugin does not store separate Google keys; it reads Moodle's global `$CFG->recaptchapublickey` and `$CFG->recaptchaprivatekey` values.

When both Moodle core keys are configured, these surfaces render and verify a Google reCAPTCHA challenge:

- `/local/moderncommerce/support.php`
- storefront `supportform` widgets
- storefront `newsletter` widgets
- contact and newsletter public web service submissions

When the keys are not configured, Modern Commerce leaves the challenge out and the forms continue to work. This keeps local development and private staging sites usable while allowing production sites to turn on spam protection centrally from Moodle administration.

Server-side validation is still required even when the widget renders in the browser. The public services accept the normal `g-recaptcha-response` field, and legacy AJAX callers may pass the same token as `recaptcharesponse`.

## Use the Storefront as the Moodle Home Page

Modern Commerce registers `/local/moderncommerce/index.php` as a selectable Moodle default homepage. Use this when the site should open directly into the commerce storefront instead of Moodle's standard front page.

Set it from Moodle administration:

1. Go to **Site administration**.
2. Search for **Default home page**.
3. Open the setting usually shown as **Default home page for users**.
4. Select **Modern Commerce storefront**.
5. Save changes.
6. Test the site root URL, for example `https://example.com/`, in both logged-out and logged-in sessions.

When this option is selected, Modern Commerce also redirects anonymous front-page requests from Moodle's root page to `/local/moderncommerce/index.php`. This keeps public visitors on the storefront even though Moodle core normally applies the default homepage setting more directly to logged-in users.

If the **Modern Commerce storefront** option is not visible after installing or upgrading the plugin, purge Moodle caches and reload the setting page. Do not edit Moodle core `index.php` or add a theme-level redirect for this; use the registered default homepage option so Moodle navigation and login redirects continue to behave predictably.

## Admin Routes

- `/local/moderncommerce/admin/pages.php` - storefront page records
- `/local/moderncommerce/admin/gallery.php` - widget gallery and presets
- `/local/moderncommerce/admin/branding.php` - design tokens and email shell
- `/local/moderncommerce/icons_bootstrap.php` - curated icon browser
- `/local/moderncommerce/styleguide.php` - design-system styleguide

## Store Pages Administration

Open **Modern Commerce > Store pages** or visit `/local/moderncommerce/admin/pages.php`. The route requires the system capability `local/moderncommerce:managestorefront`.

The page manages these buyer-facing routes:

| Page | Public route | Availability |
| --- | --- | --- |
| Catalog | `/local/moderncommerce/index.php` | Required; cannot be disabled |
| About | `/local/moderncommerce/about.php` | Optional |
| Support | `/local/moderncommerce/support.php` | Optional |
| Terms | `/local/moderncommerce/terms.php` | Optional |
| Privacy | `/local/moderncommerce/privacy.php` | Optional |
| Refund policy | `/local/moderncommerce/refund-policy.php` | Optional |

Optional pages are enabled by default until a manager explicitly disables them. A disabled optional page returns Moodle's page-not-found response to ordinary visitors. A user with `local/moderncommerce:managestorefront` can still open it for review.

The actions on each row are:

- **Visibility switch**: enable or disable an optional page. The catalog is always required.
- **Manage widgets**: open the layout drawer for that page.
- **Preview**: open the public page.

The screen also links to `/local/moderncommerce/admin/global.php`, where global widgets are managed for all storefront pages. The page table displays each configured title and summary, but it does not directly edit those text values.

### Layout Drawer

The **Manage widgets** drawer loads the page's assigned widgets and applicable global widgets. Widgets are grouped by render zone. A manager can move a widget up or down within its zone, show or hide it, and save the revised order and visibility.

Saving re-sequences widgets inside each zone. It does not create a widget, move it to another zone, or edit widget content. Use storefront edit mode or the widget gallery for those operations.

If no widgets are assigned, seed the standard defaults with:

```bash
php local/moderncommerce/cli/demo_data.php --install-defaults
```

Before enabling an optional page publicly, preview it on desktop and mobile, verify global elements, test links and forms, and confirm policy or contact content is complete.

## Widget System

Storefront widgets are stored in:

- `local_moderncommerce_widget`
- `local_moderncommerce_widget_slide`
- `local_moderncommerce_widget_preset`

Use presets for reusable styling. Use widgets for page placement and content. Keep generated CSS in SCSS bundles; do not add page-level CSS includes.

## Default Storefront Seed

Seed defaults:

```bash
php local/moderncommerce/cli/demo_data.php --install-defaults
```

Reset and seed storefront widgets only:

```bash
php local/moderncommerce/cli/seed_storefront.php --reset
```

## CSS Architecture

Modern Commerce styles are centralized:

- core design system: `styles/design-system.css`
- route bundles: `styles/bundles/*.css`
- Moodle auto-loaded root CSS: `styles.css`
- SCSS source: `styles/scss/`

Minification should happen during packaging, not during active design work.
