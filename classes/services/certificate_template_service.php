<?php
// This file is part of Moodle and is licensed under the GNU GPL v3 or later.

namespace local_moderncommerce\services;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves the store-wide and per-program Course Certificate templates.
 */
class certificate_template_service {
    private const COMPONENT = 'local_moderncommerce';
    private const CONFIG_DEFAULT_TEMPLATE = 'default_program_certificate_template_id';

    /** Whether Moodle Course Certificate and its template backend are available. */
    public static function available(): bool {
        global $DB;

        try {
            $plugin = \core_plugin_manager::instance()->get_plugin_info('mod_coursecertificate');
            return $plugin !== null
                && $plugin->is_installed_and_upgraded()
                && class_exists(\tool_certificate\template::class)
                && $DB->get_manager()->table_exists(new \xmldb_table('tool_certificate_templates'));
        } catch (\Throwable $exception) {
            // Course Certificate is optional. A partial install, removal, or
            // pending upgrade must not break Modern Commerce admin pages.
            debugging('Course Certificate availability check failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /** Return templates visible to the current administrator. */
    public static function options(): array {
        if (!self::available()) {
            return [];
        }

        try {
            $options = [];
            foreach (\tool_certificate\template::get_visible_templates_list() as $id => $name) {
                $options[(int)$id] = (string)$name;
            }
            return $options;
        } catch (\Throwable $exception) {
            debugging('Course Certificate template lookup failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return [];
        }
    }

    /** Return the template manager URL when the current user may access it. */
    public static function manage_url(): string {
        global $CFG;

        if (!self::available()
                || !class_exists(\tool_certificate\permission::class)
                || !is_readable($CFG->dirroot . '/admin/tool/certificate/manage_templates.php')) {
            return '';
        }

        try {
            if (!\tool_certificate\permission::can_view_admin_tree()) {
                return '';
            }
            return (new \moodle_url('/admin/tool/certificate/manage_templates.php'))->out(false);
        } catch (\Throwable $exception) {
            debugging('Course Certificate template manager check failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }

    /** Return the configured global template when it is still valid and visible. */
    public static function default_template_id(): int {
        $templateid = (int)(get_config(self::COMPONENT, self::CONFIG_DEFAULT_TEMPLATE) ?: 0);
        return self::exists($templateid) ? $templateid : 0;
    }

    /** Resolve a program override, falling back to the store-wide default. */
    public static function resolve(int $overridetemplateid): int {
        if (self::exists($overridetemplateid)) {
            return $overridetemplateid;
        }
        return self::default_template_id();
    }

    /** Check template existence without applying administrator visibility to learner-side issuance. */
    public static function exists(int $templateid): bool {
        global $DB;

        if ($templateid <= 0 || !self::available()) {
            return false;
        }

        try {
            return $DB->record_exists('tool_certificate_templates', ['id' => $templateid]);
        } catch (\Throwable $exception) {
            debugging('Course Certificate template validation failed: ' . $exception->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /** Validate and save the global template. Zero disables the global fallback. */
    public static function save_default(int $templateid): bool {
        if ($templateid > 0 && !array_key_exists($templateid, self::options())) {
            return false;
        }
        set_config(self::CONFIG_DEFAULT_TEMPLATE, max(0, $templateid), self::COMPONENT);
        return true;
    }
}
