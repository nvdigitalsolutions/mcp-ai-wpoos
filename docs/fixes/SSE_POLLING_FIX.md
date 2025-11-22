# SSE Polling Fix for Async Video Generation

## Problem
When video generation completes via cron, the chat client was not receiving notification because the SSE endpoint sent a single status event and immediately closed the connection.

## Root Cause
The `handle_cron_job_details_request()` method used `stream_event_stream_payload()` which:
1. Sends current job status as a single SSE event
2. Immediately sends `[DONE]` marker
3. Closes the connection

If the job was still "polling" when the client connected, the client would:
- Receive one "polling" status event
- Receive `[DONE]` marker
- Connection closes
- Never learn when the video completes

## Solution
Modified the REST controller to continuously poll job status when SSE streaming is requested:

### New Method: `stream_job_status_with_polling()`
- **Polling interval**: 3 seconds (matches client expectation)
- **Max duration**: 3 minutes (180 seconds)
- **Safety limits**: Max iterations = (max_duration / poll_interval) + 10

### Polling Loop
1. Check for client disconnection → exit early if disconnected
2. Get current job details from service
3. Handle errors → send error event and exit
4. Send current status as SSE event
5. Check if terminal state (completed/failed/error) → send `[DONE]` and exit
6. Still pending/polling → sleep for poll_interval and continue

### Terminal States
- `completed` - Job finished successfully, result available
- `failed` - Job failed with error message
- `error` - Job encountered error

### Event Flow
```
Client connects → GET /cron-status/{job_id}?stream=true

Server establishes SSE connection
↓
Server polls job status (every 3 seconds)
↓
event: cron_job_status
data: {"status":"pending","job_id":"veo_123..."}
↓
event: cron_job_status
data: {"status":"polling","poll_attempt":5,...}
↓
[Cron completes video in background]
↓
event: cron_job_status
data: {"status":"completed","result":{"url":"...","attachment_id":123,...}}
↓
data: [DONE]
↓
Connection closes
```

## Client Compatibility
The existing client code in `assets/js/chat.js` is already compatible:
- Listens for `cron_job_status` events
- Updates UI with "processing" status for non-terminal states
- Extracts and displays result when status is "completed"
- Handles errors when status is "failed"

No client-side changes required! ✅

## Testing
Created comprehensive test suite in `tests/test-sse-job-polling.php`:
- ✅ Video job metadata structure validation
- ✅ Cron status service retrieval
- ✅ Completed job includes result data
- ✅ Failed job includes error message
- ✅ Permission checks
- ✅ Terminal status detection
- ✅ Non-terminal status detection

## Safety Features
1. **Connection abortion detection** - Prevents resource exhaustion from abandoned connections
2. **Maximum iteration limit** - Prevents infinite loops
3. **Timeout limit** - Prevents hung connections (3 minutes)
4. **Proper error handling** - Sends error events instead of crashing

## Performance Impact
- Minimal - only affects connections with `stream=true` parameter
- Polling every 3 seconds is efficient (not a tight loop)
- Automatically terminates when job completes or times out
- Detects client disconnection to free resources early

## Files Modified
- `includes/class-wp-mcp-ai-rest.php` - Added polling method
- `tests/test-sse-job-polling.php` - NEW test coverage

## Related Documentation
- ASYNC_VIDEO_GENERATION.md - Async video generation architecture
- test-async-flow.md - Async tool execution flow
- CRON_ASYNC_VALIDATION_SUMMARY.md - Cron system validation
