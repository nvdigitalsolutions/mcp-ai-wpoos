# Proposal 009: RabbitMQ Integration — Async Job Transport & Decoupled Worker Architecture

**Status:** Draft  
**Date:** 2026-07-07  
**Author:** AI Agent (Zed) — Architecture Review  
**Related:** [010-infrastructure-scaling-mitigation.md](./010-infrastructure-scaling-mitigation.md)

---

## 1. Summary

NV oOS already ships a fully coded RabbitMQ client (`WP_MCP_AI_RabbitMQ_Client`) with 4 exchanges, 6 priority queues, dead-letter routing, message TTLs, and a singleton connection manager. However, the current job execution pipeline is **entirely in-process** — tools run synchronously inside the agentic REST loop, backed by `wp_options`-stored queue state. This proposal outlines the plan to **activate RabbitMQ as the primary async transport layer**, decouple long-running tool execution into dedicated queue workers, and replace the option-table-based queue storage with durable, broker-managed queues.

The proposal is **Cloudways-first**: Cloudways supports RabbitMQ as a one-click service add-on and provides Redis out of the box. The architecture is designed so the plugin degrades gracefully to its current option-table queue when RabbitMQ is unavailable (self-hosted, shared hosting, local dev).

---

## 2. Background

### 2.1 Current Queue Architecture (Pre-RabbitMQ)

```
┌─────────────────────────────────────────────────┐
│  WordPress Request                               │
│  ┌──────────┐    ┌──────────────────────────┐    │
│  │ REST API │───▶│ Agentic Loop (sequential) │    │
│  │ /chat    │    │ Tool 1 → Tool 2 → Tool 3  │    │
│  └──────────┘    └──────────┬───────────────┘    │
│                             │                     │
│                    ┌────────▼──────────┐          │
│                    │ Job_Queue_Manager │          │
│                    │ (wp_options)      │          │
│                    │ - QUEUE_STATE     │          │
│                    │ - ACTIVE_JOBS     │          │
│                    └────────┬──────────┘          │
│                             │                     │
│                    ┌────────▼──────────┐          │
│                    │ Dead_Letter_Queue │          │
│                    │ (wp_options)      │          │
│                    └───────────────────┘          │
└─────────────────────────────────────────────────┘
```

**Pain points:**
- Tool execution is **synchronous and sequential** — one slow tool blocks the entire chat pipeline.
- `wp_options` autoload serialization is not designed for queue workloads (read-modify-write races, serialized-array corruption under concurrency, autoload bloat).
- PHP-FPM workers are tied up waiting for 30–90s external API calls.
- No message durability — if a PHP process crashes mid-job, the job is lost (no ack/nack semantics).
- No priority preemption — option-based sorting is a best-effort approximation.

### 2.2 What's Already Built

`WP_MCP_AI_RabbitMQ_Client` (`includes/class-wp-mcp-ai-rabbitmq-client.php`) provides:

| Feature | Implementation |
|---|---|
| **Connection management** | Singleton with lazy connect, 5s connect timeout, 30s R/W timeouts, QoS prefetch=1 |
| **Exchange topology** | `tools` (direct), `chat` (topic), `deadletter` (fanout), `analytics` (fanout) — all durable |
| **Queue topology** | `tool.execution` (normal 5m TTL), `tool.execution.priority.high` (30s TTL, max-priority=10), `tool.execution.async` (1h TTL), `tool.results` (10m TTL), `agentic.workflow` (30m TTL), `deadletter.queue` (24h TTL) |
| **Publishing** | `publish()`, `queue_tool_execution()` with priority routing |
| **Result retrieval** | Transient-based (`wp_mcp_ai_job_{id}` / `wp_mcp_ai_job_result_{id}`) with via `store_job_result()` |
| **Health check** | `health_check()` — connection state, extension check, queue stats |
| **Config** | Constants or settings — `WP_MCP_AI_RABBITMQ_ENABLED`, host, port, user, pass, vhost, queue prefix |

### 2.3 Cloudways RabbitMQ Support

Cloudways natively supports RabbitMQ:

- **Enablement:** Server Management → Settings & Packages → RabbitMQ (toggle on).
- **Credentials:** Auto-provisioned; accessible via the Cloudways Platform API or the server dashboard.
- **Version:** 3.12.x+ (managed, auto-patched).
- **Networking:** Accessible from application servers on `localhost:5672` (same VPS) or private IP (multi-server).
- **Management UI:** Available on port `15672` (can be restricted to Cloudways VPN).

> **Reference:** [Cloudways — How to Manage Your Server Settings](https://support.cloudways.com/en/articles/5120689-how-to-manage-your-server-settings)

---

## 3. Proposed Architecture

### 3.1 Target State

```
┌──────────────────────────────────────────────────────────────────┐
│                        Cloudways VPS / K8s Pod                   │
│                                                                  │
│  ┌──────────────────────┐      ┌─────────────────────────────┐  │
│  │ WordPress (Nginx)    │      │ Queue Worker (PHP CLI)      │  │
│  │ ┌──────────────────┐ │      │                             │  │
│  │ │ Agentic Loop     │ │      │ bin/queue-worker.php        │  │
│  │ │ (sync tools)     │ │      │                             │  │
│  │ │                  │ │      │ Reads: tool.execution.*     │  │
│  │ │ Fast tools:      │ │      │ Executes: long-running tools│  │
│  │ │ - get_post       │ │      │ Reports: store_job_result() │  │
│  │ │ - search_content │ │      │                             │  │
│  │ │ - list_users     │ │      │ Supervised by:              │  │
│  │ │                  │ │      │ systemd / supervisord       │  │
│  │ │ Async dispatch:  │ │      │                             │  │
│  │ │ - deep_research  │─┼──────▶ tools exchange             │  │
│  │ │ - crawl4ai       │ │      │                             │  │
│  │ │ - generate_image │ │      └─────────────────────────────┘  │
│  │ └──────────────────┘ │                                        │
│  └──────────────────────┘                                        │
│              │                                                   │
│  ┌───────────▼──────────────────────────────────────────────┐   │
│  │                    RabbitMQ (localhost:5672)              │   │
│  │  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌─────────┐  │   │
│  │  │ tools    │  │ chat     │  │ deadlett │  │ analytic│  │   │
│  │  │ (direct) │  │ (topic)  │  │ (fanout) │  │(fanout) │  │   │
│  │  └──────────┘  └──────────┘  └──────────┘  └─────────┘  │   │
│  └──────────────────────────────────────────────────────────┘   │
│              │                                                   │
│  ┌───────────▼──────────────────────────────────────────────┐   │
│  │                   Redis (localhost:6379)                  │   │
│  │  - Object cache (wp_cache_get/set → Redis)               │   │
│  │  - Session store                                          │   │
│  │  - Transient backend (replaces wp_options autoload)       │   │
│  │  - Job result pub/sub for SSE fan-out                     │   │
│  └──────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
```

### 3.2 Sync/Async Boundary

**Stay synchronous (in-process agentic loop):**

These tools answer immediately (sub-500ms) and are needed for conversational flow:

- `get_post`, `search_content`, `list_users`, `get_option`, `read_media_file`
- All `read-only` flagged tools with local data sources
- Slash commands that return within the WordPress request cycle

**Dispatch async (RabbitMQ queue worker):**

These tools make external API calls or process large datasets:

- `deep_research` — multi-step web search + AI analysis
- `run_crawl4ai_job` — external crawler with polling
- `generate_gemini_image`, `generate_sora_video`, `generate_veo_video`
- `product_actualization` — scene fusion
- `execute_workflow`, `spawn_agent` — sub-agent dispatch
- `pro_pdf_document`, `pro_word_document` — document generation
- Any tool flagged `'async'` in its capability flags

### 3.3 Graceful Degradation

The existing `WP_MCP_AI_Async_Job_Queue` (custom DB table, cron-processed) is retained as the **fallback** when RabbitMQ is unavailable. The routing logic:

```php
// Pseudocode — routing decision in the tool dispatcher
if ( $tool->is_async_capable() && $rabbitmq->is_available() ) {
    $job_id = $rabbitmq->queue_tool_execution( $tool_name, $args, $ctx, $priority );
    return array( 'async_job_id' => $job_id, 'status' => 'queued' );
}

if ( $tool->is_async_capable() ) {
    // Fallback: DB-backed async queue with cron worker
    $job_id = WP_MCP_AI_Async_Job_Queue::queue_job( ... );
    return array( 'async_job_id' => $job_id, 'status' => 'queued_db' );
}

// Default: execute synchronously
return $tool->execute( $args, $ctx );
```

---

## 4. Implementation Plan

### Phase 1: Infrastructure Activation (Week 1)

**Goal:** Get RabbitMQ running on Cloudways and verify end-to-end connectivity.

| Task | Effort | Owner |
|---|---|---|
| Enable RabbitMQ in Cloudways Server Settings | 5 min | DevOps |
| Configure `wp-config.php` constants (`WP_MCP_AI_RABBITMQ_ENABLED`, host, credentials) | 15 min | DevOps |
| Run `WP_MCP_AI_RabbitMQ_Client::setup_infrastructure()` to create exchanges/queues | 1 click | Plugin |
| Verify via `health_check()` and Cloudways RabbitMQ Management UI | 15 min | QA |
| Add RabbitMQ status to Site Health screen (`WP_MCP_AI_Site_Health`) | 2h | Dev |

**Deliverable:** RabbitMQ operational, exchanges/queues declared, health check passing.

### Phase 2: Queue Worker — Standalone CLI Process (Weeks 1–2)

**Goal:** A PHP CLI script that consumes from `tool.execution.*` queues and executes tools.

**New file:** `bin/queue-worker.php`

```php
#!/usr/bin/env php
<?php
/**
 * NV oOS Queue Worker — RabbitMQ consumer for async tool execution.
 *
 * Usage: php bin/queue-worker.php [--queue=normal|high|async] [--memory-limit=256M]
 *
 * Run under systemd, supervisord, or Cloudways Cron (every minute with a
 * file-lock to prevent overlapping runs on Flexible plans; long-running
 * daemon on Autonomous with unlimited workers).
 */

// Bootstrap WordPress (minimal load — skip theme, skip frontend).
define( 'WP_USE_THEMES', false );
require_once __DIR__ . '/../wp-load.php';

// ... consume loop with ack/nack, signal handling, memory-limit checks
```

**Key design decisions:**

| Decision | Rationale |
|---|---|
| **PHP CLI, not wp-cron** | wp-cron fires on HTTP requests; a queue worker needs continuous polling independent of web traffic |
| **systemd on Autonomous, cron-lock on Flexible** | Autonomous (K8s) supports long-running pods; Flexible (VPS) should use cron-triggered workers with file locking |
| **ACK after execution, NACK on failure** | RabbitMQ redelivers unacked messages; failed tools go to dead-letter exchange after TTL |
| **Memory limit watchdog** | Exit cleanly at 90% of `memory_limit`; systemd/cron restarts automatically |

### Phase 3: Migrate Queue Storage Off wp_options (Weeks 2–3)

**Goal:** `WP_MCP_AI_Job_Queue_Manager` and `WP_MCP_AI_Dead_Letter_Queue` stop using `get_option()`/`update_option()` for queue state.

**Changes:**

| Class | Current Storage | New Storage |
|---|---|---|
| `WP_MCP_AI_Job_Queue_Manager` | `wp_options` (`QUEUE_STATE_OPTION`, `ACTIVE_JOBS_OPTION`) | Custom DB table `wp_mcp_ai_jobs` (like `Async_Job_Queue` already uses) |
| `WP_MCP_AI_Dead_Letter_Queue` | `wp_options` (`OPTION_NAME`) | Custom DB table `wp_mcp_ai_dead_letters` |

**Migration strategy:**

1. Add new tables via `dbDelta()` on plugin update.
2. New code writes to both old and new storage for one release cycle (dual-write).
3. Read from new table first; fall back to old option.
4. After one release, drop dual-write and add a WP-CLI command to migrate historical data.
5. Delete old `wp_options` rows.

### Phase 4: Wire the Agentic Loop (Week 3)

**Goal:** The REST agentic loop (`class-wp-mcp-ai-rest.php`) dispatches async-capable tools to RabbitMQ instead of executing them inline.

**Changes in `class-wp-mcp-ai-rest.php` (agentic loop, lines ~2578-2950):**

```php
// In the tool execution loop:
if ( $tool->has_capability_flag( 'async' ) && $rabbitmq->is_available() ) {
    $job_id = $rabbitmq->queue_tool_execution(
        $tool->get_slug(),
        $arguments,
        $context,
        $priority
    );

    // Return a pending marker to the AI model so it knows the result
    // will arrive as a follow-up tool result.
    $pending_results[] = array(
        'tool_call_id' => $tool_call['id'],
        'async_job_id' => $job_id,
        'status'       => 'pending',
    );

    continue; // Don't block — move to next tool call.
}
```

**Result polling:** The agentic loop emits SSE events when job results arrive (via Redis pub/sub or RabbitMQ `tool.results` queue). The chat UI shows a progress indicator for pending async tools.

### Phase 5: Observability & Admin Dashboard (Week 4)

| Feature | Implementation |
|---|---|
| **Queue depth gauges** | Admin dashboard widget showing messages in each RabbitMQ queue |
| **Worker health** | Heartbeat from queue workers stored in a transient; stale workers raise admin notice |
| **DLQ browser** | Replace the existing `WP_MCP_AI_Dead_Letter_Queue` admin UI with a tab that shows both option-table DLQ and RabbitMQ dead-letter queue |
| **Cost attribution** | Tag each RabbitMQ message with `assistant_id` + `user_id` for per-assistant cost tracking |

---

## 5. Cloudways-Specific Configuration

### 5.1 Cloudways Flexible (VPS)

```php
// wp-config.php
define( 'WP_MCP_AI_RABBITMQ_ENABLED', true );
define( 'WP_MCP_AI_RABBITMQ_HOST', 'localhost' );     // RabbitMQ runs on the same VPS
define( 'WP_MCP_AI_RABBITMQ_PORT', 5672 );
define( 'WP_MCP_AI_RABBITMQ_USERNAME', '***' );       // From Cloudways dashboard
define( 'WP_MCP_AI_RABBITMQ_PASSWORD', '***' );
define( 'WP_MCP_AI_RABBITMQ_VHOST', '/' );

// Worker strategy: cron-triggered with file locking
// Add to Cloudways Cron (every 1 minute):
// php /home/master/applications/{app}/public_html/wp-content/plugins/mcp-ai-wpoos/bin/queue-worker.php --timeout=55
```

**Worker supervisor (Cloudways Flexible):** On Flexible plans, long-running daemons require a cron-triggered approach with file locking. The worker script acquires an exclusive lock (`flock()`) before processing; subsequent cron invocations skip if the lock is held.

### 5.2 Cloudways Autonomous (Kubernetes)

```php
// wp-config.php — same RabbitMQ constants, plus:
define( 'WP_MCP_AI_RABBITMQ_HOST', 'rabbitmq-service' ); // K8s service name
define( 'WP_REDIS_HOST', 'redis-service' );              // Object Cache Pro included
```

**Worker deployment (Autonomous):** On Autonomous (K8s pods), the queue worker can run as a **sidecar container** or a **separate Deployment**:

```yaml
# queue-worker-deployment.yaml (illustrative — Cloudways manages K8s)
apiVersion: apps/v1
kind: Deployment
metadata:
  name: nvoos-queue-worker
spec:
  replicas: 2  # Scale horizontally
  template:
    spec:
      containers:
      - name: worker
        image: php:8.2-cli
        command: ["php", "bin/queue-worker.php"]
        resources:
          requests:
            memory: "256Mi"
            cpu: "500m"
```

> **Note:** Cloudways Autonomous abstracts K8s management. The actual deployment mechanism is via their platform API/dashboard. The worker can run as an always-on process since Autonomous offers unlimited PHP workers and auto-scaling containers.

---

## 6. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| RabbitMQ service unavailable (Cloudways outage) | Low | High | Graceful fallback to DB-backed `Async_Job_Queue`; admin alert |
| `ext-amqp` not installed | Medium (Flexible) | High | Auto-detect in `is_available()`; fall back to DB queue; add to Site Health checks |
| Message loss during worker crash | Medium | Medium | `delivery_mode=2` (persistent); ACK only after successful tool execution |
| Queue worker memory leak (long-running PHP) | Medium | Low | `memory_limit` watchdog; exit cleanly; systemd/cron restarts |
| SSE result delivery race condition | Low | Medium | Redis pub/sub for real-time result fan-out; polling fallback every 3s |
| Cloudways Flexible file-lock race with multiple cron triggers | Low | Low | `flock()` with `LOCK_EX | LOCK_NB`; process PID in lock file for stale detection |

---

## 7. Migration Impact

| Concern | Assessment |
|---|---|
| **Backward compatibility** | Full — RabbitMQ is opt-in. Sites without it continue using the option-table queue. |
| **Database changes** | Two new tables (`wp_mcp_ai_jobs`, `wp_mcp_ai_dead_letters`); old option rows cleaned up after one release cycle. |
| **Tool API changes** | None — tools implement the same `execute()` method. The async dispatch is transparent to tool authors. |
| **WP-CLI** | New commands: `wp nvoos queue stats`, `wp nvoos queue worker`, `wp nvoos queue dlq list` |
| **Admin UI** | New "Queue" tab under NV oOS → Orchestration showing RabbitMQ health, queue depths, DLQ browser |

---

## 8. Acceptance Criteria

1. **RabbitMQ available on Cloudways:** One-click enable in Server Settings; plugin auto-detects and declares exchanges/queues.
2. **Async tool dispatch:** Long-running tools (deep_research, crawl4ai, image/video generation) return `async_job_id` immediately instead of blocking the chat loop.
3. **Queue worker processes jobs:** `bin/queue-worker.php` consumes, executes tools, and stores results. Results retrievable via `get_job_result()`.
4. **Graceful fallback:** Disabling RabbitMQ or running on a host without it falls back to the existing DB-backed `Async_Job_Queue` without errors.
5. **Queue storage off wp_options:** `Job_Queue_Manager` and `Dead_Letter_Queue` read/write dedicated DB tables; old option rows cleaned up.
6. **Observability:** Admin dashboard shows queue depths, worker health, and DLQ contents. Site Health integration reports RabbitMQ status.
7. **Cloudways deployment guide:** Step-by-step docs for enabling RabbitMQ and configuring the queue worker on both Flexible and Autonomous plans.

---

## 9. Total Estimated Effort

| Phase | Working Days |
|---|---|
| Phase 1: Infrastructure Activation | 1–2 days |
| Phase 2: Queue Worker CLI | 3–5 days |
| Phase 3: Migrate Queue Storage | 2–3 days |
| Phase 4: Wire Agentic Loop | 2–3 days |
| Phase 5: Observability & Admin | 2–3 days |
| **Total** | **10–16 working days (2–3 weeks)** |

---

## 10. References

- [Cloudways RabbitMQ Documentation](https://support.cloudways.com/en/articles/5120689-how-to-manage-your-server-settings)
- [Cloudways Autonomous — Kubernetes WordPress Hosting](https://www.cloudways.com/en/autonomous.php)
- [RabbitMQ PHP AMQP Extension](https://www.php.net/manual/en/book.amqp.php)
- [RabbitMQ Tutorials — Work Queues](https://www.rabbitmq.com/tutorials/tutorial-two-php.html)
- [WordPress Action Scheduler Library](https://actionscheduler.org/) (alternative approach for DB-backed queues)
- NV oOS Codebase: `includes/class-wp-mcp-ai-rabbitmq-client.php`
- NV oOS Codebase: `includes/class-wp-mcp-ai-async-job-queue.php`
- NV oOS Codebase: `includes/class-wp-mcp-ai-job-queue-manager.php`
- NV oOS Codebase: `includes/class-wp-mcp-ai-dead-letter-queue.php`
- Related Proposal: [010-infrastructure-scaling-mitigation.md](./010-infrastructure-scaling-mitigation.md)
