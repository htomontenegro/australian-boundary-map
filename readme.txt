=== Boundary Map ===
Contributors: htomontenegro
Tags: map, leaflet, boundary, australia, geojson, interactive map, location
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Interactive map for Australian boundaries with admin-managed entries, categories, fuzzy search, and boundary overlays.

== Description ==

Australian Boundary Map lets you display an interactive, filterable map on any WordPress page using a shortcode. Entries are managed from the WordPress admin, and the map boundary is driven by GeoJSON — supporting federal electorates, state electorates, or any custom polygon.

**Features:**

* Interactive Leaflet map with zoom, pan, and responsive layout
* GeoJSON boundary overlays for federal divisions, state electorates, or custom regions
* Admin-managed entries with title, description, location, coordinates, image, and category
* Colour-coded category markers with optional legend
* Fuzzy search across title, description, location, and category
* Category filter tabs
* Clickable entry cards with map marker highlighting
* Shape-only map mode (boundary only, no entries or sidebar)
* Shortcode generator with live preview in the Config screen
* CSV import and export for entries and categories
* REST API endpoints for entries and categories

**Shortcodes:**

* `[boundary_map]` — Full map with entries, search, categories, legend, and boundary overlay
* `[boundary_map_shape]` — Boundary-only map without entries or sidebar UI

Both shortcodes accept optional parameters to lock the map to a specific geography, set dimensions, and control display options. See the Information screen in wp-admin for the full parameter reference.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via **Plugins → Add New**.
2. Activate the plugin.
3. Go to **Boundary Map → Config** to select a boundary and configure defaults.
4. Go to **Boundary Map → All Entries** to add your map entries.
5. Add the `[boundary_map]` shortcode to any page or post.

== Frequently Asked Questions ==

= Can I use this outside of Australia? =

The bundled geography data covers Australian federal and state boundaries. You can supply your own GeoJSON files for any region by editing the geography configuration.

= How do I set a custom boundary? =

Go to **Boundary Map → Config**, select your scope, state/territory, and optionally a subdivision. The live preview updates as you choose. Save the configuration to apply it as the default.

= Can I embed the map in a page builder? =

Yes. Use the shortcode `[boundary_map]` in any shortcode-compatible block, widget, or page builder element. The map adapts to its container width.

= What happens to my data if I uninstall the plugin? =

Uninstalling removes the plugin's database tables and all stored options. Export your entries and categories via **Boundary Map → Tools** before uninstalling if you need a backup.

== Screenshots ==

1. Public-facing map with entries, category filters, and boundary overlay
2. Entry list in WordPress admin
3. Config screen with boundary preview and shortcode generator
4. Add/Edit entry form with geocoding lookup

== Changelog ==

= 1.3.0 =
* Added REST response caching via WordPress transients
* Added deactivation hook to flush transient cache
* Added error notice on the public map when data fails to load
* Fixed XSS: escaped geography option values in select elements
* Added Nominatim User-Agent header and 1-second rate limiting on geocoding
* Added uninstall.php to clean up database tables and options on removal
* Updated plugin header with author URI, plugin URI, Requires at least, and Requires PHP

= 1.1.1 =
* Added map legend container
* Cleaned up unused marker icon code in script.js

= 1.1.0 =
* Added multi-scope geography selector (federal, state)
* Added boundary preview map in Config screen
* Added shortcode generator

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.3.0 =
Adds REST caching, uninstall cleanup, and security fixes. Recommended for all users.
