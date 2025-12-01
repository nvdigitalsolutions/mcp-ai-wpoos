# Troubleshooting Async Tool Execution

## Quick Diagnostics

### 1. Check if executor is initialized

```php
// In wp-admin or via WP-CLI
global $wp_filter;
$hook = 'wp_mcp_ai_async_tool_execution';
if ( isset( $wp_filter[ $hook ] ) ) {
    echo "✓ Async executor hook is registered\n";
} else {
    echo "✗ Async executor hook is NOT registered\n";
}
```

### 2. Check if a job was queued

```bash
# Via WP-CLI
wp option get wp_mcp_ai_cron_jobs --format=json
```

Look for jobs with `"hook": "wp_mcp_ai_async_tool_execution"`.

### 3. Check if cron job is scheduled

```bash
# Via WP-CLI - list all scheduled cron events
wp cron event list --fields=hook,next_run_relative,recurrence

# Filter for async tool execution jobs
wp cron event list --format=json | grep wp_mcp_ai_async_tool_execution
```

### 4. Check job result

```bash
# Via REST API (replace {job_id} with actual job ID)
curl -X GET "https://yoursite.com/wp-json/mcp-ai/v1/cron-status/{job_id}" \
  -H "X-WP-Nonce: {nonce}"
```

Or in browser console:
```javascript
fetch(wpMcpAiChat.restUrl + '/cron-status/' + jobId, {
  headers: { 'X-WP-Nonce': wpMcpAiChat.nonce }
})
.then(r => r.json())
.then(console.log);
```

### 5. Manually trigger cron

```bash
# Run all due cron events
wp cron event run --due-now

# Run a specific event
wp cron event run wp_mcp_ai_async_tool_execution
```

## Common Issues

### Issue: Hook not registered

**Symptom**: `global $wp_filter` doesn't contain `wp_mcp_ai_async_tool_execution`

**Cause**: Executor's `init()` method not called during bootstrap

**Fix**: Verify `wp_mcp_ai_init_async_executor` is hooked to `wp_mcp_ai_bootstrapped`

```bash
# Check in wp-mcp-ai.php around line 962-976
grep -A 5 "wp_mcp_ai_init_async_executor" wp-mcp-ai.php
```

### Issue: Job queued but never runs

**Symptom**: Job shows in Cron Manager but stays in "pending" status

**Possible Causes**:

1. **WP-Cron is disabled**: 
   ```php
   // Check wp-config.php
   if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
       // WP-Cron is disabled, need system cron
   }
   ```

2. **No traffic to trigger cron**:
   - WP-Cron runs when someone visits the site
   - Low-traffic sites may need system cron
   
3. **Cron is broken**:
   ```bash
   # Test if cron works at all
   wp cron test
   ```

### Issue: Job runs but no result

**Symptom**: Job status changes to "completed" but no result data

**Possible Causes**:

1. **Tool doesn't exist**:
   ```bash
   # List available tools
   wp option get wp_mcp_ai_tool_registry --format=json
   ```

2. **Tool execution error**:
   - Check `wp_mcp_ai_recent_errors` option
   - Enable logging in Settings → WP oOS

3. **Transient expired**:
   - Results stored in transients with 24h TTL
   - Check immediately after execution

### Issue: Cron bar doesn't appear

**This is normal if**:
- No jobs exist (total count = 0)
- All jobs have been pruned (retention period expired)

**Check job counts**:
```bash
# Via REST API
curl -X GET "https://yoursite.com/wp-json/mcp-ai/v1/cron-status" \
  -H "X-WP-Nonce: {nonce}"
```

Expected response:
```json
{
  "counts": {
    "pending": 0,
    "running": 0,
    "completed": 2,
    "failed": 0,
    "total": 2
  }
}
```

If `total > 0`, the bar should appear. If it doesn't:

1. Check browser console for JavaScript errors
2. Verify `wpMcpAiCronStatus` service is loaded:
   ```javascript
   console.log(typeof window.wpMcpAiCronStatus);
   // Should output: "object"
   ```

3. Check if cron status element exists:
   ```javascript
   console.log(document.querySelector('.wp-mcp-ai-chat__cron-status'));
   // Should output: HTMLDivElement or null
   ```

## Debugging Tips

### Enable verbose logging

In `wp-config.php`:
```php
define( 'WP_MCP_AI_DEBUG', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

### Monitor the transient

```php
// Get job metadata directly
$job_id = 'async_abc123...';
$metadata = get_transient( 'wp_mcp_ai_async_meta_' . $job_id );
print_r( $metadata );
```

### Watch cron events

```bash
# Monitor cron events in real-time
watch -n 5 'wp cron event list --format=table'
```

### Test with simple tool

Create a test script:
```php
<?php
require_once 'wp-load.php';

$executor = wp_mcp_ai_get_async_tool_executor();

$job_id = $executor->queue_tool(
    'get_posts', // Use a simple built-in tool
    array( 'post_type' => 'post', 'per_page' => 1 ),
    array( 'user_id' => 1 )
);

echo "Job ID: $job_id\n";

// Wait a moment
sleep( 2 );

// Manually trigger the cron
do_action( 'wp_mcp_ai_async_tool_execution', $job_id );

// Check result
$result = $executor->get_result( $job_id );
print_r( $result );
```

## Support

If issues persist:

1. Check `docs/fixes/async-tool-execution-fix.md` for technical details
2. Review logs in `wp-content/debug.log`
3. Check recent errors: `wp option get wp_mcp_ai_recent_errors --format=json`
4. Enable logging and reproduce the issue
5. Report with full error details and environment info
