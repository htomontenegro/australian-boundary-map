<?php
/*
Plugin Name: Achievements Map
Description: Interactive Leaflet map with achievements & categories managed from WP Admin.
Version: 1.1.1
Author: You
*/

if (!defined('ABSPATH')) {
    exit;
}

class Achievements_Map_Plugin
{

    private $achievements_file;
    private $categories_file;
    private $version = '1.1.1';

    public function __construct()
    {
        $this->achievements_file = plugin_dir_path(__FILE__) . 'assets/entries.json';
        $this->categories_file = plugin_dir_path(__FILE__) . 'assets/categories.json';

        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_shortcode('achievements_map', array($this, 'achievements_map'));
        add_shortcode('achievements_map_shape', array($this, 'achievements_map_shape'));

        // Basic capability check later in handlers
        // // NEW: REST routes
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_front_assets'));
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
            'achievements-map',
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
            'achievements-map-app',
            plugin_dir_url(__FILE__) . 'assets/script.js',
            array('leaflet', 'bootstrap-5', 'fuse-js'),
            $this->version,
            true
        );

        wp_localize_script(
            'achievements-map-app',
            'ACH_MAP_CONFIG',
            array(
                'achievementsApiUrl' => rest_url('achievements-map/achievements'),
                'categoriesApiUrl' => rest_url('achievements-map/categories'),
                'regionUrl' => plugins_url('assets/E_NSW24_region1.json', __FILE__),
            )
        );

        wp_enqueue_style('leaflet');
        wp_enqueue_style('bootstrap-5');
        wp_enqueue_style('achievements-map');

        wp_enqueue_script('leaflet');
        wp_enqueue_script('bootstrap-5');
        wp_enqueue_script('fuse-js');
        wp_enqueue_script('achievements-map-app');
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
    wp_enqueue_style('achievements-map');

    wp_enqueue_script('leaflet');
    wp_enqueue_script('bootstrap-5');
    wp_enqueue_script('fuse-js');
    wp_enqueue_script('achievements-map-app');

    // pick zoom: shortcode zoom if provided, else default
    $zoom = ($atts['zoom'] !== '' ? floatval($atts['zoom']) : 11.45);

    wp_localize_script(
        'achievements-map-app',
        'ACH_MAP_CONFIG',
        array(
            'mode' => $mode, // 'full' or 'shape-only'
            'zoom' => $zoom,
            'minZoom' => intval($atts['minzoom']),
            'maxZoom' => intval($atts['maxzoom']),
            'scrollWheelZoom' => !empty($atts['scrollwheel']) ? true : false,

            'achievementsApiUrl' => rest_url('achievements-map/achievements'),
            'categoriesApiUrl' => rest_url('achievements-map/categories'),
            'regionUrl' => plugins_url('assets/E_NSW24_region1.json', __FILE__),
        )
    );
}


    private function load_achievements()
    {
        if (!file_exists($this->achievements_file)) {
            return array();
        }
        $raw = file_get_contents($this->achievements_file);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : array();
    }

    private function save_achievements($achievements)
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $json = json_encode(array_values($achievements), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->achievements_file, $json);
    }

    private function load_categories()
    {
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
        // fallback if file is just an array
        return $data;
    }

    private function save_categories($categories)
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $wrapper = array('categories' => array_values($categories));
        $json = json_encode($wrapper, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->categories_file, $json);
    }

    /* ---------------------------------------------------------
     * Admin Menu / Assets
     * ------------------------------------------------------ */

    public function register_admin_menu()
    {
        add_menu_page(
            __('Achievements Map', 'achievements-map'),
            __('Achievements Map', 'achievements-map'),
            'manage_options',
            'achievements-map-achievements',
            array($this, 'render_achievements_page'),
            'dashicons-location-alt',
            26
        );

        add_submenu_page(
            'achievements-map-achievements',
            __('Achievements', 'achievements-map'),
            __('Achievements', 'achievements-map'),
            'manage_options',
            'achievements-map-achievements',
            array($this, 'render_achievements_page')
        );

        add_submenu_page(
            'achievements-map-achievements',
            __('Categories', 'achievements-map'),
            __('Categories', 'achievements-map'),
            'manage_options',
            'achievements-map-categories',
            array($this, 'render_categories_page')
        );
    }

    public function enqueue_admin_assets($hook)
    {
        if (strpos($hook, 'achievements-map') === false) {
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
            'achievements-map-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            array('wp-color-picker'),
            $this->version
        );

        wp_enqueue_script(
            'achievements-map-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.js',
            array('jquery', 'leaflet', 'wp-color-picker'),
            $this->version,
            true
        );

        wp_localize_script(
            'achievements-map-admin',
            'ACH_MAP_ADMIN',
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
            'achievements-map',
            '/achievements',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_get_achievements'),
                'permission_callback' => '__return_true', // public
            )
        );

        register_rest_route(
            'achievements-map',
            '/categories',
            array(
                'methods' => 'GET',
                'callback' => array($this, 'rest_get_categories'),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function rest_get_achievements($request)
    {
        // This uses your existing loader (which currently reads from entries.json,
        // i.e. whatever is stored via the “Achievements” admin screen).
        $achievements = $this->load_achievements();
        return rest_ensure_response($achievements);
    }

    public function rest_get_categories($request)
    {
        $categories = $this->load_categories();
        return rest_ensure_response($categories);
    }

    /* ---------------------------------------------------------
     * ACHIEVEMENTS ADMIN PAGE (CRUD)
     * ------------------------------------------------------ */

    public function render_achievements_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'achievements-map'));
        }

        $achievements = $this->load_achievements();
        $categories = $this->load_categories();

        // ----- Handle create/update -----
        if (isset($_POST['ach_achievement_submit'])) {
            check_admin_referer('achievements_map_save_achievement');

            $index = isset($_POST['achievement_index']) && $_POST['achievement_index'] !== '' ? intval($_POST['achievement_index']) : -1;

            $title = isset($_POST['title']) ? sanitize_text_field($_POST['title']) : '';
            $description = isset($_POST['description']) ? wp_kses_post($_POST['description']) : '';
            $location = isset($_POST['location']) ? sanitize_text_field($_POST['location']) : '';
            $image = isset($_POST['image']) ? esc_url_raw($_POST['image']) : '';

            $category_id = isset($_POST['category_id']) ? sanitize_text_field($_POST['category_id']) : '';
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

            $achievement_data = array(
                'title' => $title,
                'categoryId' => $category_id,
                'category' => $category_label,
                'description' => $description,
                'location' => $location,
                'coords' => $coords,
                'image' => $image,
            );

            if ($index >= 0 && isset($achievements[$index])) {
                $achievements[$index] = $achievement_data;
                $message = __('Achievement updated.', 'achievements-map');
            } else {
                $achievements[] = $achievement_data;
                $message = __('Achievement added.', 'achievements-map');
            }

            $this->save_achievements($achievements);

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        // ----- Handle delete -----
        if (isset($_GET['action'], $_GET['index']) && $_GET['action'] === 'delete') {
            $index = intval($_GET['index']);
            check_admin_referer('achievements_map_delete_achievement_' . $index);

            if (isset($achievements[$index])) {
                unset($achievements[$index]);
                $this->save_achievements($achievements);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Achievement deleted.', 'achievements-map') . '</p></div>';
            }
        }

        // Refresh after mutations
        $achievements = array_values($this->load_achievements());

        $edit_index = -1;
        $edit_achievement = null;
        if (isset($_GET['action'], $_GET['index']) && $_GET['action'] === 'edit') {
            $idx = intval($_GET['index']);
            if (isset($achievements[$idx])) {
                $edit_index = $idx;
                $edit_achievement = $achievements[$idx];
            }
        }

        ?>
        <div class="wrap ach-map-admin">
            <h1><?php esc_html_e('Achievements Map – Achievements', 'achievements-map'); ?></h1>

            <h2><?php echo $edit_achievement ? esc_html__('Edit Achievement', 'achievements-map') : esc_html__('Add New Achievement', 'achievements-map'); ?>
            </h2>

            <form method="post" class="ach-form">
                <?php wp_nonce_field('achievements_map_save_achievement'); ?>
                <input type="hidden" name="achievement_index"
                    value="<?php echo $edit_index >= 0 ? esc_attr($edit_index) : ''; ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="ach-title"><?php esc_html_e('Title', 'achievements-map'); ?></label></th>
                        <td><input name="title" id="ach-title" type="text" class="regular-text" required
                                value="<?php echo $edit_achievement ? esc_attr($edit_achievement['title']) : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="ach-category"><?php esc_html_e('Category', 'achievements-map'); ?></label></th>
                        <td>
                            <select name="category_id" id="ach-category">
                                <?php
                                foreach ($categories as $cat) {
                                    $id = isset($cat['id']) ? $cat['id'] : '';
                                    $label = isset($cat['label']) ? $cat['label'] : $id;
                                    if ($id === 'All') {
                                        continue;
                                    }
                                    printf(
                                        '<option value="%1$s"%3$s>%2$s</option>',
                                        esc_attr($id),
                                        esc_html($label),
                                        $edit_achievement && isset($edit_achievement['categoryId']) && $edit_achievement['categoryId'] === $id ? ' selected' : ''
                                    );
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-location"><?php esc_html_e('Location', 'achievements-map'); ?></label></th>
                        <td><input name="location" id="ach-location" type="text" class="regular-text"
                                value="<?php echo $edit_achievement ? esc_attr($edit_achievement['location']) : ''; ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="ach-description"><?php esc_html_e('Description', 'achievements-map'); ?></label></th>
                        <td>
                            <textarea name="description" id="ach-description" rows="4" class="large-text"><?php
                            echo $edit_achievement ? esc_textarea($edit_achievement['description']) : '';
                            ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Coordinates (Lat, Lng)', 'achievements-map'); ?></th>
                        <td>
                            <?php
                            $lat = '';
                            $lng = '';
                            if ($edit_achievement && !empty($edit_achievement['coords']) && is_array($edit_achievement['coords'])) {
                                $lat = $edit_achievement['coords'][0];
                                $lng = $edit_achievement['coords'][1];
                            }
                            ?>
                            <input name="lat" id="ach-lat" type="text" class="medium-text" placeholder="-33.6"
                                value="<?php echo esc_attr($lat); ?>" />
                            <input name="lng" id="ach-lng" type="text" class="medium-text" placeholder="151.1"
                                value="<?php echo esc_attr($lng); ?>" />

                            <button type="button" class="button" id="ach-geo-btn" style="margin-left:8px;">
                                <?php esc_html_e('Get from address', 'achievements-map'); ?>
                            </button>

                            <p class="description">
                                <?php esc_html_e('Use the button to geocode from Location, or drag/click on the map below.', 'achievements-map'); ?>
                            </p>

                            <!-- Mini map -->
                            <div id="ach-map"
                                style="height: 320px; max-width: 600px; margin-top: 10px; border: 1px solid #ddd;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-image"><?php esc_html_e('Image', 'achievements-map'); ?></label></th>
                        <td>
                            <!-- Hidden/text field that actually stores the URL -->
                            <input name="image" id="ach-image" type="text" class="regular-text"
                                value="<?php echo $edit_achievement ? esc_url($edit_achievement['image']) : ''; ?>" />

                            <button type="button" class="button" id="ach-image-btn" style="margin-top:6px;">
                                <?php esc_html_e('Upload / Select Image', 'achievements-map'); ?>
                            </button>

                            <div id="ach-image-preview" style="margin-top:10px;">
                                <?php if (!empty($edit_achievement['image'])): ?>
                                    <img src="<?php echo esc_url($edit_achievement['image']); ?>" alt=""
                                        style="max-width:200px;height:auto;display:block;border:1px solid #ddd;padding:2px;" />
                                <?php endif; ?>
                            </div>

                            <p class="description">
                                <?php esc_html_e('Choose or upload an image from the Media Library.', 'achievements-map'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button($edit_achievement ? __('Update Achievement', 'achievements-map') : __('Add Achievement', 'achievements-map'), 'primary', 'ach_achievement_submit'); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('All Achievements', 'achievements-map'); ?></h2>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('#', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('Title', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('Category', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('Location', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('Has Coords?', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('Actions', 'achievements-map'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($achievements)): ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e('No achievements yet.', 'achievements-map'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($achievements as $i => $ev):
                            $edit_url = add_query_arg(
                                array(
                                    'page' => 'achievements-map-achievements',
                                    'action' => 'edit',
                                    'index' => $i,
                                ),
                                admin_url('admin.php')
                            );
                            $delete_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'page' => 'achievements-map-achievements',
                                        'action' => 'delete',
                                        'index' => $i,
                                    ),
                                    admin_url('admin.php')
                                ),
                                'achievements_map_delete_achievement_' . $i
                            );
                            ?>
                            <tr>
                                <td><?php echo esc_html($i); ?></td>
                                <td><?php echo esc_html(isset($ev['title']) ? $ev['title'] : ''); ?></td>
                                <td><?php echo esc_html(isset($ev['category']) ? $ev['category'] : (isset($ev['categoryId']) ? $ev['categoryId'] : '')); ?>
                                </td>
                                <td><?php echo esc_html(isset($ev['location']) ? $ev['location'] : ''); ?></td>
                                <td><?php echo !empty($ev['coords']) && is_array($ev['coords']) ? esc_html__('Yes', 'achievements-map') : esc_html__('No', 'achievements-map'); ?>
                                </td>
                                <td>
                                    <a class="button button-small"
                                        href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'achievements-map'); ?></a>
                                    <a class="button button-small button-link-delete" href="<?php echo esc_url($delete_url); ?>"
                                        onclick="return confirm('<?php echo esc_js(__('Delete this achievement?', 'achievements-map')); ?>');"><?php esc_html_e('Delete', 'achievements-map'); ?></a>
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
     * CATEGORIES ADMIN PAGE (CRUD)
     * ------------------------------------------------------ */

    public function render_categories_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'achievements-map'));
        }

        $categories = $this->load_categories();

        // ----- Handle create/update -----
        if (isset($_POST['ach_category_submit'])) {
            check_admin_referer('achievements_map_save_category');

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
                    $message = __('Category updated.', 'achievements-map');
                } else {
                    $categories[] = $cat_data;
                    $message = __('Category added.', 'achievements-map');
                }

                $this->save_categories($categories);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
            }
        }

        // ----- Handle delete -----
        if (isset($_GET['action'], $_GET['index']) && $_GET['action'] === 'delete') {
            $index = intval($_GET['index']);
            check_admin_referer('achievements_map_delete_category_' . $index);

            if (isset($categories[$index])) {
                unset($categories[$index]);
                $this->save_categories($categories);
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Category deleted.', 'achievements-map') . '</p></div>';
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
            <h1><?php esc_html_e('Achievements Map – Categories', 'achievements-map'); ?></h1>

            <h2><?php echo $edit_cat ? esc_html__('Edit Category', 'achievements-map') : esc_html__('Add New Category', 'achievements-map'); ?>
            </h2>

            <form method="post" class="ach-form">
                <?php wp_nonce_field('achievements_map_save_category'); ?>
                <input type="hidden" name="category_index"
                    value="<?php echo $edit_index >= 0 ? esc_attr($edit_index) : ''; ?>" />

                <table class="form-table">
                    <tr>
                        <th><label for="ach-cat-id"><?php esc_html_e('Category ID', 'achievements-map'); ?></label></th>
                        <td>
                            <input name="id" id="ach-cat-id" type="text" class="regular-text" required
                                value="<?php echo $edit_cat ? esc_attr($edit_cat['id']) : ''; ?>" />
                            <p class="description">
                                <?php esc_html_e('Used internally and in entries.json (e.g., Music, Food).', 'achievements-map'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-cat-color"><?php esc_html_e('Colour (optional)', 'achievements-map'); ?></label>
                        </th>
                        <td>
                            <input name="color" id="ach-cat-color" type="text" class="regular-text ach-color-field"
                                placeholder="#44db12"
                                value="<?php echo $edit_cat && !empty($edit_cat['color']) ? esc_attr($edit_cat['color']) : ''; ?>" />
                            <p class="description">
                                <?php esc_html_e('Hex colour used for badges & legend (e.g., #44db12). Leave blank to use default.', 'achievements-map'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-cat-icon"><?php esc_html_e('Icon (optional)', 'achievements-map'); ?></label></th>
                        <td>
                            <input name="icon" id="ach-cat-icon" type="text" class="regular-text" placeholder="🎷"
                                value="<?php echo $edit_cat && !empty($edit_cat['icon']) ? esc_attr($edit_cat['icon']) : ''; ?>" />
                            <p class="description">
                                <?php esc_html_e('Optional emoji or short text shown next to the category label.', 'achievements-map'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ach-cat-label"><?php esc_html_e('Label', 'achievements-map'); ?></label></th>
                        <td>
                            <input name="label" id="ach-cat-label" type="text" class="regular-text"
                                value="<?php echo $edit_cat ? esc_attr($edit_cat['label']) : ''; ?>" />
                            <p class="description">
                                <?php esc_html_e('Human-readable label shown in the UI.', 'achievements-map'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button($edit_cat ? __('Update Category', 'achievements-map') : __('Add Category', 'achievements-map'), 'primary', 'ach_category_submit'); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('All Categories', 'achievements-map'); ?></h2>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('#', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('ID', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('Label', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('Colour', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('Icon', 'achievements-map'); ?></th>
                        <th><?php esc_html_e('Actions', 'achievements-map'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($categories)): ?>
                        <tr>
                            <td colspan="4"><?php esc_html_e('No categories yet.', 'achievements-map'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($categories as $i => $cat):
                            $edit_url = add_query_arg(
                                array(
                                    'page' => 'achievements-map-categories',
                                    'action' => 'edit',
                                    'index' => $i,
                                ),
                                admin_url('admin.php')
                            );
                            $delete_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'page' => 'achievements-map-categories',
                                        'action' => 'delete',
                                        'index' => $i,
                                    ),
                                    admin_url('admin.php')
                                ),
                                'achievements_map_delete_category_' . $i
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
                                        <span class="description"><?php esc_html_e('Default', 'achievements-map'); ?></span>
                                    <?php endif; ?>
                                </td>

                                <!-- Icon column -->
                                <td>
                                    <?php echo !empty($cat['icon']) ? esc_html($cat['icon']) : ''; ?>
                                </td>

                                <!-- Actions -->
                                <td>
                                    <a class="button button-small"
                                        href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'achievements-map'); ?></a>
                                    <a class="button button-small button-link-delete" href="<?php echo esc_url($delete_url); ?>"
                                        onclick="return confirm('<?php echo esc_js(__('Delete this category?', 'achievements-map')); ?>');">
                                        <?php esc_html_e('Delete', 'achievements-map'); ?>
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
     * SHORTCODE + FRONT-END ENQUEUE
     * ------------------------------------------------------ */

    public function achievements_map($atts)
{
    $this->enqueue_front_for_shortcode($atts, 'full');

    ob_start();
    ?>
      <div class="achievements-map-wrapper" data-ach-map-mode="full">
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
      <div class="achievements-map-wrapper achievements-map-wrapper--shape-only" data-ach-map-mode="shape-only">
        <div id="map-container" class="achievements-map-plugin">
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
        <div class="achievements-map-plugin">
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
                        id="achievement-search-form">
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
                        id="achievement-search-input" type="search"
                        placeholder="Search achievements..."
                        aria-label="Search achievements"
                        />
                    </div>
                    </form>
                </div>
            </div>
            <div id="map-container">
                <div id="map"></div>
                <div id="map-legend" class="map-legend"></div>
                <div id="sidebar">
                    <div id="achievement-list"></div>
                    <div id="achievement-details" class="p-2"></div>
                </div>
            </div>  
        </div>';
        return $html;
    }
}

new Achievements_Map_Plugin();
?>