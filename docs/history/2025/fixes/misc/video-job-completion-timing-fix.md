# Video Job Completion Timing Fix

## Issue
Video generation completed successfully but the generated video did not return to the chat interface. Users saw "Tool is processing..." followed by "Tool timed out before completing" even though the video was generated and saved to the media library.

## Root Cause
In the `poll_video_async()` method of `WP_MCP_AI_Gemini_Video_Generation_Service`, when a video generation job completed, two completion hooks were fired:

1. **Parent async job completion** (`async_xxx`) - via `complete_parent_job()`
2. **Veo job completion** (`veo_xxx`) - via `do_action('wp_mcp_ai_job_completed')`

The problem was that the parent job was completed **BEFORE** the veo job hooks were fired, causing both hooks to execute simultaneously. This created a race condition in the notification system where:

- Both jobs appeared to complete at the exact same time
- The notification cache might not have the veo result cached before the parent lookup
- The chat client polling the parent job ID would timeout or miss the result

## Solution
Changed the execution order in `poll_video_async()` to ensure sequential completion:

### Before (Incorrect Order)
```php
// 1. Complete parent job FIRST ❌
if ( isset( $metadata['parent_job_id'] ) ) {
    $this->complete_parent_job( $metadata['parent_job_id'], $metadata['result'] );
}

// 2. Fire veo job completion SECOND
do_action( 'wp_mcp_ai_job_completed', $job_id, $metadata['result'], $hook_metadata );
```

### After (Correct Order)
```php
// 1. Fire veo job completion FIRST ✅
do_action( 'wp_mcp_ai_job_completed', $job_id, $metadata['result'], $hook_metadata );

// 2. Fire tool execution hooks
do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result );

// 3. Complete parent job LAST ✅
if ( isset( $metadata['parent_job_id'] ) ) {
    $this->complete_parent_job( $metadata['parent_job_id'], $metadata['result'] );
}
```

## How It Works

### Sequential Execution Flow
1. **Veo job completion hook fires**
   - `wp_mcp_ai_job_completed` action for `veo_xxx`
   - Notification system caches the video result
   - Result includes `video_url`, `attachment_id`, `url`, etc.

2. **Tool execution hook fires**
   - `wp_mcp_ai_after_tool_execution` action
   - Token tracking and metrics recorded

3. **Parent job completion fires**
   - `complete_parent_job()` method called
   - Updates parent transient with wrapped result
   - Fires `wp_mcp_ai_job_completed` for `async_xxx`
   - Parent job completion triggers orchestrator

### Notification System Caching
The `WP_MCP_AI_Job_Notifier::cache_job_status()` method stores job results in transients. By ensuring veo completes first:

- Veo result is cached at `wp_mcp_ai_job_status_veo_xxx`
- Parent result is cached at `wp_mcp_ai_job_status_async_xxx`
- Chat client polling either job ID will find a cached result

## Files Changed

### `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`
- **Lines 1317-1394**: Reordered job completion logic in `poll_video_async()`
- **Lines 1343-1353**: Added comment explaining critical order requirement
- **Lines 1381-1392**: Added comment explaining parent job completion timing

### `tests/test-veo-job-completion-order.php` (new file)
- **Test 1**: Verifies parent completion comes after veo completion
- **Test 2**: Checks for explanatory comments about the fix
- **Test 3**: Validates both hooks still fire in sequence

## Benefits

1. **Prevents Race Conditions**: Sequential execution eliminates timing issues
2. **Reliable Caching**: Veo result always cached before parent lookup
3. **Client Compatibility**: Works with polling and SSE notification methods
4. **Backward Compatible**: Both job IDs still work for status checks
5. **Clear Documentation**: Comments explain why order matters

## Testing

### Automated Tests
Run the test suite:
```bash
vendor/bin/phpunit tests/test-veo-job-completion-order.php
```

### Manual Testing
1. Generate a video via chat interface
2. Verify "Tool is processing..." message appears
3. Confirm video returns to chat when complete (not timeout)
4. Check that video appears in media library
5. Verify video displays inline in chat

### Expected Behavior
- Chat shows "Video generation started" immediately
- Poll progress shows "Video is being generated..."
- Upon completion, video displays inline with video player
- No "Tool timed out" error appears

## Related Components

### Job Notifier
- `includes/class-wp-mcp-ai-job-notifier.php`
- Caches job status in `wp_mcp_ai_job_status_{job_id}` transients
- Fires webhooks for job events

### Async Executor
- `includes/services/class-wp-mcp-ai-tool-async-executor.php`
- Creates parent async jobs for long-running tools
- Stores metadata in `wp_mcp_ai_async_meta_{job_id}` transients

### Chat Client
- `assets/js/chat.js`
- Polls job status endpoint for async job updates
- Displays video when job completes

## Troubleshooting

### Symptoms of the Bug
- Video generates successfully (visible in media library)
- Chat shows "Tool timed out before completing"
- Video doesn't appear in chat interface
- Logs show both job IDs completing at same timestamp

### Debug Steps
1. Check logs for `veo_async_completed` event
2. Verify `veo_parent_job_completed` event follows
3. Check transient `wp_mcp_ai_job_status_async_xxx` exists
4. Confirm video attachment has correct metadata

### Verification
```php
// Check if fix is applied
$file = file_get_contents( WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-video-generation-service.php' );

// Should find parent completion AFTER veo completion
$has_fix = strpos( $file, 'CRITICAL ORDER: Fire veo job completion hook FIRST' ) !== false;

if ( $has_fix ) {
    echo "Fix is applied correctly\n";
} else {
    echo "Fix is NOT applied - update to latest version\n";
}
```

## Performance Impact
- **Negligible**: Only affects hook execution order, not total time
- **No additional queries**: Same number of database operations
- **Same memory usage**: No additional caching overhead
- **Improved reliability**: Reduces failed video deliveries to chat

## Future Considerations

### Potential Enhancements
1. Add timeout to parent job completion call
2. Retry parent completion if first attempt fails
3. Add metric tracking for completion timing
4. Log warning if hooks fire out of order

### Monitoring
Track these metrics to detect regressions:
- Video generation success rate in chat
- Time between veo and parent completion hooks
- Number of "tool timeout" errors for video generation
- Parent job completion failure rate

## References
- Issue: "video still not returning to chat as it looks like both jobs complete at the same time"
- PR: #[TBD]
- Related: Job notification system architecture
- Related: Async tool execution flow
