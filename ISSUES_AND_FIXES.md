# Project Audit – Issues Found & Fixes Applied

## Fixed Issues

### 1. Edit form `entry_id=0` bug (Critical)
**Problem:** When editing the first entry in JSON mode (index 0), the hidden input `entry_id` used `$edit_id ? esc_attr($edit_id) : ''`, which treats `0` as falsy and output an empty value. Form submission then performed an INSERT instead of an UPDATE.

**Fix:** Changed to `($edit_id !== null && $edit_id !== '') ? esc_attr($edit_id) : ''` so that `0` is preserved.

### 2. Migration duplicates on re-activation (Critical)
**Problem:** Re-activating the plugin re-ran `migrate_from_json()`, which INSERTed all entries again without checking if they already existed, causing duplicates.

**Fix:** Added an early return at the start of `migrate_from_json()` when `ach_map_migrated_to_db` is already set.

### 3. Categories table colspan (UI)
**Problem:** The empty-state row in the Categories table used `colspan="4"` but the table has 6 columns, causing misaligned layout.

**Fix:** Changed to `colspan="6"`.

### 4. Status validation in DB methods (Security)
**Problem:** `update_entry_status()` and `bulk_update_status()` did not validate the `$status` parameter, allowing invalid values.

**Fix:** Added validation to only allow `'publish'` or `'trash'`, and sanitized the value.

---

## Remaining / Minor Issues

### 5. Potential XSS in frontend script (Low–Medium)
**Location:** `assets/script.js` – `renderEntryList()`, `showEntryDetails()`, `drawEntryMarkers()`

**Issue:** User-entered data (title, location, description, category) is inserted via `innerHTML` without escaping. If an admin enters HTML/script in a field, it could execute.

**Recommendation:** Add an `escapeHtml()` helper and use it for all dynamic content, or use `textContent` where appropriate. Data is admin-entered so risk is limited.

### 6. Duplicate entries with same title (Low)
**Location:** `assets/script.js` – `markersByTitle.set(ev.title, ...)`

**Issue:** If two entries share the same title, the second overwrites the first in the Map. Marker highlighting and click behavior may be wrong.

**Recommendation:** Use a unique key (e.g. `id` or `title + index`) instead of `title` only.

### 7. `delete_category()` unused
**Location:** `includes/class-entries-map-database.php`

**Issue:** `delete_category()` is never called. Categories admin uses index-based logic and `save_categories()` (replace-all). The method is effectively dead code.

**Recommendation:** Remove or wire it up if single-category delete is needed.

### 8. Indentation inconsistency
**Location:** `entries-map.php` – `enqueue_front_for_shortcode()`, `entries_map()`, `entries_map_shape()`, `get_body_html()`

**Issue:** These methods have inconsistent indentation compared to the rest of the class.

**Recommendation:** Normalize indentation for consistency.

### 9. `page_has_shortcode()` on archive/404
**Location:** `entries-map.php` – `page_has_shortcode()`

**Issue:** On archives, 404, or non-post pages, `get_queried_object()` may not have `post_content`. The `isset($post->post_content)` check handles this, but behaviour on non-standard templates may vary.

**Status:** Current logic appears adequate; no change made.

### 10. Redundant/duplicate category click handler
**Location:** `assets/script.js` – end of file

**Issue:** Both `initCategoryTabs()` (which adds a delegated click handler on the category list) and a global `document.addEventListener("click", ...)` handle category clicks. The global handler does not call `applySearchAndCategory()`, so it only toggles the active class. The `initCategoryTabs` handler does the full work. The global block could be removed or merged.

### 11. Geocoding external API
**Location:** `assets/admin.js` – Nominatim OpenStreetMap

**Issue:** Geocoding calls an external API with no rate limiting or error-handling for quotas. Nominatim has usage limits.

**Recommendation:** Consider caching results or using a WordPress-ready geocoding solution.

---

## Summary

| Severity | Fixed | Remaining |
|----------|-------|-----------|
| Critical | 2 | 0 |
| Medium   | 2 | 1 (XSS) |
| Low      | 0 | 5 |

The plugin is in good shape. The most important issues have been addressed.
