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

### Phase 6: Performance & Security 🔄 PARTIALLY COMPLETED

**Completed**
- Migration method for existing users
- Comprehensive unit test coverage
- Backward compatibility maintained

**Pending**
- Caching for tier lookups
- Anomaly detection for unusual usage patterns
- Audit logging for tier changes
- Database indexing optimization

## Files Changed

### Modified Files

1. **`includes/class-wp-mcp-ai-tool-token-limits.php`**
   - Added tier system constants and configuration
   - Implemented tier management methods
   - Added hourly tracking support
   - Implemented forecasting and alert system
   - Added bulk operations and migration support
   - **NEW:** Added `export_usage_report()` method for CSV export
   - **NEW:** Added `get_filtered_users()` helper method for filtering
   - ~650 lines of new code

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
   - ~250 lines

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
- **NEW:** `wp_ajax_wp_mcp_ai_export_token_usage_csv` - AJAX action for CSV export
- **NEW:** `wp_ajax_wp_mcp_ai_bulk_assign_tier` - AJAX action for bulk tier assignment

### Modified Actions

- `wp_mcp_ai_tool_token_limit_exceeded` - Now includes `$tier` parameter
- `wp_mcp_ai_hourly_forecast_check` - New cron hook
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
- ✅ **NEW:** CSV export format validation
- ✅ **NEW:** CSV export permissions
- ✅ **NEW:** CSV tier filtering
- ✅ **NEW:** CSV tool filtering
- ✅ **NEW:** CSV percentage calculations
- ✅ **NEW:** Multi-user CSV export

## Performance Considerations

### Memory Usage

- Hourly data adds ~5-10% to user meta size with 7 days retention
- Daily cleanup prevents unbounded growth
- Automatic purging of old data

### Query Performance

- Tier lookups use simple user meta queries
- Forecasting calculations are in-memory only
- Bulk operations batch user meta updates

### Recommended Optimizations (Future)

1. Add WordPress object caching for tier lookups
2. Create database indexes on tier user meta
3. Implement transient caching for frequently accessed data
4. Add background processing for bulk operations

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

- Tier changes logged via `WP_MCP_AI_Logger`
- Bulk operations logged with results
- Alert sending logged with forecast data

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

## Future Enhancements

Planned for future releases:

1. **Caching Layer**: WP object caching for tier lookups
2. **Anomaly Detection**: Flag unusual usage patterns
3. **Advanced Analytics**: Charts, trends, comparisons (Chart.js foundation ready)
4. **Multi-tenancy**: Organization-level token pools
5. **Token Marketplace**: Buy/transfer tokens
6. **Push Notifications**: Real-time limit alerts
7. **Machine Learning**: AI-powered forecasting
8. **Advanced Filtering**: Date range filters for CSV export
9. **Scheduled Exports**: Automated daily/weekly CSV reports
10. **Usage Visualizations**: Dashboard charts using Chart.js integration

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
🔄 **Phase 6:** Performance & Security (partial - caching pending)

## Documentation

### Related Documentation

- `docs/TOKEN-MANAGER-ENHANCEMENT-PLAN.md` - Full enhancement specification
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
**Repository**: nvdigitalsolutions/wp-mcp-ai  
**Contributors**: GitHub Copilot Workspace, WP oOS Development Team

---

**Status**: Phase 4 Complete - Full Admin UI Implementation  
**Remaining**: Performance optimizations (Phase 6), Documentation updates (Phase 7)
