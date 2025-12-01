# Performance Monitoring Guide

## Overview

The WP oOS Performance Monitoring system provides real-time insights into plugin performance, historical trend analysis, AI-generated recommendations for optimization, and comprehensive error tracking. This guide covers the Performance Monitor CCT, Error Tracking Service, admin dashboard, widgets, and integration options.

## Architecture

### Performance Monitor CCT

The Performance Monitor Custom Content Type (CCT) is the central data store for all performance metrics:

- **Location:** `includes/services/class-wp-mcp-ai-performance-monitor-service.php`
- **Storage:** JetEngine CCT (primary) or WordPress Options (fallback)
- **Auto-registration:** Automatically creates CCT schema when JetEngine is active
- **AI-Friendly:** Generates diagnostic summaries and recommendations

### Error Tracking Service

The Error Tracking Service provides centralized error monitoring:

- **Location:** `includes/services/class-wp-mcp-ai-error-tracking-service.php`
- **Documentation:** See [Error Tracking Service Guide](error-tracking-service.md)
- **Features:** Real-time error tracking, error rate calculation, component-level analysis
- **Integration:** Seamlessly integrates with Performance Monitor CCT

### Data Structure

Each performance test record contains:

| Field | Type | Description |
|-------|------|-------------|
| `test_type` | select | stress, security, speed, optimization, monitoring |
| `component` | select | rest_api, chat_ui, mcp_core, elementor, cpt_* |
| `optimizations_enabled` | radio | yes/no |
| `response_time_ms` | number | Average response time in milliseconds |
| `memory_usage_bytes` | number | Peak memory usage in bytes |
| `db_queries` | number | Number of database queries |
| `error_rate` | number | Percentage of requests with errors |
| `total_errors` | number | Total error count |
| `metrics_json` | textarea | Complete metrics in JSON format |
| `test_results_json` | textarea | Detailed results in JSON format |
| `diagnostic_summary` | textarea | Human-readable summary |
| `test_status` | select | passed, warning, failed |
| `recommendations` | textarea | AI-generated recommendations (JSON) |
| `tested_at` | datetime | Test execution timestamp |
| `php_version` | text | PHP version during test |
| `wp_version` | text | WordPress version during test |
| `plugin_version` | text | Plugin version during test |

## Using the Performance Monitor CCT

### Storing Test Results

```php
// Basic usage
WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
    'stress',           // test_type
    'rest_api',        // component
    true,              // optimizations_enabled
    array(             // metrics
        'concurrent_requests' => 100,
        'avg_response_time' => 245.3,
        'max_response_time' => 890.1,
        'memory_peak_mb' => 64.2,
        'memory_peak_bytes' => 67239936,
        'db_queries' => 45
    ),
    array(             // test_results
        'passed' => 98,
        'failed' => 2,
        'total' => 100
    )
);
```

### Retrieving Performance Trends

```php
// Get trends for a component
$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends(
    'rest_api',        // component
    '-7 days',         // since (strtotime compatible)
    'stress'           // test_type (optional)
);

// Returns:
array(
    'trend' => 'improving',  // improving, stable, degrading, no_data
    'avg_response_time' => 245.3,
    'avg_memory_usage' => 64.2,  // in MB
    'avg_db_queries' => 45,
    'status_distribution' => array(
        'passed' => 95,
        'warning' => 3,
        'failed' => 2
    ),
    'total_tests' => 100
);
```

### Understanding Trend Analysis

The system automatically analyzes trends by comparing the first third and last third of test results:

- **Improving** - Last third is 20%+ faster than first third
- **Stable** - Performance variation is less than 20%
- **Degrading** - Last third is 20%+ slower than first third
- **No Data** - Insufficient test results for analysis (< 3 tests)

## Admin Dashboard

### Accessing the Dashboard

1. Navigate to **WordPress Admin → Settings → WP oOS**
2. Click on the **Performance Monitoring** tab
3. View real-time metrics, alerts, and recommendations

### Dashboard Features

#### System Health Overview

Displays overall health status:
- **Good** ✓ - All components performing well
- **Fair** ⚠ - Some warnings detected
- **Warning** ⚠ - Multiple warnings or degrading performance
- **Critical** ✗ - Critical issues detected

#### Summary Statistics

Quick overview cards showing:
- Total components monitored
- Number of active alerts
- Available recommendations

#### Component Performance Table

Lists all monitored components with:
- Component name (REST API, Chat UI, etc.)
- Health status badge
- Average response time
- Performance trend indicator
- Actions (View Details)

#### Performance Alerts

Critical and high-priority alerts including:
- Performance degradation warnings
- Security vulnerabilities (if found)
- Resource usage concerns
- Regression alerts

#### Optimization Recommendations

AI-generated, actionable recommendations:
- Severity level (critical, high, medium, low)
- Specific issue identified
- Recommended action
- Explanation/reason

#### Test Execution Interface

Buttons to run tests directly from admin:
- Run Stress Test
- Run Security Test
- Run Speed Benchmark
- Run Optimization Test

**Note:** Tests are executed via command line for accuracy. The admin provides execution instructions.

#### Export Options

Export test results for external analysis:
- **Export as JSON** - Complete data with all metrics
- **Export as CSV** - Tabular format for spreadsheets

## Performance Reporter

### Generating Reports

```php
// Load the reporter
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-performance-reporter.php';

// Generate comprehensive report
$report = WP_MCP_AI_Performance_Reporter::generate_report(array(
    'time_period' => '-30 days',
    'components' => array('rest_api', 'chat_ui', 'mcp_core'),
    'test_types' => array('stress', 'security', 'speed', 'optimization')
));

// Report structure:
array(
    'generated_at' => '2024-01-15 10:30:00',
    'time_period' => '-30 days',
    'overall_health' => 'good',
    'components' => array(/* component data */),
    'alerts' => array(/* performance alerts */),
    'recommendations' => array(/* optimization recommendations */),
    'summary' => array(/* summary statistics */)
);
```

### Getting Performance Alerts

```php
// Get top 5 alerts for dashboard widget
$alerts = WP_MCP_AI_Performance_Reporter::get_performance_alerts(5);

foreach ($alerts as $alert) {
    echo $alert['severity'] . ': ' . $alert['message'];
}
```

### Chart Data for Visualization

```php
// Get data for charts
$chart_data = WP_MCP_AI_Performance_Reporter::get_chart_data(
    'rest_api',           // component
    'avg_response_time',  // metric
    '-30 days'           // time_period
);

// Returns:
array(
    'labels' => array('2024-01-01 10:00', '2024-01-01 11:00', ...),
    'data' => array(245.3, 250.1, 240.5, ...)
);
```

### Updating Baselines

```php
// Update baseline metrics (run monthly)
$baselines = WP_MCP_AI_Performance_Reporter::update_baselines();

// Get current baselines
$baselines = WP_MCP_AI_Performance_Reporter::get_baselines();
```

## Elementor Widgets

### Available Widgets

#### 1. Performance Test Runner Widget

**File:** `includes/elementor/class-wp-mcp-ai-elementor-performance-test-runner-widget.php`

Execute performance tests directly from pages:

**Settings:**
- Widget title
- Enabled test types (stress, security, speed, optimization)
- Button style
- Auto-refresh results

**Usage:**
```
Drag "WP oOS Performance Test Runner" widget to page
Configure which tests to show
Users can click buttons to get test execution instructions
```

#### 2. Performance Metrics Widget

**File:** `includes/elementor/class-wp-mcp-ai-elementor-performance-metrics-widget.php`

Real-time performance dashboard:

**Settings:**
- Component to monitor
- Time period (-24 hours, -7 days, -30 days)
- Refresh interval
- Metrics to display

**Displays:**
- Average response time
- Peak memory usage
- Database query count
- Status indicators (good/warning/critical)

#### 3. Performance Trends Chart Widget

**File:** `includes/elementor/class-wp-mcp-ai-elementor-performance-trends-widget.php`

Historical performance visualization:

**Settings:**
- Component to chart
- Metric to visualize
- Time period
- Chart type (line, bar)

**Features:**
- Chart.js integration
- Interactive tooltips
- Trend indicators
- Responsive design

#### 4. Test Results Table Widget

**File:** `includes/elementor/class-wp-mcp-ai-elementor-test-results-table-widget.php`

Browse and filter test results:

**Settings:**
- Test type filter
- Component filter
- Results per page
- Expandable details

**Features:**
- Sortable columns
- Search/filter
- Pagination
- Detailed test information

#### 5. Performance Recommendations Widget

**File:** `includes/elementor/class-wp-mcp-ai-elementor-performance-recommendations-widget.php`

AI-generated fix suggestions:

**Settings:**
- Severity filter (all, critical, high, medium, low)
- Number of recommendations
- Component filter

**Displays:**
- Color-coded severity indicators
- Specific issue identified
- Actionable recommendations
- Implementation guidance

#### 6. System Health Status Widget

**File:** `includes/elementor/class-wp-mcp-ai-elementor-system-health-status-widget.php`

Component-level health overview:

**Settings:**
- Show component breakdown
- Health threshold settings
- Alert visibility

**Features:**
- Overall health indicator
- Component-by-component status
- Critical issue alerts
- Quick action buttons

## Gutenberg Blocks

### Available Blocks

All 6 Elementor widgets are also available as Gutenberg blocks:

**Location:** `includes/blocks/class-wp-mcp-ai-performance-blocks.php`

**Block Names:**
- `wp-mcp-ai/performance-test-runner`
- `wp-mcp-ai/performance-metrics`
- `wp-mcp-ai/system-health-status`
- `wp-mcp-ai/test-results-table`
- `wp-mcp-ai/performance-recommendations`
- `wp-mcp-ai/performance-trends`

**Usage:**
1. Open the block editor
2. Search for "WP oOS Performance"
3. Insert desired block
4. Configure block settings in the sidebar
5. Publish/update

**Features:**
- Server-side rendering for performance
- Matches Elementor widget functionality
- Block-specific settings
- Integrated with Performance Monitor CCT

### Block Editor JavaScript

**Location:** `assets/js/performance-blocks.js`

Handles block registration and editor interface.

## Integration Examples

### Dashboard Widget

Add a performance widget to the WordPress dashboard:

```php
add_action('wp_dashboard_setup', function() {
    wp_add_dashboard_widget(
        'wp_mcp_ai_performance',
        'WP oOS Performance',
        function() {
            $alerts = WP_MCP_AI_Performance_Reporter::get_performance_alerts(3);
            
            echo '<ul>';
            foreach ($alerts as $alert) {
                printf(
                    '<li class="alert-%s">%s</li>',
                    esc_attr($alert['severity']),
                    esc_html($alert['message'])
                );
            }
            echo '</ul>';
        }
    );
});
```

### Admin Bar Menu

Add performance status to admin bar:

```php
add_action('admin_bar_menu', function($wp_admin_bar) {
    $report = WP_MCP_AI_Performance_Reporter::generate_report(array(
        'time_period' => '-24 hours'
    ));
    
    $wp_admin_bar->add_node(array(
        'id' => 'wp-mcp-ai-performance',
        'title' => sprintf(
            'Performance: %s',
            ucfirst($report['overall_health'])
        ),
        'href' => admin_url('options-general.php?page=wp-mcp-ai&tab=performance')
    ));
}, 100);
```

### Custom Notifications

Send email alerts for critical issues:

```php
add_action('wp_mcp_ai_performance_test_complete', function($test_type, $component, $results) {
    if ($results['test_status'] === 'failed') {
        wp_mail(
            get_option('admin_email'),
            'WP oOS Performance Alert',
            sprintf(
                'Critical performance issue detected in %s (%s test)',
                $component,
                $test_type
            )
        );
    }
}, 10, 3);
```

### REST API Endpoint

Access performance data via REST API:

```php
add_action('rest_api_init', function() {
    register_rest_route('wp-mcp-ai/v1', '/performance/(?P<component>[a-z_]+)', array(
        'methods' => 'GET',
        'callback' => function($request) {
            $component = $request['component'];
            $trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends(
                $component,
                '-7 days'
            );
            return rest_ensure_response($trends);
        },
        'permission_callback' => function() {
            return current_user_can('manage_options');
        }
    ));
});
```

## Best Practices

### Data Retention

Configure how long to keep performance data:

```php
// Keep last 90 days of data
add_filter('wp_mcp_ai_performance_retention_days', function() {
    return 90;
});
```

### Automated Testing

Schedule regular performance tests:

```php
// Run weekly performance tests
add_action('wp_mcp_ai_weekly_performance_check', function() {
    // Trigger test suite execution
    // Results automatically stored in CCT
});

if (!wp_next_scheduled('wp_mcp_ai_weekly_performance_check')) {
    wp_schedule_event(time(), 'weekly', 'wp_mcp_ai_weekly_performance_check');
}
```

### Performance Budgets

Set performance budgets and enforce them:

```php
add_filter('wp_mcp_ai_performance_budget', function($budget, $component) {
    $budgets = array(
        'rest_api' => array(
            'response_time' => 500,  // ms
            'memory' => 128,         // MB
            'db_queries' => 30
        ),
        'chat_ui' => array(
            'response_time' => 1000,
            'memory' => 256,
            'db_queries' => 50
        )
    );
    
    return isset($budgets[$component]) ? $budgets[$component] : $budget;
}, 10, 2);
```

## Troubleshooting

### CCT Not Registering

**Problem:** Performance Monitor CCT doesn't appear in JetEngine

**Solution:**
1. Ensure JetEngine is active and up to date
2. Check that Data Stores module is enabled in JetEngine
3. Deactivate/reactivate the plugin to trigger registration
4. Check PHP error logs for registration errors

### No Data Appearing

**Problem:** Tests run but no data shows in widgets

**Solution:**
1. Verify Performance Monitor CCT class is loaded
2. Check if data is stored in WordPress options (fallback mode)
3. Test data storage manually:
```php
$result = WP_MCP_AI_Performance_Monitor_CCT::store_test_result(
    'speed', 'rest_api', false,
    array('avg_response_time' => 100),
    array('passed' => 1)
);
var_dump($result); // Should return item ID
```

### Widgets Not Displaying

**Problem:** Elementor widgets don't appear in editor

**Solution:**
1. Clear Elementor cache
2. Regenerate Elementor CSS
3. Check widget registration in `includes/class-wp-mcp-ai-elementor-integration.php`
4. Verify user has sufficient capabilities

## API Reference

### Main Methods

#### `WP_MCP_AI_Performance_Monitor_CCT::store_test_result()`

Store a performance test result.

**Parameters:**
- `$test_type` (string) - Test type: stress, security, speed, optimization
- `$component` (string) - Component tested
- `$optimizations_enabled` (bool) - Whether optimizations were active
- `$metrics` (array) - Performance metrics
- `$test_results` (array) - Detailed test results

**Returns:** (int|false) Item ID on success, false on failure

#### `WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends()`

Retrieve performance trends for analysis.

**Parameters:**
- `$component` (string) - Component to analyze
- `$since` (string) - Date string (strtotime compatible)
- `$test_type` (string) - Optional test type filter

**Returns:** (array) Performance trends data

#### `WP_MCP_AI_Performance_Reporter::generate_report()`

Generate comprehensive performance report.

**Parameters:**
- `$options` (array) - Report options

**Returns:** (array) Complete performance report

## Error Tracking Integration

The Performance Monitor integrates with the Error Tracking Service to provide comprehensive error analysis:

### Automatic Error Rate Monitoring

The Performance Reporter automatically checks error rates for all components:

```php
$report = WP_MCP_AI_Performance_Reporter::generate_report( array(
    'time_period' => '-7 days'
) );

// Report includes error rate data in component metrics
foreach ( $report['components'] as $component => $data ) {
    if ( isset( $data['metrics']['error_rate'] ) ) {
        echo "Error rate for $component: " . $data['metrics']['error_rate'] . "%\n";
    }
}
```

### Error Rate Alerts

Error rates trigger automatic alerts:

- **Critical (>10%)** - Immediate attention required
- **High (>5%)** - Investigation needed
- **Medium (>1%)** - Monitoring recommended

### Manual Error Tracking

Track errors manually and link to performance metrics:

```php
$error_service = wp_mcp_ai_get_error_tracking_service();

// Track error with performance context
$error_service->record_error_with_metrics(
    'rest_api',
    'Request timeout',
    array(
        'endpoint'      => '/wp-json/mcp-ai/v1/chat',
        'response_time' => 30000
    ),
    true  // Store in Performance Monitor CCT
);
```

### Viewing Error Statistics

```php
$error_service = wp_mcp_ai_get_error_tracking_service();

// Get error statistics for last 24 hours
$stats = $error_service->get_error_statistics( 86400 );

foreach ( $stats as $component => $data ) {
    echo "$component: {$data['count']} errors ({$data['unique_message_count']} unique)\n";
}
```

## Resources

- [Error Tracking Service Guide](error-tracking-service.md)
- [Performance Testing Guide](performance-testing-guide.md)
- [WP oOS Documentation Index](DOCUMENTATION_INDEX.md)
- [JetEngine Documentation](https://crocoblock.com/knowledge-base/jetengine/)
- [Elementor Developer Docs](https://developers.elementor.com/)

## Support

For issues or questions:

- GitHub Issues: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- Documentation: See `docs/` directory
- Security Issues: See SECURITY.md
