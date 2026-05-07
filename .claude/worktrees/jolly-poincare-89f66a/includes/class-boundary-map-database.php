<?php
/**
 * Database layer for Boundary Map.
 * Handles table creation, migration from JSON, and CRUD.
 */
if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Database
{
    private static $entries_table;
    private static $categories_table;

    public static function init()
    {
        global $wpdb;
        self::$entries_table = $wpdb->prefix . 'abm_entries';
        self::$categories_table = $wpdb->prefix . 'abm_categories';
    }

    public static function get_entries_table()
    {
        if (!self::$entries_table) {
            global $wpdb;
            self::$entries_table = $wpdb->prefix . 'abm_entries';
        }
        return self::$entries_table;
    }

    public static function get_categories_table()
    {
        if (!self::$categories_table) {
            global $wpdb;
            self::$categories_table = $wpdb->prefix . 'abm_categories';
        }
        return self::$categories_table;
    }

    /**
     * Create database tables.
     */
    public static function create_tables()
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $entries_table = self::get_entries_table();
        $categories_table = self::get_categories_table();

        $sql_entries = "CREATE TABLE $entries_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            category_id varchar(50) NOT NULL DEFAULT '',
            category_label varchar(255) NOT NULL DEFAULT '',
            description text,
            location varchar(255) NOT NULL DEFAULT '',
            lat decimal(10,7) DEFAULT NULL,
            lng decimal(10,7) DEFAULT NULL,
            image varchar(500) DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'publish',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset;";

        $sql_categories = "CREATE TABLE $categories_table (
            id varchar(50) NOT NULL,
            label varchar(255) NOT NULL DEFAULT '',
            color varchar(20) DEFAULT NULL,
            icon varchar(50) DEFAULT NULL,
            sort_order int(11) DEFAULT 0,
            PRIMARY KEY (id)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_entries);
        dbDelta($sql_categories);

        update_option('abm_db_version', '1.0');
    }

    /**
     * Check if database tables exist and have data.
     */
    public static function is_migrated()
    {
        return get_option('abm_migrated_to_db', false);
    }

    /**
     * Migrate from JSON files to database.
     * Skips migration if already migrated (avoids duplicates on re-activation).
     */
    public static function migrate_from_json($entries_file, $categories_file)
    {
        global $wpdb;

        if (get_option('abm_migrated_to_db', false)) {
            return;
        }

        self::create_tables();
        $entries_table = self::get_entries_table();
        $categories_table = self::get_categories_table();

        // Migrate categories first
        if (file_exists($categories_file)) {
            $raw = file_get_contents($categories_file);
            $data = json_decode($raw, true);
            $categories = array();
            if (is_array($data) && isset($data['categories'])) {
                $categories = $data['categories'];
            } elseif (is_array($data)) {
                $categories = $data;
            }

            foreach ($categories as $cat) {
                $id = isset($cat['id']) ? sanitize_text_field($cat['id']) : '';
                if (!$id) continue;
                $wpdb->replace($categories_table, array(
                    'id' => $id,
                    'label' => isset($cat['label']) ? sanitize_text_field($cat['label']) : $id,
                    'color' => isset($cat['color']) ? sanitize_text_field($cat['color']) : null,
                    'icon' => isset($cat['icon']) ? sanitize_text_field($cat['icon']) : null,
                ));
            }
        }

        // Migrate entries
        if (file_exists($entries_file)) {
            $raw = file_get_contents($entries_file);
            $entries = json_decode($raw, true);
            if (!is_array($entries)) {
                $entries = array();
            }

            foreach ($entries as $ev) {
                $lat = null;
                $lng = null;
                if (!empty($ev['coords']) && is_array($ev['coords']) && count($ev['coords']) >= 2) {
                    $lat = floatval($ev['coords'][0]);
                    $lng = floatval($ev['coords'][1]);
                }

                $wpdb->insert($entries_table, array(
                    'title' => isset($ev['title']) ? sanitize_text_field($ev['title']) : '',
                    'category_id' => isset($ev['categoryId']) ? sanitize_text_field($ev['categoryId']) : (isset($ev['category']) ? sanitize_text_field($ev['category']) : ''),
                    'category_label' => isset($ev['category']) ? sanitize_text_field($ev['category']) : '',
                    'description' => isset($ev['description']) ? wp_kses_post($ev['description']) : '',
                    'location' => isset($ev['location']) ? sanitize_text_field($ev['location']) : '',
                    'lat' => $lat,
                    'lng' => $lng,
                    'image' => isset($ev['image']) ? esc_url_raw($ev['image']) : null,
                    'status' => isset($ev['status']) ? sanitize_text_field($ev['status']) : 'publish',
                ));
            }
        }

        update_option('abm_migrated_to_db', true);
    }

    /**
     * Load entries from database.
     */
    public static function load_entries($status_filter = null)
    {
        global $wpdb;
        $table = self::get_entries_table();

        $where = '';
        if ($status_filter === 'publish') {
            $where = " WHERE status = 'publish'";
        } elseif ($status_filter === 'trash') {
            $where = " WHERE status = 'trash'";
        }

        $results = $wpdb->get_results("SELECT * FROM $table {$where} ORDER BY id ASC", ARRAY_A);
        $entries = array();

        foreach ($results as $row) {
            $coords = null;
            if ($row['lat'] !== null && $row['lng'] !== null) {
                $coords = array(floatval($row['lat']), floatval($row['lng']));
            }
            $entries[$row['id']] = array(
                'id' => intval($row['id']),
                'title' => $row['title'],
                'categoryId' => $row['category_id'],
                'category' => $row['category_label'],
                'description' => $row['description'],
                'location' => $row['location'],
                'coords' => $coords,
                'image' => $row['image'],
                'status' => $row['status'],
            );
        }

        return $entries;
    }

    /**
     * Save (insert or update) a single entry.
     */
    public static function save_entry($data, $id = null)
    {
        global $wpdb;
        $table = self::get_entries_table();

        $lat = null;
        $lng = null;
        if (!empty($data['coords']) && is_array($data['coords']) && count($data['coords']) >= 2) {
            $lat = floatval($data['coords'][0]);
            $lng = floatval($data['coords'][1]);
        }

        $row = array(
            'title' => isset($data['title']) ? sanitize_text_field($data['title']) : '',
            'category_id' => isset($data['categoryId']) ? sanitize_text_field($data['categoryId']) : '',
            'category_label' => isset($data['category']) ? sanitize_text_field($data['category']) : '',
            'description' => isset($data['description']) ? wp_kses_post($data['description']) : '',
            'location' => isset($data['location']) ? sanitize_text_field($data['location']) : '',
            'lat' => $lat,
            'lng' => $lng,
            'image' => isset($data['image']) ? esc_url_raw($data['image']) : null,
            'status' => isset($data['status']) ? sanitize_text_field($data['status']) : 'publish',
        );

        if ($id !== null && $id > 0) {
            $wpdb->update($table, $row, array('id' => intval($id)));
            return intval($id);
        } else {
            $wpdb->insert($table, $row);
            return $wpdb->insert_id;
        }
    }

    /**
     * Update entry status.
     */
    public static function update_entry_status($id, $status)
    {
        if (!in_array($status, array('publish', 'trash'), true)) {
            return false;
        }
        global $wpdb;
        $table = self::get_entries_table();
        return $wpdb->update($table, array('status' => sanitize_text_field($status)), array('id' => intval($id)));
    }

    /**
     * Delete entry permanently.
     */
    public static function delete_entry($id)
    {
        global $wpdb;
        $table = self::get_entries_table();
        return $wpdb->delete($table, array('id' => intval($id)));
    }

    /**
     * Bulk update status.
     */
    public static function bulk_update_status($ids, $status)
    {
        if (!in_array($status, array('publish', 'trash'), true)) {
            return 0;
        }
        global $wpdb;
        $table = self::get_entries_table();
        $ids = array_map('intval', (array) $ids);
        $ids = array_filter($ids);
        if (empty($ids)) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return $wpdb->query($wpdb->prepare(
            "UPDATE $table SET status = %s WHERE id IN ($placeholders)",
            array_merge(array(sanitize_text_field($status)), $ids)
        ));
    }

    /**
     * Bulk delete.
     */
    public static function bulk_delete($ids)
    {
        global $wpdb;
        $table = self::get_entries_table();
        $ids = array_map('intval', (array) $ids);
        $ids = array_filter($ids);
        if (empty($ids)) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE id IN ($placeholders)",
            $ids
        ));
    }

    /**
     * Load categories from database.
     */
    public static function load_categories()
    {
        global $wpdb;
        $table = self::get_categories_table();
        $results = $wpdb->get_results("SELECT * FROM $table ORDER BY sort_order ASC, id ASC", ARRAY_A);
        $categories = array();
        foreach ($results as $row) {
            $categories[] = array(
                'id' => $row['id'],
                'label' => $row['label'],
                'color' => $row['color'],
                'icon' => $row['icon'],
            );
        }
        return $categories;
    }

    /**
     * Save category.
     */
    public static function save_category($data)
    {
        global $wpdb;
        $table = self::get_categories_table();
        $id = isset($data['id']) ? sanitize_text_field($data['id']) : '';
        if (!$id) return false;
        return $wpdb->replace($table, array(
            'id' => $id,
            'label' => isset($data['label']) ? sanitize_text_field($data['label']) : $id,
            'color' => isset($data['color']) ? sanitize_text_field($data['color']) : null,
            'icon' => isset($data['icon']) ? sanitize_text_field($data['icon']) : null,
        ));
    }

    /**
     * Delete category.
     */
    public static function delete_category($id)
    {
        global $wpdb;
        $table = self::get_categories_table();
        return $wpdb->delete($table, array('id' => $id));
    }

    /**
     * Save all categories (replaces existing).
     */
    public static function save_categories($categories)
    {
        global $wpdb;
        $table = self::get_categories_table();
        $wpdb->query("TRUNCATE TABLE $table");
        foreach ($categories as $cat) {
            $id = isset($cat['id']) ? sanitize_text_field($cat['id']) : '';
            if (!$id) continue;
            $wpdb->insert($table, array(
                'id' => $id,
                'label' => isset($cat['label']) ? sanitize_text_field($cat['label']) : $id,
                'color' => isset($cat['color']) ? sanitize_text_field($cat['color']) : null,
                'icon' => isset($cat['icon']) ? sanitize_text_field($cat['icon']) : null,
            ));
        }
    }
}
