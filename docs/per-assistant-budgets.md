# Per-Assistant Token Budgets

## Overview

WP oOS supports per-assistant token budgets to control costs and prevent excessive API usage on a per-assistant basis. Each assistant can have its own token budget and time window, providing fine-grained control over resource consumption.

## Features

- **Per-Assistant Limits**: Set individual token budgets for each assistant
- **Time Windows**: Configure rolling time windows (1 minute to 24 hours)
- **Per-User Tracking**: Budgets are tracked independently for each user-assistant combination
- **Graceful Degradation**: Budget exceeded errors return 429 status with helpful messages
- **Automatic Reset**: Budgets automatically reset when the time window expires
- **Comprehensive Logging**: All budget checks and violations are logged

## Configuration

### Setting Token Budget

Token budgets are configured via post meta on the assistant custom post type:

```php
// Set a budget of 100,000 tokens per hour
update_post_meta( $assistant_id, '_wp_mcp_ai_token_budget', 100000 );
```

**Budget Values:**
- `0` (default): No limit - unlimited token usage
- `> 0`: Maximum tokens allowed within the time window
- Maximum allowed: 10,000,000 tokens (configurable via filter)

### Setting Budget Window

The budget window defines the time period for token counting:

```php
// Set budget window to 1 hour (3600 seconds)
update_post_meta( $assistant_id, '_wp_mcp_ai_budget_window', 3600 );
```

**Window Values:**
- Minimum: 60 seconds (1 minute)
- Default: 3600 seconds (1 hour)
- Maximum: 86400 seconds (24 hours)

## How It Works

### Token Tracking

1. **Request Estimation**: When a chat request is made, the system estimates the total tokens needed (input + output)
2. **Budget Check**: The current usage within the time window is checked against the budget limit
3. **Approval/Rejection**: 
   - If within budget: Request proceeds and usage is tracked
   - If exceeds budget: Request is rejected with 429 error
4. **Automatic Reset**: When the time window expires, the budget counter resets to zero

### Per-User Isolation

Budgets are tracked independently for each user-assistant pair:
- User A using Assistant 1 has their own budget tracker
- User B using Assistant 1 has a separate budget tracker
- User A using Assistant 2 has another separate tracker

This prevents one user from consuming another user's budget allocation.

## Usage Examples

### Example 1: Basic Budget Setup

```php
// Create an assistant with a 50,000 token/hour budget
$assistant_id = wp_insert_post( array(
    'post_type'  => 'mcp_ai_assistant',
    'post_title' => 'Customer Support Bot',
    'post_status' => 'publish',
) );

// Set budget: 50,000 tokens per hour
update_post_meta( $assistant_id, '_wp_mcp_ai_token_budget', 50000 );
update_post_meta( $assistant_id, '_wp_mcp_ai_budget_window', 3600 );
```

### Example 2: Checking Budget Programmatically

```php
$user_id      = get_current_user_id();
$assistant_id = 123;
$messages     = array(
    array( 'role' => 'user', 'content' => 'Hello!' ),
);

$options = array( 'model' => 'gpt-4o-mini' );

$result = WP_MCP_AI_Token_Budget_Manager::check_budget(
    $user_id,
    $assistant_id,
    $messages,
    $options
);

if ( is_wp_error( $result ) ) {
    $error_data = $result->get_error_data();
    
    echo 'Budget exceeded!';
    echo 'Current usage: ' . $error_data['current_usage'] . ' tokens';
    echo 'Limit: ' . $error_data['budget_limit'] . ' tokens';
    echo 'Try again in: ' . $error_data['minutes_until_reset'] . ' minutes';
} else {
    // Proceed with request
}
```

### Example 3: Different Budgets for Different Assistants

```php
// Premium assistant: Higher budget
$premium_id = create_assistant( 'Premium Support' );
update_post_meta( $premium_id, '_wp_mcp_ai_token_budget', 200000 );  // 200k tokens/hour
update_post_meta( $premium_id, '_wp_mcp_ai_budget_window', 3600 );

// Basic assistant: Lower budget  
$basic_id = create_assistant( 'Basic Support' );
update_post_meta( $basic_id, '_wp_mcp_ai_token_budget', 25000 );     // 25k tokens/hour
update_post_meta( $basic_id, '_wp_mcp_ai_budget_window', 3600 );

// Internal assistant: No limits
$internal_id = create_assistant( 'Internal Tools' );
update_post_meta( $internal_id, '_wp_mcp_ai_token_budget', 0 );      // Unlimited
```

## Error Handling

### Budget Exceeded Error

When a budget is exceeded, a `WP_Error` is returned with:

**Error Code**: `wp_mcp_ai_budget_exceeded`

**HTTP Status**: 429 (Too Many Requests)

**Error Message**: 
```
Token budget exceeded. This assistant has a limit of 100000 tokens per hour. 
Current usage: 95000 tokens. Please try again in 45 minutes.
```

**Error Data**:
```php
array(
    'status'              => 429,
    'budget_limit'        => 100000,
    'current_usage'       => 95000,
    'estimated_request'   => 12000,
    'time_until_reset'    => 2700,      // seconds
    'minutes_until_reset' => 45,        // minutes
)
```

### User-Facing Messages

The error message is designed to be user-friendly and actionable:
- Clearly states the problem ("Token budget exceeded")
- Shows the limit and current usage
- Tells user when they can try again
- Provides specific time remaining

## Logging

### Successful Budget Checks

```php
Event: token_budget_check_passed
Data:
  - user_id
  - assistant_id
  - budget_limit
  - current_usage
  - estimated_request
  - remaining_budget
```

### Budget Exceeded Events

```php
Event: Assistant token budget exceeded
Level: error
Data:
  - user_id
  - assistant_id
  - budget_limit
  - current_usage
  - estimated_request
  - projected_total
  - window_seconds
  - time_until_reset
```

View logs at: **WordPress Admin → Settings → WP oOS → Recent Activity / Recent Errors**

## Advanced Configuration

### Custom Maximum Budget Filter

Limit the maximum budget an administrator can set:

```php
add_filter( 'wp_mcp_ai_max_assistant_token_budget', function( $max_budget ) {
    // Limit to 1 million tokens instead of default 10 million
    return 1000000;
} );
```

### Budget Calculation Filters

Customize token estimation and budget calculation:

```php
// Adjust safety margin for budget calculations
add_filter( 'wp_mcp_ai_token_budget_safety_margin', function( $margin ) {
    return 0.15; // 15% safety margin instead of default 10%
} );

// Customize default fallback limit
add_filter( 'wp_mcp_ai_token_budget_default_limit', function( $limit, $model ) {
    if ( strpos( $model, 'gpt-5' ) !== false ) {
        return 200000; // Higher default for GPT-5 models
    }
    return $limit;
}, 10, 2 );
```

## Best Practices

### 1. Set Realistic Budgets

Consider your use case and user base:
- **Customer Support**: 50,000 - 100,000 tokens/hour
- **Content Generation**: 100,000 - 200,000 tokens/hour
- **Development Tools**: 25,000 - 50,000 tokens/hour
- **Internal Tools**: 0 (unlimited) or very high limits

### 2. Monitor Usage

Regularly check budget logs to:
- Identify users frequently hitting limits
- Adjust budgets based on actual usage patterns
- Detect unusual usage spikes

### 3. Communicate Limits to Users

Display budget information in your UI:
```php
$budget = get_post_meta( $assistant_id, '_wp_mcp_ai_token_budget', true );
if ( $budget > 0 ) {
    echo "This assistant has a limit of " . number_format( $budget ) . " tokens per hour.";
}
```

### 4. Different Budgets for User Roles

```php
add_action( 'save_post_mcp_ai_assistant', function( $post_id ) {
    // Administrators get 2x budget
    if ( current_user_can( 'manage_options' ) ) {
        $budget = get_post_meta( $post_id, '_wp_mcp_ai_token_budget', true );
        update_post_meta( $post_id, '_wp_mcp_ai_budget_multiplier', 2.0 );
    }
} );
```

### 5. Graceful UI Handling

In your frontend chat interface:
```javascript
// Handle budget exceeded error
if ( response.code === 'wp_mcp_ai_budget_exceeded' ) {
    const minutes = response.data.minutes_until_reset;
    showError( `Token limit reached. Please try again in ${minutes} minutes.` );
}
```

## Troubleshooting

### Budget Not Resetting

**Symptom**: Budget counter doesn't reset after time window

**Solution**: Check transient storage. Transients use `wp_mcp_ai_budget_{user_id}_{assistant_id}` key.

```php
// Manually clear budget transient
$transient_key = sprintf( 'wp_mcp_ai_budget_%d_%d', $user_id, $assistant_id );
delete_transient( $transient_key );
```

### Budget Too Restrictive

**Symptom**: Users frequently hit limits

**Solutions**:
1. Increase budget limit
2. Increase time window
3. Optimize prompts to use fewer tokens
4. Switch to models with better token efficiency

### Budget Not Enforced

**Symptom**: Requests proceed even when over budget

**Checklist**:
1. Verify budget meta is set: `get_post_meta( $assistant_id, '_wp_mcp_ai_token_budget' )`
2. Ensure budget > 0 (0 means no limit)
3. Check that `check_budget()` is being called before requests
4. Review error logs for failures in budget check

## API Reference

### WP_MCP_AI_Token_Budget_Manager::check_budget()

Check if a chat request is within the assistant's token budget.

**Parameters**:
- `$user_id` (int): WordPress user ID
- `$assistant_id` (int): Assistant post ID
- `$messages` (array): Chat messages array
- `$options` (array): Optional. Chat options including model, max_tokens

**Returns**: `true` if within budget, `WP_Error` if exceeded

### Assistant Meta Keys

- `_wp_mcp_ai_token_budget`: Integer. Token limit (0 = no limit)
- `_wp_mcp_ai_budget_window`: Integer. Time window in seconds (60-86400)

## Related Documentation

- [Token Budget Management](./token-budget-management.md)
- [TPM Limit Validation](./tpm-limit-validation.md)
- [Rate Limiting](./rate-limit-protection.md)
- [Cost Optimization](./cost-optimization.md)

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- Documentation: `/docs` directory
- Logs: Settings → WP oOS → Recent Activity
