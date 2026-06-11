# Veo Video Notification Flow - Complete System Documentation

## Overview

This document provides a comprehensive overview of how Veo video generation notifications flow from backend to frontend, ensuring that users see their completed videos in the chat interface.

## Architecture Diagram

```
[User Request] 
    ↓
[Tool: generate_veo_video]
    ↓
[Service: WP_MCP_AI_Gemini_Video_Generation_Service]
    ├─→ [Gemini API] (video generation)
    ├─→ [Async Polling via Cron]
    ├─→ [File-Based Detection]
    └─→ [Completion Hooks]
            ↓
[Job Notifier: WP_MCP_AI_Job_Notifier]
    ├─→ Cache Status
    └─→ Dispatch Webhooks
            ↓
[SSE Stream: WP_MCP_AI_SSE_Stream]
    ├─→ Poll Cached Status
    └─→ Send Events to Frontend
            ↓
[Chat Client: assets/js/chat.js]
    ├─→ Receive SSE Events
    ├─→ Extract video_url
    └─→ Render Video Player
```

## Backend Flow (Step-by-Step)

### 1. Video Generation Request

**File:** `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`

```php
public function execute( array $arguments = array(), array $context = array() ) {
    // Validate user permissions
    // Build generation arguments
    // Call video generation service
    $result = $service->generate_video( $generation_args );
}
```

**Key Actions:**
- Validates user has `upload_files` capability
- Builds generation args with prompt, duration, resolution, etc.
- Passes `assistant_id` and `user_id` for proper routing

### 2. Async Job Creation

**File:** `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

```php
protected function queue_async_polling( $operation, $args ) {
    // Generate unique job ID (now with underscores, not dots)
    $job_id = 'veo_' . str_replace( '.', '_', uniqid( '', true ) );
    
    // Pre-generate expected filename
    $expected_filename = 'veo-video-' . $job_id . '.mp4';
    
    // Store metadata in transient
    $metadata = array(
        'job_id'            => $job_id,
        'operation_name'    => $operation['operation_name'],
        'expected_filename' => $expected_filename,
        'assistant_id'      => $args['assistant_id'], // For routing
        'args'              => $args,
    );
    set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );
    
    // Schedule cron polling
    wp_schedule_single_event( time() + 1, self::CRON_POLL_HOOK, array( $job_id ) );
    
    // Fire job started hook
    do_action( 'wp_mcp_ai_job_started', $job_id, array( 'tool' => 'generate_veo_video' ) );
}
```

**Hooks Fired:**
- `wp_mcp_ai_job_started` → Notifies that video generation has begun

### 3. Video Polling (Cron)

**File:** `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

```php
public function poll_video_async( $job_id ) {
    // Get metadata
    $metadata = get_transient( self::ASYNC_OP_PREFIX . $job_id );
    
    // PRIORITY 1: Check for file creation (fast detection)
    if ( isset( $metadata['expected_filename'] ) ) {
        $attachment = $this->check_for_created_video_file( 
            $metadata['expected_filename'], 
            $job_id 
        );
        if ( $attachment ) {
            // Video found! Mark as complete
            $this->fire_job_completion_hooks( $job_id, $metadata, $attachment );
            return;
        }
    }
    
    // PRIORITY 2: Poll Gemini API for operation status
    $response = wp_remote_get( $gemini_operation_endpoint );
    
    if ( $operation_done ) {
        // Download video, save to media library
        $result = $this->process_completed_video( $data, $metadata['args'] );
        $save_result = $this->save_video_to_media( $result, $user_id, $job_id );
        
        // Fire completion hooks
        $this->fire_job_completion_hooks( $job_id, $metadata, $save_result );
    } else {
        // Schedule next poll
        $this->schedule_next_poll( $job_id, $metadata );
    }
}
```

**Hooks Fired:**
- `wp_mcp_ai_job_progress` → During polling with progress percentage
- `wp_mcp_ai_job_completed` → When video is ready (see below)

### 4. Job Completion Hooks

**File:** `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`

```php
protected function fire_job_completion_hooks( $job_id, $metadata, $result ) {
    // Prepare hook metadata with routing info
    $hook_metadata = array(
        'tool'         => 'generate_veo_video',
        'user_id'      => $metadata['args']['user_id'],
        'assistant_id' => $metadata['assistant_id'], // CRITICAL for chat routing
        'prompt'       => $metadata['args']['prompt'],
    );
    
    // Fire main completion hook
    do_action( 
        'wp_mcp_ai_job_completed', 
        $job_id, 
        $result,  // Full result with video_url, attachment_id, url, etc.
        $hook_metadata 
    );
    
    // Fire tool execution hook for token tracking
    do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result );
}
```

**Result Structure:**
```php
$result = array(
    'success'       => true,
    'job_id'        => 'veo_6926100bb2f8e3_59706124',
    'attachment_id' => 12345,
    'url'           => 'https://site.com/wp-content/uploads/2025/01/veo-video-veo_6926100bb2f8e3_59706124.mp4',
    'edit_url'      => 'https://site.com/wp-admin/post.php?post=12345&action=edit',
    'video_url'     => array(
        'url' => 'https://site.com/wp-content/uploads/2025/01/veo-video-veo_6926100bb2f8e3_59706124.mp4',
    ),
    'prompt'        => 'A cat playing piano',
    'duration'      => 5,
    'aspect_ratio'  => '16:9',
    'resolution'    => '720p',
    'model'         => 'veo-3.1-generate-preview',
    'provider'      => 'gemini',
    'text'          => 'Successfully generated video (ID: 12345). Format: 5s, 720p, 16:9',
    'message'       => 'Video generated successfully...',
);
```

### 5. Job Notifier Caching

**File:** `includes/class-wp-mcp-ai-job-notifier.php`

```php
public static function handle_job_completed( $job_id, $result = array(), $metadata = array() ) {
    // Cache the full result for SSE retrieval
    $status = array(
        'job_id'       => $job_id,
        'status'       => 'completed',
        'completed_at' => current_time( 'mysql', true ),
        'result'       => $result,  // FULL result including video_url
        'metadata'     => $metadata,
    );
    
    self::cache_job_status( $job_id, $status );
    self::dispatch_webhooks( $job_id, 'completed', $status );
}
```

**Cache Key:** `wp_mcp_ai_job_status_{job_id}`  
**Cache Duration:** 1 hour (3600 seconds)

## Frontend Flow (Step-by-Step)

### 1. SSE Connection

**Endpoint:** `GET /wp-json/mcp-ai/v1/jobs/{job_id}/stream`

**File:** `includes/class-wp-mcp-ai-sse-stream.php`

```php
protected static function build_sse_stream( $job_id, $max_duration, $poll_interval ) {
    while ( ( time() - $start_time ) < $max_duration ) {
        // Retrieve cached status from Job Notifier
        $status = WP_MCP_AI_Job_Notifier::get_job_status( $job_id );
        
        if ( $status && $status !== $last_status ) {
            // Send status update via SSE
            $stream .= self::format_sse_message( 'status', $status );
            
            // Check for terminal state
            if ( $status['status'] === 'completed' ) {
                $stream .= self::format_sse_message( 'complete', array( ... ) );
                break;
            }
        }
        
        sleep( $poll_interval );
    }
}
```

**SSE Event Format:**
```
event: status
data: {"job_id":"veo_123","status":"completed","result":{...video data...}}

event: complete
data: {"job_id":"veo_123","final_status":"completed"}
```

### 2. Chat Client SSE Handling

**File:** `assets/js/chat.js`

The chat client uses the SSE service to listen for job updates:

```javascript
// SSE service polls the job stream endpoint
sseService.streamJobStatus(jobId, function(event) {
    if (event.type === 'status') {
        handleJobStatus(event.data);
    } else if (event.type === 'complete') {
        handleJobComplete(event.data);
    }
});
```

### 3. Video URL Extraction

**File:** `assets/js/chat.js` (Line ~6214)

```javascript
// Check for video_url structure (similar to image_url for generate_veo_video)
const nestedVideo = result && result.video_url && typeof result.video_url === 'object' 
    ? result.video_url 
    : null;

let url = '';
if (typeof result.url === 'string' && result.url.trim()) {
    url = result.url.trim();
} else if (nestedVideo) {
    // Handle video_url structure from generate_veo_video
    if (typeof nestedVideo.url === 'string' && nestedVideo.url.trim()) {
        url = nestedVideo.url.trim();
    }
}
```

### 4. Video Detection

**File:** `assets/js/chat.js` (Line ~11132)

```javascript
function isVideoAttachment(attachment) {
    if (!attachment || typeof attachment !== 'object') {
        return false;
    }
    
    const url = attachment.url || '';
    
    // Check for video file extensions
    const lowerUrl = url.toLowerCase();
    const urlPath = lowerUrl.split('?')[0].split('#')[0];
    
    const videoExtensions = ['.mp4', '.webm', '.ogg', '.ogv', '.mov', '.avi', '.mkv'];
    for (let i = 0; i < videoExtensions.length; i++) {
        const ext = videoExtensions[i];
        if (urlPath.lastIndexOf(ext) === urlPath.length - ext.length) {
            return true;
        }
    }
    
    // Check for data URLs with video MIME type
    if (lowerUrl.indexOf('data:video/') === 0) {
        return true;
    }
    
    return false;
}
```

### 5. Video Player Rendering

**File:** `assets/js/chat.js` (Line ~10467)

```javascript
if (isVideo) {
    // Render video player
    const videoContainer = document.createElement('div');
    videoContainer.className = 'wp-mcp-ai-chat__video-container';
    
    const video = document.createElement('video');
    video.controls = true;
    video.preload = 'metadata';
    video.className = 'wp-mcp-ai-chat__video-player';
    
    const source = document.createElement('source');
    source.src = attachment.url;
    source.type = getVideoMimeType(attachment.url);
    
    video.appendChild(source);
    videoContainer.appendChild(video);
    item.appendChild(videoContainer);
}
```

## Key Data Structures

### Job Metadata (Transient)
```php
array(
    'job_id'            => 'veo_6926100bb2f8e3_59706124',
    'operation_name'    => 'operations/gemini-op-123',
    'model'             => 'veo-3.1-generate-preview',
    'args'              => array( ... ),
    'status'            => 'pending',
    'queued_at'         => 1234567890,
    'poll_attempt'      => 0,
    'max_attempts'      => 60,
    'expected_filename' => 'veo-video-veo_6926100bb2f8e3_59706124.mp4',
    'assistant_id'      => 42,  // For chat routing
)
```

### Job Status (Cached)
```php
array(
    'job_id'       => 'veo_6926100bb2f8e3_59706124',
    'status'       => 'completed',
    'completed_at' => '2025-01-15 12:34:56',
    'result'       => array(
        'success'       => true,
        'attachment_id' => 12345,
        'url'           => 'https://...',
        'video_url'     => array( 'url' => 'https://...' ),
        // ... full result ...
    ),
    'metadata'     => array(
        'tool'         => 'generate_veo_video',
        'user_id'      => 1,
        'assistant_id' => 42,
    ),
)
```

## Critical Integration Points

### 1. Assistant ID Routing
The `assistant_id` is passed through the entire chain to ensure the chat client can match the video to the correct chat session:

```
Tool → Service → Job Metadata → Completion Hook → Job Notifier Cache → SSE Stream → Chat Client
```

### 2. Video URL Structure
The `video_url` structure mirrors `image_url` from image generation:

```php
'video_url' => array(
    'url' => 'https://site.com/wp-content/uploads/...'
)
```

This allows the chat client to detect and render videos consistently.

### 3. File-Based Polling
Dual detection mechanism for faster completion:
1. **File-Based:** Check if attachment with matching `_veo_job_id` exists
2. **API-Based:** Poll Gemini API operation endpoint

File-based detection is checked FIRST for faster response.

## Troubleshooting

### Videos Not Appearing in Chat

**Check 1: Job Completion Hook**
```php
// Add temporary logging
add_action( 'wp_mcp_ai_job_completed', function( $job_id, $result, $metadata ) {
    error_log( 'Video job completed: ' . $job_id );
    error_log( 'Has video_url: ' . ( isset( $result['video_url'] ) ? 'YES' : 'NO' ) );
}, 10, 3 );
```

**Check 2: Job Notifier Cache**
```php
$status = WP_MCP_AI_Job_Notifier::get_job_status( 'veo_123' );
var_dump( $status['result']['video_url'] );
```

**Check 3: SSE Stream**
```bash
curl -H "X-WP-Nonce: YOUR_NONCE" \
  "https://site.com/wp-json/mcp-ai/v1/jobs/veo_123/stream"
```

**Check 4: Chat Client Console**
```javascript
// Check browser console for:
// - SSE connection established
// - Job status received
// - Video URL extracted
// - Video player rendered
```

### Common Issues

1. **Missing assistant_id:** Video completes but doesn't route to correct chat
2. **Missing video_url structure:** Chat client can't detect video
3. **Expired cache:** Job status not available (check 1-hour expiry)
4. **SSE connection timeout:** Client disconnects before completion

## Performance Considerations

### Caching
- Job status cached for 1 hour
- Transient expires after 24 hours
- Attachment metadata stored permanently

### Polling Intervals
- Cron polling: Every 5 seconds
- SSE client polling: Every 2 seconds
- File-based check: On every poll (very fast)

### Resource Usage
- Each SSE connection: One PHP process
- Max SSE duration: 5 minutes (300 seconds)
- Cron jobs: Spawn on-demand, self-cleanup

## Conclusion

The Veo video notification system is a robust, multi-layered architecture that ensures users receive their generated videos reliably through:

1. **Async generation** with cron-based polling
2. **Dual detection** (file-based + API-based)
3. **Complete hook chain** for extensibility
4. **Cached status** for efficient SSE delivery
5. **Structured data** with `video_url` for consistent rendering

All components work together to provide a seamless user experience from generation request to video playback in the chat interface.
