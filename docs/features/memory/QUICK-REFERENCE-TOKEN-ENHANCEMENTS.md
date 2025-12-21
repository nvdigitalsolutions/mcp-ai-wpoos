# Quick Reference: Token Manager Enhancements

**Related Document:** [TOKEN-MANAGER-ENHANCEMENT-PLAN.md](TOKEN-MANAGER-ENHANCEMENT-PLAN.md)  
**Status:** Planning Phase  
**Last Updated:** 2025-11-11

## TL;DR - Key Changes

### Current System
- **Fixed Limit:** 100,000 tokens per user per day
- **Tracking:** Daily granularity only
- **Management:** Manual admin intervention
- **UI:** Basic statistics display

### Enhanced System
- **Tiered Limits:** 50k (Free), 200k (Pro), 1M (Enterprise)
- **Tracking:** Hourly + Daily granularity
- **Management:** Automated forecasting & alerts
- **UI:** Charts, analytics, bulk operations

## Quick Access Guide

### For Developers

#### Enable Tiered Limits
```php
// Assign custom tier to user
update_user_meta( $user_id, '_wp_mcp_ai_token_tier', 'pro' );

// Get user's current tier
$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );

// Get tier-based limit for tool
$limit = WP_MCP_AI_Tool_Token_Limits::get_user_tool_limit( $user_id, 'tool_slug' );
```

#### Access Usage Forecast
```php
// Get forecast for user/tool
$forecast = WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion( $user_id, 'tool_slug' );

if ( $forecast && $forecast['will_exceed'] ) {
    echo "Warning: Projected to exceed limit by {$forecast['projected_usage']} tokens";
}
```

#### Custom Tier Filters
```php
// Override tier for specific user
add_filter( 'wp_mcp_ai_default_user_tier', function( $tier, $user_id ) {
    if ( user_has_premium_subscription( $user_id ) ) {
        return 'enterprise';
    }
    return $tier;
}, 10, 2 );

// Custom tool multiplier
add_filter( 'wp_mcp_ai_tool_result_max_tokens', function( $max_tokens, $tool_slug, $tier, $context ) {
    if ( 'my_custom_tool' === $tool_slug ) {
        return $max_tokens * 3; // 3x multiplier
    }
    return $max_tokens;
}, 10, 4 );
```

### For Administrators

#### Bulk Assign Tiers
```php
// Via code
$user_ids = array( 1, 2, 3, 4, 5 );
$results = WP_MCP_AI_Tool_Token_Limits::bulk_set_user_tiers( 
    $user_ids, 
    'pro',
    '2025-12-31' // Optional expiry date
);

// Via Admin UI (planned)
// Navigate to: WP oOS → Token Manager → Bulk Actions
```

#### Export Usage Reports
```php
// Generate CSV report
$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report( array(
    'date_range' => '2025-11-01 to 2025-11-30',
    'tier'       => 'pro',
    'tool'       => 'run_crawl4ai_job',
) );

// Download as file
header( 'Content-Type: text/csv' );
header( 'Content-Disposition: attachment; filename="token-usage-report.csv"' );
echo $csv;
```

#### Monitor Alerts
```php
// Check if alert should be sent
if ( WP_MCP_AI_Tool_Token_Limits::should_send_limit_alert( $user_id, 'tool_slug' ) ) {
    $forecast = WP_MCP_AI_Tool_Token_Limits::forecast_limit_exhaustion( $user_id, 'tool_slug' );
    WP_MCP_AI_Tool_Token_Limits::send_limit_alert( $user_id, 'tool_slug', $forecast );
}
```

### For REST API Users

#### Get User Tier
```bash
GET /wp-json/mcp-ai/v1/users/123/token-tier
Authorization: Bearer <token>

Response:
{
  "user_id": 123,
  "tier": "pro",
  "limit": 200000,
  "tier_expires": null
}
```

#### Update User Tier
```bash
POST /wp-json/mcp-ai/v1/users/123/token-tier
Authorization: Bearer <admin-token>
Content-Type: application/json

{
  "tier": "enterprise",
  "expiry": "2025-12-31"
}
```

#### Get Usage Statistics
```bash
GET /wp-json/mcp-ai/v1/users/123/token-usage?tool=run_crawl4ai_job&timeframe=hourly
Authorization: Bearer <token>

Response:
{
  "user_id": 123,
  "tool": "run_crawl4ai_job",
  "timeframe": "hourly",
  "data": {
    "2025-11-11-14": 3500,
    "2025-11-11-15": 4200
  }
}
```

#### Get Usage Forecast
```bash
GET /wp-json/mcp-ai/v1/users/123/token-forecast?tool=run_crawl4ai_job
Authorization: Bearer <token>

Response:
{
  "will_exceed": true,
  "current_usage": 150000,
  "projected_usage": 210000,
  "limit": 200000,
  "remaining_tokens": 50000,
  "hours_until_reset": 8,
  "avg_hourly_usage": 7500,
  "confidence": 85
}
```

## Tier Comparison Table

| Feature | Free | Pro | Enterprise |
|---------|------|-----|------------|
| Daily Token Limit | 50,000 | 200,000 | 1,000,000 |
| Crawl4AI Limit | 100,000 | 400,000 | 2,000,000 |
| Hourly Tracking | ✅ | ✅ | ✅ |
| Usage Forecasting | ✅ | ✅ | ✅ |
| Email Alerts | ✅ | ✅ | ✅ |
| Priority Support | ❌ | ✅ | ✅ |
| API Access | Limited | ✅ | ✅ |
| Custom Limits | ❌ | ❌ | ✅ |

## Default Role Mappings

| WordPress Role | Default Tier |
|----------------|--------------|
| Subscriber | Free |
| Contributor | Free |
| Author | Pro |
| Editor | Pro |
| Administrator | Enterprise |

*Can be overridden with custom user meta*

## Migration Checklist

### Before Upgrade
- [ ] Backup database (especially `wp_usermeta`)
- [ ] Note current token limits
- [ ] Export current usage statistics
- [ ] Review custom filters/hooks

### After Upgrade
- [ ] Run migration script: `WP_MCP_AI_Tool_Token_Limits::migrate_to_tiered_limits()`
- [ ] Verify tier assignments for key users
- [ ] Test forecast accuracy
- [ ] Configure alert settings
- [ ] Update documentation

### Rollback Plan
```php
// Disable tiered limits if needed
add_filter( 'wp_mcp_ai_use_tiered_limits', '__return_false' );

// Or restore to v1.0 behavior
update_option( 'wp_mcp_ai_tiered_limits_migrated', false );
```

## Performance Tips

### Caching
```php
// Use cached tier lookups
$tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier_cached( $user_id );

// Preload tiers for bulk operations
wp_cache_add_multiple( $tier_data, 'wp_mcp_ai_tiers' );
```

### Database Optimization
```sql
-- Add recommended indexes
ALTER TABLE wp_usermeta 
ADD INDEX idx_wp_mcp_ai_token_tier (meta_key, meta_value(20));

-- Verify index usage
EXPLAIN SELECT user_id FROM wp_usermeta 
WHERE meta_key = '_wp_mcp_ai_token_tier' AND meta_value = 'pro';
```

## Troubleshooting

### User Not Receiving Alerts
```php
// Check alert transient
$alert_key = "wp_mcp_ai_limit_alert_{$user_id}_{$tool_slug}";
$last_alert = get_transient( $alert_key );
var_dump( $last_alert ); // Should be false if no recent alert

// Manually clear transient
delete_transient( $alert_key );
```

### Forecast Shows Low Confidence
```php
// Check hourly data availability
$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );
$hourly_count = count( $usage[ $tool_slug ]['hourly'] ?? array() );

// Need at least 24 hours for good confidence
if ( $hourly_count < 24 ) {
    echo "Insufficient data for accurate forecast. Have {$hourly_count} hours, need 24+.";
}
```

### Tier Not Applying
```php
// Debug tier assignment
$user = get_userdata( $user_id );
$custom_tier = get_user_meta( $user_id, '_wp_mcp_ai_token_tier', true );
$detected_tier = WP_MCP_AI_Tool_Token_Limits::get_user_tier( $user_id );

error_log( "User {$user_id} roles: " . implode( ', ', $user->roles ) );
error_log( "Custom tier: {$custom_tier}" );
error_log( "Detected tier: {$detected_tier}" );
```

## Security Notes

### Audit Trail
All tier changes are logged:
```php
// View recent tier changes
$events = get_option( 'wp_mcp_ai_recent_activity', array() );
foreach ( $events as $event ) {
    if ( 'token_tier_changed' === $event['event'] ) {
        // Review tier change details
    }
}
```

### Anomaly Detection
```php
// Check for unusual usage
if ( WP_MCP_AI_Tool_Token_Limits::detect_usage_anomaly( $user_id, 'tool_slug', $tokens ) ) {
    // Alert admin, require 2FA, etc.
}
```

## Support & Resources

- **Full Documentation:** [TOKEN-MANAGER-ENHANCEMENT-PLAN.md](TOKEN-MANAGER-ENHANCEMENT-PLAN.md)
- **API Reference:** [rest-api.md](reference/api/rest-api.md)
- **Token Counting:** [token-counting.md](token-counting.md)
- **Token Management:** [token-management.md](token-management.md)
- **GitHub Issues:** [Report a Bug](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)

---

**Quick Reference Version:** 1.0  
**Compatible With:** WP oOS v1.1.0+  
**Last Updated:** 2025-11-11
