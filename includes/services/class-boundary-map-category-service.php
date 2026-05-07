<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Category_Service
{
    public static function load_categories($categories_file)
    {
        if (Boundary_Map_Database::is_migrated()) {
            $categories = Boundary_Map_Database::load_categories();
        } else {
            if (!file_exists($categories_file)) {
                return array();
            }

            $raw = file_get_contents($categories_file);
            $data = json_decode($raw, true);

            if (!is_array($data)) {
                return array();
            }

            if (isset($data['categories']) && is_array($data['categories'])) {
                $categories = $data['categories'];
            } else {
                $categories = $data;
            }
        }

        return apply_filters('boundary_map_loaded_categories', $categories);
    }

    public static function save_categories($categories, $categories_file)
    {
        $categories = apply_filters('boundary_map_categories_before_save', $categories);

        if (Boundary_Map_Database::is_migrated()) {
            Boundary_Map_Database::save_categories($categories);
            return true;
        }

        $wrapper = array('categories' => array_values($categories));
        $json = json_encode($wrapper, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return false !== file_put_contents($categories_file, $json);
    }

    public static function find_category_index_by_id($categories, $category_id)
    {
        foreach ((array) $categories as $index => $category) {
            $existing_id = isset($category['id']) ? (string) $category['id'] : '';
            if ($existing_id === (string) $category_id) {
                return $index;
            }
        }

        return -1;
    }

    public static function get_category_label_by_id($category_id, $categories)
    {
        foreach ((array) $categories as $category) {
            if (!empty($category['id']) && $category['id'] === $category_id) {
                $label = !empty($category['label']) ? $category['label'] : $category_id;

                return (string) apply_filters('boundary_map_category_label', $label, $category_id, $category);
            }
        }

        return (string) apply_filters('boundary_map_category_label', $category_id, $category_id, null);
    }
}
