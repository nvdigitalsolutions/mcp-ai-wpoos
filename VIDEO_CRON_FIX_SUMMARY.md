# Video Generation Cron Fix - Summary

## Problem Statement
Video generation jobs were getting stuck in "pending" status and videos were not being created. Frontend showed timeout errors:
- `{pending: 1, running: 0, completed: 0, failed: 1, total: 2}`
- `Async tool polling failed: Error: timeout`

## Root Causes

### 1. Race Condition in Cron Scheduling
**Issue**: The transient containing job metadata was being saved to the database, but WordPress cron events were executing before the database transaction was committed. This was especially problematic in high-traffic environments with database replication lag.

**Original Code** (1-second delay):
```php
set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );
$first_poll_time = time() + 1;
wp_schedule_single_event( $first_poll_time, self::CRON_POLL_HOOK, array( $job_id ) );
```

**Fix Applied**:
- Force cache flush after saving transient
- Increase delay from 1s to 3s
- Validate scheduling result

```php
set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );
wp_cache_flush(); // Force DB commit
$first_poll_time = time() + 3; // Increased delay
$scheduled = wp_schedule_single_event( $first_poll_time, self::CRON_POLL_HOOK, array( $job_id ) );
if ( false === $scheduled ) {
    // Handle failure immediately
}
```

### 2. Missing Scheduling Validation
**Issue**: `wp_schedule_single_event()` can return `false` when scheduling fails (e.g., disabled cron, DB issues), but this was not being checked. Jobs stayed in "pending" forever.

**Fix Applied**: Check return value and mark job as failed immediately:
```php
$scheduled = wp_schedule_single_event( $first_poll_time, self::CRON_POLL_HOOK, array( $job_id ) );
if ( false === $scheduled ) {
    $this->mark_job_as_failed( $job_id, $metadata, 'Failed to schedule...', ... );
    return array( 'async' => true, 'status' => 'failed', 'error' => ... );
}
```

### 3. Missing API Key Validation
**Issue**: If the Gemini API key was missing or became invalid, the polling would fail silently without updating job status.

**Fix Applied**: Validate API key before making API calls:
```php
if ( empty( $api_key ) ) {
    $this->mark_job_as_failed( $job_id, $metadata, 'API key not configured', ... );
    return;
}
```

### 4. Missing Hook Registration Check
**Issue**: If the service's `init()` method wasn't called (plugin load order issues), the cron hook wouldn't be registered, causing silent failures.

**Fix Applied**: Verify and re-register hook if missing:
```php
if ( ! has_action( self::CRON_POLL_HOOK ) ) {
    WP_MCP_AI_Logger::log_error( 'Veo cron hook not registered', ... );
    self::init(); // Re-initialize
}
```

### 5. Incomplete Error Tracking
**Issue**: Failed jobs weren't being stored in the cron manager for retrieval, and `completed_at` timestamps were missing.

**Fix Applied**: Store all terminal states with timestamps:
```php
$metadata['completed_at'] = time();
set_transient( self::ASYNC_OP_PREFIX . $job_id, $metadata, DAY_IN_SECONDS );

if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
    WP_MCP_AI_Cron_Manager::store_job_result( $job_id, new WP_Error( ... ) );
}

do_action( 'wp_mcp_ai_video_job_completed', $job_id, $metadata, 'failed' );
```

## Changes Summary

### Service Layer (`class-wp-mcp-ai-gemini-video-generation-service.php`)

#### New Helper Method
Added `mark_job_as_failed()` to centralize failure handling:
- Updates metadata with error and completed_at
- Stores result in cron manager
- Logs the failure
- Fires completion action hook
- Reduces code duplication (used in 4 failure paths)

#### `queue_async_polling()` Improvements
1. Cache flush before scheduling
2. Increased initial delay (1s → 3s)
3. Scheduling validation
4. Immediate failure handling
5. Enhanced logging

#### `poll_video_async()` Improvements
1. Hook registration verification
2. API key validation
3. Enhanced error logging
4. Completion tracking
5. Centralized failure handling

#### `schedule_next_poll()` Improvements
1. Pre-schedule metadata save
2. Cache flush before scheduling
3. Scheduling validation
4. Centralized failure handling
5. Enhanced logging

### Test Coverage (`tests/test-video-cron-fix.php`)
New test file with 225 lines covering:
1. Cron hook registration
2. Successful job queueing
3. Transient storage before scheduling
4. Missing API key handling
5. Max attempts timeout handling
6. Hook re-registration
7. Metadata saving order

## Impact Analysis

### Before Fix
- Jobs got stuck in "pending" status indefinitely
- No error feedback to users
- No visibility into failure reasons
- Manual intervention required to clear stuck jobs

### After Fix
- Jobs fail fast with clear error messages
- Errors stored for retrieval via check_video_status
- Completion action hooks fire for all terminal states
- Enhanced logging for debugging
- Proper status tracking in cron manager

## Testing Recommendations

### Manual Testing
1. **Normal Operation**: Generate a video and verify it completes
2. **Missing API Key**: Remove API key, verify job fails immediately
3. **Disabled Cron**: Disable WordPress cron, verify job fails on scheduling
4. **Network Issues**: Simulate API timeout, verify retry mechanism works
5. **Max Attempts**: Create job that exceeds max attempts, verify timeout handling

### Automated Testing
Run the new test suite:
```bash
vendor/bin/phpunit tests/test-video-cron-fix.php
```

### Integration Testing
Verify with existing test suite:
```bash
vendor/bin/phpunit tests/test-veo-async-video-generation.php
vendor/bin/phpunit tests/test-video-tools.php
```

## Migration Notes

### Backward Compatibility
- All changes are backward compatible
- Existing jobs in "pending" state will continue to work
- No database schema changes required
- No REST API changes

### Deployment Considerations
1. **No downtime required**: Changes are hot-swappable
2. **Clear cache**: Consider clearing object cache after deployment
3. **Monitor logs**: Watch for the new logging events
4. **Check stuck jobs**: May want to clean up pre-existing stuck jobs

## Performance Impact

### Positive Impacts
- Faster failure detection (jobs don't stay stuck)
- Reduced database load (fewer stale transients)
- Better resource cleanup

### Considerations
- `wp_cache_flush()` may have temporary impact on object cache
- 3-second delay adds 2 seconds to initial polling (acceptable for 60-120s video generation)
- Enhanced logging may increase log volume (only on errors)

## Future Improvements

### Potential Enhancements
1. **Retry Logic**: Add configurable retry attempts for transient API failures
2. **Health Checks**: Periodic verification that cron system is working
3. **Metrics**: Track success/failure rates, average completion times
4. **Admin Notifications**: Alert admins of repeated failures
5. **Status Dashboard**: Real-time view of all video generation jobs

### Monitoring Recommendations
Watch these log events:
- `veo_async_queued`: Job queued successfully
- `veo_poll_scheduled`: Next poll scheduled
- `veo_async_completed`: Job completed successfully
- `veo_job_failed`: Job failed (all failure paths)
- `veo_poll_error`: Polling error (before retry)

## References

- **Service File**: `includes/services/class-wp-mcp-ai-gemini-video-generation-service.php`
- **Test File**: `tests/test-video-cron-fix.php`
- **Related Tests**: `tests/test-veo-async-video-generation.php`
- **Cron Manager**: `includes/class-wp-mcp-ai-cron-manager.php`
- **Status Service**: `includes/services/class-wp-mcp-ai-cron-status-service.php`

## Security Considerations

### Validation Added
- API key presence validation
- Cron scheduling result validation
- Hook registration verification

### No Security Regressions
- All existing security checks maintained
- No new attack vectors introduced
- Proper capability checking unchanged

## SoC Compliance

All changes follow separation of concerns:
- **Service Layer**: Handles video generation and polling logic
- **Cron Manager**: Handles job tracking and result storage
- **Status Service**: Handles status reporting (unchanged)
- **Tool Layer**: Orchestrates service calls (unchanged)
- **REST API**: Exposes endpoints (unchanged)
- **Frontend**: Polls for status (unchanged)

Each layer has clear responsibilities and interfaces.
