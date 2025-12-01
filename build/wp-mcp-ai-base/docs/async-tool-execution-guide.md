# Async Tool Execution - Complete Guide

## Overview

The WP oOS plugin supports asynchronous execution of long-running tools via WordPress Cron. This allows tools like image generation to run in the background without blocking the HTTP request, with client-side polling to retrieve results.

## When Tools Run Asynchronously

Tools are executed asynchronously when:

1. **Tool has async capability flag**: Tool implements `WP_MCP_AI_Tool_Capability_Flags_Interface` and includes `'async'` in `get_capability_flags()`
2. **Global async enabled**: Filter `wp_mcp_ai_async_execution_enabled` returns `true` (default)
3. **Explicit parameter**: Tool call includes `async=true` parameter (overrides all other logic)

### Example: Gemini Image Tool

```php
public function get_capability_flags() {
    return array(
        'async',  // ← Tells orchestrator this tool can run async
        'write',
        'rate-limited',
        // ...
    );
}
```

## Complete Execution Flow

### 1. Tool Invocation (Client → Server)

**Client sends chat message**:
```javascript
POST /wp-json/mcp-ai/v1/chat
{
    "assistant_id": 123,
    "messages": [
        {"role": "user", "content": "Generate an image of a sunset"}
    ]
}
```

**LLM responds with tool call**:
```json
{
    "role": "assistant",
    "tool_calls": [
        {
            "id": "call_123",
            "function": {
                "name": "generate_gemini_image",
                "arguments": "{\"prompt\":\"A beautiful sunset...\"}"
            }
        }
    ]
}
```

### 2. Async Decision (Orchestrator)

**File**: `includes/class-wp-mcp-ai-rest.php:7145-7210`

```php
$orchestrator = wp_mcp_ai_get_async_tool_orchestrator();
$should_async = $orchestrator->should_execute_async( $tool, $arguments, $context );

if ( $should_async ) {
    // Queue for background execution
    $executor = wp_mcp_ai_get_async_tool_executor();
    $job_id   = $executor->queue_tool( $tool_slug, $arguments, $context );
    
    return array(
        'status'    => 'pending',
        'job_id'    => $job_id,
        'message'   => 'Tool is processing...',
        'async'     => true,
        'tool_slug' => $tool_slug,
    );
}
```

**Decision priority order**:
1. Explicit `async=true/false` parameter (highest)
2. Legacy `wait_for_completion` parameter
3. `background-only` capability flag (must be async)
4. Global async setting + timeout risk flags
5. `background-preferred` capability flag

### 3. Job Queueing

**File**: `includes/services/class-wp-mcp-ai-tool-async-executor.php:122-194`

```php
public function queue_tool( $tool_slug, $arguments, $context ) {
    // Generate unique job ID
    $job_id = 'async_' . substr( md5( wp_json_encode( $data ) ), 0, 16 );
    
    // Store metadata in transient (24h expiration)
    $metadata = array(
        'job_id'       => $job_id,
        'tool_slug'    => $tool_slug,
        'arguments'    => $arguments,
        'context'      => array( 'user_id', 'assistant_id', 'session_id' ), // Sanitized
        'status'       => 'pending',
        'queued_at'    => time(),
        'started_at'   => null,
        'completed_at' => null,
        'result'       => null,
        'error'        => null,
    );
    
    set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, 86400 );
    
    // Schedule WP-Cron job
    wp_schedule_single_event(
        time(),
        'wp_mcp_ai_async_tool_execution',
        array( $job_id )
    );
    
    return $job_id;
}
```

**Transient storage**:
- **Key**: `wp_mcp_ai_async_meta_{job_id}`
- **Expiration**: 24 hours (86400 seconds)
- **Location**: WordPress transients (wp_options table)

### 4. Client Receives Async Response

**Response structure**:
```json
{
    "role": "tool",
    "tool_call_id": "call_123",
    "content": {
        "status": "pending",
        "job_id": "async_a1b2c3d4e5f6g7h8",
        "message": "Tool 'generate_gemini_image' is processing...",
        "async": true,
        "tool_slug": "generate_gemini_image"
    }
}
```

**Client code** (`assets/js/chat.js:7789-7798`):
```javascript
if (result.async === true && result.status === 'pending' && result.job_id) {
    // Start polling for the async result
    waitForAsyncToolResult(state, result.job_id, toolName)
        .catch(function (error) {
            console.error('[WP oOS] Async tool polling failed:', error);
        });
    // Don't display pending message here - waitForAsyncToolResult handles it
    return;
}
```

### 5. Client Starts Polling

**File**: `assets/js/chat.js:6269-6367`

```javascript
function waitForAsyncToolResult(state, jobId, toolName) {
    const pollDelay = 3000; // Poll every 3 seconds
    const timeout = 180000; // 3 minute timeout
    
    // Show pending message in chat
    const pendingEntry = appendMessage(state.messagesEl, 'system', 
        'Tool is processing in the background. Results will appear shortly.');
    
    function poll() {
        fetchAsyncToolResult(state, jobId)
            .then(function (payload) {
                if (!payload) {
                    // No response yet, schedule next poll
                    setTimeout(poll, pollDelay);
                    return;
                }
                
                if (payload.status === 'completed') {
                    // Remove pending message
                    pendingEntry.parentNode.removeChild(pendingEntry);
                    // Display actual result
                    displayAsyncToolResult(state, toolName, payload.result);
                } else if (payload.status === 'failed') {
                    updatePendingTaskEntry(pendingEntry, 'Tool failed: ' + payload.error);
                } else {
                    // Still pending/running, continue polling
                    setTimeout(poll, pollDelay);
                }
            });
    }
    
    poll(); // Start polling immediately
}

function fetchAsyncToolResult(state, jobId) {
    const url = state.config.restUrl + '/cron-status/' + jobId;
    return fetch(url, {
        headers: buildJsonHeaders(state),
        credentials: 'same-origin'
    }).then(r => r.json());
}
```

### 6. WP-Cron Execution

**Trigger**: WordPress cron system (requires page loads or system cron)

**Hook**: `wp_mcp_ai_async_tool_execution`

**Callback**: `WP_MCP_AI_Tool_Async_Executor::execute_async_tool( $job_id )`

**File**: `includes/services/class-wp-mcp-ai-tool-async-executor.php:201-279`

```php
public function execute_async_tool( $job_id ) {
    // Load job metadata
    $metadata = get_transient( 'wp_mcp_ai_async_meta_' . $job_id );
    
    // Update status to running
    $metadata['status'] = 'running';
    $metadata['started_at'] = time();
    set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, 86400 );
    
    // Get tool instance (CRITICAL: Uses singleton + init)
    $registry = WP_MCP_AI_Tool_Registry::get_instance();
    $registry->init(); // Ensures tools are loaded
    $tool = $registry->get_tool( $tool_slug );
    
    // Execute tool
    $result = $tool->execute( $arguments, $context );
    
    // Store result (compressed if >100KB)
    if ( ! is_wp_error( $result ) ) {
        $metadata['status'] = 'completed';
        $metadata['completed_at'] = time();
        $metadata['result'] = $this->compress_result( $result );
    } else {
        $metadata['status'] = 'failed';
        $metadata['error'] = $result->get_error_message();
    }
    
    set_transient( 'wp_mcp_ai_async_meta_' . $job_id, $metadata, 86400 );
}
```

**Result compression**:
- **Threshold**: 100KB (MAX_RESULT_SIZE / 10)
- **Method**: gzcompress + base64_encode
- **Reason**: Gemini images with base64 content can be 1-5MB

### 7. Client Retrieves Result

**Endpoint**: `GET /wp-json/mcp-ai/v1/cron-status/{job_id}`

**Handler**: `WP_MCP_AI_Cron_Status_Service::get_job_details()`

**File**: `includes/services/class-wp-mcp-ai-cron-status-service.php:488-515`

```php
public function get_job_details( $job_id, $user_id ) {
    // Get async executor
    $executor = wp_mcp_ai_get_async_tool_executor();
    
    // Retrieve result (automatically decompresses)
    $result = $executor->get_result( $job_id );
    
    // Check permissions
    if ( ! $is_admin && $created_by !== $user_id ) {
        return new WP_Error( 'wp_mcp_ai_forbidden', '...' );
    }
    
    return $result; // Returns full metadata with decompressed result
}
```

**Response structure**:
```json
{
    "job_id": "async_a1b2c3d4e5f6g7h8",
    "tool_slug": "generate_gemini_image",
    "status": "completed",
    "queued_at": 1234567890,
    "started_at": 1234567891,
    "completed_at": 1234567892,
    "duration": 1.234,
    "result": {
        "attachment_id": 456,
        "url": "https://example.com/wp-content/uploads/2025/01/image.png",
        "download_url": "https://example.com/wp-content/uploads/2025/01/image.png",
        "file_name": "gemini-image-20250120.png",
        "mime_type": "image/png",
        "bytes": 234567,
        "title": "Gemini Image: A beautiful sunset...",
        "content": {
            "encoding": "base64",
            "data": "iVBORw0KGgoAAAANS...", // Full base64 image
            "mime_type": "image/png",
            "data_url": "data:image/png;base64,iVBORw0KGgo..."
        },
        "model": "gemini-2.5-flash-image",
        "aspect_ratio": "16:9",
        "format": "PNG",
        "prompt": "A beautiful sunset...",
        "text": "Successfully generated image (ID: 456). Format: 16:9, PNG"
    },
    "arguments": {...},
    "context": {
        "user_id": 1,
        "assistant_id": 123,
        "session_id": "abc123"
    }
}
```

### 8. Client Displays Result

**File**: `assets/js/chat.js:6419-6448`

```javascript
function displayAsyncToolResult(state, toolName, result) {
    // Client-side sanitization/normalization
    const normalized = normaliseToolResultForDisplay(toolName, result);
    
    let attachments = [];
    let resultText = '';
    
    if (normalized && normalized.attachments && normalized.attachments.length > 0) {
        // Extract image attachments
        attachments = normalized.attachments;
        resultText = normalized.text || (toolName + ': Completed');
    }
    
    // Display in chat
    appendMessage(state.messagesEl, 'tool', {
        text: '✓ ' + resultText,
        attachments: attachments  // Images rendered inline
    });
}
```

**Normalization** (`normaliseToolResultForDisplay`):
1. Extracts URL from `result.url` or `result.download_url`
2. If no URL but has `result.content.data`, creates blob URL:
   ```javascript
   const blobUrl = createObjectUrlFromBase64(result.content.data, result.content.mime_type);
   ```
3. Builds attachment object:
   ```javascript
   {
       url: blobUrl,
       label: result.title,
       downloadName: result.file_name,
       meta: 'PNG • 16:9 • 229 KB'
   }
   ```

## Error Handling

### Job Not Found
**Cause**: Transient expired (>24 hours) or job never existed
**Response**: `WP_Error( 'wp_mcp_ai_job_not_found' )`
**Client**: Shows "Tool timed out or job not found"

### Tool Not Found
**Cause**: Tool registry not initialized (FIXED in this PR)
**Response**: Job status set to 'failed' with error message
**Client**: Shows "Tool failed: Tool XXX not found"

### Decompression Failed
**Cause**: Corrupted data, missing gzuncompress, invalid base64
**Response**: `WP_Error( 'wp_mcp_ai_decompression_failed' )`
**Client**: Shows "Failed to retrieve job result"

### Permission Denied
**Cause**: User trying to access another user's job
**Response**: `WP_Error( 'wp_mcp_ai_forbidden' )`
**Client**: 403 error in console

### Timeout
**Cause**: Job still pending after 3 minutes
**Client**: Shows "Tool timed out before completing"
**Note**: Job continues running on server

## Security

### Context Sanitization
Only these fields are preserved in job context:
- `user_id`
- `assistant_id`
- `session_id`

All other fields (API keys, tokens, etc.) are removed.

### Permission Checks
- User can only view their own jobs
- Admins can view all jobs
- Bearer tokens and mesh keys supported

### Data Storage
- Results stored in transients (WordPress options table)
- 24-hour automatic expiration
- Compression for large results
- No sensitive data in context

## Debugging

### Check if job is queued:
```php
$cron = _get_cron_array();
foreach ( $cron as $timestamp => $jobs ) {
    foreach ( $jobs as $hook => $events ) {
        if ( 'wp_mcp_ai_async_tool_execution' === $hook ) {
            print_r( $events );
        }
    }
}
```

### Check job metadata:
```php
$job_id = 'async_a1b2c3d4e5f6g7h8';
$metadata = get_transient( 'wp_mcp_ai_async_meta_' . $job_id );
print_r( $metadata );
```

### Manually trigger cron:
```php
$job_id = 'async_a1b2c3d4e5f6g7h8';
do_action( 'wp_mcp_ai_async_tool_execution', $job_id );
```

### Check logs:
```bash
wp option get wp_mcp_ai_recent_errors --format=json
wp option get wp_mcp_ai_recent_activity --format=json
```

### Enable WP-Cron debugging:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'DOING_CRON', true );
```

## Performance Considerations

### Transient Storage
- Uses WordPress transients (wp_options table)
- 24-hour expiration with automatic cleanup
- Compressed storage for large results
- Database query per poll (cached by WordPress)

### Polling Frequency
- Default: 3 seconds
- Timeout: 3 minutes (60 polls max)
- Can be adjusted via filter

### WP-Cron Limitations
- Requires page loads to trigger (unless using system cron)
- May be delayed on low-traffic sites
- Consider system cron for production:
  ```bash
  */1 * * * * curl -s https://example.com/wp-cron.php > /dev/null
  ```

## Best Practices

### For Tool Developers

1. **Add async flag** if tool may take >5 seconds:
   ```php
   public function get_capability_flags() {
       return array( 'async', 'write', 'rate-limited' );
   }
   ```

2. **Use `background-only` for tools that take 60+ seconds**:
   ```php
   public function get_capability_flags() {
       return array( 
           'async', 
           'long-running',
           'background-only',  // Forces async even in agentic loops
           'may-timeout' 
       );
   }
   ```
   
   **Why `background-only` is needed**:
   - Agentic loops (chat conversations) normally force synchronous execution
   - This ensures LLM receives actual results, not pending status
   - But tools taking 60-120+ seconds (like video generation) will timeout
   - `background-only` flag overrides this behavior, allowing async execution
   - Example: Video generation via Gemini Veo 3.1 takes 60-120 seconds
   
   **Without `background-only`**:
   ```
   User: Generate a video → Tool runs sync → HTTP timeout after 30-60s → Error
   ```
   
   **With `background-only`**:
   ```
   User: Generate a video → Tool queued async → job_id returned → Cron executes → Client polls → Success
   ```

3. **Check `in_async_executor` context to prevent double-async**:
   ```php
   protected function should_use_async( $arguments, $context ) {
       // If already running in async executor, don't create nested async job
       if ( isset( $context['in_async_executor'] ) && $context['in_async_executor'] ) {
           return false;
       }
       
       // Otherwise use async for long operations
       return true;
   }
   ```

4. **Return complete results** - include URLs and metadata:
   ```php
   return array(
       'url'           => $attachment_url,
       'download_url'  => $download_url,
       'attachment_id' => $attachment_id,
       'content'       => $inline_content, // For client display
       'text'          => 'Success message', // For LLM and UI
   );
   ```

5. **Don't rely on request context** - only user_id, assistant_id, session_id are preserved

### For Frontend Developers

1. **Check for async response**:
   ```javascript
   if (result.async === true && result.status === 'pending' && result.job_id) {
       startPolling(result.job_id);
   }
   ```

2. **Handle all statuses**: pending, running, completed, failed

3. **Show progress indicators** during polling

4. **Handle timeouts gracefully** - job may still complete later

## Future Improvements

- [ ] Add retry mechanism for failed jobs
- [ ] Add progress updates during execution
- [ ] Add job cancellation
- [ ] Add job priority queue
- [ ] Add webhook notifications instead of polling
- [ ] Add Redis/Memcached support for job storage
- [ ] Add background worker process (instead of WP-Cron)
