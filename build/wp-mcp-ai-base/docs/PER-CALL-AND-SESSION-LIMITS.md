# Per-Call and Per-Session Token Limits

## Overview

The orchestration layer now supports granular token limit controls at two levels:

1. **Per-Call Limits**: Maximum tokens a single tool execution can consume
2. **Per-Session Limits**: Maximum tokens a chat session can use cumulatively

These limits work in conjunction with existing daily per-user limits to provide comprehensive budget management.

## Per-Call Token Limits

### Purpose

Per-call limits prevent individual tool executions from consuming excessive tokens, protecting against:
- Malformed queries that return unexpectedly large results
- Tools configured with overly broad parameters
- API abuse through intentionally large requests

### Configuration

Navigate to **Settings → WP oOS → Orchestration → Thresholds**

- **Enable Per-Call Token Limits**: Toggle to enable/disable
- **Per-Call Token Limit**: Slider (0-100,000 tokens)
  - Default: 10,000 tokens
  - Set to 0 for unlimited

### Behavior

Per-call limits are **non-blocking** and serve primarily for monitoring:

1. Tool executes and returns result
2. System estimates token count from result
3. If tokens exceed per-call limit:
   - Event is logged via `WP_MCP_AI_Logger`
   - Action hook `wp_mcp_ai_per_call_limit_exceeded` fires
   - Execution continues normally (non-blocking)

### Use Cases

- **Monitoring**: Identify tools consistently returning large results
- **Alerting**: Notify admins of unusual usage patterns
- **Analytics**: Track which tools consume the most tokens
- **Capacity Planning**: Understand typical token consumption patterns

### Example

```php
// Enable per-call limits.
update_option( 'wp_mcp_ai_enable_per_call_limits', true );
update_option( 'wp_mcp_ai_per_call_token_limit', 5000 );

// Hook into limit exceeded events.
add_action( 'wp_mcp_ai_per_call_limit_exceeded', function( $user_id, $tool_slug, $tokens, $limit, $context ) {
    // Send notification to admin.
    wp_mail(
        get_option( 'admin_email' ),
        'High Token Usage Alert',
        sprintf(
            'User %d executed %s using %d tokens (limit: %d)',
            $user_id,
            $tool_slug,
            $tokens,
            $limit
        )
    );
}, 10, 5 );
```

## Per-Session Token Limits

### Purpose

Per-session limits control the total token budget for an entire chat session, preventing:
- Runaway agentic loops consuming excessive resources
- Long conversations depleting daily budgets too quickly
- Multiple expensive tool calls in a single session
- Fair resource distribution across concurrent sessions

### Configuration

Navigate to **Settings → WP oOS → Orchestration → Thresholds**

- **Enable Per-Session Token Limits**: Toggle to enable/disable
- **Per-Session Token Limit**: Slider (0-500,000 tokens)
  - Default: 50,000 tokens
  - Set to 0 for unlimited

### Behavior

Per-session limits are **blocking** and enforce strict budget control:

1. Before each tool execution, system checks session token usage
2. If session has exceeded limit:
   - Event is logged
   - Action hook `wp_mcp_ai_per_session_limit_exceeded` fires
   - Exception is thrown (blocks execution)
   - Clear error message returned to user

3. After each tool execution:
   - Tokens are estimated and recorded
   - Session totals are updated
   - Per-tool breakdown is maintained

### Session Tracking

Sessions are tracked using the `session_id` from the execution context:

```php
$context = array(
    'user_id'    => 123,
    'session_id' => 'chat-abc123-xyz789',
);
```

Session data is stored as transients with 24-hour expiration.

### Session Data Structure

```php
array(
    'total_tokens' => 35000,
    'tool_calls'   => array(
        'search_content'   => array(
            'count'  => 3,
            'tokens' => 12000,
        ),
        'run_crawl4ai_job' => array(
            'count'  => 2,
            'tokens' => 23000,
        ),
    ),
    'started_at'   => 1699564800,
);
```

### Example

```php
// Enable per-session limits.
update_option( 'wp_mcp_ai_enable_per_session_limits', true );
update_option( 'wp_mcp_ai_per_session_token_limit', 25000 );

// Check session usage before expensive operation.
$session_id = 'current-session-id';
$usage      = WP_MCP_AI_Tool_Token_Limits::get_session_usage( $user_id, $session_id );

if ( $usage > 20000 ) {
    // Warn user they're approaching limit.
    echo "You've used {$usage} of 25,000 tokens. Consider starting a new session.";
}

// Get detailed breakdown.
$session_data = WP_MCP_AI_Tool_Token_Limits::get_session_data( $user_id, $session_id );
foreach ( $session_data['tool_calls'] as $tool => $stats ) {
    echo "{$tool}: {$stats['count']} calls, {$stats['tokens']} tokens\n";
}
```

## How Limits Work Together

The orchestration layer enforces limits in this order:

1. **Per-Session Limit** (most restrictive, checked first)
2. **Daily Per-User Per-Tool Limit** (existing functionality)
3. **Per-Call Limit** (logging only, checked after execution)

This ensures the most restrictive limits are enforced first while still maintaining comprehensive monitoring.

### Example Scenario

Configuration:
- Per-call limit: 10,000 tokens
- Per-session limit: 50,000 tokens
- Daily limit (crawl4ai): 200,000 tokens

User makes multiple crawl4ai calls in one session:

| Call | Tokens | Session Total | Daily Total | Result |
|------|--------|--------------|-------------|---------|
| 1 | 8,000 | 8,000 | 8,000 | ✓ Success |
| 2 | 12,000 | 20,000 | 20,000 | ✓ Success, per-call warning logged |
| 3 | 9,000 | 29,000 | 29,000 | ✓ Success |
| 4 | 8,500 | 37,500 | 37,500 | ✓ Success |
| 5 | 11,000 | 48,500 | 48,500 | ✓ Success, per-call warning logged |
| 6 | 7,000 | 55,500 | 55,500 | ✗ **Blocked** - Session limit exceeded |

At call #6, the session limit (50,000) is exceeded, so execution is blocked even though:
- Daily limit (200,000) still has plenty of budget
- Individual call (7,000) is under per-call limit

## API Reference

### Check Session Usage

```php
/**
 * Get total tokens used in a session.
 *
 * @param int    $user_id    User ID.
 * @param string $session_id Session identifier.
 * @return int Total tokens used.
 */
$usage = WP_MCP_AI_Tool_Token_Limits::get_session_usage( $user_id, $session_id );
```

### Get Session Details

```php
/**
 * Get detailed session data.
 *
 * @param int    $user_id    User ID.
 * @param string $session_id Session identifier.
 * @return array|null Session data or null if not found.
 */
$data = WP_MCP_AI_Tool_Token_Limits::get_session_data( $user_id, $session_id );
```

### Reset Session

```php
/**
 * Clear session usage data.
 *
 * @param int    $user_id    User ID.
 * @param string $session_id Session identifier.
 * @return bool True on success.
 */
WP_MCP_AI_Tool_Token_Limits::reset_session_usage( $user_id, $session_id );
```

## Hooks and Filters

### Action: wp_mcp_ai_per_call_limit_exceeded

Fires when a single tool call exceeds the per-call token limit.

```php
add_action( 'wp_mcp_ai_per_call_limit_exceeded', function( $user_id, $tool_slug, $tokens, $limit, $context ) {
    // Custom handling
}, 10, 5 );
```

**Parameters:**
- `$user_id` (int) - User ID
- `$tool_slug` (string) - Tool identifier
- `$tokens` (int) - Tokens used in this call
- `$limit` (int) - Per-call token limit
- `$context` (array) - Execution context

### Action: wp_mcp_ai_per_session_limit_exceeded

Fires when a session exceeds its token limit.

```php
add_action( 'wp_mcp_ai_per_session_limit_exceeded', function( $user_id, $session_id, $usage, $limit ) {
    // Custom handling
}, 10, 4 );
```

**Parameters:**
- `$user_id` (int) - User ID
- `$session_id` (string) - Session identifier
- `$usage` (int) - Current session usage
- `$limit` (int) - Session token limit

### Filter: wp_mcp_ai_enforce_per_session_limits

Control whether to enforce per-session limits (blocking).

```php
add_filter( 'wp_mcp_ai_enforce_per_session_limits', function( $enforce, $user_id, $session_id, $usage, $limit ) {
    // Allow admins to bypass limits
    if ( user_can( $user_id, 'manage_options' ) ) {
        return false;
    }
    return $enforce;
}, 10, 5 );
```

**Parameters:**
- `$enforce` (bool) - Whether to enforce (default: true)
- `$user_id` (int) - User ID
- `$session_id` (string) - Session identifier
- `$usage` (int) - Current session usage
- `$limit` (int) - Session token limit

**Return:** bool - True to enforce, false to allow

## Logging

All limit-related events are logged when logging is enabled (**Settings → WP oOS → Enable Logging**).

### Per-Call Limit Exceeded

```json
{
  "event": "per_call_token_limit_exceeded",
  "message": "Single tool call exceeded per-call token limit.",
  "context": {
    "user_id": 1,
    "tool_slug": "run_crawl4ai_job",
    "tokens": 15000,
    "limit": 10000,
    "ratio": 1.5,
    "session_id": "chat-abc123"
  }
}
```

### Per-Session Limit Exceeded

```json
{
  "event": "per_session_token_limit_exceeded",
  "message": "Session exceeded per-session token limit.",
  "context": {
    "user_id": 1,
    "session_id": "chat-abc123",
    "tool_slug": "search_content",
    "usage": 52000,
    "limit": 50000
  }
}
```

## Best Practices

### For Site Administrators

1. **Start Conservative**: Begin with limits enabled and gradually increase based on actual usage
2. **Monitor Logs**: Review per-call warnings to understand typical usage patterns
3. **Set Realistic Session Limits**: Consider typical conversation length (10-20 exchanges)
4. **Communicate Limits**: Inform users about session budgets and how to check usage
5. **Use Role-Based Limits**: Apply different limits for different user roles via filters

### For Plugin Developers

1. **Provide Session Context**: Always pass `session_id` in tool execution context
2. **Handle Limit Exceptions**: Catch session limit exceptions and show friendly error messages
3. **Show Usage Indicators**: Display current session usage in chat UI
4. **Offer Session Reset**: Provide "Start New Session" button when approaching limits
5. **Paginate Results**: For tools returning large datasets, implement pagination

### For End Users

1. **Monitor Usage**: Check session token count before expensive operations
2. **Start Fresh**: Begin new session if approaching limit
3. **Be Specific**: More precise queries typically use fewer tokens
4. **Review History**: Check which tools consumed the most tokens

## Troubleshooting

### Session Limits Not Working

**Check:**
1. Is `enable_per_session_limits` enabled?
2. Is `session_id` being passed in execution context?
3. Are transients working on your server?
4. Check logs for `per_session_token_limit_exceeded` events

### Sessions Not Persisting

**Causes:**
- Object caching conflict
- Transient API not working
- Session ID not consistent across requests

**Solutions:**
```php
// Debug: Check if session data exists
$data = get_transient( "wp_mcp_ai_session_{$user_id}_{$session_id}" );
var_dump( $data );

// Clear all session data for user
global $wpdb;
$wpdb->query( $wpdb->prepare(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
    $wpdb->esc_like( '_transient_wp_mcp_ai_session_' . $user_id ) . '%'
) );
```

### Per-Call Warnings Not Appearing

**Check:**
1. Is `enable_per_call_limits` enabled?
2. Is logging enabled?
3. Are you hooked into the action?
4. Is limit set appropriately (not 0)?

## Security Considerations

### Session ID Validation

Session IDs are sanitized using `sanitize_text_field()` to prevent injection attacks.

### User Isolation

Session data is keyed by both user ID and session ID, preventing:
- Cross-user data access
- Session hijacking
- Data leakage

### Token Estimation

Token estimation is conservative (4 chars = 1 token) to avoid underreporting usage.

### Transient Expiration

Session data automatically expires after 24 hours, preventing:
- Indefinite data accumulation
- Stale session tracking
- Database bloat

## Performance Impact

### Per-Call Limits

- **Token Estimation**: ~0.1ms per result
- **Logging**: ~0.5ms when limit exceeded
- **Action Hooks**: Depends on hooked functions

### Per-Session Limits

- **Session Read**: ~0.3ms (single transient query)
- **Session Write**: ~0.5ms (transient update)
- **Session Check**: ~0.2ms (before execution)

Total overhead per tool execution: **~1-2ms**

Session data is stored as transients (options table) which is typically cached by WordPress, minimizing database queries.

## Future Enhancements

Planned improvements:

1. **Session Budget Warnings**: Alert users at 80% and 90% of limit
2. **Session Analytics Dashboard**: Visual breakdown of session token usage
3. **Per-Tool Session Limits**: Different limits for different tools within a session
4. **Budget Rollover**: Carry unused session budget to next interaction
5. **Tiered Session Limits**: Different limits for different user roles
6. **Session Budget Pools**: Share quotas across user teams

## Related Documentation

- [Orchestration Layer Architecture](./ORCHESTRATION-LAYER-ARCHITECTURE.md)
- [Tool Token Limits](./TOOL-TOKEN-LIMITS.md)
- [Budget Enforcement](./orchestration-budget-enforcement.md)
- [Resource Management](./RESOURCE-MANAGEMENT.md)

## Changelog

### Version 1.1.0 (2025-11-18)
- Added per-call token limits (non-blocking monitoring)
- Added per-session token limits (blocking enforcement)
- Added session usage tracking with tool breakdown
- Added new action hooks for limit exceeded events
- Added filter for session limit enforcement control
- Added comprehensive test suite (10 test cases)
- Added admin UI controls in orchestration settings
