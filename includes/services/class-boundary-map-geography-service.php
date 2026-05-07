<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Geography_Service
{
    public static function get_default_config()
    {
        $config = array(
            'defaultScopeId' => 'federal',
            'country' => array(
                'id' => 'australia',
                'label' => 'Australia',
                'displayName' => 'Australia',
                'geojsonUrl' => 'https://geo.abs.gov.au/arcgis/rest/services/ASGS2021/AUS/MapServer/0/query?where=1%3D1&outFields=AUS_NAME_2021&returnGeometry=true&outSR=4326&f=geojson',
                'color' => '#4B7CD5',
            ),
            'scopes' => array(
                array(
                    'id' => 'federal',
                    'label' => 'Federal Parliament',
                    'groupLabel' => 'State or Territory',
                    'itemLabel' => 'Federal Division',
                    'children' => array(
                        array(
                            'id' => 'nsw',
                            'label' => 'New South Wales',
                            'displayName' => 'New South Wales',
                            'color' => '#4B7CD5',
                            'children' => array(
                                array(
                                    'id' => 'sydney',
                                    'label' => 'Sydney',
                                    'displayName' => 'Sydney',
                                    'geojson' => 'federal_sydney_2025.geojson',
                                    'color' => '#4B7CD5',
                                    'children' => array(),
                                ),
                            ),
                        ),
                    ),
                ),
                array(
                    'id' => 'state',
                    'label' => 'State / Territory Parliament',
                    'groupLabel' => 'State or Territory',
                    'itemLabel' => 'State Electorate',
                    'children' => array(
                        array(
                            'id' => 'nsw',
                            'label' => 'New South Wales',
                            'displayName' => 'New South Wales',
                            'color' => '#4B7CD5',
                            'children' => array(),
                        ),
                    ),
                ),
            ),
        );

        return apply_filters('boundary_map_default_geography_config', $config);
    }

    public static function load_config()
    {
        $config_file = BOUNDARY_MAP_PLUGIN_DIR . 'assets/geographies.json';
        if (!file_exists($config_file)) {
            return self::get_default_config();
        }

        $raw = file_get_contents($config_file);
        $data = json_decode($raw, true);

        if (!is_array($data) || empty($data['scopes']) || !is_array($data['scopes'])) {
            return self::get_default_config();
        }

        return apply_filters('boundary_map_geography_config', $data, $config_file);
    }

    public static function load_config_for_activation()
    {
        return self::load_config();
    }

    public static function get_default_selection($config = null)
    {
        $config = is_array($config) ? $config : self::load_config();
        $scope = !empty($config['scopes'][0]) ? $config['scopes'][0] : null;

        if (!empty($config['defaultScopeId'])) {
            $default_scope = self::get_scope_by_id($config, $config['defaultScopeId']);
            if ($default_scope) {
                $scope = $default_scope;
            }
        }

        $area = null;
        if (!empty($scope['children']) && is_array($scope['children'])) {
            $area = self::get_child_by_id($scope['children'], 'nsw');
            if (!$area) {
                $area = $scope['children'][0];
            }
        }

        $subdivision = null;
        if (!empty($area['children']) && is_array($area['children'])) {
            $subdivision = self::get_child_by_id($area['children'], 'sydney');

            if (!$subdivision) {
                $subdivision = $area['children'][0];
            }
        }

        $selection = array(
            'country' => 'australia',
            'scope' => !empty($scope['id']) ? $scope['id'] : '',
            'area' => !empty($area['id']) ? $area['id'] : '',
            'subdivision' => !empty($subdivision['id']) ? $subdivision['id'] : '',
        );

        return apply_filters('boundary_map_default_geography_selection', $selection, $config);
    }

    public static function get_activation_default_selection()
    {
        return self::get_default_selection(self::load_config_for_activation());
    }

    public static function sanitize_selection($selection, $config = null, $defaults = null)
    {
        $config = is_array($config) ? $config : self::load_config();
        $defaults = is_array($defaults) ? $defaults : self::get_default_selection($config);

        $sanitized = array(
            'country' => 'australia',
            'scope' => isset($selection['scope']) ? sanitize_key($selection['scope']) : $defaults['scope'],
            'area' => isset($selection['area']) ? sanitize_key($selection['area']) : '',
            'subdivision' => isset($selection['subdivision']) ? sanitize_key($selection['subdivision']) : '',
        );

        if ($sanitized['scope'] === '') {
            $sanitized['area'] = '';
            $sanitized['subdivision'] = '';
            return apply_filters('boundary_map_sanitized_geography_selection', $sanitized, $selection, $config, $defaults);
        }

        $scope = self::get_scope_by_id($config, $sanitized['scope']);
        if (!$scope) {
            return apply_filters('boundary_map_sanitized_geography_selection', $defaults, $selection, $config, $defaults);
        }

        $scope_children = isset($scope['children']) ? $scope['children'] : array();

        if ($sanitized['area'] === '') {
            $sanitized['subdivision'] = '';
            return apply_filters('boundary_map_sanitized_geography_selection', $sanitized, $selection, $config, $defaults);
        }

        $area = self::get_child_by_id($scope_children, $sanitized['area']);

        if (!$area) {
            $migrated_path = null;

            if (!empty($sanitized['subdivision'])) {
                $migrated_path = self::find_nested_path_by_id($scope, $sanitized['subdivision']);
            }

            if (!$migrated_path && !empty($sanitized['area'])) {
                $migrated_path = self::find_nested_path_by_id($scope, $sanitized['area']);
            }

            if ($migrated_path) {
                $area = $migrated_path['area'];
                $sanitized['area'] = !empty($area['id']) ? $area['id'] : '';
                $sanitized['subdivision'] = !empty($migrated_path['subdivision']['id']) ? $migrated_path['subdivision']['id'] : '';
            }
        }

        if (!$area) {
            $sanitized['area'] = !empty($scope_children[0]['id']) ? $scope_children[0]['id'] : '';
            $area = self::get_child_by_id($scope_children, $sanitized['area']);
        }

        if ($area && !empty($area['children']) && is_array($area['children'])) {
            if (!empty($sanitized['subdivision'])) {
                $subdivision = self::get_child_by_id($area['children'], $sanitized['subdivision']);
                if (!$subdivision) {
                    $sanitized['subdivision'] = '';
                }
            } else {
                $sanitized['subdivision'] = '';
            }
        } else {
            $sanitized['subdivision'] = '';
        }

        return apply_filters('boundary_map_sanitized_geography_selection', $sanitized, $selection, $config, $defaults);
    }

    public static function get_shortcode_selection($atts, $saved_selection, $config = null)
    {
        $config = is_array($config) ? $config : self::load_config();
        $defaults = self::get_default_selection($config);

        $raw_selection = array(
            'country' => isset($atts['country']) ? sanitize_key($atts['country']) : '',
            'scope' => isset($atts['scope']) ? sanitize_key($atts['scope']) : '',
            'area' => isset($atts['area']) ? sanitize_key($atts['area']) : '',
            'subdivision' => isset($atts['subdivision']) ? sanitize_key($atts['subdivision']) : '',
        );

        if (
            empty($raw_selection['country']) &&
            empty($raw_selection['scope']) &&
            empty($raw_selection['area']) &&
            empty($raw_selection['subdivision'])
        ) {
            return self::sanitize_selection($saved_selection, $config, $defaults);
        }

        if ($raw_selection['scope'] === 'country') {
            return array(
                'country' => 'australia',
                'scope' => '',
                'area' => '',
                'subdivision' => '',
            );
        }

        return self::sanitize_selection($raw_selection, $config, $defaults);
    }

    public static function get_selected_node($selection = null, $config = null, $saved_selection = null)
    {
        $config = is_array($config) ? $config : self::load_config();
        $defaults = self::get_default_selection($config);
        $selection = is_array($selection)
            ? self::sanitize_selection($selection, $config, $defaults)
            : self::sanitize_selection($saved_selection, $config, $defaults);

        if (empty($selection['scope'])) {
            return self::get_country_node($config);
        }

        $scope = self::get_scope_by_id($config, $selection['scope']);
        if (!$scope) {
            return null;
        }

        $area = self::get_child_by_id(isset($scope['children']) ? $scope['children'] : array(), $selection['area']);
        if (!$area) {
            return null;
        }

        if (!empty($area['children']) && is_array($area['children'])) {
            $subdivision = self::get_child_by_id($area['children'], $selection['subdivision']);
            if ($subdivision) {
                return $subdivision;
            }
        }

        return $area;
    }

    public static function get_boundary_url($selection = null, $config = null, $saved_selection = null)
    {
        $config = is_array($config) ? $config : self::load_config();
        $defaults = self::get_default_selection($config);
        $active_selection = is_array($selection)
            ? self::sanitize_selection($selection, $config, $defaults)
            : self::sanitize_selection($saved_selection, $config, $defaults);

        if (empty($active_selection['scope'])) {
            $node = self::get_country_node($config);
        } else {
            $node = self::get_selected_node($active_selection, $config, $active_selection);
            if (!$node && empty($active_selection['area'])) {
                $node = self::get_country_node($config);
            }
        }

        if (!$node) {
            return '';
        }

        if (!empty($node['geojsonUrl'])) {
            return apply_filters(
                'boundary_map_geography_boundary_url',
                esc_url_raw($node['geojsonUrl']),
                $node,
                $active_selection,
                $config
            );
        }

        if (!empty($node['geojson'])) {
            $geojson = ltrim($node['geojson'], '/');
            if (preg_match('#^https?://#i', $geojson)) {
                return apply_filters(
                    'boundary_map_geography_boundary_url',
                    esc_url_raw($geojson),
                    $node,
                    $active_selection,
                    $config
                );
            }

            return apply_filters(
                'boundary_map_geography_boundary_url',
                BOUNDARY_MAP_PLUGIN_URL . 'assets/' . $geojson,
                $node,
                $active_selection,
                $config
            );
        }

        return apply_filters('boundary_map_geography_boundary_url', '', $node, $active_selection, $config);
    }

    private static function get_scope_by_id($config, $scope_id)
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

    private static function get_country_node($config)
    {
        if (!empty($config['country']) && is_array($config['country'])) {
            return $config['country'];
        }

        $default_config = self::get_default_config();

        return $default_config['country'];
    }

    private static function get_child_by_id($children, $child_id)
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

    private static function find_nested_path_by_id($scope, $node_id)
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
}
