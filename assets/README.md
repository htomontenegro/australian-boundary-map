# Australian Boundary Map

Australian Boundary Map is an interactive map interface for displaying admin-managed entries inside a selected boundary. It is designed to work inside WordPress, but the frontend assets are also structured clearly enough for custom integrations if needed.

The map is lightweight, responsive, and built so content editors can manage entries, categories, and geography settings without touching the frontend code.

## Features

- Interactive Leaflet map with zoom and pan
- Boundary overlays powered by GeoJSON
- Admin-managed entries and categories
- Colour-coded category markers
- Fuzzy search across title, description, location, and category
- Clickable entry cards and marker highlighting
- Shape-only map mode
- Responsive layout for desktop and mobile

## Folder Structure

```text
assets/
├── index.html             # Standalone frontend reference
├── script.js              # Map logic, filters, markers, boundaries
├── styles.css             # Frontend layout and map styling
├── admin.js               # Admin interactions and shortcode generator
├── admin.css              # Admin styling
├── categories.json        # Starter category data
├── geographies.json       # Geography hierarchy and boundary sources
└── E_NSW24_region1.json   # Bundled sample boundary polygon
```

## Data Structure

`categories.json`

```json
{
  "categories": [
    { "id": "All", "label": "All Entries" },
    { "id": "community", "label": "Community" },
    { "id": "services", "label": "Services" }
  ]
}
```

## Updating the Map

Add an entry:
Use the WordPress admin screen.

Add a category:
Edit `categories.json` or use the WordPress admin screen.

Update boundaries:
Replace the GeoJSON file or update the external boundary URL in `geographies.json`.

Update the geography selector:
Edit `geographies.json` to add or replace country, state, region, and subdivision options.

## Tech Stack

- Leaflet.js for map rendering
- Bootstrap 5 for layout components
- Fuse.js for fuzzy search
- OpenStreetMap for base tiles
- JSON and WordPress database storage for content

## Notes

- Keep JSON formatting valid if editing starter files directly.
- Category IDs must match between entries and categories.
- Add your own entries in WordPress before launch if you are not importing existing data.
- Test geography and marker behaviour after changing boundary sources.
