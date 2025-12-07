# Chart.js Integration for Token Manager

**Version:** 1.0  
**Created:** 2025-11-11  
**Status:** Phase 3 Preparation  
**Related:** TOKEN-MANAGER-ENHANCEMENT-PLAN.md (Phase 3)

## Overview

This document describes the Chart.js integration added to WP oOS as preparation for Phase 3 of the Token Manager Enhancement Plan. Chart.js provides the visualization foundation for implementing token usage analytics, tier distribution charts, and usage forecasting displays.

## Purpose

As identified in the Token Enhancement Applicability Verification, Chart.js is the **only external dependency** required for implementing Phase 3 (UI/UX Enhancements) of the Token Manager enhancement plan. This PR adds:

1. Chart.js library infrastructure
2. Helper class for chart management
3. JavaScript integration layer
4. Documentation and setup guides

## Files Added

### 1. Chart.js Library
**Location:** `assets/js/vendor/chart.min.js`  
**Type:** Placeholder (requires actual library download)  
**Version:** 4.4.1  
**License:** MIT

**Status:** Placeholder file created. Actual library must be downloaded separately.

**Why Placeholder?**
- Repository security: Avoid committing large minified libraries
- Version control: Easier to update independently
- Download options: Allows choice of CDN vs. local hosting

### 2. Chart.js Helper Class
**Location:** `includes/admin/class-wp-mcp-ai-chart-js-helper.php`  
**Purpose:** PHP integration layer for Chart.js

**Features:**
- Automatic enqueuing on Token Manager pages
- Chart configuration generators
- Data preparation methods (placeholders for Phase 3)
- WordPress integration (nonces, AJAX, permissions)

**Key Methods:**
```php
WP_MCP_AI_Chart_JS_Helper::init()                    // Initialize hooks
WP_MCP_AI_Chart_JS_Helper::enqueue_chart_js()       // Enqueue scripts
WP_MCP_AI_Chart_JS_Helper::get_usage_trend_config() // Chart config
WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_config() // Pie chart config
```

### 3. Token Manager Charts JavaScript
**Location:** `assets/js/token-manager-charts.js`  
**Purpose:** Client-side chart initialization and management

**Features:**
- Automatic chart initialization
- AJAX data loading (placeholders for Phase 3)
- Time period selection handling
- Chart refresh functionality

**Chart Types Prepared:**
- Usage Trend Chart (line chart)
- Tier Distribution Chart (pie chart)
- Extensible for additional charts

### 4. Documentation
**Location:** `assets/js/vendor/README.md`  
**Purpose:** Installation and usage guide

**Contents:**
- Three installation methods
- Usage examples
- Phase 3 implementation checklist
- Chart types reference

### 5. Main Plugin Integration
**Location:** `mcp-ai-wpoos.php` (line 247)  
**Change:** Added Chart.js helper class to includes

### 6. NPM Package Configuration
**Location:** `package.json`  
**Change:** Added Chart.js as a dependency with automatic installation

## Installation Instructions

### Method 1: NPM Install (Recommended)

Chart.js is now included in the project's `package.json` and will be automatically installed.

```bash
cd wp-content/plugins/wp-mcp-ai/
npm install
```

The `postinstall` script will automatically copy Chart.js to the correct location:
- Source: `node_modules/chart.js/dist/chart.umd.min.js`
- Destination: `assets/js/vendor/chart.min.js`

### Method 2: Manual Download

```bash
cd wp-content/plugins/wp-mcp-ai/assets/js/vendor/
curl -o chart.min.js https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js
```

**Verification:**
```bash
ls -lh assets/js/vendor/chart.min.js
# Should show ~250KB file
```

### Method 3: CDN (Development/Testing Only)
```

### Method 3: CDN (Development/Testing Only)

Edit `includes/admin/class-wp-mcp-ai-chart-js-helper.php` line 52-59:

```php
// Replace local file with CDN
wp_enqueue_script(
    'chartjs',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
    array(),
    '4.4.1',
    true
);
```

**Note:** CDN method not recommended for production due to:
- External dependency
- Privacy considerations
- Network reliability
- Version control

## Architecture

### Loading Sequence

1. **Plugin Load** (`mcp-ai-wpoos.php`)
   - Includes Chart.js helper class (line 247)
   - Helper class registers hooks

2. **Admin Page Load**
   - `admin_enqueue_scripts` hook fires
   - Helper checks if Token Manager page
   - Enqueues Chart.js library
   - Enqueues token-manager-charts.js (if exists)
   - Localizes script with AJAX config

3. **DOM Ready** (client-side)
   - token-manager-charts.js checks for Chart.js
   - Initializes chart instances
   - Loads data via AJAX (Phase 3)

### Conditional Loading

Chart.js only loads when:
- User is on admin page (`is_admin()`)
- Page is WP oOS settings page
- Tab is `token_manager` OR no tab specified (general settings)

This prevents unnecessary script loading on other admin pages.

### Data Flow (Phase 3)

```
Admin Page Request
    ↓
Chart.js Helper Enqueues Scripts
    ↓
Client Loads Charts
    ↓
AJAX Request for Data
    ↓
PHP Processes Token Usage Data
    ↓
Returns Chart.js Format
    ↓
JavaScript Renders Charts
```

## Integration Points

### For Phase 3 Implementation

When implementing Phase 3 of the Token Manager enhancement:

#### 1. Add Chart Canvas Elements

In `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php`:

```php
public function render() {
    ?>
    <div class="wp-mcp-ai-charts-container">
        <div class="wp-mcp-ai-chart-wrapper">
            <canvas id="wp-mcp-ai-usage-trend-chart"></canvas>
        </div>
        <div class="wp-mcp-ai-chart-wrapper">
            <canvas id="wp-mcp-ai-tier-distribution-chart"></canvas>
        </div>
    </div>
    <?php
}
```

#### 2. Implement AJAX Endpoints

Add to `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`:

```php
add_action( 'wp_ajax_wp_mcp_ai_get_usage_trend', array( $this, 'get_usage_trend' ) );
add_action( 'wp_ajax_wp_mcp_ai_get_tier_distribution', array( $this, 'get_tier_distribution' ) );
```

#### 3. Populate Data Methods

Expand placeholders in `class-wp-mcp-ai-chart-js-helper.php`:

```php
public static function get_usage_trend_data( $args = array() ) {
    // Query token usage from WP_MCP_AI_Tool_Token_Limits
    // Format for Chart.js
    // Return labels and datasets
}
```

#### 4. Complete JavaScript AJAX Calls

Uncomment and complete AJAX calls in `token-manager-charts.js`:

```javascript
loadUsageTrendData: function() {
    $.ajax({
        url: wpMcpAiChartData.ajaxUrl,
        type: 'POST',
        data: {
            action: 'wp_mcp_ai_get_usage_trend',
            nonce: wpMcpAiChartData.nonce,
            days: 7
        },
        success: function(response) {
            // Update chart
        }
    });
}
```

#### 5. Add CSS Styling

Create `assets/css/token-manager-charts.css`:

```css
.wp-mcp-ai-charts-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.wp-mcp-ai-chart-wrapper {
    background: #fff;
    padding: 20px;
    border: 1px solid #ddd;
    border-radius: 4px;
    min-height: 300px;
}
```

## Testing

### Verify Installation

1. **Check File Exists:**
   ```bash
   ls -lh assets/js/vendor/chart.min.js
   ```

2. **Check Plugin Loads Helper:**
   - Enable WP_DEBUG
   - Look for helper class initialization
   - No PHP errors on plugin activation

3. **Check Admin Enqueue:**
   - Navigate to WP oOS → Token Manager
   - Inspect page source
   - Verify Chart.js script tag exists

4. **Check JavaScript Console:**
   - Open browser console
   - Should see warning if placeholder file
   - Should see no errors if actual library loaded

### Test Chart Rendering (Phase 3)

```javascript
// In browser console, test Chart.js is available
console.log(typeof Chart);
// Should output: "function"

// Test chart creation
var ctx = document.createElement('canvas').getContext('2d');
var chart = new Chart(ctx, {type: 'line', data: {labels: [], datasets: []}});
console.log(chart);
// Should output: Chart object
```

## Security Considerations

### Input Validation

All AJAX endpoints must:
- Verify nonce: `wp_verify_nonce()`
- Check capabilities: `current_user_can( 'manage_options' )`
- Sanitize inputs: `sanitize_key()`, `absint()`

### Output Escaping

All chart data must be:
- JSON encoded: `wp_json_encode()`
- Escaped in HTML: `esc_js()`

### File Permissions

Chart.js file should be:
- World-readable (644)
- Not writable by web server
- Served with correct MIME type

## Performance

### Loading Impact

- **Chart.js Size:** ~250KB (minified)
- **Helper Class:** ~5KB
- **Integration JS:** ~5KB
- **Total Impact:** ~260KB (only on Token Manager page)

### Optimization Strategies

1. **Conditional Loading:** Only loads on relevant pages
2. **Minification:** Use .min.js version
3. **Caching:** Versioned with `filemtime()`
4. **Async Loading:** Scripts in footer (`true` parameter)

### Browser Compatibility

Chart.js 4.x supports:
- Chrome 70+
- Firefox 60+
- Safari 12+
- Edge 79+

## Troubleshooting

### Chart.js Not Loading

**Symptom:** Console error "Chart is not defined"

**Solutions:**
1. Download actual library (replace placeholder)
2. Check file permissions (644)
3. Verify file path in enqueue call
4. Check for JavaScript errors blocking load

### Charts Not Rendering

**Symptom:** Canvas exists but no chart displayed

**Solutions:**
1. Verify Chart.js loaded (`typeof Chart`)
2. Check canvas ID matches JavaScript
3. Inspect data format (must match Chart.js spec)
4. Check for JavaScript console errors

### AJAX Errors (Phase 3)

**Symptom:** Charts show no data

**Solutions:**
1. Verify nonce is valid
2. Check AJAX action registered
3. Inspect network tab for 400/500 errors
4. Enable WP_DEBUG and check error log

## Related Documentation

- **Main Enhancement Plan:** `docs/TOKEN-MANAGER-ENHANCEMENT-PLAN.md`
- **Applicability Verification:** `docs/TOKEN-ENHANCEMENT-APPLICABILITY-VERIFICATION.md`
- **Quick Reference:** `docs/QUICK-REFERENCE-TOKEN-ENHANCEMENTS.md`
- **Chart.js Official Docs:** https://www.chartjs.org/docs/latest/

## Future Enhancements

Planned for future phases:

1. **Additional Chart Types**
   - Bar chart for top consumers
   - Stacked area chart for cumulative usage
   - Radar chart for tool comparison

2. **Interactive Features**
   - Click-to-drill-down
   - Time period selector
   - Chart export (PNG/SVG)
   - Real-time updates via WebSocket

3. **Advanced Analytics**
   - Trend prediction lines
   - Anomaly highlighting
   - Comparative analytics
   - Cost attribution visualization

## Changelog

### Version 1.0 (2025-11-11)
- Initial Chart.js integration
- Added helper class
- Created JavaScript integration layer
- Added comprehensive documentation
- Prepared for Phase 3 implementation

## License

- **Chart.js:** MIT License
- **WP oOS Integration:** GPLv3 or later

---

**Document Version:** 1.0  
**Last Updated:** 2025-11-11  
**Maintainer:** WP oOS Development Team  
**Status:** Phase 3 Preparation Complete
