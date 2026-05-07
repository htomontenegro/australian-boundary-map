<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Frontend_Controller
{
    private $core;

    public function __construct(Boundary_Map_Core $core)
    {
        $this->core = $core;

        add_action('wp_enqueue_scripts', array($this->core, 'enqueue_front_assets'));
        add_shortcode('boundary_map', array($this->core, 'boundary_map'));
        add_shortcode('boundary_map_shape', array($this->core, 'boundary_map_shape'));
        add_shortcode('entries_map', array($this->core, 'boundary_map'));
        add_shortcode('entries_map_shape', array($this->core, 'boundary_map_shape'));
    }
}
