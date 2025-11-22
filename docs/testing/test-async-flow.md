# Async Tool Execution Flow - Debugging Guide

## Current Architecture

### 1. Tool Execution Decision (REST API)
**File**: `includes/class-wp-mcp-ai-rest.php:7145-7210`

```php
$orchestrator = wp_mcp_ai_get_async_tool_orchestrator();
$should_async = $orchestrator->should_execute_async( $tool, $arguments, $context );

if ( $should_async ) {
    $executor = wp_mcp_ai_get_async_tool_executor();
    $job_id   = $executor->queue_tool( $tool_slug, $arguments, $context );
    
    return array(
        'status'    => 'pending',
        'job_id'    => $job_id,
        'message'   => '...',
        'async'     => true,
        'tool_slug' => $tool_slug,
    );
}
```

### 2. Async Orchestrator Decision
**File**: `includes/services/class-wp-mcp-ai-async-tool-orchestrator.php:62-104`

Checks in priority order:
1. Explicit `async=true/false` parameter
2. Legacy `wait_for_completion` parameter
3. `background-only` flag
4. Global async setting enabled
5. Tool has timeout risk flags ('async', 'long-running', 'may-timeout', 'background-only')

### 3. Gemini Image Tool Flags
**File**: `includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php:690-701`

```php
public function get_capability_flags() {
    return array(
        'requires-credentials',
        'requires-capability',
        'write',
        'async',                // ← HAS ASYNC FLAG
        'rate-limited',
        'requires-model',
        'consumes-tokens',
        'model-dependent',
    );
}
```

### 4. Tool Queueing
**File**: `includes/services/class-wp-mcp-ai-tool-async-executor.php:122-194`

```php
public function queue_tool( $tool_slug, array $arguments, array $context ) {
    $job_id = $this->generate_job_id( $tool_slug, $arguments, $context );
    
    $metadata = array(
        'job_id'       => $job_id,
        'tool_slug'    => $tool_slug,
        'arguments'    => $arguments,
        'context'      => $this->sanitize_context( $context ),
        'status'       => 'pending',
        'queued_at'    => time(),
        // ...
    );
    
    $this->save_metadata( $job_id, $metadata );  // Saves to transient
    
    wp_schedule_single_event(
        time(),
        self::CRON_HOOK,  // 'wp_mcp_ai_async_tool_execution'
        array( $job_id )
    );
    
    return $job_id;
}
```

### 5. Cron Hook Registration
**File**: `includes/services/class-wp-mcp-ai-tool-async-executor.php:90-91`

```php
public function init() {
    add_action( self::CRON_HOOK, array( $this, 'execute_async_tool' ), 10, 1 );
}
```

**Cron Hook**: `wp_mcp_ai_async_tool_execution`

### 6. Async Execution
**File**: `includes/services/class-wp-mcp-ai-tool-async-executor.php:201-279`

```php
public function execute_async_tool( $job_id ) {
    $metadata = $this->get_metadata( $job_id );
    
    $metadata['status']     = 'running';
    $metadata['started_at'] = time();
    $this->save_metadata( $job_id, $metadata );
    
    $tool   = $registry->get_tool( $tool_slug );
    $result = $tool->execute( $arguments, $context );
    
    $metadata['status']       = 'completed';
    $metadata['completed_at'] = time();
    $metadata['result']       = $this->compress_result( $result );
    
    $this->save_metadata( $job_id, $metadata );
}
```

### 7. Client-Side Polling
**File**: `assets/js/chat.js:6269-6367`

```javascript
function waitForAsyncToolResult(state, jobId, toolName) {
    const pollDelay = 3000; // Poll every 3 seconds
    
    function poll() {
        fetchAsyncToolResult(state, jobId)
            .then(function (payload) {
                if (payload.status === 'completed') {
                    displayAsyncToolResult(state, toolName, payload.result);
                } else {
                    scheduleNext(); // Continue polling
                }
            });
    }
}

function fetchAsyncToolResult(state, jobId) {
    const url = state.config.restUrl + '/cron-status/' + jobId;
    return fetch(url, { /* headers */ }).then(r => r.json());
}
```

### 8. Result Retrieval API
**File**: `includes/services/class-wp-mcp-ai-cron-status-service.php:478-515`

```php
public function get_job_details( $job_id, $user_id = 0 ) {
    if ( 0 === strpos( $job_id, 'async_' ) ) {
        $executor = $this->get_async_executor();
        $result   = $executor->get_result( $job_id );
        
        // Returns metadata array with:
        // - status: 'completed'
        // - result: {tool result with url, download_url, content, etc.}
        return $result;
    }
}
```

## Potential Issues

### Issue 1: WP-Cron Not Running
- WP-Cron requires page loads to trigger
- On low-traffic sites, cron jobs may be delayed
- Solution: Use system cron or trigger wp-cron.php via curl

### Issue 2: Transient Expiration
- Results stored in transients with 24h expiration
- If result retrieved after expiration, job_not_found error
- Default: 86400 seconds (24 hours)

### Issue 3: Permission Issues
- Async executor sanitizes context to only include: user_id, assistant_id, session_id
- Tool execution may fail if it needs other context data

### Issue 4: Result Compression
- Large results (>100KB) are gzcompressed
- If gzuncompress fails, result could be null
- Check: `function_exists( 'gzcompress' )` and `function_exists( 'gzuncompress' )`

### Issue 5: Client Not Receiving Async Response
- Tool must return proper async response structure:
  ```php
  array(
      'status'    => 'pending',
      'job_id'    => $job_id,
      'async'     => true,
      'tool_slug' => $tool_slug,
  )
  ```
- Client checks: `result.async === true && result.status === 'pending' && result.job_id`

## Testing Checklist

- [ ] Verify Gemini tool has 'async' capability flag
- [ ] Verify orchestrator returns true for should_execute_async
- [ ] Verify executor queues job and returns job_id
- [ ] Verify cron hook is registered
- [ ] Verify wp-cron is running (check scheduled events)
- [ ] Verify async tool execution callback is triggered
- [ ] Verify tool executes successfully
- [ ] Verify result is stored in transient
- [ ] Verify client receives async response with job_id
- [ ] Verify client starts polling
- [ ] Verify cron-status endpoint returns job details
- [ ] Verify result structure matches client expectations
- [ ] Verify image is displayed in chat

## Debug Commands

```php
// Check if cron hook is registered
has_action( 'wp_mcp_ai_async_tool_execution' );

// Check scheduled events
wp_get_scheduled_event( 'wp_mcp_ai_async_tool_execution', array( $job_id ) );

// Get all scheduled cron jobs
_get_cron_array();

// Manually trigger cron (for testing)
do_action( 'wp_mcp_ai_async_tool_execution', $job_id );

// Get job result
$executor = wp_mcp_ai_get_async_tool_executor();
$result = $executor->get_result( $job_id );

// Check transient
get_transient( 'wp_mcp_ai_async_meta_' . $job_id );
```
