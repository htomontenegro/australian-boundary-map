<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Settings_Service
{
    private const OPTION_GEOGRAPHY_SELECTION = 'boundary_map_geography_selection';
    private const OPTION_SHOW_SIDEBAR_PANEL = 'boundary_map_show_sidebar_panel';
    private const OPTION_MARKER_TAG_MODE = 'boundary_map_marker_tag_mode';

    public static function get_geography_selection_option_name()
    {
        return self::OPTION_GEOGRAPHY_SELECTION;
    }

    public static function get_sidebar_panel_option_name()
    {
        return self::OPTION_SHOW_SIDEBAR_PANEL;
    }

    public static function get_marker_tag_mode_option_name()
    {
        return self::OPTION_MARKER_TAG_MODE;
    }

    public static function seed_default_options($default_geography_selection)
    {
        add_option(self::get_geography_selection_option_name(), $default_geography_selection);
        add_option(self::get_sidebar_panel_option_name(), '1');
        add_option(self::get_marker_tag_mode_option_name(), 'clickable');
    }

    public static function get_legacy_option_name_map()
    {
        return array(
            'boundary_map_db_version' => 'ach_map_db_version',
            'boundary_map_migrated_to_db' => 'ach_map_migrated_to_db',
            'boundary_map_plugin_version' => 'ach_map_plugin_version',
            self::OPTION_GEOGRAPHY_SELECTION => 'ach_map_geography_selection',
            self::OPTION_SHOW_SIDEBAR_PANEL => 'ach_map_show_sidebar_panel',
            self::OPTION_MARKER_TAG_MODE => 'ach_map_marker_tag_mode',
        );
    }

    public static function migrate_legacy_options()
    {
        foreach (self::get_legacy_option_name_map() as $new_option_name => $legacy_option_name) {
            $new_value = get_option($new_option_name, null);
            $legacy_value = get_option($legacy_option_name, null);

            if (null === $new_value && null !== $legacy_value) {
                add_option($new_option_name, $legacy_value);
            }

            if (null !== $legacy_value) {
                delete_option($legacy_option_name);
            }
        }

        $legacy_entries_cache = get_transient('ach_map_entries');
        if (false !== $legacy_entries_cache) {
            set_transient('boundary_map_entries', $legacy_entries_cache, HOUR_IN_SECONDS);
            delete_transient('ach_map_entries');
        }

        $legacy_categories_cache = get_transient('ach_map_categories');
        if (false !== $legacy_categories_cache) {
            set_transient('boundary_map_categories', $legacy_categories_cache, HOUR_IN_SECONDS);
            delete_transient('ach_map_categories');
        }
    }

    public static function get_option_with_legacy_fallback($option_name, $default = null)
    {
        $value = get_option($option_name, null);
        if (null !== $value) {
            return $value;
        }

        $legacy_option_map = self::get_legacy_option_name_map();
        if (!isset($legacy_option_map[$option_name])) {
            return $default;
        }

        $legacy_value = get_option($legacy_option_map[$option_name], $default);

        return null === $legacy_value ? $default : $legacy_value;
    }

    public static function get_saved_geography_selection($default_selection, $sanitize_selection_callback)
    {
        $raw = self::get_option_with_legacy_fallback(self::get_geography_selection_option_name(), array());
        if (!is_array($raw)) {
            $raw = array();
        }

        if (empty($raw)) {
            return $default_selection;
        }

        return call_user_func($sanitize_selection_callback, $raw);
    }

    public static function get_saved_show_sidebar_panel($sanitize_toggle_callback)
    {
        return call_user_func(
            $sanitize_toggle_callback,
            self::get_option_with_legacy_fallback(self::get_sidebar_panel_option_name(), '1'),
            true
        );
    }

    public static function get_saved_marker_tag_mode($sanitize_marker_tag_mode_callback)
    {
        return call_user_func(
            $sanitize_marker_tag_mode_callback,
            self::get_option_with_legacy_fallback(self::get_marker_tag_mode_option_name(), 'clickable')
        );
    }

    public static function save_geography_selection($selection)
    {
        update_option(self::get_geography_selection_option_name(), $selection);
    }

    public static function save_config(
        $selection,
        $show_sidebar_panel_raw,
        $marker_tag_mode_raw,
        $sanitize_selection_callback,
        $sanitize_toggle_callback,
        $sanitize_marker_tag_mode_callback
    ) {
        $sanitized_selection = call_user_func($sanitize_selection_callback, $selection);
        $show_sidebar_panel = call_user_func($sanitize_toggle_callback, $show_sidebar_panel_raw, true);
        $marker_tag_mode = call_user_func($sanitize_marker_tag_mode_callback, $marker_tag_mode_raw);

        self::save_geography_selection($sanitized_selection);
        update_option(self::get_sidebar_panel_option_name(), $show_sidebar_panel ? '1' : '0');
        update_option(self::get_marker_tag_mode_option_name(), $marker_tag_mode);

        return array(
            'selection' => $sanitized_selection,
            'show_sidebar_panel' => $show_sidebar_panel,
            'marker_tag_mode' => $marker_tag_mode,
        );
    }
}
