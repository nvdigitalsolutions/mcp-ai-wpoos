# Cron Job Status Display in Chat Interface

## Overview

The chat interface now displays a lightweight status indicator showing pending and completed cron jobs. This feature helps users track background jobs created via the AI assistant without leaving the chat.

**New in v1.x**: The cron status endpoint now includes full result data for completed async tool executions, enabling seamless agentic workflows where the chat client can receive and process tool results automatically.

## User Experience

### Status Badge

When cron jobs exist, a compact status badge appears in the chat controls area showing:

- **Pending Jobs** (⏳ blue badge): Jobs scheduled to run in the future
- **Completed Jobs** (✓ green badge): Jobs that have finished execution

The badge automatically:
- Hides when no jobs exist (zero clutter)
- Updates every 30 seconds via background polling
- Shows only jobs created by the current user (or all jobs for admins)

### Example Display

```
[Jobs: ⏳ 2 ✓ 5]
```

This indicates 2 pending jobs and 5 completed jobs.

## Technical Implementation

### Architecture (Separation of Concerns)

#### Backend

**Service Layer** (`includes/services/class-wp-mcp-ai-cron-status-service.php`)
- Encapsulates cron status business logic
- Methods:
  - `get_status_summary($user_id, $limit)` - Returns job details
  - `get_status_counts($user_id)` - Returns counts by status

**REST API** (`includes/class-wp-mcp-ai-rest.php`)
- Endpoint: `GET /wp-json/mcp-ai/v1/cron-status`
- Parameters:
  - `limit` (optional): Max jobs to return (1-50, default 10)
  - `assistant_id` (optional): Filter jobs for specific assistant
- Response:
  ```json
  {
    "jobs": [
      {
        "job_id": "abc123...",
        "hook": "wp_mcp_ai_custom_task",
        "status": "pending",
        "next_run": {
          "timestamp": 1699999999,
          "relative": "In 5 minutes"
        },
        "created_by": 1
      },
      {
        "job_id": "async_xyz789",
        "hook": "wp_mcp_ai_async_tool_execution",
        "tool_slug": "generate_image",
        "status": "completed",
        "type": "async_tool",
        "completed_at": {
          "timestamp": 1699999000,
          "relative": "5 minutes ago"
        },
        "has_result": true,
        "result": {
          "text": "Image generated successfully",
          "data": {
            "attachment_id": 123,
            "url": "https://example.com/image.png"
          }
        },
        "duration": 45.3,
        "created_by": 1
      }
    ],
    "counts": {
      "pending": 1,
      "completed": 1,
      "total": 2
    }
  }
  ```

**Agentic Workflow Support**: For completed async tool executions (status: "completed"), the endpoint now includes the full `result` object. This enables the chat client to:
- Automatically receive tool execution results when polling or via SSE
- Continue multi-step workflows without user intervention
- Display rich results (images, files, structured data) directly in the chat

#### Frontend

**Service Module** (`assets/js/cron-status-service.js`)
- Handles API communication
- Manages polling lifecycle
- Caches results per chat instance
- Methods:
  - `fetchStatus(endpoint, nonce, limit)`
  - `startPolling(containerId, endpoint, nonce, callback)`
  - `stopPolling(containerId)`

**UI Integration** (`assets/js/chat.js`)
- Initializes cron status for each chat instance
- Updates badge display when status changes
- Stops polling when chat is hidden/destroyed

**Styling** (`assets/css/cron-status.css`)
- Responsive design
- Dark mode support
- High contrast mode support
- Accessible color combinations

### Status Determination

Jobs are classified based on WordPress cron state:

1. **Pending**: Job is scheduled in WordPress cron (`wp_get_scheduled_event()` returns event)
2. **Completed**: Job is not scheduled and `first_timestamp` is in the past

### Security & Privacy

- **Authentication**: Requires valid WordPress REST nonce, bearer token, or mesh API key
- **Authorization**: Any authenticated user can access the endpoint (no specific capability required)
- **User Filtering**: 
  - Regular users see only their own jobs
  - Administrators see all jobs
- **Data Minimization**: Returns only essential fields

### Performance

- **Polling Interval**: 30 seconds (configurable in `cron-status-service.js`)
- **Default Limit**: 10 jobs maximum
- **Caching**: Results cached client-side between polls
- **Auto-cleanup**: Stops polling when chat is hidden

## Usage Examples

### For Users

When you ask the AI assistant to create a cron job:

```
User: "Create a cron job to email me daily at 9am"
Assistant: *creates job using create_cron_job tool*
```

The status badge will immediately show:
```
[Jobs: ⏳ 1]
```

After the job runs at 9am, it updates to:
```
[Jobs: ✓ 1]
```

### For Developers

#### Testing the Endpoint

```bash
curl -X GET "https://yoursite.com/wp-json/mcp-ai/v1/cron-status?limit=5" \
  -H "X-WP-Nonce: YOUR_NONCE"
```

#### Creating Test Jobs

```php
// Create a test job
$hook = 'wp_mcp_ai_test_job';
$timestamp = time() + HOUR_IN_SECONDS;
wp_schedule_single_event( $timestamp, $hook, array() );
WP_MCP_AI_Cron_Manager::record_job( $hook, array(), 'single', $timestamp, get_current_user_id() );
```

## Configuration

### Polling Interval

Edit `assets/js/cron-status-service.js`:

```javascript
pollingInterval: 30000, // 30 seconds (in milliseconds)
```

### Job Retention

Configure in **Settings → Orchestration Layer → Cron Job History Retention**:
- Default: 24 hours
- Range: 1 hour to 30 days

Jobs are automatically removed after the retention period expires.

### Maximum Jobs Display

Modify the limit in `assets/js/chat.js`:

```javascript
window.wpMcpAiCronStatus.startPolling(instanceId, cronStatusEndpoint, nonce, updateCronStatusDisplay, 10); // last parameter is limit
```

## Browser Compatibility

- Modern browsers (Chrome, Firefox, Safari, Edge)
- Requires: 
  - `fetch()` API
  - `MutationObserver` API
  - CSS Grid/Flexbox

## Accessibility

- **ARIA**: Status badge uses `role="status"` and `aria-live="polite"`
- **Screen Readers**: Count numbers announced on update
- **Keyboard**: No interactive elements (display only)
- **High Contrast**: Border added in high contrast mode
- **Color Blind**: Uses icons (⏳ ✓) in addition to colors

## Troubleshooting

### Badge Not Appearing

1. **Check User Permissions**: Ensure user can access REST API
2. **Verify Nonce**: REST nonce must be valid
3. **Check Console**: Look for JavaScript errors
4. **Verify Jobs Exist**: Status badge hides when no jobs exist

### Badge Shows Zero Jobs

1. **Check Ownership**: Non-admin users only see their own jobs
2. **Check Retention**: Jobs may have been auto-pruned
3. **Verify Job Recording**: Ensure `WP_MCP_AI_Cron_Manager::record_job()` was called

### Polling Not Working

1. **Check Network Tab**: Verify API requests every 30 seconds
2. **Check Endpoint**: `/wp-json/mcp-ai/v1/cron-status` should return 200
3. **Check Service Load**: Ensure `cron-status-service.js` loaded before `chat.js`

## Future Enhancements

Potential improvements for future versions:

1. **Hover Tooltip**: Show job details on badge hover
2. **Click to Expand**: Open detailed job list modal
3. **Real-time Updates**: WebSocket support for instant updates
4. **Job Filtering**: Filter by status, hook name, or date
5. **Retry Failed Jobs**: UI for re-executing failed jobs
6. **Job Notifications**: Browser notifications when jobs complete

## Related Files

- Service: `includes/services/class-wp-mcp-ai-cron-status-service.php`
- REST: `includes/class-wp-mcp-ai-rest.php` (line ~1405)
- Frontend Service: `assets/js/cron-status-service.js`
- UI Integration: `assets/js/chat.js` (line ~8237)
- Styling: `assets/css/cron-status.css`
- Shortcode: `includes/class-wp-mcp-ai-shortcode.php` (lines ~51, ~508)
- Test Assistant: `includes/admin/class-wp-mcp-ai-admin-test-assistant.php` (line ~67)
- Tests: `tests/test-cron-status-service.php`, `tests/test-rest-cron-status.php`

## Credits

Implemented following WordPress coding standards and repository's separation of concerns architecture guidelines.
