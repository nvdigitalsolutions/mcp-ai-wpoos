# Async Video Generation Implementation

## Overview
This document describes the async fallback implementation for video generation to prevent HTTP timeouts.

## Problem
The `generate_veo_video` tool was experiencing timeouts because video generation takes 60-120 seconds, exceeding typical HTTP request timeouts (30-60 seconds).

## Solution
Implemented cron-based async polling that:
1. Queues video generation job immediately
2. Returns job ID to client
3. Polls Gemini API in background via WP Cron
4. Saves video to media library when complete

## Usage

### Generating a Video (Async by Default)
```php
$result = $tool->execute(
    array(
        'prompt' => 'A cat playing piano',
        'duration' => 5,
        'aspect_ratio' => '16:9',
        'resolution' => '720p',
    ),
    array( 'user_id' => 1 )
);

// Returns immediately with:
// {
//     "async": true,
//     "job_id": "veo_abc123...",
//     "status": "pending",
//     "message": "Video generation started. Use job_id to check status."
// }
```

### Checking Status
```php
$status_tool = new WP_MCP_AI_Tool_Check_Video_Status();
$status = $status_tool->execute(
    array( 'job_id' => 'veo_abc123...' ),
    array( 'user_id' => 1 )
);

// Returns:
// - status: 'pending', 'polling', 'completed', or 'failed'
// - poll_attempt: current attempt number
// - result: video data when completed
```

### Forcing Sync Mode (Not Recommended)
```php
$result = $tool->execute(
    array(
        'prompt' => 'A cat playing piano',
        'async' => false, // Force synchronous mode
    ),
    array( 'user_id' => 1 )
);
// May timeout if video takes too long!
```

## Architecture

### Components

1. **Service Layer** (`WP_MCP_AI_Gemini_Video_Generation_Service`)
   - Handles async job creation
   - Manages cron polling
   - Downloads and saves completed videos

2. **Tool Layer** (`WP_MCP_AI_Tool_Generate_Veo_Video`)
   - Determines execution mode (async by default)
   - Delegates to service layer
   - Returns appropriate response format

3. **Status Tool** (`WP_MCP_AI_Tool_Check_Video_Status`)
   - Provides status checking endpoint
   - Returns job progress and results

### Data Flow

```
1. Client Request
   ↓
2. Tool: Create async job → Service: queue_async_polling()
   ↓
3. Store operation in transient (wp_mcp_ai_veo_async_{job_id})
   ↓
4. Schedule WP Cron: wp_mcp_ai_poll_veo_video
   ↓
5. Return job_id to client
   ↓
6. [Background] Cron fires every 5 seconds
   ↓
7. [Background] Service: poll_video_async() → Poll Gemini API
   ↓
8. [Background] If done: Download video → Save to media library
   ↓
9. Client: check_video_status(job_id) → Get results
```

### Storage

**Transient Key**: `wp_mcp_ai_veo_async_{job_id}`

**Metadata Structure**:
```php
array(
    'job_id' => 'veo_abc123...',
    'operation_name' => 'operations/gemini-op-xyz',
    'args' => array(
        'prompt' => '...',
        'duration' => 5,
        'user_id' => 1,
        'save_to_media' => true,
    ),
    'status' => 'pending|polling|completed|failed',
    'queued_at' => 1234567890,
    'poll_attempt' => 0,
    'max_attempts' => 60,
    'last_poll' => 1234567895,
    'result' => array(
        'attachment_id' => 123,
        'url' => 'http://...',
        'prompt' => '...',
        // ... video metadata
    ),
    'error' => 'Error message if failed',
)
```

**Expiration**: 24 hours (DAY_IN_SECONDS)

### Polling Configuration

- **Interval**: 5 seconds (POLLING_INTERVAL constant)
- **Max Attempts**: 60 (MAX_POLLING_ATTEMPTS constant)
- **Max Duration**: 5 minutes (60 attempts × 5 seconds)
- **Cron Hook**: `wp_mcp_ai_poll_veo_video`

## Error Handling

### Timeout After Max Attempts
```php
array(
    'status' => 'failed',
    'error' => 'Video generation timed out after maximum polling attempts.',
)
```

### API Error During Generation
```php
array(
    'status' => 'failed',
    'error' => 'Message from Gemini API',
)
```

### Download/Save Failure
```php
array(
    'status' => 'failed',
    'error' => 'Failed to download/save video',
)
```

## Testing

Run tests with:
```bash
vendor/bin/phpunit tests/test-veo-async-video-generation.php
```

**Test Coverage**:
- Job queueing
- Status retrieval
- Cron scheduling
- Tool integration
- Error handling
- Capability flags

## Benefits

1. **No Timeouts**: Jobs never timeout on HTTP layer
2. **Scalable**: Multiple videos can generate concurrently
3. **Resilient**: Survives server restarts (stored in DB)
4. **Transparent**: Clients get job IDs for tracking
5. **Compatible**: Can still use sync mode if needed

## Future Enhancements

1. Add webhook notifications when complete
2. Support job cancellation
3. Add progress estimates (% complete)
4. Batch video generation
5. Priority queue for paid users

## Related Files

- `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`
- `includes/tools/class-wp-mcp-ai-tool-generate-veo-video.php`
- `includes/tools/class-wp-mcp-ai-tool-check-video-status.php`
- `tests/test-veo-async-video-generation.php`
- `includes/services-init.php` (service initialization)

## Maintenance Notes

### Code Duplication
The `save_video_to_media()` method is intentionally duplicated in both:
- Service class (for async completion)
- Tool class (for sync mode)

This maintains separation of concerns and avoids tight coupling between layers.

### Cron Manager Integration
All cron jobs are automatically recorded in the WP MCP AI Cron Manager for visibility and management through the admin interface.

### Transient Cleanup
Transients expire after 24 hours automatically. Completed jobs should be retrieved by clients within this window.
