<?php
/*
Plugin Name: Australian Boundary Map
Description: Interactive Leaflet map with entries & categories managed from WP Admin.
Version: 1.2.0
Author: You
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/class-boundary-map-database.php';

register_activation_hook(__FILE__, array('Boundary_Map_Plugin', 'activate'));

class Boundary_Map_Plugin
{

    private $entries_file;
    private $categories_file;
    private $version = '1.2.0';

    public static function activate()
    {
        Boundary_Map_Database::create_tables();
        Boundary_Map_Database::migrate_from_json(
            plugin_dir_path(__FILE__) . 'assets/entries.json',
            plugin_dir_path(__FILE__) . 'assets/categories.json'
        );
    }

    public function __construct()
    {
        $this->entries_file = plugin_dir_path(__FILE__) . 'assets/entries.json';
        $this->categories_file = plugin_dir_path(__FILE__) . 'assets/categories.json';

        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'maybe_migrate_on_load'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_footer', array($this, 'remove_admin_menu_separator'));
        add_shortcode('achievements_map', array($this, 'achievements_map'));
        add_shortcode('achievements_map_shape', array($this, 'achievements_map_shape'));

        // Basic capability check later in handlers
        // // NEW: REST routes
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));

        add_action('admin_post_abm_bulk_entries', array($this, 'handle_bulk_entries'));
        add_action('admin_init', array($this, 'handle_single_entry_actions'), 5);
        add_action('admin_init', array($this, 'handle_entry_form_submit'), 5);
    }

    /**
     * Handle single trash/restore/delete actions early (before any output) to avoid "headers already sent" errors.
     */
    public function handle_single_entry_actions()
    {
        if (!$this->user_can_manage()) {
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
            check_admin_referer('abm_trash_entry_' . $entry_id);
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::update_entry_status($entry_id, 'trash');
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
            check_admin_referer('abm_restore_entry_' . $entry_id);
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::update_entry_status($entry_id, 'publish');
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
            check_admin_referer('abm_delete_entry_' . $entry_id);
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::delete_entry($entry_id);
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
        if (!$this->user_can_manage()) {
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
        if ($page !== 'boundary-map-add-entry') {
            return;
        }
        if (!isset($_POST['abm_entry_submit'])) {
            return;
        }

        check_admin_referer('abm_save_entry');

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

        $lat = isset($_POST['lat']) ? floatval($_POST['lat']) : null;
        $lng = isset($_POST['lng']) ? floatval($_POST['lng']) : null;
        $coords = (is_numeric($lat) && is_numeric($lng)) ? array($lat, $lng) : null;

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
    public function maybe_migrate_on_load()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (Boundary_Map_Database::is_migrated()) {
            return;
        }
        Boundary_Map_Database::create_tables();
        Boundary_Map_Database::migrate_from_json($this->entries_file, $this->categories_file);
    }

    /**
     * Handle bulk actions via admin-post (runs before page render).
     */
    public function handle_bulk_entries()
    {
        if (!$this->user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        check_admin_referer('abm_bulk_action');

        if (empty($_POST['entry']) || !is_array($_POST['entry'])) {
            wp_safe_redirect(add_query_arg(array('page' => 'boundary-map-entries', 'bulk_error' => 'no_items'), admin_url('admin.php')));
            exit;
        }

        $entries = $this->load_entries();
        $ids = array_map('intval', (array) $_POST['entry']);
        $ids = array_filter($ids);
        if (!Boundary_Map_Database::is_migrated()) {
            $ids = array_values(array_filter($ids, function ($i) use ($entries) { return isset($entries[$i]); }));
        }

        $bulk_action = '';
        if (!empty($_POST['abm_bulk_action_top']) && $_POST['abm_bulk_action_top'] !== '-1') {
            $bulk_action = sanitize_text_field($_POST['abm_bulk_action_top']);
        } elseif (!empty($_POST['abm_bulk_action_bottom']) && $_POST['abm_bulk_action_bottom'] !== '-1') {
            $bulk_action = sanitize_text_field($_POST['abm_bulk_action_bottom']);
        }

        $redirect_url = add_query_arg('page', 'boundary-map-entries', admin_url('admin.php'));

        if ($bulk_action === 'trash' && !empty($ids)) {
            if (Boundary_Map_Database::is_migrated()) {
                Boundary_Map_Database::bulk_update_status($ids, 'trash');
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
            plugin_dir_url(__FILE__) . 'assets/styles.css',
            array('bootstrap-5'),
            $this->version
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
            plugin_dir_url(__FILE__) . 'assets/script.js',
            array('leaflet', 'bootstrap-5', 'fuse-js'),
            $this->version,
            true
        );

        // Enqueue only when shortcode is present on this page
        if (!$this->page_has_shortcode()) {
            return;
        }

        wp_localize_script(
            'boundary-map-app',
            'ABM_CONFIG',
            array(
                'entriesApiUrl' => rest_url('boundary-map/entries'),
                'categoriesApiUrl' => rest_url('boundary-map/categories'),
                'regionUrl' => plugins_url('assets/E_NSW24_region1.json', __FILE__),
            )
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
     * Use filter 'achievements_map_force_enqueue' => true to force enqueue (e.g. in templates).
     */
    private function page_has_shortcode()
    {
        if (apply_filters('achievements_map_force_enqueue', false)) {
            return true;
        }

        $post = get_queried_object();
        if (!$post || !isset($post->post_content)) {
            return false;
        }

        return has_shortcode($post->post_content, 'achievements_map')
            || has_shortcode($post->post_content, 'achievements_map_shape');
    }

    /* ---------------------------------------------------------
     * Helpers to load / save JSON
     * ------------------------------------------------------ */

    private function enqueue_front_for_shortcode($atts = array(), $mode = 'full')
{
    $atts = shortcode_atts(array(
        'zoom' => '',          // allow per page override
        'minzoom' => 10,
        'maxzoom' => 17,
        'scrollwheel' => 0,    // 0/1
    ), $atts);

    // enqueue now (only when shortcode is used)
    wp_enqueue_style('leaflet');
    wp_enqueue_style('bootstrap-5');
    wp_enqueue_style('boundary-map');

    wp_enqueue_script('leaflet');
    wp_enqueue_script('bootstrap-5');
    wp_enqueue_script('fuse-js');
    wp_enqueue_script('boundary-map-app');

    // pick zoom: shortcode zoom if provided, else default
    $zoom = ($atts['zoom'] !== '' ? floatval($atts['zoom']) : 11.45);

    wp_localize_script(
        'boundary-map-app',
        'ABM_CONFIG',
        array(
            'mode' => $mode, // 'full' or 'shape-only'
            'zoom' => $zoom,
            'minZoom' => intval($atts['minzoom']),
            'maxZoom' => intval($atts['maxzoom']),
            'scrollWheelZoom' => !empty($atts['scrollwheel']) ? true : false,

            'entriesApiUrl' => rest_url('boundary-map/entries'),
            'categoriesApiUrl' => rest_url('boundary-map/categories'),
            'regionUrl' => plugins_url('assets/E_NSW24_region1.json', __FILE__),
        )
    );
}


    /**
     * Localize an entry image URL to the current site.
     * Replaces old domain (e.g. julianleeser.cinfinity.au) with the current site's content URL.
     *
     * @param string|null $url Image URL (can be null/empty).
     * @return string Localized URL.
     */
    private function localize_entry_image_url($url)
    {
        if (empty($url) || !is_string($url)) {
            return $url;
        }
        $old_base = 'https://julianleeser.cinfinity.au/wp-content';
        $new_base = content_url();
        if (strpos($url, $old_base) === 0) {
            return $new_base . substr($url, strlen($old_base));
        }
        return $url;
    }

    /**
     * Load entries (from DB if migrated, else JSON).
     * @param string|null $status_filter 'publish', 'trash', or null for all.
     * @return array Entries keyed by id (DB) or index (JSON).
     */
    private function load_entries($status_filter = null)
    {
        if (Boundary_Map_Database::is_migrated()) {
            $entries = Boundary_Map_Database::load_entries($status_filter);
        } else {
            if (!file_exists($this->entries_file)) {
                return array();
            }
            $raw = file_get_contents($this->entries_file);
            $data = json_decode($raw, true);
            $entries = is_array($data) ? $data : array();
            foreach ($entries as $i => $ev) {
                if (!isset($ev['id'])) {
                    $entries[$i]['id'] = $i;
                }
            }
            if ($status_filter !== null) {
                $filtered = array();
                foreach ($entries as $i => $ev) {
                    $status = isset($ev['status']) ? $ev['status'] : 'publish';
                    if ($status === $status_filter) {
                        $filtered[$i] = $ev;
                    }
                }
                $entries = $filtered;
            }
        }

        foreach ($entries as $k => $ev) {
            if (!empty($ev['image'])) {
                $entries[$k]['image'] = $this->localize_entry_image_url($ev['image']);
            }
        }
        return $entries;
    }

    private function save_entries($entries)
    {
        if (!$this->user_can_manage()) {
            return;
        }
        if (Boundary_Map_Database::is_migrated()) {
            foreach ($entries as $key => $ev) {
                $id = isset($ev['id']) ? intval($ev['id']) : null;
                Boundary_Map_Database::save_entry($ev, $id > 0 ? $id : null);
            }
            return;
        }
        $json = json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->entries_file, $json);
    }

    private function load_categories()
    {
        if (Boundary_Map_Database::is_migrated()) {
            return Boundary_Map_Database::load_categories();
        }
        if (!file_exists($this->categories_file)) {
            return array();
        }
        $raw = file_get_contents($this->categories_file);
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return array();
        }
        if (isset($data['categories']) && is_array($data['categories'])) {
            return $data['categories'];
        }
        return $data;
    }

    private function save_categories($categories)
    {
        if (!$this->user_can_manage()) {
            return;
        }
        if (Boundary_Map_Database::is_migrated()) {
            Boundary_Map_Database::save_categories($categories);
            return;
        }
        $wrapper = array('categories' => array_values($categories));
        $json = json_encode($wrapper, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->categories_file, $json);
    }

    /* ---------------------------------------------------------
     * Admin Menu / Assets
     * ------------------------------------------------------ */

    private function user_can_manage()
    {
        return current_user_can('edit_others_posts');
    }

    public function register_admin_menu()
    {
        add_menu_page(
            __('Australian Boundary Map', 'boundary-map'),
            __('Australian Boundary Map', 'boundary-map'),
            'edit_others_posts',
            'boundary-map-entries',
            array($this, 'render_all_entries_page'),
            'dashicons-location-alt',
            26
        );

        add_submenu_page(
            'boundary-map-entries',
            __('All Entries', 'boundary-map'),
            __('All Entries', 'boundary-map'),
            'edit_others_posts',
            'boundary-map-entries',
            array($this, 'render_all_entries_page')
        );

        add_submenu_page(
            'boundary-map-entries',
            __('Add Entry', 'boundary-map'),
            __('Add Entry', 'boundary-map'),
            'edit_others_posts',
            'boundary-map-add-entry',
            array($this, 'render_add_entry_page')
        );

        add_submenu_page(
            'boundary-map-entries',
            __('Categories', 'boundary-map'),
            __('Categories', 'boundary-map'),
            'edit_others_posts',
            'boundary-map-categories',
            array($this, 'render_categories_page')
        );

        add_submenu_page(
            'boundary-map-entries',
            __('Information', 'boundary-map'),
            __('Information', 'boundary-map'),
            'edit_others_posts',
            'boundary-map-information',
            array($this, 'render_information_page')
        );
    }

    /**
     * Remove only the wp-menu-separator li that creates the gap after Australian Boundary Map.
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
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            array('wp-color-picker'),
            $this->version
        );

        wp_enqueue_script(
            'boundary-map-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            array('jquery', 'leaflet', 'wp-color-picker'),
            $this->version,
            true
        );

        wp_localize_script(
            'boundary-map-admin',
            'ABM_ADMIN',
            array(
                'regionUrl' => plugins_url('assets/E_NSW24_region1.json', __FILE__),
            )
        );
    }
    /* ---------------------------------------------------------
     * TO READ ENTRIES ACHIEVEMENTS AND CATEGORIES
     * ------------------------------------------------------ */

    public function register_rest_routes()
    {
        register_rest_route(
            'boundary-map',
            '/entries',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_get_entries'),
                'permission_callback' => '__return_true', // public
            )
        );

        register_rest_route(
            'boundary-map',
            '/categories',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_get_categories'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function rest_get_entries($request)
    {
        // This uses your existing loader (which currently reads from entries.json,
        // i.e. whatever is stored via the “Achievements” admin screen).
        $entries = $this->load_entries('publish');
        return rest_ensure_response(array_values($entries));
    }

    public function rest_get_categories($request)
    {
        $categories = $this->load_categories();
        return rest_ensure_response($categories);
    }

    /* ---------------------------------------------------------
     * ACHIEVEMENTS ADMIN PAGES
     * ------------------------------------------------------ */

    /**
     * All Achievements – list table only.
     */
    public function render_all_entries_page()
    {
        if (!$this->user_can_manage()) {
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
            <a href="<?php echo esc_url($add_url); ?>" class="page-title-action"><?php esc_html_e('Add New', 'boundary-map'); ?></a>
            <?php endif; ?>
            <hr class="wp-header-end">

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
                <input type="hidden" name="action" value="abm_bulk_entries" />
                <?php if ($status_filter): ?><input type="hidden" name="post_status" value="<?php echo esc_attr($status_filter); ?>" /><?php endif; ?>
                <?php wp_nonce_field('abm_bulk_action'); ?>
                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <label for="abm-bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'boundary-map'); ?></label>
                        <select name="abm_bulk_action_top" id="abm-bulk-action-selector-top">
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
                                ), admin_url('admin.php')), 'abm_restore_entry_' . $id);
                                $delete_url = wp_nonce_url(add_query_arg(array(
                                    'page' => 'boundary-map-entries',
                                    'action' => 'delete',
                                    'id' => $id,
                                ), admin_url('admin.php')), 'abm_delete_entry_' . $id);
                            } else {
                                $trash_url = wp_nonce_url(add_query_arg(array(
                                    'page' => 'boundary-map-entries',
                                    'action' => 'trash',
                                    'id' => $id,
                                ), admin_url('admin.php')), 'abm_trash_entry_' . $id);
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
                        <label for="abm-bulk-action-selector-bottom" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'boundary-map'); ?></label>
                        <select name="abm_bulk_action_bottom" id="abm-bulk-action-selector-bottom">
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
            var topSel = form.querySelector('[name="abm_bulk_action_top"]');
            var bottomSel = form.querySelector('[name="abm_bulk_action_bottom"]');
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
        if (!$this->user_can_manage()) {
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

        ?>
        <div class="wrap ach-map-admin">
            <h1><?php echo $edit_entry ? esc_html__('Edit Entry', 'boundary-map') : esc_html__('Add New Entry', 'boundary-map'); ?></h1>
            <a href="<?php echo esc_url($list_url); ?>" class="page-title-action"><?php esc_html_e('&larr; Back to All Entries', 'boundary-map'); ?></a>

            <form method="post" class="ach-form">
                <?php wp_nonce_field('abm_save_entry'); ?>
                <input type="hidden" name="entry_id" value="<?php echo ($edit_id !== null && $edit_id !== '') ? esc_attr($edit_id) : ''; ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="ach-title"><?php esc_html_e('Title', 'boundary-map'); ?></label></th>
                        <td><input name="title" id="ach-title" type="text" class="regular-text" required
                                value="<?php echo $edit_entry ? esc_attr($edit_entry['title']) : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="ach-category"><?php esc_html_e('Category', 'boundary-map'); ?></label></th>
                        <td>
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
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-location"><?php esc_html_e('Location', 'boundary-map'); ?></label></th>
                        <td><input name="location" id="ach-location" type="text" class="regular-text"
                                value="<?php echo $edit_entry ? esc_attr($edit_entry['location']) : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="ach-description"><?php esc_html_e('Description', 'boundary-map'); ?></label></th>
                        <td>
                            <textarea name="description" id="ach-description" rows="4" class="large-text"><?php
                            echo $edit_entry ? esc_textarea($edit_entry['description']) : '';
                            ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Coordinates (Lat, Lng)', 'boundary-map'); ?></th>
                        <td>
                            <?php
                            $lat = '';
                            $lng = '';
                            if ($edit_entry && !empty($edit_entry['coords']) && is_array($edit_entry['coords'])) {
                                $lat = $edit_entry['coords'][0];
                                $lng = $edit_entry['coords'][1];
                            }
                            ?>
                            <input name="lat" id="ach-lat" type="text" class="medium-text" placeholder="-33.6"
                                value="<?php echo esc_attr($lat); ?>" />
                            <input name="lng" id="ach-lng" type="text" class="medium-text" placeholder="151.1"
                                value="<?php echo esc_attr($lng); ?>" />

                            <button type="button" class="button" id="ach-geo-btn" style="margin-left:8px;">
                                <?php esc_html_e('Get from address', 'boundary-map'); ?>
                            </button>

                            <p class="description">
                                <?php esc_html_e('Use the button to geocode from Location, or drag/click on the map below.', 'boundary-map'); ?>
                            </p>

                            <div id="ach-map" style="height: 320px; max-width: 600px; margin-top: 10px; border: 1px solid #ddd;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-image"><?php esc_html_e('Image', 'boundary-map'); ?></label></th>
                        <td>
                            <input name="image" id="ach-image" type="text" class="regular-text"
                                value="<?php echo $edit_entry ? esc_url($edit_entry['image']) : ''; ?>" />

                            <button type="button" class="button" id="ach-image-btn" style="margin-top:6px;">
                                <?php esc_html_e('Upload / Select Image', 'boundary-map'); ?>
                            </button>

                            <div id="ach-image-preview" style="margin-top:10px;">
                                <?php if (!empty($edit_entry['image'])): ?>
                                    <img src="<?php echo esc_url($edit_entry['image']); ?>" alt=""
                                        style="max-width:200px;height:auto;display:block;border:1px solid #ddd;padding:2px;" />
                                <?php endif; ?>
                            </div>

                            <p class="description">
                                <?php esc_html_e('Choose or upload an image from the Media Library.', 'boundary-map'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button($edit_entry ? __('Update Entry', 'boundary-map') : __('Add Entry', 'boundary-map'), 'primary', 'abm_entry_submit'); ?>
            </form>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     * CATEGORIES ADMIN PAGE (CRUD)
     * ------------------------------------------------------ */

    public function render_categories_page()
    {
        if (!$this->user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        $categories = $this->load_categories();

        // ----- Handle create/update -----
        if (isset($_POST['ach_category_submit'])) {
            check_admin_referer('abm_save_category');

            $index = isset($_POST['category_index']) && $_POST['category_index'] !== '' ? intval($_POST['category_index']) : -1;

            $id = isset($_POST['id']) ? sanitize_text_field($_POST['id']) : '';
            $label = isset($_POST['label']) ? sanitize_text_field($_POST['label']) : '';
            $color = isset($_POST['color']) ? sanitize_hex_color($_POST['color']) : '';
            $icon = isset($_POST['icon']) ? sanitize_text_field($_POST['icon']) : '';

            if ($id) {
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
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
            }
        }

        // ----- Handle delete -----
        if (isset($_GET['action'], $_GET['index']) && $_GET['action'] === 'delete') {
            $index = intval($_GET['index']);
            check_admin_referer('abm_delete_category_' . $index);

            if (isset($categories[$index])) {
                unset($categories[$index]);
                $this->save_categories($categories);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Category deleted.', 'boundary-map') . '</p></div>';
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
        <div class="wrap ach-map-admin">
            <h1><?php esc_html_e('Australian Boundary Map – Categories', 'boundary-map'); ?></h1>

            <h2><?php echo $edit_cat ? esc_html__('Edit Category', 'boundary-map') : esc_html__('Add New Category', 'boundary-map'); ?>
            </h2>

            <form method="post" class="ach-form">
                <?php wp_nonce_field('abm_save_category'); ?>
                <input type="hidden" name="category_index"
                    value="<?php echo $edit_index >= 0 ? esc_attr($edit_index) : ''; ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="ach-cat-id"><?php esc_html_e('Category ID', 'boundary-map'); ?></label></th>
                        <td>
                            <input name="id" id="ach-cat-id" type="text" class="regular-text" required
                                value="<?php echo $edit_cat ? esc_attr($edit_cat['id']) : ''; ?>" />
                            <p class="description">
                                <?php esc_html_e('Used internally and in entries.json (e.g., Music, Food).', 'boundary-map'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-cat-color"><?php esc_html_e('Colour (optional)', 'boundary-map'); ?></label>
                        </th>
                        <td>
                            <input name="color" id="ach-cat-color" type="text" class="regular-text ach-color-field"
                                placeholder="#44db12"
                                value="<?php echo $edit_cat && !empty($edit_cat['color']) ? esc_attr($edit_cat['color']) : ''; ?>" />
                            <p class="description">
                                <?php esc_html_e('Hex colour used for badges & legend (e.g., #44db12). Leave blank to use default.', 'boundary-map'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-cat-icon"><?php esc_html_e('Icon (optional)', 'boundary-map'); ?></label></th>
                        <td>
                            <input name="icon" id="ach-cat-icon" type="text" class="regular-text" placeholder="🎷"
                                value="<?php echo $edit_cat && !empty($edit_cat['icon']) ? esc_attr($edit_cat['icon']) : ''; ?>" />
                            <p class="description">
                                <?php esc_html_e('Optional emoji or short text shown next to the category label.', 'boundary-map'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-cat-label"><?php esc_html_e('Label', 'boundary-map'); ?></label></th>
                        <td>
                            <input name="label" id="ach-cat-label" type="text" class="regular-text"
                                value="<?php echo $edit_cat ? esc_attr($edit_cat['label']) : ''; ?>" />
                            <p class="description">
                                <?php esc_html_e('Human-readable label shown in the UI.', 'boundary-map'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button($edit_cat ? __('Update Category', 'boundary-map') : __('Add Category', 'boundary-map'), 'primary', 'ach_category_submit'); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('All Categories', 'boundary-map'); ?></h2>

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
                                'abm_delete_category_' . $i
                            );
                            ?>
                            <tr>
                                <td><?php echo esc_html($i); ?></td>
                                <td><?php echo esc_html(isset($cat['id']) ? $cat['id'] : ''); ?></td>
                                <td><?php echo esc_html(isset($cat['label']) ? $cat['label'] : ''); ?></td>

                                <!-- Colour column -->
                                <td>
                                    <?php if (!empty($cat['color'])): ?>
                                        <span
                                            style="display:inline-block;width:18px;height:18px;border-radius:50%;background:<?php echo esc_attr($cat['color']); ?>;border:1px solid #ccc;"></span>
                                        <code><?php echo esc_html($cat['color']); ?></code>
                                    <?php else: ?>
                                        <span class="description"><?php esc_html_e('Default', 'boundary-map'); ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Icon column -->
                                <td>
                                    <?php echo !empty($cat['icon']) ? esc_html($cat['icon']) : ''; ?>
                                </td>

                                <!-- Actions -->
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
        <?php
    }

    /* ---------------------------------------------------------
     * INFORMATION / HELP PAGE
     * ------------------------------------------------------ */

    public function render_information_page()
    {
        if (!$this->user_can_manage()) {
            wp_die(__('You do not have permission to access this page.', 'boundary-map'));
        }

        $data_source = Boundary_Map_Database::is_migrated()
            ? __('Database', 'boundary-map')
            : __('JSON files (assets/entries.json)', 'boundary-map');
        ?>
        <div class="wrap ach-map-admin">
            <h1><?php esc_html_e('Australian Boundary Map – Information', 'boundary-map'); ?></h1>

            <h2><?php esc_html_e('Shortcodes', 'boundary-map'); ?></h2>
            <p><?php esc_html_e('Add one of these shortcodes to a page or post to display the map:', 'boundary-map'); ?></p>

            <table class="widefat fixed striped" style="max-width: 720px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Shortcode', 'boundary-map'); ?></th>
                        <th><?php esc_html_e('Description', 'boundary-map'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[achievements_map]</code></td>
                        <td><?php esc_html_e('Full map with search and categories.', 'boundary-map'); ?></td>
                    </tr>
                    <tr>
                        <td><code>[achievements_map_shape]</code></td>
                        <td><?php esc_html_e('Map with region shape overlay only.', 'boundary-map'); ?></td>
                    </tr>
                </tbody>
            </table>

            <h3><?php esc_html_e('Optional attributes', 'boundary-map'); ?></h3>
            <p><?php esc_html_e('You can customise the map with these attributes:', 'boundary-map'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><code>zoom</code> — <?php esc_html_e('Initial zoom level (e.g. 11.45)', 'boundary-map'); ?></li>
                <li><code>minzoom</code> — <?php esc_html_e('Minimum zoom (default: 10)', 'boundary-map'); ?></li>
                <li><code>maxzoom</code> — <?php esc_html_e('Maximum zoom (default: 17)', 'boundary-map'); ?></li>
                <li><code>scrollwheel</code> — <?php esc_html_e('Enable scroll zoom: 1 = yes, 0 = no (default)', 'boundary-map'); ?></li>
            </ul>
            <p><strong><?php esc_html_e('Example:', 'boundary-map'); ?></strong></p>
            <pre style="background: #f5f5f5; padding: 12px; overflow-x: auto;">[achievements_map zoom="12" minzoom="9" maxzoom="18" scrollwheel="1"]</pre>

            <h2><?php esc_html_e('Data source', 'boundary-map'); ?></h2>
            <p><?php printf(esc_html__('Entries are loaded from: %s', 'boundary-map'), '<strong>' . esc_html($data_source) . '</strong>'); ?></p>

            <h2><?php esc_html_e('REST API', 'boundary-map'); ?></h2>
            <p><?php esc_html_e('Public endpoints used by the map frontend:', 'boundary-map'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><code><?php echo esc_html(rest_url('boundary-map/entries')); ?></code> — <?php esc_html_e('List published entries', 'boundary-map'); ?></li>
                <li><code><?php echo esc_html(rest_url('boundary-map/categories')); ?></code> — <?php esc_html_e('List categories', 'boundary-map'); ?></li>
            </ul>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     * SHORTCODE + FRONT-END ENQUEUE
     * ------------------------------------------------------ */

    public function achievements_map($atts)
{
    $this->enqueue_front_for_shortcode($atts, 'full');

    ob_start();
    ?>
      <div class="boundary-map-wrapper" data-abm-mode="full">
        <?php echo $this->get_body_html(); ?>
      </div>
    <?php
    return ob_get_clean();
}

    
public function achievements_map_shape($atts)
{
    $this->enqueue_front_for_shortcode($atts, 'shape-only');

    ob_start();
    ?>
      <div class="boundary-map-wrapper boundary-map-wrapper--shape-only" data-abm-mode="shape-only">
        <div id="map-container" class="boundary-map-plugin">
          <div id="map"></div>
        </div>
      </div>
    <?php
    return ob_get_clean();
}

private function get_body_html()
    {
        // Load original body HTML
        $html = '<!-- Map -->
        <div class="boundary-map-plugin">
            <nav class="navbar navbar-light  ">
                <div class="container-fluid p-0" id="navbarNav">
                    <!-- Categories (left) -->
                    <ul class="nav d-flex flex-wrap justify-content-center align-items-center gap-2 w-100" id="category-filter">
                    </ul>
                </div>
            </nav>
            <div class="d-flex justify-content-center p-3" >
                <div class="row" style="max-width:300px">
                    <!-- Search bar (right) -->
                    <form class="d-flex ms-auto align-items-center"
                        role="search"
                        id="entry-search-form">
                    <div class="input-group input-group-sm search-container">
                        <span class="input-group-text search-icon">
                        <!-- Magnifying Glass Icon -->
                        <svg xmlns="http://www.w3.org/2000/svg"
                            width="15" height="15"
                            fill="#6c757d"
                            viewBox="0 0 16 16">
                            <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 
                            3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242 
                            1.31a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/>
                        </svg>
                        </span>

                        <input class="form-control p-2"
                        id="entry-search-input" type="search"
                        placeholder="Search entries..."
                        aria-label="Search entries"
                        />
                    </div>
                    </form>
                </div>
            </div>
            <div id="map-container">
                <div id="map"></div>
                <div id="map-legend" class="map-legend"></div>
                <div id="sidebar">
                    <div id="entry-list"></div>
                    <div id="entry-details" class="p-2"></div>
                </div>
            </div>  
        </div>';
        return $html;
    }
}

new Boundary_Map_Plugin();
?>