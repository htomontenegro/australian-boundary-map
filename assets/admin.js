document.addEventListener("DOMContentLoaded", () => {
  jQuery(function ($) {
    if ($(".ach-color-field").length) {
      $(".ach-color-field").wpColorPicker();
    }
  });

  initConfigPage();
  initEntryFormPage();
});

function getAdminGeographyConfig() {
  if (window.BOUNDARY_MAP_ADMIN && BOUNDARY_MAP_ADMIN.geographyConfig) {
    return BOUNDARY_MAP_ADMIN.geographyConfig;
  }

  return {
    defaultScopeId: "federal",
    country: {
      id: "australia",
      label: "Australia",
      displayName: "Australia",
      geojsonUrl:
        "https://geo.abs.gov.au/arcgis/rest/services/ASGS2021/AUS/MapServer/0/query?where=1%3D1&outFields=AUS_NAME_2021&returnGeometry=true&outSR=4326&f=geojson",
      color: "#4B7CD5",
    },
    scopes: [],
  };
}

function getAdminGeographySelection() {
  const raw = window.BOUNDARY_MAP_ADMIN && BOUNDARY_MAP_ADMIN.geographySelection
    ? BOUNDARY_MAP_ADMIN.geographySelection
    : {};

  return {
    country: raw.country || "australia",
    scope: raw.scope || "",
    area: raw.area || "",
    subdivision: raw.subdivision || "",
  };
}

function getAdminCategories() {
  const raw = window.BOUNDARY_MAP_ADMIN && Array.isArray(BOUNDARY_MAP_ADMIN.categories)
    ? BOUNDARY_MAP_ADMIN.categories
    : [];

  return raw.filter((category) => category && category.id);
}

function getScopeById(config, scopeId) {
  return (config.scopes || []).find((scope) => scope.id === scopeId) || null;
}

function getChildById(children, childId) {
  return (children || []).find((child) => child.id === childId) || null;
}

function getCountryNode(config) {
  if (config && config.country && typeof config.country === "object") {
    return config.country;
  }

  return {
    id: "australia",
    label: "Australia",
    displayName: "Australia",
    geojsonUrl:
      "https://geo.abs.gov.au/arcgis/rest/services/ASGS2021/AUS/MapServer/0/query?where=1%3D1&outFields=AUS_NAME_2021&returnGeometry=true&outSR=4326&f=geojson",
    color: "#4B7CD5",
  };
}

function ensureValidSelection(config, selection) {
  const scopes = Array.isArray(config.scopes) ? config.scopes : [];
  const next = { ...selection };

  if (!next.scope) {
    next.area = "";
    next.subdivision = "";
    return next;
  }

  let scope = getScopeById(config, next.scope);
  if (!scope) {
    scope = scopes[0] || null;
    next.scope = scope ? scope.id : "";
  }

  if (!scope) {
    next.area = "";
    next.subdivision = "";
    return next;
  }

  if (!next.area) {
    next.subdivision = "";
    return next;
  }

  let area = getChildById(scope.children, next.area);
  if (!area) {
    next.area = "";
    next.subdivision = "";
    return next;
  }

  if (!area) {
    next.subdivision = "";
    return next;
  }

  if (Array.isArray(area.children) && area.children.length) {
    if (next.subdivision) {
      const subdivision = getChildById(area.children, next.subdivision);
      if (!subdivision) {
        next.subdivision = "";
      }
    }
  } else {
    next.subdivision = "";
  }

  return next;
}

function buildBoundaryUrl(node) {
  if (!node) {
    return window.BOUNDARY_MAP_ADMIN && BOUNDARY_MAP_ADMIN.regionUrl
      ? BOUNDARY_MAP_ADMIN.regionUrl
      : "";
  }

  if (node.geojsonUrl) {
    return node.geojsonUrl;
  }

  if (node.geojson) {
    if (/^(https?:)?\/\//.test(node.geojson) || node.geojson.startsWith("/")) {
      return node.geojson;
    }

    const baseUrl = window.BOUNDARY_MAP_ADMIN && BOUNDARY_MAP_ADMIN.assetsBaseUrl
      ? BOUNDARY_MAP_ADMIN.assetsBaseUrl
      : "";

    return `${baseUrl}${node.geojson}`;
  }

  return window.BOUNDARY_MAP_ADMIN && BOUNDARY_MAP_ADMIN.regionUrl
    ? BOUNDARY_MAP_ADMIN.regionUrl
    : "";
}

function getActiveBoundaryNode(config, selection) {
  const scope = getScopeById(config, selection.scope);
  if (!scope) return null;

  const area = getChildById(scope.children, selection.area);
  if (!area) return null;

  if (Array.isArray(area.children) && area.children.length) {
    const subdivision = getChildById(area.children, selection.subdivision);
    if (subdivision) {
      return subdivision;
    }
  }

  return area;
}

function getPreviewNodes(config, selection) {
  const country = getCountryNode(config);

  if (!selection.scope) {
    return {
      title: country.displayName || country.label || "Australia",
      nodes: [country],
    };
  }

  const scope = getScopeById(config, selection.scope);
  if (!scope) {
    return {
      title: country.displayName || country.label || "Australia",
      nodes: [country],
    };
  }

  if (!selection.area) {
    return {
      title: scope.label || "Selected parliament level",
      nodes: Array.isArray(scope.children) ? scope.children : [],
    };
  }

  const area = getChildById(scope.children, selection.area);
  if (!area) {
    return {
      title: scope.label || "Selected parliament level",
      nodes: Array.isArray(scope.children) ? scope.children : [],
    };
  }

  if (selection.subdivision) {
    const subdivision = getChildById(area.children, selection.subdivision);
    if (subdivision) {
      return {
        title: subdivision.displayName || subdivision.label || "Selected boundary",
        nodes: [subdivision],
      };
    }
  }

  return {
    title: area.displayName || area.label || "Selected boundary",
    nodes: [area],
  };
}

function combineGeoJSONPayloads(items) {
  const features = [];

  items.forEach(({ geojson, node }) => {
    if (!geojson) return;

    if (geojson.type === "FeatureCollection" && Array.isArray(geojson.features)) {
      geojson.features.forEach((feature) => {
        const nextFeature = { ...feature };
        nextFeature.properties = {
          ...(feature.properties || {}),
          __achBoundaryName: node.displayName || node.label || "Boundary",
          __achBoundaryColor: node.color || "#4B7CD5",
        };
        features.push(nextFeature);
      });
      return;
    }

    if (geojson.type === "Feature") {
      features.push({
        ...geojson,
        properties: {
          ...(geojson.properties || {}),
          __achBoundaryName: node.displayName || node.label || "Boundary",
          __achBoundaryColor: node.color || "#4B7CD5",
        },
      });
    }
  });

  return {
    type: "FeatureCollection",
    features,
  };
}

function setSelectOptions(selectEl, options, value, emptyLabel, blankLabel = "") {
  if (!selectEl) return;

  const safeOptions = Array.isArray(options) ? options : [];

  if (!safeOptions.length) {
    selectEl.innerHTML = `<option value="">${emptyLabel}</option>`;
    selectEl.disabled = true;
    return;
  }

  const optionMarkup = safeOptions
    .map((option) => {
      const selected = option.id === value ? " selected" : "";
      return `<option value="${option.id}"${selected}>${option.label}</option>`;
    })
    .join("");

  const blankOption = blankLabel
    ? `<option value=""${value ? "" : " selected"}>${blankLabel}</option>`
    : "";

  selectEl.innerHTML = `${blankOption}${optionMarkup}`;
  selectEl.disabled = false;
}

function buildSelectionShortcode(selection, shortcodeName = "boundary_map", options = {}) {
  const parts = [shortcodeName];

  if (!selection || !selection.scope) {
    parts.push('scope="country"');
  } else {
    parts.push(`scope="${selection.scope}"`);

    if (selection.area) {
      parts.push(`area="${selection.area}"`);
    }

    if (selection.subdivision) {
      parts.push(`subdivision="${selection.subdivision}"`);
    }
  }

  if (options.width) {
    parts.push(`width="${options.width}"`);
  }

  if (options.height) {
    parts.push(`height="${options.height}"`);
  }

  if (options.zoomMode) {
    parts.push(`zoommode="${options.zoomMode}"`);
  }

  if (options.zoomMode === "custom" && options.zoom) {
    parts.push(`zoom="${options.zoom}"`);
  }

  if (options.minZoom) {
    parts.push(`minzoom="${options.minZoom}"`);
  }

  if (options.maxZoom) {
    parts.push(`maxzoom="${options.maxZoom}"`);
  }

  if (typeof options.scrollWheel !== "undefined" && options.scrollWheel !== "") {
    parts.push(`scrollwheel="${options.scrollWheel}"`);
  }

  if (typeof options.categoryBox !== "undefined" && options.categoryBox !== "") {
    parts.push(`categorybox="${options.categoryBox}"`);
  }

  if (typeof options.sidebarPanel !== "undefined" && options.sidebarPanel !== "") {
    parts.push(`sidebarpanel="${options.sidebarPanel}"`);
  }

  if (typeof options.markerTag !== "undefined" && options.markerTag !== "") {
    parts.push(`markertag="${options.markerTag}"`);
  }

  return `[${parts.join(" ")}]`;
}

function initConfigPage() {
  const DEFAULT_PREVIEW_CENTER = [-33.6, 151.1];
  const DEFAULT_PREVIEW_ZOOM = 11.45;
  const mapContainer = document.getElementById("ach-config-map");
  const scopeSelect = document.getElementById("ach-config-scope");
  const areaSelect = document.getElementById("ach-config-area");
  const areaLabel = document.getElementById("ach-config-area-label");
  const subdivisionRow = document.getElementById("ach-config-subdivision-row");
  const subdivisionSelect = document.getElementById("ach-config-subdivision");
  const subdivisionLabel = document.getElementById("ach-config-subdivision-label");
  const previewTitle = document.getElementById("ach-config-preview-title");
  const currentBoundary = document.getElementById("ach-config-current-boundary");
  const previewLegend = document.getElementById("ach-config-map-legend");
  const generateBtn = document.getElementById("ach-config-generate-shortcode");
  const copyBtn = document.getElementById("ach-config-copy-shortcode");
  const shortcodeOutput = document.getElementById("ach-config-shortcode-output");
  const widthPreset = document.getElementById("ach-config-shortcode-width-preset");
  const widthCustomWrap = document.getElementById("ach-config-shortcode-width-custom-wrap");
  const widthCustomInput = document.getElementById("ach-config-shortcode-width-custom");
  const heightPreset = document.getElementById("ach-config-shortcode-height-preset");
  const heightCustomWrap = document.getElementById("ach-config-shortcode-height-custom-wrap");
  const heightCustomInput = document.getElementById("ach-config-shortcode-height-custom");
  const zoomModeSelect = document.getElementById("ach-config-shortcode-zoommode");
  const zoomWrap = document.getElementById("ach-config-shortcode-zoom-wrap");
  const zoomInput = document.getElementById("ach-config-shortcode-zoom");
  const minZoomInput = document.getElementById("ach-config-shortcode-minzoom");
  const maxZoomInput = document.getElementById("ach-config-shortcode-maxzoom");
  const scrollWheelSelect = document.getElementById("ach-config-shortcode-scrollwheel");
  const categoryBoxSelect = document.getElementById("ach-config-shortcode-categorybox");
  const sidebarPanelSelect = document.getElementById("ach-config-shortcode-sidebarpanel");
  const markerTagSelect = document.getElementById("ach-config-shortcode-markertag");

  if (!mapContainer || !scopeSelect || !areaSelect || !areaLabel || !subdivisionRow || !subdivisionSelect || !subdivisionLabel) {
    return;
  }

  if (typeof L === "undefined") {
    console.warn("Leaflet not loaded in admin.");
    return;
  }

  const config = getAdminGeographyConfig();
  let selection = ensureValidSelection(config, getAdminGeographySelection());

  const map = L.map(mapContainer, {
    zoomSnap: 0,
    zoomDelta: 0.05,
  }).setView(DEFAULT_PREVIEW_CENTER, DEFAULT_PREVIEW_ZOOM);
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
  }).addTo(map);

  let boundaryLayer = null;
  let previewRequestId = 0;
  const previewCategories = getAdminCategories().filter((category) => category.id !== "All");

  const getPresetValue = (presetEl, customEl, fallbackValue) => {
    if (!presetEl) {
      return fallbackValue;
    }

    if (presetEl.value === "custom") {
      return customEl && customEl.value.trim() ? customEl.value.trim() : fallbackValue;
    }

    return presetEl.value || fallbackValue;
  };

  const parseZoomValue = (value, fallbackValue) => {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : fallbackValue;
  };

  const clampZoomValue = (value, minZoom, maxZoom) => {
    let nextValue = value;

    if (Number.isFinite(minZoom)) {
      nextValue = Math.max(nextValue, minZoom);
    }

    if (Number.isFinite(maxZoom)) {
      nextValue = Math.min(nextValue, maxZoom);
    }

    return nextValue;
  };

  const getPreviewMapOptions = () => {
    const zoomMode = zoomModeSelect ? zoomModeSelect.value || "fit" : "fit";
    const minZoom = parseZoomValue(minZoomInput ? minZoomInput.value.trim() : "", 10);
    const maxZoom = parseZoomValue(maxZoomInput ? maxZoomInput.value.trim() : "", 17);
    const customZoom = parseZoomValue(zoomInput ? zoomInput.value.trim() : "", DEFAULT_PREVIEW_ZOOM);
    const zoom = clampZoomValue(customZoom, minZoom, maxZoom);

    return {
      zoomMode,
      zoom,
      minZoom,
      maxZoom,
      scrollWheelZoom: scrollWheelSelect ? scrollWheelSelect.value === "1" : false,
    };
  };

  const applyPreviewMapOptions = () => {
    const options = getPreviewMapOptions();

    map.setMinZoom(options.minZoom);
    map.setMaxZoom(options.maxZoom);

    if (options.scrollWheelZoom) {
      map.scrollWheelZoom.enable();
    } else {
      map.scrollWheelZoom.disable();
    }

    return options;
  };

  const applyPreviewViewport = (bounds) => {
    const options = applyPreviewMapOptions();

    if (bounds && bounds.isValid()) {
      if (options.zoomMode === "custom") {
        map.setView(bounds.getCenter(), options.zoom);
      } else {
        map.fitBounds(bounds, { padding: [24, 24] });
      }
      return;
    }

    const fallbackZoom =
      options.zoomMode === "custom" ? options.zoom : DEFAULT_PREVIEW_ZOOM;

    map.setView(
      DEFAULT_PREVIEW_CENTER,
      clampZoomValue(fallbackZoom, options.minZoom, options.maxZoom)
    );
  };

  const updateShortcodeSettingVisibility = () => {
    if (widthCustomWrap && widthPreset) {
      widthCustomWrap.hidden = widthPreset.value !== "custom";
    }

    if (heightCustomWrap && heightPreset) {
      heightCustomWrap.hidden = heightPreset.value !== "custom";
    }

    if (zoomWrap && zoomModeSelect) {
      zoomWrap.hidden = zoomModeSelect.value !== "custom";
    }
  };

  const updateShortcodeOutput = () => {
    if (!shortcodeOutput) {
      return;
    }

    const width = getPresetValue(widthPreset, widthCustomInput, "fit-container");
    const height = getPresetValue(heightPreset, heightCustomInput, "600px");
    const zoomMode = zoomModeSelect ? zoomModeSelect.value || "fit" : "fit";
    const zoom = zoomInput ? zoomInput.value.trim() : "";
    const minZoom = minZoomInput ? minZoomInput.value.trim() : "10";
    const maxZoom = maxZoomInput ? maxZoomInput.value.trim() : "17";
    const scrollWheel = scrollWheelSelect ? scrollWheelSelect.value : "0";
    const categoryBox = categoryBoxSelect ? categoryBoxSelect.value : "1";
    const sidebarPanel = sidebarPanelSelect ? sidebarPanelSelect.value : "1";
    const markerTag = markerTagSelect ? markerTagSelect.value : "clickable";

    shortcodeOutput.value = buildSelectionShortcode(selection, "boundary_map", {
      width,
      height,
      zoomMode,
      zoom,
      minZoom,
      maxZoom,
      scrollWheel,
      categoryBox,
      sidebarPanel,
      markerTag,
    });
  };

  const updatePreviewLegend = () => {
    if (!previewLegend) {
      return;
    }

    const showCategoryBox = !categoryBoxSelect || categoryBoxSelect.value !== "0";
    previewLegend.innerHTML = "";

    if (!showCategoryBox || !previewCategories.length) {
      previewLegend.hidden = true;
      return;
    }

    const title = document.createElement("h6");
    title.className = "ach-config-map-legend__title";
    title.textContent = "Categories";
    previewLegend.appendChild(title);

    previewCategories.forEach((category) => {
      const item = document.createElement("div");
      item.className = "ach-config-map-legend__item";

      const swatch = document.createElement("span");
      swatch.className = "ach-config-map-legend__swatch";
      if (category.color) {
        swatch.style.backgroundColor = category.color;
      }

      const label = document.createElement("span");
      label.textContent = category.label || category.id;

      item.appendChild(swatch);
      item.appendChild(label);
      previewLegend.appendChild(item);
    });

    previewLegend.hidden = false;
  };

  const updatePreview = async () => {
    previewRequestId += 1;
    const requestId = previewRequestId;
    const preview = getPreviewNodes(config, selection);

    if (previewTitle) {
      previewTitle.textContent = preview.title || "No boundary selected";
    }

    if (currentBoundary) {
      currentBoundary.textContent = `Current boundary: ${preview.title || "Australia"}`;
    }

    if (boundaryLayer) {
      map.removeLayer(boundaryLayer);
      boundaryLayer = null;
    }

    if (!preview.nodes.length) {
      applyPreviewViewport();
      return;
    }

    try {
      const boundaryItems = await Promise.all(
        preview.nodes.map(async (node) => {
          const url = buildBoundaryUrl(node);
          if (!url) {
            return null;
          }

          const response = await fetch(url, { cache: "no-cache" });
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }

          const geojson = await response.json();
          return { node, geojson };
        })
      );

      if (requestId !== previewRequestId) {
        return;
      }

      const mergedGeoJSON = combineGeoJSONPayloads(boundaryItems.filter(Boolean));
      if (!mergedGeoJSON.features.length) {
        applyPreviewViewport();
        return;
      }

      if (boundaryLayer) {
        map.removeLayer(boundaryLayer);
        boundaryLayer = null;
      }

      boundaryLayer = L.geoJSON(mergedGeoJSON, {
        style: {
          color: "#4B7CD5",
          weight: 2,
          fillColor: "#4B7CD5",
          fillOpacity: 0.3,
        },
        onEachFeature: (feature, layer) => {
          const props = feature && feature.properties ? feature.properties : {};
          const boundaryName =
            props.__achBoundaryName ||
            props.name ||
            props.Elect_div ||
            props.Sortname ||
            "Boundary";
          layer.bindPopup(`<strong>${boundaryName}</strong>`);
        },
      }).addTo(map);

      const bounds = boundaryLayer.getBounds();
      applyPreviewViewport(bounds);
    } catch (err) {
      console.error("Failed to load config preview boundary:", err);
    }
  };

  const renderSelectors = () => {
    setSelectOptions(
      scopeSelect,
      config.scopes || [],
      selection.scope,
      "No types configured",
      "Whole of Australia"
    );

    const scope = getScopeById(config, selection.scope);
    const areas = scope && Array.isArray(scope.children) ? scope.children : [];
    areaLabel.textContent = scope ? scope.groupLabel || "State or Territory" : "State or Territory";
    setSelectOptions(
      areaSelect,
      areas,
      selection.area,
      "No boundaries configured",
      scope ? "All states and territories" : ""
    );

    const area = getChildById(areas, selection.area);
    const subdivisions = area && Array.isArray(area.children) ? area.children : [];

    if (subdivisions.length) {
      subdivisionRow.hidden = false;
      subdivisionLabel.textContent = scope ? scope.itemLabel || "Region / Division" : "Region / Division";
      setSelectOptions(
        subdivisionSelect,
        subdivisions,
        selection.subdivision,
        "No subdivisions configured",
        "Whole state or territory"
      );
    } else {
      subdivisionRow.hidden = true;
      subdivisionSelect.innerHTML = "";
      subdivisionSelect.disabled = true;
    }
  };

  scopeSelect.addEventListener("change", async () => {
    selection.scope = scopeSelect.value;
    selection.area = "";
    selection.subdivision = "";
    selection = ensureValidSelection(config, selection);
    renderSelectors();
    updateShortcodeOutput();
    await updatePreview();
  });

  areaSelect.addEventListener("change", async () => {
    selection.area = areaSelect.value;
    selection.subdivision = "";
    selection = ensureValidSelection(config, selection);
    renderSelectors();
    updateShortcodeOutput();
    await updatePreview();
  });

  subdivisionSelect.addEventListener("change", async () => {
    selection.subdivision = subdivisionSelect.value;
    selection = ensureValidSelection(config, selection);
    renderSelectors();
    updateShortcodeOutput();
    await updatePreview();
  });

  if (generateBtn) {
    generateBtn.addEventListener("click", () => {
      updateShortcodeSettingVisibility();
      updateShortcodeOutput();
      if (shortcodeOutput) {
        shortcodeOutput.focus();
        shortcodeOutput.select();
      }
    });
  }

  if (copyBtn && shortcodeOutput) {
    copyBtn.addEventListener("click", async () => {
      updateShortcodeSettingVisibility();
      updateShortcodeOutput();

      try {
        await navigator.clipboard.writeText(shortcodeOutput.value);
        copyBtn.textContent = "Copied";
        window.setTimeout(() => {
          copyBtn.textContent = "Copy";
        }, 1400);
      } catch (err) {
        shortcodeOutput.focus();
        shortcodeOutput.select();
      }
    });
  }

  [
    widthPreset,
    widthCustomInput,
    heightPreset,
    heightCustomInput,
    zoomModeSelect,
    zoomInput,
    minZoomInput,
    maxZoomInput,
    scrollWheelSelect,
    categoryBoxSelect,
    sidebarPanelSelect,
    markerTagSelect,
  ].forEach((control) => {
    if (!control) {
      return;
    }

    const eventName = control.tagName === "SELECT" ? "change" : "input";
    control.addEventListener(eventName, () => {
      updateShortcodeSettingVisibility();
      updateShortcodeOutput();
      updatePreviewLegend();

      if (
        control === zoomModeSelect ||
        control === zoomInput ||
        control === minZoomInput ||
        control === maxZoomInput ||
        control === scrollWheelSelect
      ) {
        applyPreviewViewport(boundaryLayer ? boundaryLayer.getBounds() : null);
      }
    });
  });

  renderSelectors();
  updateShortcodeSettingVisibility();
  updateShortcodeOutput();
  updatePreviewLegend();
  updatePreview();
}

function initEntryFormPage() {
  const locationInput = document.getElementById("ach-location");
  const latInput = document.getElementById("ach-lat");
  const lngInput = document.getElementById("ach-lng");
  const geoBtn = document.getElementById("ach-geo-btn");
  const mapContainer = document.getElementById("ach-map");
  const countrySelect = document.getElementById("ach-entry-country");
  const scopeSelect = document.getElementById("ach-entry-scope");
  const areaSelect = document.getElementById("ach-entry-area");
  const areaLabel = document.getElementById("ach-entry-area-label");
  const subdivisionWrap = document.getElementById("ach-entry-subdivision-wrap");
  const subdivisionSelect = document.getElementById("ach-entry-subdivision");
  const subdivisionLabel = document.getElementById("ach-entry-subdivision-label");
  const boundaryTitle = document.getElementById("ach-entry-boundary-title");

  initMediaPicker();

  if (!mapContainer || !latInput || !lngInput) {
    return;
  }

  if (typeof L === "undefined") {
    console.warn("Leaflet not loaded in admin.");
    return;
  }

  if (geoBtn && locationInput) {
    let lastGeocodedAt = 0;
    const GEOCODE_MIN_INTERVAL_MS = 1100;

    geoBtn.addEventListener("click", async () => {
      const address = locationInput.value.trim();
      if (!address) {
        alert("Please enter a Location first.");
        locationInput.focus();
        return;
      }

      const now = Date.now();
      const elapsed = now - lastGeocodedAt;
      if (elapsed < GEOCODE_MIN_INTERVAL_MS) {
        await new Promise((resolve) => setTimeout(resolve, GEOCODE_MIN_INTERVAL_MS - elapsed));
      }

      const originalText = geoBtn.textContent;
      geoBtn.disabled = true;
      geoBtn.textContent = "Looking up…";

      try {
        const url =
          "https://nominatim.openstreetmap.org/search?format=json&limit=1&q=" +
          encodeURIComponent(address);

        const res = await fetch(url, {
          headers: {
            Accept: "application/json",
            "User-Agent": "AustralianBoundaryMap/1.3.0 (WordPress plugin; https://betomxxx.com/plugins/australian-boundary-map)",
          },
        });

        lastGeocodedAt = Date.now();

        if (!res.ok) throw new Error("HTTP " + res.status);

        const data = await res.json();
        if (!data || !data.length) {
          alert("Address not found. Try being more specific.");
        } else {
          const place = data[0];
          latInput.value = place.lat;
          lngInput.value = place.lon;

          bumpHighlight(latInput, lngInput);

          const lat = parseFloat(place.lat);
          const lng = parseFloat(place.lon);
          if (!isNaN(lat) && !isNaN(lng) && window.achMap && window.achMarker) {
            window.achMarker.setLatLng([lat, lng]);
            window.achMap.setView([lat, lng], 16);
          }
        }
      } catch (err) {
        console.error("Geocoding error:", err);
        alert("There was a problem looking up that address.");
      } finally {
        geoBtn.disabled = false;
        geoBtn.textContent = originalText;
      }
    });
  }

  const DEFAULT_CENTER = [-33.6, 151.1];
  const DEFAULT_ZOOM = 12;

  let startLat = parseFloat(latInput.value);
  let startLng = parseFloat(lngInput.value);

  if (isNaN(startLat) || isNaN(startLng)) {
    startLat = DEFAULT_CENTER[0];
    startLng = DEFAULT_CENTER[1];
  }

  const map = L.map(mapContainer).setView([startLat, startLng], DEFAULT_ZOOM);
  window.achMap = map;

  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
  }).addTo(map);

  const marker = L.marker([startLat, startLng], {
    draggable: true,
  }).addTo(map);
  window.achMarker = marker;

  const hasBoundaryControls =
    !!countrySelect &&
    !!scopeSelect &&
    !!areaSelect &&
    !!areaLabel &&
    !!subdivisionWrap &&
    !!subdivisionSelect &&
    !!subdivisionLabel;

  const config = getAdminGeographyConfig();
  let selection = ensureValidSelection(config, getAdminGeographySelection());
  let boundaryLayer = null;
  let previewRequestId = 0;

  const setBoundarySummary = (title) => {
    if (!boundaryTitle) return;
    boundaryTitle.textContent = title ? `Current boundary: ${title}` : "Current boundary: Australia";
  };

  const renderEntrySelectors = () => {
    if (!hasBoundaryControls) {
      return;
    }

    if (countrySelect) {
      countrySelect.value = "australia";
    }

    setSelectOptions(
      scopeSelect,
      config.scopes || [],
      selection.scope,
      "No types configured",
      "Whole of Australia"
    );

    const scope = getScopeById(config, selection.scope);
    const areas = scope && Array.isArray(scope.children) ? scope.children : [];

    areaLabel.textContent = scope ? scope.groupLabel || "State or Territory" : "State or Territory";
    setSelectOptions(
      areaSelect,
      areas,
      selection.area,
      "No boundaries configured",
      scope ? "All states and territories" : ""
    );

    const area = getChildById(areas, selection.area);
    const subdivisions = area && Array.isArray(area.children) ? area.children : [];

    if (subdivisions.length) {
      subdivisionWrap.hidden = false;
      subdivisionLabel.textContent = scope ? scope.itemLabel || "Region / Division" : "Region / Division";
      setSelectOptions(
        subdivisionSelect,
        subdivisions,
        selection.subdivision,
        "No subdivisions configured",
        "Whole state or territory"
      );
    } else {
      subdivisionWrap.hidden = true;
      subdivisionSelect.innerHTML = "";
      subdivisionSelect.disabled = true;
    }
  };

  const updateBoundaryOverlay = async ({ fitToBoundary = false } = {}) => {
    previewRequestId += 1;
    const requestId = previewRequestId;
    const preview = getPreviewNodes(config, selection);

    setBoundarySummary(preview.title);

    if (boundaryLayer) {
      map.removeLayer(boundaryLayer);
      boundaryLayer = null;
    }

    if (!preview.nodes.length) {
      return;
    }

    try {
      const boundaryItems = await Promise.all(
        preview.nodes.map(async (node) => {
          const url = buildBoundaryUrl(node);
          if (!url) {
            return null;
          }

          const response = await fetch(url, { cache: "no-cache" });
          if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
          }

          const geojson = await response.json();
          return { node, geojson };
        })
      );

      if (requestId !== previewRequestId) {
        return;
      }

      const mergedGeoJSON = combineGeoJSONPayloads(boundaryItems.filter(Boolean));
      if (!mergedGeoJSON.features.length) {
        return;
      }

      boundaryLayer = L.geoJSON(mergedGeoJSON, {
        style: {
          color: "#FBFF00",
          weight: 2,
          fillColor: "#FBFF00",
          fillOpacity: 0.25,
        },
        onEachFeature: (feature, layer) => {
          const props = feature && feature.properties ? feature.properties : {};
          const boundaryName =
            props.__achBoundaryName ||
            props.name ||
            props.Elect_div ||
            props.Sortname ||
            "Boundary";
          layer.bindPopup(`<strong>${boundaryName}</strong>`);
        },
      }).addTo(map);

      if (fitToBoundary) {
        const bounds = boundaryLayer.getBounds();
        if (bounds.isValid()) {
          map.fitBounds(bounds, { padding: [24, 24] });
        }
      }
    } catch (err) {
      console.error("Failed to load entry boundary:", err);
    }
  };

  if (hasBoundaryControls) {
    renderEntrySelectors();

    scopeSelect.addEventListener("change", async () => {
      selection.scope = scopeSelect.value;
      selection.area = "";
      selection.subdivision = "";
      selection = ensureValidSelection(config, selection);
      renderEntrySelectors();
      await updateBoundaryOverlay({ fitToBoundary: true });
    });

    areaSelect.addEventListener("change", async () => {
      selection.area = areaSelect.value;
      selection.subdivision = "";
      selection = ensureValidSelection(config, selection);
      renderEntrySelectors();
      await updateBoundaryOverlay({ fitToBoundary: true });
    });

    subdivisionSelect.addEventListener("change", async () => {
      selection.subdivision = subdivisionSelect.value;
      selection = ensureValidSelection(config, selection);
      renderEntrySelectors();
      await updateBoundaryOverlay({ fitToBoundary: true });
    });

    updateBoundaryOverlay({ fitToBoundary: !latInput.value || !lngInput.value });
  } else if (window.BOUNDARY_MAP_ADMIN && BOUNDARY_MAP_ADMIN.regionUrl) {
    fetch(BOUNDARY_MAP_ADMIN.regionUrl)
      .then((res) => {
        if (!res.ok) throw new Error("HTTP " + res.status);
        return res.json();
      })
      .then((geojson) => {
        boundaryLayer = L.geoJSON(geojson, {
          style: {
            color: "#FBFF00",
            weight: 2,
            fillColor: "#FBFF00",
            fillOpacity: 0.3,
          },
        }).addTo(map);

        if (!latInput.value || !lngInput.value) {
          const bounds = boundaryLayer.getBounds();
          map.fitBounds(bounds);
        }
      })
      .catch((err) => {
        console.error("Failed to load admin boundary:", err);
      });
  }

  marker.on("dragend", () => {
    const pos = marker.getLatLng();
    latInput.value = pos.lat.toFixed(6);
    lngInput.value = pos.lng.toFixed(6);
    bumpHighlight(latInput, lngInput);
  });

  map.on("click", (e) => {
    marker.setLatLng(e.latlng);
    latInput.value = e.latlng.lat.toFixed(6);
    lngInput.value = e.latlng.lng.toFixed(6);
    bumpHighlight(latInput, lngInput);
  });

  const syncMarkerFromInputs = () => {
    const lat = parseFloat(latInput.value);
    const lng = parseFloat(lngInput.value);
    if (isNaN(lat) || isNaN(lng)) return;
    const ll = [lat, lng];
    marker.setLatLng(ll);
    map.setView(ll, map.getZoom());
  };

  latInput.addEventListener("change", syncMarkerFromInputs);
  lngInput.addEventListener("change", syncMarkerFromInputs);
}

function bumpHighlight(...inputs) {
  inputs.forEach((el) => {
    if (!el) return;
    el.classList.add("ach-geo-filled");
    setTimeout(() => el.classList.remove("ach-geo-filled"), 1200);
  });
}

function initMediaPicker() {
  const imageInput = document.getElementById("ach-image");
  const imageBtn = document.getElementById("ach-image-btn");
  const imagePreview = document.getElementById("ach-image-preview");

  if (!(imageBtn && imageInput && typeof wp !== "undefined" && wp.media)) {
    return;
  }

  let fileFrame = null;

  imageBtn.addEventListener("click", (e) => {
    e.preventDefault();

    if (fileFrame) {
      fileFrame.open();
      return;
    }

    fileFrame = wp.media({
      title: "Select or Upload Image",
      button: { text: "Use this image" },
      multiple: false,
    });

    fileFrame.on("select", () => {
      const attachment = fileFrame.state().get("selection").first().toJSON();
      imageInput.value = attachment.url;

      if (imagePreview) {
        imagePreview.innerHTML = `
          <img src="${attachment.url}" alt=""
               style="max-width:200px;height:auto;display:block;border:1px solid #ddd;padding:2px;" />
        `;
      }
    });

    fileFrame.open();
  });
}
