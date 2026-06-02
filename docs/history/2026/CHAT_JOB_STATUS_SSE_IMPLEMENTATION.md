# Real-Time Job Status Updates in Chat Interface

## Overview

This feature adds real-time job status notifications to the chat interface via Server-Sent Events (SSE), providing users with live updates on cron jobs and crawl4ai jobs during AI conversations.

## Problem Solved

**Before**: Users had no visibility into job progress during conversations. They had to wait silently for completion with no feedback on long-running async jobs (cron scheduling, web crawling, etc.).

**After**: Users see real-time status updates in the chat interface as jobs progress, with messages like "Processing... 50%" and "Job completed successfully".

## Architecture

### Full Stack Implementation

```
┌─────────────────────────────────────────────────────────────┐
│                    Backend (PHP)                             │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  Job Status Change (WP Cron / Crawl4AI)                    │
│              ↓                                               │
│  WP_MCP_AI_Job_Notifier::handle_job_*()                    │
│              ↓                                               │
│  emit_sse_event($job_id, $type, $status)                   │
│              ↓                                               │
│  do_action('wp_mcp_ai_emit_sse_event', $name, $data)       │
│              ↓                                               │
│  WP_MCP_AI_REST_Chat_Controller::handle_sse_job_event()    │
│              ↓                                               │
│  WP_MCP_AI_SSE_Handler::send_sse_event()                   │
│                                                              │
└─────────────────────────────────────────────────────────────┘
                         ↓ SSE Stream
┌─────────────────────────────────────────────────────────────┐
│                    Frontend (JavaScript)                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  SSE Event Received                                         │
│              ↓                                               │
│  chat.js event handler                                      │
│              ↓                                               │
│  ┌──────────────────┬─────────────────────┐               │
│  │ Update Chat UI   │ Emit to Job Bus     │               │
│  │ setStatus()      │ wpMcpAiJobBus       │               │
│  └──────────────────┴─────────────────────┘               │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

## Event Types

### 1. cron_job_status_update
Emitted for WordPress cron jobs created by AI assistants.

**When**: Cron job status changes (started, progress, completed, failed)
**Detection**: Job ID starts with `cron_` OR metadata contains `tool: 'create_cron_job'`

### 2. crawl4ai_job_status_update
Emitted for web crawling jobs.

**When**: Crawl4AI job status changes
**Detection**: Job ID starts with `crawl` OR `crawl4ai` OR metadata contains `tool: 'run_crawl4ai_job'`

### 3. job_status_update
Generic fallback for other async jobs.

**When**: Other async operations complete
**Detection**: Default when job type cannot be determined

## Event Data Structure

All events include:

```javascript
{
  "job_id": "string",        // Unique job identifier
  "status": "string",        // started|running|completed|failed
  "progress": number|null,   // 0-100 percentage (progress only)
  "message": "string",       // Human-readable status message
  "metadata": {              // Job-specific metadata
    "tool": "string",        // Tool that created the job
    "user_id": number,       // User who initiated
    // ... tool-specific fields
  },
  "result": object,          // Job result (completed only)
  "error": {                 // Error details (failed only)
    "message": "string",
    "code": "string"
  }
}
```

## Implementation Details

### Backend: Job Notifier

**File**: `includes/class-wp-mcp-ai-job-notifier.php`

**Key Method**: `emit_sse_event()`

```php
protected static function emit_sse_event( $job_id, $event_type, $status ) {
    // Only emit in SSE context
    if ( ! defined( 'WP_MCP_AI_SSE_ACTIVE' ) || ! WP_MCP_AI_SSE_ACTIVE ) {
        return;
    }

    // Determine event name (cron_job_status_update, etc.)
    $sse_event_name = self::get_sse_event_name_for_job( $job_id, $event_type );

    // Build event data
    $event_data = array(
        'job_id'   => $job_id,
        'status'   => $status['status'],
        'progress' => $status['progress'] ?? null,
        'message'  => self::get_status_message( $status, $event_type ),
        'metadata' => $status['metadata'] ?? array(),
    );

    // Emit via WordPress action
    do_action( 'wp_mcp_ai_emit_sse_event', $sse_event_name, $event_data );
}
```

**Job Type Detection**:
1. Check job ID prefix (`cron_`, `crawl`, `crawl4ai_`)
2. Check metadata `tool` field
3. Fall back to generic `job_status_update`

**Status Messages**:
- Custom messages from metadata take priority
- Default messages generated based on event type
- Includes progress percentage when available

### Backend: Chat Controller

**File**: `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`

**Constructor Hook**:
```php
public function __construct( $main_controller, $authenticator, $validator ) {
    parent::__construct( $authenticator, $validator );
    $this->main_controller = $main_controller;
    
    // Hook into SSE job status events
    add_action( 'wp_mcp_ai_emit_sse_event', array( $this, 'handle_sse_job_event' ), 10, 2 );
}
```

**Event Handler**:
```php
public function handle_sse_job_event( $event_name, $event_data ) {
    // Get SSE handler from main controller
    $sse_handler = $main_controller->get_sse_handler();
    
    // Stream the event to connected clients
    $sse_handler->send_sse_event( $event_name, $event_data );
}
```

### Frontend: Chat Interface

**File**: `assets/js/chat.js`

**SSE Event Handlers**:
```javascript
sseConnection = sseService.connect(url, {
    eventHandlers: {
        cron_job_status_update: function (payload) {
            // Log for debugging
            console.log('[NV oOS] Cron job status update:', payload);
            
            // Emit to job event bus for coordination
            if (window.wpMcpAiJobBus && payload.job_id) {
                window.wpMcpAiJobBus.handleJobUpdate(payload.job_id, payload);
            }
            
            // Update chat UI with status message
            if (payload.message && state && state.container) {
                setStatus(state.container, {
                    message: payload.message,
                    type: 'text-stream',
                    showTime: false
                });
            }
        },
        
        crawl4ai_job_status_update: function (payload) {
            // Same pattern as cron_job_status_update
        },
        
        job_status_update: function (payload) {
            // Generic fallback handler
        }
    }
});
```

**UI Integration**:
- Uses existing `setStatus()` function
- Displays messages in chat status indicator
- Type: `'text-stream'` for streaming appearance
- `showTime: false` for cleaner display

**Job Event Bus**:
- All handlers emit to `wpMcpAiJobBus` if available
- Allows coordination with cron status bar
- Maintains existing job tracking infrastructure

## Testing

**File**: `tests/test-chat-job-status-sse.php`

**13 Comprehensive Tests**:

1. **test_sse_event_emitted_on_job_start**: Verifies SSE events emitted when jobs start
2. **test_cron_job_emits_correct_event_type**: Confirms cron jobs emit `cron_job_status_update`
3. **test_crawl4ai_job_emits_correct_event_type**: Confirms crawl4ai jobs emit `crawl4ai_job_status_update`
4. **test_progress_event_includes_percentage**: Validates progress events contain percentage
5. **test_completed_event_includes_result**: Verifies completed events include result data
6. **test_failed_event_includes_error**: Checks failed events include error information
7. **test_no_sse_event_outside_sse_context**: Ensures events only emit in SSE context
8. **test_status_messages_generated**: Validates status message generation
9. **test_custom_messages_in_metadata**: Confirms custom messages from metadata are used
10. **test_metadata_included_in_event**: Verifies metadata is included in events
11. **test_chat_controller_registers_sse_handler**: Confirms Chat Controller hooks into events
12. **test_progress_value_normalized**: Tests progress values are capped at 0-100

**Running Tests**:
```bash
vendor/bin/phpunit tests/test-chat-job-status-sse.php
```

## User Experience Examples

### Example 1: Cron Job Creation

**User Input**: "Schedule a post to publish tomorrow at 9am"

**Chat Flow**:
```
User: Schedule a post to publish tomorrow at 9am
Assistant: [calls create_cron_job tool]
Chat UI: Job started
Chat UI: Processing... 0%
Chat UI: Job completed successfully
Assistant: I've scheduled your post to publish tomorrow at 9:00 AM. 
          The cron job has been created and will run automatically.
```

### Example 2: Web Crawling

**User Input**: "Crawl https://example.com and summarize the content"

**Chat Flow**:
```
User: Crawl https://example.com and summarize the content
Assistant: [calls run_crawl4ai_job tool]
Chat UI: Job started
Chat UI: Crawling page 1 of 4
Chat UI: Crawling page 2 of 4
Chat UI: Crawling page 3 of 4
Chat UI: Crawling page 4 of 4
Chat UI: Job completed successfully
Assistant: [displays crawl results and summary]
```

### Example 3: Progress Updates

**Visual Feedback**:
```
┌─────────────────────────────────────────┐
│ Chat Interface                          │
├─────────────────────────────────────────┤
│ User: Schedule a task                   │
│                                         │
│ Assistant: Creating cron job...         │
│                                         │
│ [Status: Processing... 50%]             │  ← Real-time update
│                                         │
│ [Status: Job completed successfully]    │  ← Final status
│                                         │
│ Assistant: Task scheduled for...        │
└─────────────────────────────────────────┘
```

## Benefits

### For Users
✅ **Transparency**: See what's happening in real-time
✅ **Progress Feedback**: Know how long to wait
✅ **Error Visibility**: Immediate notification if something fails
✅ **Better UX**: Reduced perceived wait time

### For Developers
✅ **Reusable Infrastructure**: Works with existing SSE system
✅ **Extensible**: Easy to add new job types
✅ **Well-Tested**: 13 comprehensive tests
✅ **Standards-Compliant**: Follows WordPress coding standards

### For System
✅ **No Polling**: SSE push reduces server load
✅ **Session-Isolated**: No cross-talk between users
✅ **Backward Compatible**: Falls back gracefully
✅ **Performant**: Minimal overhead

## Configuration

### SSE Context

Events only emit when `WP_MCP_AI_SSE_ACTIVE` is defined and true. This constant is automatically defined by the SSE Handler when an SSE stream is active.

**No Configuration Needed**: The system automatically detects SSE context and emits events appropriately.

### Custom Messages

You can provide custom status messages in job metadata:

```php
do_action( 'wp_mcp_ai_job_progress', 'my_job_id', 50, array(
    'message' => 'Processing step 2 of 4...',
    'tool'    => 'my_custom_tool',
) );
```

## Integration with Existing Features

### Admin Monitoring Pages
The admin pages (Cron Manager, Crawl4AI Monitor) have **separate** auto-refresh via AJAX. They are independent systems:

| Feature | Admin Pages | Chat Interface |
|---------|-------------|----------------|
| Update Method | AJAX polling (10-15s) | SSE push (real-time) |
| Purpose | Monitoring/management | User conversation |
| Auth | `manage_options` | Multiple modes |
| Endpoint | admin-ajax.php | REST API SSE |

### Cron Status Bar
The job event bus (`wpMcpAiJobBus`) coordinates between:
- Chat interface (this feature)
- Cron status bar (job tracking widget)
- Other job-aware components

All handlers emit to the bus, ensuring consistent state across the UI.

## Troubleshooting

### Events Not Appearing

**Check**:
1. Is SSE connection established? (Check browser console)
2. Is `WP_MCP_AI_SSE_ACTIVE` defined during job execution?
3. Are WordPress actions firing? (Add logging to handlers)

**Debug**:
```javascript
// Browser console
console.log('[NV oOS] Cron job status update:', payload);
```

### Wrong Event Type

**Issue**: Job emitting generic `job_status_update` instead of specific type

**Fix**: Ensure job ID includes proper prefix (`cron_`, `crawl`) OR metadata includes `tool` field:

```php
// Option 1: Job ID prefix
$job_id = 'cron_my_task';

// Option 2: Metadata tool field
$metadata = array( 'tool' => 'create_cron_job' );
```

### Messages Not Displaying

**Issue**: Events received but UI not updating

**Fix**: Verify `setStatus()` is working and container element exists:

```javascript
if (payload.message && state && state.container) {
    setStatus(state.container, {
        message: payload.message,
        type: 'text-stream',
        showTime: false
    });
}
```

## Future Enhancements

Potential improvements for future versions:

1. **Visual Progress Bars**: Display progress as a bar instead of percentage
2. **Job History**: Show list of recent jobs in chat sidebar
3. **Pause/Cancel**: Allow users to pause or cancel running jobs
4. **Notifications**: Browser notifications for completed jobs
5. **Job Groups**: Track multiple related jobs together
6. **Retry Failed Jobs**: Quick retry button for failed jobs

## Related Documentation

- **Admin Pages Enhancement**: `ADMIN_PAGES_ENHANCEMENT.md`
- **Test Coverage**: `ADMIN_PAGE_JOB_TRACKING_TEST_COVERAGE.md`
- **Visual Flow**: `ADMIN_PAGE_TEST_FLOW_VISUAL.md`
- **SSE Architecture**: `docs/features/streaming/SSE_NOTIFICATION_ARCHITECTURE.md`
- **Job Notifier**: `includes/class-wp-mcp-ai-job-notifier.php` (inline docs)

## Credits

Implemented as follow-up enhancement to admin pages auto-refresh feature.

**Commits**:
- `4917b82` - Backend SSE event emission
- `6655f19` - Frontend event handlers
- `e64861c` - Comprehensive test suite

**Author**: GitHub Copilot for @nvdigitalsolutions
**Date**: January 29, 2026
