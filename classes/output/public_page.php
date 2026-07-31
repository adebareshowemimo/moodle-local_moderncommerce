<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Public buyer-facing page renderer for Modern Commerce.
 *
 * @package    local_moderncommerce
 * @copyright  2025 Adebare Showemimo | adebareshowemimo@gmail.com | support@agunfoninteractivity.com | www.agunfoninteractivity.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_moderncommerce\output;

use html_writer;
use local_moderncommerce\persistent\widget;
use local_moderncommerce\storefront\zones;
use moodle_url;

/**
 * Renders shared public ecommerce pages.
 */
class public_page {
    /** @var string Plugin component name. */
    private const COMPONENT = 'local_moderncommerce';

    /**
     * Get supported editable public pages.
     *
     * @return array<string, array>
     */
    public static function page_catalog(): array {
        return [
            'catalog' => [
                'title' => get_string('p1_publicpage_catalog_title', self::COMPONENT),
                'summary' => get_string('p1_publicpage_catalog_summary', self::COMPONENT),
                'icon' => 'bi-shop',
                'route' => '/local/moderncommerce/index.php',
            ],
            'about' => [
                'title' => get_string('p1_publicpage_about_title', self::COMPONENT),
                'summary' => get_string('p1_publicpage_about_summary', self::COMPONENT),
                'icon' => 'bi-patch-check',
                'route' => '/local/moderncommerce/about.php',
            ],
            'support' => [
                'title' => get_string('p1_publicpage_support_title', self::COMPONENT),
                'summary' => get_string('p1_publicpage_support_summary', self::COMPONENT),
                'icon' => 'bi-life-preserver',
                'route' => '/local/moderncommerce/support.php',
            ],
            'terms' => [
                'title' => get_string('p1_publicpage_terms_title', self::COMPONENT),
                'summary' => get_string('p1_publicpage_terms_summary', self::COMPONENT),
                'icon' => 'bi-file-text',
                'route' => '/local/moderncommerce/terms.php',
            ],
            'privacy' => [
                'title' => get_string('p1_publicpage_privacy_title', self::COMPONENT),
                'summary' => get_string('p1_publicpage_privacy_summary', self::COMPONENT),
                'icon' => 'bi-shield-lock',
                'route' => '/local/moderncommerce/privacy.php',
            ],
            'refund-policy' => [
                'title' => get_string('p1_publicpage_refund_title', self::COMPONENT),
                'summary' => get_string('p1_publicpage_refund_summary', self::COMPONENT),
                'icon' => 'bi-arrow-counterclockwise',
                'route' => '/local/moderncommerce/refund-policy.php',
            ],
        ];
    }

    /**
     * Whether a catalog entry can be hidden from public visitors.
     *
     * The storefront catalog is the commerce entry point and remains required.
     *
     * @param string $key Page key.
     * @return bool
     */
    public static function can_disable(string $key): bool {
        $catalog = self::page_catalog();

        return isset($catalog[$key]) && $key !== 'catalog';
    }

    /**
     * Whether a public page is enabled for non-manager visitors.
     *
     * @param string $key Page key.
     * @return bool
     */
    public static function is_enabled(string $key): bool {
        $catalog = self::page_catalog();
        if (!isset($catalog[$key])) {
            return false;
        }
        if (!self::can_disable($key)) {
            return true;
        }

        $value = get_config(self::COMPONENT, self::config_name($key, 'enabled'));

        return $value === false || $value === null || $value === '' ? true : (bool) (int) $value;
    }

    /**
     * Require a public page to be enabled unless the current user can manage storefront pages.
     *
     * @param string $key Page key.
     */
    public static function require_enabled(string $key): void {
        if (self::is_enabled($key)) {
            return;
        }

        if (has_capability('local/moderncommerce:managestorefront', \context_system::instance())) {
            return;
        }

        throw new \moodle_exception('pagenotfound', 'error');
    }

    /**
     * Render a public content page.
     *
     * @param string $key Page key.
     * @param object $output Moodle renderer.
     * @param array $options Extra rendering options.
     * @return string
     */
    public static function render(string $key, $output, array $options = []): string {
        global $CFG;

        $catalog = self::page_catalog();
        $page = $catalog[$key] ?? $catalog['about'];
        $title = self::setting($key, 'title', $page['title']);
        $summary = self::setting($key, 'summary', $page['summary']);
        $body = trim(self::setting($key, 'body', ''));
        $sections = $options['sections'] ?? self::default_sections($key);
        $actions = $options['actions'] ?? self::default_actions($key);

        $html = html_writer::start_div('mc-public-page');
        if (empty($options['skipbreadcrumbs'])) {
            $html .= self::breadcrumbs($title);
        }
        $html .= html_writer::start_div('mc-public-hero');
        $html .= html_writer::span(
            html_writer::tag('i', '', ['class' => 'bi ' . $page['icon'], 'aria-hidden' => 'true']),
            'mc-public-hero__icon'
        );
        $html .= html_writer::tag('span', get_string('pluginname', self::COMPONENT), ['class' => 'mc-public-hero__eyebrow']);
        $html .= html_writer::tag('h1', format_string($title), ['class' => 'mc-public-hero__title']);
        $html .= html_writer::tag('p', format_text($summary, FORMAT_PLAIN), ['class' => 'mc-public-hero__summary']);
        if (!empty($actions)) {
            $html .= html_writer::start_div('mc-public-hero__actions');
            foreach ($actions as $action) {
                $primary = !empty($action['primary']);
                $html .= html_writer::link($action['url'], $action['label'], [
                    'class' => $primary ? 'mc-button btn-mc-primary' : 'mc-button mc-btn-soft',
                    'data-mc-button' => $primary ? 'primary' : 'soft',
                ]);
            }
            $html .= html_writer::end_div();
        }
        $html .= html_writer::end_div();

        if ($body !== '') {
            $html .= html_writer::div(format_text($body, FORMAT_HTML, ['trusted' => true]), 'mc-public-card mc-public-richtext');
        } else {
            $html .= self::render_sections($sections);
        }

        $html .= html_writer::end_div();

        return $html;
    }

    /**
     * Render a public page using the storefront widget builder.
     *
     * @param string $key Page key.
     * @param object $output Moodle renderer.
     * @param array $options Extra options.
     * @return string
     */
    public static function render_widget_page(string $key, $output, array $options = []): string {
        global $PAGE;

        $catalog = self::page_catalog();
        if (!isset($catalog[$key])) {
            $key = 'about';
        }

        $editing = $PAGE->user_is_editing();
        $canmanage = has_capability('local/moderncommerce:managestorefront', \context_system::instance());

        $toolbarid = 'moderncommerce-public-page-' . clean_param($key, PARAM_ALPHANUMEXT) . '-toolbar';

        // Site-wide global bands wrap every page (above and below its own content).
        $globaltop = self::render_global_band(zones::GLOBAL_TOP, $output, $key);
        $globalbottom = self::render_global_band(zones::GLOBAL_BOTTOM, $output, $key);
        $hasglobalbreadcrumb = self::has_global_breadcrumb();

        $haswidgets = widget::record_exists_select('pagetype = ?', [$key]);
        if (!$haswidgets && !$editing) {
            $options['skipbreadcrumbs'] = $hasglobalbreadcrumb;
            return $globaltop . self::render($key, $output, $options) . $globalbottom;
        }

        $page = $catalog[$key];
        $title = self::setting($key, 'title', $page['title']);

        $html = ($editing && $canmanage) ? html_writer::div('', 'mc-sf-toolbar-host', ['id' => $toolbarid]) : '';
        $html .= $globaltop;
        $html .= html_writer::start_div('mc-public-page mc-public-page--widgets');
        if (!$hasglobalbreadcrumb) {
            $html .= self::breadcrumbs($title);
        }
        $html .= self::render_widget_mount($key, $output, [
            'editing' => $editing,
            'canmanage' => $canmanage,
            'title' => $title,
            'region' => 'moderncommerce-public-page-' . $key,
            'context' => ['hostpage' => $key],
            'toolbarTargetId' => ($editing && $canmanage) ? $toolbarid : '',
        ]);
        $html .= html_writer::end_div();
        $html .= $globalbottom;

        return $html;
    }

    /**
     * Render a widget designer/preview mount for a Store Page.
     *
     * @param string $key Page key.
     * @param object $output Moodle renderer.
     * @param array $options Extra options.
     * @return string
     */
    public static function render_widget_mount(string $key, $output, array $options = []): string {
        self::ensure_lib_loaded();

        $catalog = self::page_catalog();
        // The 'global' (site-wide) scope is not a navigable page, so it is not in the
        // catalog - fall back to a generic descriptor and allow an icon/title override.
        $page = $catalog[$key] ?? ['title' => ucfirst($key), 'icon' => 'bi-globe2'];
        $title = $options['title'] ?? self::setting($key, 'title', $page['title']);
        $icon = $options['icon'] ?? $page['icon'];
        $region = $options['region'] ?? 'moderncommerce-store-page-' . $key;
        $editing = !empty($options['editing']);
        $canmanage = !empty($options['canmanage']);
        $onlyzone = (string)($options['onlyzone'] ?? '');
        $toolbartargetid = (string)($options['toolbarTargetId'] ?? '');
        $rendercontext = $options['context'] ?? [];
        if (!is_array($rendercontext)) {
            $rendercontext = [];
        }

        $reactconfig = json_encode([
            'component' => '@moodle/lms/local_moderncommerce/storefront',
            'id' => clean_param($region, PARAM_ALPHANUMEXT) . '-app',
            'class' => 'local-moderncommerce-storefront local-moderncommerce-storefront--' . $key,
            'props' => [
                'pageType' => $key,
                'onlyZone' => $onlyzone,
                'renderContext' => $rendercontext,
                'getPageMethod' => 'local_moderncommerce_get_storefront_page',
                'layoutGetMethod' => 'local_moderncommerce_get_storefront_layout',
                'layoutSaveMethod' => 'local_moderncommerce_save_storefront_layout',
                'widgetGetMethod' => 'local_moderncommerce_get_storefront_widget',
                'widgetSaveMethod' => 'local_moderncommerce_save_storefront_widget',
                'presetListMethod' => 'local_moderncommerce_list_widget_presets',
                'addMethod' => 'local_moderncommerce_add_storefront_widget',
                'deleteMethod' => 'local_moderncommerce_delete_storefront_widget',
                'uploadMethod' => 'local_moderncommerce_upload_slide_image',
                'videoUploadUrl' => (new moodle_url('/local/moderncommerce/storefront_upload.php'))->out(false),
                'iconOptions' => local_moderncommerce_icon_options(),
                'editing' => $editing,
                'canManage' => $canmanage,
                'showToolbar' => empty($options['hidetoolbar']),
                'toolbarTargetId' => $toolbartargetid,
                'catalogLabels' => self::catalog_labels(),
                'labels' => self::react_labels($title),
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return $output->render_from_template('local_moderncommerce/react_mount', [
            'region' => $region,
            'reactconfig' => $reactconfig,
            'icon' => $icon,
            // Global bands (footer/top) suppress the loading placeholder so the page
            // shows a single spinner (the main content mount), not two.
            'quiet' => !empty($options['quiet']),
        ]);
    }

    /**
     * Render a site-wide widget band for a single global zone.
     *
     * Global widgets (pagetype 'global') render on EVERY storefront page via these bands
     * rather than being stored per page. The band stays display-only for visitors, but
     * shows the inline edit affordance when a manager has Moodle edit mode enabled.
     * Returns '' when the zone holds no enabled widget so pages without global content
     * emit no empty mount.
     *
     * @param string $zone Global zone slug (zones::GLOBAL_TOP or zones::GLOBAL_BOTTOM).
     * @param object $output Moodle renderer.
     * @param string $hostpage Storefront page currently hosting this global band.
     * @return string
     */
    public static function render_global_band(string $zone, $output, string $hostpage = ''): string {
        global $PAGE;

        if (!widget::record_exists_select('pagetype = ? AND zone = ? AND enabled = 1', [zones::PAGE_GLOBAL, $zone])) {
            return '';
        }

        if ($hostpage === '') {
            $hostpage = zones::PAGE_GLOBAL;
        }

        $editing = $PAGE->user_is_editing();
        $canmanage = has_capability('local/moderncommerce:managestorefront', \context_system::instance());

        return self::render_widget_mount(zones::PAGE_GLOBAL, $output, [
            'onlyzone' => $zone,
            'editing' => $editing,
            'canmanage' => $canmanage,
            'hidetoolbar' => true,
            'title' => '',
            'icon' => 'bi-globe2',
            'region' => 'moderncommerce-global-' . $zone,
            'context' => ['hostpage' => $hostpage],
            // No loading spinner for the below-the-fold global band.
            'quiet' => true,
        ]);
    }

    /**
     * Whether the site has an enabled global breadcrumb widget.
     *
     * @return bool
     */
    private static function has_global_breadcrumb(): bool {
        return widget::record_exists_select(
            'pagetype = ? AND zone = ? AND type = ? AND enabled = 1',
            [zones::PAGE_GLOBAL, zones::GLOBAL_TOP, 'breadcrumb']
        );
    }

    /**
     * Render a content-card section list.
     *
     * @param array $sections Sections.
     * @return string
     */
    public static function render_sections(array $sections): string {
        $html = html_writer::start_div('mc-public-section-grid');
        foreach ($sections as $section) {
            $html .= html_writer::start_div('mc-public-card');
            if (!empty($section['icon'])) {
                $html .= html_writer::span(
                    html_writer::tag('i', '', ['class' => 'bi ' . $section['icon'], 'aria-hidden' => 'true']),
                    'mc-public-card__icon'
                );
            }
            $html .= html_writer::tag('h2', format_string($section['title']), ['class' => 'mc-public-card__title']);
            if (!empty($section['text'])) {
                $html .= html_writer::tag('p', format_text($section['text'], FORMAT_PLAIN), ['class' => 'mc-public-card__text']);
            }
            if (!empty($section['items'])) {
                $html .= html_writer::start_tag('ul', ['class' => 'mc-public-list']);
                foreach ($section['items'] as $item) {
                    $html .= html_writer::tag('li', format_text($item, FORMAT_PLAIN));
                }
                $html .= html_writer::end_tag('ul');
            }
            $html .= html_writer::end_div();
        }
        $html .= html_writer::end_div();

        return $html;
    }

    /**
     * Default content sections.
     *
     * @param string $key Page key.
     * @return array
     */
    public static function default_sections(string $key): array {
        switch ($key) {
            case 'support':
                return [
                    [
                        'title' => get_string('p1_publicpage_support_help_title', self::COMPONENT),
                        'icon' => 'bi-chat-dots',
                        'items' => [
                            get_string('p1_publicpage_support_help_payment', self::COMPONENT),
                            get_string('p1_publicpage_support_help_access', self::COMPONENT),
                            get_string('p1_publicpage_support_help_invoices', self::COMPONENT),
                            get_string('p1_publicpage_support_help_refunds', self::COMPONENT),
                        ],
                    ],
                    [
                        'title' => get_string('p1_publicpage_support_before_title', self::COMPONENT),
                        'icon' => 'bi-search',
                        'items' => [
                            get_string('p1_publicpage_support_before_order', self::COMPONENT),
                            get_string('p1_publicpage_support_before_email', self::COMPONENT),
                            get_string('p1_publicpage_support_before_product', self::COMPONENT),
                        ],
                    ],
                ];

            case 'terms':
                return [
                    [
                        'title' => get_string('p1_publicpage_terms_purchases_title', self::COMPONENT),
                        'icon' => 'bi-bag-check',
                        'items' => [
                            get_string('p1_publicpage_terms_purchases_products', self::COMPONENT),
                            get_string('p1_publicpage_terms_purchases_access', self::COMPONENT),
                            get_string('p1_publicpage_terms_purchases_rules', self::COMPONENT),
                        ],
                    ],
                    [
                        'title' => get_string('p1_publicpage_terms_accounts_title', self::COMPONENT),
                        'icon' => 'bi-person-check',
                        'items' => [
                            get_string('p1_publicpage_terms_accounts_secure', self::COMPONENT),
                            get_string('p1_publicpage_terms_accounts_sharing', self::COMPONENT),
                            get_string('p1_publicpage_terms_accounts_changes', self::COMPONENT),
                        ],
                    ],
                    [
                        'title' => get_string('p1_publicpage_terms_payments_title', self::COMPONENT),
                        'icon' => 'bi-credit-card',
                        'items' => [
                            get_string('p1_publicpage_terms_payments_prices', self::COMPONENT),
                            get_string('p1_publicpage_terms_payments_subscriptions', self::COMPONENT),
                            get_string('p1_publicpage_terms_payments_taxes', self::COMPONENT),
                        ],
                    ],
                ];

            case 'privacy':
                return [
                    [
                        'title' => get_string('p1_publicpage_privacy_data_title', self::COMPONENT),
                        'icon' => 'bi-database-lock',
                        'items' => [
                            get_string('p1_publicpage_privacy_data_profile', self::COMPONENT),
                            get_string('p1_publicpage_privacy_data_orders', self::COMPONENT),
                            get_string('p1_publicpage_privacy_data_learning', self::COMPONENT),
                        ],
                    ],
                    [
                        'title' => get_string('p1_publicpage_privacy_why_title', self::COMPONENT),
                        'icon' => 'bi-check2-circle',
                        'items' => [
                            get_string('p1_publicpage_privacy_why_orders', self::COMPONENT),
                            get_string('p1_publicpage_privacy_why_access', self::COMPONENT),
                            get_string('p1_publicpage_privacy_why_messages', self::COMPONENT),
                        ],
                    ],
                    [
                        'title' => get_string('p1_publicpage_privacy_processors_title', self::COMPONENT),
                        'icon' => 'bi-shield-check',
                        'text' => get_string('p1_publicpage_privacy_processors_text', self::COMPONENT),
                    ],
                ];

            case 'refund-policy':
                return [
                    [
                        'title' => get_string('p1_publicpage_refund_eligibility_title', self::COMPONENT),
                        'icon' => 'bi-arrow-counterclockwise',
                        'items' => [
                            get_string('p1_publicpage_refund_eligibility_policy', self::COMPONENT),
                            get_string('p1_publicpage_refund_eligibility_digital', self::COMPONENT),
                            get_string('p1_publicpage_refund_eligibility_subscriptions', self::COMPONENT),
                        ],
                    ],
                    [
                        'title' => get_string('p1_publicpage_refund_after_title', self::COMPONENT),
                        'icon' => 'bi-unlock',
                        'items' => [
                            get_string('p1_publicpage_refund_after_access', self::COMPONENT),
                            get_string('p1_publicpage_refund_after_enrolments', self::COMPONENT),
                            get_string('p1_publicpage_refund_after_timing', self::COMPONENT),
                        ],
                    ],
                    [
                        'title' => get_string('p1_publicpage_refund_process_title', self::COMPONENT),
                        'icon' => 'bi-life-preserver',
                        'text' => get_string('p1_publicpage_refund_process_text', self::COMPONENT),
                    ],
                ];

            case 'about':
            default:
                return [
                    [
                        'title' => get_string('p1_publicpage_about_built_title', self::COMPONENT),
                        'icon' => 'bi-mortarboard',
                        'text' => get_string('p1_publicpage_about_built_text', self::COMPONENT),
                    ],
                    [
                        'title' => get_string('p1_publicpage_about_why_title', self::COMPONENT),
                        'icon' => 'bi-patch-check',
                        'items' => [
                            get_string('p1_publicpage_about_why_checkout', self::COMPONENT),
                            get_string('p1_publicpage_about_why_enrolment', self::COMPONENT),
                            get_string('p1_publicpage_about_why_bundles', self::COMPONENT),
                            get_string('p1_publicpage_about_why_dashboard', self::COMPONENT),
                        ],
                    ],
                    [
                        'title' => get_string('p1_publicpage_about_promise_title', self::COMPONENT),
                        'icon' => 'bi-stars',
                        'text' => get_string('p1_publicpage_about_promise_text', self::COMPONENT),
                    ],
                ];
        }
    }

    /**
     * Default hero actions.
     *
     * @param string $key Page key.
     * @return array
     */
    private static function default_actions(string $key): array {
        $catalogurl = new moodle_url('/local/moderncommerce/index.php');
        $supporturl = new moodle_url('/local/moderncommerce/support.php');

        if ($key === 'support') {
            return [
                ['label' => get_string('p1_publicpage_browse_courses', self::COMPONENT), 'url' => $catalogurl, 'primary' => true],
            ];
        }

        return [
            ['label' => get_string('p1_publicpage_browse_courses', self::COMPONENT), 'url' => $catalogurl, 'primary' => true],
            ['label' => get_string('p1_publicpage_contact_support', self::COMPONENT), 'url' => $supporturl, 'primary' => false],
        ];
    }

    /**
     * Breadcrumbs.
     *
     * @param string $title Current page title.
     * @return string
     */
    private static function breadcrumbs(string $title): string {
        $items = [
            html_writer::link(new moodle_url('/local/moderncommerce/index.php'), get_string('catalog', self::COMPONENT)),
            format_string($title),
        ];

        return html_writer::tag(
            'nav',
            html_writer::alist($items, ['class' => 'breadcrumb']),
            ['aria-label' => get_string('p1_publicpage_breadcrumb', self::COMPONENT), 'class' => 'mc-public-breadcrumb']
        );
    }

    /**
     * Get editable setting.
     *
     * @param string $key Page key.
     * @param string $field Field.
     * @param string $default Default.
     * @return string
     */
    public static function setting(string $key, string $field, string $default = ''): string {
        $value = get_config(self::COMPONENT, self::config_name($key, $field));
        return $value === false || $value === null || $value === '' ? $default : (string)$value;
    }

    /**
     * Build config name.
     *
     * @param string $key Page key.
     * @param string $field Field.
     * @return string
     */
    public static function config_name(string $key, string $field): string {
        return 'publicpage_' . str_replace('-', '_', $key) . '_' . $field;
    }

    /**
     * Labels expected by the storefront React component.
     *
     * @param string $title Page title.
     * @return array<string, string>
     */
    private static function react_labels(string $title): array {
        $gallerystring = static function (string $identifier): string {
            return get_string($identifier, self::COMPONENT);
        };

        return [
            'customize' => get_string('storefront_customize', self::COMPONENT),
            'managetitle' => get_string('storefront_manage_page_title', self::COMPONENT, $title),
            'manageintro' => get_string('storefront_manage_page_intro', self::COMPONENT),
            'show' => get_string('chart_show', self::COMPONENT),
            'moveup' => get_string('chart_move_up', self::COMPONENT),
            'movedown' => get_string('chart_move_down', self::COMPONENT),
            'add' => get_string('add'),
            'remove' => get_string('remove', self::COMPONENT),
            'filecouldnotread' => get_string('p1_storefront_filecouldnotread', self::COMPONENT),
            'removeimage' => get_string('p1_storefront_removeimage', self::COMPONENT),
            'removevideo' => get_string('p1_storefront_removevideo', self::COMPONENT),
            'uploadfailed' => get_string('p1_storefront_uploadfailed', self::COMPONENT),
            'uploading' => get_string('p1_storefront_uploading', self::COMPONENT),
            'videouploaded' => get_string('p1_storefront_videouploaded', self::COMPONENT),
            'zone' => get_string('storefront_zone', self::COMPONENT),
            'editwidget' => get_string('storefront_edit_widget', self::COMPONENT),
            'deletewidget' => get_string('storefront_delete_widget', self::COMPONENT),
            'addwidget' => get_string('storefront_add_widget', self::COMPONENT),
            'choosetype' => get_string('storefront_choose_type', self::COMPONENT),
            'applypreset' => get_string('widgetpreset_apply', self::COMPONENT),
            'universalstyles' => get_string('widgetpreset_universalstyles', self::COMPONENT),
            'widgetpreset' => get_string('widgetpreset_label', self::COMPONENT),
            'widgetpresetempty' => get_string('widgetpreset_empty', self::COMPONENT),
            'widgetpresetnone' => get_string('widgetpreset_none', self::COMPONENT),
            'slides' => get_string('storefront_slides', self::COMPONENT),
            'addslide' => get_string('storefront_add_slide', self::COMPONENT),
            'save' => get_string('savechanges', self::COMPONENT),
            'cancel' => get_string('cancel'),
            'loading' => get_string('loading', self::COMPONENT),
            'breadcrumb_backgroundfile' => $gallerystring('widgetgallery_breadcrumb_backgroundfile'),
            'breadcrumb_backgroundhint' => $gallerystring('widgetgallery_breadcrumb_backgroundhint'),
            'breadcrumb_backgroundimage' => $gallerystring('widgetgallery_breadcrumb_backgroundimage'),
            'breadcrumb_imagesource' => $gallerystring('widgetgallery_breadcrumb_imagesource'),
            'breadcrumb_item_background' => $gallerystring('widgetgallery_breadcrumb_item_background'),
            'breadcrumb_sourceupload' => $gallerystring('widgetgallery_breadcrumb_sourceupload'),
            'breadcrumb_sourceurl' => $gallerystring('widgetgallery_breadcrumb_sourceurl'),
            'countdown_bgcolor' => $gallerystring('widgetgallery_countdown_bgcolor'),
            'countdown_buttoncolor' => $gallerystring('widgetgallery_countdown_buttoncolor'),
            'countdown_buttontextcolor' => $gallerystring('widgetgallery_countdown_buttontextcolor'),
            'countdown_edititem' => $gallerystring('widgetgallery_countdown_edititem'),
            'countdown_expiredbgcolor' => $gallerystring('widgetgallery_countdown_expiredbgcolor'),
            'countdown_expiredtextcolor' => $gallerystring('widgetgallery_countdown_expiredtextcolor'),
            'countdown_headingcolor' => $gallerystring('widgetgallery_countdown_headingcolor'),
            'countdown_headingfontsize' => $gallerystring('widgetgallery_countdown_headingfontsize'),
            'countdown_item_background' => $gallerystring('widgetgallery_countdown_item_background'),
            'countdown_item_button' => $gallerystring('widgetgallery_countdown_item_button'),
            'countdown_item_expired' => $gallerystring('widgetgallery_countdown_item_expired'),
            'countdown_item_heading' => $gallerystring('widgetgallery_countdown_item_heading'),
            'countdown_item_spacing' => $gallerystring('widgetgallery_countdown_item_spacing'),
            'countdown_item_timer' => $gallerystring('widgetgallery_countdown_item_timer'),
            'countdown_paddingbottom' => $gallerystring('widgetgallery_countdown_paddingbottom'),
            'countdown_paddingtop' => $gallerystring('widgetgallery_countdown_paddingtop'),
            'countdown_textcolor' => $gallerystring('widgetgallery_countdown_textcolor'),
            'countdown_timerbgcolor' => $gallerystring('widgetgallery_countdown_timerbgcolor'),
            'countdown_timerlabelcolor' => $gallerystring('widgetgallery_countdown_timerlabelcolor'),
            'countdown_timerlabelfontsize' => $gallerystring('widgetgallery_countdown_timerlabelfontsize'),
            'countdown_timernumbercolor' => $gallerystring('widgetgallery_countdown_timernumbercolor'),
            'countdown_timernumberfontsize' => $gallerystring('widgetgallery_countdown_timernumberfontsize'),
            'categories_bgcolor' => $gallerystring('widgetgallery_categories_bgcolor'),
            'categories_cardbgcolor' => $gallerystring('widgetgallery_categories_cardbgcolor'),
            'categories_cardradius' => $gallerystring('widgetgallery_categories_cardradius'),
            'categories_cardtextcolor' => $gallerystring('widgetgallery_categories_cardtextcolor'),
            'categories_cardtextfontsize' => $gallerystring('widgetgallery_categories_cardtextfontsize'),
            'categories_countcolor' => $gallerystring('widgetgallery_categories_countcolor'),
            'categories_countfontsize' => $gallerystring('widgetgallery_categories_countfontsize'),
            'categories_edititem' => $gallerystring('widgetgallery_categories_edititem'),
            'categories_iconbgcolor' => $gallerystring('widgetgallery_categories_iconbgcolor'),
            'categories_iconcolor' => $gallerystring('widgetgallery_categories_iconcolor'),
            'categories_iconsize' => $gallerystring('widgetgallery_categories_iconsize'),
            'categories_item_cards' => $gallerystring('widgetgallery_categories_item_cards'),
            'categories_item_content' => $gallerystring('widgetgallery_categories_item_content'),
            'categories_item_count' => $gallerystring('widgetgallery_categories_item_count'),
            'categories_item_heading' => $gallerystring('widgetgallery_categories_item_heading'),
            'categories_item_icons' => $gallerystring('widgetgallery_categories_item_icons'),
            'categories_item_layout' => $gallerystring('widgetgallery_categories_item_layout'),
            'categories_item_spacing' => $gallerystring('widgetgallery_categories_item_spacing'),
            'categories_paddingbottom' => $gallerystring('widgetgallery_categories_paddingbottom'),
            'categories_paddingtop' => $gallerystring('widgetgallery_categories_paddingtop'),
            'categories_style' => $gallerystring('widgetgallery_categories_style'),
            'categories_subtitlecolor' => $gallerystring('widgetgallery_categories_subtitlecolor'),
            'categories_subtitlefontsize' => $gallerystring('widgetgallery_categories_subtitlefontsize'),
            'categories_titlecolor' => $gallerystring('widgetgallery_categories_titlecolor'),
            'categories_titlefontsize' => $gallerystring('widgetgallery_categories_titlefontsize'),
            'categories_visiblecards' => $gallerystring('widgetgallery_categories_visiblecards'),
            'videohero_accentcolor' => $gallerystring('widgetgallery_videohero_accentcolor'),
            'videohero_bgcolor' => $gallerystring('widgetgallery_videohero_bgcolor'),
            'videohero_bodysize' => $gallerystring('widgetgallery_videohero_bodysize'),
            'videohero_edititem' => $gallerystring('widgetgallery_videohero_edititem'),
            'videohero_headingcolor' => $gallerystring('widgetgallery_videohero_headingcolor'),
            'videohero_headingsize' => $gallerystring('widgetgallery_videohero_headingsize'),
            'videohero_infocardbgcolor' => $gallerystring('widgetgallery_videohero_infocardbgcolor'),
            'videohero_infoheadingcolor' => $gallerystring('widgetgallery_videohero_infoheadingcolor'),
            'videohero_infoheadingsize' => $gallerystring('widgetgallery_videohero_infoheadingsize'),
            'videohero_infoiconbgcolor' => $gallerystring('widgetgallery_videohero_infoiconbgcolor'),
            'videohero_infoiconcolor' => $gallerystring('widgetgallery_videohero_infoiconcolor'),
            'videohero_infotextcolor' => $gallerystring('widgetgallery_videohero_infotextcolor'),
            'videohero_item_background' => $gallerystring('widgetgallery_videohero_item_background'),
            'videohero_item_body' => $gallerystring('widgetgallery_videohero_item_body'),
            'videohero_item_buttons' => $gallerystring('widgetgallery_videohero_item_buttons'),
            'videohero_item_heading' => $gallerystring('widgetgallery_videohero_item_heading'),
            'videohero_item_infocard' => $gallerystring('widgetgallery_videohero_item_infocard'),
            'videohero_item_panel' => $gallerystring('widgetgallery_videohero_item_panel'),
            'videohero_item_spacing' => $gallerystring('widgetgallery_videohero_item_spacing'),
            'videohero_panelradius' => $gallerystring('widgetgallery_videohero_panelradius'),
            'videohero_primarybuttoncolor' => $gallerystring('widgetgallery_videohero_primarybuttoncolor'),
            'videohero_primarybuttontextcolor' => $gallerystring('widgetgallery_videohero_primarybuttontextcolor'),
            'videohero_secondarybuttoncolor' => $gallerystring('widgetgallery_videohero_secondarybuttoncolor'),
            'videohero_secondarybuttontextcolor' => $gallerystring('widgetgallery_videohero_secondarybuttontextcolor'),
            'videohero_sectionbgcolor' => $gallerystring('widgetgallery_videohero_sectionbgcolor'),
            'videohero_sourcenone' => $gallerystring('widgetgallery_videohero_sourcenone'),
            'videohero_sourceupload' => $gallerystring('widgetgallery_videohero_sourceupload'),
            'videohero_sourceurl' => $gallerystring('widgetgallery_videohero_sourceurl'),
            'videohero_spacingbottom' => $gallerystring('widgetgallery_videohero_spacingbottom'),
            'videohero_spacingtop' => $gallerystring('widgetgallery_videohero_spacingtop'),
            'videohero_textcolor' => $gallerystring('widgetgallery_videohero_textcolor'),
            'videohero_videofile' => $gallerystring('widgetgallery_videohero_videofile'),
            'videohero_videoposter' => $gallerystring('widgetgallery_videohero_videoposter'),
            'videohero_videosource' => $gallerystring('widgetgallery_videohero_videosource'),
            'videohero_videotitle' => $gallerystring('widgetgallery_videohero_videotitle'),
            'videohero_videotitleplaceholder' => $gallerystring('widgetgallery_videohero_videotitleplaceholder'),
            'videohero_videourl' => $gallerystring('widgetgallery_videohero_videourl'),
        ];
    }

    /**
     * Labels expected by embedded catalog/product widgets.
     *
     * @return array<string, string>
     */
    private static function catalog_labels(): array {
        $labels = get_string_manager()->load_component_strings(self::COMPONENT, current_language());
        return array_merge($labels, [
            'catalog' => get_string('catalog', self::COMPONENT),
            'course' => get_string('course'),
            'login' => get_string('login'),
            'startsignup' => get_string('startsignup'),
        ]);
    }

    /**
     * Load plugin lib functions used by the widget editor config.
     */
    private static function ensure_lib_loaded(): void {
        global $CFG;

        require_once($CFG->dirroot . '/local/moderncommerce/lib.php');
    }

    /**
     * Resolve support email.
     *
     * @return string
     */
    public static function support_email(): string {
        global $CFG;

        $email = trim((string)get_config(self::COMPONENT, 'support_email'));
        if ($email === '' && !empty($CFG->supportemail)) {
            $email = (string)$CFG->supportemail;
        }

        return validate_email($email) ? $email : '';
    }
}
