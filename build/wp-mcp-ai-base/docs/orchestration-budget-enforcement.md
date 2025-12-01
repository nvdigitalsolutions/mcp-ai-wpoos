# Orchestration Layer Budget Enforcement

## Overview

The WP oOS orchestration layer implements intelligent budget management for tool execution, ensuring that tools operate within token and memory constraints. This system **predicts, orchestrates, and adjusts** tool responses to prevent API limit overruns and maintain system stability.

## Key Principles

The orchestration layer follows three core principles:

1. **Predict**: Estimate resource usage before execution
2. **Orchestrate**: Coordinate execution based on available resources
3. **Adjust**: Modify outputs to fit within budget constraints

## Architecture

### Components

The budget enforcement system consists of three main components:

1. **WP_MCP_AI_Resource_Manager**: Determines workload tiers and resource limits
2. **WP_MCP_AI_Tool_Token_Limits**: Manages per-tool token budgets and enforcement
3. **WP_MCP_AI_REST**: Integrates budget checks into tool execution flow

### Flow

```
User Request
    ↓
Determine Workload Tier (low/medium/high)
    ↓
Check User's Daily Tool Usage
    ↓
Execute Tool (if budget allows) ← Budget Enforcement Point #1
    ↓
Adjust Tool Result to Fit Budget ← Budget Enforcement Point #2
    ↓
Return to User
```

## Features

### 1. Per-User Daily Token Limits

Each user has daily token limits for each tool to prevent abuse:

- **General Tools**: 100,000 tokens per day
- **Crawl4AI Tool**: 200,000 tokens per day
- **Custom Limits**: Configurable via `WP_MCP_AI_Tool_Token_Limits::set_tool_limit()`

Limits reset at midnight GMT.

### 2. Budget Enforcement

When a user exceeds their daily limit for a tool, the orchestration layer:

1. Logs the event
2. Fires `wp_mcp_ai_tool_token_limit_exceeded` action
3. Throws an exception preventing execution
4. Returns clear error message to user

**Example Error**:
```
Daily token limit exceeded for tool "run_crawl4ai_job". 
Your limit is 200000 tokens per day. 
Limit resets at 2025-11-11 00:00:00.
```

### 3. Workload-Tier-Aware Limits

Tool result sizes are constrained based on server resources:

| Tier | Memory Limit | Max Tool Result Tokens | Use Case |
|------|--------------|------------------------|----------|
| Low | < 128MB | 500 tokens | Shared hosting |
| Medium | 128MB - 512MB | 2,000 tokens | VPS |
| High | ≥ 512MB | 8,000 tokens | Dedicated server |

### 4. Intelligent Result Adjustment

The orchestration layer automatically truncates oversized results:

#### String Results
```php
// Original (15,000 chars = ~3,750 tokens)
$result = "Very long content... [continues for many pages]";

// Adjusted (2,000 chars = ~500 tokens on low tier)
$result = "Very long content... [truncated]
[... Result truncated by orchestration layer to fit within budget constraints ...]";
```

#### Array Results
The system intelligently preserves structure:

```php
// Original
$result = array(
    'url' => 'https://example.com',
    'title' => 'Page Title',
    'markdown' => 'Very long markdown... [20,000 chars]',
    'metadata' => array(...),
);

// Adjusted - preserves url, title, metadata; truncates markdown
$result = array(
    'url' => 'https://example.com',
    'title' => 'Page Title',
    'markdown' => 'Very long markdown... [truncated to fit budget]',
    'metadata' => array(...),
);
```

### 5. High-Output Tool Support

Known high-output tools get 2x the token budget:

- `run_crawl4ai_job`
- `search_content`
- `get_recent_posts`
- `web_search`
- `submit_document_prompt`

This allows them to return more comprehensive results while still enforcing limits.

## Configuration

### Disable Enforcement

To disable budget enforcement for testing:

```php
add_filter( 'wp_mcp_ai_enforce_tool_token_limits', '__return_false' );
```

### Customize Tool Limits

Set custom daily limits for specific tools:

```php
// Increase crawl4ai limit to 500k tokens
WP_MCP_AI_Tool_Token_Limits::set_tool_limit( 'run_crawl4ai_job', 500000 );

// Set limit for custom tool
WP_MCP_AI_Tool_Token_Limits::set_tool_limit( 'my_custom_tool', 50000 );
```

### Customize Result Token Limits

Adjust how many tokens tool results can contain:

```php
add_filter( 'wp_mcp_ai_tool_result_max_tokens', function( $max_tokens, $tool_slug, $tier, $context ) {
    // Allow more tokens for specific tools
    if ( 'my_important_tool' === $tool_slug ) {
        return $max_tokens * 2;
    }
    
    // Reduce tokens on low tier
    if ( 'low' === $tier ) {
        return $max_tokens / 2;
    }
    
    return $max_tokens;
}, 10, 4 );
```

### Monitor Usage

Track tool usage across your site:

```php
// Get statistics for a specific tool
$stats = WP_MCP_AI_Tool_Token_Limits::get_tool_statistics( 'run_crawl4ai_job' );
/*
Array(
    'tool_slug' => 'run_crawl4ai_job',
    'total_users' => 15,
    'total_tokens' => 2500000,
    'total_requests' => 142,
    'limit' => 200000
)
*/

// Get user's daily usage
$user_id = 1;
$usage = WP_MCP_AI_Tool_Token_Limits::get_user_tool_daily_usage( $user_id, 'run_crawl4ai_job' );
echo "User has used {$usage} tokens today";
```

## Logging

The orchestration layer logs all budget-related events:

### Budget Exceeded Event
```json
{
  "event": "tool_token_limit_exceeded",
  "message": "User exceeded daily token limit for tool.",
  "context": {
    "user_id": 1,
    "tool_slug": "run_crawl4ai_job",
    "usage": 205000,
    "limit": 200000,
    "reset_time": "2025-11-11 00:00:00"
  }
}
```

### Result Truncation Event
```json
{
  "event": "tool_result_truncated",
  "message": "Tool result exceeded budget and was truncated by orchestration layer.",
  "context": {
    "tool_slug": "run_crawl4ai_job",
    "original_tokens": 15000,
    "max_tokens": 2000,
    "tier": "medium",
    "truncation_ratio": 0.13
  }
}
```

### Execution Blocked Event
```json
{
  "event": "error",
  "message": "Tool execution blocked by orchestration layer",
  "context": {
    "tool_slug": "run_crawl4ai_job",
    "error": "Daily token limit exceeded for tool...",
    "context": {...}
  }
}
```

Enable logging in **Settings → WP oOS → Enable Logging** to see these events.

## Best Practices

### For Site Administrators

1. **Monitor Logs**: Regularly check for budget exceeded events
2. **Adjust Limits**: Increase limits for power users if needed
3. **Set Appropriate Tier**: Ensure server resources match expected workload
4. **Communicate Limits**: Inform users about daily limits

### For Plugin Developers

1. **Implement Pagination**: Break large datasets into pages
2. **Return Summaries**: For large content, return summaries instead of full text
3. **Use Filters**: Respect the `wp_mcp_ai_tool_result_max_tokens` filter
4. **Test High Load**: Test tools with large outputs across all tiers

### For End Users

- **Be Mindful**: Large crawl operations consume your daily budget
- **Check Usage**: Monitor your usage if you're approaching limits
- **Spread Tasks**: Distribute large operations over multiple days if needed

## Troubleshooting

### "Daily token limit exceeded" Error

**Symptoms**: Tool execution fails with budget exceeded message

**Solutions**:
1. Wait until midnight GMT for limit reset
2. Ask admin to increase your limit
3. Use smaller inputs or different tools
4. Admin can reset usage: `WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $user_id, 'tool_slug' )`

### Tool Results Are Too Short

**Symptoms**: Results appear truncated even when not at limit

**Solutions**:
1. Check server tier: `WP_MCP_AI_Resource_Manager::instance()->get_workload_tier()`
2. Increase server memory if on low tier
3. Use filter to increase result limits
4. Admin can adjust via `wp_mcp_ai_tool_result_max_tokens` filter

### Enforcement Not Working

**Symptoms**: Users exceed limits without being blocked

**Solutions**:
1. Check if enforcement is disabled via filter
2. Verify `WP_MCP_AI_Tool_Token_Limits::init()` is called
3. Check hooks are firing: `has_action('wp_mcp_ai_before_tool_execution')`

## API Reference

### WP_MCP_AI_Tool_Token_Limits

#### `check_tool_limit( $tool_slug, $arguments, $context )`
Checks if user has exceeded their daily limit. Throws exception if limit exceeded and enforcement is enabled.

#### `adjust_tool_result_for_budget( $result, $tool_slug, $context )`
Adjusts tool result to fit within budget constraints. Returns truncated result if needed.

#### `get_tool_limit( $tool_slug )`
Gets the daily token limit for a specific tool.

#### `set_tool_limit( $tool_slug, $limit )`
Sets the daily token limit for a specific tool.

#### `get_user_tool_daily_usage( $user_id, $tool_slug )`
Gets user's token usage for a tool today.

#### `reset_user_tool_usage( $user_id, $tool_slug = '' )`
Resets user's tool usage. If no tool specified, resets all tools.

## Integration with Other Systems

### Resource Manager

Budget limits automatically scale with Resource Manager workload tiers:

```php
$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
$tier = $resource_mgr->get_workload_tier(); // 'low', 'medium', 'high'

// Orchestration layer uses this to determine result limits
```

### Token Budget Manager

Works alongside existing token budget validation:

```php
// Global token budget (for API calls)
WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit( $messages, $model );

// Per-tool budget (for tool execution)
WP_MCP_AI_Tool_Token_Limits::check_tool_limit( $tool_slug, $args, $context );
```

## Performance Impact

The orchestration layer has minimal performance impact:

- **Token Estimation**: ~0.1ms per result
- **Budget Check**: ~0.5ms per execution
- **Result Truncation**: ~1-5ms for large results

Database queries are minimized through caching of user meta.

## Future Enhancements

Planned improvements:

1. **Predictive Scaling**: Warn users before reaching limits
2. **Quota Carry-Over**: Allow unused tokens to roll over
3. **Admin Dashboard**: Visual usage monitoring
4. **Tiered Limits**: Different limits for different user roles
5. **Budget Pools**: Share quotas across user groups

## Related Documentation

- [Resource Management](./RESOURCE-MANAGEMENT.md) - Workload tiers and resource detection
- [High Token Tool Handling](./high-token-tool-handling.md) - Model switching for large responses
- [Token Budget Manager](./token-management.md) - Global token budgeting
- [Tool Reference](./tool-reference.md) - All available tools

## Changelog

### Version 1.0.0 (2025-11-10)
- Initial implementation of orchestration layer budget enforcement
- Added per-user daily token limits
- Added workload-tier-aware result adjustment
- Added intelligent truncation for strings and arrays
- Added comprehensive logging
- Added filter support for customization
- Created test suite with 11 test cases
