# Tool Token Usage Limits

## Overview

The Tool Token Limits system provides granular control over token usage at the individual tool level. Unlike the existing usage tracker that monitors global token consumption per user/model, this system allows administrators to set specific daily token limits for different tools.

This is particularly useful for resource-intensive tools like Crawl4AI that may consume significantly more tokens than standard tools.

## Key Features

- **Per-Tool Configuration**: Set different token limits for each tool
- **Daily Usage Tracking**: Monitor token consumption per user per tool per day
- **Automatic Enforcement**: System logs when limits are exceeded (non-blocking by default)
- **Visual Dashboard**: Admin UI shows usage statistics and configurable limits
- **Automatic Cleanup**: Old usage data (30+ days) is automatically cleaned up
- **Event Hooks**: Integrates with WordPress action hooks for extensibility

## Architecture

### Core Class

**File**: `includes/class-wp-mcp-ai-tool-token-limits.php`

The `WP_MCP_AI_Tool_Token_Limits` class manages all aspects of per-tool token limiting:

- Token limit configuration (stored in WordPress options)
- Usage tracking (stored in user meta)
- Limit checking and enforcement
- Statistics aggregation
- Data cleanup

### Integration Points

The system automatically hooks into the tool execution pipeline:

```php
// Before tool execution - checks limits
add_action( 'wp_mcp_ai_before_tool_execution', array( __CLASS__, 'check_tool_limit' ), 5, 3 );

// After tool execution - records usage
add_action( 'wp_mcp_ai_after_tool_execution', array( __CLASS__, 'record_tool_usage' ), 10, 4 );
```

No changes to individual tools are required - the system intercepts all tool executions automatically.

## Default Limits

```php
const DEFAULT_GENERAL_LIMIT = 100000;   // 100k tokens per day for general tools
const DEFAULT_CRAWL4AI_LIMIT = 200000;  // 200k tokens per day for crawl4ai
```

These defaults can be overridden via the admin UI or programmatically.

## Usage

### Admin UI

Navigate to **Settings → WP oOS** and scroll to the "Tool Token Usage Limits" section.

**Features**:
- Configure limits for featured tools (Crawl4AI, General Tools)
- Visual usage indicators showing daily consumption vs limits
- Color-coded progress bars (green < 70%, yellow 70-90%, red > 90%)
- Usage statistics table showing all tools used by current user
- AJAX-powered save functionality

### Programmatic Usage

#### Get Tool Limit

```php
$limit = WP_MCP_AI_Tool_Token_Limits::get_tool_limit( 'run_crawl4ai_job' );
// Returns: int (e.g., 200000)
```

#### Set Tool Limit

```php
$success = WP_MCP_AI_Tool_Token_Limits::set_tool_limit( 'my_custom_tool', 50000 );
// Returns: bool
```

#### Check User's Daily Usage

```php
$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_daily_usage( $user_id, 'run_crawl4ai_job' );
// Returns: int (tokens used today)
```

#### Get All Usage for User

```php
$all_usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_usage( $user_id );
// Returns: array [
//   'tool_slug' => [
//     'total_tokens' => 15000,
//     'requests' => 5,
//     'first_used' => '2025-01-01 12:00:00',
//     'last_used' => '2025-01-07 15:30:00',
//     'daily' => [
//       '2025-01-07' => 3000,
//       '2025-01-06' => 5000,
//       ...
//     ]
//   ]
// ]
```

#### Get Tool Statistics (All Users)

```php
$stats = WP_MCP_AI_Tool_Token_Limits::get_tool_statistics( 'run_crawl4ai_job' );
// Returns: array [
//   'tool_slug' => 'run_crawl4ai_job',
//   'total_users' => 5,
//   'total_tokens' => 500000,
//   'total_requests' => 25,
//   'limit' => 200000
// ]
```

#### Reset User's Tool Usage

```php
// Reset specific tool
WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $user_id, 'run_crawl4ai_job' );

// Reset all tools for user
WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $user_id );
```

## Event Hooks

### wp_mcp_ai_tool_token_limit_exceeded

Fired when a user exceeds their daily limit for a tool.

```php
add_action( 'wp_mcp_ai_tool_token_limit_exceeded', function( $user_id, $tool_slug, $usage, $limit, $reset_time ) {
    // Send notification
    // Block tool execution
    // Log for analytics
}, 10, 5 );
```

**Parameters**:
- `$user_id` (int) - User ID
- `$tool_slug` (string) - Tool identifier
- `$usage` (int) - Current daily usage in tokens
- `$limit` (int) - Configured limit
- `$reset_time` (string) - Time when limit resets (midnight GMT)

### wp_mcp_ai_tool_token_usage_recorded

Fired after tool token usage has been recorded.

```php
add_action( 'wp_mcp_ai_tool_token_usage_recorded', function( $user_id, $tool_slug, $tokens, $context ) {
    // Log to external analytics
    // Trigger alerts if nearing limit
}, 10, 4 );
```

**Parameters**:
- `$user_id` (int) - User ID
- `$tool_slug` (string) - Tool identifier
- `$tokens` (int) - Tokens used in this execution
- `$context` (array) - Execution context

## Data Storage

### Tool Limits

**Location**: WordPress options table  
**Key**: `wp_mcp_ai_tool_token_limits`  
**Format**:
```php
array(
    'run_crawl4ai_job' => 200000,
    'my_custom_tool' => 50000,
)
```

### User Usage

**Location**: User meta table  
**Key**: `_wp_mcp_ai_tool_token_usage`  
**Format**:
```php
array(
    'tool_slug' => array(
        'total_tokens' => 15000,
        'requests' => 5,
        'first_used' => '2025-01-01 12:00:00',
        'last_used' => '2025-01-07 15:30:00',
        'daily' => array(
            '2025-01-07' => 3000,
            '2025-01-06' => 5000,
            // ...
        ),
    ),
)
```

## Token Estimation

The system estimates token usage based on result size:

```php
protected static function estimate_tokens( $result ) {
    if ( is_string( $result ) ) {
        return max( 1, (int) ( strlen( $result ) / 4 ) );
    }
    
    if ( is_array( $result ) || is_object( $result ) ) {
        $json = wp_json_encode( $result );
        return max( 1, (int) ( strlen( $json ) / 4 ) );
    }
    
    return 1;
}
```

**Heuristic**: ~4 characters = 1 token (industry standard approximation)

## Automatic Cleanup

A WordPress cron job (`wp_mcp_ai_daily_cleanup`) automatically removes usage data older than 30 days:

```php
add_action( 'wp_mcp_ai_daily_cleanup', array( __CLASS__, 'cleanup_expired_usage' ) );
```

This prevents the database from growing indefinitely while maintaining recent trend data.

## Enforcement Strategy

The current implementation is **non-blocking** by default:

1. System checks limit before tool execution
2. If exceeded, logs event via `WP_MCP_AI_Logger`
3. Fires `wp_mcp_ai_tool_token_limit_exceeded` action
4. **Does NOT block execution** (can be customized via hooks)

To implement **blocking enforcement**, add a filter:

```php
add_action( 'wp_mcp_ai_before_tool_execution', function( $tool_slug, $arguments, $context ) {
    $user_id = $context['user_id'] ?? 0;
    
    if ( ! $user_id ) {
        return;
    }
    
    $limit = WP_MCP_AI_Tool_Token_Limits::get_tool_limit( $tool_slug );
    $usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_daily_usage( $user_id, $tool_slug );
    
    if ( $usage >= $limit ) {
        // Throw exception or return WP_Error to block execution
        throw new Exception( sprintf(
            'Daily token limit exceeded for tool %s. Usage: %d/%d tokens.',
            $tool_slug,
            $usage,
            $limit
        ) );
    }
}, 1, 3 ); // Priority 1 to run before check_tool_limit
```

## Admin REST API

### Save Tool Limits

**Endpoint**: `wp_ajax_wp_mcp_ai_save_tool_limits`  
**Method**: POST  
**Capability**: `manage_options`  

**Parameters**:
```javascript
{
    action: 'wp_mcp_ai_save_tool_limits',
    nonce: 'wp-mcp-ai-settings-nonce',
    limits: {
        'run_crawl4ai_job': 200000,
        'general_tools': 100000
    }
}
```

**Response**:
```json
{
    "success": true,
    "data": {
        "message": "Tool token limits saved successfully. 2 limits updated."
    }
}
```

## Testing

Comprehensive test suite in `tests/test-tool-token-limits.php`:

- Default tool limits
- Setting/getting custom limits
- Recording tool usage
- Accumulating usage
- Daily usage tracking
- Limit checking
- Resetting usage
- Tool statistics
- Token estimation
- Expired data cleanup

Run tests:
```bash
composer run test -- tests/test-tool-token-limits.php
```

## Best Practices

1. **Set Realistic Limits**: Base limits on typical usage patterns, not worst-case scenarios
2. **Monitor Trends**: Use the statistics functions to identify tools that consistently approach limits
3. **Communicate Limits**: Inform users about their limits and current usage
4. **Plan for Scaling**: Higher limits may be needed as usage grows
5. **Use Events**: Hook into events for custom notifications or analytics

## Security Considerations

- All input is sanitized via `sanitize_key()` and `absint()`
- AJAX handlers verify nonces and check capabilities
- User meta is scoped to individual users (no cross-user data leakage)
- Token estimation is conservative to avoid underreporting

## Performance

- Minimal overhead: Single user meta query per tool execution
- Efficient storage: Uses WordPress meta API with built-in caching
- Automatic cleanup prevents database bloat
- No external API calls required

## Future Enhancements

Possible extensions:

1. **Time-based limits**: Hourly or weekly limits in addition to daily
2. **Quota rollover**: Allow unused tokens to carry over
3. **Admin notifications**: Email admins when users consistently hit limits
4. **WP-CLI commands**: Manage limits via command line
5. **Multisite support**: Network-wide limit configuration
6. **Export/Import**: Backup and restore limit configurations
7. **Rate limiting**: Requests per minute in addition to token limits

## Troubleshooting

### Limits not being enforced

**Check**:
1. Is `WP_MCP_AI_Tool_Token_Limits::init()` called in `mcp-ai-wpoos.php`?
2. Are the action hooks (`wp_mcp_ai_before_tool_execution`, `wp_mcp_ai_after_tool_execution`) firing?
3. Check logs for `tool_token_limit_exceeded` events

### Usage not being tracked

**Check**:
1. Is the tool executing via the standard execution pipeline?
2. Check if `wp_mcp_ai_after_tool_execution` action is firing
3. Verify user meta is writable

### Cleanup not running

**Check**:
1. Is WP-Cron enabled?
2. Is `wp_mcp_ai_daily_cleanup` scheduled?
3. Check `wp_get_schedules()` for schedule definition

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: See `docs/DOCUMENTATION_INDEX.md`
