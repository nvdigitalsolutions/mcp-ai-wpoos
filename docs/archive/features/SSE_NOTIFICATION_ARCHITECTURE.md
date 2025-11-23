# SSE Notification Architecture for Async Tool Execution

## Overview

This document describes how Server-Sent Events (SSE) are used to provide real-time notifications during both synchronous and asynchronous tool execution in the WP oOS plugin.

## Two-Stream Architecture

The plugin uses **two separate SSE streams** for optimal performance and separation of concerns:

### Stream 1: Agentic Loop SSE (Chat Streaming)
**Endpoint**: `/wp-json/mcp-ai/v1/chat-client` (with `stream=true`)  
**Purpose**: Stream LLM responses and tool execution events during conversation  
**Duration**: Closes when LLM completes final response (~5-30 seconds)

### Stream 2: Async Job SSE (Job Monitoring)
**Endpoint**: `/wp-json/mcp-ai/v1/cron-status/{job_id}` (with `stream=true`)  
**Purpose**: Monitor long-running async job progress  
**Duration**: Stays open until job completes or times out (up to 6 minutes)

---

## Complete Flow: Video Generation Example

### 1. User Sends Message
```
User: "Generate a video of a cat playing with yarn"
```

### 2. Client Opens Stream 1 (Agentic Loop SSE)
```javascript
// Client opens SSE connection to chat endpoint
POST /wp-json/mcp-ai/v1/chat-client?stream=true
Headers: {
  'Content-Type': 'application/json',
  'X-WP-Nonce': '<nonce>'
}
Body: {
  assistant_id: 123,
  messages: [
    {role: 'user', content: 'Generate a video of a cat playing with yarn'}
  ]
}
```

### 3. Server Sends Initial SSE Events (Stream 1)
```
event: status
data: {"type":"thinking","message":"Processing your request…"}

event: status
data: {"type":"generating","message":"Generating response…"}
```

### 4. LLM Calls Tool
```
LLM decides to call: generate_veo_video
Tool arguments: {
  prompt: "A playful cat batting at colorful yarn, 8K cinematic quality",
  duration: 4,
  aspect_ratio: "16:9"
}
```

### 5. Server Sends Tool Execution Events (Stream 1)
```
event: tool_execution
data: {"type":"start","iteration":0,"tool_count":1,"tools":["generate_veo_video"]}

event: tool_execution
data: {"type":"tool_start","tool_name":"generate_veo_video","tool_id":"call_abc123"}
```

### 6. Tool Starts Async Job
```php
// Tool executes and returns async response
return array(
    'async'   => true,
    'job_id'  => 'veo_69203b5b2388f5.11575461',
    'status'  => 'pending',
    'message' => 'Video generation started. Your video is being created...'
);
```

### 7. Server Sends Tool Result Event (Stream 1)
```
event: tool_execution
data: {
  "type": "tool_result",
  "tool_name": "generate_veo_video",
  "tool_id": "call_abc123",
  "result": {
    "async": true,
    "job_id": "veo_69203b5b2388f5.11575461",
    "status": "pending",
    "message": "Video generation started. Your video is being created..."
  }
}
```

### 8. Client Displays Job Started Message
```javascript
// Client receives async response and displays it
appendMessage(messagesEl, 'system', 
  'Video generation started. Your video is being created in the background and will appear here when ready. (Job ID: veo_69203b5b2388f5.11575461)'
);

// Client starts monitoring via second SSE stream
waitForAsyncToolResult(state, 'veo_69203b5b2388f5.11575461', 'generate_veo_video');
```

### 9. Client Opens Stream 2 (Job Monitoring SSE)
```javascript
// Opens SECOND SSE connection to monitor job
GET /wp-json/mcp-ai/v1/cron-status/veo_69203b5b2388f5.11575461?stream=true
Headers: {
  'X-WP-Nonce': '<nonce>'
}
```

### 10. Server Sends Job Status Events (Stream 2)
```
retry: 3000

event: cron_job_status
data: {
  "job_id": "veo_69203b5b2388f5.11575461",
  "status": "pending",
  "queued_at": {"timestamp": 1234567890, "relative": "Just now"}
}

// 3 seconds later...
event: cron_job_status
data: {
  "job_id": "veo_69203b5b2388f5.11575461",
  "status": "polling",
  "poll_attempt": 1,
  "last_poll": {"timestamp": 1234567893, "relative": "3 seconds ago"}
}

// 60-120 seconds later when video is ready...
event: cron_job_status
data: {
  "job_id": "veo_69203b5b2388f5.11575461",
  "status": "completed",
  "completed_at": {"timestamp": 1234567950, "relative": "Just now"},
  "result": {
    "attachment_id": 456,
    "url": "https://example.com/wp-content/uploads/2025/01/veo-video-123.mp4",
    "prompt": "A playful cat batting at colorful yarn...",
    "duration": 4,
    "aspect_ratio": "16:9",
    "resolution": "720p",
    "model": "veo-3.1-generate-preview",
    "provider": "gemini"
  }
}

data: [DONE]
```

### 11. Stream 2 Closes, Video Displayed
```javascript
// Client receives completed status, displays video
displayAsyncToolResult(state, 'generate_veo_video', result);

// Video player appears in chat with the generated content
```

### 12. Meanwhile, Stream 1 Continues (LLM Response)
```
event: status
data: {"type":"thinking","message":"Analyzing tool results…"}

event: status
data: {"type":"generating","message":"Generating response…"}

// LLM response text streams character by character
event: content
data: {"delta":{"content":"I"}}

event: content
data: {"delta":{"content":"'ve"}}

event: content
data: {"delta":{"content":" created"}}

event: content
data: {"delta":{"content":" your"}}

event: content
data: {"delta":{"content":" video"}}

event: content
data: {"delta":{"content":"!"}}

// Final message from LLM
event: content
data: {"delta":{"content":" The video shows a playful cat batting at colorful yarn..."}}

data: [DONE]
```

### 13. Stream 1 Closes
```
// Agentic loop SSE connection closes
// Total duration: ~5-10 seconds
```

---

## Key SSE Event Types

### Stream 1 Events (Agentic Loop)

| Event Type | Purpose | Example Data |
|------------|---------|--------------|
| `status` | Chat status updates | `{type: 'thinking', message: 'Processing...'}` |
| `tool_execution` | Tool call events | `{type: 'tool_start', tool_name: 'generate_veo_video'}` |
| `thinking` | Gemini 2.0 reasoning | `{delta: {thinking: 'The user wants...'}}` |
| `content` | LLM response text | `{delta: {content: 'Hello'}}` |
| `error` | Error occurred | `{code: 'error_code', message: 'Error message'}` |

### Stream 2 Events (Job Monitoring)

| Event Type | Purpose | Example Data |
|------------|---------|--------------|
| `cron_job_status` | Job progress update | `{job_id: 'veo_xxx', status: 'polling', poll_attempt: 5}` |
| `cron_job_status` | Job completed | `{job_id: 'veo_xxx', status: 'completed', result: {...}}` |
| `cron_job_status` | Job failed | `{job_id: 'veo_xxx', status: 'failed', error: 'Error msg'}` |

---

## Benefits of Two-Stream Architecture

### ✅ Advantages

1. **No Timeouts**: Agentic loop SSE closes quickly, avoiding HTTP timeout issues
2. **Parallel Monitoring**: Client can monitor multiple async jobs simultaneously
3. **Separation of Concerns**: Chat streaming separate from job monitoring
4. **Resource Efficiency**: Only keeps connections open as long as needed
5. **HTTP/2 Compatible**: Works well with HTTP/2 multiplexing
6. **Scalability**: Each concern has dedicated connection with appropriate timeouts
7. **Clean UX**: LLM can respond to user while video generates in background

### ❌ Alternative: Single Long-Running Stream

What if we kept Stream 1 open to monitor async jobs?

- ❌ Connection open for 60-120+ seconds (video generation time)
- ❌ Timeout issues on restrictive hosting (30s limits common)
- ❌ Can't monitor multiple async jobs (one stream per chat request)
- ❌ Violates separation of concerns
- ❌ LLM can't continue conversation until job completes
- ❌ Poor user experience (waiting for everything to finish)

---

## Implementation Details

### Server-Side

**File**: `includes/class-wp-mcp-ai-rest.php`

#### Stream 1: Agentic Loop SSE
```php
protected function handle_chat_request_with_streaming(...) {
    $this->send_sse_headers();
    
    // Send status events
    $this->send_sse_event('status', array('type' => 'thinking', ...));
    
    // Agentic loop
    while ($iteration < $max_iterations) {
        foreach ($tool_calls as $tool_call) {
            // Send tool start event
            $this->send_sse_event('tool_execution', array('type' => 'tool_start', ...));
            
            // Execute tool (might return async response)
            $tool_result = $this->execute_tool_call_internal(...);
            
            // Send tool result event (includes async response if applicable)
            $this->send_sse_event('tool_execution', array(
                'type' => 'tool_result',
                'result' => $tool_result  // {async: true, job_id: 'veo_xxx', ...}
            ));
        }
        
        // Get next LLM response
        $response = $this->client->create_chat_completion(...);
    }
    
    // Stream final response text
    foreach ($chunks as $chunk) {
        $this->send_sse_event('content', array('delta' => $chunk));
    }
    
    // Close stream
    $this->send_sse_done();
    exit;
}
```

#### Stream 2: Job Monitoring SSE
```php
protected function stream_job_status_updates($initial_details, $job_id, $service, $user_id) {
    $this->sse_handler->send_sse_headers();
    
    // Send initial status
    $this->sse_handler->send_sse_event('cron_job_status', $initial_details);
    
    // Poll for updates
    $max_polls = 120;  // 6 minutes max
    $poll_interval = 3;  // 3 seconds
    
    while ($poll_count < $max_polls) {
        sleep($poll_interval);
        
        // Get updated job details
        $updated_details = $service->get_job_details($job_id, $user_id);
        
        // Send update if status changed
        if ($current_status !== $last_status) {
            $this->sse_handler->send_sse_event('cron_job_status', $updated_details);
        }
        
        // Check if completed
        if (in_array($current_status, array('completed', 'failed'), true)) {
            $this->sse_handler->send_sse_done();
            exit;
        }
    }
    
    // Timeout
    $this->sse_handler->send_sse_event('cron_job_status', array('status' => 'timeout'));
    $this->sse_handler->send_sse_done();
    exit;
}
```

### Client-Side

**File**: `assets/js/chat.js`

#### Handling Stream 1 Events
```javascript
// When tool_result event received with async response
if (result.async === true && result.status === 'pending' && result.job_id) {
    // Display initial message
    if (result.message) {
        const messageWithJobId = result.message + ' (Job ID: ' + result.job_id + ')';
        appendMessage(state.messagesEl, 'system', messageWithJobId);
    }
    
    // Start monitoring via Stream 2
    waitForAsyncToolResult(state, result.job_id, toolName);
    return;
}
```

#### Opening Stream 2
```javascript
function waitForAsyncToolResultSSE(state, jobId, toolName) {
    // Build SSE URL
    let url = state.config.restUrl + '/cron-status/' + encodeURIComponent(jobId) + '?stream=true';
    
    // Open SSE connection using service
    sseConnection = sseService.connect(url, {
        eventHandlers: {
            cron_job_status: function (payload) {
                if (payload.status === 'completed') {
                    // Display final result with video
                    displayAsyncToolResult(state, toolName, payload.result);
                } else if (payload.status === 'failed') {
                    // Display error
                    handleError(payload.error);
                } else {
                    // Update status message
                    updatePendingTaskEntry(pendingEntry, 'Tool is processing…');
                }
            }
        }
    });
}
```

---

## Error Handling

### Stream 1 Errors
- **LLM API Error**: Send `error` event, close stream
- **Tool Execution Error**: Send in `tool_result`, continue loop
- **Token Budget Exceeded**: Try model switch or truncate, send `status` event
- **Max Iterations**: Send `status` event with type `max_iterations`

### Stream 2 Errors
- **Job Not Found**: Send `cron_job_status` with status `failed`
- **Permission Error**: Send `cron_job_status` with status `failed`
- **Timeout**: Send `cron_job_status` with status `timeout` after 6 minutes
- **Job Deleted**: Send `cron_job_status` with status `failed`

---

## Timeout Strategy

### Stream 1 (Agentic Loop)
- **Client Timeout**: 180 seconds (3 minutes)
- **Server Max Execution**: Depends on hosting (30-300s typical)
- **Why It Works**: Stream closes quickly after LLM response (~5-30s)

### Stream 2 (Job Monitoring)
- **Client Timeout**: 180 seconds (3 minutes) with reconnection
- **Server Max Polls**: 120 polls × 3 seconds = 360 seconds (6 minutes)
- **Poll Interval**: 3 seconds
- **Heartbeat**: Every 15 seconds (5 polls)
- **Why It Works**: Dedicated to job monitoring, can run longer

---

## Testing Scenarios

### Happy Path
1. ✅ User requests video generation
2. ✅ Stream 1 sends tool execution events
3. ✅ Tool returns async job_id
4. ✅ Client displays job started message with job_id
5. ✅ Stream 2 opens automatically
6. ✅ Job status updates received every 3 seconds
7. ✅ Video completes after 60 seconds
8. ✅ Final event with attachment_id received
9. ✅ Video displays in chat
10. ✅ Stream 2 closes
11. ✅ LLM response continues streaming on Stream 1
12. ✅ Stream 1 closes

### Error Scenarios
- ❌ Invalid prompt: Stream 1 sends error, Stream 2 never opens
- ❌ Job fails during generation: Stream 2 sends failed status
- ❌ Job timeout: Stream 2 sends timeout status after 6 minutes
- ❌ Network interruption: Client reconnects using SSE retry mechanism
- ❌ Permission changed: Stream 2 detects and sends failed status

---

## Security Considerations

### Authentication
Both streams require authentication via:
- WordPress nonce (`X-WP-Nonce` header)
- Assistant bearer token (`Authorization: Bearer cred_xxx.SECRET`)
- Auth0 token (enterprise)
- Guest token (public chat surfaces)

### Permission Checks
- **Stream 1**: Validates assistant access and user capabilities
- **Stream 2**: Validates job ownership on every poll (prevents unauthorized access)

### Rate Limiting
- **Stream 1**: Standard chat rate limits apply
- **Stream 2**: No additional rate limiting (already limited by poll interval)

---

## Performance Optimization

### Connection Pooling
- Modern browsers support 6 concurrent HTTP/1.1 connections per domain
- HTTP/2 allows unlimited concurrent streams
- Two SSE connections per active chat is well within limits

### Memory Usage
- **Stream 1**: Minimal buffering (events sent immediately)
- **Stream 2**: Transient polling (no database load, uses wp_options cache)

### Network Efficiency
- **Stream 1**: Text streaming reduces perceived latency
- **Stream 2**: 3-second polling interval balances responsiveness vs overhead
- **Heartbeats**: Every 15 seconds prevents connection drops

---

## Future Enhancements

### Possible Improvements
1. **WebSocket Support**: Replace SSE with WebSockets for bidirectional communication
2. **Job Cancellation**: Allow user to cancel async jobs via Stream 2
3. **Progress Percentage**: Include progress info in cron_job_status events
4. **Multi-Job Aggregation**: Single Stream 2 for multiple concurrent async jobs
5. **Retry Logic**: Automatic reconnection with exponential backoff
6. **Compression**: Enable gzip for large SSE payloads

### Not Recommended
- ❌ **Single Long Stream**: Keep both streams separate for reasons above
- ❌ **Polling Fallback**: SSE is already a polling mechanism, additional polling adds overhead
- ❌ **Job Results in Stream 1**: Would require keeping connection open indefinitely

---

## Conclusion

The **two-stream SSE architecture** provides:
- ✅ Real-time streaming of LLM responses
- ✅ Immediate feedback for tool execution
- ✅ Asynchronous job monitoring without timeouts
- ✅ Clean separation of concerns
- ✅ Optimal resource utilization
- ✅ Excellent user experience

This architecture is **production-ready** and follows SSE best practices for 2024-2025.
