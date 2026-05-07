<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Core
{
    private const SUPPORT_URL = 'https://buymeacoffee.com/htomontenegro';

    private $entries_file;
    private $categories_file;
    private $version = '1.3.0';

    private function get_feature_flags()
    {
        return Boundary_Map_Feature_Registry::get_features();
    }

    private function get_asset_version($relative_path)
    {
        $file_path = BOUNDARY_MAP_PLUGIN_DIR . ltrim($relative_path, '/');

        if (file_exists($file_path)) {
            return (string) filemtime($file_path);
        }

        return $this->version;
    }

    private function get_support_url()
    {
        return self::SUPPORT_URL;
    }

    public static function activate()
    {
        Boundary_Map_Database::create_tables();
        self::migrate_legacy_options();
        Boundary_Map_Database::migrate_from_json(
            BOUNDARY_MAP_PLUGIN_DIR . 'assets/entries.json',
            BOUNDARY_MAP_PLUGIN_DIR . 'assets/categories.json'
        );
        self::seed_default_options();
        update_option(self::get_plugin_version_option_name_static(), '1.3.0');
    }

    public static function deactivate()
    {
        delete_transient('boundary_map_entries');
        delete_transient('boundary_map_categories');
    }

    private static function get_plugin_version_option_name_static()
    {
        return 'boundary_map_plugin_version';
    }

    private function get_plugin_version_option_name()
    {
        return self::get_plugin_version_option_name_static();
    }

    private static function seed_default_options()
    {
        Boundary_Map_Settings_Service::seed_default_options(
            self::get_activation_default_geography_selection()
        );
    }

    private static function get_legacy_option_name_map()
    {
        return Boundary_Map_Settings_Service::get_legacy_option_name_map();
    }

    private static function migrate_legacy_options()
    {
        Boundary_Map_Settings_Service::migrate_legacy_options();
    }

    private function get_option_with_legacy_fallback($option_name, $default = null)
    {
        return Boundary_Map_Settings_Service::get_option_with_legacy_fallback($option_name, $default);
    }

    public function __construct()
    {
        $this->entries_file = BOUNDARY_MAP_PLUGIN_DIR . 'assets/entries.json';
        $this->categories_file = BOUNDARY_MAP_PLUGIN_DIR . 'assets/categories.json';
    }

    /**
     * Handle single trash/restore/delete actions early (before any output) to avoid "headers already sent" errors.
     */
    public function handle_single_entry_actions()
    {
        if (!$this->user_can_manage_boundary_map()) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page !== 'boundary-map-entries') {
            return;
        }
        $action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : '';
        if (!in_array($action, array('trash', 'untrash', 'delete'), true)) {
            return;
        }
        $entry_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['index']) ? intval($_GET['index']) : -1);
        if ($entry_id < 0) {
            return;
        }
        $has_id = isset($_GET['id']) || isset($_GET['index']);

        $redirect_url = add_query_arg('page', 'boundary-map-entries', admin_url('admin.php'));

        if ($action === 'trash' && $has_id) {
            check_admin_referer('boundary_map_trash_entry_' . $entry_id);
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::update_entry_status($entry_id, 'trash');
                self::flush_rest_cache();
            } else {
                $all_entries = $this->load_entries();
                if (isset($all_entries[$entry_id])) {
                    $all_entries[$entry_id]['status'] = 'trash';
                    $this->save_entries($all_entries);
                }
            }
            wp_safe_redirect(add_query_arg('trashed', '1', $redirect_url));
            exit;
        }

        if ($action === 'untrash' && $has_id) {
            check_admin_referer('boundary_map_restore_entry_' . $entry_id);
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::update_entry_status($entry_id, 'publish');
                self::flush_rest_cache();
            } else {
                $all_entries = $this->load_entries();
                if (isset($all_entries[$entry_id])) {
                    $all_entries[$entry_id]['status'] = 'publish';
                    $this->save_entries($all_entries);
                }
            }
            wp_safe_redirect(add_query_arg(array('post_status' => 'trash', 'untrashed' => '1'), $redirect_url));
            exit;
        }

        if ($action === 'delete' && $has_id) {
            check_admin_referer('boundary_map_delete_entry_' . $entry_id);
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::delete_entry($entry_id);
                self::flush_rest_cache();
            } else {
                $all_entries = $this->load_entries();
                if (isset($all_entries[$entry_id])) {
                    unset($all_entries[$entry_id]);
                    $this->save_entries($all_entries);
                }
            }
            wp_safe_redirect(add_query_arg(array('post_status' => 'trash', 'deleted' => '1'), $redirect_url));
            exit;
        }
    }

    /**
     * Handle Add/Edit Entry form submission early (before any output) to avoid "headers already sent" errors.
     */
    public function handle_entry_form_submit()
    {
        if (!$this->user_can_manage_boundary_map()) {
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page !== 'boundary-map-add-entry') {
            return;
        }
        if (!isset($_POST['boundary_map_entry_submit'])) {
            return;
        }

        check_admin_referer('boundary_map_save_entry');

        $edit_id = isset($_POST['entry_id']) && $_POST['entry_id'] !== '' ? intval($_POST['entry_id']) : null;
        $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
        $description = isset($_POST['description']) ? wp_kses_post($_POST['description']) : '';
        $location = isset($_POST['location']) ? sanitize_text_field($_POST['location']) : '';
        $image = isset($_POST['image']) ? esc_url_raw($_POST['image']) : '';
        $category_id = isset($_POST['category_id']) ? sanitize_text_field($_POST['category_id']) : '';

        $categories = $this->load_categories();
        $category_label = $category_id;
        foreach ($categories as $cat) {
            if (!empty($cat['id']) && $cat['id'] === $category_id) {
                $category_label = $cat['label'];
                break;
            }
        }

        $raw_lat = isset($_POST['lat']) ? trim(wp_unslash($_POST['lat'])) : '';
        $raw_lng = isset($_POST['lng']) ? trim(wp_unslash($_POST['lng'])) : '';
        $coords = null;
        if ($raw_lat !== '' && $raw_lng !== '' && is_numeric($raw_lat) && is_numeric($raw_lng)) {
            $coords = array(floatval($raw_lat), floatval($raw_lng));
        }

        $redirect_args = array();
        if ($edit_id !== null && $edit_id >= 0) {
            $redirect_args['action'] = 'edit';
            $redirect_args['id'] = $edit_id;
        }

        if ($title === '') {
            $this->redirect_to_admin_page('boundary-map-add-entry', array_merge($redirect_args, array(
                'entry_error' => 'missing_title',
            )));
        }

        if ($this->has_partial_coords($raw_lat, $raw_lng) || (($raw_lat !== '' || $raw_lng !== '') && !$this->coords_are_valid($raw_lat, $raw_lng))) {
            $this->redirect_to_admin_page('boundary-map-add-entry', array_merge($redirect_args, array(
                'entry_error' => 'invalid_coords',
            )));
        }

        if (!$this->is_valid_image_url($image)) {
            $this->redirect_to_admin_page('boundary-map-add-entry', array_merge($redirect_args, array(
                'entry_error' => 'invalid_image',
            )));
        }

        if ($category_id !== '' && $this->find_category_index_by_id($categories, $category_id) < 0) {
            $this->redirect_to_admin_page('boundary-map-add-entry', array_merge($redirect_args, array(
                'entry_error' => 'invalid_category',
            )));
        }

        if (
            isset($_POST['country'])
            || isset($_POST['scope'])
            || isset($_POST['area'])
            || isset($_POST['subdivision'])
        ) {
            $geography_selection = $this->sanitize_geography_selection(array(
                'country' => isset($_POST['country']) ? sanitize_key($_POST['country']) : 'australia',
                'scope' => isset($_POST['scope']) ? sanitize_key($_POST['scope']) : '',
                'area' => isset($_POST['area']) ? sanitize_key($_POST['area']) : '',
                'subdivision' => isset($_POST['subdivision']) ? sanitize_key($_POST['subdivision']) : '',
            ));

            Boundary_Map_Settings_Service::save_geography_selection($geography_selection);
        }

        $entry_data = array(
            'title' => $title,
            'categoryId' => $category_id,
            'category' => $category_label,
            'description' => $description,
            'location' => $location,
            'coords' => $coords,
            'image' => $image,
            'status' => 'publish',
        );

        if (Boundary_Map_Database::is_migrated()) {
            Boundary_Map_Database::save_entry($entry_data, $edit_id);
            self::flush_rest_cache();
            $message_param = ($edit_id !== null && $edit_id > 0) ? 'updated' : 'added';
        } else {
            $entries = $this->load_entries();
            $index = ($edit_id !== null && $edit_id >= 0) ? $edit_id : -1;
            if ($index >= 0 && isset($entries[$index])) {
                $entries[$index] = $entry_data;
                $message_param = 'updated';
            } else {
                $entries[] = $entry_data;
                $message_param = 'added';
            }
            $this->save_entries($entries);
        }

        $redirect_url = add_query_arg(array('page' => 'boundary-map-entries', $message_param => '1'), admin_url('admin.php'));
        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Run migration from JSON to DB on first admin load (for existing installs).
     */
    public function maybe_upgrade_plugin()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        Boundary_Map_Database::create_tables();
        self::migrate_legacy_options();
        self::seed_default_options();

        if (!Boundary_Map_Database::is_migrated()) {
            Boundary_Map_Database::migrate_from_json($this->entries_file, $this->categories_file);
        }

        update_option($this->get_plugin_version_option_name(), $this->version);
    }

    /**
     * Legacy migration hook kept for backward compatibility.
     */
    public function maybe_migrate_on_load()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (Boundary_Map_Database::is_migrated()) {
            return;
        }
        Boundary_Map_Database::create_tables();
        self::migrate_legacy_options();
        Boundary_Map_Database::migrate_from_json($this->entries_file, $this->categories_file);
    }

    /**
     * Handle bulk actions via admin-post (runs before page render).
     */
    public function handle_bulk_entries()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        check_admin_referer('boundary_map_bulk_action');

        if (empty($_POST['entry']) || !is_array($_POST['entry'])) {
            wp_safe_redirect(add_query_arg(array('page' => 'boundary-map-entries', 'bulk_error' => 'no_items'), admin_url('admin.php')));
            exit;
        }

        $entries = $this->load_entries();
        $ids = array_values(array_unique(array_map('intval', (array) $_POST['entry'])));
        if (Boundary_Map_Database::is_migrated()) {
            $ids = array_values(array_filter($ids, function ($i) { return $i > 0; }));
        } else {
            $ids = array_values(array_filter($ids, function ($i) use ($entries) { return isset($entries[$i]); }));
        }

        $bulk_action = '';
        if (!empty($_POST['boundary_map_bulk_action_top']) && $_POST['boundary_map_bulk_action_top'] !== '-1') {
            $bulk_action = sanitize_text_field($_POST['boundary_map_bulk_action_top']);
        } elseif (!empty($_POST['boundary_map_bulk_action_bottom']) && $_POST['boundary_map_bulk_action_bottom'] !== '-1') {
            $bulk_action = sanitize_text_field($_POST['boundary_map_bulk_action_bottom']);
        }

        $redirect_url = add_query_arg('page', 'boundary-map-entries', admin_url('admin.php'));

        if ($bulk_action === 'trash' && !empty($ids)) {
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::bulk_update_status($ids, 'trash');
                self::flush_rest_cache();
            } else {
                foreach ($ids as $i) {
                    $entries[$i]['status'] = 'trash';
                }
                $this->save_entries($entries);
            }
            wp_safe_redirect(add_query_arg(array('trashed' => count($ids)), $redirect_url));
            exit;
        }

        if ($bulk_action === 'untrash' && !empty($ids)) {
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::bulk_update_status($ids, 'publish');
                self::flush_rest_cache();
            } else {
                foreach ($ids as $i) {
                    $entries[$i]['status'] = 'publish';
                }
                $this->save_entries($entries);
            }
            $args = array('untrashed' => count($ids));
            if (!empty($_POST['post_status']) && $_POST['post_status'] === 'trash') {
                $args['post_status'] = 'trash';
            }
            wp_safe_redirect(add_query_arg($args, $redirect_url));
            exit;
        }

        if ($bulk_action === 'delete_permanently' && !empty($ids)) {
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::bulk_delete($ids);
                self::flush_rest_cache();
            } else {
                foreach ($ids as $i) {
                    unset($entries[$i]);
                }
                $this->save_entries($entries);
            }
            $args = array('deleted' => count($ids));
            if (!empty($_POST['post_status']) && $_POST['post_status'] === 'trash') {
                $args['post_status'] = 'trash';
            }
            wp_safe_redirect(add_query_arg($args, $redirect_url));
            exit;
        }

        if ($bulk_action === 'edit' && !empty($ids)) {
            wp_safe_redirect(add_query_arg(array(
                'page' => 'boundary-map-add-entry',
                'action' => 'edit',
                'id' => reset($ids),
            ), admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg('bulk_error', 'no_action', $redirect_url));
        exit;
    }

    public function enqueue_front_assets()
    {
        $front_style_version = $this->get_asset_version('assets/styles.css');
        $front_script_version = $this->get_asset_version('assets/script.js');

        wp_register_style(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            array(),
            '1.9.4'
        );
        wp_register_style(
            'bootstrap-5',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
            array(),
            '5.3.3'
        );
        wp_register_style(
            'boundary-map',
            BOUNDARY_MAP_PLUGIN_URL . 'assets/styles.css',
            array('bootstrap-5'),
            $front_style_version
        );

        wp_register_script(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            array(),
            '1.9.4',
            true
        );
        wp_register_script(
            'bootstrap-5',
            'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
            array(),
            '5.3.3',
            true
        );
        wp_register_script(
            'fuse-js',
            'https://cdn.jsdelivr.net/npm/fuse.js@7.0.0',
            array(),
            '7.0.0',
            true
        );
        wp_register_script(
            'boundary-map-app',
            BOUNDARY_MAP_PLUGIN_URL . 'assets/script.js',
            array('leaflet', 'bootstrap-5', 'fuse-js'),
            $front_script_version,
            true
        );

        // Enqueue only when shortcode is present on this page
        if (!$this->page_has_shortcode()) {
            return;
        }

        $front_config = array(
            'entriesApiUrl' => rest_url('boundary-map/entries'),
            'entriesUrl' => rest_url('boundary-map/entries'),
            'categoriesApiUrl' => rest_url('boundary-map/categories'),
            'categoriesUrl' => rest_url('boundary-map/categories'),
            'features' => $this->get_feature_flags(),
            'showCategoryBox' => true,
            'showSidebarPanel' => $this->get_saved_show_sidebar_panel(),
            'markerTagMode' => $this->get_saved_marker_tag_mode(),
            'regionUrl' => $this->get_geography_boundary_url(),
            'assetsBaseUrl' => BOUNDARY_MAP_PLUGIN_URL . 'assets/',
            'geographyConfig' => $this->load_geography_config(),
            'geographySelection' => $this->get_saved_geography_selection(),
        );

        wp_localize_script(
            'boundary-map-app',
            'BOUNDARY_MAP_CONFIG',
            apply_filters('boundary_map_frontend_config', $front_config, 'global', $this)
        );

        wp_enqueue_style('leaflet');
        wp_enqueue_style('bootstrap-5');
        wp_enqueue_style('boundary-map');

        wp_enqueue_script('leaflet');
        wp_enqueue_script('bootstrap-5');
        wp_enqueue_script('fuse-js');
        wp_enqueue_script('boundary-map-app');
    }

    /**
     * Check if the current page contains one of our shortcodes.
     * Use filter 'boundary_map_force_enqueue' => true to force enqueue (e.g. in templates).
     */
    private function page_has_shortcode()
    {
        if (apply_filters('boundary_map_force_enqueue', false)) {
            return true;
        }

        $post = get_queried_object();
        if (!$post || !isset($post->post_content)) {
            return false;
        }

        return has_shortcode($post->post_content, 'boundary_map')
            || has_shortcode($post->post_content, 'boundary_map_shape')
            || has_shortcode($post->post_content, 'entries_map')
            || has_shortcode($post->post_content, 'entries_map_shape');
    }

    /* ---------------------------------------------------------
     * Helpers to load / save JSON
     * ------------------------------------------------------ */

    private function enqueue_front_for_shortcode($atts = array(), $mode = 'full')
    {
        $atts = shortcode_atts(array(
            'zoom' => '',
            'minzoom' => 10,
            'maxzoom' => 17,
            'scrollwheel' => 0,
            'categorybox' => 1,
            'sidebarpanel' => '',
            'markertag' => '',
            'zoommode' => 'fit',
            'width' => 'fit-container',
            'height' => '600px',
            'country' => '',
            'scope' => '',
            'area' => '',
            'subdivision' => '',
        ), $atts);

        $selection = $this->get_shortcode_geography_selection($atts);

        wp_enqueue_style('leaflet');
        wp_enqueue_style('bootstrap-5');
        wp_enqueue_style('boundary-map');

        wp_enqueue_script('leaflet');
        wp_enqueue_script('bootstrap-5');
        wp_enqueue_script('fuse-js');
        wp_enqueue_script('boundary-map-app');

        $zoom = ($atts['zoom'] !== '' ? floatval($atts['zoom']) : 11.45);
        $show_sidebar_panel = $this->resolve_shortcode_sidebar_panel_setting($atts);
        $marker_tag_mode = $this->resolve_shortcode_marker_tag_mode($atts);

        $front_config = array(
            'mode' => $mode,
            'zoom' => $zoom,
            'minZoom' => intval($atts['minzoom']),
            'maxZoom' => intval($atts['maxzoom']),
            'scrollWheelZoom' => !empty($atts['scrollwheel']) ? true : false,
            'features' => $this->get_feature_flags(),
            'showCategoryBox' => $this->sanitize_shortcode_toggle($atts['categorybox'], true),
            'showSidebarPanel' => $show_sidebar_panel,
            'markerTagMode' => $marker_tag_mode,
            'zoomMode' => $this->sanitize_shortcode_zoom_mode($atts['zoommode']),
            'entriesApiUrl' => rest_url('boundary-map/entries'),
            'entriesUrl' => rest_url('boundary-map/entries'),
            'categoriesApiUrl' => rest_url('boundary-map/categories'),
            'categoriesUrl' => rest_url('boundary-map/categories'),
            'regionUrl' => $this->get_geography_boundary_url($selection),
            'assetsBaseUrl' => BOUNDARY_MAP_PLUGIN_URL . 'assets/',
            'geographyConfig' => $this->load_geography_config(),
            'geographySelection' => $selection,
        );

        wp_localize_script(
            'boundary-map-app',
            'BOUNDARY_MAP_CONFIG',
            apply_filters('boundary_map_frontend_config', $front_config, $mode, $this)
        );
    }

    private function sanitize_shortcode_zoom_mode($value)
    {
        $mode = sanitize_key($value);
        return in_array($mode, array('fit', 'custom'), true) ? $mode : 'fit';
    }

    private function sanitize_shortcode_toggle($value, $default = true)
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return intval($value) === 1;
        }

        $value = strtolower(trim((string) $value));

        if (in_array($value, array('1', 'true', 'yes', 'on'), true)) {
            return true;
        }

        if (in_array($value, array('0', 'false', 'no', 'off'), true)) {
            return false;
        }

        return $default;
    }

    private function resolve_shortcode_sidebar_panel_setting($atts)
    {
        if (isset($atts['sidebarpanel']) && $atts['sidebarpanel'] !== '') {
            return $this->sanitize_shortcode_toggle($atts['sidebarpanel'], true);
        }

        return $this->get_saved_show_sidebar_panel();
    }

    private function sanitize_marker_tag_mode($value)
    {
        $mode = sanitize_key($value);
        return in_array($mode, array('clickable', 'visible'), true) ? $mode : 'clickable';
    }

    private function resolve_shortcode_marker_tag_mode($atts)
    {
        if (isset($atts['markertag']) && $atts['markertag'] !== '') {
            return $this->sanitize_marker_tag_mode($atts['markertag']);
        }

        return $this->get_saved_marker_tag_mode();
    }

    private function sanitize_shortcode_dimension($value, $default, $allow_fit_container = false)
    {
        $value = is_string($value) ? trim($value) : '';

        if ($allow_fit_container && $value === 'fit-container') {
            return 'fit-container';
        }

        if ($value === '') {
            return $default;
        }

        if (is_numeric($value)) {
            return $value . 'px';
        }

        if (preg_match('/^\d+(?:\.\d+)?(px|%|vh|vw|rem|em)$/i', $value)) {
            return $value;
        }

        return $default;
    }

    private function get_shortcode_wrapper_style($atts)
    {
        $width = $this->sanitize_shortcode_dimension(
            isset($atts['width']) ? $atts['width'] : 'fit-container',
            'fit-container',
            true
        );
        $height = $this->sanitize_shortcode_dimension(
            isset($atts['height']) ? $atts['height'] : '600px',
            '600px'
        );

        $max_width = $width === 'fit-container' ? '100%' : $width;

        return sprintf(
            '--ach-map-max-width:%1$s; --ach-map-height:%2$s;',
            esc_attr($max_width),
            esc_attr($height)
        );
    }

    private function get_shortcode_width_mode($atts)
    {
        $width = $this->sanitize_shortcode_dimension(
            isset($atts['width']) ? $atts['width'] : 'fit-container',
            'fit-container',
            true
        );

        return $width === 'fit-container' ? 'fit-container' : 'custom';
    }


    /**
     * @param string|null $url Image URL (can be null/empty).
     * @return string Original URL when valid.
     */
    private function localize_entry_image_url($url)
    {
        return $url;
    }

    /**
     * Load entries (from DB if migrated, else JSON).
     * @param string|null $status_filter 'publish', 'trash', or null for all.
     * @return array Entries keyed by id (DB) or index (JSON).
     */
    private function load_entries($status_filter = null)
    {
        return Boundary_Map_Entry_Service::load_entries($this->entries_file, $status_filter);
    }

    private function save_entries($entries)
    {
        if (!$this->user_can_manage_boundary_map()) {
            return;
        }

        self::flush_rest_cache();

        Boundary_Map_Entry_Service::save_entries($entries, $this->entries_file);
    }

    private function load_categories()
    {
        return Boundary_Map_Category_Service::load_categories($this->categories_file);
    }

    private function save_categories($categories)
    {
        if (!$this->user_can_manage_boundary_map()) {
            return;
        }

        self::flush_rest_cache();

        Boundary_Map_Category_Service::save_categories($categories, $this->categories_file);
    }

    private function count_entries_using_category($category_id)
    {
        return Boundary_Map_Entry_Service::count_entries_using_category($category_id, $this->entries_file);
    }

    private function sync_entries_for_category_change($old_category_id, $new_category_id, $new_category_label)
    {
        if (!$this->user_can_manage_boundary_map()) {
            return;
        }

        self::flush_rest_cache();
        Boundary_Map_Entry_Service::sync_entries_for_category_change(
            $old_category_id,
            $new_category_id,
            $new_category_label,
            $this->entries_file
        );
    }

    private function redirect_to_admin_page($page, $args = array())
    {
        $base_url = add_query_arg('page', $page, admin_url('admin.php'));
        wp_safe_redirect(add_query_arg($args, $base_url));
        exit;
    }

    private function find_category_index_by_id($categories, $category_id)
    {
        return Boundary_Map_Category_Service::find_category_index_by_id($categories, $category_id);
    }

    private function get_category_label_by_id($category_id, $categories = null)
    {
        $categories = is_array($categories) ? $categories : $this->load_categories();

        return Boundary_Map_Category_Service::get_category_label_by_id($category_id, $categories);
    }

    private function has_partial_coords($lat_raw, $lng_raw)
    {
        $lat_has_value = $lat_raw !== null && $lat_raw !== '';
        $lng_has_value = $lng_raw !== null && $lng_raw !== '';
        return $lat_has_value xor $lng_has_value;
    }

    private function coords_are_valid($lat, $lng)
    {
        return is_numeric($lat)
            && is_numeric($lng)
            && floatval($lat) >= -90
            && floatval($lat) <= 90
            && floatval($lng) >= -180
            && floatval($lng) <= 180;
    }

    private function is_valid_image_url($url)
    {
        if ($url === null || $url === '') {
            return true;
        }

        return (bool) wp_http_validate_url($url);
    }

    private function csv_headers_to_keys($headers)
    {
        return Boundary_Map_Csv_Service::csv_headers_to_keys($headers);
    }

    private function parse_uploaded_csv($file_field)
    {
        return Boundary_Map_Csv_Service::parse_uploaded_csv($file_field);
    }

    private function send_csv_download($filename, $headers, $rows)
    {
        Boundary_Map_Csv_Service::send_csv_download($filename, $headers, $rows);
    }

    private function get_default_geography_config()
    {
        return Boundary_Map_Geography_Service::get_default_config();
    }

    private static function get_default_geography_config_static()
    {
        return Boundary_Map_Geography_Service::get_default_config();
    }

    private function load_geography_config()
    {
        return Boundary_Map_Geography_Service::load_config();
    }

    private static function load_geography_config_for_activation()
    {
        return Boundary_Map_Geography_Service::load_config_for_activation();
    }

    private function get_geography_selection_option_name()
    {
        return Boundary_Map_Settings_Service::get_geography_selection_option_name();
    }

    private static function get_geography_selection_option_name_static()
    {
        return Boundary_Map_Settings_Service::get_geography_selection_option_name();
    }

    private function get_sidebar_panel_option_name()
    {
        return Boundary_Map_Settings_Service::get_sidebar_panel_option_name();
    }

    private static function get_sidebar_panel_option_name_static()
    {
        return Boundary_Map_Settings_Service::get_sidebar_panel_option_name();
    }

    private function get_saved_show_sidebar_panel()
    {
        return Boundary_Map_Settings_Service::get_saved_show_sidebar_panel(
            function ($value, $default = true) {
                return $this->sanitize_shortcode_toggle($value, $default);
            }
        );
    }

    private function get_marker_tag_mode_option_name()
    {
        return Boundary_Map_Settings_Service::get_marker_tag_mode_option_name();
    }

    private static function get_marker_tag_mode_option_name_static()
    {
        return Boundary_Map_Settings_Service::get_marker_tag_mode_option_name();
    }

    private function get_saved_marker_tag_mode()
    {
        return Boundary_Map_Settings_Service::get_saved_marker_tag_mode(
            function ($value) {
                return $this->sanitize_marker_tag_mode($value);
            }
        );
    }

    private function get_geography_scope_by_id($config, $scope_id)
    {
        if (empty($config['scopes']) || !is_array($config['scopes'])) {
            return null;
        }

        foreach ($config['scopes'] as $scope) {
            if (!empty($scope['id']) && $scope['id'] === $scope_id) {
                return $scope;
            }
        }

        return null;
    }

    private function get_geography_country_node($config)
    {
        if (!empty($config['country']) && is_array($config['country'])) {
            return $config['country'];
        }

        return array(
            'id' => 'australia',
            'label' => 'Australia',
            'displayName' => 'Australia',
            'geojsonUrl' => 'https://geo.abs.gov.au/arcgis/rest/services/ASGS2021/AUS/MapServer/0/query?where=1%3D1&outFields=AUS_NAME_2021&returnGeometry=true&outSR=4326&f=geojson',
            'color' => '#4B7CD5',
        );
    }

    private function get_geography_child_by_id($children, $child_id)
    {
        if (empty($children) || !is_array($children)) {
            return null;
        }

        foreach ($children as $child) {
            if (!empty($child['id']) && $child['id'] === $child_id) {
                return $child;
            }
        }

        return null;
    }

    private function find_geography_nested_path_by_id($scope, $node_id)
    {
        if (empty($node_id) || empty($scope['children']) || !is_array($scope['children'])) {
            return null;
        }

        foreach ($scope['children'] as $area) {
            if (empty($area['children']) || !is_array($area['children'])) {
                continue;
            }

            foreach ($area['children'] as $subdivision) {
                if (!empty($subdivision['id']) && $subdivision['id'] === $node_id) {
                    return array(
                        'area' => $area,
                        'subdivision' => $subdivision,
                    );
                }
            }
        }

        return null;
    }

    private function get_default_geography_selection()
    {
        return Boundary_Map_Geography_Service::get_default_selection($this->load_geography_config());
    }

    private static function get_activation_default_geography_selection()
    {
        return Boundary_Map_Geography_Service::get_activation_default_selection();
    }

    private function sanitize_geography_selection($selection)
    {
        return Boundary_Map_Geography_Service::sanitize_selection(
            $selection,
            $this->load_geography_config(),
            $this->get_default_geography_selection()
        );
    }

    private function get_saved_geography_selection()
    {
        return Boundary_Map_Settings_Service::get_saved_geography_selection(
            $this->get_default_geography_selection(),
            function ($selection) {
                return $this->sanitize_geography_selection($selection);
            }
        );
    }

    private function get_shortcode_geography_selection($atts)
    {
        return Boundary_Map_Geography_Service::get_shortcode_selection(
            $atts,
            $this->get_saved_geography_selection(),
            $this->load_geography_config()
        );
    }

    private function get_selected_geography_node($selection = null)
    {
        return Boundary_Map_Geography_Service::get_selected_node(
            $selection,
            $this->load_geography_config(),
            $this->get_saved_geography_selection()
        );
    }

    private function get_geography_boundary_url($selection = null)
    {
        return Boundary_Map_Geography_Service::get_boundary_url(
            $selection,
            $this->load_geography_config(),
            $this->get_saved_geography_selection()
        );
    }

    private function get_geography_controls_html()
    {
        return Boundary_Map_Geography_Controls_Component::render();
    }

    /* ---------------------------------------------------------
     * Admin Menu / Assets
     * ------------------------------------------------------ */

    private function user_can_manage_boundary_map()
    {
        return Boundary_Map_Access_Service::can_manage_plugin();
    }

    public function register_admin_menu()
    {
        $manage_capability = Boundary_Map_Access_Service::get_manage_capability();

        add_menu_page(
            __('Australian Boundary Map', 'boundary-map'),
            __('Boundary Map', 'boundary-map'),
            $manage_capability,
            'boundary-map-entries',
            array($this, 'render_all_entries_page'),
            'dashicons-location-alt',
            26
        );

        add_submenu_page(
            'boundary-map-entries',
            __('All Entries', 'boundary-map'),
            __('All Entries', 'boundary-map'),
            $manage_capability,
            'boundary-map-entries',
            array($this, 'render_all_entries_page')
        );

        add_submenu_page(
            'boundary-map-entries',
            __('Add Entry', 'boundary-map'),
            __('Add Entry', 'boundary-map'),
            $manage_capability,
            'boundary-map-add-entry',
            array($this, 'render_add_entry_page')
        );

        add_submenu_page(
            'boundary-map-entries',
            __('Categories', 'boundary-map'),
            __('Categories', 'boundary-map'),
            $manage_capability,
            'boundary-map-categories',
            array($this, 'render_categories_page')
        );

        add_submenu_page(
            'boundary-map-entries',
            __('Config', 'boundary-map'),
            __('Config', 'boundary-map'),
            $manage_capability,
            'boundary-map-config',
            array($this, 'render_config_page')
        );

        add_submenu_page(
            'boundary-map-entries',
            __('Tools', 'boundary-map'),
            __('Tools', 'boundary-map'),
            $manage_capability,
            'boundary-map-tools',
            array($this, 'render_tools_page')
        );

        add_submenu_page(
            'boundary-map-entries',
            __('Information', 'boundary-map'),
            __('Information', 'boundary-map'),
            $manage_capability,
            'boundary-map-information',
            array($this, 'render_information_page')
        );
    }

    /**
     * Remove only the wp-menu-separator li that creates the gap after Boundary Map.
     */
    public function remove_admin_menu_separator()
    {
        ?>
        <script>
        (function() {
            var menuItem = document.getElementById('toplevel_page_boundary-map-entries');
            if (!menuItem) return;
            var next = menuItem.nextElementSibling;
            while (next) {
                if (next.classList && next.classList.contains('wp-menu-separator')) {
                    next.remove();
                    break;
                }
                next = next.nextElementSibling;
            }
        })();
        </script>
        <?php
    }

    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'boundary-map') === false) {
            return;
        }

        $admin_style_version = $this->get_asset_version('assets/admin.css');
        $admin_script_version = $this->get_asset_version('assets/admin.js');

        // Media library for image upload/select
        wp_enqueue_media();

        // WP colour picker
        wp_enqueue_style('wp-color-picker');
        // Leaflet for mini admin map
        wp_enqueue_style(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
            array(),
            '1.9.4'
        );
        wp_enqueue_script(
            'leaflet',
            'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            array(),
            '1.9.4',
            true
        );

        wp_enqueue_style(
            'boundary-map-admin',
            BOUNDARY_MAP_PLUGIN_URL . 'assets/admin.css',
            array('wp-color-picker'),
            $admin_style_version
        );

        wp_enqueue_script(
            'boundary-map-admin',
            BOUNDARY_MAP_PLUGIN_URL . 'assets/admin.js',
            array('jquery', 'leaflet', 'wp-color-picker'),
            $admin_script_version,
            true
        );

        $admin_config = array(
            'regionUrl' => $this->get_geography_boundary_url(),
            'assetsBaseUrl' => BOUNDARY_MAP_PLUGIN_URL . 'assets/',
            'categories' => $this->load_categories(),
            'features' => $this->get_feature_flags(),
            'geographyConfig' => $this->load_geography_config(),
            'geographySelection' => $this->get_saved_geography_selection(),
        );

        wp_localize_script(
            'boundary-map-admin',
            'BOUNDARY_MAP_ADMIN',
            apply_filters('boundary_map_admin_config', $admin_config, $hook, $this)
        );
    }

    public function render_support_dashboard_notice()
    {
        if (!$this->user_can_manage_boundary_map()) {
            return;
        }

        global $pagenow;

        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        $is_wordpress_dashboard = $pagenow === 'index.php';
        $is_plugin_home = $page === 'boundary-map-entries';

        if (!$is_wordpress_dashboard && !$is_plugin_home) {
            return;
        }
        ?>
        <div class="notice notice-info ach-support-notice">
            <p>
                <span class="ach-support-badge" style="display:inline-flex;align-items:center;justify-content:center;padding:4px 10px;border-radius:999px;background:#fff1c2;color:#7a4b00;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;">
                    <?php esc_html_e('Support', 'boundary-map'); ?>
                </span>
                <?php esc_html_e('Do you like the plugin? Support me and help keep Boundary Map improving.', 'boundary-map'); ?>
                <a href="<?php echo esc_url($this->get_support_url()); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('Buy Me a Coffee', 'boundary-map'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    /* ---------------------------------------------------------
     * Public REST data endpoints
     * ------------------------------------------------------ */

    public function register_rest_routes()
    {
        register_rest_route(
            'boundary-map',
            '/entries',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_get_entries'),
                'permission_callback' => array($this, 'rest_can_read_public_data'),
            )
        );

        register_rest_route(
            'boundary-map',
            '/categories',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_get_categories'),
                'permission_callback' => array($this, 'rest_can_read_public_data'),
            )
        );

        register_rest_route(
            'entries-map',
            '/entries',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_get_entries'),
                'permission_callback' => array($this, 'rest_can_read_public_data'),
            )
        );

        register_rest_route(
            'entries-map',
            '/categories',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_get_categories'),
                'permission_callback' => array($this, 'rest_can_read_public_data'),
            )
        );
    }

    public function rest_can_read_public_data($request)
    {
        return Boundary_Map_Access_Service::can_read_rest_collection($request, 'public');
    }

    public function rest_get_entries($request)
    {
        $cached = get_transient('boundary_map_entries');
        if ($cached !== false) {
            return rest_ensure_response($cached);
        }
        $entries = array_values($this->load_entries('publish'));
        $entries = apply_filters('boundary_map_rest_entries', $entries, $request, $this);
        set_transient('boundary_map_entries', $entries, HOUR_IN_SECONDS);
        return rest_ensure_response($entries);
    }

    public function rest_get_categories($request)
    {
        $cached = get_transient('boundary_map_categories');
        if ($cached !== false) {
            return rest_ensure_response($cached);
        }
        $categories = $this->load_categories();
        $categories = apply_filters('boundary_map_rest_categories', $categories, $request, $this);
        set_transient('boundary_map_categories', $categories, HOUR_IN_SECONDS);
        return rest_ensure_response($categories);
    }

    public static function flush_rest_cache()
    {
        delete_transient('boundary_map_entries');
        delete_transient('boundary_map_categories');
    }

    /* ---------------------------------------------------------
     * Entries admin pages
     * ------------------------------------------------------ */

    /**
     * All Entries – list table only.
     */
    public function render_all_entries_page()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        $all_entries = $this->load_entries();
        $status_filter = isset($_GET['post_status']) ? sanitize_text_field($_GET['post_status']) : 'publish';
        if (!in_array($status_filter, array('', 'publish', 'trash'), true)) {
            $status_filter = '';
        }
        $bulk_action_error = false;

        // Single trash/restore/delete are handled in handle_single_entry_actions() on admin_init (before any output)

        if (isset($_GET['bulk_error'])) {
            $bulk_action_error = true;
        }

        $all_entries = $this->load_entries();

        // Apply status filter (preserve keys: id for DB, index for JSON)
        $entries_by_status = array('publish' => array(), 'trash' => array());
        foreach ($all_entries as $key => $ev) {
            $status = isset($ev['status']) ? $ev['status'] : 'publish';
            $entries_by_status[$status][$key] = $ev;
        }
        $published = $entries_by_status['publish'];
        $trashed = $entries_by_status['trash'];

        // Which list to show
        $entries = array();
        if ($status_filter === 'trash') {
            $entries = $trashed;
        } elseif ($status_filter === 'publish') {
            $entries = $published;
        } else {
            $entries = $all_entries;
        }

        // Apply search filter (preserve original indices)
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        if ($search !== '') {
            $search_lower = strtolower($search);
            $entries = array_filter($entries, function ($ev) use ($search_lower) {
                $title = isset($ev['title']) ? strtolower($ev['title']) : '';
                $category = isset($ev['category']) ? strtolower($ev['category']) : (isset($ev['categoryId']) ? strtolower($ev['categoryId']) : '');
                $location = isset($ev['location']) ? strtolower($ev['location']) : '';
                $description = isset($ev['description']) ? strtolower($ev['description']) : '';
                return strpos($title, $search_lower) !== false || strpos($category, $search_lower) !== false
                    || strpos($location, $search_lower) !== false || strpos($description, $search_lower) !== false;
            });
        }

        // Sort
        $orderby = isset($_GET['orderby']) ? sanitize_key($_GET['orderby']) : 'title';
        $allowed_orderby = array('title', 'category', 'location', 'coords', 'id');
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'title';
        }
        $order = isset($_GET['order']) && strtolower($_GET['order']) === 'desc' ? 'desc' : 'asc';

        uasort($entries, function ($a, $b) use ($orderby, $order) {
            $va = '';
            $vb = '';
            switch ($orderby) {
                case 'title':
                    $va = isset($a['title']) ? strtolower($a['title']) : '';
                    $vb = isset($b['title']) ? strtolower($b['title']) : '';
                    break;
                case 'category':
                    $va = isset($a['category']) ? strtolower($a['category']) : (isset($a['categoryId']) ? strtolower($a['categoryId']) : '');
                    $vb = isset($b['category']) ? strtolower($b['category']) : (isset($b['categoryId']) ? strtolower($b['categoryId']) : '');
                    break;
                case 'location':
                    $va = isset($a['location']) ? strtolower($a['location']) : '';
                    $vb = isset($b['location']) ? strtolower($b['location']) : '';
                    break;
                case 'coords':
                    $va = !empty($a['coords']) && is_array($a['coords']) ? 1 : 0;
                    $vb = !empty($b['coords']) && is_array($b['coords']) ? 1 : 0;
                    break;
                case 'id':
                    $va = isset($a['id']) ? intval($a['id']) : 0;
                    $vb = isset($b['id']) ? intval($b['id']) : 0;
                    break;
            }
            $cmp = is_numeric($va) && is_numeric($vb) ? $va <=> $vb : strcmp($va, $vb);
            return $order === 'asc' ? $cmp : -$cmp;
        });

        $add_url = add_query_arg('page', 'boundary-map-add-entry', admin_url('admin.php'));
        $tools_url = add_query_arg('page', 'boundary-map-tools', admin_url('admin.php'));
        $base_url = add_query_arg('page', 'boundary-map-entries', admin_url('admin.php'));
        $list_url = add_query_arg(array('orderby' => $orderby, 'order' => $order), $base_url);
        if ($search !== '') $list_url = add_query_arg('s', $search, $list_url);

        $sort_url = function ($col) use ($list_url, $status_filter, $orderby, $order) {
            $args = array('orderby' => $col, 'order' => ($orderby === $col && $order === 'asc') ? 'desc' : 'asc');
            if ($status_filter) $args['post_status'] = $status_filter;
            return add_query_arg($args, $list_url);
        };

        ?>
        <div class="wrap ach-map-admin">
            <h1 class="wp-heading-inline"><?php
                echo $status_filter === 'trash' ? esc_html__('Entries in Trash', 'boundary-map') : esc_html__('All Entries', 'boundary-map');
            ?></h1>
            <?php if ($status_filter !== 'trash'): ?>
            <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add Entry', 'boundary-map'); ?></a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php if ($status_filter !== 'trash' && $search === '' && empty($published)): ?>
                <div class="ach-entry-card ach-empty-state-card">
                    <div class="ach-entry-card__header">
                        <h2><?php esc_html_e('Start Your First Map', 'boundary-map'); ?></h2>
                        <p><?php esc_html_e('This site does not have any published entries yet. Add one manually or import a CSV to get the public map live.', 'boundary-map'); ?></p>
                    </div>
                    <p>
                        <a href="<?php echo esc_url($add_url); ?>" class="button button-primary"><?php esc_html_e('Add First Entry', 'boundary-map'); ?></a>
                        <a href="<?php echo esc_url($tools_url); ?>" class="button button-secondary"><?php esc_html_e('Open Import / Export Tools', 'boundary-map'); ?></a>
                    </p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['trashed']) && $_GET['trashed']): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    $n = intval($_GET['trashed']);
                    printf(_n('Entry moved to trash.', '%d entries moved to trash.', $n, 'boundary-map'), $n);
                    ?>
                </p></div>
            <?php elseif (isset($_GET['untrashed']) && $_GET['untrashed']): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    $n = intval($_GET['untrashed']);
                    printf(_n('Entry restored.', '%d entries restored.', $n, 'boundary-map'), $n);
                    ?>
                </p></div>
            <?php elseif (isset($_GET['deleted']) && $_GET['deleted']): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    $n = intval($_GET['deleted']);
                    printf(_n('Entry permanently deleted.', '%d entries permanently deleted.', $n, 'boundary-map'), $n);
                    ?>
                </p></div>
            <?php elseif (isset($_GET['added']) && $_GET['added'] === '1'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Entry added.', 'boundary-map'); ?></p></div>
            <?php elseif (isset($_GET['updated']) && $_GET['updated'] === '1'): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Entry updated.', 'boundary-map'); ?></p></div>
            <?php elseif (!empty($bulk_action_error) || isset($_GET['bulk_error'])): ?>
                <div class="notice notice-warning is-dismissible"><p>
                    <?php
                    echo esc_html(
                        isset($_GET['bulk_error']) && $_GET['bulk_error'] === 'no_items'
                            ? __('Please select one or more entries.', 'boundary-map')
                            : __('Please select a bulk action (Edit or Move to Trash) and try again.', 'boundary-map')
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <ul class="subsubsub">
                <li>
                    <a href="<?php echo esc_url($list_url); ?>" class="<?php echo $status_filter === '' ? 'current' : ''; ?>">
                        <?php printf(esc_html__('All (%d)', 'boundary-map'), count($all_entries)); ?>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('post_status', 'publish', $list_url)); ?>" class="<?php echo $status_filter === 'publish' ? 'current' : ''; ?>">
                        <?php printf(esc_html__('Published (%d)', 'boundary-map'), count($published)); ?>
                    </a> |
                </li>
                <li>
                    <a href="<?php echo esc_url(add_query_arg('post_status', 'trash', $list_url)); ?>" class="<?php echo $status_filter === 'trash' ? 'current' : ''; ?>">
                        <?php printf(esc_html__('Trash (%d)', 'boundary-map'), count($trashed)); ?>
                    </a>
                </li>
                <?php if ($search !== ''): ?>
                    <li> | <span class="current"><?php printf(esc_html__('Search results (%d)', 'boundary-map'), count($entries)); ?></span></li>
                <?php endif; ?>
            </ul>

            <form method="get" class="search-form" style="float:right; margin: 0 0 10px 0;">
                <input type="hidden" name="page" value="boundary-map-entries" />
                <?php if ($status_filter): ?><input type="hidden" name="post_status" value="<?php echo esc_attr($status_filter); ?>" /><?php endif; ?>
                <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>" />
                <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>" />
                <p class="search-box">
                    <label class="screen-reader-text" for="ach-search-input"><?php esc_html_e('Search Entries', 'boundary-map'); ?></label>
                    <input type="search" id="ach-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search entries...', 'boundary-map'); ?>" />
                    <input type="submit" id="search-submit" class="button" value="<?php esc_attr_e('Search Entries', 'boundary-map'); ?>" />
                </p>
            </form>

            <form method="post" id="ach-bulk-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="clear:both;">
                <input type="hidden" name="action" value="boundary_map_bulk_entries" />
                <?php if ($status_filter): ?><input type="hidden" name="post_status" value="<?php echo esc_attr($status_filter); ?>" /><?php endif; ?>
                <?php wp_nonce_field('boundary_map_bulk_action'); ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label for="ach-bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'boundary-map'); ?></label>
                        <select name="boundary_map_bulk_action_top" id="boundary-map-bulk-action-selector-top">
                            <option value="-1"><?php esc_html_e('Bulk actions', 'boundary-map'); ?></option>
                            <?php if ($status_filter === 'trash'): ?>
                                <option value="untrash"><?php esc_html_e('Restore', 'boundary-map'); ?></option>
                                <option value="delete_permanently"><?php esc_html_e('Delete Permanently', 'boundary-map'); ?></option>
                            <?php else: ?>
                                <option value="edit"><?php esc_html_e('Edit', 'boundary-map'); ?></option>
                                <option value="trash"><?php esc_html_e('Move to Trash', 'boundary-map'); ?></option>
                            <?php endif; ?>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'boundary-map'); ?>" />
                    </div>
                </div>

                <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <td class="check-column"><input type="checkbox" id="ach-select-all" /></td>
                        <th scope="col" class="sortable <?php echo $orderby === 'title' ? 'sorted ' . esc_attr($order) : ''; ?>">
                            <a href="<?php echo esc_url($sort_url('title')); ?>">
                                <span><?php esc_html_e('Title', 'boundary-map'); ?></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th scope="col" class="sortable <?php echo $orderby === 'category' ? 'sorted ' . esc_attr($order) : ''; ?>">
                            <a href="<?php echo esc_url($sort_url('category')); ?>">
                                <span><?php esc_html_e('Category', 'boundary-map'); ?></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th scope="col" class="sortable <?php echo $orderby === 'location' ? 'sorted ' . esc_attr($order) : ''; ?>">
                            <a href="<?php echo esc_url($sort_url('location')); ?>">
                                <span><?php esc_html_e('Location', 'boundary-map'); ?></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th scope="col" class="sortable <?php echo $orderby === 'coords' ? 'sorted ' . esc_attr($order) : ''; ?>">
                            <a href="<?php echo esc_url($sort_url('coords')); ?>">
                                <span><?php esc_html_e('Has Coords?', 'boundary-map'); ?></span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th><?php esc_html_e('Actions', 'boundary-map'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($entries)): ?>
                        <tr>
                            <td colspan="6"><?php
                                if ($search !== '') {
                                    esc_html_e('No entries match your search.', 'boundary-map');
                                } elseif ($status_filter === 'trash') {
                                    esc_html_e('No entries in trash.', 'boundary-map');
                                } elseif ($status_filter === 'publish') {
                                    esc_html_e('No published entries yet.', 'boundary-map');
                                } else {
                                    esc_html_e('No entries yet.', 'boundary-map');
                                }
                            ?></td>
                        </tr>
                    <?php else: ?>
                        <?php
                        foreach ($entries as $key => $ev) {
                            $id = isset($ev['id']) ? intval($ev['id']) : $key;
                            $edit_url = add_query_arg(array(
                                'page' => 'boundary-map-add-entry',
                                'action' => 'edit',
                                'id' => $id,
                            ), admin_url('admin.php'));
                            if ($status_filter === 'trash') {
                                $restore_url = wp_nonce_url(add_query_arg(array(
                                    'page' => 'boundary-map-entries',
                                    'action' => 'untrash',
                                    'id' => $id,
                                ), admin_url('admin.php')), 'boundary_map_restore_entry_' . $id);
                                $delete_url = wp_nonce_url(add_query_arg(array(
                                    'page' => 'boundary-map-entries',
                                    'action' => 'delete',
                                    'id' => $id,
                                ), admin_url('admin.php')), 'boundary_map_delete_entry_' . $id);
                            } else {
                                $trash_url = wp_nonce_url(add_query_arg(array(
                                    'page' => 'boundary-map-entries',
                                    'action' => 'trash',
                                    'id' => $id,
                                ), admin_url('admin.php')), 'boundary_map_trash_entry_' . $id);
                            }
                            ?>
                            <tr>
                                <th scope="row" class="check-column">
                                    <input type="checkbox" name="entry[]" value="<?php echo esc_attr($id); ?>" class="ach-row-checkbox" />
                                </th>
                                <td><?php echo esc_html(isset($ev['title']) ? $ev['title'] : ''); ?></td>
                                <td><?php echo esc_html(isset($ev['category']) ? $ev['category'] : (isset($ev['categoryId']) ? $ev['categoryId'] : '')); ?></td>
                                <td><?php echo esc_html(isset($ev['location']) ? $ev['location'] : ''); ?></td>
                                <td><?php echo !empty($ev['coords']) && is_array($ev['coords']) ? esc_html__('Yes', 'boundary-map') : esc_html__('No', 'boundary-map'); ?></td>
                                <td>
                                    <?php if ($status_filter === 'trash'): ?>
                                        <a class="button button-small" href="<?php echo esc_url($restore_url); ?>"><?php esc_html_e('Restore', 'boundary-map'); ?></a>
                                        <a class="button button-small button-link-delete" href="<?php echo esc_url($delete_url); ?>"
                                            onclick="return confirm('<?php echo esc_js(__('Delete this entry permanently? This cannot be undone.', 'boundary-map')); ?>');"><?php esc_html_e('Delete Permanently', 'boundary-map'); ?></a>
                                    <?php else: ?>
                                        <a class="button button-small" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'boundary-map'); ?></a>
                                        <a class="button button-small button-link-delete" href="<?php echo esc_url($trash_url); ?>"><?php esc_html_e('Trash', 'boundary-map'); ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php endif; ?>
                </tbody>
                </table>

                <?php if (!empty($entries)): ?>
                <div class="tablenav bottom">
                    <div class="alignleft actions bulkactions">
                        <label for="ach-bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'boundary-map'); ?></label>
                        <select name="boundary_map_bulk_action_bottom" id="boundary-map-bulk-action-selector-bottom">
                            <option value="-1"><?php esc_html_e('Bulk actions', 'boundary-map'); ?></option>
                            <?php if ($status_filter === 'trash'): ?>
                                <option value="untrash"><?php esc_html_e('Restore', 'boundary-map'); ?></option>
                                <option value="delete_permanently"><?php esc_html_e('Delete Permanently', 'boundary-map'); ?></option>
                            <?php else: ?>
                                <option value="edit"><?php esc_html_e('Edit', 'boundary-map'); ?></option>
                                <option value="trash"><?php esc_html_e('Move to Trash', 'boundary-map'); ?></option>
                            <?php endif; ?>
                        </select>
                        <input type="submit" class="button action" value="<?php esc_attr_e('Apply', 'boundary-map'); ?>" />
                    </div>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <script>
        (function() {
            var form = document.getElementById('ach-bulk-form');
            if (!form) return;
            var selectAll = document.getElementById('ach-select-all');
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    form.querySelectorAll('.ach-row-checkbox').forEach(function(cb) { cb.checked = selectAll.checked; });
                });
            }
            var topSel = form.querySelector('[name="boundary_map_bulk_action_top"]');
            var bottomSel = form.querySelector('[name="boundary_map_bulk_action_bottom"]');
            if (topSel && bottomSel) {
                topSel.addEventListener('change', function() { bottomSel.value = this.value; });
                bottomSel.addEventListener('change', function() { topSel.value = this.value; });
            }
            form.addEventListener('submit', function(e) {
                var action = (topSel && topSel.value !== '-1') ? topSel.value : (bottomSel && bottomSel.value !== '-1') ? bottomSel.value : '-1';
                if (action === '-1') {
                    e.preventDefault();
                    alert('<?php echo esc_js(__('Please select a bulk action.', 'boundary-map')); ?>');
                    return false;
                }
                var checked = form.querySelectorAll('.ach-row-checkbox:checked');
                if (checked.length === 0) {
                    e.preventDefault();
                    alert('<?php echo esc_js(__('Please select one or more entries.', 'boundary-map')); ?>');
                    return false;
                }
                if (action === 'trash' && !confirm('<?php echo esc_js(__('Move selected entries to trash?', 'boundary-map')); ?>')) {
                    e.preventDefault();
                    return false;
                }
                if (action === 'delete_permanently' && !confirm('<?php echo esc_js(__('Delete selected entries permanently? This cannot be undone.', 'boundary-map')); ?>')) {
                    e.preventDefault();
                    return false;
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Add/Edit Entry – form only.
     */
    public function render_add_entry_page()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        $entries = $this->load_entries();
        $categories = $this->load_categories();

        // Form submission is handled in handle_entry_form_submit() on admin_init (before any output)
        $edit_entry = null;
        $edit_id = null;
        if (isset($_GET['action']) && $_GET['action'] === 'edit') {
            $req_id = isset($_GET['id']) ? intval($_GET['id']) : (isset($_GET['index']) ? intval($_GET['index']) : -1);
            if ($req_id >= 0 && isset($entries[$req_id])) {
                $edit_id = $req_id;
                $edit_entry = $entries[$req_id];
            }
        }

        $list_url = add_query_arg('page', 'boundary-map-entries', admin_url('admin.php'));
        $geography_selection = $this->get_saved_geography_selection();
        $selected_boundary_node = $this->get_selected_geography_node($geography_selection);
        if (empty($geography_selection['scope'])) {
            $selected_boundary_node = $this->get_geography_country_node($this->load_geography_config());
        }
        $selected_boundary_label = $selected_boundary_node && !empty($selected_boundary_node['displayName'])
            ? $selected_boundary_node['displayName']
            : __('Australia', 'boundary-map');

        ?>
        <div class="wrap ach-map-admin">
            <h1><?php echo $edit_entry ? esc_html__('Edit Entry', 'boundary-map') : esc_html__('Add New Entry', 'boundary-map'); ?></h1>
            <a href="<?php echo esc_url($list_url); ?>" class="page-title-action"><?php esc_html_e('&larr; Back to All Entries', 'boundary-map'); ?></a>

            <?php if (isset($_GET['entry_error'])): ?>
                <div class="notice notice-error is-dismissible"><p>
                    <?php
                    $error_code = sanitize_key(wp_unslash($_GET['entry_error']));
                    switch ($error_code) {
                        case 'missing_title':
                            esc_html_e('Please enter a title before saving this entry.', 'boundary-map');
                            break;
                        case 'invalid_coords':
                            esc_html_e('Please enter both latitude and longitude, and keep them within valid ranges.', 'boundary-map');
                            break;
                        case 'invalid_image':
                            esc_html_e('Please use a valid http or https image URL.', 'boundary-map');
                            break;
                        case 'invalid_category':
                            esc_html_e('Please choose a valid category before saving this entry.', 'boundary-map');
                            break;
                        default:
                            esc_html_e('This entry could not be saved. Please review the form and try again.', 'boundary-map');
                    }
                    ?>
                </p></div>
            <?php endif; ?>

            <form method="post" class="ach-form ach-entry-form">
                <?php wp_nonce_field('boundary_map_save_entry'); ?>
                <input type="hidden" name="entry_id" value="<?php echo ($edit_id !== null && $edit_id !== '') ? esc_attr($edit_id) : ''; ?>" />

                <?php
                $lat = '';
                $lng = '';
                if ($edit_entry && !empty($edit_entry['coords']) && is_array($edit_entry['coords'])) {
                    $lat = $edit_entry['coords'][0];
                    $lng = $edit_entry['coords'][1];
                }
                ?>

                <div class="ach-entry-layout">
                    <div class="ach-entry-main">
                        <section class="ach-entry-card">
                            <div class="ach-entry-card__header">
                                <h2><?php esc_html_e('Entry Details', 'boundary-map'); ?></h2>
                                <p><?php esc_html_e('Add the core information for this entry.', 'boundary-map'); ?></p>
                            </div>

                            <div class="ach-entry-fields">
                                <div class="ach-entry-field ach-entry-field--span-2">
                                    <label for="ach-title"><?php esc_html_e('Title', 'boundary-map'); ?></label>
                                    <input name="title" id="ach-title" type="text" class="regular-text" required
                                        value="<?php echo $edit_entry ? esc_attr($edit_entry['title']) : ''; ?>" />
                                </div>

                                <div class="ach-entry-field">
                                    <label for="ach-category"><?php esc_html_e('Category', 'boundary-map'); ?></label>
                                    <select name="category_id" id="ach-category">
                                        <?php
                                        foreach ($categories as $cat) {
                                            $id = isset($cat['id']) ? $cat['id'] : '';
                                            $label = isset($cat['label']) ? $cat['label'] : $id;
                                            if ($id === 'All') continue;
                                            printf(
                                                '<option value="%1$s"%3$s>%2$s</option>',
                                                esc_attr($id),
                                                esc_html($label),
                                                $edit_entry && isset($edit_entry['categoryId']) && $edit_entry['categoryId'] === $id ? ' selected' : ''
                                            );
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div class="ach-entry-field">
                                    <label for="ach-location"><?php esc_html_e('Location', 'boundary-map'); ?></label>
                                    <input name="location" id="ach-location" type="text" class="regular-text"
                                        value="<?php echo $edit_entry ? esc_attr($edit_entry['location']) : ''; ?>" />
                                </div>

                                <div class="ach-entry-field ach-entry-field--span-2">
                                    <label for="ach-description"><?php esc_html_e('Description', 'boundary-map'); ?></label>
                                    <textarea name="description" id="ach-description" rows="7" class="large-text"><?php
                                    echo $edit_entry ? esc_textarea($edit_entry['description']) : '';
                                    ?></textarea>
                                </div>
                            </div>
                        </section>

                        <section class="ach-entry-card">
                            <div class="ach-entry-card__header">
                                <h2><?php esc_html_e('Image', 'boundary-map'); ?></h2>
                                <p><?php esc_html_e('Choose or upload an image from the Media Library.', 'boundary-map'); ?></p>
                            </div>

                            <div class="ach-entry-image-row">
                                <input name="image" id="ach-image" type="text" class="regular-text"
                                    value="<?php echo $edit_entry ? esc_url($edit_entry['image']) : ''; ?>" />
                                <button type="button" class="button" id="ach-image-btn">
                                    <?php esc_html_e('Upload / Select Image', 'boundary-map'); ?>
                                </button>
                            </div>

                            <div id="ach-image-preview" class="ach-entry-image-preview">
                                <?php if (!empty($edit_entry['image'])): ?>
                                    <img src="<?php echo esc_url($edit_entry['image']); ?>" alt=""
                                        style="max-width:200px;height:auto;display:block;border:1px solid #ddd;padding:2px;" />
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>

                    <div class="ach-entry-sidebar">
                        <section class="ach-entry-card ach-entry-boundary-card">
                            <div class="ach-entry-card__header">
                                <h2><?php esc_html_e('Boundary', 'boundary-map'); ?></h2>
                                <p><?php esc_html_e('Choose the active boundary for this entry and for the default plugin settings.', 'boundary-map'); ?></p>
                            </div>

                            <div class="ach-entry-boundary-grid">
                                <div class="ach-entry-boundary-field">
                                    <label for="ach-entry-country"><?php esc_html_e('Country', 'boundary-map'); ?></label>
                                    <select id="ach-entry-country" name="country">
                                        <option value="australia" selected><?php esc_html_e('Australia', 'boundary-map'); ?></option>
                                    </select>
                                </div>
                                <div class="ach-entry-boundary-field">
                                    <label for="ach-entry-scope"><?php esc_html_e('Boundary Level', 'boundary-map'); ?></label>
                                    <select id="ach-entry-scope" name="scope"></select>
                                </div>
                                <div class="ach-entry-boundary-field">
                                    <label for="ach-entry-area" id="ach-entry-area-label"><?php esc_html_e('State or Territory', 'boundary-map'); ?></label>
                                    <select id="ach-entry-area" name="area"></select>
                                </div>
                                <div class="ach-entry-boundary-field" id="ach-entry-subdivision-wrap" hidden>
                                    <label for="ach-entry-subdivision" id="ach-entry-subdivision-label"><?php esc_html_e('Region / Division', 'boundary-map'); ?></label>
                                    <select id="ach-entry-subdivision" name="subdivision"></select>
                                </div>
                            </div>

                            <p class="description">
                                <?php esc_html_e('This mirrors the current boundary setting and will update it when you save this entry. The map below updates as you change the selection.', 'boundary-map'); ?>
                            </p>
                            <p class="ach-entry-boundary-summary" id="ach-entry-boundary-title">
                                <?php
                                printf(
                                    esc_html__('Current boundary: %s', 'boundary-map'),
                                    esc_html($selected_boundary_label)
                                );
                                ?>
                            </p>
                        </section>

                        <section class="ach-entry-card">
                            <div class="ach-entry-card__header">
                                <h2><?php esc_html_e('Map & Coordinates', 'boundary-map'); ?></h2>
                                <p><?php esc_html_e('Geocode from the location, or drag and click directly on the map.', 'boundary-map'); ?></p>
                            </div>

                            <div class="ach-entry-coords-row">
                                <input name="lat" id="ach-lat" type="text" class="medium-text" placeholder="-33.6"
                                    value="<?php echo esc_attr($lat); ?>" />
                                <input name="lng" id="ach-lng" type="text" class="medium-text" placeholder="151.1"
                                    value="<?php echo esc_attr($lng); ?>" />
                                <button type="button" class="button" id="ach-geo-btn">
                                    <?php esc_html_e('Get from address', 'boundary-map'); ?>
                                </button>
                            </div>

                            <div id="ach-map" class="ach-entry-map"></div>
                        </section>
                    </div>
                </div>

                <?php submit_button($edit_entry ? __('Update Entry', 'boundary-map') : __('Add Entry', 'boundary-map'), 'primary', 'boundary_map_entry_submit'); ?>
            </form>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     * CATEGORIES ADMIN PAGE (CRUD)
     * ------------------------------------------------------ */

    public function render_categories_page()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        $categories = $this->load_categories();

        // ----- Handle create/update -----
        if (isset($_POST['boundary_map_category_submit'])) {
            check_admin_referer('boundary_map_save_category');

            $index = isset($_POST['category_index']) && $_POST['category_index'] !== '' ? intval($_POST['category_index']) : -1;

            $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
            $label = isset($_POST['label']) ? sanitize_text_field($_POST['label']) : '';
            $color = isset($_POST['color']) ? sanitize_hex_color($_POST['color']) : '';
            $icon = isset($_POST['icon']) ? sanitize_text_field($_POST['icon']) : '';

            if ($id) {
                $duplicate_index = $this->find_category_index_by_id($categories, $id);
                if ($duplicate_index >= 0 && $duplicate_index !== $index) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('A category with that ID already exists. Please choose a unique category ID.', 'boundary-map') . '</p></div>';
                } else {
                $existing_category = ($index >= 0 && isset($categories[$index])) ? $categories[$index] : null;
                $old_category_id = $existing_category && isset($existing_category['id']) ? $existing_category['id'] : '';

                $cat_data = array(
                    'id' => $id,
                    'label' => $label ? $label : $id,
                );

                if ($color) {
                    $cat_data['color'] = $color;  // optional
                }
                if ($icon) {
                    $cat_data['icon'] = $icon;    // optional (emoji or short text)
                }

                if ($index >= 0 && isset($categories[$index])) {
                    $categories[$index] = $cat_data;
                    $message = __('Category updated.', 'boundary-map');
                } else {
                    $categories[] = $cat_data;
                    $message = __('Category added.', 'boundary-map');
                }

                $this->save_categories($categories);
                if ($old_category_id !== '') {
                    $this->sync_entries_for_category_change($old_category_id, $cat_data['id'], $cat_data['label']);
                }
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
                }
            }
        }

        // ----- Handle delete -----
        if (isset($_GET['action'], $_GET['index']) && $_GET['action'] === 'delete') {
            $index = intval($_GET['index']);
            check_admin_referer('boundary_map_delete_category_' . $index);

            if (isset($categories[$index])) {
                $category_id = isset($categories[$index]['id']) ? $categories[$index]['id'] : '';
                $usage_count = $this->count_entries_using_category($category_id);

                if ($usage_count > 0) {
                    printf(
                        '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                        esc_html(
                            sprintf(
                                _n(
                                    'This category cannot be deleted because it is still assigned to %d entry.',
                                    'This category cannot be deleted because it is still assigned to %d entries.',
                                    $usage_count,
                                    'boundary-map'
                                ),
                                $usage_count
                            )
                        )
                    );
                } else {
                    unset($categories[$index]);
                    $this->save_categories($categories);
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Category deleted.', 'boundary-map') . '</p></div>';
                }
            }
        }

        // Refresh after mutations
        $categories = array_values($this->load_categories());

        $edit_index = -1;
        $edit_cat = null;
        if (isset($_GET['action'], $_GET['index']) && $_GET['action'] === 'edit') {
            $idx = intval($_GET['index']);
            if (isset($categories[$idx])) {
                $edit_index = $idx;
                $edit_cat = $categories[$idx];
            }
        }

        ?>
        <div class="wrap ach-map-admin ach-categories-page">
            <h1><?php esc_html_e('Australian Boundary Map – Categories', 'boundary-map'); ?></h1>

            <div class="ach-categories-layout">
                <section class="ach-entry-card ach-category-editor">
                    <div class="ach-entry-card__header">
                        <h2><?php echo $edit_cat ? esc_html__('Edit Category', 'boundary-map') : esc_html__('Add New Category', 'boundary-map'); ?></h2>
                        <p><?php esc_html_e('Create and manage the category labels, colours, and icons used across entries and the frontend legend.', 'boundary-map'); ?></p>
                    </div>

                    <form method="post" class="ach-form ach-categories-form">
                        <?php wp_nonce_field('boundary_map_save_category'); ?>
                        <input type="hidden" name="category_index"
                            value="<?php echo $edit_index >= 0 ? esc_attr($edit_index) : ''; ?>" />

                        <div class="ach-category-fields">
                            <div class="ach-category-field">
                                <label for="ach-cat-id"><?php esc_html_e('Category ID', 'boundary-map'); ?></label>
                                <input name="id" id="ach-cat-id" type="text" class="regular-text" required
                                    value="<?php echo $edit_cat ? esc_attr($edit_cat['id']) : ''; ?>" />
                                <p class="description">
                                    <?php esc_html_e('Used internally for entry data (e.g., Music, Food).', 'boundary-map'); ?>
                                </p>
                            </div>

                            <div class="ach-category-field">
                                <label for="ach-cat-label"><?php esc_html_e('Label', 'boundary-map'); ?></label>
                                <input name="label" id="ach-cat-label" type="text" class="regular-text"
                                    value="<?php echo $edit_cat ? esc_attr($edit_cat['label']) : ''; ?>" />
                                <p class="description">
                                    <?php esc_html_e('Human-readable label shown in the UI.', 'boundary-map'); ?>
                                </p>
                            </div>

                            <div class="ach-category-field">
                                <label for="ach-cat-color"><?php esc_html_e('Colour (optional)', 'boundary-map'); ?></label>
                                <input name="color" id="ach-cat-color" type="text" class="regular-text ach-color-field"
                                    placeholder="#44db12"
                                    value="<?php echo $edit_cat && !empty($edit_cat['color']) ? esc_attr($edit_cat['color']) : ''; ?>" />
                                <p class="description">
                                    <?php esc_html_e('Hex colour used for badges and the legend. Leave blank to use the default.', 'boundary-map'); ?>
                                </p>
                            </div>

                            <div class="ach-category-field">
                                <label for="ach-cat-icon"><?php esc_html_e('Icon (optional)', 'boundary-map'); ?></label>
                                <input name="icon" id="ach-cat-icon" type="text" class="regular-text" placeholder="🎷"
                                    value="<?php echo $edit_cat && !empty($edit_cat['icon']) ? esc_attr($edit_cat['icon']) : ''; ?>" />
                                <p class="description">
                                    <?php esc_html_e('Optional emoji or short text shown next to the category label.', 'boundary-map'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="ach-category-actions">
                            <?php submit_button($edit_cat ? __('Update Category', 'boundary-map') : __('Add Category', 'boundary-map'), 'primary', 'boundary_map_category_submit', false); ?>
                        </div>
                    </form>
                </section>

                <section class="ach-entry-card ach-category-list-card">
                    <div class="ach-entry-card__header">
                        <h2><?php esc_html_e('All Categories', 'boundary-map'); ?></h2>
                        <p><?php printf(esc_html__('%d categories currently configured.', 'boundary-map'), count($categories)); ?></p>
                    </div>

                    <div class="ach-table-wrap">
                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('#', 'boundary-map'); ?></th>
                                    <th><?php esc_html_e('ID', 'boundary-map'); ?></th>
                                    <th><?php esc_html_e('Label', 'boundary-map'); ?></th>
                                    <th><?php esc_html_e('Colour', 'boundary-map'); ?></th>
                                    <th><?php esc_html_e('Icon', 'boundary-map'); ?></th>
                                    <th><?php esc_html_e('Actions', 'boundary-map'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($categories)): ?>
                                    <tr>
                                        <td colspan="6"><?php esc_html_e('No categories yet.', 'boundary-map'); ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($categories as $i => $cat):
                                        $edit_url = add_query_arg(
                                            array(
                                                'page' => 'boundary-map-categories',
                                                'action' => 'edit',
                                                'index' => $i,
                                            ),
                                            admin_url('admin.php')
                                        );
                                        $delete_url = wp_nonce_url(
                                            add_query_arg(
                                                array(
                                                    'page' => 'boundary-map-categories',
                                                    'action' => 'delete',
                                                    'index' => $i,
                                                ),
                                                admin_url('admin.php')
                                            ),
                                            'boundary_map_delete_category_' . $i
                                        );
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html($i); ?></td>
                                            <td><?php echo esc_html(isset($cat['id']) ? $cat['id'] : ''); ?></td>
                                            <td><?php echo esc_html(isset($cat['label']) ? $cat['label'] : ''); ?></td>
                                            <td>
                                                <?php if (!empty($cat['color'])): ?>
                                                    <span
                                                        style="display:inline-block;width:18px;height:18px;border-radius:50%;background:<?php echo esc_attr($cat['color']); ?>;border:1px solid #ccc;"></span>
                                                    <code><?php echo esc_html($cat['color']); ?></code>
                                                <?php else: ?>
                                                    <span class="description"><?php esc_html_e('Default', 'boundary-map'); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo !empty($cat['icon']) ? esc_html($cat['icon']) : ''; ?></td>
                                            <td>
                                                <a class="button button-small"
                                                    href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'boundary-map'); ?></a>
                                                <a class="button button-small button-link-delete" href="<?php echo esc_url($delete_url); ?>"
                                                    onclick="return confirm('<?php echo esc_js(__('Delete this category?', 'boundary-map')); ?>');">
                                                    <?php esc_html_e('Delete', 'boundary-map'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    public function handle_export_entries()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        check_admin_referer('boundary_map_export_entries');

        $rows = array();
        foreach ($this->load_entries() as $entry) {
            $coords = !empty($entry['coords']) && is_array($entry['coords']) ? $entry['coords'] : array('', '');
            $rows[] = array(
                isset($entry['id']) ? $entry['id'] : '',
                isset($entry['title']) ? $entry['title'] : '',
                isset($entry['categoryId']) ? $entry['categoryId'] : '',
                isset($entry['category']) ? $entry['category'] : '',
                isset($entry['description']) ? wp_strip_all_tags($entry['description']) : '',
                isset($entry['location']) ? $entry['location'] : '',
                isset($coords[0]) ? $coords[0] : '',
                isset($coords[1]) ? $coords[1] : '',
                isset($entry['image']) ? $entry['image'] : '',
                isset($entry['status']) ? $entry['status'] : 'publish',
            );
        }

        $this->send_csv_download(
            'boundary-map-entries-' . gmdate('Ymd') . '.csv',
            array('id', 'title', 'category_id', 'category_label', 'description', 'location', 'lat', 'lng', 'image', 'status'),
            $rows
        );
    }

    public function handle_export_categories()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        check_admin_referer('boundary_map_export_categories');

        $rows = array();
        foreach ($this->load_categories() as $category) {
            $rows[] = array(
                isset($category['id']) ? $category['id'] : '',
                isset($category['label']) ? $category['label'] : '',
                isset($category['color']) ? $category['color'] : '',
                isset($category['icon']) ? $category['icon'] : '',
            );
        }

        $this->send_csv_download(
            'boundary-map-categories-' . gmdate('Ymd') . '.csv',
            array('id', 'label', 'color', 'icon'),
            $rows
        );
    }

    public function handle_import_entries()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        check_admin_referer('boundary_map_import_entries');

        $rows = $this->parse_uploaded_csv('entries_csv');
        if (is_wp_error($rows)) {
            $this->redirect_to_admin_page('boundary-map-tools', array('import_error' => $rows->get_error_code()));
        }

        $categories = $this->load_categories();
        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $title = isset($row['title']) ? sanitize_text_field($row['title']) : '';
            if ($title === '') {
                $skipped++;
                continue;
            }

            $category_id = isset($row['category_id']) ? sanitize_text_field($row['category_id']) : '';
            if ($category_id !== '' && $this->find_category_index_by_id($categories, $category_id) < 0) {
                $skipped++;
                continue;
            }

            $lat_raw = isset($row['lat']) ? trim($row['lat']) : '';
            $lng_raw = isset($row['lng']) ? trim($row['lng']) : '';
            $coords = null;
            if ($this->has_partial_coords($lat_raw, $lng_raw) || (($lat_raw !== '' || $lng_raw !== '') && !$this->coords_are_valid($lat_raw, $lng_raw))) {
                $skipped++;
                continue;
            }
            if ($lat_raw !== '' && $lng_raw !== '') {
                $coords = array(floatval($lat_raw), floatval($lng_raw));
            }

            $image = isset($row['image']) ? esc_url_raw($row['image']) : '';
            if (!$this->is_valid_image_url($image)) {
                $skipped++;
                continue;
            }

            $status = isset($row['status']) ? sanitize_key($row['status']) : 'publish';
            if (!in_array($status, array('publish', 'trash'), true)) {
                $status = 'publish';
            }

            $entry_data = array(
                'title' => $title,
                'categoryId' => $category_id,
                'category' => $this->get_category_label_by_id($category_id, $categories),
                'description' => isset($row['description']) ? wp_kses_post($row['description']) : '',
                'location' => isset($row['location']) ? sanitize_text_field($row['location']) : '',
                'coords' => $coords,
                'image' => $image,
                'status' => $status,
            );

            $entry_id = isset($row['id']) && $row['id'] !== '' ? intval($row['id']) : 0;
            Boundary_Map_Database::save_entry($entry_data, $entry_id > 0 ? $entry_id : null);

            if ($entry_id > 0) {
                $updated++;
            } else {
                $imported++;
            }
        }

        if ($imported > 0 || $updated > 0) {
            self::flush_rest_cache();
        }

        $this->redirect_to_admin_page('boundary-map-tools', array(
            'entries_imported' => $imported,
            'entries_updated' => $updated,
            'entries_skipped' => $skipped,
        ));
    }

    public function handle_import_categories()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        check_admin_referer('boundary_map_import_categories');

        $rows = $this->parse_uploaded_csv('categories_csv');
        if (is_wp_error($rows)) {
            $this->redirect_to_admin_page('boundary-map-tools', array('import_error' => $rows->get_error_code()));
        }

        $existing = array();
        foreach ($this->load_categories() as $category) {
            if (!empty($category['id'])) {
                $existing[$category['id']] = $category;
            }
        }

        $imported = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $id = isset($row['id']) ? sanitize_text_field($row['id']) : '';
            if ($id === '') {
                $skipped++;
                continue;
            }

            $color = isset($row['color']) ? sanitize_hex_color($row['color']) : '';
            $category = array(
                'id' => $id,
                'label' => isset($row['label']) && $row['label'] !== '' ? sanitize_text_field($row['label']) : $id,
                'color' => $color ? $color : null,
                'icon' => isset($row['icon']) && $row['icon'] !== '' ? sanitize_text_field($row['icon']) : null,
            );

            if (isset($existing[$id])) {
                $updated++;
            } else {
                $imported++;
            }

            $existing[$id] = $category;
        }

        $this->save_categories(array_values($existing));

        $this->redirect_to_admin_page('boundary-map-tools', array(
            'categories_imported' => $imported,
            'categories_updated' => $updated,
            'categories_skipped' => $skipped,
        ));
    }

    public function render_tools_page()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }
        ?>
        <div class="wrap ach-map-admin ach-tools-page">
            <h1><?php esc_html_e('Australian Boundary Map – Tools', 'boundary-map'); ?></h1>
            <p><?php esc_html_e('Use these tools to move entries and categories in and out of the plugin with CSV files.', 'boundary-map'); ?></p>

            <?php if (isset($_GET['import_error'])): ?>
                <div class="notice notice-error is-dismissible"><p>
                    <?php
                    $error_code = sanitize_key(wp_unslash($_GET['import_error']));
                    switch ($error_code) {
                        case 'missing_file':
                            esc_html_e('Please choose a CSV file before starting an import.', 'boundary-map');
                            break;
                        case 'invalid_file':
                            esc_html_e('The uploaded CSV file could not be read.', 'boundary-map');
                            break;
                        case 'empty_file':
                            esc_html_e('The uploaded CSV file was empty.', 'boundary-map');
                            break;
                        default:
                            esc_html_e('The import could not be completed.', 'boundary-map');
                    }
                    ?>
                </p></div>
            <?php endif; ?>

            <?php if (isset($_GET['entries_imported']) || isset($_GET['categories_imported'])): ?>
                <div class="notice notice-success is-dismissible"><p>
                    <?php
                    if (isset($_GET['entries_imported'])) {
                        printf(
                            esc_html__('Entries import complete: %1$d added, %2$d updated, %3$d skipped.', 'boundary-map'),
                            intval($_GET['entries_imported']),
                            intval(isset($_GET['entries_updated']) ? $_GET['entries_updated'] : 0),
                            intval(isset($_GET['entries_skipped']) ? $_GET['entries_skipped'] : 0)
                        );
                    } else {
                        printf(
                            esc_html__('Categories import complete: %1$d added, %2$d updated, %3$d skipped.', 'boundary-map'),
                            intval($_GET['categories_imported']),
                            intval(isset($_GET['categories_updated']) ? $_GET['categories_updated'] : 0),
                            intval(isset($_GET['categories_skipped']) ? $_GET['categories_skipped'] : 0)
                        );
                    }
                    ?>
                </p></div>
            <?php endif; ?>

            <div class="ach-tools-grid">
                <section class="ach-entry-card">
                    <div class="ach-entry-card__header">
                        <h2><?php esc_html_e('Export Entries', 'boundary-map'); ?></h2>
                        <p><?php esc_html_e('Download all entries in CSV format for backup, migration, or bulk editing.', 'boundary-map'); ?></p>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="boundary_map_export_entries" />
                        <?php wp_nonce_field('boundary_map_export_entries'); ?>
                        <?php submit_button(__('Download Entries CSV', 'boundary-map'), 'secondary', 'submit', false); ?>
                    </form>
                </section>

                <section class="ach-entry-card">
                    <div class="ach-entry-card__header">
                        <h2><?php esc_html_e('Import Entries', 'boundary-map'); ?></h2>
                        <p><?php esc_html_e('Upload a CSV with entry fields such as title, category_id, description, location, lat, lng, image, and status.', 'boundary-map'); ?></p>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="boundary_map_import_entries" />
                        <?php wp_nonce_field('boundary_map_import_entries'); ?>
                        <input type="file" name="entries_csv" accept=".csv,text/csv" required />
                        <p class="description"><?php esc_html_e('If an id column is provided, matching entries will be updated.', 'boundary-map'); ?></p>
                        <?php submit_button(__('Import Entries CSV', 'boundary-map'), 'primary', 'submit', false); ?>
                    </form>
                </section>

                <section class="ach-entry-card">
                    <div class="ach-entry-card__header">
                        <h2><?php esc_html_e('Export Categories', 'boundary-map'); ?></h2>
                        <p><?php esc_html_e('Download the configured categories in CSV format.', 'boundary-map'); ?></p>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="boundary_map_export_categories" />
                        <?php wp_nonce_field('boundary_map_export_categories'); ?>
                        <?php submit_button(__('Download Categories CSV', 'boundary-map'), 'secondary', 'submit', false); ?>
                    </form>
                </section>

                <section class="ach-entry-card">
                    <div class="ach-entry-card__header">
                        <h2><?php esc_html_e('Import Categories', 'boundary-map'); ?></h2>
                        <p><?php esc_html_e('Upload a CSV with id, label, color, and icon columns to add or update categories.', 'boundary-map'); ?></p>
                    </div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="boundary_map_import_categories" />
                        <?php wp_nonce_field('boundary_map_import_categories'); ?>
                        <input type="file" name="categories_csv" accept=".csv,text/csv" required />
                        <?php submit_button(__('Import Categories CSV', 'boundary-map'), 'primary', 'submit', false); ?>
                    </form>
                </section>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     * CONFIG PAGE
     * ------------------------------------------------------ */

    public function render_config_page()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        $notice_message = '';

        if (isset($_POST['boundary_map_config_submit'])) {
            check_admin_referer('boundary_map_save_config');

            Boundary_Map_Settings_Service::save_config(
                array(
                'country' => isset($_POST['country']) ? sanitize_key($_POST['country']) : 'australia',
                'scope' => isset($_POST['scope']) ? sanitize_key($_POST['scope']) : '',
                'area' => isset($_POST['area']) ? sanitize_key($_POST['area']) : '',
                'subdivision' => isset($_POST['subdivision']) ? sanitize_key($_POST['subdivision']) : '',
                ),
                isset($_POST['show_sidebar_panel'])
                ? $this->sanitize_shortcode_toggle(wp_unslash($_POST['show_sidebar_panel']), true)
                : true,
                isset($_POST['marker_tag_mode'])
                ? $this->sanitize_marker_tag_mode(wp_unslash($_POST['marker_tag_mode']))
                : 'clickable',
                function ($selection) {
                    return $this->sanitize_geography_selection($selection);
                },
                function ($value, $default = true) {
                    return $this->sanitize_shortcode_toggle($value, $default);
                },
                function ($value) {
                    return $this->sanitize_marker_tag_mode($value);
                }
            );

            $notice_message = __('Configuration updated.', 'boundary-map');
        }

        $selection = $this->get_saved_geography_selection();
        $selected_node = $this->get_selected_geography_node($selection);
        Boundary_Map_Config_Page_Renderer::render(array(
            'selected_node' => $selected_node,
            'show_sidebar_panel' => $this->get_saved_show_sidebar_panel(),
            'marker_tag_mode' => $this->get_saved_marker_tag_mode(),
            'notice_message' => $notice_message,
            'support_url' => $this->get_support_url(),
        ));
    }

    /* ---------------------------------------------------------
     * INFORMATION / HELP PAGE
     * ------------------------------------------------------ */

    public function render_information_page()
    {
        if (!$this->user_can_manage_boundary_map()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        $data_source = Boundary_Map_Database::is_migrated()
            ? __('Database', 'boundary-map')
            : __('WordPress admin entries', 'boundary-map');

        Boundary_Map_Information_Page_Renderer::render($data_source);
    }

    /* ---------------------------------------------------------
     * SHORTCODE + FRONT-END ENQUEUE
     * ------------------------------------------------------ */

public function boundary_map($atts)
{
    $this->enqueue_front_for_shortcode($atts, 'full');

    return Boundary_Map_Public_Map_Component::render_full(
        $this->get_shortcode_width_mode($atts),
        $this->get_shortcode_wrapper_style($atts),
        $this->resolve_shortcode_sidebar_panel_setting($atts)
    );
}

    
public function boundary_map_shape($atts)
{
    $this->enqueue_front_for_shortcode($atts, 'shape-only');

    return Boundary_Map_Public_Map_Component::render_shape_only(
        $this->get_shortcode_width_mode($atts),
        $this->get_shortcode_wrapper_style($atts)
    );
}

private function get_body_html($show_sidebar_panel = true)
    {
        return Boundary_Map_Public_Map_Component::render_body($show_sidebar_panel);
    }
}
