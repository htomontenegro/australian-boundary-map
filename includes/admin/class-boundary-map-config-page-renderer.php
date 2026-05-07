<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Config_Page_Renderer
{
    public static function render($args)
    {
        $selected_node = isset($args['selected_node']) ? $args['selected_node'] : null;
        $show_sidebar_panel = !empty($args['show_sidebar_panel']);
        $marker_tag_mode = isset($args['marker_tag_mode']) ? $args['marker_tag_mode'] : 'clickable';
        $notice_message = isset($args['notice_message']) ? $args['notice_message'] : '';
        $support_url = isset($args['support_url']) ? $args['support_url'] : '';
        ?>
        <div class="wrap ach-map-admin ach-config-page">
            <h1><?php esc_html_e('Australian Boundary Map – Config', 'boundary-map'); ?></h1>
            <p><?php esc_html_e('Choose the geography hierarchy for the active map boundary. The selected polygon is previewed below and used as the default shape for the plugin.', 'boundary-map'); ?></p>

            <?php if ($notice_message !== '') : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice_message); ?></p></div>
            <?php endif; ?>

            <form method="post" class="ach-form ach-config-form">
                <?php wp_nonce_field('boundary_map_save_config'); ?>

                <div class="ach-config-layout">
                    <div class="ach-config-main">
                        <section class="ach-entry-card">
                            <div class="ach-entry-card__header">
                                <h2><?php esc_html_e('Division', 'boundary-map'); ?></h2>
                                <p><?php esc_html_e('Set the geography hierarchy the map should follow, from Australia down to a specific region or division.', 'boundary-map'); ?></p>
                            </div>

                            <div class="ach-config-boundary-grid">
                                <div class="ach-config-boundary-field">
                                    <label for="ach-config-country"><?php esc_html_e('Country', 'boundary-map'); ?></label>
                                    <select id="ach-config-country" name="country">
                                        <option value="australia" selected><?php esc_html_e('Australia', 'boundary-map'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('The bundled geography data is currently configured for Australia.', 'boundary-map'); ?></p>
                                </div>

                                <div class="ach-config-boundary-field">
                                    <label for="ach-config-scope"><?php esc_html_e('Boundary Level', 'boundary-map'); ?></label>
                                    <select id="ach-config-scope" name="scope"></select>
                                    <p class="description"><?php esc_html_e('Choose which boundary level this map should follow.', 'boundary-map'); ?></p>
                                </div>

                                <div class="ach-config-boundary-field">
                                    <label for="ach-config-area" id="ach-config-area-label"><?php esc_html_e('State or Territory', 'boundary-map'); ?></label>
                                    <select id="ach-config-area" name="area"></select>
                                    <p class="description"><?php esc_html_e('Select the state or territory that contains the boundary you want to use.', 'boundary-map'); ?></p>
                                </div>

                                <div class="ach-config-boundary-field" id="ach-config-subdivision-row" hidden>
                                    <label for="ach-config-subdivision" id="ach-config-subdivision-label"><?php esc_html_e('Region / Division', 'boundary-map'); ?></label>
                                    <select id="ach-config-subdivision" name="subdivision"></select>
                                    <p class="description"><?php esc_html_e('Optionally narrow the selection to a specific region or division. Leave this blank to use the whole state or territory boundary.', 'boundary-map'); ?></p>
                                </div>
                            </div>

                            <p class="description">
                                <?php esc_html_e('The preview and generated shortcode update as you change the selection.', 'boundary-map'); ?>
                            </p>
                            <p class="ach-entry-boundary-summary" id="ach-config-current-boundary">
                                <?php
                                printf(
                                    esc_html__('Current boundary: %s', 'boundary-map'),
                                    esc_html(
                                        $selected_node && !empty($selected_node['displayName'])
                                            ? $selected_node['displayName']
                                            : __('Australia', 'boundary-map')
                                    )
                                );
                                ?>
                            </p>
                        </section>

                        <section class="ach-entry-card">
                            <div class="ach-entry-card__header">
                                <h2><?php esc_html_e('Settings', 'boundary-map'); ?></h2>
                                <p><?php esc_html_e('Choose how entry information should appear on the public map by default.', 'boundary-map'); ?></p>
                            </div>

                            <div class="ach-config-boundary-grid">
                                <div class="ach-config-boundary-field">
                                    <label for="ach-config-show-sidebar-panel"><?php esc_html_e('Entry panel', 'boundary-map'); ?></label>
                                    <select id="ach-config-show-sidebar-panel" name="show_sidebar_panel">
                                        <option value="1" <?php selected($show_sidebar_panel, true); ?>><?php esc_html_e('Show', 'boundary-map'); ?></option>
                                        <option value="0" <?php selected($show_sidebar_panel, false); ?>><?php esc_html_e('Hide', 'boundary-map'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Show or hide the floating entry panel on the public map. When hidden, visitors can still use the map, markers, search, and category filters.', 'boundary-map'); ?></p>
                                </div>

                                <div class="ach-config-boundary-field">
                                    <label for="ach-config-marker-tag-mode"><?php esc_html_e('Marker tag', 'boundary-map'); ?></label>
                                    <select id="ach-config-marker-tag-mode" name="marker_tag_mode">
                                        <option value="clickable" <?php selected($marker_tag_mode, 'clickable'); ?>><?php esc_html_e('Clickable', 'boundary-map'); ?></option>
                                        <option value="visible" <?php selected($marker_tag_mode, 'visible'); ?>><?php esc_html_e('Always visible', 'boundary-map'); ?></option>
                                    </select>
                                    <p class="description"><?php esc_html_e('Choose whether entry labels open when clicked, or stay visible above each marker.', 'boundary-map'); ?></p>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="ach-config-sidebar">
                        <div class="ach-config-preview">
                            <div class="ach-config-preview__header">
                                <h2><?php esc_html_e('Boundary Preview', 'boundary-map'); ?></h2>
                                <p id="ach-config-preview-title">
                                    <?php
                                    echo esc_html(
                                        $selected_node && !empty($selected_node['displayName'])
                                            ? $selected_node['displayName']
                                            : __('No boundary selected', 'boundary-map')
                                    );
                                    ?>
                                </p>
                            </div>
                            <div class="ach-config-map-wrap">
                                <div id="ach-config-map"></div>
                                <div id="ach-config-map-legend" class="ach-config-map-legend" hidden></div>
                            </div>
                        </div>

                        <div class="ach-config-shortcode">
                            <div class="ach-config-shortcode__header">
                                <h2><?php esc_html_e('Shortcode Generator', 'boundary-map'); ?></h2>
                                <p><?php esc_html_e('Create a shortcode locked to the current boundary selection.', 'boundary-map'); ?></p>
                            </div>

                            <div class="ach-config-shortcode-settings">
                                <div class="ach-config-shortcode-field">
                                    <label for="ach-config-shortcode-width-preset"><?php esc_html_e('Map width', 'boundary-map'); ?></label>
                                    <select id="ach-config-shortcode-width-preset">
                                        <option value="fit-container" selected><?php esc_html_e('Fit to container', 'boundary-map'); ?></option>
                                        <option value="960px"><?php esc_html_e('960px', 'boundary-map'); ?></option>
                                        <option value="1200px"><?php esc_html_e('1200px', 'boundary-map'); ?></option>
                                        <option value="custom"><?php esc_html_e('Custom', 'boundary-map'); ?></option>
                                    </select>
                                </div>

                                <div class="ach-config-shortcode-field" id="ach-config-shortcode-width-custom-wrap" hidden>
                                    <label for="ach-config-shortcode-width-custom"><?php esc_html_e('Custom width', 'boundary-map'); ?></label>
                                    <input id="ach-config-shortcode-width-custom" type="text" class="regular-text" value="100%" placeholder="100%, 960px, 80vw" />
                                </div>

                                <div class="ach-config-shortcode-field">
                                    <label for="ach-config-shortcode-height-preset"><?php esc_html_e('Map height', 'boundary-map'); ?></label>
                                    <select id="ach-config-shortcode-height-preset">
                                        <option value="600px" selected><?php esc_html_e('600px', 'boundary-map'); ?></option>
                                        <option value="70vh"><?php esc_html_e('70vh', 'boundary-map'); ?></option>
                                        <option value="800px"><?php esc_html_e('800px', 'boundary-map'); ?></option>
                                        <option value="custom"><?php esc_html_e('Custom', 'boundary-map'); ?></option>
                                    </select>
                                </div>

                                <div class="ach-config-shortcode-field" id="ach-config-shortcode-height-custom-wrap" hidden>
                                    <label for="ach-config-shortcode-height-custom"><?php esc_html_e('Custom height', 'boundary-map'); ?></label>
                                    <input id="ach-config-shortcode-height-custom" type="text" class="regular-text" value="600px" placeholder="600px, 70vh" />
                                </div>

                                <div class="ach-config-shortcode-field">
                                    <label for="ach-config-shortcode-zoommode"><?php esc_html_e('Zoom behaviour', 'boundary-map'); ?></label>
                                    <select id="ach-config-shortcode-zoommode">
                                        <option value="fit" selected><?php esc_html_e('Fit selected boundary', 'boundary-map'); ?></option>
                                        <option value="custom"><?php esc_html_e('Custom zoom level', 'boundary-map'); ?></option>
                                    </select>
                                </div>

                                <div class="ach-config-shortcode-field" id="ach-config-shortcode-zoom-wrap" hidden>
                                    <label for="ach-config-shortcode-zoom"><?php esc_html_e('Initial zoom', 'boundary-map'); ?></label>
                                    <input id="ach-config-shortcode-zoom" type="number" class="small-text" value="11.45" min="0" max="22" step="0.05" />
                                </div>

                                <div class="ach-config-shortcode-field">
                                    <label for="ach-config-shortcode-minzoom"><?php esc_html_e('Min zoom', 'boundary-map'); ?></label>
                                    <input id="ach-config-shortcode-minzoom" type="number" class="small-text" value="10" min="0" max="22" step="1" />
                                </div>

                                <div class="ach-config-shortcode-field">
                                    <label for="ach-config-shortcode-maxzoom"><?php esc_html_e('Max zoom', 'boundary-map'); ?></label>
                                    <input id="ach-config-shortcode-maxzoom" type="number" class="small-text" value="17" min="0" max="22" step="1" />
                                </div>

                                <div class="ach-config-shortcode-field">
                                    <label for="ach-config-shortcode-scrollwheel"><?php esc_html_e('Scroll wheel zoom', 'boundary-map'); ?></label>
                                    <select id="ach-config-shortcode-scrollwheel">
                                        <option value="0" selected><?php esc_html_e('Off', 'boundary-map'); ?></option>
                                        <option value="1"><?php esc_html_e('On', 'boundary-map'); ?></option>
                                    </select>
                                </div>

                                <div class="ach-config-shortcode-field">
                                    <label for="ach-config-shortcode-categorybox"><?php esc_html_e('Category box', 'boundary-map'); ?></label>
                                    <select id="ach-config-shortcode-categorybox">
                                        <option value="1" selected><?php esc_html_e('Show', 'boundary-map'); ?></option>
                                        <option value="0"><?php esc_html_e('Hide', 'boundary-map'); ?></option>
                                    </select>
                                </div>

                                <div class="ach-config-shortcode-field">
                                    <label for="ach-config-shortcode-sidebarpanel"><?php esc_html_e('Entry panel', 'boundary-map'); ?></label>
                                    <select id="ach-config-shortcode-sidebarpanel">
                                        <option value="1" <?php selected($show_sidebar_panel, true); ?>><?php esc_html_e('Show', 'boundary-map'); ?></option>
                                        <option value="0" <?php selected($show_sidebar_panel, false); ?>><?php esc_html_e('Hide', 'boundary-map'); ?></option>
                                    </select>
                                </div>

                                <div class="ach-config-shortcode-field">
                                    <label for="ach-config-shortcode-markertag"><?php esc_html_e('Marker tag', 'boundary-map'); ?></label>
                                    <select id="ach-config-shortcode-markertag">
                                        <option value="clickable" <?php selected($marker_tag_mode, 'clickable'); ?>><?php esc_html_e('Clickable', 'boundary-map'); ?></option>
                                        <option value="visible" <?php selected($marker_tag_mode, 'visible'); ?>><?php esc_html_e('Always visible', 'boundary-map'); ?></option>
                                    </select>
                                </div>
                            </div>

                            <div class="ach-config-shortcode__actions">
                                <button type="button" class="button button-secondary" id="ach-config-generate-shortcode">
                                    <?php esc_html_e('Generate Shortcode', 'boundary-map'); ?>
                                </button>
                                <button type="button" class="button" id="ach-config-copy-shortcode">
                                    <?php esc_html_e('Copy', 'boundary-map'); ?>
                                </button>
                            </div>

                            <label class="screen-reader-text" for="ach-config-shortcode-output"><?php esc_html_e('Generated shortcode', 'boundary-map'); ?></label>
                            <textarea id="ach-config-shortcode-output" class="large-text code" rows="3" readonly><?php echo esc_textarea('[boundary_map]'); ?></textarea>
                            <p class="description"><?php esc_html_e('Generate the shortcode after choosing Australia, a boundary level, a state or territory, or a specific region/division.', 'boundary-map'); ?></p>
                        </div>

                        <?php if ($support_url !== '') : ?>
                            <section class="ach-entry-card ach-support-card">
                                <div class="ach-entry-card__header">
                                    <span class="ach-support-badge"><?php esc_html_e('Support', 'boundary-map'); ?></span>
                                    <h2><?php esc_html_e('Enjoying the plugin?', 'boundary-map'); ?></h2>
                                    <p><?php esc_html_e('If Boundary Map is helping your site, you can support future updates and maintenance with a coffee.', 'boundary-map'); ?></p>
                                </div>
                                <p class="ach-support-actions">
                                    <a href="<?php echo esc_url($support_url); ?>" class="button button-primary" target="_blank" rel="noopener noreferrer">
                                        <?php esc_html_e('Support on Buy Me a Coffee', 'boundary-map'); ?>
                                    </a>
                                </p>
                            </section>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="ach-config-footer">
                    <?php submit_button(__('Save Configuration', 'boundary-map'), 'primary', 'boundary_map_config_submit'); ?>
                </div>
            </form>
        </div>
        <?php
    }
}
