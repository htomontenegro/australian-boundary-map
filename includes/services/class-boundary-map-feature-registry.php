<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Feature_Registry
{
    public static function get_default_features()
    {
        return array(
            'core_map' => true,
            'entries_management' => true,
            'categories_management' => true,
            'csv_import_export' => true,
            'public_rest_api' => true,
            'shortcode_generator' => true,
            'single_dataset' => true,
            'single_map_config' => true,
            'premium_addon_support' => true,
        );
    }

    public static function get_features()
    {
        $features = apply_filters('boundary_map_registered_features', self::get_default_features());

        if (!is_array($features)) {
            return self::get_default_features();
        }

        return $features;
    }

    public static function is_enabled($feature_key, $default = false)
    {
        $features = self::get_features();
        $enabled = array_key_exists($feature_key, $features) ? (bool) $features[$feature_key] : (bool) $default;

        return (bool) apply_filters('boundary_map_feature_enabled', $enabled, $feature_key, $features);
    }
}
