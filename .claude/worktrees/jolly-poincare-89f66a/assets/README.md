
# Julian Leeser – Interactive Map

This project is an interactive map for displaying electorate events, mobile offices, community engagements, school visits, local business visits, and other activities across the Berowra region.

The map is fully client-side, lightweight, responsive, and designed so that staff can update events and categories through simple JSON files without modifying any code. It can be embedded into any website (Squarespace, WordPress, HTML, iframe).



## Features
- Interactive Leaflet map with zoom + pan
- Electorate boundary overlay (GeoJSON)
- Live events loaded from JSON
- Live categories loaded dynamically
- Colour-coded category markers
- Fuzzy search across title / description / location
- Clickable event cards in sidebar
- Marker highlight animation
- Skeleton loading for event images
- Clean Bootstrap UI
- Fully responsive layout


## Folder Structure
```
interactive-map/
│
├── index.html              # Main interface, navbar, containers
├── script.js               # Map logic, markers, filters, boundaries
├── styles.css              # Layout styles, event cards, search bar
│
├── events.json             # LIVE electorate events (update regularly)
├── categories.json         # LIVE event categories
├── E_NSW24_region1.json    # Electorate boundary polygon
│
└── assets/                 # Optional icons, images, branding

```

# Data Structure

**categories.json**

Simple list of event types:

```
{
  "categories": [
    { "id": "All", "label": "All Events" },
    { "id": "Music", "label": "Music" },
    { "id": "Food", "label": "Food" },
    { "id": "Community", "label": "Community" },
    { "id": "Art", "label": "Art" }
  ]
}

```

**events.json**

Each event uses latitude/longitude + a category:

```
  {
    "title": "Berowra Twilight Jazz",
    "categoryId": "Music",
    "category": "Music",
    "description": "Smooth live jazz as the sun sets over the bushland.",
    "location": "Berowra Village Green, Berowra",
    "coords": [-33.6225, 151.1440],
    "image": "https://picsum.photos/seed/berowra-twilight-jazz/800/450"
  },

```

**Updating the Map**

Add an event:
Edit **events.json** → add a new entry.

Add a category:
Edit **categories.json** → add ```{ id, label } ```.

Update boundaries:
Replace the GeoJSON file.

No changes to HTML, CSS, or JavaScript are required.
## Tech Stack
Leaflet.js — mapping engine

Bootstrap 5 — layout + components

Fuse.js — fuzzy search

OpenStreetMap — basemap tiles

JSON — fully editable data layer
## Notes for Staff

Keep JSON formatting valid

Images, categories and event details should all be changed to what is given by client

Category IDs must match between event + category files

Test updates locally before deployment