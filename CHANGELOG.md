# Changelog

All notable changes to Australian Boundary Map are documented here.

## [1.3.0] – 2026-05-06

### Security
- Fixed XSS: geography option IDs and labels are now HTML-escaped before insertion into select elements via `innerHTML`
- Added `User-Agent` header to Nominatim geocoding requests per usage policy

### Added
- REST API responses for entries and categories are now cached as WordPress transients (1 hour TTL) to reduce database load on every page view
- Cache is automatically invalidated on any entry or category write (add, edit, trash, restore, bulk actions, import)
- `uninstall.php`: drops `wp_ach_entries` and `wp_ach_categories` tables and deletes all plugin options when the plugin is removed
- Deactivation hook flushes transient cache on plugin deactivation
- Public map now shows a visible error notice when the REST API fails to load entries or categories
- `readme.txt` for WordPress plugin directory submission

### Changed
- Plugin header updated with author URI, plugin URI, `Requires at least: 6.0`, and `Requires PHP: 7.4`
- Nominatim geocoding in the admin entry form now enforces a 1-second minimum interval between requests to comply with usage policy

## [1.1.1] – 2025-xx-xx

### Changed
- Added map legend container in `boundary-map.php`
- Removed unused marker icon code from `script.js`
- Version bumped to 1.1.1

## [1.1.0]

### Added
- Multi-scope geography selector (federal, state/territory)
- Boundary preview map in Config screen
- Shortcode generator with live boundary preview

## [1.0.0]

### Added
- Initial release
- Interactive Leaflet map with GeoJSON boundary overlays
- Admin-managed entries and categories
- Fuzzy search, category filter tabs, and map legend
- CSV import/export for entries and categories
- REST API endpoints for public data
