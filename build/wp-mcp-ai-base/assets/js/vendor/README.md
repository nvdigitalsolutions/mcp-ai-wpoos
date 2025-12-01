# Chart.js Integration for Token Manager

This directory contains Chart.js integration for the WP oOS Token Manager enhancement (Phase 3).

## Files

### Library File
- **chart.min.js** - Chart.js v4.4.1 UMD bundle (placeholder)

### Installation

#### Option 1: NPM Install (Recommended)
Chart.js is now included in `package.json` and will be automatically installed and copied to the vendor directory.

```bash
# Install all dependencies (including Chart.js)
npm install

# Chart.js will be automatically copied to assets/js/vendor/chart.min.js via postinstall script
```

#### Option 2: Manual Download
```bash
# Download Chart.js v4.4.1
cd assets/js/vendor/
curl -o chart.min.js https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js
```

#### Option 3: Use CDN (Development Only)
For development/testing, you can modify the enqueue function in 
`includes/admin/class-wp-mcp-ai-chart-js-helper.php` to use the CDN:

```php
wp_enqueue_script(
    'chartjs',
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
    array(),
    '4.4.1',
    true
);
```

## Usage

The Chart.js library is automatically enqueued on the Token Manager admin page.

### PHP Integration

```php
// The helper class handles automatic enqueuing
WP_MCP_AI_Chart_JS_Helper::enqueue_chart_js();

// Get chart configuration
$config = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_config();
```

### JavaScript Integration

```javascript
// Charts are initialized automatically when DOM is ready
// Token usage trend chart
var ctx = document.getElementById('wp-mcp-ai-usage-trend-chart').getContext('2d');
var chart = new Chart(ctx, config);

// Tier distribution pie chart  
var ctx2 = document.getElementById('wp-mcp-ai-tier-distribution-chart').getContext('2d');
var pieChart = new Chart(ctx2, pieConfig);
```

## Phase 3 Implementation Checklist

When implementing Phase 3 of the Token Manager enhancement:

- [ ] Replace placeholder chart.min.js with actual Chart.js library
- [ ] Implement AJAX endpoints for chart data:
  - `wp_ajax_wp_mcp_ai_get_usage_trend`
  - `wp_ajax_wp_mcp_ai_get_tier_distribution`
- [ ] Add chart canvas elements to Token Manager admin page
- [ ] Expand `WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data()`
- [ ] Expand `WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_data()`
- [ ] Complete token-manager-charts.js AJAX calls
- [ ] Add CSS styling for chart containers
- [ ] Add time period selector UI
- [ ] Test chart responsiveness

## Chart Types Planned

### 1. Usage Trend Chart (Line Chart)
- X-axis: Date (last 7, 30, or 90 days)
- Y-axis: Token count
- Multiple datasets for different tools or users

### 2. Tier Distribution Chart (Pie Chart)
- Shows distribution of users across Free, Pro, Enterprise tiers
- Color-coded segments

### 3. Top Consumers Chart (Bar Chart - Future)
- Shows top 10 users by token consumption
- Sortable and filterable

### 4. Peak Usage Times (Bar Chart - Future)
- Shows hourly usage patterns
- Helps identify peak load times

## Resources

- [Chart.js Documentation](https://www.chartjs.org/docs/latest/)
- [Chart.js GitHub](https://github.com/chartjs/Chart.js)
- [Token Manager Enhancement Plan](../../docs/TOKEN-MANAGER-ENHANCEMENT-PLAN.md)

## License

Chart.js is released under the MIT License.
WP oOS integration code is licensed under GPLv3 or later.

## Version

- Chart.js: v4.4.1
- Last Updated: 2025-11-11
