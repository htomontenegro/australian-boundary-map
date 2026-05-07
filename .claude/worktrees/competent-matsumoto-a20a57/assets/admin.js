// assets/admin.js
document.addEventListener("DOMContentLoaded", () => {

     jQuery(function ($) {
        // Colour picker for category colour field(s)
        if ($('.ach-color-field').length) {
            $('.ach-color-field').wpColorPicker();
        }

        // …leave your existing mini-map / media-upload JS here…
    });

    
    const locationInput = document.getElementById("ach-location");
    const latInput = document.getElementById("ach-lat");
    const lngInput = document.getElementById("ach-lng");
    const geoBtn = document.getElementById("ach-geo-btn");
    const mapContainer = document.getElementById("ach-map");

    // If we're not on the Achievements screen, bail
    if (!mapContainer || !latInput || !lngInput) {
        return;
    }

    // -----------------------------
    // 1) Geocode from Location -> Lat/Lng
    // -----------------------------
    if (geoBtn && locationInput) {
        geoBtn.addEventListener("click", async () => {
            const address = locationInput.value.trim();
            if (!address) {
                alert("Please enter a Location first.");
                locationInput.focus();
                return;
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
                        Accept: "application/json"
                    }
                });

                if (!res.ok) throw new Error("HTTP " + res.status);

                const data = await res.json();
                if (!data || !data.length) {
                    alert("Address not found. Try being more specific.");
                } else {
                    const place = data[0];
                    latInput.value = place.lat;
                    lngInput.value = place.lon;

                    bumpHighlight(latInput, lngInput);

                    // Also update the map marker if map already exists
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

    function bumpHighlight(...inputs) {
        inputs.forEach((el) => {
            if (!el) return;
            el.classList.add("ach-geo-filled");
            setTimeout(() => el.classList.remove("ach-geo-filled"), 1200);
        });
    }

    // -----------------------------
    // 2) Mini Leaflet map in admin
    // -----------------------------
    if (typeof L === "undefined") {
        console.warn("Leaflet not loaded in admin.");
        return;
    }

    // Default center (e.g. Berowra)
    const DEFAULT_CENTER = [-33.6, 151.1];
    const DEFAULT_ZOOM = 12;

    let startLat = parseFloat(latInput.value);
    let startLng = parseFloat(lngInput.value);

    if (isNaN(startLat) || isNaN(startLng)) {
        startLat = DEFAULT_CENTER[0];
        startLng = DEFAULT_CENTER[1];
    }

    const map = L.map(mapContainer).setView([startLat, startLng], DEFAULT_ZOOM);
    window.achMap = map; // so geocoder can access

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        maxZoom: 19
    }).addTo(map);

    const marker = L.marker([startLat, startLng], {
        draggable: true
    }).addTo(map);
    window.achMarker = marker;

    // -----------------------------
    // 2b) Load the Berowra boundary (same shape as public map)
    // -----------------------------
    if (window.ACH_MAP_ADMIN && ACH_MAP_ADMIN.regionUrl) {
        fetch(ACH_MAP_ADMIN.regionUrl)
            .then((res) => {
                if (!res.ok) throw new Error("HTTP " + res.status);
                return res.json();
            })
            .then((geojson) => {
                const boundaryLayer = L.geoJSON(geojson, {
                    style: {
                        color: "#FBFF00",      // outline
                        weight: 2,
                        fillColor: "#FBFF00",  // fill
                        fillOpacity: 0.3
                    }
                }).addTo(map);

                // If no coordinates yet, fit map to the boundary bounds
                if (!latInput.value || !lngInput.value) {
                    const bounds = boundaryLayer.getBounds();
                    map.fitBounds(bounds);
                }
            })
            .catch((err) => {
                console.error("Failed to load admin boundary:", err);
            });
    }

    // Marker drag -> update inputs
    marker.on("dragend", () => {
        const pos = marker.getLatLng();
        latInput.value = pos.lat.toFixed(6);
        lngInput.value = pos.lng.toFixed(6);
        bumpHighlight(latInput, lngInput);
    });

    // Map click -> move marker + update inputs
    map.on("click", (e) => {
        marker.setLatLng(e.latlng);
        latInput.value = e.latlng.lat.toFixed(6);
        lngInput.value = e.latlng.lng.toFixed(6);
        bumpHighlight(latInput, lngInput);
    });

    // Input change -> move marker
    function syncMarkerFromInputs() {
        const lat = parseFloat(latInput.value);
        const lng = parseFloat(lngInput.value);
        if (isNaN(lat) || isNaN(lng)) return;
        const ll = [lat, lng];
        marker.setLatLng(ll);
        map.setView(ll, map.getZoom());
    }

    latInput.addEventListener("change", syncMarkerFromInputs);
    lngInput.addEventListener("change", syncMarkerFromInputs);

    // -----------------------------
    // 3) Media Library image picker
    // -----------------------------
    const imageInput = document.getElementById("ach-image");
    const imageBtn = document.getElementById("ach-image-btn");
    const imagePreview = document.getElementById("ach-image-preview");

    if (imageBtn && imageInput && typeof wp !== "undefined" && wp.media) {
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
                multiple: false
            });

            fileFrame.on("select", () => {
                const attachment = fileFrame.state().get("selection").first().toJSON();
                imageInput.value = attachment.url;

                // Update preview
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

   


});