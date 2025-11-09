# Enhanced Orchestration Layer & Token Manager

## Overview

This enhancement adds enterprise-grade features to the WP oOS orchestration layer and token manager, building upon the recently integrated SIEM infrastructure to provide comprehensive auditability, predictive resource management, and intelligent budget allocation.

## New Features

### Resource Manager Enhancements

#### 1. Usage History Tracking
- **Method**: `get_usage_history( $hours )`
- **Purpose**: Retrieve historical resource usage data for analysis
- **Retention**: 7 days of data with automatic cleanup
- **Caching**: 5-minute cache for performance optimization

#### 2. Usage Recording
- **Method**: `record_usage( $usage_data )`
- **Purpose**: Record resource usage for historical tracking
- **Data Captured**: 
  - Memory usage (used, peak, limit)
  - Execution time
  - Token usage
  - Operation type
  - System tier
  - Status (success/error)

#### 3. Predictive Resource Forecasting
- **Method**: `predict_requirements( $operation_type )`
- **Purpose**: Forecast future resource needs based on historical patterns
- **Returns**:
  - Predicted tokens, memory, and execution time
  - Confidence score (0-1 scale)
  - Sample size
  - Recommendation (proceed/consider_larger_tier/monitor_memory)
  - Average historical values

#### 4. Health Status Monitoring
- **Method**: `get_health_status()`
- **Purpose**: Comprehensive system health monitoring
- **Monitors**:
  - Memory usage percentage
  - Error rate (last hour)
  - Average response time
  - Recent request count
- **Health Levels**: healthy, warning, critical
- **SIEM Integration**: Logs critical health events
- **Caching**: 1-minute cache

#### 5. Adaptive Budget Allocation
- **Method**: `get_adaptive_budget( $priority )`
- **Purpose**: Priority-based budget allocation with health adjustments
- **Priority Levels**:
  - High: 100% allocation
  - Medium: 80% allocation
  - Low: 50% allocation
- **Health Adjustments**:
  - Critical: 50% reduction
  - Warning: 75% allocation
  - Healthy: 100% allocation
- **Minimum Budget**: 100 tokens guaranteed

### Token Budget Manager Enhancements

#### 1. SIEM Integration
- **Budget Violations**: Logs user, assistant, overage amount, window details
- **Successful Checks**: Logs utilization percentage for analytics
- **Severity Levels**: warning (violations), info (successful checks)

#### 2. Usage Analytics
- **Method**: `get_usage_analytics( $user_id, $period )`
- **Periods**: 1h, 24h, 7d, 30d
- **Metrics**:
  - Total tokens used
  - Total requests
  - Average tokens per request
  - Peak usage
  - Trend (increasing/decreasing/stable)
  - Hourly breakdown

#### 3. Analytics Recording
- **Method**: `record_analytics( $user_id, $tokens, $context )`
- **Purpose**: Record token usage for long-term analysis
- **Retention**: 30 days with automatic cleanup
- **Context**: Model, assistant, and other metadata

#### 4. Usage Forecasting
- **Method**: `forecast_usage( $user_id, $period )`
- **Purpose**: Predict future token usage based on historical patterns
- **Returns**:
  - Forecasted token count
  - Confidence level (0-1 scale)
  - Trend direction
  - Recommendation (normal/consider_budget_increase)
  - Alert threshold status
- **Trend Multipliers**:
  - Increasing: 1.2x multiplier
  - Decreasing: 0.8x multiplier
  - Stable: 1.0x multiplier

### Admin UI Enhancements

#### 1. Health Status Banner
- Real-time system health display
- Color-coded indicators:
  - Green: Healthy
  - Yellow: Warning
  - Red: Critical
- Issue summary display
- Dynamic dashicons based on status

#### 2. Enhanced Metrics Dashboard
- **Memory Usage**: Percentage display with color-coded progress bar
- **Error Rate**: Last hour error percentage
- **Active Cron Jobs**: Count of scheduled tasks
- **Average Response Time**: Performance metric

#### 3. Predictive Insights Section
- Displays when confidence > 30%
- Shows predicted resource needs
- Confidence percentage
- Actionable recommendations
- Warning indicators for issues

## Usage Examples

### Recording Resource Usage

```php
$resource_manager = WP_MCP_AI_Resource_Manager::instance();

$resource_manager->record_usage( array(
    'operation_type' => 'chat',
    'tokens_used'    => 1500,
    'execution_time' => 8,
    'status'         => 'success',
) );
```

### Getting Predictive Recommendations

```php
$prediction = $resource_manager->predict_requirements( 'chat' );

if ( $prediction['confidence'] > 0.5 ) {
    echo "Expected to need {$prediction['predicted_tokens']} tokens";
    
    if ( 'proceed' !== $prediction['recommendation'] ) {
        echo "Recommendation: {$prediction['recommendation']}";
    }
}
```

### Checking System Health

```php
$health = $resource_manager->get_health_status();

if ( 'critical' === $health['overall_health'] ) {
    // Take action - reduce load, alert admins, etc.
    foreach ( $health['issues'] as $issue ) {
        error_log( "Health issue: $issue" );
    }
}
```

### Adaptive Budget Allocation

```php
// High priority request
$budget_high = $resource_manager->get_adaptive_budget( 'high' );

// Background task (low priority)
$budget_low = $resource_manager->get_adaptive_budget( 'low' );
```

### Token Usage Analytics

```php
$user_id = get_current_user_id();

// Get weekly analytics
$analytics = WP_MCP_AI_Token_Budget_Manager::get_usage_analytics( $user_id, '7d' );

echo "Total tokens: {$analytics['total_tokens']}";
echo "Average per request: {$analytics['avg_tokens_per_request']}";
echo "Trend: {$analytics['trend']}";
```

### Usage Forecasting

```php
// Forecast next 24 hours
$forecast = WP_MCP_AI_Token_Budget_Manager::forecast_usage( $user_id, '24h' );

if ( $forecast['alert_threshold_met'] ) {
    // Send alert - user approaching budget limits
    notify_user_of_high_usage( $user_id, $forecast );
}
```

## Integration Points

### SIEM Integration

The enhancements automatically integrate with the SIEM logger when available:

```php
if ( class_exists( 'WP_MCP_AI_SIEM_Logger' ) ) {
    $siem = WP_MCP_AI_SIEM_Logger::get_instance();
    $siem->export_event( $event_type, $message, $context, $severity );
}
```

Events logged:
- `resource_health_critical` - Critical health status detected
- `token_budget_exceeded` - User exceeded token budget
- `token_budget_usage` - Regular usage tracking (info level)

### WordPress Hooks

The enhancements respect existing WordPress filters:

```php
// Customize workload tier determination
add_filter( 'wp_mcp_ai_workload_tier', function( $tier, $memory_limit ) {
    // Custom logic
    return $tier;
}, 10, 2 );

// Customize max tokens
add_filter( 'wp_mcp_ai_resource_max_tokens', function( $max_tokens, $tier ) {
    // Custom logic
    return $max_tokens;
}, 10, 2 );
```

## Performance Considerations

### Caching Strategy
- Health status: 1-minute cache
- Usage history: 5-minute cache
- Analytics: No cache (on-demand)

### Data Retention
- Resource usage history: 7 days
- Token analytics: 30 days
- Automatic cleanup on new recordings

### Database Impact
- Options API usage: 2 new options
  - `wp_mcp_ai_resource_usage_history`
  - `wp_mcp_ai_token_analytics`
- Transients: 2 new transients
  - `wp_mcp_ai_resource_health`
  - `wp_mcp_ai_resource_history_{hours}`

## Security Considerations

### Data Privacy
- All PII redaction handled by SIEM logger
- User IDs logged but not email addresses
- No sensitive request data stored in analytics

### Access Control
- All features respect existing WordPress capability checks
- SIEM events only created if SIEM logger is properly configured
- No bypass mechanisms for security checks

## Testing

### Test Coverage
- 27 comprehensive test cases
- Resource manager: 13 tests
- Token budget manager: 14 tests
- All features have unit tests
- Integration tests for SIEM compatibility

### Test Execution

```bash
# Run all tests
composer run test

# Run specific test files
vendor/bin/phpunit tests/test-enhanced-resource-manager.php
vendor/bin/phpunit tests/test-enhanced-token-budget-manager.php
```

## Migration Notes

### Backward Compatibility
- All new methods are additions, no breaking changes
- Existing functionality remains unchanged
- Graceful degradation if SIEM not available
- No database schema changes required

### Upgrade Path
1. Code changes are automatically active
2. Options will be created on first usage
3. Historical data begins accumulating immediately
4. No manual migration steps required

## Future Enhancements

### Potential Additions
- Machine learning models for better predictions
- Anomaly detection in usage patterns
- Automated budget adjustments
- Multi-site aggregated analytics
- Export analytics to CSV/JSON
- Grafana/Prometheus integration
- Real-time alerting system

## Troubleshooting

### Common Issues

**Issue**: Predictions show 0% confidence
- **Cause**: Insufficient historical data
- **Solution**: Wait for more usage data to accumulate (typically 24-48 hours)

**Issue**: Health status always shows "healthy"
- **Cause**: No recent request history
- **Solution**: System is likely lightly loaded, this is normal

**Issue**: SIEM events not appearing
- **Cause**: SIEM logger not configured or disabled
- **Solution**: Check SIEM settings in admin panel

## Changelog

### Version 1.1.0
- Added usage history tracking for resources
- Implemented predictive resource forecasting
- Added comprehensive health monitoring
- Implemented adaptive budget allocation
- Added SIEM integration for resource events
- Added token usage analytics and trending
- Implemented usage forecasting for tokens
- Enhanced admin UI with health dashboard
- Added 27 comprehensive test cases

## Contributors

- Enhanced by: GitHub Copilot
- Integration with existing SIEM infrastructure
- Follows WordPress coding standards
- Compatible with existing WP oOS architecture
