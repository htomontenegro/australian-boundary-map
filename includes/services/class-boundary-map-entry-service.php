<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Entry_Service
{
    public static function load_entries($entries_file, $status_filter = null)
    {
        if (Boundary_Map_Database::is_migrated()) {
            $entries = Boundary_Map_Database::load_entries($status_filter);
        } else {
            if (!file_exists($entries_file)) {
                return array();
            }

            $raw = file_get_contents($entries_file);
            $data = json_decode($raw, true);
            $entries = is_array($data) ? $data : array();

            foreach ($entries as $index => $entry) {
                if (!isset($entry['id'])) {
                    $entries[$index]['id'] = $index;
                }
            }

            if ($status_filter !== null) {
                $filtered = array();

                foreach ($entries as $index => $entry) {
                    $status = isset($entry['status']) ? $entry['status'] : 'publish';
                    if ($status === $status_filter) {
                        $filtered[$index] = $entry;
                    }
                }

                $entries = $filtered;
            }
        }

        foreach ($entries as $index => $entry) {
            if (!empty($entry['image'])) {
                $entries[$index]['image'] = self::localize_entry_image_url($entry['image']);
            }
        }

        return apply_filters('boundary_map_loaded_entries', $entries, $status_filter);
    }

    public static function save_entries($entries, $entries_file)
    {
        $entries = apply_filters('boundary_map_entries_before_save', $entries);

        if (Boundary_Map_Database::is_migrated()) {
            foreach ($entries as $entry) {
                $id = isset($entry['id']) ? intval($entry['id']) : null;
                Boundary_Map_Database::save_entry($entry, $id > 0 ? $id : null);
            }

            return true;
        }

        $json = json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return false !== file_put_contents($entries_file, $json);
    }

    public static function count_entries_using_category($category_id, $entries_file)
    {
        if ($category_id === '') {
            return 0;
        }

        if (Boundary_Map_Database::is_migrated()) {
            return Boundary_Map_Database::count_entries_for_category($category_id);
        }

        $count = 0;
        foreach (self::load_entries($entries_file) as $entry) {
            $entry_category_id = isset($entry['categoryId']) ? $entry['categoryId'] : '';
            $entry_category = isset($entry['category']) ? $entry['category'] : '';

            if ($entry_category_id === $category_id || ($entry_category_id === '' && $entry_category === $category_id)) {
                $count++;
            }
        }

        return $count;
    }

    public static function sync_entries_for_category_change($old_category_id, $new_category_id, $new_category_label, $entries_file)
    {
        if ($old_category_id === '' || $new_category_id === '' || $new_category_label === '') {
            return false;
        }

        if (Boundary_Map_Database::is_migrated()) {
            Boundary_Map_Database::update_entry_category($old_category_id, $new_category_id, $new_category_label);
            return true;
        }

        $entries = self::load_entries($entries_file);
        $updated = false;

        foreach ($entries as $index => $entry) {
            $entry_category_id = isset($entry['categoryId']) ? $entry['categoryId'] : '';
            $entry_category = isset($entry['category']) ? $entry['category'] : '';

            if ($entry_category_id !== $old_category_id && !($entry_category_id === '' && $entry_category === $old_category_id)) {
                continue;
            }

            $entries[$index]['categoryId'] = $new_category_id;
            $entries[$index]['category'] = $new_category_label;
            $updated = true;
        }

        if (!$updated) {
            return false;
        }

        return self::save_entries($entries, $entries_file);
    }

    private static function localize_entry_image_url($url)
    {
        if (empty($url) || !is_string($url)) {
            return $url;
        }

        return $url;
    }
}
