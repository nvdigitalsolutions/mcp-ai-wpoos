# Dead Letter Queue (DLQ) Architecture

**Last Updated:** January 2026  
**Version:** 1.1.0  
**Status:** Implemented

## Overview

The Dead Letter Queue (DLQ) provides persistent storage and management for permanently failed operations in the WordPress-native cron manager and orchestration layer (when RabbitMQ is not available). It ensures failed jobs, webhooks, and async operations are not lost and can be retried, audited, or diagnosed.

## Purpose

When asynchronous operations fail after multiple retries, they need a place to go for:
1. **Auditability** - Track what failed and why
2. **Retryability** - Allow manual or automated retry of failed operations
3. **Debugging** - Provide detailed failure information for diagnosis
4. **Compliance** - Maintain records of failed operations for regulatory requirements

## Architecture

### Components

```
┌─────────────────────────────────────────────────────────────────┐
│                    Job/Notification Sources                      │
│  ┌──────────────┐  ┌──────────────┐  ┌─────────────────────┐   │
│  │ Job Queue    │  │  Webhooks    │  │  Crawl4AI Jobs      │   │
│  │  Manager     │  │  Notifier    │  │   (Enhanced)        │   │
│  └──────────────┘  └──────────────┘  └─────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
                             │
                             ▼ (After max retries)
┌─────────────────────────────────────────────────────────────────┐
│              WP_MCP_AI_Dead_Letter_Queue                         │
│  ┌───────────────────────────────────────────────────────────┐  │
│  │  Storage: wp_mcp_ai_dead_letter_queue option               │  │
│  │  Max Items: 1000 (automatic pruning)                       │  │
│  │  Retention: 30 days default                                │  │
│  └───────────────────────────────────────────────────────────┘  │
│                                                                  │
│  Item Types:                                                     │
│  • TYPE_CRON_JOB     - Failed cron jobs                        │
│  • TYPE_WEBHOOK      - Failed webhook deliveries               │
│  • TYPE_ASYNC_TOOL   - Failed async tool executions            │
│  • TYPE_JOB_QUEUE    - Failed queue manager jobs               │
│                                                                  │
│  Operations:                                                     │
│  • add()          - Add failed item to DLQ                      │
│  • retry()        - Retry a failed item                         │
│  • dismiss()      - Mark item as acknowledged                   │
│  • purge_old()    - Remove old items                            │
│  • get_stats()    - Get DLQ statistics                          │
└─────────────────────────────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────┐
│                   Admin UI (Future)                              │
│  • View failed items                                             │
│  • Retry/dismiss/delete bulk actions                             │
│  • Filter by type, date, status                                  │
│  • Export to CSV                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Data Model

Each DLQ item contains:

```php
array(
    'id'             => 'unique_item_id',          // MD5 hash
    'type'           => 'cron_job|webhook|...',   // Item type
    'identifier'     => 'original_job_id',         // Original identifier
    'data'           => array(...),                // Full job/webhook data
    'failure_reason' => 'Error message',           // Why it failed
    'retry_history'  => array(                     // Array of retry attempts
        array(
            'timestamp' => 1234567890,
            'result'    => 'failed',
            'error'     => 'Error message'
        ),
        // ... more attempts
    ),
    'retry_count'    => 3,                         // Total retries
    'added_at'       => '2026-01-03 19:00:00',    // When added to DLQ
    'added_timestamp' => 1234567890,               // Unix timestamp
    'dismissed'      => false,                     // User acknowledged?
    'dismissed_at'   => null,                      // When dismissed
)
```

## Integration Points

### 1. Job Queue Manager

**File:** `includes/class-wp-mcp-ai-job-queue-manager.php`

**Integration:** In `handle_job_failure()` method, after max retries (3):

```php
// Max retries exceeded - move to dead letter queue.
if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
    WP_MCP_AI_Dead_Letter_Queue::add(
        WP_MCP_AI_Dead_Letter_Queue::TYPE_JOB_QUEUE,
        $job_id,
        array(
            'job_id'   => $job_id,
            'job_data' => $job,
        ),
        $error->get_error_message(),
        $retry_history
    );
}
```

### 2. Webhook Notifier

**File:** `includes/class-wp-mcp-ai-job-notifier.php`

**Integration:** New `handle_webhook_failure()` method tracks retries via transients:

```php
protected static function handle_webhook_failure( $url, $payload, $error ) {
    // Track retry count in transients.
    $retry_count = get_transient( $retry_key );
    $retry_count++;
    
    if ( $retry_count >= 3 ) {
        // Move to DLQ.
        WP_MCP_AI_Dead_Letter_Queue::add(
            WP_MCP_AI_Dead_Letter_Queue::TYPE_WEBHOOK,
            $identifier,
            array( 'url' => $url, 'payload' => $payload ),
            $error->get_error_message(),
            $retry_history
        );
    }
}
```

### 3. Crawl4AI Crawler

**File:** `includes/crawler/class-wp-mcp-ai-crawler.php`

**New Feature:** Exponential backoff retry with DLQ fallback:

```php
protected static function finalise_with_error( $job, $error, $status ) {
    $retry_count = isset( $job['retry_count'] ) ? $job['retry_count'] : 0;
    
    // Retry with exponential backoff: 30s, 60s, 120s
    if ( 'timeout' !== $status && $retry_count < 3 ) {
        $backoff_delay = $job['poll_interval'] * pow( 2, $retry_count );
        $job['retry_count']++;
        wp_schedule_single_event( time() + $backoff_delay, self::CRON_HOOK, array( $task_id ) );
        return; // Retry scheduled
    }
    
    // Move to DLQ after max retries
    WP_MCP_AI_Dead_Letter_Queue::add(
        WP_MCP_AI_Dead_Letter_Queue::TYPE_CRON_JOB,
        $job['task_id'],
        $job_data,
        $error->get_error_message(),
        $retry_history
    );
}
```

## Retry Strategies

### Webhook Retries
- **Method:** Track via transients (1 hour expiry)
- **Max Retries:** 3
- **Backoff:** None (immediate retries on cron schedule)
- **Trigger:** wp_remote_post() failure

### Job Queue Retries
- **Method:** Track in queue state
- **Max Retries:** 3
- **Backoff:** None (immediate requeue)
- **Trigger:** Job execution returns WP_Error

### Crawl4AI Retries (NEW)
- **Method:** Track in job metadata
- **Max Retries:** 3
- **Backoff:** Exponential (30s → 60s → 120s)
- **Trigger:** Remote API failure (not timeout)
- **Special:** Timeouts skip retry and go straight to DLQ

## Capacity Management

### Automatic Pruning

When DLQ reaches 1000 items:
- Automatically removes oldest 100 items
- Maintains chronological order
- Logs pruning events

### Scheduled Cleanup

Weekly cron job (`wp_mcp_ai_dlq_cleanup`):
- Removes items older than 30 days (configurable)
- Runs via WordPress cron
- Can be triggered manually: `do_action('wp_mcp_ai_dlq_cleanup')`

## API Reference

### Adding Items

```php
$result = WP_MCP_AI_Dead_Letter_Queue::add(
    string $type,           // Item type constant
    string $identifier,     // Unique identifier
    array  $data,          // Full item data
    string $failure_reason, // Error message
    array  $retry_history   // Optional retry history
);
// Returns: bool|WP_Error
```

### Retrieving Items

```php
// Get all items
$items = WP_MCP_AI_Dead_Letter_Queue::get_all();

// Get by type
$webhooks = WP_MCP_AI_Dead_Letter_Queue::get_by_type( 'webhook' );

// Get with filters
$active = WP_MCP_AI_Dead_Letter_Queue::get_all(
    array(
        'type'      => 'cron_job',
        'dismissed' => false,
        'date_from' => '2026-01-01',
    )
);

// Get single item
$item = WP_MCP_AI_Dead_Letter_Queue::get( $item_id );
```

### Managing Items

```php
// Retry an item
$result = WP_MCP_AI_Dead_Letter_Queue::retry( $item_id );

// Dismiss an item
WP_MCP_AI_Dead_Letter_Queue::dismiss( $item_id );

// Remove an item
WP_MCP_AI_Dead_Letter_Queue::remove( $item_id );

// Purge old items
$count = WP_MCP_AI_Dead_Letter_Queue::purge_old( 30 ); // 30 days
```

### Statistics

```php
$stats = WP_MCP_AI_Dead_Letter_Queue::get_stats();
// Returns:
array(
    'total'       => 42,
    'by_type'     => array(
        'webhook'   => 20,
        'cron_job'  => 15,
        'async_tool' => 7,
    ),
    'dismissed'   => 10,
    'active'      => 32,
    'oldest_date' => '2026-01-01 00:00:00',
    'newest_date' => '2026-01-03 19:00:00',
)
```

## WordPress Hooks

### Actions

```php
// Fired when item added to DLQ
do_action( 'wp_mcp_ai_dlq_item_added', $item_id, $type, $identifier, $data, $failure_reason );

// Cleanup cron
add_action( 'wp_mcp_ai_dlq_cleanup', array( 'WP_MCP_AI_Dead_Letter_Queue', 'cleanup' ) );
```

### Filters

```php
// Customize retention period
add_filter( 'wp_mcp_ai_dlq_retention_days', function( $days ) {
    return 60; // 60 days instead of 30
});
```

## Performance Considerations

### Storage

- Uses WordPress options table
- Single option stores all items as serialized array
- Automatically pruned at 1000 items
- No database queries per item (in-memory operations)

### Memory

- Full DLQ loaded into memory when accessed
- 1000 items ≈ 500KB-1MB depending on data
- Acceptable for WordPress environment

### Cron Load

- Weekly cleanup runs in background
- Webhook retries use existing cron infrastructure
- Exponential backoff reduces retry storms

## Security

### Capability Checks

All DLQ operations should check `manage_options` capability in admin UI.

### Data Sanitization

- All inputs sanitized on add: `sanitize_text_field()`, `sanitize_key()`
- Outputs escaped in admin UI
- Retry operations validate callable existence

### Sensitive Data

DLQ may contain:
- Webhook URLs and payloads
- Job arguments and context
- Error messages with stack traces

**Recommendation:** Exclude DLQ data from public exports or backups.

## Testing

See `tests/test-dead-letter-queue.php` for comprehensive unit tests:
- Adding items
- Filtering by type and status
- Purging old items
- Statistics calculation
- Retry history tracking
- Max items limit enforcement

## Future Enhancements

### Phase 3: Admin UI
- Visual DLQ viewer in WordPress admin
- Bulk retry/dismiss/delete actions
- Search and filter interface
- Export to CSV for analysis

### Phase 4: Advanced Features
- Exponential backoff for all retry types
- Circuit breaker pattern integration
- Dead letter queue alerts/notifications
- Automatic retry scheduling

### Phase 5: WP-CLI
```bash
wp mcp-ai dlq list --type=webhook --status=active
wp mcp-ai dlq retry <item-id>
wp mcp-ai dlq purge --days=60
wp mcp-ai dlq stats
```

## Related Documentation

- [SLA Prioritization](./sla-prioritization.md) - SLA-based job prioritization
- [Orchestration Layer Architecture](./orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md)
- [Job Queue Manager](../reference/job-queue-manager.md)

## Troubleshooting

### DLQ filling up too quickly

**Symptom:** DLQ reaches 1000 items and prunes frequently.

**Solutions:**
1. Check for systematic failures (bad webhook URL, service down)
2. Increase retry count before DLQ
3. Fix underlying issues causing failures
4. Decrease retention period to purge faster

### Items not being retried

**Symptom:** retry() returns success but job doesn't execute.

**Solutions:**
1. Check if WordPress cron is running: `wp cron event list`
2. Verify callable still exists (closures can't be unserialized)
3. Check job queue capacity limits
4. Review logs for rescheduling errors

### Memory exhaustion

**Symptom:** Fatal error when accessing DLQ.

**Solutions:**
1. Purge old items: `WP_MCP_AI_Dead_Letter_Queue::purge_old(7)`
2. Clear items manually: `delete_option('wp_mcp_ai_dead_letter_queue')`
3. Increase PHP memory limit
4. Implement pagination in admin UI

## Changelog

### v1.1.0 (January 2026)
- Initial DLQ implementation
- Four item types supported
- Integration with Job Queue Manager
- Integration with Webhook Notifier
- Crawl4AI exponential backoff retry
- Automatic cleanup cron
- Comprehensive unit tests

---

**Maintainer:** NV Digital Solutions  
**License:** GPLv3
