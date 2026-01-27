# CDN Cache Column Implementation

## Overview

Added a new "CDN Cache" column to the NPM package dependency tables on the Pro settings page to show which packages are available via CDN caching (jsdelivr/unpkg).

## Location

**Pro Settings Page**: `wp-admin/admin.php?page=nvoos-pro-settings`

## Implementation Details

### New Method: `get_package_cdn_info()`

Located in: `includes/admin/class-wp-mcp-ai-pro-settings.php`

```php
private static function get_package_cdn_info( $package ) {
    $cdn_info = array();
    
    // Searches for package.json in:
    // 1. Pro vendor directory (production)
    // 2. Pro node_modules (development)
    // 3. Base vendor directory
    // 4. Base node_modules
    
    // Extracts 'jsdelivr' and 'unpkg' fields from package.json
    
    return $cdn_info; // Array of CDN name => URL pairs
}
```

**Priority Order**:
1. `addons/pro/assets/vendor/{package}/package.json`
2. `addons/pro/node_modules/{package}/package.json`
3. `assets/js/vendor/{package}/package.json`
4. `node_modules/{package}/package.json`

### Updated Method: `render_packages_table()`

**Changes**:
1. Added 4th column header: "CDN Cache"
2. Adjusted column widths:
   - Package Name: 40% → 35%
   - Version: 20% → 15%
   - Status: (no width) → 20%
   - CDN Cache: → 30% (new)
3. Added CDN info display logic in table rows

**Display Logic**:
```php
if ( ! empty( $cdn_info ) ) {
    // Shows CDN name (JSDELIVR/UNPKG) and filename
    foreach ( $cdn_info as $cdn_name => $cdn_url ) {
        // Display: JSDELIVR: chart.umd.min.js
    }
} else {
    // Shows: N/A
}
```

## Example Output

### Table Headers
| Package Name (35%) | Version (15%) | Status (20%) | CDN Cache (30%) |
|--------------------|---------------|--------------|-----------------|

### Sample Rows

**chart.js** (with CDN info):
```
Package Name: chart.js
Version: ^4.4.7
Status: [Installed]
CDN Cache: 
  JSDELIVR: chart.umd.min.js
  UNPKG: chart.umd.min.js
```

**axios** (with CDN info):
```
Package Name: axios
Version: ^1.6.5
Status: [Installed]
CDN Cache:
  JSDELIVR: axios.min.js
  UNPKG: axios.min.js
```

**@microsoft/fetch-event-source** (bundled, no CDN):
```
Package Name: @microsoft/fetch-event-source
Version: ^2.0.1
Status: [Installed]
CDN Cache: N/A
```

## Benefits

1. **Visibility**: Users can see which packages have CDN alternatives
2. **Performance**: Helps identify packages that could be loaded from CDNs for better caching
3. **Deployment**: Shows CDN availability for packages in production environments
4. **Documentation**: Provides insights into package distribution methods

## Notes

- CDN information is read directly from package.json metadata
- Only shows jsdelivr and unpkg fields (most common CDNs for npm packages)
- Displays filename only (not full URL) to save space
- Falls back to "N/A" if no CDN information is available
- Works with both vendor directories (production) and node_modules (development)

## WordPress.org Compliance

This feature complies with WordPress.org plugin guidelines:
- Does NOT load packages from CDNs at runtime
- Only READS CDN information from package.json for display purposes
- All packages remain bundled locally as required
- CDN info is informational only

## Related Files

- `includes/admin/class-wp-mcp-ai-pro-settings.php` - Main implementation
- `addons/pro/package.json` - Pro addon dependencies
- `package.json` - Base plugin dependencies
- `docs/THIRD_PARTY_ASSETS.md` - Third-party asset documentation
- `docs/DEPENDENCIES_BUNDLING.md` - Dependency management guide

## Testing

To test this feature:

1. Navigate to `wp-admin/admin.php?page=nvoos-pro-settings`
2. Scroll to the "NPM Package Information" section
3. Check the "Production Dependencies" and "Development Dependencies" tables
4. Verify the "CDN Cache" column shows:
   - Package CDN availability (jsdelivr/unpkg with filenames)
   - "N/A" for packages without CDN info

### Expected Packages with CDN Info

From Pro addon:
- chart.js
- d3
- axios
- mathjs
- prettier
- katex
- @turf/turf (as turf)

From base plugin:
- chart.js (also in Pro)

## Implementation Date

January 27, 2026

## Pull Request

Branch: `copilot/update-list-show-dependencies`
