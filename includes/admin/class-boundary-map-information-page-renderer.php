<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Information_Page_Renderer
{
    public static function render($data_source)
    {
        ?>
        <div class="wrap ach-map-admin">
            <h1><?php esc_html_e('Australian Boundary Map – Information', 'boundary-map'); ?></h1>

            <h2><?php esc_html_e('Shortcodes', 'boundary-map'); ?></h2>
            <p><?php esc_html_e('Use these shortcodes to display the public map on a page or post.', 'boundary-map'); ?></p>

            <table class="widefat fixed striped" style="max-width: 720px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Shortcode', 'boundary-map'); ?></th>
                        <th><?php esc_html_e('Description', 'boundary-map'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><code>[boundary_map]</code></td>
                        <td><?php esc_html_e('Full public map with entries, search, categories, legend, and boundary overlay.', 'boundary-map'); ?></td>
                    </tr>
                    <tr>
                        <td><code>[boundary_map_shape]</code></td>
                        <td><?php esc_html_e('Shape-only map that displays the selected boundary without entry markers or sidebar UI.', 'boundary-map'); ?></td>
                    </tr>
                </tbody>
            </table>
            <p><?php esc_html_e('Legacy shortcode aliases are still supported for backward compatibility: entries_map and entries_map_shape.', 'boundary-map'); ?></p>

            <h3><?php esc_html_e('Boundary targeting', 'boundary-map'); ?></h3>
            <p><?php esc_html_e('By default, both shortcodes use the saved selection from the Config screen. You can also target a specific geography directly in the shortcode.', 'boundary-map'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><code>scope</code> — <?php esc_html_e('Use country, federal, or state.', 'boundary-map'); ?></li>
                <li><code>area</code> — <?php esc_html_e('State or territory id such as nsw, vic, qld, act, or nt.', 'boundary-map'); ?></li>
                <li><code>subdivision</code> — <?php esc_html_e('Region or division id such as wentworth or blacktown.', 'boundary-map'); ?></li>
            </ul>
            <p><strong><?php esc_html_e('Examples:', 'boundary-map'); ?></strong></p>
            <pre style="background: #f5f5f5; padding: 12px; overflow-x: auto;">[boundary_map scope="country"]</pre>
            <pre style="background: #f5f5f5; padding: 12px; overflow-x: auto;">[boundary_map scope="federal" area="nsw" subdivision="wentworth"]</pre>

            <h3><?php esc_html_e('Optional attributes', 'boundary-map'); ?></h3>
            <p><?php esc_html_e('You can customise the display and zoom behaviour with these attributes:', 'boundary-map'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><code>width</code> — <?php esc_html_e('Map width. Use values like fit-container, 960px, 100%, or 80vw.', 'boundary-map'); ?></li>
                <li><code>height</code> — <?php esc_html_e('Map height. Use values like 600px, 70vh, or a custom CSS size.', 'boundary-map'); ?></li>
                <li><code>zoommode</code> — <?php esc_html_e('Use fit to automatically fit the selected boundary, or custom to use a fixed zoom level.', 'boundary-map'); ?></li>
                <li><code>zoom</code> — <?php esc_html_e('Initial zoom level (e.g. 11.45)', 'boundary-map'); ?></li>
                <li><code>minzoom</code> — <?php esc_html_e('Minimum zoom (default: 10)', 'boundary-map'); ?></li>
                <li><code>maxzoom</code> — <?php esc_html_e('Maximum zoom (default: 17)', 'boundary-map'); ?></li>
                <li><code>scrollwheel</code> — <?php esc_html_e('Enable scroll zoom: 1 = yes, 0 = no (default)', 'boundary-map'); ?></li>
                <li><code>categorybox</code> — <?php esc_html_e('Show the category legend box on the map: 1 = yes (default), 0 = no.', 'boundary-map'); ?></li>
                <li><code>sidebarpanel</code> — <?php esc_html_e('Show the floating entry panel on the map: 1 = yes (default), 0 = no.', 'boundary-map'); ?></li>
                <li><code>markertag</code> — <?php esc_html_e('Control marker labels: clickable (default) or visible.', 'boundary-map'); ?></li>
            </ul>
            <p><strong><?php esc_html_e('Example:', 'boundary-map'); ?></strong></p>
            <pre style="background: #f5f5f5; padding: 12px; overflow-x: auto;">[boundary_map scope="federal" area="nsw" subdivision="wentworth" width="fit-container" height="70vh" zoommode="fit" minzoom="9" maxzoom="18" scrollwheel="1" categorybox="0" sidebarpanel="0" markertag="visible"]</pre>

            <h2><?php esc_html_e('Config screen', 'boundary-map'); ?></h2>
            <p><?php esc_html_e('The Config screen lets you choose the saved default boundary, preview it on the map, and generate shortcodes with matching geography and display settings.', 'boundary-map'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><?php esc_html_e('Boundary Selection sets the saved default geography for the plugin.', 'boundary-map'); ?></li>
                <li><?php esc_html_e('Boundary Preview shows the current selected country, state, region, or division.', 'boundary-map'); ?></li>
                <li><?php esc_html_e('Map display settings let you save whether the public entry panel is shown by default.', 'boundary-map'); ?></li>
                <li><?php esc_html_e('Map display settings also control whether marker tags are click-to-open or always visible.', 'boundary-map'); ?></li>
                <li><?php esc_html_e('Shortcode Generator creates ready-to-paste shortcodes with width, height, zoom, scroll-wheel, category-box, entry-panel, and marker-tag options.', 'boundary-map'); ?></li>
            </ul>

            <h2><?php esc_html_e('Data source', 'boundary-map'); ?></h2>
            <p><?php printf(esc_html__('Entries are loaded from: %s', 'boundary-map'), '<strong>' . esc_html($data_source) . '</strong>'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><?php esc_html_e('Categories can be seeded from assets/categories.json on activation, while entries can be added in the WordPress admin or imported separately.', 'boundary-map'); ?></li>
                <li><?php esc_html_e('The geography hierarchy is bundled in assets/geographies.json.', 'boundary-map'); ?></li>
                <li><?php esc_html_e('The saved default boundary selection is stored in the WordPress option boundary_map_geography_selection.', 'boundary-map'); ?></li>
                <li><?php esc_html_e('Most boundary polygons are loaded from official ABS GeoJSON service URLs referenced in geographies.json.', 'boundary-map'); ?></li>
            </ul>

            <h2><?php esc_html_e('REST API', 'boundary-map'); ?></h2>
            <p><?php esc_html_e('Public endpoints used by the frontend map:', 'boundary-map'); ?></p>
            <ul style="list-style: disc; margin-left: 20px;">
                <li><code><?php echo esc_html(rest_url('boundary-map/entries')); ?></code> — <?php esc_html_e('List published entries', 'boundary-map'); ?></li>
                <li><code><?php echo esc_html(rest_url('boundary-map/categories')); ?></code> — <?php esc_html_e('List categories', 'boundary-map'); ?></li>
            </ul>
            <p><?php esc_html_e('Legacy REST endpoints under entries-map remain available for backward compatibility.', 'boundary-map'); ?></p>

            <h2><?php esc_html_e('Activation behaviour', 'boundary-map'); ?></h2>
            <p><?php esc_html_e('When the plugin is activated, it creates the database tables, migrates the bundled starter entries and categories, and seeds the default geography selection so the plugin starts with a valid saved boundary.', 'boundary-map'); ?></p>
        </div>
        <?php
    }
}
