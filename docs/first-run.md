# First Run and Demo Data

Modern Commerce has one primary CLI for installing defaults, seeding demo data, auditing table coverage, refreshing demo data, and resetting to empty.

Run commands from the Moodle root.

## Install Defaults

Use this on a new site before real configuration:

```bash
php local/moderncommerce/cli/demo_data.php --install-defaults
```

This seeds safe defaults such as storefront widgets, email templates, and gateway records.

## Full Demo Seed

Use this on development or staging sites:

```bash
php local/moderncommerce/cli/demo_data.php --seed
```

Optional counts:

```bash
php local/moderncommerce/cli/demo_data.php --seed --categories=12 --courses=25 --orders=120 --coupons=12 --keys=24 --reviews=4
```

The demo seed can create Moodle course categories, Moodle courses, Modern Commerce products, bundles, coupons, enrolment keys, reviews, orders, subscription plans, feature mappings, storefront content, and supporting lifecycle records.

## Audit Data Coverage

```bash
php local/moderncommerce/cli/demo_data.php --audit
```

Use this after install or after demo seeding to see which Modern Commerce tables have data.

## Refresh Demo Data

This deletes existing Modern Commerce table data and seeded Moodle demo courses, then seeds again:

```bash
php local/moderncommerce/cli/demo_data.php --refresh --yes
```

Use only on development or staging sites.

## Reset to Empty

This clears Modern Commerce table data and seeded Moodle demo courses:

```bash
php local/moderncommerce/cli/demo_data.php --reset-empty --yes
```

Do not run this against production unless you deliberately want to remove Modern Commerce data.

## Related Seed Commands

The unified command above is preferred. These scripts remain useful for targeted development work:

- `php local/moderncommerce/cli/seed_storefront.php`
- `php local/moderncommerce/cli/seed_storefront.php --reset`
- `php local/moderncommerce/cli/seed_subscription_features.php`
- `php local/moderncommerce/cli/test_emails.php --userid=ID`
