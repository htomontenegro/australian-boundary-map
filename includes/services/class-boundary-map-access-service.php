<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Access_Service
{
    private const DEFAULT_MANAGE_CAPABILITY = 'edit_others_posts';

    public static function get_manage_capability()
    {
        return (string) apply_filters('boundary_map_manage_capability', self::DEFAULT_MANAGE_CAPABILITY);
    }

    public static function can_manage_plugin($user_id = 0)
    {
        $capability = self::get_manage_capability();
        $allowed = $user_id ? user_can($user_id, $capability) : current_user_can($capability);

        return (bool) apply_filters('boundary_map_can_manage', $allowed, $capability, $user_id);
    }

    public static function can_read_rest_collection($request, $resource = 'public')
    {
        $resource = sanitize_key((string) $resource);
        $public_rest_enabled = Boundary_Map_Feature_Registry::is_enabled('public_rest_api', true);

        $allowed = $public_rest_enabled ? true : self::can_manage_plugin();

        return (bool) apply_filters('boundary_map_rest_permission', $allowed, $request, $resource);
    }
}
