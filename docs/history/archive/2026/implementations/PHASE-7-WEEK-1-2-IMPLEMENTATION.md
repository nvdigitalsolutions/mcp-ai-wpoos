# Phase 7 Week 1-2: Chart.js Integration Implementation

**Date**: 2025-11-12  
**Phase**: Token Manager Phase 7 - Advanced Analytics & Visualization  
**Week**: 1-2 (Chart.js Integration)  
**Status**: ✅ Core Data Integration Complete | 🔨 UI Integration In Progress

## Executive Summary

The foundational work for Phase 7's Chart.js integration has been completed. All data-gathering methods now pull real usage data from the WP_MCP_AI_Tool_Token_Limits tracking system. The next step is to integrate these charts into the Token Manager admin UI and test the complete flow.

## What Was Completed

### 1. Real Data Integration ✅

#### Chart.js Helper (`class-wp-mcp-ai-chart-js-helper.php`)

**Method: `get_usage_trend_data()`**
- ✅ Pulls real daily usage data from `WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage()`
- ✅ Supports filtering by:
  - `user_id`: Specific user or all users (default: all)
  - `tool_slug`: Specific tool or all tools (default: all)
  - `days`: Time range 1-90 days (default: 7)
- ✅ Generates Chart.js compatible data structure
- ✅ Aggregates across multiple users and tools
- ✅ Maps usage to specific dates in the range

**Method: `get_tier_distribution_data()`**
- ✅ Uses `WP_MCP_AI_Tool_Token_Limits::get_user_tier()` for accurate tier detection
- ✅ Handles role-based tier assignment
- ✅ Accounts for custom tier overrides
- ✅ Falls back to 'free' tier for unknown tiers
- ✅ Returns counts for Free/Pro/Enterprise tiers

**Method: `get_tool_breakdown_data()` (NEW)**
- ✅ Shows token usage distribution across different tools
- ✅ Supports top N tools limit (default: 10)
- ✅ Aggregates across users or single user
- ✅ Converts tool slugs to human-readable names
- ✅ Sorts by usage descending
- ✅ Returns Chart.js compatible data

#### Analytics Dashboard (`class-wp-mcp-ai-analytics-dashboard.php`)

**Method: `get_usage_forecast_data()`**
- ✅ Connects to existing `WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion()`
- ✅ Calculates site-wide usage trends
- ✅ Determines trend direction (increasing/decreasing/stable)
- ✅ Computes average confidence from forecasts
- ✅ Compares projected vs. current usage

### 2. AJAX Infrastructure ✅

#### New AJAX Handler (`class-wp-mcp-ai-admin-ajax-handlers.php`)

**Method: `handle_get_tool_breakdown()`**
- ✅ Nonce verification using 'wp_mcp_ai_token_charts'
- ✅ Capability check (requires `manage_options`)
- ✅ Accepts parameters: user_id, days, limit
- ✅ Returns JSON response with tool breakdown data

**Existing Handlers Enhanced**
- ✅ `handle_get_usage_trend()` - Uses updated data method
- ✅ `handle_get_tier_distribution()` - Uses updated data method

#### AJAX Action Registration (`class-wp-mcp-ai-settings-dashboard.php`)

- ✅ `wp_ajax_wp_mcp_ai_get_usage_trend`
- ✅ `wp_ajax_wp_mcp_ai_get_tier_distribution`
- ✅ `wp_ajax_wp_mcp_ai_get_tool_breakdown` (NEW)

### 3. File Structure Verified ✅

```
includes/admin/
├── class-wp-mcp-ai-chart-js-helper.php      ✅ Enhanced with real data
├── class-wp-mcp-ai-analytics-dashboard.php  ✅ Enhanced with real forecasts
├── class-wp-mcp-ai-admin-ajax-handlers.php  ✅ New tool breakdown handler
├── class-wp-mcp-ai-settings-dashboard.php   ✅ AJAX actions registered
└── widgets/
    ├── token-usage-overview.php             ✅ Template ready
    ├── cost-breakdown.php                   ✅ Template ready
    └── usage-forecast.php                   ✅ Template ready

assets/js/
├── vendor/chart.min.js                      ✅ Chart.js v4.4.1
├── token-manager-charts.js                  ✅ Main integration script
└── analytics-dashboard.js                   ✅ Dashboard interactions

assets/css/
└── analytics-dashboard.css                  ✅ Widget styling
```

## Implementation Details

### Data Flow

```
User Request
    ↓
AJAX Handler (handle_get_usage_trend)
    ↓
Chart.js Helper (get_usage_trend_data)
    ↓
WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage()
    ↓
User Meta (_wp_mcp_ai_tool_token_usage)
    ↓
Data Structure:
    {
        'tool_slug': {
            'total_tokens': int,
            'requests': int,
            'daily': { 'YYYY-MM-DD': tokens },
            'hourly': { 'YYYY-MM-DD-HH': tokens }
        }
    }
    ↓
Aggregated & Formatted for Chart.js
    ↓
JSON Response to Client
    ↓
Chart.js Renders Visualization
```

### Chart Types Supported

1. **Line Chart**: Usage trends over time
   - Data source: `get_usage_trend_data()`
   - Displays: Daily token usage over 7-90 days
   - Filters: User, tool, time range

2. **Pie/Doughnut Chart**: Tier distribution
   - Data source: `get_tier_distribution_data()`
   - Displays: User counts per tier (Free/Pro/Enterprise)
   - Updated: Uses proper tier detection method

3. **Bar Chart**: Tool breakdown
   - Data source: `get_tool_breakdown_data()` (NEW)
   - Displays: Top N tools by token usage
   - Filters: User, time range, limit

4. **Gauge/Progress**: Forecast trends
   - Data source: `get_usage_forecast_data()`
   - Displays: Projected usage, confidence, trend direction
   - Shows: Increasing/decreasing/stable trends

## Testing Checklist

### Backend Tests ⏳

- [ ] Test `get_usage_trend_data()` with real user data
  - [ ] No users (empty result)
  - [ ] Single user with usage
  - [ ] Multiple users
  - [ ] Filter by specific tool
  - [ ] Different day ranges (7, 30, 90)

- [ ] Test `get_tier_distribution_data()`
  - [ ] All users in free tier
  - [ ] Mixed tiers
  - [ ] Users with custom tier assignments
  - [ ] Users with expired tier assignments

- [ ] Test `get_tool_breakdown_data()`
  - [ ] Top 5 tools
  - [ ] Top 10 tools (default)
  - [ ] Specific user
  - [ ] All users

- [ ] Test `get_usage_forecast_data()`
  - [ ] No usage data
  - [ ] Increasing trend
  - [ ] Decreasing trend
  - [ ] Stable trend

### AJAX Tests ⏳

- [ ] Test `handle_get_usage_trend`
  - [ ] With valid nonce
  - [ ] Without nonce (should fail)
  - [ ] Non-admin user (should fail)
  - [ ] Different day parameters

- [ ] Test `handle_get_tier_distribution`
  - [ ] Valid request
  - [ ] Permission checks

- [ ] Test `handle_get_tool_breakdown`
  - [ ] Valid request
  - [ ] With user_id parameter
  - [ ] With days parameter
  - [ ] With limit parameter

### Frontend Tests ⏳

- [ ] Dashboard widgets render correctly
  - [ ] Usage overview widget
  - [ ] Cost breakdown widget
  - [ ] Usage forecast widget

- [ ] Charts display data
  - [ ] Usage trend line chart
  - [ ] Tier distribution pie chart
  - [ ] Tool breakdown bar chart

- [ ] Interactive features
  - [ ] Period selector (7d, 30d, 90d)
  - [ ] Chart tooltips
  - [ ] Legend toggles
  - [ ] Refresh data button
  - [ ] Export chart as PNG

### Integration Tests ⏳

- [ ] Test with no usage data
- [ ] Test with sample usage data
- [ ] Test with large datasets (100+ users)
- [ ] Test performance with 90-day range
- [ ] Test responsive design (mobile, tablet, desktop)

## What's Next: UI Integration

### Immediate Tasks

1. **Add Charts to Token Manager Page**
   - Add chart containers to Token Manager admin section
   - Wire up AJAX calls from existing JavaScript
   - Test chart rendering with real data

2. **Add Period Selector**
   ```php
   <select class="wp-mcp-ai-chart-period-select">
       <option value="7">Last 7 Days</option>
       <option value="30">Last 30 Days</option>
       <option value="90">Last 90 Days</option>
   </select>
   ```

3. **Implement Chart Export**
   - Use Chart.js `toBase64Image()` method
   - Create download link
   - Trigger client-side download

4. **Add Loading States**
   - Show spinner while fetching data
   - Handle empty data states
   - Display error messages gracefully

5. **Write Unit Tests**
   - Create `tests/test-chart-data.php`
   - Test all chart data methods
   - Test AJAX handlers
   - Verify data accuracy

### Token Manager Admin Page Integration

The Token Manager page currently shows tables. We need to add chart sections:

```php
// In class-wp-mcp-ai-section-token-manager.php

public function render() {
    ?>
    <!-- Existing tables... -->
    
    <!-- NEW: Analytics Charts Section -->
    <div class="wp-mcp-ai-analytics-section">
        <h2><?php esc_html_e( 'Usage Analytics', 'wp-mcp-ai' ); ?></h2>
        
        <!-- Usage Trend Chart -->
        <div class="wp-mcp-ai-chart-container">
            <canvas id="wp-mcp-ai-usage-trend-chart" width="800" height="300"></canvas>
        </div>
        
        <!-- Tool Breakdown & Tier Distribution -->
        <div class="wp-mcp-ai-chart-row">
            <div class="wp-mcp-ai-chart-half">
                <canvas id="wp-mcp-ai-tool-breakdown-chart" width="400" height="300"></canvas>
            </div>
            <div class="wp-mcp-ai-chart-half">
                <canvas id="wp-mcp-ai-tier-distribution-chart" width="400" height="300"></canvas>
            </div>
        </div>
    </div>
    <?php
}
```

### JavaScript Initialization

Update `token-manager-charts.js` to initialize charts on the Token Manager page:

```javascript
// Check if we're on the token manager page
if (document.getElementById('wp-mcp-ai-usage-trend-chart')) {
    TokenCharts.init();
}
```

## Known Issues & Considerations

### Performance

- ✅ Usage data is already cached per user
- ⚠️ Large datasets (100+ users, 90 days) may slow down chart generation
- **Solution**: Implement transient caching for chart data (1-hour TTL)

### Edge Cases Handled

- ✅ Users with no usage data
- ✅ Empty daily/hourly arrays
- ✅ Invalid date ranges
- ✅ Unknown tier assignments
- ✅ Non-existent tools

### Security

- ✅ Nonce verification on all AJAX requests
- ✅ Capability checks (requires `manage_options`)
- ✅ Input sanitization (user_id, days, limit)
- ✅ Data escaping in chart labels

## API Reference

### Chart Data Methods

```php
// Get usage trend data
$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array(
    'user_id'   => 0,      // 0 = all users
    'tool_slug' => '',     // Empty = all tools
    'days'      => 7,      // 1-90 days
) );

// Get tier distribution
$data = WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_data();

// Get tool breakdown
$data = WP_MCP_AI_Chart_JS_Helper::get_tool_breakdown_data( array(
    'user_id' => 0,        // 0 = all users
    'days'    => 7,        // Time range
    'limit'   => 10,       // Top N tools
) );
```

### AJAX Endpoints

```javascript
// Get usage trend
$.ajax({
    url: wpMcpAiChartData.ajaxUrl,
    type: 'POST',
    data: {
        action: 'wp_mcp_ai_get_usage_trend',
        nonce: wpMcpAiChartData.nonce,
        days: 7,
        user_id: 0,
        tool_slug: ''
    }
});

// Get tier distribution
$.ajax({
    url: wpMcpAiChartData.ajaxUrl,
    type: 'POST',
    data: {
        action: 'wp_mcp_ai_get_tier_distribution',
        nonce: wpMcpAiChartData.nonce
    }
});

// Get tool breakdown
$.ajax({
    url: wpMcpAiChartData.ajaxUrl,
    type: 'POST',
    data: {
        action: 'wp_mcp_ai_get_tool_breakdown',
        nonce: wpMcpAiChartData.nonce,
        user_id: 0,
        days: 7,
        limit: 10
    }
});
```

## Completion Criteria

Phase 7, Week 1-2 will be considered complete when:

- [x] All chart data methods pull real usage data
- [x] AJAX handlers are implemented and registered
- [ ] Charts are integrated into Token Manager admin page
- [ ] Charts render correctly with real data
- [ ] Period selector allows changing time ranges
- [ ] Chart export functionality works
- [ ] Unit tests pass for all chart data methods
- [ ] AJAX endpoints tested and working
- [ ] Dashboard widgets display correctly
- [ ] Responsive design verified on mobile/tablet/desktop

## Next Phase: Cost Attribution (Week 3-4)

Once Week 1-2 is complete, Phase 7 will move to Week 3-4: Cost Attribution & ROI Tracking

**Focus Areas**:
- Implement `WP_MCP_AI_Cost_Calculator` class
- Add provider-specific pricing models
- Track costs per user/tool/project
- Calculate ROI based on productivity metrics
- Create cost breakdown reports
- Add budget alerts

**Prerequisites**:
- Cost tracking requires knowing provider and model for each request
- May need to enhance usage recording to capture provider/model metadata
- Consider adding new database table for cost tracking (optional)

---

**Status**: ✅ Week 1-2 Data Layer Complete | 🔨 UI Integration Remaining  
**Next Step**: Integrate charts into Token Manager admin page  
**Blocked By**: None  
**Estimated Completion**: Week 1-2 UI integration can be completed in 1-2 days
