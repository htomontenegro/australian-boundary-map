<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Geography_Controls_Component
{
    public static function render()
    {
        ob_start();
        ?>
        <div class="ach-map-geography-controls" id="ach-map-geography-controls">
            <div class="ach-map-geography-field">
                <label for="ach-map-scope"><?php esc_html_e('Map Type', 'boundary-map'); ?></label>
                <select id="ach-map-scope" class="form-select">
                    <option value=""><?php esc_html_e('Select a map type', 'boundary-map'); ?></option>
                </select>
            </div>
            <div class="ach-map-geography-field">
                <label for="ach-map-area" id="ach-map-area-label"><?php esc_html_e('Area', 'boundary-map'); ?></label>
                <select id="ach-map-area" class="form-select">
                    <option value=""><?php esc_html_e('Select an area', 'boundary-map'); ?></option>
                </select>
            </div>
            <div class="ach-map-geography-field" id="ach-map-subdivision-field" hidden>
                <label for="ach-map-subdivision" id="ach-map-subdivision-label"><?php esc_html_e('Subdivision', 'boundary-map'); ?></label>
                <select id="ach-map-subdivision" class="form-select">
                    <option value=""><?php esc_html_e('Select a subdivision', 'boundary-map'); ?></option>
                </select>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }
}
