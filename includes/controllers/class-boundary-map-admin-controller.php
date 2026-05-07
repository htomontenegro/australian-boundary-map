<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Admin_Controller
{
    private $core;

    public function __construct(Boundary_Map_Core $core)
    {
        $this->core = $core;

        add_action('admin_menu', array($this->core, 'register_admin_menu'));
        add_action('admin_init', array($this->core, 'maybe_upgrade_plugin'));
        add_action('admin_init', array($this->core, 'maybe_migrate_on_load'));
        add_action('admin_notices', array($this->core, 'render_support_dashboard_notice'));
        add_action('admin_enqueue_scripts', array($this->core, 'enqueue_admin_assets'));
        add_action('admin_footer', array($this->core, 'remove_admin_menu_separator'));
        add_action('admin_post_boundary_map_bulk_entries', array($this->core, 'handle_bulk_entries'));
        add_action('admin_post_boundary_map_export_entries', array($this->core, 'handle_export_entries'));
        add_action('admin_post_boundary_map_export_categories', array($this->core, 'handle_export_categories'));
        add_action('admin_post_boundary_map_import_entries', array($this->core, 'handle_import_entries'));
        add_action('admin_post_boundary_map_import_categories', array($this->core, 'handle_import_categories'));
        add_action('admin_init', array($this->core, 'handle_single_entry_actions'), 5);
        add_action('admin_init', array($this->core, 'handle_entry_form_submit'), 5);
    }
}
