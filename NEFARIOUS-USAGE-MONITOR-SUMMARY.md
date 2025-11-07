# Nefarious Usage Monitor Implementation Summary

## Overview

This implementation adds a comprehensive security monitoring system to WP Open Operator System (WP oOS) that automatically detects suspicious usage patterns and disables tools when threats are identified.

## Requirements Met

✅ **Core Integration**: Monitor is loaded early in the plugin bootstrap, before all other components  
✅ **Auto-Shutdown**: Tools are automatically disabled when security violations exceed the configured threshold  
✅ **Admin Interface**: Full management UI integrated into existing settings page  
✅ **Test Coverage**: Comprehensive PHPUnit test suite with 15 test cases  

## Architecture

### 1. Core Monitor Class
**File**: `includes/class-wp-mcp-ai-nefarious-usage-monitor.php`

- **Singleton Pattern**: Ensures single instance runs globally
- **Early Loading**: Included at line 168 of `wp-mcp-ai.php`, right after logger
- **First Initialization**: Initialized at the start of `WP_MCP_AI::bootstrap()` method

### 2. Integration Points

The monitor hooks into three critical points:

```php
// Blocks tool execution during shutdown
add_filter( 'wp_mcp_ai_can_execute_tool', array( $this, 'check_tool_execution' ), 1, 3 );

// Monitors tool usage for suspicious patterns
add_action( 'wp_mcp_ai_tool_executed', array( $this, 'monitor_tool_execution' ), 10, 4 );

// Monitors chat requests for threats
add_action( 'wp_mcp_ai_before_chat_request', array( $this, 'monitor_chat_request' ), 1, 2 );
```

### 3. Admin Interface
**File**: `includes/admin/class-wp-mcp-ai-security-monitor-admin.php`

- Handles settings registration and sanitization
- Processes "Clear Shutdown" and "Clear Violations" actions
- Integrates with WordPress admin_post hooks

## Detection Patterns

The monitor scans for these malicious patterns:

### Phishing
- `verify.*account.*immediately`
- `suspended.*account.*click`
- `urgent.*action.*required`
- `confirm.*identity.*now`

### Credential Harvesting
- `enter.*password.*below`
- `provide.*credit.*card`
- `social.*security.*number`

### Script Injection / Malware
- `<script[^>]*>`
- `eval\s*\(`
- `base64_decode`
- `system\s*\(`
- `exec\s*\(`
- `shell_exec`

### SQL Injection
- `union.*select.*from`
- `drop.*table`
- `delete.*from.*where`

### Spam
- `buy.*now.*limited.*time`
- `click.*here.*free.*money`
- `congratulations.*won`

## Security Flow

```
1. User/System attempts action
   ↓
2. Monitor checks if emergency shutdown is active
   ↓ (if active)
   → Block action, return error
   ↓ (if not active)
3. Monitor scans content for suspicious patterns
   ↓ (if patterns found)
4. Record violation with full context
   ↓
5. Check if violations exceed threshold (default: 5 in 5 minutes)
   ↓ (if exceeded)
6. Trigger emergency shutdown
   ↓
7. Send admin email notification
   ↓
8. All subsequent tool executions blocked
   ↓
9. All subsequent chat requests blocked (503 error)
   ↓
10. Admin must manually clear shutdown from settings
```

## Configuration

### Default Thresholds

- **Max Requests Per Minute**: 60
- **Max Tools Per Hour**: 500
- **Violation Threshold**: 5
- **Violation Window**: 5 minutes

### Configurable via Admin UI

Administrators can adjust all thresholds from the "Security Monitoring" section in WP oOS Settings:

1. Enable/disable monitoring
2. Enable/disable auto-shutdown
3. Adjust rate limits
4. View violation history
5. Clear violations
6. Clear emergency shutdown

## Data Storage

### Violations
**Option Key**: `wp_mcp_ai_nefarious_violations`

```php
array(
    'type'      => 'suspicious_content',
    'message'   => 'Suspicious content detected...',
    'details'   => array( /* context data */ ),
    'timestamp' => '2025-11-07 04:55:22',
    'user_id'   => 1,
    'ip'        => '192.168.1.1',
)
```

### Emergency Shutdown
**Option Key**: `wp_mcp_ai_emergency_shutdown`

```php
array(
    'active'               => true,
    'triggered_at'         => '2025-11-07 04:55:22',
    'triggering_violation' => array( /* violation data */ ),
)
```

### Monitor Settings
**Option Key**: `wp_mcp_ai_nefarious_monitor_settings`

```php
array(
    'enabled'                 => true,
    'auto_shutdown_enabled'   => true,
    'max_requests_per_minute' => 60,
    'max_tools_per_hour'      => 500,
    'violation_threshold'     => 5,
    'suspicious_patterns'     => array( /* regex patterns */ ),
    'notify_admin_email'      => true,
)
```

### Rate Limiting
**Transient Keys**:
- `wp_mcp_ai_rate_limit_user_{user_id}` (1 minute TTL)
- `wp_mcp_ai_rate_limit_guest` (1 minute TTL)
- `wp_mcp_ai_tool_usage_count` (1 hour TTL)
- `wp_mcp_ai_messaging_count` (1 minute TTL)

## Test Coverage

**File**: `tests/test-nefarious-usage-monitor.php`

### Test Cases

1. ✅ Monitor initializes with default settings
2. ✅ Detects phishing patterns
3. ✅ Detects script injection
4. ✅ Does not flag normal content
5. ✅ Records violations
6. ✅ Triggers emergency shutdown after threshold
7. ✅ Shutdown blocks tool execution
8. ✅ Can clear emergency shutdown
9. ✅ Can clear violations
10. ✅ Can update settings
11. ✅ Detects SQL injection
12. ✅ Scans array content
13. ✅ Disabled monitor does not interfere
14. ✅ Counts recent violations correctly
15. ✅ Array/object content scanning works

## Usage Examples

### Programmatic Access

```php
// Get monitor instance
$monitor = WP_MCP_AI_Nefarious_Usage_Monitor::get_instance();

// Check if shutdown is active
if ( $monitor->is_emergency_shutdown_active() ) {
    // Handle shutdown state
}

// Get violations
$violations = $monitor->get_violations();

// Clear shutdown (requires admin capability check)
$monitor->clear_emergency_shutdown();

// Clear violations (requires admin capability check)
$monitor->clear_violations();

// Update settings
$monitor->update_settings( array(
    'enabled'                 => true,
    'max_requests_per_minute' => 100,
) );
```

### Admin Interface

1. Navigate to **Settings → WP oOS**
2. Scroll to **Security Monitoring** section
3. Configure thresholds and enable/disable monitoring
4. View violation history in real-time
5. Clear shutdown or violations as needed

## Email Notifications

When emergency shutdown triggers, an email is sent to the site administrator:

**Subject**: `[{Site Name}] WP oOS Emergency Shutdown Activated`

**Body**:
```
The WP Open Operator System has been automatically shut down due to suspicious activity.

Site: {site_url}
Violation Type: {type}
Message: {message}

Please review the security logs and clear the shutdown from the plugin settings page if this was a false positive.
```

## Admin Notices

### Emergency Shutdown Active
Displays on all admin pages when shutdown is active:

> ⚠️ **WP Open Operator System: Emergency Shutdown Active**
> 
> The AI Assistant has been automatically disabled due to suspicious activity. [Review and clear shutdown](#)
>
> *{Triggering violation message}*

### Violations Detected
Displays when violations exist but shutdown not active:

> ⚠️ **WP Open Operator System: Security Violations Detected**
> 
> {N} security violations detected in the past hour. [View details](#)

## Security Benefits

1. **Prevents Phishing**: Blocks AI from being used to craft phishing messages
2. **Stops Injection Attacks**: Detects and blocks script/SQL injection attempts
3. **Rate Limiting**: Prevents DDOS-like abuse and excessive API usage
4. **Spam Prevention**: Limits messaging tool abuse
5. **Audit Trail**: Full logging of all violations with IP and user tracking
6. **Automatic Protection**: No manual intervention required for threat response
7. **Admin Control**: Full oversight and management capability
8. **Email Alerts**: Immediate notification of critical security events

## Performance Considerations

- **Pattern Matching**: Uses efficient regex compilation
- **Transient Caching**: Minimizes database queries for rate limiting
- **Selective Logging**: Only logs when logging is enabled
- **Violation Limit**: Keeps only last 100 violations to prevent database bloat
- **Early Returns**: Checks shutdown state before expensive pattern matching

## Future Enhancements

Potential improvements for future versions:

1. **IP Blocking**: Integrate with WordPress IP blocking
2. **Machine Learning**: Train model on violation patterns
3. **Custom Patterns**: Allow admins to add custom regex patterns
4. **Webhook Integration**: Notify external security systems
5. **GeoIP Blocking**: Block requests from specific countries
6. **Whitelist System**: Allow trusted users to bypass monitoring
7. **Scheduled Reports**: Weekly security digest emails
8. **Integration with Security Plugins**: Wordfence, Sucuri, etc.

## Compatibility

- **WordPress**: 6.0+
- **PHP**: 7.4+
- **Dependencies**: None (uses only WordPress core functions)
- **Multisite**: Compatible
- **Hooks**: Uses standard WordPress filter/action system

## Maintenance

### Regular Tasks

1. Review violations weekly
2. Adjust thresholds based on usage patterns
3. Clear old violations monthly
4. Test shutdown/recovery process quarterly
5. Update patterns as new threats emerge

### Troubleshooting

**Q**: False positives triggering shutdown?
**A**: Increase `violation_threshold` or adjust patterns

**Q**: Legitimate traffic blocked?
**A**: Increase rate limits or disable monitoring temporarily

**Q**: Shutdown won't clear?
**A**: Delete `wp_mcp_ai_emergency_shutdown` option via database

**Q**: Violations not recording?
**A**: Ensure monitoring is enabled and logging is active

## Code Quality

- ✅ Follows WordPress Coding Standards
- ✅ All strings internationalized
- ✅ Input sanitization and output escaping
- ✅ Capability checks for admin actions
- ✅ Nonce verification for forms
- ✅ PHPDoc comments for all methods
- ✅ No PHP syntax errors
- ✅ Comprehensive test coverage

## Files Modified/Created

### Created
1. `includes/class-wp-mcp-ai-nefarious-usage-monitor.php` (654 lines)
2. `includes/admin/class-wp-mcp-ai-security-monitor-admin.php` (107 lines)
3. `tests/test-nefarious-usage-monitor.php` (330 lines)
4. `NEFARIOUS-USAGE-MONITOR-SUMMARY.md` (this file)

### Modified
1. `wp-mcp-ai.php` (added require statements and initialization)
2. `includes/admin/class-wp-mcp-ai-admin-settings.php` (added settings section)

## Conclusion

This implementation provides robust, automatic protection against nefarious usage of the AI assistant plugin while maintaining full administrative control. The system is production-ready, thoroughly tested, and follows WordPress best practices.
