const DEFAULT_CENTER = [-33.6, 151.1];

const DEFAULT_ZOOM =
  window.BOUNDARY_MAP_CONFIG && typeof BOUNDARY_MAP_CONFIG.zoom !== "undefined"
    ? BOUNDARY_MAP_CONFIG.zoom
    : 11.45;

const MIN_ZOOM =
  window.BOUNDARY_MAP_CONFIG && typeof BOUNDARY_MAP_CONFIG.minZoom !== "undefined"
    ? BOUNDARY_MAP_CONFIG.minZoom
    : 10;

const MAX_ZOOM =
  window.BOUNDARY_MAP_CONFIG && typeof BOUNDARY_MAP_CONFIG.maxZoom !== "undefined"
    ? BOUNDARY_MAP_CONFIG.maxZoom
    : 17;

const SCROLL_WHEEL =
  window.BOUNDARY_MAP_CONFIG && typeof BOUNDARY_MAP_CONFIG.scrollWheelZoom !== "undefined"
    ? !!BOUNDARY_MAP_CONFIG.scrollWheelZoom
    : false;

const ZOOM_MODE =
  window.BOUNDARY_MAP_CONFIG && typeof BOUNDARY_MAP_CONFIG.zoomMode === "string"
    ? BOUNDARY_MAP_CONFIG.zoomMode
    : "fit";

const SHOW_CATEGORY_BOX =
  window.BOUNDARY_MAP_CONFIG && typeof BOUNDARY_MAP_CONFIG.showCategoryBox !== "undefined"
    ? !!BOUNDARY_MAP_CONFIG.showCategoryBox
    : true;

const MARKER_TAG_MODE =
  window.BOUNDARY_MAP_CONFIG && typeof BOUNDARY_MAP_CONFIG.markerTagMode === "string"
    ? BOUNDARY_MAP_CONFIG.markerTagMode
    : "clickable";

const ROOT_EL = document.querySelector(".boundary-map-wrapper[data-boundary-map-mode]");
const BOUNDARY_MAP_MODE = ROOT_EL ? ROOT_EL.getAttribute("data-boundary-map-mode") : "full";
const IS_SHAPE_ONLY = BOUNDARY_MAP_MODE === "shape-only";
const SHOW_MARKERS = !IS_SHAPE_ONLY;

const map = L.map("map", {
  scrollWheelZoom: SCROLL_WHEEL,
  minZoom: MIN_ZOOM,
  maxZoom: MAX_ZOOM,
  zoomSnap: 0,
  zoomDelta: 0.05,
}).setView(DEFAULT_CENTER, DEFAULT_ZOOM);
const mapContainer = document.getElementById("map-container");

function syncMapSize() {
  if (!mapContainer) return;

  requestAnimationFrame(() => {
    map.invalidateSize(false);
  });
}

window.addEventListener("load", syncMapSize);
window.addEventListener("resize", syncMapSize);

if (typeof ResizeObserver !== "undefined" && mapContainer) {
  const mapResizeObserver = new ResizeObserver(() => {
    syncMapSize();
  });

  mapResizeObserver.observe(mapContainer);
}

L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
  maxZoom: 19,
}).addTo(map);

const entryMarkers = L.layerGroup();
if (SHOW_MARKERS) {
  entryMarkers.addTo(map);
}

const ALL_CATEGORY_ID = "All";
const DEFAULT_BOUNDARY_COLOR = "#4B7CD5";

let entriesData = [];
let categories = [];
let categoriesById = {};
let fuse = null;

let activeCategoryId = ALL_CATEGORY_ID;
let searchQuery = "";

const markersByTitle = new Map();
let activeMarkerEntry = null;

let boundaryLayer = null;
let activeBoundaryGeoJSON = null;
let initialBounds = null;
let initialBoundaryCenter = null;

const geographyConfig = normaliseGeographyConfig(
  window.BOUNDARY_MAP_CONFIG && BOUNDARY_MAP_CONFIG.geographyConfig
    ? BOUNDARY_MAP_CONFIG.geographyConfig
    : null
);

const initialGeographySelection = normaliseInitialGeographySelection(
  window.BOUNDARY_MAP_CONFIG && BOUNDARY_MAP_CONFIG.geographySelection
    ? BOUNDARY_MAP_CONFIG.geographySelection
    : null
);

let selectedScopeId = initialGeographySelection.scope;
let selectedAreaId = initialGeographySelection.area;
let selectedSubdivisionId = initialGeographySelection.subdivision;

function normaliseGeographyConfig(raw) {
  const fallbackGeojson = getFallbackGeojsonFile();

  const fallback = {
    defaultScopeId: "federal",
    country: {
      id: "australia",
      label: "Australia",
      displayName: "Australia",
      geojsonUrl:
        "https://geo.abs.gov.au/arcgis/rest/services/ASGS2021/AUS/MapServer/0/query?where=1%3D1&outFields=AUS_NAME_2021&returnGeometry=true&outSR=4326&f=geojson",
      color: DEFAULT_BOUNDARY_COLOR,
    },
    scopes: [
      {
        id: "federal",
        label: "Federal Parliament",
        groupLabel: "State or Territory",
        itemLabel: "Federal Division",
        children: [
          {
            id: "nsw",
            label: "New South Wales",
            displayName: "New South Wales",
            color: DEFAULT_BOUNDARY_COLOR,
            children: [
              {
                id: "berowra",
                label: "Berowra",
                displayName: "Berowra",
                geojson: fallbackGeojson,
                color: DEFAULT_BOUNDARY_COLOR,
                children: [],
              },
            ],
          },
        ],
      },
    ],
  };

  if (!raw || !Array.isArray(raw.scopes) || !raw.scopes.length) {
    return fallback;
  }

  const scopes = raw.scopes
    .map((scope, index) => normaliseScope(scope, index))
    .filter(Boolean);

  if (!scopes.length) {
    return fallback;
  }

  return {
    defaultScopeId:
      typeof raw.defaultScopeId === "string" && raw.defaultScopeId
        ? raw.defaultScopeId
        : scopes[0].id,
    country: normaliseBoundaryNode(raw.country || fallback.country, 0) || fallback.country,
    scopes,
  };
}

function normaliseScope(scope, index) {
  if (!scope || typeof scope !== "object") {
    return null;
  }

  const children = Array.isArray(scope.children)
    ? scope.children
        .map((child, childIndex) => normaliseBoundaryNode(child, childIndex))
        .filter(Boolean)
    : [];

  return {
    id: safeNodeId(scope.id, `scope-${index + 1}`),
    label: scope.label || `Scope ${index + 1}`,
    groupLabel: scope.groupLabel || "Area",
    itemLabel: scope.itemLabel || "Subdivision",
    children,
  };
}

function normaliseBoundaryNode(node, index) {
  if (!node || typeof node !== "object") {
    return null;
  }

  const children = Array.isArray(node.children)
    ? node.children
        .map((child, childIndex) => normaliseBoundaryNode(child, childIndex))
        .filter(Boolean)
    : [];

  return {
    id: safeNodeId(node.id, `node-${index + 1}`),
    label: node.label || `Area ${index + 1}`,
    displayName: node.displayName || node.label || `Area ${index + 1}`,
    geojson: typeof node.geojson === "string" ? node.geojson : "",
    geojsonUrl: typeof node.geojsonUrl === "string" ? node.geojsonUrl : "",
    color: typeof node.color === "string" && node.color ? node.color : DEFAULT_BOUNDARY_COLOR,
    children,
  };
}

function safeNodeId(value, fallback) {
  return typeof value === "string" && value ? value : fallback;
}

function clampZoomValue(value, minZoom, maxZoom) {
  let nextValue = value;

  if (Number.isFinite(minZoom)) {
    nextValue = Math.max(nextValue, minZoom);
  }

  if (Number.isFinite(maxZoom)) {
    nextValue = Math.min(nextValue, maxZoom);
  }

  return nextValue;
}

function getFallbackGeojsonFile() {
  const regionUrl =
    window.BOUNDARY_MAP_CONFIG && typeof BOUNDARY_MAP_CONFIG.regionUrl === "string"
      ? BOUNDARY_MAP_CONFIG.regionUrl
      : "E_NSW24_region1.json";

  try {
    const url = new URL(regionUrl, window.location.href);
    const parts = url.pathname.split("/");
    return parts[parts.length - 1] || "E_NSW24_region1.json";
  } catch (err) {
    return regionUrl;
  }
}

function normaliseInitialGeographySelection(rawSelection) {
  const fallbackScope = geographyConfig.defaultScopeId || geographyConfig.scopes[0]?.id || "";
  const selection = {
    scope: typeof rawSelection?.scope === "string" ? rawSelection.scope : fallbackScope,
    area: typeof rawSelection?.area === "string" ? rawSelection.area : "",
    subdivision: typeof rawSelection?.subdivision === "string" ? rawSelection.subdivision : "",
  };

  return selection;
}

function resolveSuburbName(feature, fallback) {
  const props = feature && feature.properties ? feature.properties : {};
  return props.name || props.Elect_div || props.Sortname || fallback;
}

function sanitizeText(value, fallback = "") {
  return typeof value === "string" && value !== "" ? value : fallback;
}

function sanitizeImageUrl(url) {
  if (typeof url !== "string" || !url.trim()) {
    return null;
  }

  try {
    const parsed = new URL(url, window.location.href);
    if (parsed.protocol === "http:" || parsed.protocol === "https:") {
      return parsed.toString();
    }
  } catch (err) {
    return null;
  }

  return null;
}

function getEntryKey(raw, fallbackIndex = 0) {
  if (raw && typeof raw.id !== "undefined" && raw.id !== null && raw.id !== "") {
    return `entry-${String(raw.id)}`;
  }

  return `entry-fallback-${fallbackIndex}`;
}

function normaliseEntry(raw, fallbackIndex = 0) {
  if (!raw) return null;

  const categoryId = raw.categoryId || raw.category || ALL_CATEGORY_ID;
  const catMeta = categoriesById[categoryId] || null;
  const coords =
    Array.isArray(raw.coords) &&
    raw.coords.length >= 2 &&
    Number.isFinite(Number(raw.coords[0])) &&
    Number.isFinite(Number(raw.coords[1]))
      ? [Number(raw.coords[0]), Number(raw.coords[1])]
      : null;

  return {
    id: typeof raw.id !== "undefined" && raw.id !== null ? raw.id : null,
    entryKey: getEntryKey(raw, fallbackIndex),
    title: sanitizeText(raw.title, "Untitled entry"),
    categoryId,
    categoryLabel: sanitizeText((catMeta && catMeta.label) || raw.category || categoryId, "Uncategorised"),
    categoryColor: catMeta && catMeta.color ? catMeta.color : null,
    categoryIcon: sanitizeText(catMeta && catMeta.icon ? catMeta.icon : "", ""),
    description: sanitizeText(raw.description, ""),
    location: sanitizeText(raw.location, ""),
    coords,
    image: sanitizeImageUrl(raw.image),
  };
}

function setSkeletonImageHandlers(img, wrapper) {
  if (!img || !wrapper) return;

  img.addEventListener("load", () => {
    wrapper.classList.remove("skeleton");
  });

  img.addEventListener("error", () => {
    wrapper.remove();
  });
}

function createBadgeElement(ev, className) {
  const badge = document.createElement("span");
  badge.className = className;
  if (ev.categoryColor) {
    badge.style.backgroundColor = ev.categoryColor;
  }
  badge.textContent = ev.categoryIcon ? `${ev.categoryIcon} ${ev.categoryLabel}` : ev.categoryLabel;
  return badge;
}

function createEntryImageBlock(ev, badgeClass, extraStyles = {}) {
  if (!ev.image) return null;

  const wrapper = document.createElement("div");
  wrapper.className = "entry-thumb skeleton";
  Object.assign(wrapper.style, extraStyles);

  const img = document.createElement("img");
  img.src = ev.image;
  img.alt = ev.title;
  img.className = "entry-thumb-img";
  setSkeletonImageHandlers(img, wrapper);

  wrapper.appendChild(img);
  wrapper.appendChild(createBadgeElement(ev, badgeClass));

  return wrapper;
}

function createMarkerPopupContent(ev) {
  const popup = document.createElement("div");
  popup.style.maxWidth = "220px";

  if (ev.image) {
    const imageWrap = document.createElement("div");
    imageWrap.style.marginBottom = "6px";

    const img = document.createElement("img");
    img.src = ev.image;
    img.alt = ev.title;
    img.style.width = "100%";
    img.style.maxHeight = "150px";
    img.style.objectFit = "cover";
    img.style.borderRadius = "6px";
    img.addEventListener("error", () => {
      imageWrap.remove();
    });

    imageWrap.appendChild(img);
    popup.appendChild(imageWrap);
  }

  const title = document.createElement("strong");
  title.textContent = ev.title;
  popup.appendChild(title);
  popup.appendChild(document.createElement("br"));

  const location = document.createElement("small");
  location.textContent = ev.location;
  popup.appendChild(location);

  return popup;
}

function createMarkerTooltipContent(ev) {
  const tooltip = document.createElement("div");
  tooltip.className = "entry-marker-tag";

  const title = document.createElement("strong");
  title.textContent = ev.title;
  tooltip.appendChild(title);

  const location = document.createElement("small");
  location.textContent = ev.location;
  tooltip.appendChild(location);

  return tooltip;
}

function categoryClassSuffix(categoryId) {
  return (categoryId || "default").toLowerCase();
}

function createPinIcon(ev, isActive = false) {
  const hex =
    ev.categoryColor ||
    (categoriesById?.[ev.categoryId]?.color) ||
    (categoriesById?.[ev.categoryId]?.colour) ||
    "#666666";

  const iconSize = isActive ? [30, 49] : [25, 41];
  const iconAnchor = isActive ? [15, 49] : [12, 41];

  const borderColor = "#00000022";
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

  const iconUrl = "data:image/svg+xml;charset=UTF-8," + encodeURIComponent(svg);

  return L.icon({
    iconUrl,
    iconSize,
    iconAnchor,
    popupAnchor: [1, -34],
    shadowUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png",
    shadowSize: [41, 41],
    shadowAnchor: [12, 41],
  });
}

function buildFuseIndex() {
  if (!window.Fuse) {
    console.warn("Fuse.js not found - fuzzy search disabled.");
    return;
  }

  fuse = new Fuse(entriesData, {
    keys: ["title", "location", "description", "categoryLabel"],
    threshold: 0.35,
    ignoreLocation: true,
  });
}

function getScopeById(scopeId) {
  return geographyConfig.scopes.find((scope) => scope.id === scopeId) || null;
}

function getSelectedScope() {
  return getScopeById(selectedScopeId);
}

function getSelectedArea() {
  const scope = getSelectedScope();
  if (!scope) return null;
  return scope.children.find((child) => child.id === selectedAreaId) || null;
}

function getSelectedSubdivision() {
  const area = getSelectedArea();
  if (!area || !area.children.length) return null;
  return area.children.find((child) => child.id === selectedSubdivisionId) || null;
}

function getCountryNode() {
  return geographyConfig.country || null;
}

function getActiveBoundaryNode() {
  return getSelectedSubdivision() || getSelectedArea();
}

function ensureSelectedGeography() {
  const scopes = geographyConfig.scopes;
  if (!selectedScopeId) {
    selectedAreaId = "";
    selectedSubdivisionId = "";
    return;
  }

  if (!scopes.length) {
    selectedScopeId = "";
    selectedAreaId = "";
    selectedSubdivisionId = "";
    return;
  }

  let scope = getSelectedScope();
  if (!scope) {
    scope = scopes[0];
    selectedScopeId = scope.id;
  }

  if (!selectedAreaId) {
    selectedSubdivisionId = "";
    return;
  }

  if (scope.children.length) {
    let area = getSelectedArea();
    if (!area) {
      selectedAreaId = "";
      selectedSubdivisionId = "";
      return;
    }

    if (area.children.length) {
      if (selectedSubdivisionId) {
        const subdivision = getSelectedSubdivision();
        if (!subdivision) {
          selectedSubdivisionId = "";
        }
      }
    } else {
      selectedSubdivisionId = "";
    }
  } else {
    selectedAreaId = "";
    selectedSubdivisionId = "";
  }
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function showMapError(message) {
  const wrapper = ROOT_EL || document.querySelector(".boundary-map-wrapper");
  if (!wrapper) return;

  const existing = wrapper.querySelector(".ach-map-error-notice");
  if (existing) return;

  const notice = document.createElement("div");
  notice.className = "ach-map-error-notice";
  notice.setAttribute("role", "alert");
  notice.textContent = message || "Map data could not be loaded. Please try refreshing the page.";
  wrapper.insertBefore(notice, wrapper.firstChild);
}

function setSelectOptions(selectEl, options, value, emptyLabel) {
  if (!selectEl) return;

  const optionMarkup = options.length
    ? options
        .map((option) => {
          const selected = option.id === value ? " selected" : "";
          return `<option value="${escapeHtml(option.id)}"${selected}>${escapeHtml(option.label)}</option>`;
        })
        .join("")
    : `<option value="">${escapeHtml(emptyLabel)}</option>`;

  selectEl.innerHTML = optionMarkup;
  selectEl.disabled = !options.length;

  if (options.length && !options.some((option) => option.id === value)) {
    selectEl.value = options[0].id;
  }
}

function setSelectOptionsWithBlank(selectEl, options, value, emptyLabel, blankLabel = "") {
  if (!selectEl) return;

  const safeOptions = Array.isArray(options) ? options : [];
  if (!safeOptions.length) {
    selectEl.innerHTML = `<option value="">${escapeHtml(emptyLabel)}</option>`;
    selectEl.disabled = true;
    return;
  }

  const optionMarkup = safeOptions
    .map((option) => {
      const selected = option.id === value ? " selected" : "";
      return `<option value="${escapeHtml(option.id)}"${selected}>${escapeHtml(option.label)}</option>`;
    })
    .join("");

  const blankOption = blankLabel
    ? `<option value=""${value ? "" : " selected"}>${escapeHtml(blankLabel)}</option>`
    : "";

  selectEl.innerHTML = `${blankOption}${optionMarkup}`;
  selectEl.disabled = false;
}

function initGeographyControls() {
  const scopeSelect = document.getElementById("ach-map-scope");
  const areaSelect = document.getElementById("ach-map-area");
  const areaLabel = document.getElementById("ach-map-area-label");
  const subdivisionField = document.getElementById("ach-map-subdivision-field");
  const subdivisionSelect = document.getElementById("ach-map-subdivision");
  const subdivisionLabel = document.getElementById("ach-map-subdivision-label");

  if (!scopeSelect || !areaSelect || !areaLabel || !subdivisionField || !subdivisionSelect || !subdivisionLabel) {
    return;
  }

  ensureSelectedGeography();

  setSelectOptionsWithBlank(
    scopeSelect,
    geographyConfig.scopes,
    selectedScopeId,
    "No map types configured",
    "Whole of Australia"
  );

  const renderChildSelectors = () => {
    const scope = getSelectedScope();
    const scopeChildren = scope && Array.isArray(scope.children) ? scope.children : [];

    areaLabel.textContent = scope ? scope.groupLabel || "Area" : "Area";
    setSelectOptionsWithBlank(
      areaSelect,
      scopeChildren,
      selectedAreaId,
      "No areas configured",
      scope ? "All states and territories" : ""
    );

    const area = getSelectedArea();
    const subdivisionChildren = area && Array.isArray(area.children) ? area.children : [];

    if (subdivisionChildren.length) {
      subdivisionField.hidden = false;
      subdivisionLabel.textContent = scope ? scope.itemLabel || "Subdivision" : "Subdivision";
      setSelectOptionsWithBlank(
        subdivisionSelect,
        subdivisionChildren,
        selectedSubdivisionId,
        "No subdivisions configured",
        "Whole state or territory"
      );
    } else {
      subdivisionField.hidden = true;
      subdivisionSelect.innerHTML = "";
      subdivisionSelect.disabled = true;
    }
  };

  scopeSelect.onchange = async () => {
    selectedScopeId = scopeSelect.value;
    selectedAreaId = "";
    selectedSubdivisionId = "";
    ensureSelectedGeography();
    renderChildSelectors();
    await loadBoundaryForSelection();
  };

  areaSelect.onchange = async () => {
    selectedAreaId = areaSelect.value;
    selectedSubdivisionId = "";
    ensureSelectedGeography();
    renderChildSelectors();
    await loadBoundaryForSelection();
  };

  subdivisionSelect.onchange = async () => {
    selectedSubdivisionId = subdivisionSelect.value;
    ensureSelectedGeography();
    await loadBoundaryForSelection();
  };

  renderChildSelectors();
}

function resolveBoundaryUrl(node) {
  if (!node) {
    return (window.BOUNDARY_MAP_CONFIG && BOUNDARY_MAP_CONFIG.regionUrl) || "E_NSW24_region1.json";
  }

  if (node.geojsonUrl) {
    return node.geojsonUrl;
  }

  if (node.geojson) {
    if (/^(https?:)?\/\//.test(node.geojson) || node.geojson.startsWith("/")) {
      return node.geojson;
    }

    const baseUrl =
      (window.BOUNDARY_MAP_CONFIG && BOUNDARY_MAP_CONFIG.assetsBaseUrl) ||
      "";

    return `${baseUrl}${node.geojson}`;
  }

  return (window.BOUNDARY_MAP_CONFIG && BOUNDARY_MAP_CONFIG.regionUrl) || "E_NSW24_region1.json";
}

function getBoundaryPreviewState() {
  const country = getCountryNode();

  if (!selectedScopeId) {
    return {
      displayName: country ? country.displayName || country.label : "Australia",
      nodes: country ? [country] : [],
    };
  }

  const scope = getSelectedScope();
  if (!scope) {
    return {
      displayName: country ? country.displayName || country.label : "Australia",
      nodes: country ? [country] : [],
    };
  }

  if (!selectedAreaId) {
    return {
      displayName: scope.label || "Selected parliament level",
      nodes: Array.isArray(scope.children) ? scope.children : [],
    };
  }

  const area = getSelectedArea();
  if (!area) {
    return {
      displayName: scope.label || "Selected parliament level",
      nodes: Array.isArray(scope.children) ? scope.children : [],
    };
  }

  const subdivision = getSelectedSubdivision();
  if (subdivision) {
    return {
      displayName: subdivision.displayName || subdivision.label || "Boundary",
      nodes: [subdivision],
    };
  }

  return {
    displayName: area.displayName || area.label || "Boundary",
    nodes: [area],
  };
}

function combineGeoJSONPayloads(items) {
  const features = [];

  items.forEach(({ geojson, node }) => {
    if (!geojson) return;

    if (geojson.type === "FeatureCollection" && Array.isArray(geojson.features)) {
      geojson.features.forEach((feature) => {
        features.push({
          ...feature,
          properties: {
            ...(feature.properties || {}),
            __achBoundaryName: node.displayName || node.label || "Boundary",
            __achBoundaryColor: node.color || DEFAULT_BOUNDARY_COLOR,
          },
        });
      });
      return;
    }

    if (geojson.type === "Feature") {
      features.push({
        ...geojson,
        properties: {
          ...(geojson.properties || {}),
          __achBoundaryName: node.displayName || node.label || "Boundary",
          __achBoundaryColor: node.color || DEFAULT_BOUNDARY_COLOR,
        },
      });
    }
  });

  return {
    type: "FeatureCollection",
    features,
  };
}

async function loadBoundaryForSelection() {
  const preview = getBoundaryPreviewState();
  if (!preview.nodes.length) {
    clearBoundaryLayer();
    if (!IS_SHAPE_ONLY) {
      refreshCategoryTabs();
      applySearchAndCategory();
    }
    return;
  }

  try {
    const boundaryItems = await Promise.all(
      preview.nodes.map(async (node) => {
        const filePath = resolveBoundaryUrl(node);
        const response = await fetch(filePath, { cache: "no-cache" });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const geojson = await response.json();
        return { node, geojson };
      })
    );

    const mergedGeoJSON = combineGeoJSONPayloads(boundaryItems);
    setBoundaryLayer(mergedGeoJSON, preview.displayName || "Boundary", DEFAULT_BOUNDARY_COLOR);
  } catch (err) {
    console.error("Failed to load boundary:", err);
    if (!IS_SHAPE_ONLY) {
      refreshCategoryTabs();
      applySearchAndCategory();
    }
  }
}

function clearBoundaryLayer() {
  if (boundaryLayer) {
    map.removeLayer(boundaryLayer);
    boundaryLayer = null;
  }

  activeBoundaryGeoJSON = null;
  initialBounds = null;
  initialBoundaryCenter = null;
}

function setBoundaryLayer(geojson, displayName, color) {
  clearBoundaryLayer();

  boundaryLayer = L.geoJSON(geojson, {
    style: (feature) => {
      const featureColor =
        feature && feature.properties && feature.properties.__achBoundaryColor
          ? feature.properties.__achBoundaryColor
          : color;
      return {
        color: featureColor,
        weight: 2,
        fillColor: featureColor,
        fillOpacity: 0.3,
      };
    },
    onEachFeature: (feature, layer) => {
      const props = feature && feature.properties ? feature.properties : {};
      const boundaryName = props.__achBoundaryName || resolveSuburbName(feature, displayName);
      layer.bindPopup(`<strong>${boundaryName}</strong>`);
    },
  }).addTo(map);

  activeBoundaryGeoJSON = geojson;

  const bounds = boundaryLayer.getBounds();
  if (bounds.isValid()) {
    initialBounds = bounds;
    initialBoundaryCenter = bounds.getCenter();

    if (ZOOM_MODE === "custom") {
      map.setView(
        initialBoundaryCenter,
        clampZoomValue(DEFAULT_ZOOM, MIN_ZOOM, MAX_ZOOM)
      );
    } else {
      map.fitBounds(bounds, { padding: [24, 24] });
    }
  } else {
    initialBounds = null;
    initialBoundaryCenter = null;
    map.setView(DEFAULT_CENTER, clampZoomValue(DEFAULT_ZOOM, MIN_ZOOM, MAX_ZOOM));
  }

  if (!IS_SHAPE_ONLY) {
    refreshCategoryTabs();
    applySearchAndCategory();
  }
}

function clearActiveMarker() {
  if (activeMarkerEntry && activeMarkerEntry.marker) {
    const { marker, entry } = activeMarkerEntry;
    marker.setIcon(createPinIcon(entry, false));
  }
  activeMarkerEntry = null;
}

function highlightMarkerForEntry(ev) {
  clearActiveMarker();
  const entry = markersByTitle.get(ev.entryKey);
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
  const card = Array.from(listEl.querySelectorAll(".entry")).find(
    (item) => item.dataset.entryKey === ev.entryKey
  );
  if (card) {
    card.classList.add("entry--active");
    card.scrollIntoView({ block: "nearest", behavior: "smooth" });
  }
}

function renderEntryList(entryArray) {
  const listEl = document.getElementById("entry-list");
  const detailsEl = document.getElementById("entry-details");
  if (!listEl || !detailsEl) return;

  listEl.style.display = "";
  detailsEl.style.display = "none";
  detailsEl.innerHTML = "";
  listEl.innerHTML = "";

  if (!entryArray.length) {
    return;
  }

  entryArray.forEach((ev) => {
    const card = document.createElement("div");
    card.className = "entry";
    card.dataset.entryKey = ev.entryKey;

    const thumb = createEntryImageBlock(ev, "category-badge");
    if (thumb) {
      card.appendChild(thumb);
    }

    const content = document.createElement("div");
    content.className = "entry-content";

    const title = document.createElement("p");
    title.className = "entry-title mb-0";
    title.textContent = ev.title;
    content.appendChild(title);

    const description = document.createElement("p");
    description.className = "mb-0";
    description.textContent = ev.description;
    content.appendChild(description);

    const meta = document.createElement("div");
    meta.className = "entry-meta";

    const location = document.createElement("span");
    location.className = "entry-location";
    location.textContent = ev.location;
    meta.appendChild(location);

    content.appendChild(meta);
    card.appendChild(content);

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
  detailsEl.innerHTML = "";

  const backWrap = document.createElement("span");
  backWrap.style.marginBottom = "15px";
  const backBtn = document.createElement("a");
  backBtn.href = "#";
  backBtn.id = "back-to-list";
  backBtn.textContent = "<- Back to results";
  backWrap.appendChild(backBtn);
  detailsEl.appendChild(backWrap);

  const thumb = createEntryImageBlock(ev, "category-badge-details", { marginBottom: "10px" });
  if (thumb) {
    detailsEl.appendChild(thumb);
  }

  const title = document.createElement("p");
  title.className = "entry-title";
  title.textContent = ev.title;
  detailsEl.appendChild(title);

  const description = document.createElement("p");
  description.className = "mb-0";
  description.textContent = ev.description;
  detailsEl.appendChild(description);

  const address = document.createElement("p");
  const strong = document.createElement("strong");
  strong.textContent = "Address:";
  address.appendChild(strong);
  address.appendChild(document.createTextNode(` ${ev.location}`));
  detailsEl.appendChild(address);

  listEl.style.display = "none";
  detailsEl.style.display = "block";

  if (backBtn) {
    backBtn.addEventListener("click", (e) => {
      e.preventDefault();
      applySearchAndCategory();

      if (ZOOM_MODE === "fit" && initialBounds) {
        map.fitBounds(initialBounds, { padding: [24, 24] });
      } else if (initialBoundaryCenter) {
        map.setView(
          initialBoundaryCenter,
          clampZoomValue(DEFAULT_ZOOM, MIN_ZOOM, MAX_ZOOM)
        );
      } else {
        map.setView(DEFAULT_CENTER, clampZoomValue(DEFAULT_ZOOM, MIN_ZOOM, MAX_ZOOM));
      }

      clearActiveMarker();
      clearActiveEntryCard();
    });
  }

  if (ev.coords) {
    map.setView(ev.coords, 16);

    if (window.matchMedia("(max-width: 768px)").matches) {
      setTimeout(() => {
        map.panBy([0, -100], { animate: true });
      }, 50);
    }
  }
}

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

    if (MARKER_TAG_MODE === "visible") {
      marker.bindTooltip(createMarkerTooltipContent(ev), {
        permanent: true,
        direction: "top",
        offset: [0, -26],
        opacity: 1,
        interactive: false,
        className: "entry-marker-tag-tooltip",
      });
    } else {
      marker.bindPopup(createMarkerPopupContent(ev));
    }

    marker.on("click", () => {
      showEntryDetails(ev);
      highlightMarkerForEntry(ev);
      highlightEntryCard(ev);
    });

    markersByTitle.set(ev.entryKey, { marker, entry: ev });
    entryMarkers.addLayer(marker);
  });
}

function getUsedCategoryIds(entries) {
  const used = new Set();

  entries.forEach((ev) => {
    const id = ev?.categoryId;
    if (!id || id === ALL_CATEGORY_ID) return;
    used.add(id);
  });

  return used;
}

function getSyntheticCategories(entries, usedCategoryIds) {
  const syntheticCategories = [];
  const seen = new Set();

  entries.forEach((ev) => {
    const id = ev?.categoryId;
    if (!id || id === ALL_CATEGORY_ID || categoriesById[id] || !usedCategoryIds.has(id) || seen.has(id)) {
      return;
    }

    syntheticCategories.push({
      id,
      label: ev.categoryLabel || id,
    });
    seen.add(id);
  });

  return syntheticCategories;
}

function initCategoryTabs() {
  const ul = document.getElementById("category-filter");
  if (!ul) return;

  ul.addEventListener("click", (e) => {
    const link = e.target.closest(".nav-link");
    if (!link) return;

    e.preventDefault();

    activeCategoryId = link.dataset.category || ALL_CATEGORY_ID;
    syncActiveCategoryButton();
    applySearchAndCategory();
  });
}

function refreshCategoryTabs() {
  const ul = document.getElementById("category-filter");
  if (!ul) return;

  ul.innerHTML = "";

  const filteredByBoundary = getBoundaryFilteredEntries();
  const usedCategoryIds = getUsedCategoryIds(filteredByBoundary);
  const baseCategories = usedCategoryIds.size
    ? categories.filter((cat) => cat.id === ALL_CATEGORY_ID || usedCategoryIds.has(cat.id))
    : categories.filter((cat) => cat.id === ALL_CATEGORY_ID);
  const visibleCategories = baseCategories.concat(
    getSyntheticCategories(filteredByBoundary, usedCategoryIds)
  );

  if (!visibleCategories.some((cat) => cat.id === activeCategoryId)) {
    activeCategoryId = visibleCategories[0]?.id || ALL_CATEGORY_ID;
  }

  visibleCategories.forEach((cat) => {
    const li = document.createElement("li");
    li.className = "nav-item";

    const a = document.createElement("a");
    a.href = "#";
    a.className = "nav-link mx-2 px-3 py-2 border rounded";
    a.dataset.category = cat.id;
    a.textContent = cat.label;

    li.appendChild(a);
    ul.appendChild(li);
  });

  syncActiveCategoryButton();
}

function syncActiveCategoryButton() {
  document.querySelectorAll("#category-filter .nav-link").forEach((a) => {
    a.classList.toggle("active", a.dataset.category === activeCategoryId);
  });
}

function initLegend() {
  const legend = document.getElementById("map-legend");
  if (!legend) return;

  if (!SHOW_CATEGORY_BOX) {
    legend.hidden = true;
    legend.innerHTML = "";
    return;
  }

  legend.hidden = false;

  legend.innerHTML = "";

  const title = document.createElement("h6");
  title.className = "legend-title mb-1";
  title.textContent = "Categories";
  legend.appendChild(title);

  categories
    .filter((cat) => cat.id !== ALL_CATEGORY_ID)
    .forEach((cat) => {
      const color = cat.color || null;
      const icon = cat.icon || "";
      const item = document.createElement("div");
      item.className = "legend-item";

      const swatch = document.createElement("span");
      swatch.className = "legend-swatch";
      if (color) {
        swatch.style.background = color;
      }
      item.appendChild(swatch);

      item.appendChild(
        document.createTextNode(icon ? `${icon} ${cat.label}` : cat.label)
      );
      legend.appendChild(item);
    });
}

function initSearch() {
  const form = document.getElementById("entry-search-form");
  const input = document.getElementById("entry-search-input");

  if (!input) return;

  const updateSearch = () => {
    searchQuery = input.value || "";
    applySearchAndCategory();
  };

  input.addEventListener("input", updateSearch);

  if (form) {
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      updateSearch();
    });
  }
}

function pointInRing(point, ring) {
  let inside = false;
  const x = point[0];
  const y = point[1];

  for (let i = 0, j = ring.length - 1; i < ring.length; j = i, i += 1) {
    const xi = ring[i][0];
    const yi = ring[i][1];
    const xj = ring[j][0];
    const yj = ring[j][1];

    const intersects =
      yi > y !== yj > y &&
      x < ((xj - xi) * (y - yi)) / ((yj - yi) || Number.EPSILON) + xi;

    if (intersects) {
      inside = !inside;
    }
  }

  return inside;
}

function pointInPolygonGeometry(point, polygonCoords) {
  if (!Array.isArray(polygonCoords) || !polygonCoords.length) {
    return false;
  }

  if (!pointInRing(point, polygonCoords[0])) {
    return false;
  }

  for (let i = 1; i < polygonCoords.length; i += 1) {
    if (pointInRing(point, polygonCoords[i])) {
      return false;
    }
  }

  return true;
}

function geometryContainsPoint(point, geometry) {
  if (!geometry || !geometry.type) return false;

  if (geometry.type === "Polygon") {
    return pointInPolygonGeometry(point, geometry.coordinates);
  }

  if (geometry.type === "MultiPolygon") {
    return geometry.coordinates.some((polygonCoords) =>
      pointInPolygonGeometry(point, polygonCoords)
    );
  }

  return false;
}

function geoJSONContainsLatLng(geojson, latLng) {
  if (!geojson || !latLng || latLng.length < 2) {
    return false;
  }

  const point = [latLng[1], latLng[0]];
  const features =
    geojson.type === "FeatureCollection"
      ? geojson.features || []
      : geojson.type === "Feature"
        ? [geojson]
        : [];

  return features.some((feature) => geometryContainsPoint(point, feature.geometry));
}

function getBoundaryFilteredEntries() {
  const scope = getSelectedScope();
  if (!scope) {
    return entriesData;
  }

  if (!scope.children.length || !activeBoundaryGeoJSON) {
    return entriesData;
  }

  return entriesData.filter((ev) => ev.coords && geoJSONContainsLatLng(activeBoundaryGeoJSON, ev.coords));
}

function applySearchAndCategory() {
  let baseEntries = getBoundaryFilteredEntries();
  const q = searchQuery.trim();

  if (q && fuse) {
    const results = fuse.search(q);
    const visibleKeys = new Set(baseEntries.map((ev) => ev.entryKey));
    baseEntries = results
      .map((result) => result.item)
      .filter((ev) => visibleKeys.has(ev.entryKey));
  } else if (q) {
    const lower = q.toLowerCase();
    baseEntries = baseEntries.filter((ev) =>
      [ev.title, ev.location, ev.description, ev.categoryLabel].some(
        (field) => field && field.toLowerCase().includes(lower)
      )
    );
  }

  const filtered = baseEntries.filter((ev) =>
    activeCategoryId === ALL_CATEGORY_ID ? true : ev.categoryId === activeCategoryId
  );

  renderEntryList(filtered);
  drawEntryMarkers(filtered);
}

async function loadCategories() {
  try {
    const apiUrl =
      (window.BOUNDARY_MAP_CONFIG && BOUNDARY_MAP_CONFIG.categoriesApiUrl) ||
      (window.BOUNDARY_MAP_CONFIG && BOUNDARY_MAP_CONFIG.categoriesUrl) ||
      "categories.json";

    const res = await fetch(apiUrl, { cache: "no-cache" });
    if (!res.ok) throw new Error(`categories HTTP ${res.status}`);

    const data = await res.json();
    categories = Array.isArray(data.categories) ? data.categories : data;

    categoriesById = {};
    categories.forEach((cat) => {
      if (cat.id) categoriesById[cat.id] = cat;
    });

    if (!categories.some((cat) => cat.id === ALL_CATEGORY_ID)) {
      categories.unshift({ id: ALL_CATEGORY_ID, label: "All" });
    }

    categoriesById[ALL_CATEGORY_ID] =
      categoriesById[ALL_CATEGORY_ID] || { id: ALL_CATEGORY_ID, label: "All" };
  } catch (err) {
    console.error("Failed to load categories:", err);
    return false;
  }
  return true;
}

async function loadEntries() {
  try {
    const apiUrl =
      (window.BOUNDARY_MAP_CONFIG && BOUNDARY_MAP_CONFIG.entriesApiUrl) ||
      (window.BOUNDARY_MAP_CONFIG && BOUNDARY_MAP_CONFIG.entriesUrl) ||
      "entries.json";

    const res = await fetch(apiUrl, { cache: "no-cache" });
    if (!res.ok) {
      if (apiUrl === "entries.json" && res.status === 404) {
        entriesData = [];
        return true;
      }

      throw new Error(`entries HTTP ${res.status}`);
    }

    const rawEntries = await res.json();
    entriesData = rawEntries
      .map((item, index) => normaliseEntry(item, index))
      .filter(Boolean);
  } catch (err) {
    console.error("Failed to load entries:", err);
    entriesData = [];
    return false;
  }
  return true;
}

async function init() {
  initGeographyControls();
  ensureSelectedGeography();
  await loadBoundaryForSelection();
  syncMapSize();

  if (IS_SHAPE_ONLY) {
    return;
  }

  const categoriesOk = await loadCategories();
  const entriesOk = await loadEntries();

  if (!categoriesOk || !entriesOk) {
    showMapError("Map data could not be loaded. Please refresh the page or contact the site administrator.");
  }

  buildFuseIndex();
  initCategoryTabs();
  initSearch();
  initLegend();

  activeCategoryId = categories[0]?.id || ALL_CATEGORY_ID;
  refreshCategoryTabs();
  applySearchAndCategory();
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", init);
} else {
  init();
}
