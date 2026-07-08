# Proposal 010: Infrastructure Scaling & Production Hardening — Cloudways-First Mitigation Plan

**Status:** Draft  
**Date:** 2026-07-07  
**Author:** AI Agent (Zed) — Architecture Review  
**Related:** [009-rabbitmq-integration-proposal.md](./009-rabbitmq-integration-proposal.md)

---

## 1. Summary

An architecture audit of NV oOS v1.1.36 identified five scaling risks that must be addressed before production launch on Cloudways. This document maps each risk to a **concrete mitigation**, prioritized by severity, with specific implementation guidance for **Cloudways Flexible (VPS)** and **Cloudways Autonomous (Kubernetes)** deployment targets.

The mitigations are designed to be **incrementally adoptable** — each one can be shipped independently without breaking existing functionality. The RabbitMQ integration (Proposal 009) is the cornerstone mitigation and should be implemented first.

---

## 2. Issue Inventory & Mitigation Map

| # | Severity | Issue | Mitigation | Effort |
|---|---|---|---|---|
| 1 | 🔴 Critical | In-Process Job Execution | RabbitMQ async dispatch + CLI queue worker | 10–16 days (see Proposal 009) |
| 2 | 🔴 Critical | Option-Table Queue Storage | Custom DB tables for queue state + Redis transient backend | 2–3 days |
| 3 | 🟡 High | No Production Container Orchestration | Cloudways-native scaling (Autonomous K8s) + production docker-compose override for self-hosted | 3–5 days docs + config |
| 4 | 🟡 High | SSE Connection Saturation | Cloudflare Worker SSE offload + connection pooling + Autonomous unlimited PHP workers | 5–8 days |
| 5 | 🟡 Medium | No Distributed Caching Layer | Redis Object Cache (Cloudways built-in) + cache-helper hardening | 2–3 days |

---

## 3. Mitigation Details

### 3.1 🔴 Critical — In-Process Job Execution

#### Problem

The agentic loop in `class-wp-mcp-ai-rest.php` executes tools **sequentially and synchronously**:

```php
// Current (simplified) — includes/class-wp-mcp-ai-rest.php lines ~2578-2950
foreach ( $tool_calls as $tool_call ) {
    $result = $tool->execute( $arguments, $context );
    // PHP-FPM worker blocked here for 30-90s on external API calls.
    $messages[] = format_tool_result( $tool_call, $result );
}
```

A single `deep_research` or `run_crawl4ai_job` call blocks the entire chat pipeline. Under concurrent load (10+ simultaneous chats), PHP-FPM workers are saturated waiting for external AI API responses, causing 504 timeouts for all other requests.

#### Cloudways Context

| Plan | PHP Workers | Implication |
|---|---|---|
| **Flexible (VPS)** | Limited by server size (typically 10–50 FPM children) | Worker exhaustion is a real risk at moderate concurrency |
| **Autonomous (K8s)** | **Unlimited** — auto-scaling pods | FPM exhaustion is not a bottleneck, but **pod cost scales with idle time** — blocking a pod for 90s waiting for OpenAI is expensive |

#### Mitigation

**Primary:** RabbitMQ-based async tool dispatch (see Proposal 009). The agentic loop dispatches async-capable tools to RabbitMQ and continues processing other tool calls. Results arrive asynchronously via SSE.

**Secondary (available today, no RabbitMQ):** Leverage WordPress's existing HTTP API timeout controls for external calls:

```php
// In provider clients (OpenAI, Gemini, etc.), already partially implemented:
$args['timeout'] = apply_filters(
    'wp_mcp_ai_http_request_timeout',
    30,  // Default: 30s (down from 120s WordPress default)
    $provider,
    $endpoint
);
```

Add a **progressive timeout** filter that shortens timeouts when the server is under load:

```php
add_filter( 'wp_mcp_ai_http_request_timeout', function ( $timeout ) {
    $active_chats = WP_MCP_AI_Resource_Manager::instance()->get_active_chat_count();
    if ( $active_chats > 5 ) {
        return max( 10, $timeout - ( $active_chats * 2 ) );
    }
    return $timeout;
}, 10, 3 );
```

**Cloudways Autonomous advantage:** Unlimited PHP workers mean you can safely increase FPM `pm.max_children` and let pods scale horizontally. The key is making sure idle pods aren't blocked — which RabbitMQ async dispatch solves.

#### Implementation Steps

1. **Immediate (no code changes):** Add `WP_MCP_AI_HTTP_REQUEST_TIMEOUT` constant to `wp-config.php` on production (30s default).
2. **Short-term:** Implement Proposal 009 (RabbitMQ async dispatch).
3. **Long-term:** Agentic loop sends **all** external-API tools through the queue; only local DB/option reads remain synchronous.

---

### 3.2 🔴 Critical — Option-Table Queue Storage

#### Problem

Two key classes store queue state in `wp_options`:

```php
// WP_MCP_AI_Job_Queue_Manager (lines 537-571)
protected static function get_queue_state() {
    return get_option( self::QUEUE_STATE_OPTION, array() ); // Single serialized array
}
protected static function save_queue_state( array $queue ) {
    return update_option( self::QUEUE_STATE_OPTION, $queue, false );
}

// WP_MCP_AI_Dead_Letter_Queue (lines 135-148)
public static function get_all( $filters = array() ) {
    return get_option( self::OPTION_NAME, array() ); // Single serialized array
}
protected static function save_items( $items ) {
    return update_option( self::OPTION_NAME, $items, false );
}
```

**Why this is dangerous:**

- `wp_options` autoloads on every WordPress request. A bloated queue option (thousands of jobs) slows **every** page load, not just queue processing.
- `update_option()` on a serialized array is **not atomic** under concurrent writes. Two simultaneous `get→modify→update` cycles can silently lose jobs.
- WordPress options are cached in memory. Multiple PHP-FPM workers see stale copies of the queue, leading to duplicate job execution.
- No transactional semantics — you can't atomically dequeue + execute.

#### Industry Best Practice

| Approach | Used By | Notes |
|---|---|---|
| **Custom DB table** | Action Scheduler, WooCommerce | Single-row-per-job, indexed, transactional via `$wpdb` |
| **Redis lists** | WP Redis, Cavalcade | Atomic `LPUSH`/`BRPOP`, no serialization overhead, but data loss risk without AOF persistence |
| **RabbitMQ** | This proposal | Purpose-built for queues; durability, priority, DLQ built-in |
| **MySQL `SELECT ... FOR UPDATE`** | EDD, GiveWP | Row-level locking for job claiming; requires InnoDB |

#### Mitigation

**Primary:** Migrate both classes to custom DB tables (matching the pattern already established by `WP_MCP_AI_Async_Job_Queue` which **already** uses a custom table).

**Secondary:** When Redis is available, use it as the transient/cache backend so that queue result lookups don't hit `wp_options`:

```php
// wp-config.php — Cloudways provides Redis out of the box
define( 'WP_REDIS_HOST', 'localhost' );
define( 'WP_REDIS_PORT', 6379 );
// Install and activate the Redis Object Cache plugin
```

**New table schemas:**

```sql
-- Replaces WP_MCP_AI_Job_Queue_Manager::QUEUE_STATE_OPTION
CREATE TABLE wp_mcp_ai_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id VARCHAR(64) NOT NULL UNIQUE,
    callable_class VARCHAR(255) NOT NULL,
    callable_method VARCHAR(255),
    args LONGTEXT,              -- JSON-encoded arguments
    priority INT DEFAULT 5,
    sla_tier VARCHAR(32),
    status ENUM('pending','active','failed','complete') DEFAULT 'pending',
    retry_count INT DEFAULT 0,
    max_retries INT DEFAULT 3,
    last_error TEXT,
    enqueued_at DATETIME,
    started_at DATETIME,
    completed_at DATETIME,
    INDEX idx_status_priority (status, priority, enqueued_at),
    INDEX idx_sla_tier (sla_tier, status)
) ENGINE=InnoDB;

-- Replaces WP_MCP_AI_Dead_Letter_Queue::OPTION_NAME
CREATE TABLE wp_mcp_ai_dead_letters (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id VARCHAR(64) NOT NULL UNIQUE,
    type VARCHAR(32) NOT NULL,
    identifier VARCHAR(255) NOT NULL,
    data LONGTEXT,              -- JSON-encoded payload
    failure_reason TEXT,
    retry_history LONGTEXT,     -- JSON-encoded array
    retry_count INT DEFAULT 0,
    dismissed TINYINT(1) DEFAULT 0,
    added_at DATETIME,
    dismissed_at DATETIME,
    INDEX idx_type (type, dismissed),
    INDEX idx_added_at (added_at)
) ENGINE=InnoDB;
```

#### Atomic Job Claiming

Use `$wpdb->query( 'START TRANSACTION' )` + `SELECT ... FOR UPDATE` to atomically claim jobs:

```php
$wpdb->query( 'START TRANSACTION' );
$job = $wpdb->get_row( $wpdb->prepare(
    "SELECT * FROM wp_mcp_ai_jobs
     WHERE status = 'pending'
     ORDER BY priority DESC, enqueued_at ASC
     LIMIT 1
     FOR UPDATE SKIP LOCKED"  -- SKIP LOCKED requires MySQL 8.0+ (Cloudways default)
) );

if ( $job ) {
    $wpdb->update( 'wp_mcp_ai_jobs',
        array( 'status' => 'active', 'started_at' => current_time( 'mysql' ) ),
        array( 'id' => $job->id )
    );
}
$wpdb->query( 'COMMIT' );
```

`SKIP LOCKED` (MySQL 8.0+) allows multiple workers to claim jobs concurrently without blocking each other — a critical feature for horizontal scaling.

#### Implementation Steps

1. **Week 1:** Add `dbDelta()` table creation to the plugin activation hook.
2. **Week 1–2:** Dual-write to both old option and new table (write to table, sync to option for backward compat).
3. **Week 2:** Switch read paths to new table; fall back to option if table is empty.
4. **Week 3:** Remove option writes; add WP-CLI migration command.
5. **Week 4:** Delete old option rows in a cleanup cron.

---

### 3.3 🟡 High — No Production Container Orchestration

#### Problem

`docker-compose.yml` is development-only (single Apache+PHP container, single MySQL). There is no production deployment configuration, no load-balancing strategy, no health-check endpoints, and no guidance for self-hosted users.

#### Cloudways Context

Cloudways **eliminates most container orchestration concerns** for managed hosting:

| Concern | Cloudways Handling |
|---|---|
| **Web server (Nginx + Apache)** | Thunderstack (Flexible) or Lightning Stack (Autonomous) — pre-configured, optimized |
| **PHP-FPM tuning** | Auto-tuned per server size on Flexible; unlimited workers on Autonomous |
| **MySQL/MariaDB** | Managed, auto-backup, Galera cluster option on Autonomous |
| **Redis** | One-click enable; Object Cache Pro included on Autonomous |
| **SSL** | Auto-renewing Let's Encrypt via Cloudways Platform |
| **CDN** | Cloudflare Enterprise included on Autonomous |
| **Scaling** | Vertical (Flexible: upgrade server size) or Horizontal (Autonomous: K8s auto-scaling pods) |
| **Health checks** | Built-in monitoring; custom health-check URLs configurable |

#### What Cloudways Does NOT Handle

| Concern | Requires Plugin-Level Work |
|---|---|
| **Queue worker daemon** | Cloudways doesn't know about NV oOS queue workers — must be configured manually or via platform API |
| **Graceful SSE shutdown** | Standard Nginx `proxy_read_timeout` configuration needed |
| **Multi-server RabbitMQ** | If scaling beyond a single VPS, RabbitMQ clustering/HA must be configured separately |
| **Plugin-level health checks** | Custom REST endpoint for load balancer health probes |

#### Mitigation

**For Cloudways Flexible (VPS):**

```nginx
# Cloudways Nginx configuration (via Platform → Application Settings → Nginx)
# Added to the server block:

# Extend timeout for SSE streaming
proxy_read_timeout 600s;
proxy_send_timeout 600s;

# Health check endpoint for Cloudways monitoring
location = /wp-json/mcp-ai/v1/health {
    access_log off;
    return 200 '{"status":"ok"}';
    add_header Content-Type application/json;
}
```

**For Cloudways Autonomous (K8s):**

Autonomous handles Nginx configuration automatically. The queue worker runs as a long-lived process:

```bash
# Cloudways Autonomous — add to the application's startup script
# (via Cloudways Platform API or support ticket)

# Queue worker daemon
php /var/www/html/wp-content/plugins/mcp-ai-wpoos/bin/queue-worker.php \
    --daemon \
    --memory-limit=256M \
    --queue=normal,high,async
```

**For self-hosted (Docker):**

Add a `docker-compose.prod.yml` override:

```yaml
# docker-compose.prod.yml — NOT committed (contains secrets)
# See docs/operations/docker-production.md for full reference

services:
  wordpress:
    build:
      context: .
      dockerfile: docker/Dockerfile.prod
    deploy:
      replicas: 3
    environment:
      WP_REDIS_HOST: redis
      WP_MCP_AI_RABBITMQ_HOST: rabbitmq

  redis:
    image: redis:7-alpine
    volumes:
      - redis_data:/data

  rabbitmq:
    image: rabbitmq:3.12-management-alpine
    environment:
      RABBITMQ_DEFAULT_USER: ${RABBITMQ_USER}
      RABBITMQ_DEFAULT_PASS: ${RABBITMQ_PASS}

  queue-worker:
    build:
      context: .
      dockerfile: docker/Dockerfile.worker
    deploy:
      replicas: 2
    depends_on:
      - rabbitmq
      - wordpress
```

#### Plugin-Level Health Check Endpoint

```php
// New: includes/rest/class-wp-mcp-ai-rest-health.php
register_rest_route( 'mcp-ai/v1', '/health', array(
    'methods'             => 'GET',
    'callback'            => array( $this, 'health_check' ),
    'permission_callback' => '__return_true', // Read-only, no auth needed
) );

public function health_check() {
    return array(
        'status'    => 'ok',
        'timestamp' => time(),
        'checks'    => array(
            'database'       => $this->check_database(),
            'rabbitmq'       => $this->check_rabbitmq(),
            'redis'          => $this->check_redis(),
            'queue_workers'  => $this->check_queue_workers(),
            'active_chats'   => WP_MCP_AI_Resource_Manager::instance()->get_active_chat_count(),
        ),
    );
}
```

#### Implementation Steps

1. Add `/wp-json/mcp-ai/v1/health` endpoint (1 day).
2. Write `docs/operations/cloudways-deployment.md` — step-by-step Cloudways setup guide (2 days).
3. Create `docker-compose.prod.yml` + `docker/Dockerfile.prod` + `docker/Dockerfile.worker` for self-hosted reference (2 days).
4. Add graceful SSE shutdown logic to `WP_MCP_AI_SSE_Stream` (1 day).

---

### 3.4 🟡 High — SSE Connection Saturation

#### Problem

Each chat session opens an SSE connection:

```
Client (browser) ←──SSE──→ WordPress (Apache/Nginx) ←──→ AI Provider
```

With Apache `mod_php` (Cloudways Flexible default), each SSE connection **blocks one PHP-FPM worker for the entire chat session duration** (minutes to hours). With 100 concurrent chats, 100 PHP-FPM workers are permanently occupied — leaving none for regular page requests.

#### Industry Best Practices

| Approach | Used By | Trade-off |
|---|---|---|
| **Dedicated SSE proxy (Node/Go)** | Slack, Discord | Best performance; adds infrastructure complexity |
| **Cloudflare Workers with WebSockets** | Cloudflare, Vercel | Offloads connection holding to edge; requires Worker code |
| **Nginx with `proxy_buffering off`** | Standard WP hosts | Mitigates but doesn't solve — worker still held |
| **Short-lived SSE connections with reconnect** | OpenAI API, Anthropic | Client reconnects on timeout; server-side remains stateless |
| **Polling fallback** | Legacy apps | Wastes bandwidth but requires no persistent connections |

#### Cloudways Context

| Plan | SSE Strategy |
|---|---|
| **Flexible (VPS)** | PHP-FPM workers are finite. SSE connections **must** be kept short-lived or offloaded. |
| **Autonomous (K8s)** | Unlimited workers. 100 SSE connections = 100 pods (auto-scaled). This works but is **cost-inefficient** — pods sit idle between AI response chunks. |

#### Mitigation

**Primary: Cloudflare Worker SSE offload (SaaS path)**

The existing Cloudflare Worker (`addons/cloud-worker/`) already proxies `/v1/chat/completions`. Extend it to manage SSE connections:

```
Browser ──SSE──▶ Cloudflare Worker ──HTTP──▶ WordPress REST (stateless)
  │                  │                            │
  │   (persistent)    │   (short-lived)            │   (no SSE holding)
  │                  │                            │
  ▼                  ▼                            ▼
              Worker buffers SSE chunks
              from AI provider response
```

The browser holds a persistent SSE connection to the **Worker** (near-zero cost at Cloudflare's edge), while WordPress gets short-lived HTTP requests. This is the same pattern used by Vercel AI SDK and Cloudflare AI Gateway.

**Secondary: Chunked SSE with reconnect (self-hosted path)**

For self-hosted deployments without the Cloudflare Worker:

```php
// WP_MCP_AI_SSE_Stream — add configurable max connection time
define( 'WP_MCP_AI_SSE_MAX_DURATION', 120 ); // Seconds before forcing reconnect

// Client-side reconnect logic (already partially implemented):
// assets/js/chat.js — reconnect with Last-Event-ID header
```

The server sends a `retry: 3000` field and closes the connection after 120s. The client reconnects automatically, picking up where it left off via `Last-Event-ID`. This keeps PHP-FPM workers occupied for at most 2 minutes instead of hours.

**Tertiary: Autonomous unlimited workers (Cloudways-only)**

On Autonomous, the "throw workers at it" approach works but should be combined with connection timeouts to manage pod costs.

#### Implementation Steps

1. **Week 1:** Add `WP_MCP_AI_SSE_MAX_DURATION` filter + forced reconnect logic to `WP_MCP_AI_SSE_Stream`.
2. **Week 1:** Verify client-side reconnect with `Last-Event-ID` in `assets/js/chat.js`.
3. **Week 2–3:** Extend Cloudflare Worker (`addons/cloud-worker/src/inference.ts`) to tee the AI provider's SSE stream and hold the browser connection at the edge.
4. **Week 3:** Add `X-NV-SSE-Connection-Id` header for connection tracking.

---

### 3.5 🟡 Medium — No Distributed Caching Layer

#### Problem

NV oOS uses WordPress transients extensively for job results, rate-limit counters, and cache data. WordPress transients default to `wp_options` (autoloaded, serialized) unless a persistent object cache is configured.

Without Redis/Memcached:
- Transient writes are `INSERT ... ON DUPLICATE KEY UPDATE` queries against `wp_options`.
- Multiple WordPress nodes behind a load balancer see different transient states (cache divergence).
- The `WP_MCP_AI_Cache_Helper` class wraps these calls but can't fix the underlying storage.

#### Cloudways Context

**Both Cloudways plans include Redis:**

| Plan | Redis Setup |
|---|---|
| **Flexible** | One-click enable via Server Management → Settings & Packages → Redis |
| **Autonomous** | Object Cache Pro included by default; Redis pre-configured |

This is the **easiest mitigation to implement** — Cloudways has already done the infrastructure work.

#### Mitigation

**Primary: Enable Redis Object Cache**

```php
// wp-config.php — Cloudways provides Redis on localhost
define( 'WP_REDIS_HOST', 'localhost' );
define( 'WP_REDIS_PORT', 6379 );
define( 'WP_REDIS_DATABASE', 0 );
define( 'WP_CACHE_KEY_SALT', 'nvoos_' . wp_generate_uuid4() ); // Unique per install

// Use the Redis Object Cache plugin (free, on wp.org)
// or Object Cache Pro (included with Cloudways Autonomous)
```

After activation, WordPress transients automatically store in Redis instead of `wp_options`. This:
- Eliminates cache divergence across nodes
- Reduces `wp_options` autoload bloat
- Makes transient operations O(1) instead of O(n) serialized array scans

**Secondary: NV oOS cache-helper hardening**

Update `WP_MCP_AI_Cache_Helper` to:

1. Detect if Redis is available and log a Site Health warning if not (production guidance).
2. Add a `wp_mcp_ai_cache_group` prefix to all cache keys for easy identification in Redis monitoring.
3. Use `wp_cache_get_multiple_salted()` and `wp_cache_set_salted()` for query-cache groups (WP 6.9+ pattern, already referenced in your skill docs).

```php
// WP_MCP_AI_Cache_Helper — add Redis detection
public static function get_cache_backend() {
    global $wp_object_cache;
    if ( $wp_object_cache instanceof WP_Object_Cache && wp_using_ext_object_cache() ) {
        return 'redis'; // or 'memcached'
    }
    return 'database';
}

// Site Health integration
add_filter( 'site_status_tests', function ( $tests ) {
    $tests['direct']['nvoos_cache_backend'] = array(
        'label' => 'NV oOS Cache Backend',
        'test'  => array( 'WP_MCP_AI_Cache_Helper', 'site_health_test' ),
    );
    return $tests;
} );
```

#### Implementation Steps

1. **Day 1:** Enable Redis on Cloudways (one click in dashboard).
2. **Day 1:** Install and activate Redis Object Cache plugin.
3. **Day 1–2:** Update `WP_MCP_AI_Cache_Helper` with Redis detection + Site Health integration.
4. **Day 2:** Test: verify transients are stored in Redis (`redis-cli KEYS *nvoos*`).

---

## 4. Cloudways Deployment Checklist

### Cloudways Flexible (VPS Launch)

- [ ] Enable Redis (Server → Settings & Packages → Redis)
- [ ] Enable RabbitMQ (Server → Settings & Packages → RabbitMQ)
- [ ] Install Redis Object Cache plugin (wp.org)
- [ ] Add `wp-config.php` constants:
  - `WP_MCP_AI_RABBITMQ_ENABLED`, `WP_MCP_AI_RABBITMQ_HOST`, credentials
  - `WP_REDIS_HOST`, `WP_REDIS_PORT`
  - `WP_MCP_AI_HTTP_REQUEST_TIMEOUT` (30)
- [ ] Configure Cloudways Cron: `php bin/queue-worker.php --timeout=55` every 1 minute
- [ ] Add Nginx `proxy_read_timeout 600s` for SSE endpoints
- [ ] Configure Cloudways monitoring (add `/wp-json/mcp-ai/v1/health` as custom health check URL)
- [ ] Enable Cloudways bot protection (reduces noise on SSE endpoints)
- [ ] Run `wp nvoos rabbitmq setup` WP-CLI command to declare exchanges/queues

### Cloudways Autonomous (K8s Launch)

- [ ] Verify Object Cache Pro is active (included by default)
- [ ] Enable RabbitMQ via platform API or support ticket
- [ ] Add `wp-config.php` constants (same as Flexible, except `RABBITMQ_HOST` = service name)
- [ ] Request queue worker as always-on process via Cloudways support
- [ ] Set `WP_MCP_AI_SSE_MAX_DURATION` to 300 (Autonomous can handle longer connections)
- [ ] Add health check endpoint for K8s liveness probe

---

## 5. Dependency Order

```
Week 1:  Redis (Mitigation 3.5) ─── Easy win, Cloudways one-click
         │
         ▼
Week 2:  DB Tables (Mitigation 3.2) ─── Dual-write phase, no behavior change yet
         │
         ▼
Week 3:  RabbitMQ (Proposal 009 Phase 1-2) ─── Infrastructure + worker CLI
         │
         ▼
Week 4:  Wire Agentic Loop (Proposal 009 Phase 4) ─── Async tools go through queue
         │
         ▼
Week 5:  SSE Hardening (Mitigation 3.4) ─── Timeouts + reconnect
         │
         ▼
Week 6:  Production Docker + Docs (Mitigation 3.3) ─── Self-hosted reference
         │
         ▼
Week 7:  Observability (Proposal 009 Phase 5) ─── Dashboards + health checks
```

---

## 6. Risk Assessment

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Cloudways RabbitMQ not available on all plans | Low (listed as supported) | Medium | Fall back to DB-backed queue; document plan restrictions |
| Redis Object Cache plugin conflict with NV oOS transients | Low | Medium | Test suite covers transient behavior; namespace all keys with `nvoos_` prefix |
| `SKIP LOCKED` not available on older MySQL | None (Cloudways uses MySQL 8.0) | High for self-hosted | Feature-detect; fall back to `FOR UPDATE` without `SKIP LOCKED` |
| SSE reconnect causes chat message duplication | Medium | Medium | `Last-Event-ID` deduplication on server side; idempotency keys on tool calls |
| Queue worker memory leak over long runs | Medium | Low | PHP `memory_limit` watchdog; worker restart after N jobs processed |

---

## 7. Acceptance Criteria

1. **Redis active:** Site Health shows "Redis" as cache backend. Transients stored in Redis, not `wp_options`.
2. **Queue state off wp_options:** `SELECT COUNT(*) FROM wp_mcp_ai_jobs` shows queue items; `wp_options` rows are empty/cleaned up.
3. **Async tool dispatch:** `deep_research` returns `async_job_id` immediately; result arrives via SSE within expected time.
4. **SSE reconnection:** Client reconnects after server-forced close; chat history is continuous.
5. **Health endpoint:** `GET /wp-json/mcp-ai/v1/health` returns 200 with database, Redis, RabbitMQ, and worker status.
6. **Production Docker:** `docker compose -f docker-compose.yml -f docker-compose.prod.yml up` starts WordPress + Redis + RabbitMQ + queue worker.
7. **Cloudways docs:** Step-by-step deployment guide for both Flexible and Autonomous in `docs/operations/cloudways-deployment.md`.

---

## 8. Total Estimated Effort

| Mitigation | Effort |
|---|---|
| Redis Cache + Cache Helper hardening | 2–3 days |
| Custom DB tables for queue storage | 2–3 days |
| SSE hardening (timeouts + reconnect) | 3–4 days |
| Health endpoint + production Docker + docs | 3–5 days |
| **Subtotal (non-RabbitMQ)** | **10–15 days** |
| RabbitMQ integration (Proposal 009) | 10–16 days |
| **Grand Total** | **20–31 working days (4–6 weeks)** |

---

## 9. References

- [Cloudways Server Settings (Redis, RabbitMQ)](https://support.cloudways.com/en/articles/5120689-how-to-manage-your-server-settings)
- [Cloudways Autonomous — Kubernetes WordPress](https://www.cloudways.com/en/autonomous.php)
- [Cloudways — WordPress Scaling Best Practices](https://www.cloudways.com/blog/wordpress-scaling/)
- [WordPress Object Cache / Redis](https://developer.wordpress.org/reference/classes/wp_object_cache/)
- [Action Scheduler — Custom Table Queue Pattern](https://actionscheduler.org/)
- [MySQL 8.0 — `SELECT ... FOR UPDATE SKIP LOCKED`](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html)
- [SSE Specification (RFC 6202 / W3C)](https://html.spec.whatwg.org/multipage/server-sent-events.html)
- [Cloudflare Workers — TransformStream + SSE](https://developers.cloudflare.com/workers/runtime-apis/streams/)
- Related Proposal: [009-rabbitmq-integration-proposal.md](./009-rabbitmq-integration-proposal.md)
