# Proposal 017: Polling, Queue & Load Balancing Hardening — Comprehensive Implementation Plan

**Status:** Draft  
**Date:** 2026-08-03  
**Author:** AI Agent (Zed) — Architecture Review  
**Related:**
- [009-rabbitmq-integration-proposal.md](./009-rabbitmq-integration-proposal.md)
- [010-infrastructure-scaling-mitigation.md](./010-infrastructure-scaling-mitigation.md)
- [011-queue-worker-implementation-plan.md](./011-queue-worker-implementation-plan.md)
- [016-security-architecture-hardening-code-review-2026-08.md](./016-security-architecture-hardening-code-review-2026-08.md)

---

## 1. Executive Summary

A code review of NV oOS v1.1.43+ (base + pro) identified **twelve structural weaknesses** across three interconnected systems:

| System | Findings | Risk Level |
|--------|----------|------------|
| **Polling** | Fragile transient fallbacks, fixed-interval polling, orphaned option records, missing exponential backoff | Medium–High |
| **Jobs Queue** | `wp_options` storage for queue state, no persistent result storage, missing job ID returns from async execution, no payload signing | High |
| **Load Balancing** | No provider failover, hardcoded capacity thresholds, cache key collisions, no circuit breaker | Medium–High |

This proposal delivers **incremental, backward-compatible hardening** in four waves. Each wave can ship independently. No wave requires RabbitMQ (Proposal 009) as a prerequisite, though Waves 3–4 are designed to compose cleanly with it when available.

**Target releases:** v1.1.45 (Wave 1), v1.2.0 (Wave 2), v1.2.5 (Wave 3), v1.3.0 (Wave 4)

---

## 2. Issue Inventory & Mitigation Map

| # | Severity | Issue | Location | Mitigation | Wave |
|---|----------|-------|----------|------------|------|
| P1 | 🔴 High | Transient-based job fallback is unreliable | `lib/wordpress-adapter/src/Adapter/QueueClient.php` | Custom DB table for job tracking; transient only as L1 cache | 1 |
| P2 | 🔴 High | `cancel()` does not unschedule WP-Cron events | `lib/wordpress-adapter/src/Adapter/QueueClient.php` | Implement `wp_unschedule_event()` in fallback path | 1 |
| P3 | 🟡 Medium | Fixed-interval polling (no backoff/jitter) | `addons/cloudways-dashboard/includes/class-nvoos-cloudways-dashboard-provisioning-job.php` | Exponential backoff with capped jitter | 2 |
| P4 | 🟡 Medium | Orphaned option records after job completion | `addons/cloudways-dashboard/includes/class-nvoos-cloudways-dashboard-provisioning-job.php` | TTL-based cleanup + completion hook | 2 |
| Q1 | 🔴 High | Queue state stored in `wp_options` | `includes/class-wp-mcp-ai-job-queue-manager.php` | Custom DB table (`wp_mcp_ai_jobs`) | 1 |
| Q2 | 🔴 High | No persistent result storage for async jobs | `lib/wordpress-adapter/src/Adapter/QueueClient.php` | Job results table + `JobStatus` hydration | 2 |
| Q3 | 🟡 Medium | Async execution returns no job ID for polling | `includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php` | Return `job_id` in async response envelope | 2 |
| Q4 | 🟡 Medium | No capability check on `cancel()` | `lib/wordpress-adapter/src/Adapter/QueueClient.php` | Add `current_user_can()` gate | 1 |
| L1 | 🔴 High | No provider failover on AI provider errors | `lib/core/src/Application/Provider/ProviderRouter.php` | Health-score tracking + fallback chain | 3 |
| L2 | 🟡 Medium | Capacity thresholds are hardcoded constants | `includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php` | Filterable settings + adaptive thresholds | 2 |
| L3 | 🟡 Medium | Cache key collision on nested arguments | `includes/services/class-wp-mcp-ai-tool-load-balancer.php` | Recursive normalization + `ksort()` | 2 |
| L4 | 🟡 Medium | `clear_cache()` is a no-op for specific tools | `includes/services/class-wp-mcp-ai-tool-load-balancer.php` | Implement cache invalidation via registry | 3 |

---

## 3. Wave 1 — v1.1.45 (Foundation: Persistent Job Storage)

### 3.1 Task P1+Q1 — Custom DB Table for Job Tracking

**Files:**
- New: `includes/db/class-wp-mcp-ai-job-store.php`
- New: `includes/db/class-wp-mcp-ai-job-store-schema.php`
- Modified: `lib/wordpress-adapter/src/Adapter/QueueClient.php`
- Modified: `includes/bootstrap/activation.php`

**Schema:**

```sql
CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mcp_ai_jobs (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id          VARCHAR(64)  NOT NULL UNIQUE,
    handler         VARCHAR(255) NOT NULL,
    payload         LONGTEXT,
    status          VARCHAR(20)  NOT NULL DEFAULT 'queued',
    result          LONGTEXT,
    error           TEXT,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at      DATETIME     NULL,
    completed_at    DATETIME     NULL,
    attempts        TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts    TINYINT UNSIGNED NOT NULL DEFAULT 3,
    scheduled_at    DATETIME     NULL,
    claimed_by      VARCHAR(64)  NULL,
    claimed_at      DATETIME     NULL,
    user_id         BIGINT UNSIGNED NULL,
    INDEX idx_status_created (status, created_at),
    INDEX idx_handler_status (handler, status),
    INDEX idx_job_id (job_id),
    INDEX idx_claimed (claimed_by, claimed_at)
) ENGINE=InnoDB {$wpdb->get_charset_collate()};
```

**Design decisions:**
- `job_id` is a UUID (via `wp_generate_uuid4()`) — decoupled from Action Scheduler action IDs to survive transport changes.
- `claimed_by` + `claimed_at` enable cooperative locking without relying on Action Scheduler's internal state.
- `payload` is JSON — framework-agnostic, human-readable in SQL queries.
- `result` is JSON — nullable; populated only on completion.

**QueueClient adapter changes:**

```php
// In Nvoos\WordPress\Adapter\QueueClient::enqueue()
public function enqueue( string $handler, array $payload, array $options = array() ): string {
    $jobId = 'job_' . wp_generate_uuid4();

    // Persist to custom table first.
    WP_MCP_AI_Job_Store::insert( array(
        'job_id'  => $jobId,
        'handler' => $handler,
        'payload' => wp_json_encode( $payload ),
        'status'  => 'queued',
        'user_id' => get_current_user_id(),
    ) );

    // Then dispatch to Action Scheduler (or WP-Cron fallback).
    // ... existing dispatch logic ...

    return $jobId;
}
```

**Backward compatibility:**
- Old Action Scheduler action IDs continue to work — the adapter maps them on read.
- New jobs get the UUID; old jobs reference the AS action ID directly.
- `wp_options` rows are preserved for one release cycle (not deleted).

**Tests:** `tests/test-job-store.php`
- Insert → assert row exists with correct status.
- Claim → assert `claimed_by` populated, `claimed_at` set.
- Complete → assert `result` JSON round-trips correctly.
- Concurrent claim → only one claimant succeeds.

---

### 3.2 Task P2 — Fix `cancel()` WP-Cron Fallback

**File:** `lib/wordpress-adapter/src/Adapter/QueueClient.php`

**Current (broken):**
```php
public function cancel( string $jobId ): bool {
    if ( \function_exists( 'as_unschedule_action' ) ) {
        \as_unschedule_action( '', array(), '', $jobId );
        return true;
    }
    \delete_transient( 'wp_mcp_ai_job_' . $jobId );
    return true;
}
```

**Fixed:**
```php
public function cancel( string $jobId ): bool {
    // 1. Cancel in Action Scheduler.
    if ( \function_exists( 'as_unschedule_action' ) ) {
        \as_unschedule_action( '', array(), '', $jobId );
    }

    // 2. Cancel WP-Cron fallback event.
    $timestamp = \wp_next_scheduled( 'wp_mcp_ai_handle_async_job', array( '_job_id' => $jobId ) );
    if ( $timestamp ) {
        \wp_unschedule_event( $timestamp, 'wp_mcp_ai_handle_async_job', array( '_job_id' => $jobId ) );
    }

    // 3. Mark as cancelled in persistent store.
    WP_MCP_AI_Job_Store::update_status( $jobId, 'cancelled' );

    // 4. Clear transient.
    \delete_transient( 'wp_mcp_ai_job_' . $jobId );

    return true;
}
```

**Tests:** `tests/test-queue-client-cancel.php`
- Schedule a WP-Cron job → cancel → assert `wp_next_scheduled()` returns false.
- Schedule an AS job → cancel → assert `as_has_scheduled_action()` returns false.
- Cancel non-existent job → returns true (idempotent).

---

### 3.3 Task Q4 — Capability Gate on `cancel()`

**File:** `lib/wordpress-adapter/src/Adapter/QueueClient.php`

**Change:** Add a capability check before allowing cancellation. Jobs enqueued by other users (or the system) should not be cancellable by low-privileged users.

```php
public function cancel( string $jobId ): bool {
    $job = WP_MCP_AI_Job_Store::get( $jobId );

    if ( ! $job ) {
        return false;
    }

    // Capability gate: users can cancel their own jobs; admins can cancel any.
    $userId = get_current_user_id();
    if ( $job['user_id'] && (int) $job['user_id'] !== $userId && ! current_user_can( 'manage_options' ) ) {
        return false;
    }

    // ... rest of cancel logic ...
}
```

**Tests:** `tests/test-queue-client-cancel-capabilities.php`
- Subscriber cancels own job → succeeds.
- Subscriber cancels admin's job → fails.
- Admin cancels any job → succeeds.
- System job (user_id = 0) cancelled by subscriber → fails.

---

## 4. Wave 2 — v1.2.0 (Resilience: Backoff, Results, Thresholds)

### 4.1 Task P3 — Exponential Backoff with Jitter for Polling Jobs

**File:** `addons/cloudways-dashboard/includes/class-nvoos-cloudways-dashboard-provisioning-job.php`

**Current:** Fixed 30s interval.

**Proposed:**
```php
const POLL_INTERVAL_BASE = 30;
const POLL_INTERVAL_MAX  = 300; // 5 minutes
const BACKOFF_MULTIPLIER = 2;
const JITTER_MAX_SECONDS = 5;

private static function calculate_poll_delay( $attempt ) {
    $exponential = self::POLL_INTERVAL_BASE * pow( self::BACKOFF_MULTIPLIER, $attempt - 1 );
    $capped      = min( $exponential, self::POLL_INTERVAL_MAX );
    $jitter      = wp_rand( 0, self::JITTER_MAX_SECONDS );

    return $capped + $jitter;
}
```

**Rationale:**
- Prevents thundering herd when many apps provision simultaneously.
- Reduces API call volume during long-running provisioning (e.g., 30 min = ~6 calls instead of 60).
- Jitter prevents synchronized retry storms across multiple app instances.

**Tests:** `tests/test-cloudways-provisioning-backoff.php`
- Attempt 1 → delay between 30–35s.
- Attempt 5 → delay capped at 300s + jitter.
- Assert delay never exceeds `POLL_INTERVAL_MAX + JITTER_MAX_SECONDS`.

---

### 4.2 Task P4 — Orphaned Option Cleanup

**File:** `addons/cloudways-dashboard/includes/class-nvoos-cloudways-dashboard-provisioning-job.php`

**Change:** Add a cleanup hook on job completion/timeout.

```php
private static function set_status( $app_id, $status, $error = null, $attempt = null, $results = null ) {
    // ... existing logic ...

    // Schedule cleanup for completed/terminal states.
    if ( in_array( $status, array( 'ready', 'failed', 'timeout' ), true ) ) {
        wp_schedule_single_event(
            time() + DAY_IN_SECONDS,
            'nvoos_cloudways_dashboard_cleanup_provisioning_status',
            array( 'app_id' => $app_id )
        );
    }
}

public static function cleanup_status( $app_id ) {
    delete_option( self::status_key( $app_id ) );
    delete_option( "nvoos_cw_app_plugin_intent_{$app_id}" );
    delete_option( "nvoos_cw_toolkit_intents_{$app_id}" );
}
```

**Also:** Register cleanup on plugin deactivation.

**Tests:** `tests/test-cloudways-provisioning-cleanup.php`
- Complete provisioning → assert option exists → simulate cron tick → assert option deleted.
- Timeout → assert cleanup runs.

---

### 4.3 Task Q2 — Persistent Result Storage

**Files:**
- Modified: `includes/db/class-wp-mcp-ai-job-store.php` (add `complete()`)
- Modified: `lib/wordpress-adapter/src/Adapter/QueueClient.php` (hydrate `JobStatus` with result)

**Change:** When a job completes (via Action Scheduler callback or WP-Cron), write the result to the custom table.

```php
// In the job completion handler:
WP_MCP_AI_Job_Store::complete( $jobId, $result, $error );
```

**QueueClient::getStatus() enhancement:**
```php
public function getStatus( string $jobId ): JobStatus {
    // 1. Check persistent store first.
    $row = WP_MCP_AI_Job_Store::get( $jobId );
    if ( $row ) {
        return new JobStatus(
            jobId: $jobId,
            status: $row['status'],
            result: $row['result'] ? json_decode( $row['result'], true ) : null,
            error: $row['error'],
            queuedAt: $row['created_at'] ? new \DateTimeImmutable( $row['created_at'] ) : null,
            startedAt: $row['started_at'] ? new \DateTimeImmutable( $row['started_at'] ) : null,
            completedAt: $row['completed_at'] ? new \DateTimeImmutable( $row['completed_at'] ) : null,
            attempts: (int) $row['attempts'],
        );
    }

    // 2. Fall back to Action Scheduler / transient.
    // ... existing logic ...
}
```

**Tests:** `tests/test-job-store-results.php`
- Complete job with result → assert result retrievable via `getStatus()`.
- Complete job with error → assert error populated.
- Job not found → returns `cancelled` status (existing behavior).

---

### 4.4 Task Q3 — Return Job ID from Async Execution

**File:** `includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php`

**Current:** `execute_async()` returns a generic "queued" response without the job ID.

**Fixed:**
```php
protected function execute_async( $tool_slug, array $arguments, array $context ) {
    $queue = $this->get_async_executor(); // Returns QueueClientInterface

    $handler = 'WP_MCP_AI_Tool_Async_Handler';
    $payload = array(
        'tool_slug'  => $tool_slug,
        'arguments'  => $arguments,
        'context'    => $context,
        'session_id' => $context['session_id'] ?? '',
    );

    $jobId = $queue->enqueue( $handler, $payload, array(
        'group'    => 'wp_mcp_ai_tools',
        'priority' => $context['priority'] ?? 10,
    ) );

    return array(
        'success' => true,
        'status'  => 'queued',
        'job_id'  => $jobId, // <-- NEW
        'message' => __( 'Tool execution queued. Poll for results using job_id.', 'mcp-ai-wpoos' ),
    );
}
```

**REST response change:**
The `/chat` endpoint already returns tool results inline. For async tools, the response now includes:
```json
{
  "tool_calls": [
    {
      "tool": "deep_research",
      "status": "queued",
      "job_id": "job_550e8400-e29b-41d4-a716-446655440000"
    }
  ]
}
```

**Client-side (JS):** The chat client can poll `GET /mcp-ai/v1/jobs/{job_id}` for completion.

**Tests:** `tests/test-tool-async-job-id.php`
- Execute async tool → assert response contains `job_id`.
- Poll job status → assert transitions `queued` → `running` → `completed`.

---

### 4.5 Task L2 — Filterable Capacity Thresholds

**File:** `includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php`

**Current:** Hardcoded constants.

**Proposed:**
```php
// Replace constants with filterable values.
protected function get_capacity_thresholds() {
    $defaults = array(
        'critical'      => 15,   // capacity_score below this → queue everything.
        'high_util'     => 30,   // capacity_score below this → queue slow tools.
        'utilization'   => 0.85, // utilization above this → consider async.
        'slow_tool_sec' => 5.0,  // avg_duration above this → "slow".
    );

    return apply_filters( 'wp_mcp_ai_capacity_thresholds', $defaults );
}
```

**Usage in `check_capacity_routing()`:**
```php
$thresholds = $this->get_capacity_thresholds();
if ( isset( $metrics['capacity_score'] ) && $metrics['capacity_score'] < $thresholds['critical'] ) {
    // ... queue it ...
}
```

**Settings UI (optional):** Add to **Settings → NV oOS → Performance**:
- "Critical capacity threshold" (default 15)
- "High utilization threshold" (default 30)
- "Slow tool threshold (seconds)" (default 5)

**Tests:** `tests/test-capacity-thresholds-filter.php`
- Filter thresholds → assert routing uses filtered values.
- Filter to extreme values → assert expected routing behavior.

---

### 4.6 Task L3 — Recursive Cache Key Normalization

**File:** `includes/services/class-wp-mcp-ai-tool-load-balancer.php`

**Current:**
```php
protected function generate_cache_key( $tool_slug, $arguments ) {
    ksort( $arguments );
    $args_hash = md5( wp_json_encode( $arguments ) );
    return self::CACHE_KEY_PREFIX . $tool_slug . '_' . $args_hash;
}
```

**Problem:** `ksort()` only sorts top-level keys. Nested arrays with different key orders produce different hashes.

**Fixed:**
```php
protected function generate_cache_key( $tool_slug, $arguments ) {
    $normalized = $this->deep_ksort( $arguments );
    $args_hash  = md5( wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
    return self::CACHE_KEY_PREFIX . $tool_slug . '_' . $args_hash;
}

private function deep_ksort( array $array ): array {
    ksort( $array );
    foreach ( $array as $key => $value ) {
        if ( is_array( $value ) ) {
            $array[ $key ] = $this->deep_ksort( $value );
        }
    }
    return $array;
}
```

**Tests:** `tests/test-cache-key-normalization.php`
- Arguments with nested keys in different order → assert same hash.
- Floating-point values → assert consistent hashing.

---

## 5. Wave 3 — v1.2.5 (Intelligence: Provider Failover & Cache Invalidation)

### 5.1 Task L1 — Provider Health Tracking & Failover

**Files:**
- New: `lib/core/src/Application/Provider/ProviderHealthTracker.php`
- Modified: `lib/core/src/Application/Provider/ProviderRouter.php`
- Modified: `includes/class-wp-mcp-ai-settings.php` (add failover settings)

**Design:**

```php
// ProviderHealthTracker.php
class ProviderHealthTracker {
    private array $scores = array();

    public function record_success( string $providerSlug, float $latencyMs ): void {
        $this->update_score( $providerSlug, +1, $latencyMs );
    }

    public function record_failure( string $providerSlug, string $errorType ): void {
        $this->update_score( $providerSlug, -5, null ); // Failures penalize more than successes reward.
    }

    public function is_healthy( string $providerSlug ): bool {
        return $this->get_score( $providerSlug ) > -10; // Configurable threshold.
    }

    public function get_fallback_chain( string $preferredSlug ): array {
        $all = $this->get_all_providers();
        usort( $all, fn( $a, $b ) => $b['score'] <=> $a['score'] );

        // Return providers that are healthy, excluding the preferred one.
        return array_filter( $all, fn( $p ) => $p['slug'] !== $preferredSlug && $p['score'] > -10 );
    }
}
```

**ProviderRouter enhancement:**
```php
public function chat( array $messages, array $options = array(), array $assistantConfig = array() ): mixed {
    $provider = $this->resolveForChat( $options, $assistantConfig );
    $result   = $provider->chat( $messages, $options );

    // If provider error and failover is enabled, try fallback.
    if ( $this->is_error( $result ) && $this->failover_enabled() ) {
        $fallbacks = $this->healthTracker->get_fallback_chain( $provider->getProviderSlug() );
        foreach ( $fallbacks as $fallback ) {
            $fallbackProvider = $this->get( $fallback['slug'] );
            if ( $fallbackProvider ) {
                $result = $fallbackProvider->chat( $messages, $options );
                if ( ! $this->is_error( $result ) ) {
                    $this->healthTracker->record_success( $fallback['slug'], 0 );
                    return $result;
                }
            }
        }
    }

    return $result;
}
```

**Storage:** Health scores stored in transients with 5-minute TTL. On sites with Redis, this is near-real-time. On sites without object cache, scores are recomputed per request (acceptable since the calculation is lightweight).

**Settings:**
- "Enable provider failover" (checkbox, default off)
- "Failover providers" (multi-select, ordered)
- "Health score threshold" (integer, default -10)

**Tests:** `tests/test-provider-failover.php`
- Primary provider fails → assert fallback provider used.
- All providers fail → assert error returned.
- Health score recovers → assert primary used again after TTL.

---

### 5.2 Task L4 — Implement `clear_cache()` for Specific Tools

**File:** `includes/services/class-wp-mcp-ai-tool-load-balancer.php`

**Current:**
```php
public function clear_cache( ?string $tool_slug = null ) {
    if ( null === $tool_slug ) {
        return wp_cache_flush();
    }
    return true; // No-op for specific tool.
}
```

**Fixed:** Maintain a cache key registry.

```php
protected function cache_result( $tool_slug, $arguments, $result ) {
    $cache_key = $this->generate_cache_key( $tool_slug, $arguments );
    $ttl       = apply_filters( 'wp_mcp_ai_tool_cache_ttl', self::DEFAULT_CACHE_TTL, $tool_slug );

    wp_cache_set( $cache_key, $result, self::CACHE_GROUP, $ttl );

    // Register key for later invalidation.
    $registry_key = self::CACHE_KEY_PREFIX . 'registry_' . $tool_slug;
    $registry     = wp_cache_get( $registry_key, self::CACHE_GROUP ) ?: array();
    $registry[]   = $cache_key;
    wp_cache_set( $registry_key, $registry, self::CACHE_GROUP, $ttl * 2 );

    return true;
}

public function clear_cache( ?string $tool_slug = null ) {
    if ( null === $tool_slug ) {
        return wp_cache_flush();
    }

    $registry_key = self::CACHE_KEY_PREFIX . 'registry_' . $tool_slug;
    $registry     = wp_cache_get( $registry_key, self::CACHE_GROUP );

    if ( is_array( $registry ) ) {
        foreach ( $registry as $cache_key ) {
            wp_cache_delete( $cache_key, self::CACHE_GROUP );
        }
        wp_cache_delete( $registry_key, self::CACHE_GROUP );
        return true;
    }

    return false;
}
```

**Tests:** `tests/test-tool-cache-invalidation.php`
- Cache result → clear by tool slug → assert cache miss.
- Cache results for two tools → clear one → assert other still cached.

---

## 6. Wave 4 — v1.3.0 (Integration: Compose with RabbitMQ & SSE)

### 6.1 Task SSE-1 — Short-Lived SSE Connections with Reconnect

**File:** `includes/rest/class-wp-mcp-ai-rest-chat-session-stream-controller.php`

**Current:** `MAX_TICKS = 900` (30 min) with 2s sleep intervals.

**Proposed:** Add configurable max connection duration.

```php
const POLL_INTERVAL = 2;
const PING_EVERY_N_TICKS = 8;
const MAX_TICKS = 900; // Keep for backward compatibility.

// NEW: Configurable max duration before forced reconnect.
protected function get_max_duration_seconds(): int {
    return apply_filters( 'wp_mcp_ai_sse_max_duration', 120 ); // 2 minutes default.
}
```

**In `handle_stream()`:**
```php
$max_duration = $this->get_max_duration_seconds();
$start_time   = time();

while ( $tick_count < self::MAX_TICKS ) {
    if ( function_exists( 'connection_aborted' ) && connection_aborted() ) {
        break;
    }

    // Force reconnect after max duration.
    if ( ( time() - $start_time ) >= $max_duration ) {
        $this->sse_handler->send_sse_event( 'chat:reconnect', array(
            'retry'  => 3000,
            'reason' => 'max_duration_reached',
        ) );
        break;
    }

    sleep( self::POLL_INTERVAL );
    // ... rest of loop ...
}
```

**Client-side (assets/js/chat.js):**
```javascript
// Reconnect with Last-Event-ID on forced close.
eventSource.addEventListener('chat:reconnect', (e) => {
    const data = JSON.parse(e.data);
    eventSource.close();
    setTimeout(() => {
        connectSSE(lastEventId);
    }, data.retry || 3000);
});
```

**Tests:** `tests/test-sse-reconnect.php`
- Open stream → wait for forced reconnect → assert client reconnects with correct Last-Event-ID.
- Assert no message duplication on reconnect.

---

### 6.2 Task RMQ-1 — RabbitMQ-Aware QueueClient

**File:** `lib/wordpress-adapter/src/Adapter/QueueClient.php`

**When RabbitMQ is available (Proposal 009):**
```php
public function enqueue( string $handler, array $payload, array $options = array() ): string {
    // 1. Always persist to custom table (Wave 1).
    $jobId = $this->persist_job( $handler, $payload, $options );

    // 2. If RabbitMQ is available, dispatch to broker.
    if ( $this->rabbitmq_available() ) {
        $this->rabbitmq_client->publish( $handler, $payload, array(
            'job_id'   => $jobId,
            'priority' => $options['priority'] ?? 0,
        ) );
        return $jobId;
    }

    // 3. Fall back to Action Scheduler / WP-Cron.
    // ... existing fallback ...

    return $jobId;
}
```

**Benefit:** The custom DB table becomes the **source of truth** for job status, while RabbitMQ handles transport. This decouples status tracking from the transport layer.

---

## 7. Security Hardening (Cross-Cutting)

### 7.1 Job Payload Signing (Optional, Wave 3)

For environments where job payloads may contain sensitive data or where the database is shared:

```php
private function sign_payload( array $payload ): string {
    $secret = wp_salt( 'auth' );
    return hash_hmac( 'sha256', wp_json_encode( $payload ), $secret );
}

private function verify_payload( array $payload, string $signature ): bool {
    return hash_equals( $this->sign_payload( $payload ), $signature );
}
```

Store `payload_signature` in the jobs table. Verify before execution.

**Trade-off:** Adds overhead. Enable via `WP_MCP_AI_SIGN_JOB_PAYLOADS` constant.

### 7.2 Rate Limiting on SSE Endpoint

**File:** `includes/rest/class-wp-mcp-ai-rest-chat-session-stream-controller.php`

```php
public function permissions_check( WP_REST_Request $request ) {
    $ip = $request->get_header( 'X-Forwarded-For' ) ?: $_SERVER['REMOTE_ADDR'];

    // Rate limit: max 3 concurrent streams per IP.
    $key   = 'wp_mcp_ai_sse_streams_' . md5( $ip );
    $count = get_transient( $key ) ?: 0;

    if ( $count >= 3 ) {
        return new WP_Error(
            'rate_limit_exceeded',
            __( 'Too many concurrent SSE connections.', 'mcp-ai-wpoos' ),
            array( 'status' => 429 )
        );
    }

    set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

    return $this->permissions_check_authenticated( $request );
}
```

---

## 8. File Map

### New Files

| File | Description |
|------|-------------|
| `includes/db/class-wp-mcp-ai-job-store.php` | CRUD operations for custom jobs table |
| `includes/db/class-wp-mcp-ai-job-store-schema.php` | Schema definition + `dbDelta()` |
| `lib/core/src/Application/Provider/ProviderHealthTracker.php` | Health scoring + failover chain |
| `tests/test-job-store.php` | Job store unit tests |
| `tests/test-queue-client-cancel.php` | Cancel functionality tests |
| `tests/test-queue-client-cancel-capabilities.php` | Capability gate tests |
| `tests/test-cloudways-provisioning-backoff.php` | Exponential backoff tests |
| `tests/test-cloudways-provisioning-cleanup.php` | Orphan cleanup tests |
| `tests/test-job-store-results.php` | Result persistence tests |
| `tests/test-tool-async-job-id.php` | Async job ID tests |
| `tests/test-capacity-thresholds-filter.php` | Threshold filter tests |
| `tests/test-cache-key-normalization.php` | Cache key tests |
| `tests/test-provider-failover.php` | Provider failover tests |
| `tests/test-tool-cache-invalidation.php` | Cache invalidation tests |
| `tests/test-sse-reconnect.php` | SSE reconnect tests |

### Modified Files

| File | Change |
|------|--------|
| `lib/wordpress-adapter/src/Adapter/QueueClient.php` | Custom table integration, cancel fix, capability gate, result hydration |
| `includes/services/class-wp-mcp-ai-tool-execution-orchestrator.php` | Return job_id, filterable thresholds |
| `includes/services/class-wp-mcp-ai-tool-load-balancer.php` | Recursive cache keys, cache invalidation |
| `includes/services/class-wp-mcp-ai-tool-load-monitor.php` | Time-decay weighting (optional Wave 3) |
| `lib/core/src/Application/Provider/ProviderRouter.php` | Failover integration |
| `addons/cloudways-dashboard/includes/class-nvoos-cloudways-dashboard-provisioning-job.php` | Exponential backoff, cleanup |
| `includes/rest/class-wp-mcp-ai-rest-chat-session-stream-controller.php` | Max duration, rate limiting |
| `includes/bootstrap/activation.php` | New table creation on activation |
| `includes/class-wp-mcp-ai-settings.php` | New settings fields |

---

## 9. Backward Compatibility

| Change | BC Impact | Mitigation |
|--------|-----------|------------|
| Custom jobs table | None — new code reads table, falls back to AS/transient | Table empty on upgrade → fallback works |
| `cancel()` WP-Cron fix | None — fixes broken behavior | No migration needed |
| Capacity thresholds filter | None — defaults match old constants | `apply_filters` additive |
| Recursive cache key normalization | Low — cache keys change, causing one-time cache misses | Old cache entries expire naturally |
| Provider failover | None — disabled by default | Settings checkbox, default off |
| SSE max duration | Low — clients may see forced reconnect | Client already supports reconnect |
| Async job ID in response | None — additive field | Old clients ignore `job_id` |

---

## 10. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Custom table creation fails on shared hosting | Low | High | Graceful fallback to existing AS/transient; table creation logged |
| Provider failover causes unexpected costs | Medium | Medium | Disabled by default; monitor latency on fallback |
| SSE forced reconnect disrupts user experience | Low | Medium | Retry interval configurable; client already handles reconnect |
| Cache key normalization causes cache stampede | Low | Low | One-time miss only; TTL is short |
| Job store table grows unbounded | Medium | Medium | Add daily purge cron for completed jobs; archive old jobs |

---

## 11. Acceptance Criteria

1. **Job store table exists:** `SHOW TABLES` includes `wp_mcp_ai_jobs` after activation.
2. **Job lifecycle:** Enqueue → claim → complete → result retrievable via `getStatus()`.
3. **Cancel works for WP-Cron:** `wp_next_scheduled()` returns false after cancel.
4. **Capability gate:** Subscriber cannot cancel admin's job.
5. **Exponential backoff:** Attempt 5 delay ≥ 480s for Cloudways provisioning.
6. **Orphan cleanup:** Completed provisioning options deleted within 24 hours.
7. **Async job ID:** `execute_async()` returns `job_id` in response.
8. **Filterable thresholds:** `apply_filters('wp_mcp_ai_capacity_thresholds')` affects routing.
9. **Recursive cache keys:** Nested arguments in different order produce identical hashes.
10. **Provider failover:** Primary fails → fallback provider serves request (when enabled).
11. **Cache invalidation:** `clear_cache('tool_slug')` removes all cached results for that tool.
12. **SSE reconnect:** Connection forced after 120s; client reconnects with `Last-Event-ID`.
13. **Rate limiting:** 4th concurrent SSE connection from same IP returns 429.
14. **Lint clean:** `composer run lint:errors-only` passes on all modified files.

---

## 12. Estimated Effort

| Wave | Tasks | Effort |
|------|-------|--------|
| Wave 1 (v1.1.45) | P1, Q1, P2, Q4 | 4–5 days |
| Wave 2 (v1.2.0) | P3, P4, Q2, Q3, L2, L3 | 5–7 days |
| Wave 3 (v1.2.5) | L1, L4 | 4–5 days |
| Wave 4 (v1.3.0) | SSE-1, RMQ-1 | 3–4 days |
| **Testing (all waves)** | | 3–4 days |
| **Total** | | **19–25 working days (4–5 weeks)** |

---

## 13. References

- [Cloudways Server Settings](https://support.cloudways.com/en/articles/5120689-how-to-manage-your-server-settings)
- [Action Scheduler Custom Tables](https://actionscheduler.org/performance/)
- [WordPress Object Cache API](https://developer.wordpress.org/reference/classes/wp_object_cache/)
- [MySQL 8.0 — `SELECT ... FOR UPDATE SKIP LOCKED`](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html)
- [SSE Specification (W3C/WHATWG)](https://html.spec.whatwg.org/multipage/server-sent-events.html)
- [Exponential Backoff and Jitter (AWS Architecture Blog)](https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/)
- [Circuit Breaker Pattern (Microsoft Azure Architecture)](https://docs.microsoft.com/en-us/azure/architecture/patterns/circuit-breaker)
- [deliciousbrains/wp-queue — Job queues for WordPress](https://github.com/deliciousbrains/wp-queue)
- [Mercure protocol — scalable SSE alternative](https://mercure.rocks/)
- Related Proposals: [009](./009-rabbitmq-integration-proposal.md), [010](./010-infrastructure-scaling-mitigation.md), [011](./011-queue-worker-implementation-plan.md), [016](./016-security-architecture-hardening-code-review-2026-08.md)

---

*Next step: Scrum Master (nv-oos-scrum-master) breaks this plan into atomic stories and assigns to the next sprint.*
