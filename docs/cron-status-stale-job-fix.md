# Cron Status Bar Fix - Stale Job Validation

## Problem Statement

The cron status bar in the chat client was showing jobs as "running" when they were actually stale (the WordPress cron event had already executed or been removed, but the metadata wasn't updated).

### Symptoms
1. Chat client shows "9 running jobs" in the status bar
2. No jobs or notifications appear in the actual chat
3. After initial fix: nothing shows in bar, video completes with no notification

## Root Cause

The cron status service (`WP_MCP_AI_Cron_Status_Service`) was trusting the job status stored in transient metadata without validating that the corresponding WordPress cron event still exists.

### Job Lifecycle

**Normal Flow:**
1. Job created → metadata stored with status 'pending'
2. Cron event scheduled → `wp_schedule_single_event()`
3. Cron runs → metadata updated to 'running'
4. Tool executes → metadata updated to 'completed' or 'failed'
5. Cron event auto-removed (single events delete after execution)

**Bug Scenario:**
1. Job created → metadata stored with status 'pending' or 'running'
2. Cron event scheduled
3. Cron fails to run OR runs but fails to update metadata
4. Metadata stuck at 'pending'/'running' indefinitely
5. Status bar shows ghost "running" jobs

## Solution

Added validation logic that checks if a WordPress cron event actually exists for jobs showing as 'pending', 'running', or 'polling'.

### Implementation

**New Method:** `validate_async_job_status()`

```php
protected function validate_async_job_status( $job, $status ) {
    // 1. Determine cron hook based on job type
    $cron_hook = ($job_type === 'video_generation') 
        ? 'wp_mcp_ai_poll_veo_video'
        : 'wp_mcp_ai_async_tool_execution';
    
    // 2. Check if cron event exists
    $event = wp_get_scheduled_event( $cron_hook, array( $job_id ) );
    
    // 3. If event exists, job is active
    if ( false !== $event ) {
        return $status; // Keep original status
    }
    
    // 4. No event - check if stale (> 10 minutes)
    $age = time() - max( $job['started_at'], $job['queued_at'] );
    
    if ( $age > 10 * MINUTE_IN_SECONDS ) {
        return 'failed'; // Mark as failed
    }
    
    return $status; // Still recent, keep original status
}
```

**Modified Method:** `get_status_counts()`

Now validates async tool jobs before counting:

```php
if ( isset( $job['tool_slug'] ) && isset( $job['status'] ) ) {
    $status = $job['status'];
    
    // Validate 'pending', 'running', or 'polling' jobs
    if ( 'pending' === $status || 'running' === $status || 'polling' === $status ) {
        $status = $this->validate_async_job_status( $job, $status );
    }
}
```

### Validation Logic Flow

```
Job with status 'running', 'pending', or 'polling'
│
├─→ Check WordPress for cron event
│   │
│   ├─→ Event EXISTS
│   │   └─→ Return original status (job is active)
│   │
│   └─→ Event DOESN'T EXIST
│       │
│       ├─→ Job age < 10 minutes
│       │   └─→ Return original status (might be queuing)
│       │
│       └─→ Job age >= 10 minutes
│           └─→ Return 'failed' (stale job)
```

## Job Types Supported

### 1. Async Tool Jobs
- **Cron Hook:** `wp_mcp_ai_async_tool_execution`
- **Metadata Prefix:** `wp_mcp_ai_async_meta_`
- **Statuses:** pending → running → completed/failed
- **Examples:** Background tool executions

### 2. Video Generation Jobs
- **Cron Hook:** `wp_mcp_ai_poll_veo_video`
- **Metadata Prefix:** `wp_mcp_ai_veo_async_`
- **Statuses:** polling → completed/failed
- **Examples:** Veo video generation with API polling

## Logging

The fix includes comprehensive logging for debugging:

### Log Events

1. **`async_job_validated_active`**
   - Job has active cron event
   - Status unchanged
   - Includes next run time

2. **`async_job_validated_recent`**
   - No cron event but job < 10 minutes old
   - Status unchanged (might be queuing)
   - Includes job age

3. **`async_job_marked_as_failed`**
   - No cron event and job > 10 minutes old
   - Status changed to 'failed'
   - Includes job age and previous status

4. **`cron_status_counts_calculated`**
   - Final count summary
   - Total jobs by type (regular, async, video)
   - Count breakdown (pending, running, completed, failed)

### Accessing Logs

1. Enable logging: **Settings → WP oOS → Enable Logging**
2. View logs: **Settings → WP oOS → View Logs**
3. Filter by event type in admin interface

## Testing

### Test Suite: `test-cron-status-stale-job-validation.php`

**Test Scenarios:**
- ✓ Async job with active cron event shows as running
- ✓ Stale async job (no cron, >10 min) shows as failed
- ✓ Recent async job (no cron, <10 min) shows as running
- ✓ Completed async job shows as completed
- ✓ Video job with cron event shows as running
- ✓ Stale video job shows as failed
- ✓ Completed video job shows as completed

**Running Tests:**
```bash
composer test -- tests/test-cron-status-stale-job-validation.php
```

## Debugging Guide

### If Jobs Aren't Showing in Bar

1. **Enable logging** in WP oOS settings
2. **Check count logs:**
   ```
   Event: cron_status_counts_calculated
   Data: {
     counts: {pending: 0, running: 2, completed: 5, failed: 3},
     total_jobs: 10,
     regular: 0,
     async: 7,
     video: 3
   }
   ```
3. **Check validation logs** for specific jobs:
   - Look for `async_job_validated_*` events
   - Check which jobs are being marked as failed
   - Verify timestamps and ages

### If Counts Seem Wrong

1. **Check job metadata** in database:
   ```sql
   SELECT * FROM wp_options 
   WHERE option_name LIKE '_transient_wp_mcp_ai_async_meta_%' 
   OR option_name LIKE '_transient_wp_mcp_ai_veo_async_%';
   ```

2. **Check WordPress cron events:**
   ```php
   $crons = _get_cron_array();
   // Look for wp_mcp_ai_async_tool_execution and wp_mcp_ai_poll_veo_video hooks
   ```

3. **Verify user_id filtering:**
   - Non-admin users only see their own jobs
   - Check `created_by` field in metadata

### If Video Completes with No Notification

This is a **separate issue** from stale job detection. Check:

1. **Video completion action firing:**
   ```php
   do_action( 'wp_mcp_ai_video_job_completed', $job_id, $metadata, $status );
   ```

2. **Event Dispatcher listening:**
   - `WP_MCP_AI_Event_Dispatcher_Service::handle_video_job_completed()`
   - Should dispatch notification via `dispatch_notification()`

3. **Frontend polling:**
   - Check `/wp-json/mcp-ai/v1/job-notifications` endpoint
   - Verify notifications array contains video completion

## Configuration

### Stale Threshold

Default: **10 minutes**

To customize:
```php
add_filter( 'wp_mcp_ai_stale_job_threshold', function() {
    return 15 * MINUTE_IN_SECONDS; // 15 minutes
});
```

### Disable Validation (Not Recommended)

```php
add_filter( 'wp_mcp_ai_validate_async_jobs', '__return_false' );
```

## Performance Considerations

- `wp_get_scheduled_event()` is called once per active job per status check
- This is lightweight (WordPress core function, no DB query for small cron tables)
- Validation only happens for jobs with 'pending'/'running'/'polling' status
- Completed/failed jobs skip validation entirely

## Known Limitations

1. **10-minute threshold is fixed** in code (not filterable yet)
2. **No automatic metadata cleanup** - stale metadata remains in transients until expiration
3. **LIMIT 50** on job queries - only first 50 jobs per type are checked

## Future Improvements

1. Add filter for stale threshold
2. Add WP-CLI command to clean stale job metadata
3. Add admin notice when stale jobs are detected
4. Consider dedicated job tracking table instead of transients
5. Add batch validation for very large job counts

## Related Files

- `includes/services/class-wp-mcp-ai-cron-status-service.php` - Main service
- `includes/services/class-wp-mcp-ai-tool-async-executor.php` - Async tool executor
- `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php` - Video generation
- `includes/services/class-wp-mcp-ai-event-dispatcher-service.php` - Notification dispatcher
- `tests/test-cron-status-stale-job-validation.php` - Test suite

## Changelog

### Version 1.1.0 (This Fix)
- Added `validate_async_job_status()` method
- Modified `get_status_counts()` to validate async jobs
- Added comprehensive logging for debugging
- Created test suite for validation logic

### Future Version
- [ ] Add configurable stale threshold
- [ ] Add WP-CLI cleanup command
- [ ] Add admin UI for stale job management
