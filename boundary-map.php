<?php
/*
Plugin Name: Boundary Map
Plugin URI: https://betomxxx.com/plugins/australian-boundary-map
Description: Interactive map for Australian boundaries with admin-managed entries and categories.
Version: 1.3.0
Requires at least: 6.0
Requires PHP: 7.4
Author: htomontenegro
Author URI: https://betomxxx.com
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: boundary-map
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BOUNDARY_MAP_PLUGIN_FILE')) {
    define('BOUNDARY_MAP_PLUGIN_FILE', __FILE__);
}

if (!defined('BOUNDARY_MAP_PLUGIN_DIR')) {
    define('BOUNDARY_MAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('BOUNDARY_MAP_PLUGIN_URL')) {
    define('BOUNDARY_MAP_PLUGIN_URL', plugin_dir_url(__FILE__));
}

require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/class-boundary-map-database.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/components/class-boundary-map-geography-controls-component.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/components/class-boundary-map-public-map-component.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/services/class-boundary-map-feature-registry.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/services/class-boundary-map-access-service.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/services/class-boundary-map-geography-service.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/services/class-boundary-map-entry-service.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/services/class-boundary-map-category-service.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/services/class-boundary-map-csv-service.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/services/class-boundary-map-settings-service.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/admin/class-boundary-map-config-page-renderer.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/admin/class-boundary-map-information-page-renderer.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/class-boundary-map-core.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/controllers/class-boundary-map-admin-controller.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/controllers/class-boundary-map-api-controller.php';
require_once BOUNDARY_MAP_PLUGIN_DIR . 'includes/controllers/class-boundary-map-frontend-controller.php';

register_activation_hook(__FILE__, array('Boundary_Map_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('Boundary_Map_Plugin', 'deactivate'));

class Boundary_Map_Plugin
{
    private $core;
    private $admin_controller;
    private $api_controller;
    private $frontend_controller;

    public function __construct()
    {
        $this->core = new Boundary_Map_Core();
        $this->admin_controller = new Boundary_Map_Admin_Controller($this->core);
        $this->api_controller = new Boundary_Map_Api_Controller($this->core);
        $this->frontend_controller = new Boundary_Map_Frontend_Controller($this->core);
    }

    public static function activate()
    {
        Boundary_Map_Core::activate();
    }

    public static function deactivate()
    {
        Boundary_Map_Core::deactivate();
    }
}

if (!function_exists('boundary_map_feature_enabled')) {
    function boundary_map_feature_enabled($feature_key, $default = false)
    {
        return Boundary_Map_Feature_Registry::is_enabled($feature_key, $default);
    }
}

new Boundary_Map_Plugin();
