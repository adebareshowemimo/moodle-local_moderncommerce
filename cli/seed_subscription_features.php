<?php
// This file is part of Moodle and is licensed under the
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
 * Seeds demo subscription plans and feature matrix data.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState -- Reusable CLI seed include.
if (!defined('CLI_SCRIPT')) {
    define('CLI_SCRIPT', true);
}
// phpcs:enable moodle.Files.MoodleInternal.MoodleInternalGlobalState

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/ddllib.php');

if (local_moderncommerce_seed_subscription_features_cli_should_run()) {
    local_moderncommerce_seed_subscription_features_cli_main();
}

/**
 * Whether this file is the active CLI entrypoint.
 *
 * @return bool
 */
function local_moderncommerce_seed_subscription_features_cli_should_run(): bool {
    return !defined('LOCAL_MODERNCOMMERCE_DEMO_DATA_INCLUDE');
}

/**
 * Seed subscription feature data from the CLI entrypoint.
 */
function local_moderncommerce_seed_subscription_features_cli_main(): void {
    [$options, $unrecognized] = cli_get_params(
        [
            'help' => false,
        ],
        [
            'h' => 'help',
        ]
    );

    if (!empty($unrecognized)) {
        $unrecognized = implode(PHP_EOL . '  ', $unrecognized);
        cli_error("Unknown options:\n  {$unrecognized}");
    }

    if (!empty($options['help'])) {
        echo "Seed Modern Commerce subscription feature matrix demo data.\n\n";
        echo "This creates or updates demo subscription plans, demo features, and their matrix mappings.\n";
        echo "It is idempotent and does not delete non-demo records.\n\n";
        echo "Example:\n";
        echo "  php public/local/moderncommerce/cli/seed_subscription_features.php\n";
        exit(0);
    }

    $result = local_moderncommerce_seed_subscription_feature_matrix();

    cli_heading('Modern Commerce subscription feature matrix seeded');
    echo 'Plans: ' . $result['plans'] . PHP_EOL;
    echo 'Features: ' . $result['features'] . PHP_EOL;
    echo 'Mappings enabled: ' . $result['mappings'] . PHP_EOL;
}

/**
 * Seed demo subscription feature matrix rows.
 *
 * @return array{plans: int, features: int, mappings: int}
 */
function local_moderncommerce_seed_subscription_feature_matrix(): array {
    global $DB;

    $manager = $DB->get_manager();
    $requiredtables = [
        'local_moderncommerce_subscription_plans',
        'local_moderncommerce_subscription_features',
        'local_moderncommerce_subscription_feature_map',
    ];
    foreach ($requiredtables as $table) {
        if (!$manager->table_exists(new xmldb_table($table))) {
            cli_error("Missing required table: {$table}");
        }
    }

    $now = time();
    $admin = get_admin();
    $userid = $admin ? (int) $admin->id : 0;

    $plans = [
        'demo_starter' => [
            'name' => 'Demo Starter',
            'description' => 'A lightweight plan for individual learners.',
            'billing_cycle' => 'monthly',
            'price' => 29,
            'trial_days' => 7,
            'grace_period_days' => 7,
            'max_seats' => 1,
            'sortorder' => 10,
            'featured' => 0,
        ],
        'demo_growth' => [
            'name' => 'Demo Growth',
            'description' => 'A fuller plan with projects, downloads, and instructor support.',
            'billing_cycle' => 'monthly',
            'price' => 79,
            'trial_days' => 14,
            'grace_period_days' => 7,
            'max_seats' => 5,
            'sortorder' => 20,
            'featured' => 1,
        ],
        'demo_enterprise' => [
            'name' => 'Demo Enterprise',
            'description' => 'A team plan with priority support and reporting.',
            'billing_cycle' => 'yearly',
            'price' => 499,
            'trial_days' => 30,
            'grace_period_days' => 14,
            'max_seats' => 25,
            'sortorder' => 30,
            'featured' => 0,
        ],
    ];

    $planids = [];
    foreach ($plans as $code => $plan) {
        $record = $DB->get_record('local_moderncommerce_subscription_plans', ['code' => $code]);
        $data = (object) [
            'name' => $plan['name'],
            'code' => $code,
            'description' => $plan['description'],
            'billing_cycle' => $plan['billing_cycle'],
            'price' => $plan['price'],
            'promo_price' => 0,
            'promo_end_date' => 0,
            'currency' => 'USD',
            'trial_days' => $plan['trial_days'],
            'grace_period_days' => $plan['grace_period_days'],
            'max_seats' => $plan['max_seats'],
            'sortorder' => $plan['sortorder'],
            'status' => 'active',
            'featured' => $plan['featured'],
            'timemodified' => $now,
            'createdby' => $userid,
        ];

        if ($record) {
            $data->id = (int) $record->id;
            $DB->update_record('local_moderncommerce_subscription_plans', $data);
            $planids[$code] = (int) $record->id;
            continue;
        }

        $data->timecreated = $now;
        $planids[$code] = (int) $DB->insert_record('local_moderncommerce_subscription_plans', $data);
    }

    $features = [
        'Course library' => [
            'description' => 'Access to the subscription course catalog.',
            'icon' => 'collection',
            'sortorder' => 10,
        ],
        'Certificates' => [
            'description' => 'Completion certificates for eligible courses.',
            'icon' => 'award',
            'sortorder' => 20,
        ],
        'Community access' => [
            'description' => 'Access to learner discussion spaces.',
            'icon' => 'people',
            'sortorder' => 30,
        ],
        'Downloadable resources' => [
            'description' => 'Templates, worksheets, and offline files.',
            'icon' => 'download',
            'sortorder' => 40,
        ],
        'Instructor Q&A' => [
            'description' => 'Ask questions and get instructor guidance.',
            'icon' => 'chat-dots',
            'sortorder' => 50,
        ],
        'Practice projects' => [
            'description' => 'Hands-on projects and graded practice work.',
            'icon' => 'clipboard-check',
            'sortorder' => 60,
        ],
        'Team seats' => [
            'description' => 'Seat management for teams and cohorts.',
            'icon' => 'people-fill',
            'sortorder' => 70,
        ],
        'Priority support' => [
            'description' => 'Faster support response for admins and learners.',
            'icon' => 'headset',
            'sortorder' => 80,
        ],
        'Analytics dashboard' => [
            'description' => 'Progress and usage reporting for team leads.',
            'icon' => 'graph-up-arrow',
            'sortorder' => 90,
        ],
    ];

    $featureids = [];
    foreach ($features as $name => $feature) {
        $record = $DB->get_record('local_moderncommerce_subscription_features', ['name' => $name]);
        $data = (object) [
            'name' => $name,
            'description' => $feature['description'],
            'icon' => $feature['icon'],
            'sortorder' => $feature['sortorder'],
            'status' => 'active',
            'timemodified' => $now,
        ];

        if ($record) {
            $data->id = (int) $record->id;
            $DB->update_record('local_moderncommerce_subscription_features', $data);
            $featureids[$name] = (int) $record->id;
            continue;
        }

        $data->timecreated = $now;
        $featureids[$name] = (int) $DB->insert_record('local_moderncommerce_subscription_features', $data);
    }

    $matrix = [
        'demo_starter' => [
            'Course library',
            'Certificates',
            'Community access',
        ],
        'demo_growth' => [
            'Course library',
            'Certificates',
            'Community access',
            'Downloadable resources',
            'Instructor Q&A',
            'Practice projects',
        ],
        'demo_enterprise' => array_keys($features),
    ];

    $enabled = 0;
    foreach ($planids as $code => $planid) {
        foreach ($featureids as $name => $featureid) {
            $shouldenable = in_array($name, $matrix[$code] ?? [], true);
            $params = ['planid' => $planid, 'featureid' => $featureid];

            if (!$shouldenable) {
                $DB->delete_records('local_moderncommerce_subscription_feature_map', $params);
                continue;
            }

            if (!$DB->record_exists('local_moderncommerce_subscription_feature_map', $params)) {
                $DB->insert_record('local_moderncommerce_subscription_feature_map', (object) [
                    'planid' => $planid,
                    'featureid' => $featureid,
                    'timecreated' => $now,
                ]);
            }
            $enabled++;
        }
    }

    return [
        'plans' => count($planids),
        'features' => count($featureids),
        'mappings' => $enabled,
    ];
}
