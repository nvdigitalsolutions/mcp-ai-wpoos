# Video Job Notification System Verification

## Summary
This document verifies that video generation jobs (and other async tool jobs) now properly appear in the assistant widget's job bar after implementing the Event Dispatcher → Job Notifier bridging.

## System Architecture

### Complete Notification Flow

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. Video Generation Service                                     │
│    ├─ Queues job via queue_async_polling()                      │
│    ├─ Fires: do_action('wp_mcp_ai_video_job_queued', ...)      │
│    └─ Polls Gemini API in background via WP Cron                │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 2. Event Dispatcher Service (NEW BRIDGING LOGIC)                │
│    ├─ Listens to: wp_mcp_ai_video_job_queued                   │
│    ├─ Listens to: wp_mcp_ai_video_job_completed                │
│    ├─ Transforms to generic events:                             │
│    │  ├─ do_action('wp_mcp_ai_job_started', ...)               │
│    │  ├─ do_action('wp_mcp_ai_job_completed', ...)             │
│    │  └─ do_action('wp_mcp_ai_job_failed', ...)                │
│    └─ Also stores in user/assistant-specific transients         │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 3. Job Notifier System                                          │
│    ├─ Listens to generic events (wp_mcp_ai_job_*)              │
│    ├─ Caches job status in transients:                          │
│    │  └─ Key: wp_mcp_ai_job_status_{job_id}                    │
│    │  └─ TTL: 3600 seconds (1 hour)                            │
│    └─ Provides SSE streaming endpoints                          │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 4. Cron Status Service                                          │
│    ├─ Aggregates from 3 sources:                                │
│    │  ├─ Regular cron jobs (WP_MCP_AI_Cron_Manager)            │
│    │  ├─ Async tool jobs (transients)                           │
│    │  └─ Video jobs (transients)                                │
│    ├─ Filters by context ('chat' vs 'admin')                    │
│    └─ Returns unified job list                                  │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 5. REST API Endpoint                                            │
│    └─ GET /wp-json/mcp-ai/v1/cron-status?context=chat          │
│       └─ Returns: {jobs: [...], counts: {...}}                  │
└─────────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────────┐
│ 6. Chat Client JavaScript                                       │
│    ├─ Polls every 30 seconds (wpMcpAiCronStatus.pollingInterval)│
│    ├─ Updates job bar UI:                                       │
│    │  ├─ Pending count                                          │
│    │  ├─ Running count (with pulse animation)                   │
│    │  └─ Completed count (with checkmark)                       │
│    └─ Job bar visible when jobs exist                           │
└─────────────────────────────────────────────────────────────────┘
```

## Implementation Changes

### 1. Event Dispatcher Service
**File**: `includes/services/class-wp-mcp-ai-event-dispatcher-service.php`

**Modified Methods**:
- `handle_video_job_queued()` - Added `do_action('wp_mcp_ai_job_started')`
- `handle_video_job_completed()` - Added `do_action('wp_mcp_ai_job_completed')` or `wp_mcp_ai_job_failed`
- `handle_async_job_queued()` - Added `do_action('wp_mcp_ai_job_started')`
- `handle_async_job_completed()` - Added `do_action('wp_mcp_ai_job_completed')`
- `handle_async_job_failed()` - Added `do_action('wp_mcp_ai_job_failed')`

**SOC Compliance**: Event Dispatcher remains a pure event router with no business logic.

### 2. Integration Tests
**File**: `tests/test-event-dispatcher-job-notifier-integration.php`

**Test Coverage**:
- ✓ Video job queued creates job notifier entry
- ✓ Video job completed creates job notifier entry  
- ✓ Video job failed creates job notifier entry
- ✓ Async tool queued creates job notifier entry
- ✓ Async tool completed creates job notifier entry
- ✓ Async tool failed creates job notifier entry
- ✓ Job IDs with dots (from uniqid) work correctly
- ✓ Complete video generation lifecycle

## Verification Steps

### Backend Verification

1. **Check Event Dispatcher Initialization**
```bash
# Event dispatcher should be initialized on wp_mcp_ai_bootstrapped hook
grep -n "wp_mcp_ai_init_event_dispatcher" wp-mcp-ai.php
# Should show initialization at priority 5
```

2. **Check Job Notifier Initialization**
```bash
# Job notifier should be initialized early
grep -n "WP_MCP_AI_Job_Notifier::init" includes/job-notifier-init.php
```

3. **Verify Hook Registration**
```php
// In WordPress admin or debugging:
global $wp_filter;
print_r($wp_filter['wp_mcp_ai_video_job_completed']); // Should show Event Dispatcher listener
print_r($wp_filter['wp_mcp_ai_job_completed']); // Should show Job Notifier listener
```

### Frontend Verification

1. **Check Cron Status Service Loading**
```javascript
// In browser console:
console.log(window.wpMcpAiCronStatus);
// Should show: {fetchStatus: ƒ, startPolling: ƒ, stopPolling: ƒ, ...}
```

2. **Monitor Polling Requests**
```javascript
// In browser Network tab:
// Should see: GET /wp-json/mcp-ai/v1/cron-status?limit=10&context=chat&assistant_id=XXX
// Every 30 seconds
```

3. **Verify Job Bar Updates**
```javascript
// After triggering video generation:
// 1. Job bar should become visible
// 2. Running count should increment (with pulse animation)
// 3. After completion, completed count should increment
// 4. Job should remain visible in bar
```

## Test Scenarios

### Scenario 1: Video Generation Job
```
1. User sends message: "Generate a 5-second video of a sunset"
2. Tool: generate_veo_video executes
3. Expected behavior:
   ✓ Job bar appears immediately
   ✓ "Running: 1" shown with pulse animation
   ✓ After ~60-120 seconds: "Completed: 1" shown
   ✓ Assistant responds with video attachment
```

### Scenario 2: Multiple Concurrent Jobs
```
1. User triggers video generation
2. User triggers another async tool (e.g., crawl4ai)
3. Expected behavior:
   ✓ Job bar shows total running count
   ✓ Each job tracked independently
   ✓ Completed count increments as each finishes
```

### Scenario 3: Job Failure
```
1. User triggers video with invalid parameters
2. Expected behavior:
   ✓ Job bar shows running initially
   ✓ After failure: Job removed from running
   ✓ Error notification shown to user
   ✓ Job marked as failed in status
```

## Data Flow Verification

### Transient Keys Used

1. **Job Notifier Cache**
```
Key: wp_mcp_ai_job_status_{job_id}
Example: wp_mcp_ai_job_status_veo_69203b5b2388f5.11575461
TTL: 3600 seconds
```

2. **Video Job Metadata**
```
Key: wp_mcp_ai_veo_async_{job_id}
Example: wp_mcp_ai_veo_async_veo_69203b5b2388f5.11575461
TTL: 86400 seconds (24 hours)
```

3. **Event Dispatcher Notifications**
```
Key: wp_mcp_ai_notifications_{user_id}_{assistant_id}
Example: wp_mcp_ai_notifications_1_123
TTL: 3600 seconds
```

### REST Endpoints

1. **Cron Status Endpoint**
```
GET /wp-json/mcp-ai/v1/cron-status
Query params:
  - limit: 10 (default)
  - context: 'chat' (filters out internal jobs)
  - assistant_id: XXX (multi-widget isolation)

Response:
{
  "jobs": [
    {
      "job_id": "veo_abc123",
      "status": "running",
      "type": "video_generation",
      "created_by": 1,
      "assistant_id": 123,
      "queued_at": {...},
      "admin_url": "..."
    }
  ],
  "counts": {
    "pending": 0,
    "running": 1,
    "completed": 5,
    "failed": 0,
    "total": 6
  }
}
```

2. **Job Notifications Endpoint**
```
GET /wp-json/mcp-ai/v1/job-notifications
Query params:
  - assistant_id: XXX
  - clear: true (default)

Response:
{
  "notifications": [
    {
      "job_id": "veo_abc123",
      "status": "completed",
      "message": "Video generation completed (saved to media library #456)",
      "timestamp": 1234567890,
      "data": {
        "type": "video_generation",
        "result": {...}
      }
    }
  ],
  "count": 1
}
```

## Troubleshooting

### Jobs Not Appearing in Bar

**Symptoms**: Video generation jobs don't show in job bar

**Check**:
1. Event Dispatcher initialized? `WP_MCP_AI_Event_Dispatcher_Service::get_instance()`
2. Job Notifier initialized? `WP_MCP_AI_Job_Notifier::init()`
3. Event fired? Check `do_action('wp_mcp_ai_video_job_queued')`
4. Bridge working? Check `do_action('wp_mcp_ai_job_started')` fires after video event
5. Cache exists? `get_transient('wp_mcp_ai_job_status_' . $job_id)`

**Debug**:
```php
// Add to wp-config.php:
define('WP_MCP_AI_DEBUG', true);

// Check WordPress debug log for:
// - "Event dispatcher: video_job_queued for job veo_xxx"
// - "Job started: veo_xxx"
```

### Polling Not Working

**Symptoms**: UI doesn't update

**Check**:
1. JavaScript console for errors
2. Network tab for failed requests
3. Nonce validity
4. REST API permissions

**Debug**:
```javascript
// In console:
window.wpMcpAiCronStatus.pollers // Should show active pollers
window.wpMcpAiCronStatus.cache // Should show cached data
```

## Performance Considerations

### Database Queries
- Cron status service uses direct `$wpdb` query to find transients
- LIMIT 50 per query to prevent performance issues
- Transient expiration handled automatically by WordPress

### Polling Impact
- Client polls every 30 seconds
- Request is lightweight (< 1KB response)
- No impact on page load (asynchronous)
- Stops when no active jobs

### Memory Usage
- Event Dispatcher keeps last 50 notifications in memory
- Job Notifier uses WordPress transients (auto-cleanup)
- No persistent data growth

## Security

### Authentication
- Requires valid WordPress nonce OR bearer token
- User can only see their own jobs (unless admin)
- Assistant ID filter prevents cross-widget leakage

### Data Exposure
- Job metadata filtered by user_id
- Internal async jobs hidden in 'chat' context
- Admin-only jobs not exposed to non-admins

### Rate Limiting
- Polling interval fixed at 30 seconds (client-side)
- No rate limiting on backend (authenticated requests only)

## Conclusion

The video job notification system is now fully integrated:

✅ Event Dispatcher bridges domain-specific events to generic job lifecycle events
✅ Job Notifier caches status for all job types (video, async tools, cron)
✅ Cron Status Service aggregates from all sources
✅ REST API exposes unified job list with proper filtering
✅ Chat client polls and displays jobs in widget job bar
✅ Multi-widget isolation via assistant_id
✅ SOC maintained throughout the stack
✅ Comprehensive test coverage

Jobs from video generation tools (and other async tools) now properly appear in the assistant widget's job bar, providing real-time feedback to users.
