# Proposal 023: Database Connection Pooling Stance & Service Connection Audit

**Date:** 2026-08-12
**Status:** Draft
**Author:** AI Agent (Zed / DeepSeek V4 Pro)
**Based on:** Full architecture review of all service-to-database connections across Base + Pro plugin performed 2026-08-12
**Related docs:** `009-rabbitmq-integration-proposal.md` · `010-infrastructure-scaling-mitigation.md` · `011-queue-worker-implementation-plan.md` · `022-request-queuing-job-pooling-hardening.md`
**Implementation plan:** `023-database-connection-pooling-stance-implementation-plan.md` (companion)

---

## 1. Executive Summary

A complete audit of every service connecting to MySQL/MariaDB, external databases, and message brokers was performed against `includes/`, `addons/`, `lib/`, and `bin/` on 2026-08-12. The audit traced connection lifecycles across **14+ custom database tables**, **3 competing queue transports**, **2 remote database drivers**, and the RabbitMQ message broker.

**Overall verdict:** The plugin has a solid adapter pattern (`QueueClientInterface` → `QueueClient` adapter) and the RabbitMQ integration is well-designed. However, the system operates with **no connection pooling anywhere**, **three parallel queue transports fire simultaneously** (causing write amplification), and the concurrency guard uses a non-atomic transient pattern that can race under load. On Cloudways servers — where RabbitMQ is pre-installed — the DB-polling queue worker is wasteful and the Action Scheduler fallback enqueue creates unnecessary table bloat.

This proposal identifies **6 issues** (1 critical, 3 high, 2 medium) and recommends a phased remediation approach.

| Release | Theme | Issues |
|---|---|---|
| **v1.2.1** (patch) | Stop dual-enqueue, fix concurrency guard race | C1, H2 |
| **v1.3.0** (minor) | PDO persistence, transport preference, health monitoring | H3, H4, M5, M6 |

---

## 2. Review Scope

| Area | Files / classes examined |
|---|---|
| Queue transports | `lib/wordpress-adapter/src/Adapter/QueueClient.php`, `includes/class-wp-mcp-ai-async-job-queue.php`, `includes/class-wp-mcp-ai-job-queue-manager.php`, `includes/class-wp-mcp-ai-queue-manager.php` |
| RabbitMQ client | `includes/class-wp-mcp-ai-rabbitmq-client.php`, `bin/queue-worker.php`, `includes/admin/sections/class-wp-mcp-ai-section-rabbitmq.php` |
| Job store | `includes/db/class-wp-mcp-ai-job-store.php`, `includes/admin/class-wp-mcp-ai-admin-cron-manager.php` |
| Concurrency guard | `includes/security/class-wp-mcp-ai-concurrency-guard.php`, `includes/security/class-wp-mcp-ai-concurrency-guard-subscriber.php` |
| Custom tables | `includes/bootstrap/activation.php` (table creation), `includes/bootstrap/loader.php` |
| Remote DB drivers | `addons/graphify/includes/remote/drivers/class-nvoos-graphify-remote-generic-sql.php` (PDO) |
| Performance section | `addons/pro/includes/admin/sections/class-wp-mcp-ai-section-performance.php` |
| External API | `wp_remote_get`/`wp_remote_post` calls across all tools (OpenAI, Gemini, Anthropic, EZuite, Cloudways API, etc.) |
| Infrastructure | `docker-compose.yml`, `includes/admin/class-wp-mcp-ai-admin-settings-base.php` (RabbitMQ settings) |
| Activation/cleanup | `includes/bootstrap/activation.php`, various `uninstall.php` files |

---

## 3. Connection Inventory — What Connects to MySQL

### 3.1 WordPress `$wpdb` — Primary Database Connection

| Property | Value |
|---|---|
| **Library** | `mysqli` (via WordPress's `wpdb` class) |
| **Connection type** | Per-request, non-persistent (WordPress default) |
| **Lifecycle** | Created on first query, released at PHP `shutdown` |
| **Custom tables** | 14+ tables prefixed `wp_mcp_ai_*` (job_queue, concurrent_jobs, jobs, audit_trail, threads, thread_messages, thread_checkpoints, hourly_token_usage, metric_events, slash_command_audit, qms_audit, compliance_checks, risks, controls, evidence, eca_attendance, eca_enrollments, events) |
| **Risk** | Low — Standard WordPress behaviour. However, many direct queries bypass the WordPress Object Cache (`DirectDatabaseQuery` annotations are prevalent), meaning every read hits MySQL. |

### 3.2 Action Scheduler (WooCommerce / Standalone)

| Property | Value |
|---|---|
| **Library** | `ActionScheduler` v3.9.x (bundled with WooCommerce or standalone) |
| **Tables** | `wp_actionscheduler_actions`, `wp_actionscheduler_groups`, `wp_actionscheduler_logs`, `wp_actionscheduler_claims` |
| **Connection** | Uses `$wpdb` internally; background runner wakes on HTTP request or WP-Cron |
| **Risk** | Medium — Designed for high-volume async processing, but tables grow unbounded without cleanup. The plugin enqueues jobs here even when RabbitMQ is active (see §5.1). |

### 3.3 DB Queue Worker (`bin/queue-worker.php`)

| Property | Value |
|---|---|
| **Table** | `wp_mcp_ai_concurrent_jobs` |
| **Polling interval** | Every 60 seconds (cron) or 1 second (daemon mode) |
| **Batch size** | 3 jobs per tick (hardcoded) |
| **Lock mechanism** | `flock()` on `/tmp/nvoos-queue-worker.lock` |
| **Risk** | **High** — Continuous polling wastes connections even when the queue is empty. On Cloudways servers with RabbitMQ, this is strictly inferior. |

### 3.4 RabbitMQ (`WP_MCP_AI_RabbitMQ_Client`)

| Property | Value |
|---|---|
| **Protocol** | AMQP 0-9-1 via PHP `amqp` extension |
| **Connection type** | Singleton, connects on demand, reuses within process |
| **Exchanges** | 4 (tools/direct, chat/topic, deadletter/fanout, analytics/fanout) |
| **Queues** | 7 (tool.execution, tool.execution.priority.high, tool.execution.async, tool.results, agentic.workflow, deadletter.queue, + implicit) |
| **QoS** | Prefetch count = 1 |
| **Risk** | Low — Well-designed. QoS=1 prevents worker overwhelm. Dead-letter exchange handles poison messages. Only risk is the `disconnect()` call at end of batch (see §5.5). |

### 3.5 Remote PDO Connections (Graphify)

| Property | Value |
|---|---|
| **Driver** | `class-nvoos-graphify-remote-generic-sql.php` |
| **Library** | PHP PDO (MySQL/MariaDB/PostgreSQL/SQLite) |
| **Connection type** | **New `new PDO()` per query** — no persistence, no cache |
| **Options** | `ATTR_EMULATE_PREPARES=false`, `ATTR_TIMEOUT=5s` |
| **Risk** | **High** — If configured to poll a remote database on every graph rebuild (which can be scheduled), each query opens a fresh TCP connection to the remote DB. No `PDO::ATTR_PERSISTENT`. |

### 3.6 External API Connections (HTTP)

| Property | Value |
|---|---|
| **Library** | WordPress HTTP API (`wp_remote_get`, `wp_remote_post`) |
| **Backend** | cURL (usually) or PHP streams |
| **Connection reuse** | cURL handles keep-alive per PHP process (automatic) |
| **Providers** | OpenAI, Google Gemini, Anthropic, Cloudflare, HuggingFace, Ollama, EZuite ERP, Cloudways API, Yahoo FF, ESPN, Telegram, Discord, Facebook, LinkedIn, Twitter/X, Instagram, Meta, TikTok, Google Drive, Gmail, + more |
| **Risk** | Low — cURL manages connection reuse internally. WordPress HTTP API timeouts prevent indefinite blocking. |

---

## 4. Custom Table Inventory

All tables use InnoDB, created via `dbDelta()` on plugin activation:

| Table | Purpose | Writes | Reads |
|---|---|---|---|
| `mcp_ai_job_queue` | Async job queue (slash commands, workflows, tools, agentic loops) | High (every job enqueue) | High (cron poll every 60s) |
| `mcp_ai_concurrent_jobs` | Concurrent API request throttling (replaces `wp_options` from v1.1.37) | High (every tool execution) | High (queue worker poll) |
| `mcp_ai_jobs` | Job store — canonical source of truth for all queued jobs | High (every enqueue) | Medium (status checks) |
| `mcp_ai_threads` | Chat thread metadata | Medium | Medium |
| `mcp_ai_thread_messages` | Individual chat messages in threads | High (every message) | High (every thread view) |
| `mcp_ai_thread_checkpoints` | Thread state snapshots | Low | Medium |
| `mcp_ai_hourly_token_usage` | Token usage per hour for cost tracking | Medium (hourly) | Low |
| `mcp_ai_metric_events` | Metric event storage | Medium | Low |
| `mcp_ai_slash_command_audit` | Slash command execution audit trail | Medium | Low |
| `mcp_ai_audit_trail` | Security audit events | Medium | Low |
| `mcp_ai_qms_audit` | Quality Management System audit | Low | Low |
| `mcp_ai_compliance_checks` | Compliance check results | Low | Low |
| `mcp_ai_risks` | Risk register entries | Low | Low |
| `mcp_ai_controls` | Control definitions | Low | Low |
| `mcp_ai_evidence` | Compliance evidence records | Low | Low |
| `mcp_ai_eca_attendance` | ECA attendance records | Low | Low |
| `mcp_ai_eca_enrollments` | ECA enrollment records | Low | Low |
| `mcp_ai_events` | Event records | Low | Low |

---

## 5. Issues & Findings

### 5.1 🔴 C1 — Dual-Enqueue Write Amplification (Critical)

**Location:** `lib/wordpress-adapter/src/Adapter/QueueClient.php:42-70`

When RabbitMQ is available, the `enqueue()` method publishes to RabbitMQ **AND** simultaneously enqueues to Action Scheduler as a "fallback":

```php
// Line 42-70 (simplified)
if ( $this->isRabbitMqAvailable() ) {
    WP_MCP_AI_RabbitMQ_Client::get_instance()->publish('tools', 'execute.normal', [...]);
    
    // ALSO enqueue to Action Scheduler as fallback
    if ( function_exists( 'as_enqueue_async_action' ) ) {
        as_enqueue_async_action($handler, $payload, $groupId, $unique, $priority);
    }
    return $jobId;
}
```

**Impact:** Every RabbitMQ-processed job also creates an Action Scheduler action record. This doubles write load on the `wp_actionscheduler_*` tables. The deduplication authority (`WP_MCP_AI_Job_Store`) prevents double-execution, but the AS table bloat is wasteful:
- AS tables grow with records that will never be claimed (RabbitMQ handles them first)
- The AS cleanup routine (`wp_actionscheduler_cleanup`) processes stale claimed actions, consuming CPU
- On a busy site processing 100+ jobs/minute, this creates 100+ orphaned AS actions per minute

**Root cause:** The AS enqueue was designed as a fallback for sites without the queue worker binary. When RabbitMQ is active, this fallback should be gated — only enqueue to AS if no dedicated queue worker is configured.

### 5.2 🟡 H2 — Concurrency Guard Race Condition (High)

**Location:** `includes/security/class-wp-mcp-ai-concurrency-guard.php:63-83`

The concurrency guard uses WordPress transients for slot tracking with a read-modify-write pattern:

```php
public static function acquire( $operation_type ) {
    $max     = self::get_limit( $operation_type );
    $key     = self::TRANSIENT_PREFIX . sanitize_key( $operation_type );
    $current = absint( get_transient( $key ) );   // READ
    
    if ( $current >= $max ) {
        return new WP_Error('concurrency_limit', ...);
    }
    
    set_transient( $key, $current + 1, self::LOCK_TTL );  // WRITE (non-atomic)
    return true;
}
```

**Impact:** Under concurrent execution (e.g., 5 simultaneous image generation requests arriving within the same millisecond), two PHP workers can both read `$current = 2`, both conclude the limit (3) is not reached, and both increment — resulting in 4 concurrent operations when the limit is 3.

**Root cause:** `get_transient` → `set_transient` is not atomic. There is no lock, no `wp_cache_incr`, and no database-level serialisation.

**Mitigation options (in order of preference):**
1. **Redis-backed object cache:** `wp_cache_incr()` / `wp_cache_decr()` are atomic when backed by Redis. Cloudways servers typically run Redis.
2. **Database atomic increment:** `UPDATE wp_options SET option_value = option_value + 1 WHERE option_name = ...` is atomic on InnoDB. This avoids the transient API entirely for counters.
3. **File-based lock:** `flock()` — already used by the queue worker, but adds I/O latency.

### 2.3 🟡 H3 — No PDO Connection Reuse (High)

**Location:** `addons/graphify/includes/remote/drivers/class-nvoos-graphify-remote-generic-sql.php:344-351`

Every call to `open_pdo()` creates a fresh PDO connection:

```php
private function open_pdo() {
    $dsn     = $this->build_dsn();
    $user    = (string) $this->config['username'];
    $pass    = (string) $this->config['password'];
    $timeout = max(1, (int) $this->config['connection_timeout']);
    
    $options = array(
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => $timeout,
        // NOTE: PDO::ATTR_PERSISTENT is NOT set
    );
    return new PDO($dsn, $user, $pass, $options);
}
```

**Impact:** If Graphify is configured with a scheduled rebuild polling a remote MySQL database, each rebuild cycle opens and tears down a TCP connection. Even at modest polling frequencies (every 15 minutes), this is needlessly wasteful. Worse, `PDO::ATTR_PERSISTENT` works particularly well with PHP-FPM (which Cloudways uses), as persistent connections survive across requests within the same FPM worker.

**Root cause:** No static PDO instance cache, no `PDO::ATTR_PERSISTENT` flag.

### 5.4 🟡 H4 — Polling Queue Worker Runs Even When RabbitMQ Is Active (High)

**Location:** `bin/queue-worker.php` (cron deployment), `includes/class-wp-mcp-ai-async-job-queue.php:171-181`

The DB queue worker's cron job (`wp_mcp_ai_process_job_queue`) fires every minute regardless of whether RabbitMQ is configured:

```php
// includes/class-wp-mcp-ai-async-job-queue.php:173-174
if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
    wp_schedule_event( time(), 'minute', self::CRON_HOOK );
}
```

**Impact:** On Cloudways servers with RabbitMQ enabled, the DB polling cron still fires every 60 seconds, queries `wp_mcp_ai_concurrent_jobs` (finding nothing because RabbitMQ consumed the jobs), and releases. This is a wasted MySQL connection 1,440 times per day — per site.

**Root cause:** The cron is scheduled unconditionally. It should be gated on whether RabbitMQ is the active transport.

### 5.5 🟡 M5 — Batch Size Too Conservative (Medium)

**Location:** `bin/queue-worker.php:236`

```php
$result = WP_MCP_AI_Job_Queue_Manager::process_queue( 3 );
```

The default batch size of 3 jobs per cron tick is overly conservative for production environments. On a site processing 50+ tools/minute, the queue worker needs ~17 cron ticks to clear the backlog — over 17 minutes of latency.

**Impact:** Tool execution latency for async jobs is artificially inflated because the queue worker processes too few jobs per cycle.

**Root cause:** Hardcoded value. No filter or configuration option.

### 5.6 🟡 M6 — No Connection Pool Health Monitoring (Medium)

**Location:** `addons/pro/includes/admin/sections/class-wp-mcp-ai-section-performance.php`

The performance section has a basic "Database Connectivity" check (does `$wpdb->get_results()` work). It does not report:

- Current `Threads_connected` vs `max_connections`
- Number of active `$wpdb` queries in the last minute
- Whether the DB queue worker daemon is running
- Queue depth for each transport (Action Scheduler pending, RabbitMQ queue depth, DB queue depth)

**Impact:** Operators cannot detect connection pool exhaustion before it causes failures.

---

## 6. Architecture Decision: Pooling Stance

### 6.1 WordPress `$wpdb` — Accept Default, No Persistent Connections

**Decision:** Do **not** enable MySQL persistent connections for WordPress.

**Rationale:**
- WordPress does not support persistent connections (`mysqli_pconnect`) natively.
- PHP-FPM (Cloudways) already manages worker lifecycle efficiently.
- Persistent connections can cause issues with transaction state leakage, prepared statement caching, and `SET NAMES` corruption across requests.
- The Cloudways Autoscale model automatically adds PHP workers under load.

**Instead:** Focus on reducing unnecessary queries (caching, combining reads) and eliminating the polling waste (§5.4).

### 6.2 PDO (Graphify Remote DB) — Enable Persistent Connections

**Decision:** Enable `PDO::ATTR_PERSISTENT` for remote database connections, gated to PHP-FPM only (not CLI).

**Rationale:**
- The `PDO::ATTR_PERSISTENT` flag is specifically designed for PHP-FPM scenarios where connection overhead matters.
- Graphify's remote DB queries are read-only `SELECT` statements — no transaction state to leak.
- On Cloudways Autonomous (K8s), where pods are longer-lived, this provides a substantial performance improvement.
- Gate behind `PHP_SAPI !== 'cli'` to avoid persistent connections in queue worker daemon processes.

### 6.3 RabbitMQ — Singleton Per Process

**Decision:** Keep the current singleton pattern. RabbitMQ connections are designed to be long-lived. The existing `QoS=1` (prefetch=1) setting is correct for fair dispatch.

**Improvement:** Do not disconnect at end of every batch in queue-worker.php (currently calls `$client->disconnect()` at line 493). In daemon mode, the connection should persist across batch boundaries.

### 6.4 Action Scheduler — Accept, But Gate Fallback Enqueue

**Decision:** Action Scheduler is the right default transport when neither RabbitMQ nor a queue worker daemon is available. But when RabbitMQ IS active, the AS fallback enqueue should be suppressed (§5.1).

### 6.5 Concurrency Guard — Migrate to Atomic Operations

**Decision:** The transient-based read-modify-write pattern is not safe at scale. Migrate to `wp_cache_incr()` / `wp_cache_decr()` (atomic on Redis) with a database fallback using InnoDB row-level locking.

---

## 7. Cloudways-Specific Considerations

### 7.1 RabbitMQ Availability

Cloudways Flexible (VPS) plans include RabbitMQ as an optional service. The plugin's `bin/queue-worker.php --rabbitmq` mode should be the **primary recommendation** for Cloudways deployments. The existing documentation in the queue worker file is good but should be surfaced in the admin UI.

### 7.2 Redis Object Cache

Most Cloudways servers run Redis. If Redis is available:
- The Concurrency Guard transient issue (§5.2) is mitigated because Redis `INCR`/`DECR` are atomic.
- The DB queue polling waste (§5.4) is less impactful but still wasteful.
- Add a Site Health check that detects Redis availability and recommends enabling it.

### 7.3 Cloudways Autonomous (Kubernetes)

On Autonomous plans:
- PHP workers are unlimited (auto-scaling pods). Worker exhaustion is not a bottleneck.
- Long-lived daemon processes (RabbitMQ consumer in daemon mode) are well-suited to K8s pods.
- Pod cost scales with idle time — blocking a pod polling an empty DB queue is expensive. RabbitMQ push model eliminates this waste.

### 7.4 Cloudways Flexible (VPS)

On VPS plans:
- PHP-FPM `pm.max_children` is typically 10-50. Connection pooling is more important.
- MySQL `max_connections` defaults to 151. With 10-20 FPM children + queue worker + web server, the margin is small.
- Action Scheduler's background runner can consume connections under load.

---

## 8. Risk Matrix

| # | Severity | Issue | Affected transport | User impact | Effort |
|---|---|---|---|---|---|
| C1 | 🔴 Critical | Dual-enqueue write amplification | Action Scheduler | Table bloat, cleanup CPU waste | 1 day |
| H2 | 🟡 High | Concurrency guard race condition | Transients | Concurrent operations exceed limits | 1–2 days |
| H3 | 🟡 High | No PDO connection reuse | PDO (Graphify) | Wasted remote DB connections on each poll | 0.5 day |
| H4 | 🟡 High | Polling worker runs alongside RabbitMQ | DB Queue | Wasted MySQL connections 1,440×/day | 0.5 day |
| M5 | 🟡 Medium | Batch size too small (3 jobs) | DB Queue | Artificial async job latency | 0.25 day |
| M6 | 🟡 Medium | No connection pool health monitoring | All | Operators blind to pool exhaustion | 2 days |

**Total estimated effort:** ~5.25 days

---

## 9. Transport Decision Matrix

| Scenario | Recommended Transport | Why |
|---|---|---|
| Cloudways VPS (Flexible) with RabbitMQ | RabbitMQ daemon (`--rabbitmq --daemon`) | Push-based, no polling waste, QoS=1 |
| Cloudways VPS (Flexible) without RabbitMQ | Action Scheduler + DB queue | AS for reliability, DB queue as safety net |
| Cloudways Autonomous (K8s) | RabbitMQ daemon pod | Long-lived container, natural fit |
| Shared hosting (no RabbitMQ, no WooCommerce) | DB queue + WP-Cron | Universal fallback |
| Self-hosted with WooCommerce | Action Scheduler (preferred) | WooCommerce-bundled, no extra infra |
| Self-hosted with RabbitMQ | RabbitMQ daemon (systemd unit) | Best performance, see `docs/operations/queue-worker-systemd.md` |
| Remote DB access (Graphify) | PDO with `ATTR_PERSISTENT` | Reduces connection overhead on PHP-FPM |

---

## 10. References

- `includes/class-wp-mcp-ai-rabbitmq-client.php` — RabbitMQ client (AMQP connection management, exchanges/queues, publish/consume)
- `lib/wordpress-adapter/src/Adapter/QueueClient.php` — QueueClient adapter (transport selection, dual-enqueue logic)
- `includes/class-wp-mcp-ai-job-queue-manager.php` — DB queue manager (concurrent job table)
- `includes/class-wp-mcp-ai-async-job-queue.php` — Async job queue (full lifecycle: queue_job → process_queue → execute_job)
- `includes/db/class-wp-mcp-ai-job-store.php` — Job store (durable, transport-agnostic job tracking)
- `includes/security/class-wp-mcp-ai-concurrency-guard.php` — Concurrency guard (transient-based slot tracking)
- `bin/queue-worker.php` — CLI queue worker (DB and RabbitMQ modes)
- `addons/graphify/includes/remote/drivers/class-nvoos-graphify-remote-generic-sql.php` — Remote SQL driver (PDO)
- `docker-compose.yml` — Development environment (MySQL 8.0, Media Worker sidecar)
- `includes/bootstrap/activation.php` — Table creation on activation
- `docs/project/proposals/009-rabbitmq-integration-proposal.md` — Original RabbitMQ integration proposal
- `docs/project/proposals/010-infrastructure-scaling-mitigation.md` — Infrastructure scaling plan
