# Job Notification System

General-purpose infrastructure for real-time notifications on async WordPress jobs.

## Overview

The job notification system provides:
- **SSE Streaming**: Real-time status updates via Server-Sent Events
- **Webhooks**: HTTP callbacks for external integrations
- **Job Status Tracking**: Centralized status cache for all async operations

## Architecture

```
Async Job → WordPress Action → Job Notifier → [SSE | Webhooks]
                                                 ↓       ↓
                                            Frontend  External
```

## Using with Crawl4AI (Automatic)

The system automatically hooks into crawl4ai jobs via `wp_mcp_ai_crawl4ai_job_completed`:

```php
// No code needed - crawl4ai automatically triggers notifications
```

## Using with Custom Async Jobs

### 1. Trigger Job Events

```php
// When job starts
do_action( 'wp_mcp_ai_job_started', $job_id, array(
    'type' => 'image_generation',
    'user_id' => get_current_user_id(),
) );

// Progress updates
do_action( 'wp_mcp_ai_job_progress', $job_id, 45.5, array(
    'message' => 'Processing image 5 of 10',
) );

// On completion
do_action( 'wp_mcp_ai_job_completed', $job_id, $result, array(
    'duration' => 42.3,
) );

// On failure
do_action( 'wp_mcp_ai_job_failed', $job_id, new WP_Error( 'timeout', 'Job timed out' ), array() );
```

### 2. Frontend SSE Subscription

```javascript
const jobId = 'crawl_abc123';
const eventSource = new EventSource(
    `/wp-json/mcp-ai/v1/jobs/${jobId}/stream?max_duration=300&poll_interval=2`
);

eventSource.addEventListener('connected', (e) => {
    const data = JSON.parse(e.data);
    console.log('Connected to job stream:', data);
});

eventSource.addEventListener('status', (e) => {
    const status = JSON.parse(e.data);
    console.log('Job status:', status.status, status.progress);
    
    // Update UI
    if (status.progress !== undefined) {
        updateProgressBar(status.progress);
    }
});

eventSource.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log('Job completed:', data.final_status);
    eventSource.close();
});

eventSource.addEventListener('timeout', (e) => {
    console.warn('SSE stream timed out');
    eventSource.close();
});

eventSource.onerror = (error) => {
    console.error('SSE error:', error);
    eventSource.close();
};
```

### 3. Register Webhooks

```php
// Register webhook for specific job
WP_MCP_AI_Job_Notifier::register_webhook(
    'crawl_abc123',
    'https://example.com/webhook',
    array( 'completed', 'failed' )
);

// Register webhook for all jobs
WP_MCP_AI_Job_Notifier::register_webhook(
    '*',
    'https://example.com/all-jobs-webhook',
    array( 'started', 'progress', 'completed', 'failed' )
);
```

Via REST API:

```bash
curl -X POST https://yoursite.com/wp-json/mcp-ai/v1/jobs/crawl_abc123/webhooks \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "webhook_url": "https://example.com/webhook",
    "events": ["completed", "failed"]
  }'
```

### 4. Webhook Payload

Your webhook endpoint will receive:

```json
{
  "event": "completed",
  "job_id": "crawl_abc123",
  "data": {
    "job_id": "crawl_abc123",
    "status": "completed",
    "completed_at": "2025-01-15T10:30:00Z",
    "result": {
      "urls": [...],
      "markdown": "..."
    },
    "metadata": {
      "duration": 42.3
    }
  },
  "sent_at": "2025-01-15T10:30:01Z"
}
```

## REST API Endpoints

### GET /wp-json/mcp-ai/v1/jobs/{job_id}/stream

Stream job status updates via SSE.

**Parameters:**
- `job_id` (required): Job identifier
- `max_duration` (optional): Max connection time in seconds (10-600, default 300)
- `poll_interval` (optional): Polling interval in seconds (1-30, default 2)

**Response:** SSE stream with events:
- `connected`: Initial connection
- `status`: Job status update
- `complete`: Job reached terminal state
- `timeout`: Max duration reached
- `close`: Stream closing

### GET /wp-json/mcp-ai/v1/jobs/{job_id}

Get current job status (non-streaming).

**Response:**
```json
{
  "job_id": "crawl_abc123",
  "status": "running",
  "progress": 67.5,
  "started_at": "2025-01-15T10:28:00Z",
  "updated_at": "2025-01-15T10:29:30Z",
  "metadata": {}
}
```

### POST /wp-json/mcp-ai/v1/jobs/{job_id}/webhooks

Register webhook for job notifications.

**Request:**
```json
{
  "webhook_url": "https://example.com/webhook",
  "events": ["completed", "failed"]
}
```

**Response:**
```json
{
  "success": true,
  "job_id": "crawl_abc123",
  "webhook_url": "https://example.com/webhook",
  "events": ["completed", "failed"],
  "message": "Webhook registered successfully."
}
```

## Use Cases

### Real-time Crawl4AI Updates

```javascript
// Subscribe to crawl job
const source = new EventSource(`/wp-json/mcp-ai/v1/jobs/crawl_${taskId}/stream`);
source.addEventListener('status', (e) => {
    const status = JSON.parse(e.data);
    if (status.status === 'completed') {
        displayCrawlResults(status.result);
    }
});
```

### Bulk Post Creation

```php
function create_posts_async( $post_data_array ) {
    $job_id = 'bulk_posts_' . wp_generate_uuid4();
    
    do_action( 'wp_mcp_ai_job_started', $job_id, array( 'total' => count( $post_data_array ) ) );
    
    foreach ( $post_data_array as $index => $post_data ) {
        wp_insert_post( $post_data );
        
        $progress = ( $index + 1 ) / count( $post_data_array ) * 100;
        do_action( 'wp_mcp_ai_job_progress', $job_id, $progress, array(
            'created' => $index + 1,
            'total' => count( $post_data_array ),
        ) );
    }
    
    do_action( 'wp_mcp_ai_job_completed', $job_id, array( 'created' => count( $post_data_array ) ) );
    
    return $job_id;
}
```

### External Service Integration

```php
// Register webhook to notify external CRM when image generation completes
add_action( 'wp_mcp_ai_job_started', function( $job_id, $metadata ) {
    if ( isset( $metadata['type'] ) && 'image_generation' === $metadata['type'] ) {
        WP_MCP_AI_Job_Notifier::register_webhook(
            $job_id,
            'https://crm.example.com/api/image-ready',
            array( 'completed' )
        );
    }
}, 10, 2 );
```

## Performance Considerations

### SSE Connections
- Max 5 minutes per connection (configurable)
- Server polls cache every 2 seconds (configurable)
- Heartbeat every 15 seconds to keep connection alive
- Consider using Redis for job status cache in high-traffic scenarios

### Webhooks
- Delivered asynchronously via WP-Cron
- 10-second timeout per webhook
- Max 10 webhooks per job
- Failed deliveries are logged

### Job Status Cache
- Stored in WordPress transients
- 1-hour TTL (configurable)
- Automatically cleaned up after expiration

## Security

### SSE Endpoints
- Require authentication (logged-in user or bearer token)
- Job IDs should be unpredictable (use UUIDs)
- Rate limiting recommended

### Webhooks
- Only admins can register webhooks
- Webhook URLs validated before registration
- Sent over HTTPS recommended
- Include signature verification in production

## WordPress Actions

Trigger these actions in your code:

- `wp_mcp_ai_job_started` - Job initialization
- `wp_mcp_ai_job_progress` - Progress updates
- `wp_mcp_ai_job_completed` - Successful completion
- `wp_mcp_ai_job_failed` - Error occurred

## Filters

Modify behavior:

```php
// Adjust cache duration
add_filter( 'wp_mcp_ai_job_cache_duration', function( $duration ) {
    return 7200; // 2 hours
} );

// Customize webhook payload
add_filter( 'wp_mcp_ai_webhook_payload', function( $payload, $job_id, $event ) {
    $payload['custom_field'] = 'value';
    return $payload;
}, 10, 3 );
```
