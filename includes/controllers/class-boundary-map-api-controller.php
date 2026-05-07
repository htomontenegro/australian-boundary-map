<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Api_Controller
{
    private $core;

    public function __construct(Boundary_Map_Core $core)
    {
        $this->core = $core;

        add_action('rest_api_init', array($this->core, 'register_rest_routes'));
    }
}
