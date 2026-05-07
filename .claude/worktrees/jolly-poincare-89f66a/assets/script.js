// Sydney Boundary Map – pin markers, no clustering, with fuzzy search,
// active marker highlight, skeleton images, category badges, and
// DYNAMIC legend from categories.json.
// ---------------------------
// Map setup
// ---------------------------
const DEFAULT_CENTER = [-33.6, 151.1];

const DEFAULT_ZOOM =
  (window.ABM_CONFIG && typeof ABM_CONFIG.zoom !== "undefined")
    ? ABM_CONFIG.zoom
    : 11.45;

const MIN_ZOOM =
  (window.ABM_CONFIG && typeof ABM_CONFIG.minZoom !== "undefined")
    ? ABM_CONFIG.minZoom
    : 10;

const MAX_ZOOM =
  (window.ABM_CONFIG && typeof ABM_CONFIG.maxZoom !== "undefined")
    ? ABM_CONFIG.maxZoom
    : 17;

const SCROLL_WHEEL =
  (window.ABM_CONFIG && typeof ABM_CONFIG.scrollWheelZoom !== "undefined")
    ? !!ABM_CONFIG.scrollWheelZoom
    : false;


// ---------------------------
// Mode (full vs shape-only)
// ---------------------------
const ROOT_EL = document.querySelector('.boundary-map-wrapper[data-abm-mode]');
const ABM_MODE = ROOT_EL ? ROOT_EL.getAttribute('data-abm-mode') : 'full';
const IS_SHAPE_ONLY = ABM_MODE === 'shape-only';
const SHOW_MARKERS = !IS_SHAPE_ONLY;


const map = L.map("map", {
  scrollWheelZoom: SCROLL_WHEEL,
  minZoom: MIN_ZOOM,
  maxZoom: MAX_ZOOM
}).setView(DEFAULT_CENTER, DEFAULT_ZOOM);

L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
  maxZoom: 19,
}).addTo(map);

// Marker layer group (no clustering)
const entryMarkers = L.layerGroup();
if (SHOW_MARKERS) {
  entryMarkers.addTo(map);
}

// Suburb / boundary layers
const geoLayers = [];

// ---------------------------
// Data state
// ---------------------------
let entries = [];           // normalised entries
let categories = [];
let categoriesById = {};
let fuse = null;

const ALL_CATEGORY_ID = "All";

let activeCategoryId = ALL_CATEGORY_ID;
let searchQuery = "";

// store { marker, entry } by title
const markersByTitle = new Map();
let activeMarkerEntry = null;

// ---------------------------
// Utilities
// ---------------------------

// Simple suburb name resolver for your dataset
function resolveSuburbName(feature, fallback) {
  const props = feature && feature.properties ? feature.properties : {};
  return props.name || fallback;
}

// Normalise raw entry from JSON
function normaliseEntry(raw) {
  if (!raw) return null;

  const categoryId = raw.categoryId || raw.category || ALL_CATEGORY_ID;
  const catMeta = categoriesById[categoryId] || null;

  return {
    title: raw.title || "Untitled entry",
    categoryId,
    categoryLabel: (catMeta && catMeta.label) || raw.category || categoryId,
    categoryColor: catMeta && catMeta.color ? catMeta.color : null,
    categoryIcon: catMeta && catMeta.icon ? catMeta.icon : "",
    description: raw.description || "",
    location: raw.location || "",
    coords: Array.isArray(raw.coords) ? raw.coords : null,
    image: raw.image || null,
  };
}

// Category → safe suffix
function categoryClassSuffix(categoryId) {
  return (categoryId || "default").toLowerCase();
}

// ---------------------------
// Pin marker icons (colour-coded)
// ---------------------------
function createPinIcon(ev, isActive = false) {
  const hex =
    ev.categoryColor ||
    (categoriesById?.[ev.categoryId]?.color) ||
    (categoriesById?.[ev.categoryId]?.colour) ||
    "#666666";

  const iconSize = isActive ? [30, 49] : [25, 41];
  const iconAnchor = isActive ? [15, 49] : [12, 41];

  // Subtle border: slightly darkened version of fill
  const borderColor = "#00000022"; // ultra-light black
  const borderWidth = 0.75;

  const svg = `
  <svg xmlns="http://www.w3.org/2000/svg"
       width="25" height="41" viewBox="0 0 25 41">
    <path
      d="M12.5 0C5.6 0 0 5.6 0 12.5
         0 22.2 12.5 41 12.5 41
         12.5 41 25 22.2 25 12.5
         25 5.6 19.4 0 12.5 0Z"
      fill="${hex}"
      stroke="${borderColor}"
      stroke-width="${borderWidth}"
    />
    <circle cx="12.5" cy="12.5" r="5.5" fill="#ffffffee"/>
  </svg>`;

  const iconUrl =
    "data:image/svg+xml;charset=UTF-8," +
    encodeURIComponent(svg);

  return L.icon({
    iconUrl,
    iconSize,
    iconAnchor,
    popupAnchor: [1, -34],
    shadowUrl:
      "https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png",
    shadowSize: [41, 41],
    shadowAnchor: [12, 41],
  });
}

// ---------------------------
// Fuzzy search helpers
// ---------------------------
function buildFuseIndex() {
  if (!window.Fuse) {
    console.warn("Fuse.js not found – fuzzy search disabled.");
    return;
  }

  const options = {
    keys: ["title", "location", "description", "categoryLabel"],
    threshold: 0.35,
    ignoreLocation: true,
  };

  fuse = new Fuse(entries, options);
}

function applySearchAndCategory() {
  let baseEntries = entries;
  const q = searchQuery.trim();

  if (q && fuse) {
    const results = fuse.search(q);
    baseEntries = results.map((r) => r.item);
  } else if (q) {
    const lower = q.toLowerCase();
    baseEntries = entries.filter((ev) =>
      [ev.title, ev.location, ev.description, ev.categoryLabel].some(
        (field) => field && field.toLowerCase().includes(lower)
      )
    );
  }

  const filtered = baseEntries.filter((ev) =>
    activeCategoryId === ALL_CATEGORY_ID
      ? true
      : ev.categoryId === activeCategoryId
  );

  renderEntryList(filtered);
  drawEntryMarkers(filtered);
}

// ---------------------------
// GeoJSON boundaries
// ---------------------------
async function loadLocalGeoJSON(filePath, displayName, color) {
  try {
    const response = await fetch(filePath);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();

    const layer = L.geoJSON(data, {
      style: {
        color,
        weight: 2,
        fillColor: color,
        fillOpacity: 0.3,
      },
      onEachFeature: (feature, lyr) => {
        const suburbName = resolveSuburbName(feature, displayName);
        lyr.bindPopup(`<strong>${suburbName}</strong>`);
      },
    }).addTo(map);

    geoLayers.push({ name: displayName, layer });
    //console.log(`✅ Loaded suburb boundary: ${displayName}`);
  } catch (err) {
    //console.error(`❌ Failed to load ${displayName}:`, err);
  }
}


let initialBounds = null;  // put this near the top, next to geoLayers

async function initBoundaries() {
  const regionPath =
    (window.ABM_CONFIG && ABM_CONFIG.regionUrl) ||
    "E_NSW24_region1.json";

  await loadLocalGeoJSON(regionPath, "Berowra", "#4B7CD5");

  if (geoLayers.length > 0) {
    const group = L.featureGroup(geoLayers.map((g) => g.layer));
    initialBounds = group.getBounds();       // 🆕 store bounds once
    const center = initialBounds.getCenter();
    const FIXED_ZOOM = 11;
    map.setView(center, DEFAULT_ZOOM);
  }
}


// ---------------------------
// Active marker / card helpers
// ---------------------------
function clearActiveMarker() {
  if (activeMarkerEntry && activeMarkerEntry.marker) {
    const { marker, entry } = activeMarkerEntry;
    marker.setIcon(createPinIcon(entry, false));
  }
  activeMarkerEntry = null;
}

function highlightMarkerForEntry(ev) {
  clearActiveMarker();
  const entry = markersByTitle.get(ev.title);
  if (entry && entry.marker) {
    entry.marker.setIcon(createPinIcon(entry.entry, true));
    activeMarkerEntry = entry;
  }
}

function clearActiveEntryCard() {
  document
    .querySelectorAll(".entry.entry--active")
    .forEach((el) => el.classList.remove("entry--active"));
}

function highlightEntryCard(ev) {
  clearActiveEntryCard();
  const listEl = document.getElementById("entry-list");
  if (!listEl) return;
  const card = listEl.querySelector(`[data-title="${ev.title}"]`);
  if (card) {
    card.classList.add("entry--active");
    card.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }
}

// ---------------------------
// Render list & details
// ---------------------------
function renderEntryList(entryArray) {
  const listEl = document.getElementById("entry-list");
  const detailsEl = document.getElementById("entry-details");
  if (!listEl || !detailsEl) return;

  listEl.style.display = "";
  detailsEl.style.display = "none";
  detailsEl.innerHTML = "";
  listEl.innerHTML = "";

  if (!entryArray.length) {
    listEl.innerHTML =
      '<div class="text-muted" style="padding:8px 4px;">No entries match your filters.</div>';
    return;
  }

  entryArray.forEach((ev) => {
    const card = document.createElement("div");
    card.className = "entry";
    card.dataset.title = ev.title;

    const suffix = categoryClassSuffix(ev.categoryId);
    const badgeStyle = ev.categoryColor
      ? `style="background-color:${ev.categoryColor}"`
      : "";
    const iconHtml = ev.categoryIcon ? `${ev.categoryIcon} ` : "";

    const thumbHtml = ev.image
      ? `
        <div class="entry-thumb skeleton">
          <img
            src="${ev.image}"
            alt="${ev.title}"
            class="entry-thumb-img"
            onload="this.parentElement.classList.remove('skeleton')"
          />
          <span class="category-badge" ${badgeStyle}>${iconHtml}${ev.categoryLabel}</span>
        </div>
      `
      : "";

    card.innerHTML = `
      ${thumbHtml}
      <div class="entry-content">
        <p class="entry-title mb-0">${ev.title}</p>
        <p class=" mb-0">${ev.description}</p>
        <div class="entry-meta">
          <span class="entry-location">${ev.location}</span>
        </div>
      </div>
    `;

    card.addEventListener("click", () => {
      showEntryDetails(ev);
      highlightMarkerForEntry(ev);
      highlightEntryCard(ev);
    });

    listEl.appendChild(card);
  });
}

function showEntryDetails(ev) {
  const listEl = document.getElementById("entry-list");
  const detailsEl = document.getElementById("entry-details");
  if (!listEl || !detailsEl) return;

  const badgeStyle = ev.categoryColor
    ? `style="background-color:${ev.categoryColor}"`
    : "";
  const iconHtml = ev.categoryIcon ? `${ev.categoryIcon} ` : "";

  const thumbHtml = ev.image
    ? `
      <div class="entry-thumb skeleton" style="margin-bottom:10px;">
        <img
          src="${ev.image}"
          alt="${ev.title}"
          class="entry-thumb-img"
          onload="this.parentElement.classList.remove('skeleton')"
        />
        <span class="category-badge-details" ${badgeStyle}>${iconHtml}${ev.categoryLabel}</span>
      </div>
    `
    : "";

  const hasCoords = !!ev.coords;
  const directionsHref = hasCoords
    ? `https://www.google.com/maps/dir/?api=1&destination=${ev.coords[0]},${ev.coords[1]}`
    : null;

  detailsEl.innerHTML = `
  <span style="margin-bottom:15px;">
  <a href="#" id="back-to-list" >&larr; Back to results</a>
   </span>
    ${thumbHtml}
    <p class="entry-title">${ev.title}</p>
    <p class="mb-0">${ev.description}</p>
    <p><strong>Address:</strong> ${ev.location}</p>
  `;

  listEl.style.display = "none";
  detailsEl.style.display = "block";

  const backBtn = document.getElementById("back-to-list");
  if (backBtn) {
    backBtn.addEventListener("click", (e) => {
      e.preventDefault();
      applySearchAndCategory();   // re-render list & markers with filters

      // 🔁 reset map view
      if (initialBounds) {
        map.setView(initialBounds.getCenter(), DEFAULT_ZOOM);
      } else {
        map.setView(DEFAULT_CENTER, DEFAULT_ZOOM);
      }

      clearActiveMarker();                    // optional: remove highlight
      clearActiveEntryCard();           // optional: clear list highlight
    });
  }

  if (ev.coords) {
    map.setView(ev.coords, 16);

    // MOBILE ONLY: push the view down so the marker isn't covered by the bottom drawer
    if (window.matchMedia("(max-width: 768px)").matches) {
      setTimeout(() => {
        map.panBy([0, -100], { animate: true });
      }, 50);
    }
  }
}

// ---------------------------
// Draw markers (pins)
// ---------------------------
function drawEntryMarkers(entryArray) {
  if (!SHOW_MARKERS) return;
  entryMarkers.clearLayers();
  markersByTitle.clear();
  clearActiveMarker();

  entryArray.forEach((ev) => {
    if (!ev.coords) return;

    const marker = L.marker(ev.coords, {
      title: ev.title,
      icon: createPinIcon(ev, false),
    });

    const popupImgHtml = ev.image
      ? `<div style="margin-bottom:6px;">
           <img src="${ev.image}" alt="${ev.title}"
                style="width:100%;max-height:150px;object-fit:cover;border-radius:6px;">
         </div>`
      : "";

    marker.bindPopup(`
      <div style="max-width:220px;">
        ${popupImgHtml}
        <strong>${ev.title}</strong><br/>
        <small>${ev.location}</small>
      </div>
    `);

    marker.on("click", () => {
      showEntryDetails(ev);
      highlightMarkerForEntry(ev);
      highlightEntryCard(ev);
    });

    markersByTitle.set(ev.title, { marker, entry: ev });
    entryMarkers.addLayer(marker);
  });
}

// ---------------------------
// Categories & legend & search
// ---------------------------
function initCategoryTabs() {
  const ul = document.getElementById("category-filter");
  if (!ul) return;

  ul.innerHTML = "";

  const usedCategoryIds = getUsedCategoryIds(entries);

  // If no used categories, show only All (don’t show nothing)
  const visibleCategories =
    usedCategoryIds.size
      ? categories.filter(cat => cat.id === ALL_CATEGORY_ID || usedCategoryIds.has(cat.id))
      : categories.filter(cat => cat.id === ALL_CATEGORY_ID);

  visibleCategories.forEach((cat, idx) => {
    const li = document.createElement("li");
    li.className = "nav-item";

    const a = document.createElement("a");
    a.href = "#";
    a.className = "nav-link mx-2 px-3 py-2 border rounded " + (idx === 0 ? " active" : "");
    a.dataset.category = cat.id;
    a.textContent = cat.label;

    li.appendChild(a);
    ul.appendChild(li);
  });

  // Make sure activeCategoryId is valid
  if (!visibleCategories.some(c => c.id === activeCategoryId)) {
    activeCategoryId = ALL_CATEGORY_ID;
  }

  ul.addEventListener("click", (e) => {
    const link = e.target.closest(".nav-link");
    if (!link) return;
    e.preventDefault();

    ul.querySelectorAll(".nav-link").forEach(a => a.classList.remove("active"));
    link.classList.add("active");

    activeCategoryId = link.dataset.category || ALL_CATEGORY_ID;
    applySearchAndCategory();
  });
}

// 🎨 Dynamic legend built from categories.json
function initLegend() {
  const legend = document.getElementById("map-legend");
  if (!legend) return;

  legend.innerHTML = `<h6 class="legend-title mb-1">Categories</h6>`;

  categories
    .filter((cat) => cat.id !== ALL_CATEGORY_ID) // skip "All"
    .forEach((cat) => {
      const color = cat.color || null;
      const icon = cat.icon || "";
      const item = document.createElement("div");
      item.className = "legend-item";
      item.innerHTML = `
        <span class="legend-swatch" style="${color ? `background:${color};` : ""}"></span>
        ${icon ? icon + " " : ""}${cat.label}
      `;
      legend.appendChild(item);
    });
}

function initSearch() {
  const form = document.getElementById("entry-search-form");
  const input = document.getElementById("entry-search-input");

  if (!input) return;

  function updateSearch() {
    searchQuery = input.value || "";
    applySearchAndCategory();
  }

  input.addEventListener("input", updateSearch);

  if (form) {
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      updateSearch();
    });
  }
}

// ---------------------------
// Data loading
// ---------------------------
async function loadCategories() {
  try {
    const apiUrl =
      (window.ABM_CONFIG && ABM_CONFIG.categoriesApiUrl) ||
      (window.ABM_CONFIG && ABM_CONFIG.categoriesUrl) ||
      "categories.json";

    const res = await fetch(apiUrl, { cache: "no-cache" });

    if (!res.ok) throw new Error(`categories HTTP ${res.status}`);

    const data = await res.json();

    // If REST returns the plain array (from load_categories()), you may need:
    // categories = data.categories || data;
    categories = Array.isArray(data.categories) ? data.categories : data;

    categoriesById = {};
    categories.forEach((cat) => {
      if (cat.id) categoriesById[cat.id] = cat;
    });
    // Ensure "All" exists as the first option
    if (!categories.some(c => c.id === ALL_CATEGORY_ID)) {
      categories.unshift({ id: ALL_CATEGORY_ID, label: "All" });
    }
    categoriesById[ALL_CATEGORY_ID] = categoriesById[ALL_CATEGORY_ID] || { id: ALL_CATEGORY_ID, label: "All" };

  } catch (err) {
    console.error("Failed to load categories:", err);
  }
}

function getUsedCategoryIds(entries) {
  const used = new Set();

  entries.forEach(ev => {
    const id = ev?.categoryId;
    if (!id || id === ALL_CATEGORY_ID) return;

    // Only count if category exists in categories.json
    if (categoriesById[id]) used.add(id);
  });

  return used;
}
async function loadEntries() {
  try {
    // Use REST URL if provided, fallback to old file path
    const apiUrl =
      (window.ABM_CONFIG && ABM_CONFIG.entriesApiUrl) ||
      (window.ABM_CONFIG && ABM_CONFIG.entriesUrl) ||
      "entries.json";

    const res = await fetch(apiUrl, { cache: "no-cache" });
    if (!res.ok) throw new Error(`entries HTTP ${res.status}`);

    const rawEntries = await res.json();
    // If REST returns wrapped object, adjust here; assuming array:
    entries = rawEntries.map(normaliseEntry).filter(Boolean);
  } catch (err) {
    console.error("Failed to load entries:", err);
    entries = [];
  }
}

// ---------------------------
// Init
// ---------------------------
async function init() {
  // Always load the boundary/shape
  await initBoundaries();

  // Shape-only mode: no markers, no sidebar/nav, no data fetch
  if (IS_SHAPE_ONLY) {
    return;
  }

  await loadCategories();
  await loadEntries();

  buildFuseIndex();
  initCategoryTabs();
  initSearch();
  initLegend(); // legend from categories.json

  activeCategoryId = categories[0]?.id || ALL_CATEGORY_ID;

  const ul = document.getElementById("category-filter");
  if (ul) {
    ul.querySelectorAll(".nav-link").forEach((a) => {
      a.classList.toggle("active", a.dataset.category === activeCategoryId);
    });
  }

  applySearchAndCategory();
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", init);
} else {
  init();
}if (!IS_SHAPE_ONLY) {
  document.addEventListener("click", function (e) {
    if (e.target.matches("#category-filter .nav-link")) {
      e.preventDefault();

      // Remove active from all
      document
        .querySelectorAll("#category-filter .nav-link")
        .forEach(btn => btn.classList.remove("active"));

      // Add active to clicked
      e.target.classList.add("active");

      // (Optional) Call your filter function
      // filterByCategory(e.target.dataset.category);
    }
  })
}
;
