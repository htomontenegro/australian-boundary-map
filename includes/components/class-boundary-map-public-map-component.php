<?php

if (!defined('ABSPATH')) {
    exit;
}

class Boundary_Map_Public_Map_Component
{
    public static function render_full($width_mode, $wrapper_style, $show_sidebar_panel = true)
    {
        ob_start();
        ?>
        <div class="boundary-map-wrapper" data-boundary-map-mode="full" data-boundary-map-width="<?php echo esc_attr($width_mode); ?>" style="<?php echo esc_attr($wrapper_style); ?>">
            <?php echo self::render_body($show_sidebar_panel); ?>
        </div>
        <?php

        return ob_get_clean();
    }

    public static function render_shape_only($width_mode, $wrapper_style)
    {
        ob_start();
        ?>
        <div class="boundary-map-wrapper boundary-map-wrapper--shape-only" data-boundary-map-mode="shape-only" data-boundary-map-width="<?php echo esc_attr($width_mode); ?>" style="<?php echo esc_attr($wrapper_style); ?>">
            <div id="map-container" class="boundary-map-plugin">
                <div id="map"></div>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    public static function render_body($show_sidebar_panel = true)
    {
        ob_start();
        ?>
        <div class="boundary-map-plugin">
            <nav class="navbar navbar-light">
                <div class="container-fluid p-0" id="navbarNav">
                    <ul class="nav d-flex flex-wrap justify-content-center align-items-center gap-2 w-100" id="category-filter">
                    </ul>
                </div>
            </nav>
            <div id="map-container">
                <div id="map"></div>
                <form class="map-search-overlay" role="search" id="entry-search-form">
                    <div class="input-group input-group-sm search-container">
                        <span class="input-group-text search-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="#6c757d" viewBox="0 0 16 16">
                                <path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85
                                3.85a1 1 0 0 0 1.415-1.414l-3.85-3.85zm-5.242
                                1.31a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/>
                            </svg>
                        </span>
                        <input class="form-control p-2" id="entry-search-input" type="search" placeholder="Search entries..." aria-label="Search entries" />
                    </div>
                </form>
                <!--<div id="map-legend" class="map-legend"></div> -->
            </div>
        </div>
        <?php

        return ob_get_clean();
    }
}
