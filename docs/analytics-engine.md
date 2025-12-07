# Analytics Engine Documentation

## Overview

The Analytics Engine provides advanced statistical analysis and trend detection for token usage data in WP oOS. It implements Phase 7 (Week 5-6) of the Token Manager Enhancement Plan.

## Features

### 1. Trend Analysis
- **Linear Regression**: Calculate slope, intercept, and R-squared values
- **Trend Direction**: Automatic classification (increasing, decreasing, stable)
- **Confidence Scoring**: 0-100% confidence based on R-squared
- **Projections**: Forecast usage 7 and 30 days ahead

### 2. Statistical Insights
- Mean, median, mode calculations
- Standard deviation and variance
- Min/max values
- Coefficient of variation
- Z-score calculations for anomaly detection

### 3. Pattern Recognition
- **Peak Hours**: Identify top 3 usage hours (0-23)
- **Peak Days**: Identify top 3 usage days (Mon-Sun)
- **Hourly Pattern**: Average usage by hour across all days
- **Daily Pattern**: Average usage by day of week
- **Usage Type**: Classification (consistent, sporadic, bursty)

### 4. Comparative Analysis
- **User vs User**: Compare two users' token usage
- **Tool vs Tool**: Compare two tools' popularity
- **Usage Ratios**: Calculate relative usage differences
- **Difference Percentage**: Show percentage variance

### 5. Anomaly Detection
- **Z-score Analysis**: Detect usage spikes >3 standard deviations
- **Severity Classification**: Low, medium, high, critical
- **Expected vs Actual**: Show what usage should have been
- **Date-specific**: Pinpoint exact dates of anomalies

## REST API Endpoints

### Get User Trends
```
GET /wp-json/mcp-ai/v1/analytics/trends/{user_id}?days=30
```

**Response:**
```json
{
  "success": true,
  "user_id": 123,
  "days": 30,
  "data": {
    "daily_usage": { "2025-01-01": 1000, "2025-01-02": 1200 },
    "trend": {
      "slope": 50.25,
      "intercept": 950.00,
      "r_squared": 0.9234,
      "direction": "increasing",
      "confidence": 92
    },
    "statistics": {
      "mean": 1100,
      "median": 1050,
      "std_dev": 150.5,
      "variance": 22650.25,
      "min": 900,
      "max": 1400,
      "count": 30,
      "coefficient_of_variation": 13.68
    },
    "patterns": {
      "peak_hours": [10, 14, 16],
      "peak_days": ["Monday", "Wednesday", "Friday"],
      "usage_type": "consistent"
    },
    "projected_7d": 2500,
    "projected_30d": 5000
  }
}
```

### Get User Patterns
```
GET /wp-json/mcp-ai/v1/analytics/patterns/{user_id}
```

**Response:**
```json
{
  "success": true,
  "user_id": 123,
  "patterns": {
    "peak_hours": [9, 10, 14],
    "peak_days": ["Monday", "Tuesday", "Wednesday"],
    "hourly_pattern": [0, 0, 0, 0, 0, 0, 0, 0, 50, 200, 250, ...],
    "daily_pattern": [100, 500, 450, 600, 550, 200, 150],
    "usage_type": "consistent"
  }
}
```

### Compare Users
```
GET /wp-json/mcp-ai/v1/analytics/compare?user_ids=123,456&days=30
```

**Response:**
```json
{
  "success": true,
  "user_id_1": 123,
  "user_id_2": 456,
  "days": 30,
  "comparison": {
    "user1_stats": { "mean": 1100, "median": 1050, ... },
    "user2_stats": { "mean": 800, "median": 750, ... },
    "usage_ratio": 1.38,
    "higher_user": 123,
    "difference_pct": 31.58
  }
}
```

### Detect Anomalies
```
GET /wp-json/mcp-ai/v1/analytics/anomalies?user_id=123&severity=high&threshold=3.0
```

**Response:**
```json
{
  "success": true,
  "user_id": 123,
  "threshold": 3.0,
  "severity": "high",
  "anomalies": [
    {
      "date": "2025-01-15",
      "tokens": 5000,
      "z_score": 4.25,
      "expected_value": 1100,
      "severity": "high"
    }
  ]
}
```

### Compare Tools
```
GET /wp-json/mcp-ai/v1/analytics/tools/compare?tool_slugs=search_content,web_search&days=30
```

**Response:**
```json
{
  "success": true,
  "tool_slug_1": "search_content",
  "tool_slug_2": "web_search",
  "days": 30,
  "comparison": {
    "tool1_stats": { "mean": 2500, ... },
    "tool2_stats": { "mean": 1800, ... },
    "usage_ratio": 1.39,
    "popular_tool": "search_content",
    "difference_pct": 32.35
  }
}
```

## PHP API

### Class: `WP_MCP_AI_Analytics_Engine`

#### `calculate_trend( $data_points )`
Calculate linear regression for usage trends.

**Parameters:**
- `$data_points` (array): Array of `[ timestamp => value ]` pairs

**Returns:** Array with slope, intercept, r_squared, direction, confidence

**Example:**
```php
$data = array(
    strtotime('2025-01-01') => 100,
    strtotime('2025-01-02') => 150,
    strtotime('2025-01-03') => 200,
);

$trend = WP_MCP_AI_Analytics_Engine::calculate_trend( $data );
// Returns: ['slope' => 50, 'direction' => 'increasing', 'confidence' => 99]
```

#### `calculate_statistics( $values )`
Calculate statistical metrics for dataset.

**Parameters:**
- `$values` (array): Numeric values

**Returns:** Array with mean, median, std_dev, variance, min, max, count, coefficient_of_variation

#### `calculate_z_score( $value, $mean, $std_dev )`
Calculate Z-score for a value.

**Parameters:**
- `$value` (float): Value to analyze
- `$mean` (float): Dataset mean
- `$std_dev` (float): Dataset standard deviation

**Returns:** (float) Z-score

#### `detect_patterns( $user_id )`
Analyze hourly and daily usage patterns.

**Parameters:**
- `$user_id` (int): User ID

**Returns:** Array with peak_hours, peak_days, hourly_pattern, daily_pattern, usage_type

#### `compare_users( $user_id_1, $user_id_2, $days = 30 )`
Compare usage between two users.

**Parameters:**
- `$user_id_1` (int): First user ID
- `$user_id_2` (int): Second user ID
- `$days` (int): Number of days to analyze (default: 30)

**Returns:** Array with comparison metrics

#### `compare_tools( $tool_slug_1, $tool_slug_2, $days = 30 )`
Compare usage between two tools.

**Parameters:**
- `$tool_slug_1` (string): First tool slug
- `$tool_slug_2` (string): Second tool slug
- `$days` (int): Number of days to analyze (default: 30)

**Returns:** Array with comparison metrics

#### `get_user_trends( $user_id, $days = 30 )`
Get comprehensive trend analysis for a user.

**Parameters:**
- `$user_id` (int): User ID
- `$days` (int): Number of days to analyze (default: 30)

**Returns:** Array with daily_usage, trend, statistics, patterns, projections

#### `detect_anomalies( $user_id, $threshold = 3.0 )`
Detect usage anomalies using Z-score analysis.

**Parameters:**
- `$user_id` (int): User ID
- `$threshold` (float): Z-score threshold (default: 3.0)

**Returns:** Array of anomalies with dates, tokens, z_scores, severity

## Usage Examples

### Admin Dashboard Widget
```php
// Get trends for current user
$trends = WP_MCP_AI_Analytics_Engine::get_user_trends( get_current_user_id(), 30 );

echo '<h3>Usage Trend: ' . esc_html( ucfirst( $trends['trend']['direction'] ) ) . '</h3>';
echo '<p>Confidence: ' . absint( $trends['trend']['confidence'] ) . '%</p>';
echo '<p>Projected usage in 7 days: ' . number_format( $trends['projected_7d'] ) . ' tokens</p>';
```

### Admin Report: Top Users
```php
// Compare all users to find highest usage
$users = get_users( array( 'fields' => 'ID' ) );
$usage_stats = array();

foreach ( $users as $user_id ) {
    $trends = WP_MCP_AI_Analytics_Engine::get_user_trends( $user_id, 30 );
    $usage_stats[ $user_id ] = $trends['statistics']['mean'];
}

arsort( $usage_stats );
$top_users = array_slice( $usage_stats, 0, 10, true );
```

### Security Alert: Detect Anomalies
```php
// Check for usage anomalies
$anomalies = WP_MCP_AI_Analytics_Engine::detect_anomalies( $user_id, 3.0 );

foreach ( $anomalies as $anomaly ) {
    if ( $anomaly['severity'] === 'critical' || $anomaly['severity'] === 'high' ) {
        // Send alert email
        wp_mail(
            get_option( 'admin_email' ),
            'High Usage Anomaly Detected',
            sprintf(
                'User %d had unusual usage on %s: %d tokens (expected %d)',
                $user_id,
                $anomaly['date'],
                $anomaly['tokens'],
                $anomaly['expected_value']
            )
        );
    }
}
```

### AJAX Endpoint for Charts
```php
add_action( 'wp_ajax_get_usage_chart_data', function() {
    check_ajax_referer( 'wp_mcp_ai_nonce', 'nonce' );
    
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    
    $user_id = absint( $_POST['user_id'] ?? 0 );
    $trends = WP_MCP_AI_Analytics_Engine::get_user_trends( $user_id, 30 );
    
    wp_send_json_success( array(
        'labels' => array_keys( $trends['daily_usage'] ),
        'data' => array_values( $trends['daily_usage'] ),
        'trend_line' => array( /* calculate trend line points */ )
    ) );
} );
```

## Security & Permissions

All REST API endpoints require:
- **Authentication**: WordPress user session
- **Authorization**: `manage_options` capability (admin only)

Validation is performed on:
- User IDs (must exist)
- Tool slugs (non-empty strings)
- Numeric parameters (sanitized with `absint()` or `floatval()`)

## Performance Considerations

- **Caching**: Results can be cached with `wp_cache_set()` for 15 minutes
- **Query Limits**: Anomaly detection limited to 100 users at once
- **Data Retention**: Analyzes only last 30 days of data by default
- **Memory**: Handles datasets up to 10,000 data points efficiently

## Integration with Existing Features

The Analytics Engine integrates seamlessly with:
- **Token Manager**: Uses existing usage data structure
- **Chart.js Helper**: Provides data in Chart.js-compatible format
- **Cost Tracking**: Can be extended to analyze cost trends
- **Admin Dashboard**: Powers dashboard widgets

## Future Enhancements

Planned for Week 7-10:
- **Report Scheduler**: Automated email reports using trend data
- **Tier Recommendations**: Use trend analysis to suggest tier upgrades/downgrades
- **Enhanced Anomaly Detection**: Geolocation and temporal pattern analysis
- **Predictive Modeling**: ML-based forecasting (Phase 8+)

## Testing

Run tests with:
```bash
vendor/bin/phpunit tests/test-analytics-engine.php
vendor/bin/phpunit tests/test-rest-analytics-endpoints.php
```

Test coverage:
- ✅ Trend calculation (increasing, decreasing, stable)
- ✅ Statistical calculations (mean, median, std dev, etc.)
- ✅ Z-score calculations
- ✅ Pattern detection (hourly, daily)
- ✅ User comparisons
- ✅ Tool comparisons
- ✅ Anomaly detection with severity classification
- ✅ REST API endpoints (authentication, validation, responses)

## Support

For questions or issues:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: See `/docs/token-management.md`
