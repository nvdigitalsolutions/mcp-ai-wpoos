# SSE and Polling Migration Guide

## Overview

As of version 1.1.0, the WP oOS plugin has removed legacy cron status polling in favor of a modern SSE (Server-Sent Events) first approach with automatic polling fallback.

## What Changed

### Before (Legacy System)

The plugin used **dual polling** which resulted in duplicate API calls:
1. **Cron Status Polling** (`cron-status-service.js`) - Polled `/cron-status` endpoint every 30 seconds
2. **Notification Polling** - Polled `/job-notifications` endpoint separately

This created unnecessary server load and redundant API calls.

### After (Modern System)

The plugin now uses a **unified SSE-first approach**:
1. **Attempts SSE connection** first via `/job-notifications/stream`
2. **Real-time updates** when SSE is available (checks every 2 seconds server-side)
3. **Automatic fallback** to polling every 30 seconds if SSE is unavailable
4. **Single endpoint** (`/job-notifications`) provides both notifications and job counts

## Migration Steps

### For Plugin Users

**No action required!** The migration is automatic. The chat client will:
- Attempt to use SSE for real-time updates
- Automatically fall back to 30-second polling if SSE is not available
- Continue to display job status and notifications as before

### For Developers

If you have customized the chat interface or enqueued scripts manually:

1. **Remove** the legacy cron status service script:
   ```php
   // REMOVE THIS:
   wp_enqueue_script('wp-mcp-ai-cron-status', ...);
   ```

2. **Add** the SSE service script dependency:
   ```php
   // ADD THIS:
   wp_enqueue_script('wp-mcp-ai-sse-service', 
       WP_MCP_AI_URL . 'assets/js/sse-service.js',
       array(),
       $version,
       true
   );
   ```

3. **Update** chat.js dependencies:
   ```php
   wp_enqueue_script('wp-mcp-ai-chat',
       WP_MCP_AI_URL . 'assets/js/chat.js',
       array('wp-mcp-ai-sse-service'), // Changed from 'wp-mcp-ai-cron-status'
       $version,
       true
   );
   ```

4. **Keep** the cron status CSS (for job bar styling):
   ```php
   wp_enqueue_style('wp-mcp-ai-cron-status',
       WP_MCP_AI_URL . 'assets/css/cron-status.css',
       array(),
       $version
   );
   ```

## Technical Details

### SSE Implementation

When available, SSE provides:
- **Real-time updates** - No waiting for polling interval
- **Efficient** - Server pushes updates only when needed
- **Persistent connection** - Reduces connection overhead
- **Heartbeat** - Keeps connection alive (every 15 seconds)

### Polling Fallback

When SSE is unavailable (old browsers, restrictive firewalls, etc.):
- **30-second interval** - Balances responsiveness with server load
- **Exponential backoff** - Increases interval to 5 minutes on rate limiting
- **Automatic retry** - Recovers from temporary failures
- **Same functionality** - No feature loss

### Browser Support

- **SSE Supported**: Modern browsers (Chrome, Firefox, Safari, Edge)
- **Fallback**: All browsers via polling (including IE11)

## Benefits

1. **Reduced Server Load**
   - Eliminated duplicate polling
   - SSE reduces HTTP request overhead

2. **Better User Experience**
   - Real-time job updates when SSE available
   - Faster notification delivery

3. **Cleaner Architecture**
   - Single consolidated endpoint
   - Reduced code complexity
   - Easier to maintain

4. **Backwards Compatible**
   - Automatic fallback ensures it works everywhere
   - No user-facing changes

## Troubleshooting

### Issue: Job counts not updating

**Check:**
1. Browser console for SSE connection errors
2. Server supports SSE (most modern servers do)
3. Firewall/proxy isn't blocking EventSource

**Solution:** The system will automatically fall back to polling. Check console logs for:
```
[WP oOS] SSE error, falling back to polling
[WP oOS] Using polling mode for notifications
```

### Issue: Too many polling requests

**Check:**
1. Ensure you've removed legacy `cron-status-service.js` enqueue
2. Check for duplicate chat instances on the same page

**Solution:** Use browser dev tools Network tab to verify only one polling request every 30 seconds.

### Issue: SSE connection drops frequently

This is normal behavior. SSE connections:
- Have a 5-minute default timeout (configurable)
- Automatically reconnect on error
- Fall back to polling if unstable

## Files Modified

- `includes/class-wp-mcp-ai-shortcode.php` - Updated script dependencies
- `includes/admin/class-wp-mcp-ai-admin-test-page-base.php` - Updated script dependencies
- `assets/js/chat.js` - Removed legacy polling code
- `assets/js/cron-status-service.js` - Marked as deprecated

## Future Plans

In version 2.0.0, the deprecated `cron-status-service.js` file will be removed entirely. Ensure you've migrated to the new system before upgrading.

## API Reference

### SSE Endpoint

```
GET /wp-json/mcp-ai/v1/job-notifications/stream?assistant_id={id}
```

**Parameters:**
- `assistant_id` (required) - Assistant ID to monitor
- `max_duration` (optional) - Maximum stream duration in seconds (default 300, max 600)
- `poll_interval` (optional) - Server-side check interval in seconds (default 2, max 30)

**Events:**
- `connected` - Connection established
- `job_counts` - Job count update
- `notification` - Job completion notification
- `heartbeat` - Connection alive signal
- `timeout` - Maximum duration reached
- `close` - Stream closed

### Polling Endpoint

```
GET /wp-json/mcp-ai/v1/job-notifications?assistant_id={id}&clear=true
```

**Parameters:**
- `assistant_id` (required) - Assistant ID to monitor
- `clear` (optional) - Whether to clear notifications after retrieval (default false)

**Response:**
```json
{
  "notifications": [...],
  "job_counts": {
    "pending": 0,
    "running": 0,
    "completed": 0,
    "failed": 0,
    "total": 0
  }
}
```

## Questions?

For issues or questions:
1. Check the plugin logs (Settings → WP oOS → Enable Logging)
2. Review browser console for errors
3. Open an issue on GitHub: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
