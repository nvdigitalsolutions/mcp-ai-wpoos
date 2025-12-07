# Token Usage Manager Enhancement Implementation Summary

## Overview

This document summarizes the implementation of the Token Usage Manager enhancements for WP oOS (WordPress Open Operator System). The implementation follows the comprehensive plan outlined in `docs/TOKEN-MANAGER-ENHANCEMENT-PLAN.md`.

## Implementation Status

### Phase 1: Core Tiered System ✅ COMPLETED

The tiered token limit system has been fully implemented with the following features:

**Constants and Configuration**
- Three tier levels: `free` (50k tokens/day), `pro` (200k tokens/day), `enterprise` (1M tokens/day)
- Role-based default tier mapping
- Tool-specific multipliers for high-output tools (crawl4ai: 2.0x, search: 1.5x)

**Core Methods**
- `get_user_tier($user_id)` - Determines user's tier based on role or custom assignment
- `get_user_tool_limit($user_id, $tool_slug)` - Calculates tier-based limit with tool multipliers
- `set_user_tier($user_id, $tier, $expires)` - Sets custom tier with optional expiration
- `get_tool_multiplier($tool_slug)` - Returns multiplier for specific tools

**Features**
- Automatic tier expiration checking
- Role-based default tiers (subscriber/contributor: free, author/editor: pro, admin: enterprise)
- Custom tier overrides with expiration dates
- Filter hooks for customization

### Phase 2: Hourly Usage Tracking ✅ COMPLETED

Hourly granularity tracking has been added alongside existing daily tracking:

**Data Structure**
- Added `hourly` array to usage data structure
- Format: `'YYYY-MM-DD-HH' => tokens_used`
- 7-day retention with automatic cleanup
- Maintains 30-day daily data for compatibility

**Core Methods**
- `get_user_tool_hourly_usage($user_id, $tool_slug, $hour_key)` - Get usage for specific hour
- `get_peak_usage_hour($user_id, $tool_slug, $days)` - Identify peak usage times

**Implementation**
- Updated `record_tool_usage()` to track both daily and hourly
- Automatic cleanup of hourly data older than 7 days
- Backward compatible with existing daily tracking

### Phase 3: Usage Forecasting & Alerts ✅ COMPLETED

Predictive capabilities have been added to prevent limit exhaustion:

**Forecasting Algorithm**
- Linear regression based on 7 days of hourly data
- Requires minimum 24 hours of data for predictions
- Confidence scoring from 30% (1 day) to 90% (7 days)
- Projects daily usage based on average hourly consumption

**Core Methods**
- `forecast_limit_exhaustion($user_id, $tool_slug)` - Generate forecast
- `calculate_forecast_confidence($hourly_data)` - Calculate confidence percentage
- `should_send_limit_alert($user_id, $tool_slug)` - Determine if alert needed
- `send_limit_alert($user_id, $tool_slug, $forecast)` - Send email notification
- `check_and_send_alerts()` - Batch process all users (cron job)

**Alert System**
- Email notifications when limit exhaustion predicted
- 70% confidence threshold to avoid false alarms
- Daily alert limit (one per tool per day)
- Transient-based deduplication

**Cron Integration**
- Hourly cron job: `wp_mcp_ai_hourly_forecast_check`
- Automatically registered on plugin init
- Cleanup on plugin deactivation

### Phase 4: Admin UI Enhancements ✅ COMPLETED

**Completed**
- Tier badge display in per-user view
- Color-coded tier indicators (free: gray, pro: blue, enterprise: green)
- Bulk tier assignment functionality via `bulk_set_user_tiers()`
- CSV export for usage reports
- Bulk tier management UI with checkboxes and toolbar
- AJAX handlers for user details expansion (client-side toggle)
- AJAX handlers for usage reset operations (improved to accept user_id)

**Features Implemented**
- CSV Export:
  - Export all users or filtered by tier/tool
  - Includes: User ID, Username, Email, Tier, Total Tokens, Total Requests, Last Used, Limit, Usage %
  - Download as timestamped CSV file
  - Admin-only access with nonce verification

- Bulk Tier Management:
  - Select individual or all users via checkboxes
  - Bulk assign Free, Pro, or Enterprise tier
  - Confirmation dialog before applying
  - Success/error feedback
  - Automatic page reload after assignment

- User Details:
  - Expandable/collapsible per-user details
  - Shows detailed breakdown by provider and model
  - Client-side toggle (no AJAX required)

### Phase 5: REST API Endpoints ✅ COMPLETED

Four REST endpoints have been added for programmatic access:

**Endpoints**

1. `GET /mcp-ai/v1/users/{id}/token-tier`
   - Get user's tier, limits, and expiration
   - Returns sample tool limits
   - Permission: Self or admin

2. `POST /mcp-ai/v1/users/{id}/token-tier`
   - Update user's tier
   - Parameters: `tier`, `expiry` (optional)
   - Permission: Admin only

3. `GET /mcp-ai/v1/users/{id}/token-usage`
   - Get usage history
   - Parameters: `tool` (optional), `timeframe` (hourly/daily)
   - Permission: Self or admin

4. `GET /mcp-ai/v1/users/{id}/token-forecast`
   - Get usage forecast
   - Parameters: `tool` (required)
   - Permission: Self or admin

**Security**
- Proper permission callbacks
- Input validation and sanitization
- User data access restrictions
- Admin-only tier modifications

### Phase 6: Performance & Security ✅ COMPLETED

**Completed**
- Migration method for existing users
- Comprehensive unit test coverage
- Backward compatibility maintained
- Caching for tier lookups (WordPress object cache with 1-hour TTL)
- Anomaly detection for unusual usage patterns (5x threshold detection)
- Audit logging for tier changes (full audit trail with admin ID, IP, user agent)
- Database indexing optimization (meta_key and user_id indexes)

**Features Implemented**
- Caching:
  - `get_user_tier_cached()` method with wp_cache API
  - Automatic cache invalidation on tier updates
  - 1-hour TTL in 'wp_mcp_ai' cache group
  - Reduces database queries for tier lookups

- Anomaly Detection:
  - `detect_usage_anomaly()` detects 5x average usage spikes
  - Integrated into `record_tool_usage()` for automatic monitoring
  - Logs to WP_MCP_AI_Logger with full context
  - Fires `wp_mcp_ai_usage_anomaly_detected` action hook

- Audit Logging:
  - `log_tier_change()` captures all tier modifications
  - Hooked to `wp_mcp_ai_user_tier_changed` action
  - Logs admin ID, IP address, user agent, tier transition
  - Full audit trail for compliance and security

- Database Optimization:
  - `create_database_indexes()` creates performance indexes
  - idx_wp_mcp_ai_token_tier on (meta_key, meta_value)
  - idx_wp_mcp_ai_usage on (meta_key, user_id)
  - Idempotent - safe to call multiple times

## Files Changed

### Modified Files

1. **`includes/class-wp-mcp-ai-tool-token-limits.php`**
   - Added tier system constants and configuration
   - Implemented tier management methods
   - Added hourly tracking support
   - Implemented forecasting and alert system
   - Added bulk operations and migration support
   - **PHASE 4:** Added `export_usage_report()` method for CSV export
   - **PHASE 4:** Added `get_filtered_users()` helper method for filtering
   - **PHASE 6:** Added `get_user_tier_cached()` for cached tier lookups
   - **PHASE 6:** Added `invalidate_tier_cache()` for cache management
   - **PHASE 6:** Added `detect_usage_anomaly()` for security monitoring
   - **PHASE 6:** Added `log_tier_change()` for audit trail
   - **PHASE 6:** Added `create_database_indexes()` for performance optimization
   - **PHASE 6:** Integrated cache invalidation into `set_user_tier()`
   - **PHASE 6:** Integrated anomaly detection into `record_tool_usage()`
   - **PHASE 6:** Hooked audit logging into init()
   - ~870 lines of code (650 original + 220 Phase 6)

2. **`includes/admin/sections/class-wp-mcp-ai-section-token-manager.php`**
   - Added tier column to user table
   - Added tier badge styling
   - Updated colspan for new column
   - **NEW:** Added bulk tier management toolbar with checkbox column
   - **NEW:** Added "Export to CSV" button
   - **NEW:** Added "Select All" checkbox for bulk operations

3. **`includes/class-wp-mcp-ai-rest.php`**
   - Added token manager REST endpoint registration
   - Loaded REST token manager class

4. **`includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`**
   - **NEW:** Added `handle_export_token_usage_csv()` handler
   - **NEW:** Added `handle_bulk_assign_tier()` handler
   - **IMPROVED:** Updated `handle_reset_user_token_usage()` to accept user_id parameter
   - ~100 lines of new code

5. **`includes/admin/class-wp-mcp-ai-settings-dashboard.php`**
   - **NEW:** Registered CSV export AJAX action
   - **NEW:** Registered bulk tier assignment AJAX action

6. **`assets/js/settings-dashboard.js`**
   - **NEW:** Added `handleExportUsageCSV()` method for CSV downloads
   - **NEW:** Added `handleSelectAllUsers()` for select all functionality
   - **NEW:** Added `handleUserCheckboxChange()` for individual selections
   - **NEW:** Added `handleBulkTierSelectorChange()` for tier selector
   - **NEW:** Added `handleApplyBulkTier()` for bulk tier assignment
   - **NEW:** Added `updateBulkActionButton()` for button state management
   - ~150 lines of new code

### New Files

1. **`includes/rest/class-wp-mcp-ai-rest-token-manager.php`**
   - REST API controller for token management
   - 4 endpoint implementations
   - Permission callbacks
   - ~330 lines

2. **`tests/test-tiered-token-limits.php`**
   - Comprehensive unit tests for tiered system
   - Tests for role-based tiers, custom overrides, expiration
   - Tests for limits, multipliers, hourly tracking
   - Tests for bulk operations and forecasting
   - **PHASE 6:** Tests for tier caching and cache invalidation
   - **PHASE 6:** Tests for anomaly detection (normal and spike scenarios)
   - **PHASE 6:** Tests for audit logging of tier changes
   - **PHASE 6:** Tests for database index creation and idempotency
   - ~455 lines (250 original + 205 Phase 6)

3. **`tests/test-rest-token-manager.php`**
   - REST API endpoint tests
   - Permission check tests
   - User access restriction tests
   - ~190 lines

4. **`tests/test-csv-export.php`**
   - **NEW:** Comprehensive tests for CSV export functionality
   - Tests export format, permissions, filtering
   - Tests tier and tool filters
   - Tests percentage calculations
   - 8 test methods, ~270 lines

## Filter and Action Hooks

### New Filters

- `wp_mcp_ai_default_guest_tier` - Default tier for non-logged-in users
- `wp_mcp_ai_default_invalid_user_tier` - Default tier for invalid users
- `wp_mcp_ai_user_tier_by_role` - Modify tier based on role
- `wp_mcp_ai_default_user_tier` - Default tier for users without matching role
- `wp_mcp_ai_user_tool_limit` - Modify calculated token limit
- `wp_mcp_ai_tool_limit_multiplier` - Modify tool multiplier

### Modified Filters

- `wp_mcp_ai_enforce_tool_token_limits` - Now includes `$tier` parameter

### New Actions

- `wp_mcp_ai_user_tier_changed` - Fires when user's tier changes
- `wp_mcp_ai_limit_alert_sent` - Fires after limit alert email is sent
- **PHASE 4:** `wp_ajax_wp_mcp_ai_export_token_usage_csv` - AJAX action for CSV export
- **PHASE 4:** `wp_ajax_wp_mcp_ai_bulk_assign_tier` - AJAX action for bulk tier assignment
- **PHASE 6:** `wp_mcp_ai_usage_anomaly_detected` - Fires when usage anomaly is detected

### Modified Actions

- `wp_mcp_ai_tool_token_limit_exceeded` - Now includes `$tier` parameter
- `wp_mcp_ai_hourly_forecast_check` - Cron hook for usage forecasting
- **PHASE 4:** `wp_ajax_wp_mcp_ai_reset_user_token_usage` - Now accepts user_id parameter
- **IMPROVED:** `wp_ajax_wp_mcp_ai_reset_user_token_usage` - Now accepts user_id parameter

## Database Schema Changes

### User Meta Keys

**New**
- `_wp_mcp_ai_token_tier` (string) - User's custom tier assignment
- `_wp_mcp_ai_token_tier_expires` (timestamp) - Tier expiration date

**Modified**
- `_wp_mcp_ai_tool_token_usage` - Now includes `hourly` array

### Options

**New**
- `wp_mcp_ai_tiered_limits_migrated` (timestamp) - Migration completion timestamp

### Transients

**New**
- `wp_mcp_ai_limit_alert_{user_id}_{tool_slug}` - Alert deduplication (24h TTL)

## API Examples

### Get User Tier

```bash
curl -X GET \
  https://yoursite.com/wp-json/mcp-ai/v1/users/123/token-tier \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

### Update User Tier

```bash
curl -X POST \
  https://yoursite.com/wp-json/mcp-ai/v1/users/123/token-tier \
  -H 'Authorization: Bearer ADMIN_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
    "tier": "enterprise",
    "expiry": "2025-12-31"
  }'
```

### Get Usage Forecast

```bash
curl -X GET \
  "https://yoursite.com/wp-json/mcp-ai/v1/users/123/token-forecast?tool=run_crawl4ai_job" \
  -H 'Authorization: Bearer YOUR_TOKEN'
```

## PHP Usage Examples

### CSV Export

```php
// Export all users (admin only)
$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report();

// Export users filtered by tier
$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report(
    array( 'tier' => 'pro' )
);

// Export users filtered by tool
$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report(
    array( 'tool' => 'run_crawl4ai_job' )
);

// Export with multiple filters
$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report(
    array(
        'tier' => 'enterprise',
        'tool' => 'search_content',
    )
);
```

### Programmatic Tier Management

```php
// Get user's tier
$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );

// Set custom tier with expiration
WP_MCP_AI_Tool_Token_Limits::set_user_tier( 
    $user_id, 
    'pro', 
    strtotime( '+1 year' ) 
);

// Get tier-based limit for a tool
$limit = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( 
    $user_id, 
    'run_crawl4ai_job' 
);

// Bulk assign tiers
$results = WP_MCP_AI_Tool_Token_Limits::bulk_set_user_tiers(
    array( 123, 456, 789 ),
    'enterprise',
    '2025-12-31'
);
```

### Usage Forecasting

```php
// Get forecast for a user and tool
$forecast = WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion(
    $user_id,
    'run_crawl4ai_job'
);

if ( $forecast && $forecast['will_exceed'] ) {
    // User is projected to exceed limit
    $hours_until_reset = $forecast['hours_until_reset'];
    $confidence = $forecast['confidence'];
}

// Get peak usage hour
$peak = WP_MCP_AI_Tool_Token_Limits::get_peak_usage_hour(
    $user_id,
    'run_crawl4ai_job',
    7 // last 7 days
);
```

### Hourly Usage

```php
// Get hourly usage for current hour
$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_hourly_usage(
    $user_id,
    'run_crawl4ai_job'
);

// Get usage for specific hour
$hour_key = '2025-11-11-14';
$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_hourly_usage(
    $user_id,
    'run_crawl4ai_job',
    $hour_key
);
```

### Phase 6: Performance & Security Features

```php
// Use cached tier lookups for better performance
$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier_cached( $user_id );

// Manually invalidate cache if needed
WP_MCP_AI_Tool_Token_Limits::invalidate_tier_cache( $user_id );

// Detect usage anomaly (called automatically in record_tool_usage)
$is_anomaly = WP_MCP_AI_Tool_Token_Limits::detect_usage_anomaly(
    $user_id,
    'run_crawl4ai_job',
    6000 // Tokens in current request
);

if ( $is_anomaly ) {
    // Unusual usage detected - 5x average
    // Automatically logged to WP_MCP_AI_Logger
}

// Hook into anomaly detection
add_action( 'wp_mcp_ai_usage_anomaly_detected', function( $user_id, $tool_slug, $tokens, $avg_hourly ) {
    // Send alert, throttle user, or log to external system
    error_log( "User {$user_id} anomaly: {$tokens} tokens (avg: {$avg_hourly})" );
}, 10, 4 );

// Tier changes are automatically logged, but you can hook into it
add_action( 'wp_mcp_ai_user_tier_changed', function( $user_id, $old_tier, $new_tier, $expires ) {
    // Custom handling of tier changes
    error_log( "User {$user_id} tier changed from {$old_tier} to {$new_tier}" );
}, 10, 4 );

// Create database indexes for performance (typically in activation hook)
WP_MCP_AI_Tool_Token_Limits::create_database_indexes();
```

## Migration

### Automatic Migration

```php
// Run migration (admin only)
$result = WP_MCP_AI_Tool_Token_Limits::migrate_to_tiered_limits();

if ( $result['success'] ) {
    echo $result['message']; // "Successfully migrated X users..."
}
```

Migration assigns tiers based on user roles:
- Administrators → Enterprise tier
- Editors/Authors → Pro tier
- Contributors/Subscribers → Free tier

### Manual Migration

For custom tier assignments during migration, use:

```php
foreach ( $users as $user ) {
    $tier = determine_custom_tier( $user ); // Your logic
    WP_MCP_AI_Tool_Token_Limits::set_user_tier( $user->ID, $tier );
}
```

## Testing

### Run Tests

```bash
# All tests
vendor/bin/phpunit

# Tiered limits tests only
vendor/bin/phpunit tests/test-tiered-token-limits.php

# REST API tests only
vendor/bin/phpunit tests/test-rest-token-manager.php
```

### Test Coverage

- ✅ Tier assignment by role
- ✅ Custom tier overrides
- ✅ Tier expiration
- ✅ Limit calculations
- ✅ Tool multipliers
- ✅ Hourly tracking
- ✅ Bulk operations
- ✅ Forecasting
- ✅ REST endpoints
- ✅ Permission checks
- ✅ User access restrictions
- ✅ **PHASE 4:** CSV export format validation
- ✅ **PHASE 4:** CSV export permissions
- ✅ **PHASE 4:** CSV tier filtering
- ✅ **PHASE 4:** CSV tool filtering
- ✅ **PHASE 4:** CSV percentage calculations
- ✅ **PHASE 4:** Multi-user CSV export
- ✅ **PHASE 6:** Tier caching functionality
- ✅ **PHASE 6:** Cache invalidation on tier update
- ✅ **PHASE 6:** Anomaly detection with normal usage
- ✅ **PHASE 6:** Anomaly detection with usage spikes
- ✅ **PHASE 6:** Anomaly detection with insufficient data
- ✅ **PHASE 6:** Audit logging for tier changes
- ✅ **PHASE 6:** Database index creation and idempotency

## Performance Considerations

### Memory Usage

- Hourly data adds ~5-10% to user meta size with 7 days retention
- Daily cleanup prevents unbounded growth
- Automatic purging of old data

### Query Performance

- Tier lookups use simple user meta queries
- **PHASE 6:** WordPress object cache reduces tier lookup queries (1-hour TTL)
- **PHASE 6:** Database indexes on meta_key and user_id improve query speed
- Forecasting calculations are in-memory only
- Bulk operations batch user meta updates

### Implemented Optimizations (Phase 6)

1. ✅ WordPress object caching for tier lookups (`get_user_tier_cached()`)
2. ✅ Database indexes on tier user meta (`create_database_indexes()`)
3. ✅ Automatic cache invalidation on tier updates
4. ✅ Anomaly detection integrated into existing usage recording flow

### Future Optimization Opportunities

1. Add transient caching for forecasting calculations
2. Add background processing for bulk tier assignments (1000+ users)
3. Implement query result caching for admin dashboard
4. Add Redis/Memcached support for high-traffic sites

## Security Considerations

### Access Control

- Tier modifications require `manage_options` capability
- Users can only access their own data via REST API
- Administrators can access all user data

### Input Validation

- All tier values validated against enum
- User IDs sanitized with `absint()`
- Tool slugs sanitized with `sanitize_key()`
- Expiry dates validated before conversion

### Audit Trail

- **PHASE 6:** Tier changes logged via `WP_MCP_AI_Logger` with full context
- **PHASE 6:** Audit logs include admin ID, IP address, user agent
- **PHASE 6:** Anomaly detection logs include tokens, averages, multipliers
- Bulk operations logged with results
- Alert sending logged with forecast data

### Anomaly Detection (Phase 6)

- **PHASE 6:** 5x threshold for detecting unusual usage spikes
- **PHASE 6:** Automatic logging of anomalous patterns
- **PHASE 6:** `wp_mcp_ai_usage_anomaly_detected` action hook for custom responses
- **PHASE 6:** Baseline requires at least 1 hour of usage history

## Backward Compatibility

### Maintained Compatibility

- Old `get_tool_limit()` method still works
- Existing daily tracking continues
- Filter hooks remain unchanged (with additions)
- Old usage data structure supported

### Migration Path

- Automatic migration available
- No data loss during migration
- Gradual rollout supported via filters

## Known Limitations

1. Forecasting requires 24+ hours of data
2. Alert system limited to email notifications
3. No real-time UI updates (requires page refresh)
4. Bulk operations not paginated (may timeout with 1000+ users)

## Next Phase: Advanced Analytics & Visualization (Phase 7)

**Status**: 📋 Planning Phase  
**Documentation**: See `docs/PHASE-7-ANALYTICS-PLAN.md` for complete specifications  
**Target Release**: v1.2.0  
**Estimated Timeline**: 8-10 weeks

Phase 7 focuses on transforming raw token usage data into actionable insights through advanced analytics and visualization:

### Phase 7 Planned Features

1. ~~**Caching Layer**: WP object caching for tier lookups~~ ✅ COMPLETED (Phase 6)
2. ~~**Anomaly Detection**: Flag unusual usage patterns~~ ✅ COMPLETED (Phase 6)
3. **Chart.js Integration & Dashboards** 📋 PHASE 7
   - Interactive line, bar, pie, and gauge charts
   - Real-time usage visualization
   - Responsive mobile-friendly design
   - Export charts as PNG

4. **Cost Attribution & ROI Tracking** 📋 PHASE 7
   - Provider-specific pricing models
   - Project-level cost attribution
   - ROI calculations based on productivity metrics
   - Budget alerts and forecasting

5. **Advanced Analytics** 📋 PHASE 7
   - Trend analysis with linear regression
   - Pattern recognition (daily, weekly, monthly cycles)
   - Comparative benchmarking across users/tools
   - Statistical analysis with confidence scores

6. **Automated Scheduled Reports** 📋 PHASE 7
   - Daily/weekly/monthly email reports
   - Customizable templates (HTML/PDF)
   - User opt-in preferences
   - Comprehensive usage summaries

7. **Enhanced Anomaly Detection** 📋 PHASE 7
   - Statistical Z-score analysis (>3σ)
   - Temporal pattern anomalies
   - Geolocation-based detection
   - Automated severity-based responses

8. **Tier Adjustment Recommendations** 📋 PHASE 7
   - AI-powered upgrade/downgrade suggestions
   - Confidence scoring and reasoning
   - One-click tier adjustments
   - Cost-benefit analysis

### Future Enhancements (Phase 8+)

Planned for releases beyond v1.2.0:

9. **Multi-tenancy**: Organization-level token pools
10. **Token Marketplace**: Buy/transfer tokens between users
11. **Push Notifications**: Real-time mobile alerts
12. **Machine Learning Forecasting**: TensorFlow.js integration
13. **Mobile App**: iOS/Android management apps
14. **Webhook Integration**: Slack, Zapier, custom webhooks

## Completed Features (v1.1.0)

✅ **Phase 1:** Core tiered limit system (Free, Pro, Enterprise)  
✅ **Phase 2:** Hourly usage tracking with 7-day retention  
✅ **Phase 3:** Usage forecasting and email alerts  
✅ **Phase 4:** Complete admin UI enhancements:
  - CSV export with filtering
  - Bulk tier management UI
  - User details expansion
  - Enhanced AJAX handlers  
✅ **Phase 5:** REST API endpoints for external integration  
✅ **Phase 6:** Performance & Security enhancements:
  - WordPress object caching for tier lookups
  - Anomaly detection (5x threshold)
  - Audit logging for tier changes
  - Database indexing optimization

## Documentation

### Related Documentation

- `docs/TOKEN-MANAGER-ENHANCEMENT-PLAN.md` - Full enhancement specification (Phases 1-6)
- `docs/PHASE-7-ANALYTICS-PLAN.md` - **Phase 7 detailed planning document** ⭐ NEW
- `docs/QUICK-REFERENCE-TOKEN-ENHANCEMENTS.md` - Quick start guide
- `docs/token-management.md` - Original token management docs
- `docs/token-counting.md` - Token counting reference

### Filter/Action Reference

See `docs/TOKEN-MANAGER-ENHANCEMENT-PLAN.md` for complete filter and action hook documentation.

## Support

For issues or questions:

1. Check the documentation in `docs/`
2. Review test files for usage examples
3. Open an issue on GitHub
4. Contact the WP oOS development team

## Credits

**Implementation Date**: 2025-11-11  
**Based On**: TOKEN-MANAGER-ENHANCEMENT-PLAN.md  
**Repository**: nvdigitalsolutions/mcp-ai-wpoos  
**Contributors**: GitHub Copilot Workspace, WP oOS Development Team

---

**Status**: ✅ Phases 1-6 Complete | 📋 Phase 7 Planned  
**Phase 6 Completion Date**: 2025-11-11  
**Phase 7 Planning Date**: 2025-11-12  
**Current Version Features**: Tiered limits, hourly tracking, forecasting, admin UI, REST API, caching, anomaly detection, audit logging, database optimization  
**Next Phase**: Advanced Analytics & Visualization (see `docs/PHASE-7-ANALYTICS-PLAN.md`)
