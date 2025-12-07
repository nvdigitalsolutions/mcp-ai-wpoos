# Quick Reference: Phase 7 - Advanced Analytics & Visualization

**Version:** 1.0  
**Status:** Planning Phase  
**Target:** WP oOS v1.2.0

## Overview

Phase 7 builds on the token management foundation (Phases 1-6) by adding advanced analytics, visualization, and intelligence features.

## Key Features at a Glance

| Feature | Description | Benefit |
|---------|-------------|---------|
| **Chart.js Dashboards** | Interactive charts and graphs | Visual insights at a glance |
| **Cost Attribution** | Track costs by user/tool/project | Budget management |
| **Trend Analysis** | Identify usage patterns | Predictive planning |
| **Automated Reports** | Scheduled email reports | Effortless monitoring |
| **Advanced Anomalies** | Statistical pattern detection | Enhanced security |
| **Tier Recommendations** | AI-powered upgrade suggestions | Optimal tier assignment |

## Quick Start Examples

### 1. View Analytics Dashboard

```php
// Access the analytics dashboard
// Navigate to: Settings → WP oOS → Token Manager → Analytics
```

### 2. Get Cost Breakdown

```php
// PHP: Get user's cost breakdown
$costs = WP_MCP_AI_Cost_Calculator::get_user_cost_breakdown(
    $user_id,
    '2025-11-01',  // Start date
    '2025-11-30'   // End date
);

echo 'Total Cost: $' . $costs['total_cost'];
echo 'By Provider: ' . print_r( $costs['by_provider'], true );
```

```bash
# REST API: Get cost breakdown
GET /wp-json/mcp-ai/v1/users/123/cost-breakdown?start_date=2025-11-01&end_date=2025-11-30
```

### 3. Analyze Usage Trends

```php
// Get 30-day usage trend
$trend = WP_MCP_AI_Analytics_Engine::calculate_usage_trend( $user_id, 30 );

if ( $trend['direction'] === 'increasing' ) {
    echo 'Usage is trending up by ' . $trend['slope'] . ' tokens/day';
}
```

### 4. Enable Automated Reports

```php
// Enable daily reports for a user
update_user_meta( $user_id, '_wp_mcp_ai_report_daily', true );
update_user_meta( $user_id, '_wp_mcp_ai_report_format', 'html' );

// Customize report metrics
update_user_meta( $user_id, '_wp_mcp_ai_report_metrics', array(
    'tokens',
    'cost',
    'tools',
    'trends',
) );
```

### 5. Detect Advanced Anomalies

```php
// Automatic detection during usage recording
// Triggers wp_mcp_ai_advanced_anomaly_detected action

// Hook into anomaly detection
add_action( 'wp_mcp_ai_advanced_anomaly_detected', function( $user_id, $anomaly ) {
    if ( $anomaly['severity'] === 'critical' ) {
        // Send alert to admin
        wp_mail(
            get_option( 'admin_email' ),
            'Critical Token Anomaly',
            sprintf( 'User %d: %s', $user_id, $anomaly['message'] )
        );
    }
}, 10, 2 );
```

### 6. Get Tier Recommendation

```php
// Get AI-powered tier recommendation
$rec = WP_MCP_AI_Tier_Recommendations::get_tier_recommendation( $user_id );

if ( $rec['action'] === 'upgrade' ) {
    echo 'Recommend upgrading to: ' . $rec['to_tier'];
    echo 'Reason: ' . $rec['reason'];
    echo 'Confidence: ' . $rec['confidence'] . '%';
}
```

## New REST API Endpoints

### Cost Endpoints

```bash
# Get user cost breakdown
GET /wp-json/mcp-ai/v1/users/{id}/cost-breakdown?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD

# Get site-wide costs
GET /wp-json/mcp-ai/v1/cost/total?start_date=YYYY-MM-DD&end_date=YYYY-MM-DD

# Get costs by provider
GET /wp-json/mcp-ai/v1/cost/by-provider

# Get project costs
GET /wp-json/mcp-ai/v1/cost/by-project/{project_id}
```

### Analytics Endpoints

```bash
# Get usage trends
GET /wp-json/mcp-ai/v1/analytics/trends/{user_id}?days=30

# Get usage patterns
GET /wp-json/mcp-ai/v1/analytics/patterns/{user_id}

# Compare users
GET /wp-json/mcp-ai/v1/analytics/compare?user_ids=1,2,3

# Get anomalies
GET /wp-json/mcp-ai/v1/analytics/anomalies?severity=high
```

### Recommendation Endpoints

```bash
# Get tier recommendation
GET /wp-json/mcp-ai/v1/recommendations/tier/{user_id}

# Apply recommendation
POST /wp-json/mcp-ai/v1/recommendations/apply/{user_id}

# Get all recommendations
GET /wp-json/mcp-ai/v1/recommendations/all
```

## Chart Types Available

| Chart Type | Use Case | Location |
|------------|----------|----------|
| **Line Chart** | Token usage over time | User dashboard |
| **Bar Chart** | Tool/user comparison | Analytics page |
| **Pie Chart** | Provider distribution | Site stats |
| **Stacked Bar** | Multi-provider breakdown | Advanced view |
| **Gauge Chart** | Current vs. limit | Widget |
| **Heatmap** | Hourly patterns | Pattern analysis |

## Report Types

### Daily Report
- **Frequency**: Every day at 8 AM
- **Contains**: Yesterday's usage, costs, top tools
- **Opt-in**: Per user preference

### Weekly Report
- **Frequency**: Every Monday at 9 AM
- **Contains**: Last 7 days trends, comparisons
- **Opt-in**: Per user preference

### Monthly Report
- **Frequency**: 1st of month at 10 AM
- **Contains**: Full month breakdown, ROI analysis
- **Opt-in**: Per user preference

## Anomaly Severity Levels

| Severity | Z-Score | Action |
|----------|---------|--------|
| **Low** | 3-4 | Log only |
| **Medium** | 4-5 | Log + Email admin |
| **High** | 5-6 | Log + Email + Flag account |
| **Critical** | >6 | Log + Email + Suspend account |

## Cost Calculation Examples

### OpenAI Pricing
```php
// gpt-4o-mini: $0.15/1M input, $0.60/1M output
$cost = WP_MCP_AI_Cost_Calculator::calculate_cost(
    'openai',
    'gpt-4o-mini',
    1000000,  // 1M input tokens
    500000    // 500k output tokens
);
// Result: $0.45 ($0.15 + $0.30)
```

### Gemini Pricing
```php
// gemini-1.5-flash: $0.075/1M input, $0.30/1M output
$cost = WP_MCP_AI_Cost_Calculator::calculate_cost(
    'gemini',
    'gemini-1.5-flash',
    2000000,  // 2M input tokens
    1000000   // 1M output tokens
);
// Result: $0.45 ($0.15 + $0.30)
```

## Filter Hooks (Phase 7)

```php
// Customize chart colors
add_filter( 'wp_mcp_ai_chart_colors', function( $colors ) {
    $colors['primary'] = '#0073aa';
    $colors['success'] = '#46b450';
    $colors['warning'] = '#ffb900';
    $colors['danger'] = '#dc3232';
    return $colors;
} );

// Modify cost calculation
add_filter( 'wp_mcp_ai_calculated_cost', function( $cost, $provider, $model, $input, $output ) {
    // Apply custom pricing or discounts
    return $cost * 0.9; // 10% discount
}, 10, 5 );

// Customize report template
add_filter( 'wp_mcp_ai_report_template_path', function( $template, $report_type ) {
    if ( $report_type === 'daily' ) {
        return get_stylesheet_directory() . '/custom-daily-report.php';
    }
    return $template;
}, 10, 2 );

// Adjust anomaly threshold
add_filter( 'wp_mcp_ai_anomaly_z_score_threshold', function( $threshold ) {
    return 4.0; // Require 4 standard deviations (stricter)
} );
```

## Action Hooks (Phase 7)

```php
// When cost exceeds budget
add_action( 'wp_mcp_ai_cost_budget_exceeded', function( $user_id, $cost, $budget ) {
    // Send custom alert
    error_log( "User $user_id exceeded budget: $$cost / $$budget" );
}, 10, 3 );

// When tier recommendation generated
add_action( 'wp_mcp_ai_tier_recommendation_generated', function( $user_id, $recommendation ) {
    // Track recommendations for analytics
    do_action( 'custom_analytics_track', 'tier_recommendation', $recommendation );
}, 10, 2 );

// Before sending report
add_action( 'wp_mcp_ai_before_send_report', function( $recipient, $report_type, $data ) {
    // Modify report data or add custom metrics
    $data['custom_metric'] = calculate_custom_metric( $recipient['user_id'] );
}, 10, 3 );

// When chart rendered
add_action( 'wp_mcp_ai_chart_rendered', function( $chart_id, $chart_type, $data ) {
    // Track chart views
    increment_chart_view_count( $chart_id );
}, 10, 3 );
```

## Performance Tips

### 1. Cache Chart Data

```php
// Cache expensive chart data
$cache_key = 'wp_mcp_ai_chart_data_' . $user_id;
$data = wp_cache_get( $cache_key, 'wp_mcp_ai' );

if ( false === $data ) {
    $data = WP_MCP_AI_Analytics_Engine::get_chart_data( $user_id );
    wp_cache_set( $cache_key, $data, 'wp_mcp_ai', HOUR_IN_SECONDS );
}
```

### 2. Use Transients for Analytics

```php
// Cache trend analysis
$transient_key = 'wp_mcp_ai_trends_' . $user_id;
$trends = get_transient( $transient_key );

if ( false === $trends ) {
    $trends = WP_MCP_AI_Analytics_Engine::calculate_usage_trend( $user_id, 30 );
    set_transient( $transient_key, $trends, DAY_IN_SECONDS );
}
```

### 3. Paginate Large Reports

```php
// Limit data returned in API
$per_page = isset( $_GET['per_page'] ) ? min( 100, absint( $_GET['per_page'] ) ) : 20;
$page = isset( $_GET['page'] ) ? absint( $_GET['page'] ) : 1;

$data = WP_MCP_AI_Analytics_Engine::get_paginated_data( $user_id, $page, $per_page );
```

## Troubleshooting

### Charts Not Rendering
```php
// Check if Chart.js is loaded
if ( ! wp_script_is( 'chartjs', 'enqueued' ) ) {
    wp_enqueue_script( 'chartjs' );
}

// Check browser console for errors
// Verify data format matches Chart.js requirements
```

### Reports Not Sending
```php
// Check cron jobs
$cron = _get_cron_array();
foreach ( $cron as $timestamp => $jobs ) {
    if ( isset( $jobs['wp_mcp_ai_daily_usage_report'] ) ) {
        echo 'Next run: ' . gmdate( 'Y-m-d H:i:s', $timestamp );
    }
}

// Manually trigger report
WP_MCP_AI_Report_Scheduler::send_daily_report();
```

### Cost Calculations Incorrect
```php
// Verify pricing data is up to date
$pricing = WP_MCP_AI_Cost_Calculator::PRICING;
print_r( $pricing );

// Check usage data
$usage = WP_MCP_AI_Usage_Tracker::get_usage_for_user( $user_id );
print_r( $usage );
```

## Migration Notes

Phase 7 builds on Phases 1-6, so:
- **No breaking changes** to existing APIs
- **Backward compatible** with current data
- **Optional features** - can be enabled gradually
- **Database changes** are additive (new tables/columns only)

## Security Considerations

1. **Cost Data**: Sensitive - restrict access to authorized users
2. **Anomaly Logs**: May contain IP addresses - GDPR compliant storage
3. **Reports**: Opt-in only, no automatic emails without consent
4. **API Access**: Proper permission checks on all endpoints
5. **Chart Data**: Cache keys should include user_id to prevent leaks

## Next Steps

1. Review the full plan: `docs/PHASE-7-ANALYTICS-PLAN.md`
2. Provide feedback on proposed features
3. Prioritize features for MVP
4. Allocate development resources
5. Set target timeline for implementation

## Questions?

- **Full Documentation**: See `docs/PHASE-7-ANALYTICS-PLAN.md`
- **Current Features**: See `TOKEN-USAGE-MANAGER-IMPLEMENTATION.md`
- **Original Plan**: See `docs/TOKEN-MANAGER-ENHANCEMENT-PLAN.md`
- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

---

**Created**: 2025-11-12  
**Status**: Planning Phase  
**Next Review**: TBD
