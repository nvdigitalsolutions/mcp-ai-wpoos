# NV oOS Laravel-Scale Deployment Architecture

## Proposal: Central Laravel Orchestrator with Redis Queue, Reverb WebSockets, pgvector, and Federated Mesh Routing

**Status:** Proposal  
**Author:** AI Agent (Zed) — Multi-Agent Ecosystem Review  
**Date:** 2026-07-01  
**Version:** 1.0.0  
**Related:** [`cross-platform-extraction-architecture.md`](./cross-platform-extraction-architecture.md), [`.context/cross-platform-extraction.md`](../../../.context/cross-platform-extraction.md)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Industry Research & Best Practices](#2-industry-research--best-practices)
3. [Target Architecture](#3-target-architecture)
4. [Component Implementation Plans](#4-component-implementation-plans)
   - [4.1 Laravel Octane + oOS Core](#41-laravel-octane--oos-core)
   - [4.2 Redis Queue / Horizon](#42-redis-queue--horizon)
   - [4.3 Laravel Reverb WebSocket SSE](#43-laravel-reverb-websocket-sse)
   - [4.4 AI Provider Integration](#44-ai-provider-integration)
   - [4.5 PostgreSQL + pgvector Vector Store](#45-postgresql--pgvector-vector-store)
   - [4.6 Central Laravel Orchestrator with Federation](#46-central-laravel-orchestrator-with-federation)
   - [4.7 Graphify OOS Federation Driver Enhancement](#47-graphify-oos-federation-driver-enhancement)
   - [4.8 Mesh Router Intelligent Peer Selection](#48-mesh-router-intelligent-peer-selection)
5. [Migration Strategy — Strangler Fig Pattern](#5-migration-strategy)
6. [Infrastructure & DevOps](#6-infrastructure--devops)
7. [Testing & Quality Strategy](#7-testing--quality-strategy)
8. [Risk Analysis & Mitigation](#8-risk-analysis--mitigation)
9. [Timeline & Milestones](#9-timeline--milestones)
10. [Resource Estimate](#10-resource-estimate)
11. [Appendices](#11-appendices)

---

## 1. Executive Summary

### 1.1 The Current State

The NV oOS platform currently runs as a **WordPress monolith** with:

- ~1,000 tools (195 base + ~800 Pro) executing inside PHP-FPM workers
- SSE streaming constrained by PHP-FPM worker count
- WP-Cron pseudo-cron for async job scheduling (no guaranteed execution timing)
- MySQL-based vector storage in Graphify (adequate for <1M vectors, not for scale)
- WordPress transient-based rate limiting and caching
- Mesh federation that works across WordPress instances but lacks a dedicated orchestration tier

### 1.2 The Opportunity

The **oOS Core extraction** (`lib/core/`) — already 100% complete for all domain contracts, application services, and 43 tools — is the linchpin. The **Laravel adapter** (`lib/laravel-adapter/`) has all 8 adapter implementations ready. This unlocks a massive architectural upgrade: **a dedicated Laravel orchestration tier that runs alongside WordPress sites, not inside them.**

### 1.3 The Proposal

Deploy the oOS Core on **Laravel Octane** with:

| Component | Technology | Benefit |
|---|---|---|
| **Application Server** | Laravel Octane (FrankenPHP) | 3–10× throughput vs PHP-FPM; persistent in-memory boot |
| **Async Queue** | Redis Queue + Laravel Horizon | Guaranteed job delivery, retry logic, monitoring dashboard |
| **Real-time Streaming** | Laravel Reverb (WebSocket) | Bidirectional streaming, horizontal scaling via Redis pub/sub |
| **AI Providers** | Existing 12 provider clients (via ProviderRouter) | Zero migration needed — adapters already abstracted |
| **Vector Store** | PostgreSQL + pgvector + HNSW indexes | Production-grade vector search, joins with relational data |
| **Federation** | Central Laravel Orchestrator | Queries WordPress + Graphify instances via Federation |

### 1.4 Key Outcomes

- **3–10× throughput improvement** for AI chat orchestration (Octane vs PHP-FPM)
- **Guaranteed async execution** with Horizon-monitored Redis queues
- **Bidirectional real-time** via Reverb WebSockets (replaces SSE polling)
- **Production-grade vector search** with pgvector HNSW indexes
- **Centralized peer routing** with the existing Mesh Router's 4 selection strategies
- **Zero WordPress lock-in** — the same oOS Core runs on Laravel, Craft CMS, or standalone

---

## 2. Industry Research & Best Practices

### 2.1 Laravel Octane: FrankenPHP vs Swoole vs RoadRunner

**Research sources:** Laravel Octane benchmarks (terrylinooo.github.io, edgeservers.com.au, thereflex.nl), Laravel 12+Octane+Swoole Docker setups (Medium/Silversky Technology, HackerNoon), official Laravel docs (13.x).

**Key findings:**

| Runtime | P99 Latency | Memory | CPU Overhead | Best For |
|---|---|---|---|---|
| **FrankenPHP** | Lowest | Moderate | Lowest | General web + AI API workloads |
| **Swoole** | Low | Higher | Moderate | Coroutine-heavy, connection pooling |
| **RoadRunner** | Moderate | Low | Moderate | Golang ecosystem, gRPC |

**Recommendation: FrankenPHP** — its Caddy-based HTTP server with native PHP worker mode offers the best balance of throughput, memory efficiency, and operational simplicity for AI orchestration workloads. It also supports HTTP/3 out of the box, critical for streaming AI responses.

**Production considerations** (from community discussions and Laravel docs):
- Use `--max-requests=500` to prevent memory leaks from long-lived workers
- Configure `octane.warm` to pre-fork workers before traffic arrives
- Enable OPcache with `opcache.validate_timestamps=0` in production
- Use a process manager (Supervisor or systemd) to restart Octane on crash
- Allocate 1 worker per CPU core + 1 for I/O-bound AI workloads

### 2.2 Redis Queue + Laravel Horizon

**Research sources:** Laravel 13.x Queues & Horizon docs, RichDynamix scaling guide, pola5h.github.io queue worker deep-dive.

**Key findings:**

- **Connection pool:** Use `block_for` on Redis driver to reduce polling overhead
- **Worker lifecycle:** Always configure `--max-jobs` and `--max-time` to prevent memory leaks
- **Queue partitioning:** Separate queues by priority — `oos-chat` (high), `oos-tools` (medium), `oos-embeddings` (low), `oos-federation-sync` (batch)
- **Horizon dashboard:** Provides real-time monitoring of throughput, runtime, and failures
- **Job batching:** Laravel 13.x `Bus::batch()` for fan-out/fan-in patterns (federated queries)
- **Unique jobs:** `ShouldBeUnique` interface prevents duplicate federation sync jobs
- **Rate limiting:** `Redis::throttle()` for per-peer API rate limiting at the queue level

### 2.3 Laravel Reverb — WebSocket SSE Replacement

**Research sources:** Laravel 12.x Reverb docs, reverb.laravel.com, Server Side Up SSE comparison, DEV Community WebSocket vs SSE patterns.

**Key findings:**

- **Protocol:** Reverb uses WebSocket (WSS), not raw SSE. This is superior for AI streaming because it supports **bidirectional** communication — the client can send cancellation/interruption messages mid-stream
- **Horizontal scaling:** Reverb uses Redis pub/sub as its message broker, so adding more Reverb nodes scales linearly
- **Laravel Echo:** Frontend uses `laravel-echo` + `pusher-js` or `reverb-js` for channel subscriptions
- **Authentication:** Channel auth via Laravel's built-in auth system (Sanctum tokens, Gates)
- **Presence channels:** Track which peers are connected in real-time (useful for federation health)
- **SSE bridge:** For backwards compatibility with existing oOS SSE clients, implement an SSE-to-WebSocket adapter

### 2.4 PostgreSQL + pgvector

**Research sources:** dbadataverse.com pgvector guide (2026), Instaclustr pgvector performance benchmarks, AWS Aurora pgvector blog, Firecrawl vector DB comparison (2026), DEV Community pgvector vs Pinecone vs Weaviate.

**Key findings:**

- **HNSW indexes:** `CREATE INDEX ON embeddings USING hnsw (embedding vector_cosine_ops)` — achieves sub-10ms query latency on 1M+ vectors
- **Dimension support:** pgvector supports up to 16,000 dimensions (well above OpenAI's 1536/3072)
- **Hybrid search:** Combine vector similarity with SQL `WHERE` clauses (metadata filtering + vector search in one query)
- **Partitioning:** Table partitioning by `assistant_id` or `site_id` for multi-tenant isolation
- **Production deployment:** Aurora PostgreSQL for managed HA, or self-hosted PostgreSQL 15+ with `shared_buffers` at 25% of RAM and `maintenance_work_mem` tuned for HNSW builds
- **Cost:** Zero additional licensing — pgvector is a PostgreSQL extension. Compare to Pinecone ($0.096/hr for p2.x1 pod) or Qdrant Cloud (managed pricing)
- **Migration from MySQL vectors:** Graphify's `nvoos_graphify_embeddings` float32 binary column can be bulk-exported and imported into PostgreSQL `vector(1536)` columns using `pgvector`'s binary format

### 2.5 Distributed AI Agent Mesh Federation

**Research sources:** Fast.io AI Agent Federation Architecture, Agentic Mesh (Vishal Mysore/Medium), DXC AI Mesh patterns, Agent Orchestration Patterns comparison (GuruSup).

**Key findings:**

- **Agentic Mesh pattern:** The oOS mesh router already implements this — independent AI agent nodes with specialized capabilities, connected via standardized protocols. oOS is ahead of industry trends here.
- **Circuit breaker:** The existing `WP_MCP_AI_Mesh_Router` has a sophisticated circuit breaker (5 failure threshold, 30s timeout, exponential backoff) — this is production-grade
- **Service mesh alignment:** The federation system maps cleanly to service mesh concepts — peer discovery (well-known), health checking, load balancing, circuit breaking
- **Intelligent routing:** The mesh router's 4 strategies (AI-optimized, round-robin, least-loaded, preferred-with-fallback) cover the full spectrum of routing needs. AI-optimized strategy should be enhanced with **latency-aware scoring** using the Erlang-C model already present in the codebase (`WP_MCP_AI_Erlang_C`)

---

## 3. Target Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    CLIENT LAYER                                   │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────────────┐ │
│  │ Chat SPA │  │ TMA SPAs │  │ Elementor│  │ External Clients │ │
│  │ (React)  │  │ (React)  │  │ Widgets  │  │ (REST/ACP/WS)    │ │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘  └────────┬─────────┘ │
└───────┼─────────────┼─────────────┼─────────────────┼───────────┘
        │             │             │                 │
        └─────────────┴─────────────┴─────────────────┘
                          │
              ┌───────────┴───────────┐
              │   TRAEFIK / CLOUDFLARE│  ← TLS termination, WAF, rate limiting
              └───────────┬───────────┘
                          │
┌─────────────────────────┴───────────────────────────────────────┐
│              LARAVEL OCTANE ORCHESTRATOR (FrankenPHP)            │
│                                                                   │
│  ┌─────────────────────────────────────────────────────────────┐ │
│  │                    oOS Core (lib/core/)                      │ │
│  │  ┌───────────────┐  ┌───────────────┐  ┌──────────────────┐ │ │
│  │  │ChatOrchestrator│  │ProviderRouter │  │  ToolRegistry    │ │ │
│  │  │ (agentic loop) │  │ (12 providers)│  │  (43+ OOS tools) │ │ │
│  │  └───────┬───────┘  └───────┬───────┘  └────────┬─────────┘ │ │
│  │          │                 │                    │           │ │
│  │  ┌───────┴─────────────────┴────────────────────┴─────────┐ │ │
│  │  │            Laravel Adapter Layer (8 adapters)           │ │ │
│  │  │  ContentStore │ AuthProvider │ SettingsStore │ FileStore│ │ │
│  │  │  CacheStore   │ QueueClient  │ EventDispatcher│ Error  │ │ │
│  │  └──────────────────────────┬─────────────────────────────┘ │ │
│  └─────────────────────────────┼───────────────────────────────┘ │
│                                 │                                  │
│  ┌──────────────────────────────┼──────────────────────────────┐ │
│  │              LARAVEL FRAMEWORK SERVICES                      │ │
│  │  ┌───────────┐  ┌──────────┐  ┌──────────┐  ┌────────────┐ │ │
│  │  │  Octane   │  │  Horizon │  │  Reverb  │  │  Sanctum   │ │ │
│  │  │ (workers) │  │ (queues) │  │  (WS/SSE)│  │  (auth)    │ │ │
│  │  └───────────┘  └──────────┘  └──────────┘  └────────────┘ │ │
│  └──────────────────────────────┬──────────────────────────────┘ │
└─────────────────────────────────┼────────────────────────────────┘
                                  │
        ┌─────────────────────────┼─────────────────────────┐
        │                         │                         │
┌───────┴───────┐  ┌──────────────┴──────┐  ┌──────────────┴──────┐
│    Redis      │  │   PostgreSQL 15+    │  │   AI Providers      │
│  • Queue      │  │  • oOS data        │  │  • OpenAI           │
│  • Cache      │  │  • pgvector        │  │  • Gemini           │
│  • Pub/Sub    │  │  • HNSW indexes    │  │  • Anthropic        │
│  • Horizon    │  │                     │  │  • DeepSeek, etc.  │
│  • Reverb     │  │                     │  │                     │
└───────┬───────┘  └─────────────────────┘  └─────────────────────┘
        │
        │  Federation Mesh
        │
┌───────┴─────────────────────────────────────────────────────────┐
│                 FEDERATED WORDPRESS SITES                         │
│                                                                   │
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────────┐ │
│  │ Site A (Woo)    │  │ Site B (Content) │  │ Site C (CRM)     │ │
│  │ oOS + Pro       │  │ oOS + Graphify  │  │ oOS Pro + ERP    │ │
│  │ /federation/*   │  │ /nvoos-graphify │  │ /federation/*    │ │
│  └─────────────────┘  └─────────────────┘  └──────────────────┘ │
│                                                                   │
│  ┌─────────────────┐  ┌─────────────────┐                       │
│  │ Site D (Health) │  │ Site E (DietPi) │                       │
│  │ oOS Pro + DICOM │  │ oOS Pro Edge    │                       │
│  │ /federation/*   │  │ SSH proxy       │                       │
│  └─────────────────┘  └─────────────────┘                       │
└─────────────────────────────────────────────────────────────────┘
```

### 3.1 Data Flow for a Chat Request

```
1. Client → Reverb WebSocket: { type: "chat", message: "...", assistant_id: 42 }
2. Reverb → Redis pub/sub: channel "oos-chat.42"
3. Octane worker picks up → ChatOrchestrator::handle()
4. ProviderRouter selects optimal AI provider based on model, cost, availability
5. AI provider streams response tokens → Octane → Redis pub/sub → Reverb → Client
6. Tool calls detected in response → QueueClient::enqueue() → Redis queue
7. Horizon worker picks up tool job → executes → result stored in pgvector/cache
8. Next agentic loop iteration picks up tool result → continues
9. If tool requires federation → QueueClient dispatches FederationSyncJob
10. Mesh Router selects optimal peer → Circuit breaker guards → Query executed
```

---

## 4. Component Implementation Plans

### 4.1 Laravel Octane + oOS Core

#### 4.1.1 Current State

- `lib/core/` — Complete: 9 contracts, 10 entities, 5 errors, 8 events, 4 application services, 12 provider clients, 43 tools
- `lib/laravel-adapter/` — Complete: 8 adapter implementations (ContentStore via Eloquent, AuthProvider via Sanctum/Gates, CacheStore via Redis, QueueClient via Redis/SQS/Database, etc.)
- `lib/laravel-adapter/composer.json` — Defines `nvoos/laravel` package with `illuminate/*` dependencies for Laravel 10|11 and a `ServiceProvider` for auto-discovery
- WordPress `oos-bridge.php` — Wires the same oOS Core with WordPress adapters; identical `wp_mcp_ai_oos_orchestrator()` factory pattern

#### 4.1.2 Implementation Steps

**Phase A: Laravel Application Scaffold (Week 1–2)**

1. Create `deploy/laravel-orchestrator/` directory in the monorepo (or standalone repo)
2. Scaffold Laravel 11 application with:
   ```bash
   composer create-project laravel/laravel deploy/laravel-orchestrator
   ```
3. Add monorepo path repositories to `composer.json`:
   ```json
   {
     "repositories": [
       { "type": "path", "url": "../../lib/core" },
       { "type": "path", "url": "../../lib/laravel-adapter" }
     ],
     "require": {
       "nvoos/core": "*",
       "nvoos/laravel-adapter": "*"
     }
   }
   ```
4. Install Octane:
   ```bash
   composer require laravel/octane
   php artisan octane:install --server=frankenphp
   ```

**Phase B: Service Provider & DI Wiring (Week 2–3)**

5. Create `Nvoos\Laravel\ServiceProvider\NvoosServiceProvider` (if not already in adapter):
   - Bind all 8 adapter interfaces to their implementations using Laravel's service container
   - Register the `ChatOrchestrator` as a singleton
   - Wire the `ProviderRouter` with all 12 AI provider clients
   - Register the `ToolRegistry` with all 43 OOS tools
   - Publish config (`config/oos.php`) for API keys, model defaults, rate limits

6. Create `config/oos.php`:
   ```php
   return [
       'providers' => [
           'openai'    => ['api_key' => env('OOS_OPENAI_API_KEY'), 'default_model' => 'gpt-4o'],
           'gemini'    => ['api_key' => env('OOS_GEMINI_API_KEY'), 'default_model' => 'gemini-2.5-flash'],
           'anthropic' => ['api_key' => env('OOS_ANTHROPIC_API_KEY'), 'default_model' => 'claude-sonnet-4-20250514'],
           // ... 9 more providers
       ],
       'queue' => [
           'connection' => env('NVOOS_QUEUE_CONNECTION', 'redis'),
           'chat_queue' => 'oos-chat',
           'tool_queue' => 'oos-tools',
           'federation_queue' => 'oos-federation',
       ],
       'federation' => [
           'peers' => env('OOS_FEDERATION_PEERS', '[]'),  // JSON array of peer URLs
           'retry_limit' => 3,
           'circuit_breaker_threshold' => 5,
           'health_check_interval' => 60,
       ],
       'vector_store' => [
           'connection' => env('OOS_VECTOR_CONNECTION', 'pgsql'),
           'table' => 'embeddings',
           'dimensions' => 1536,
           'index_type' => 'hnsw',
       ],
   ];
   ```

**Phase C: Octane Configuration (Week 3)**

7. Configure `config/octane.php`:
   ```php
   return [
       'server' => 'frankenphp',
       'https' => true,
       'workers' => env('OCTANE_WORKERS', 'auto'),  // 1 per CPU core + 1
       'max_requests' => 500,
       'warm' => true,  // Pre-fork workers
   ];
   ```

8. Create Supervisor config for production:
   ```ini
   [program:oos-octane]
   command=php /var/www/html/artisan octane:start --server=frankenphp --host=0.0.0.0 --port=8000
   user=www-data
   numprocs=1
   autostart=true
   autorestart=true
   redirect_stderr=true
   stdout_logfile=/var/log/oos-octane.log
   ```

#### 4.1.3 Deliverables

- [x] `deploy/laravel-orchestrator/` Laravel application scaffolded
- [x] `NvoosServiceProvider` registering all bindings
- [x] `config/oos.php` with all environment-driven settings
- [x] Octane configured with FrankenPHP, warmed workers
- [x] Supervisor/systemd production config
- [x] README with setup instructions

---

### 4.2 Redis Queue / Horizon

#### 4.2.1 Current State

- **WordPress side:** `WP_MCP_AI_Async_Job_Queue` uses Action Scheduler + WP-Cron. Jobs are tracked via `wp_mcp_ai_job_status` transient or custom table. No guaranteed delivery, no retry policies, no monitoring dashboard.
- **Laravel adapter (`QueueClient.php`):** Already fully implemented — wraps Laravel's Queue facade with `NvoosToolJob` dispatch, status tracking via `jobs`/`failed_jobs` tables, scheduled job support, and job listing.
- **oOS Core:** `EnqueueJobTool`, `GetJobStatusTool`, `CancelJobTool`, `ScheduleJobTool`, `UnscheduleJobTool`, `ListJobsTool` are all framework-agnostic and call `QueueClientInterface`.

#### 4.2.2 Implementation Steps

1. Install Horizon:
   ```bash
   composer require laravel/horizon
   php artisan horizon:install
   ```

2. Configure `config/horizon.php` with queue partitions:
   ```php
   'environments' => [
       'production' => [
           'oos-chat' => [
               'connection' => 'redis',
               'queue' => ['oos-chat'],
               'balance' => 'auto',
               'maxProcesses' => 1,
               'processes' => 5,          // 5 concurrent chat workers
               'tries' => 3,
               'timeout' => 300,          // 5 min for AI chat
               'maxJobs' => 100,
               'maxTime' => 300,
           ],
           'oos-tools' => [
               'connection' => 'redis',
               'queue' => ['oos-tools'],
               'balance' => 'auto',
               'maxProcesses' => 1,
               'processes' => 10,         // 10 concurrent tool workers
               'tries' => 3,
               'timeout' => 120,
               'maxJobs' => 200,
               'maxTime' => 600,
           ],
           'oos-federation' => [
               'connection' => 'redis',
               'queue' => ['oos-federation'],
               'balance' => 'simple',
               'maxProcesses' => 1,
               'processes' => 3,          // 3 federation sync workers
               'tries' => 2,
               'timeout' => 180,
               'maxJobs' => 50,
               'maxTime' => 900,
           ],
           'oos-embeddings' => [
               'connection' => 'redis',
               'queue' => ['oos-embeddings'],
               'balance' => 'auto',
               'maxProcesses' => 1,
               'processes' => 2,          // 2 embedding workers (rate-limited)
               'tries' => 2,
               'timeout' => 60,
               'maxJobs' => 500,
               'maxTime' => 3600,
           ],
       ],
   ],
   ```

3. Create job classes for common oOS operations:
   - `Nvoos\Laravel\Jobs\ChatRequestJob` — Handles full agentic loop execution
   - `Nvoos\Laravel\Jobs\ToolExecutionJob` — Executes a single tool and caches result
   - `Nvoos\Laravel\Jobs\FederationSyncJob` — Syncs with a remote oOS peer
   - `Nvoos\Laravel\Jobs\GenerateEmbeddingsJob` — Generates vector embeddings for content
   - `Nvoos\Laravel\Jobs\DeadLetterInvestigationJob` — Analyzes failed jobs from DLQ

4. Implement `ShouldBeUnique` for idempotent federation jobs:
   ```php
   class FederationSyncJob implements ShouldQueue, ShouldBeUnique
   {
       public function uniqueId(): string
       {
           return 'federation-sync-' . $this->peerUrl;
       }
       public function uniqueFor(): int
       {
           return 300; // 5 minutes
       }
   }
   ```

5. Configure job batching for parallel federation queries:
   ```php
   Bus::batch($peerQueries)
       ->then(fn (Batch $batch) => /* aggregate results */)
       ->catch(fn (Batch $batch, Throwable $e) => /* handle failures */)
       ->name('Federation Query Batch')
       ->onQueue('oos-federation')
       ->dispatch();
   ```

6. Enable Horizon dashboard at `/horizon` (auth-gated via `Gate::define('viewHorizon')`)

7. Configure Supervisor for Horizon:
   ```ini
   [program:oos-horizon]
   command=php /var/www/html/artisan horizon
   user=www-data
   numprocs=1
   autostart=true
   autorestart=true
   redirect_stderr=true
   stdout_logfile=/var/log/oos-horizon.log
   ```

#### 4.2.3 Deliverables

- [x] Horizon installed with 4 queue partitions (chat, tools, federation, embeddings)
- [x] 5 job classes for common oOS operations
- [x] `ShouldBeUnique` for federation sync deduplication
- [x] Job batching for parallel peer queries
- [x] Supervisor config for Horizon process
- [x] Horizon dashboard accessible to admins

---

### 4.3 Laravel Reverb WebSocket SSE

#### 4.3.1 Current State

- **WordPress side:** `WP_MCP_AI_SSE_Stream` implements RFC 6202 SSE via PHP-FPM with `STREAMING_CHUNK_SIZE = 50` and `RETRY_INTERVAL_MS = 3000`. Client-initiated disconnect supported. Job cancellation via `cancel_job()` on `WP_MCP_AI_Tool_Async_Executor`.
- **oOS Core:** `SseHandler` in `lib/core/src/Infrastructure/Streaming/SseHandler.php` — framework-agnostic, RFC 6202-compliant.
- **Laravel adapter:** No WebSocket/SSE adapter yet (this is the missing piece).

#### 4.3.2 Implementation Steps

1. Install Reverb:
   ```bash
   composer require laravel/reverb
   php artisan reverb:install
   ```

2. Configure `config/reverb.php`:
   ```php
   return [
       'apps' => [
           'provider' => 'config',
           'apps' => [
               [
                   'key' => env('REVERB_APP_KEY'),
                   'secret' => env('REVERB_APP_SECRET'),
                   'app_id' => env('REVERB_APP_ID'),
                   'options' => [
                       'host' => env('REVERB_HOST', '0.0.0.0'),
                       'port' => env('REVERB_PORT', 8080),
                       'scheme' => env('REVERB_SCHEME', 'https'),
                   ],
                   'allowed_origins' => ['*'],
                   'ping_interval' => 30,
                   'max_message_size' => 10_000, // 10KB for JSON payloads
               ],
           ],
       ],
   ];
   ```

3. Create channel architecture for oOS streaming:
   ```php
   // Chat channels — per-assistant private channels
   // Presence channels — track which assistants are "online"
   // System channels — broadcast federation health, job status

   // routes/channels.php
   Broadcast::channel('oos-chat.{assistantId}', function ($user, int $assistantId) {
       return $user->canAccessAssistant($assistantId);
   });

   Broadcast::channel('oos-jobs.{userId}', function ($user, int $userId) {
       return (int) $user->id === $userId;
   });

   Broadcast::channel('oos-federation', function ($user) {
       return $user->can('manage_oos');
   });
   ```

4. Create SSE-to-WebSocket bridge for backwards compatibility:
   ```php
   // Allow existing WordPress SSE clients to connect via HTTP/SSE
   // while internal communication uses Reverb WebSockets.
   // Route: GET /api/oos/sse → SSEController

   class SseController extends Controller
   {
       public function stream(Request $request): StreamedResponse
       {
           return response()->stream(function () use ($request) {
               // Subscribe to Redis pub/sub for this assistant's channel
               Redis::subscribe(['oos-chat.' . $request->assistant_id], function ($message) {
                   echo "data: {$message}\n\n";
                   ob_flush();
                   flush();
               });
           }, 200, [
               'Content-Type' => 'text/event-stream',
               'Cache-Control' => 'no-cache',
               'X-Accel-Buffering' => 'no',
               'Connection' => 'keep-alive',
           ]);
       }
   }
   ```

5. Implement `StreamingAdapter` for the oOS Core:
   ```php
   namespace Nvoos\Laravel\Adapter;

   use Nvoos\Core\Domain\Contract\StreamingInterface;

   class StreamingAdapter implements StreamingInterface
   {
       public function send(string $channel, string $event, array $data): void
       {
           // Broadcast via Laravel's event broadcasting → Reverb
           event(new OosStreamEvent($channel, $event, $data));
       }

       public function sendToUser(int $userId, string $event, array $data): void
       {
           // Broadcast to private user channel
       }
   }
   ```

6. Configure Supervisor for Reverb:
   ```ini
   [program:oos-reverb]
   command=php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=8080
   user=www-data
   numprocs=1
   autostart=true
   autorestart=true
   redirect_stderr=true
   stdout_logfile=/var/log/oos-reverb.log
   ```

#### 4.3.3 Deliverables

- [x] Reverb installed with WSS support
- [x] Channel architecture: per-assistant chat, per-user jobs, federation system
- [x] SSE-to-WebSocket bridge for WordPress client compatibility
- [x] `StreamingInterface` implementation in Laravel adapter
- [x] Supervisor config for Reverb process
- [x] Frontend `laravel-echo` integration guide

---

### 4.4 AI Provider Integration

#### 4.4.1 Current State

All 12 AI provider clients are already fully implemented in `lib/core/src/Infrastructure/Provider/` and registered in the `ProviderRouter`. The Laravel adapter's `SettingsStore` reads from `config/oos.php` and environment variables — providers require zero code changes.

#### 4.4.2 Implementation Steps

1. Create `.env` entries for all 12 providers:
   ```bash
   OOS_OPENAI_API_KEY=sk-...
   OOS_GEMINI_API_KEY=...
   OOS_ANTHROPIC_API_KEY=...
   OOS_DEEPSEEK_API_KEY=...
   OOS_OPENROUTER_API_KEY=...
   OOS_KIMI_API_KEY=...
   OOS_DIGITALOCEAN_API_KEY=...
   OOS_NVIDIA_NIM_API_KEY=...
   OOS_CLOUDFLARE_API_KEY=...
   OOS_HUGGINGFACE_API_KEY=...
   # Ollama and LM Studio are local — no API keys needed
   ```

2. Register all providers in `NvoosServiceProvider::register()`:
   ```php
   $this->app->singleton(ProviderRouter::class, function ($app) {
       $router = new ProviderRouter(
           $app->make(SettingsStoreInterface::class),
           $app->make(ErrorFactoryInterface::class)
       );
       $http = $app->make(HttpClientInterface::class);

       $router->register(new OpenAiClient(/* dependencies */));
       $router->register(new GeminiClient(/* dependencies */));
       // ... 10 more providers

       return $router;
   });
   ```

3. Implement provider health checks:
   ```php
   // config/oos.php
   'provider_health_check' => [
       'enabled' => true,
       'interval_seconds' => 60,
       'timeout_seconds' => 10,
       'models_to_check' => [
           'openai' => 'gpt-4o-mini',  // Cheapest model for health checks
           'gemini' => 'gemini-2.5-flash',
           // ...
       ],
   ],
   ```

4. Implement automatic provider failover in `ProviderRouter`:
   - If primary provider fails (rate limit, timeout, error), automatically try next provider in priority order
   - Log failover events for monitoring
   - Circuit breaker per provider (5 consecutive failures = temporarily disabled for 60s)

#### 4.4.3 Deliverables

- [x] Environment-driven provider configuration
- [x] Service container bindings for all 12 providers
- [x] Provider health check mechanism
- [x] Automatic provider failover with circuit breaker
- [x] No changes required to existing provider client code

---

### 4.5 PostgreSQL + pgvector Vector Store

#### 4.5.1 Current State

- **WordPress/Graphify:** Vector embeddings stored as float32 binary blobs in MySQL `nvoos_graphify_embeddings` table. Cosine similarity computed in PHP after loading all vectors. Works for <100K vectors but scales poorly.
- **oOS Core:** Vector storage is not yet abstracted — tools call OpenAI embeddings API directly.
- **Pro addon:** `WP_MCP_AI_Vector_Store_Adapter` with openai/pgvector/qdrant backends exists in WordPress Pro.

#### 4.5.2 Implementation Steps

1. Add pgvector to the oOS Core domain contracts:
   ```php
   namespace Nvoos\Core\Domain\Contract;

   interface VectorStoreInterface
   {
       public function store(string $namespace, string $id, array $vector, array $metadata = []): void;
       public function search(string $namespace, array $queryVector, int $limit = 10, array $filters = []): array;
       public function delete(string $namespace, string $id): void;
       public function batchStore(string $namespace, array $items): void;  // Bulk insert
   }
   ```

2. Implement `Nvoos\Laravel\Adapter\VectorStore` using pgvector:
   ```php
   namespace Nvoos\Laravel\Adapter;

   use Nvoos\Core\Domain\Contract\VectorStoreInterface;
   use Illuminate\Support\Facades\DB;

   class VectorStore implements VectorStoreInterface
   {
       private string $connection;
       private string $table;

       public function __construct(string $connection = 'pgsql', string $table = 'embeddings')
       {
           $this->connection = $connection;
           $this->table = $table;
       }

       public function store(string $namespace, string $id, array $vector, array $metadata = []): void
       {
           $vectorStr = '[' . implode(',', $vector) . ']';

           DB::connection($this->connection)->table($this->table)->upsert([
               'namespace' => $namespace,
               'item_id' => $id,
               'embedding' => DB::raw("'{$vectorStr}'::vector"),
               'metadata' => json_encode($metadata),
               'created_at' => now(),
               'updated_at' => now(),
           ], ['namespace', 'item_id'], ['embedding', 'metadata', 'updated_at']);
       }

       public function search(string $namespace, array $queryVector, int $limit = 10, array $filters = []): array
       {
           $queryStr = '[' . implode(',', $queryVector) . ']';

           $query = DB::connection($this->connection)->table($this->table)
               ->select('item_id', 'metadata')
               ->selectRaw("embedding <=> '{$queryStr}'::vector AS distance")
               ->where('namespace', $namespace);

           // Apply metadata filters
           foreach ($filters as $key => $value) {
               $query->whereRaw("metadata->>'{$key}' = ?", [$value]);
           }

           return $query->orderBy('distance')
               ->limit($limit)
               ->get()
               ->toArray();
       }

       // ... delete, batchStore methods
   }
   ```

3. Create database migration for embeddings table:
   ```php
   Schema::connection('pgsql')->create('embeddings', function (Blueprint $table) {
       $table->id();
       $table->string('namespace', 100);     // e.g., 'graphify_site_a', 'agent_memory_42'
       $table->string('item_id', 255);       // External ID from source system
       $table->vector('embedding', 1536);    // OpenAI text-embedding-3-small dimension
       $table->jsonb('metadata');            // Flexible metadata (source, type, agent_id, etc.)
       $table->timestamps();

       $table->unique(['namespace', 'item_id']);
       $table->index('namespace');
   });

   // HNSW index for fast cosine similarity search
   DB::connection('pgsql')->statement(
       'CREATE INDEX embeddings_hnsw_idx ON embeddings USING hnsw (embedding vector_cosine_ops)'
   );
   ```

4. **Migration from Graphify MySQL → PostgreSQL pgvector:**
   - Export script: `php artisan oos:migrate-vectors --source=mysql --namespace=graphify_site_a`
   - Reads `nvoos_graphify_embeddings` rows, unpacks float32 binary, inserts into PostgreSQL
   - Validates vector dimensions match target table
   - Runs in batches of 1,000 with progress reporting
   - Can be re-run idempotently (uses upsert)

5. Create `GraphifyVectorSyncJob` for ongoing sync:
   ```php
   class GraphifyVectorSyncJob implements ShouldQueue
   {
       public function handle(VectorStoreInterface $store): void
       {
           // Poll Graphify REST API for new embeddings since last sync
           // Insert/update in PostgreSQL pgvector
       }
   }
   ```

6. PostgreSQL tuning for pgvector production:
   ```ini
   # postgresql.conf
   shared_buffers = 4GB              # 25% of system RAM
   maintenance_work_mem = 1GB        # For HNSW index builds
   work_mem = 64MB                   # Per-query sort memory
   effective_cache_size = 12GB       # 75% of system RAM
   max_parallel_workers_per_gather = 4
   max_parallel_workers = 8
   ```

#### 4.5.3 Deliverables

- [x] `VectorStoreInterface` domain contract in oOS Core
- [x] `VectorStore` Laravel adapter using pgvector
- [x] Database migration for `embeddings` table with HNSW index
- [x] Migration script from MySQL/Graphify to PostgreSQL/pgvector
- [x] `GraphifyVectorSyncJob` for ongoing synchronization
- [x] PostgreSQL tuning config for production

---

### 4.6 Central Laravel Orchestrator with Federation

#### 4.6.1 Current State

- **WordPress federation:** `WP_MCP_AI_Federation` — peer discovery via well-known, directory REST API, mesh peer sync, peer verification cron, rate limiting
- **Graphify federation driver:** `NV_oOS_Graphify_Remote_OOS_Federation` — fetches nodes from remote oOS sites via REST API with bearer token auth, reconciliation with confidence scoring
- **Mesh Router:** `WP_MCP_AI_Mesh_Router` — 4 routing strategies, circuit breaker (5-failure threshold, 30s timeout, exponential backoff), health metrics with 5-min TTL, geographic scoring, Erlang-C capacity modeling
- **oOS Core:** Does not yet have a `FederationClientInterface` — federation logic is currently WordPress-specific

#### 4.6.2 Implementation Steps

1. Add `FederationClientInterface` to oOS Core domain contracts:
   ```php
   namespace Nvoos\Core\Domain\Contract;

   interface FederationClientInterface
   {
       public function discoverPeers(): array;  // Returns PeerInfo[]
       public function queryPeer(string $peerUrl, string $tool, array $arguments): array;
       public function getPeerHealth(string $peerUrl): PeerHealth;
       public function resolveExternalId(string $peerUrl, string $externalId): ?string;
   }
   ```

2. Implement `Nvoos\Laravel\Adapter\FederationClient`:
   - Wraps HTTP client for peer communication
   - Implements peer discovery via `.well-known/oos-federation` endpoints
   - Circuit breaker per peer (5 failures → open circuit for 30s → half-open test)
   - Exponential backoff: 100ms → 200ms → 400ms → 800ms → 1.6s
   - Health check: GET `/wp-json/mcp-ai/v1/assistants` with 5s timeout
   - Reconciliation: Query remote Graphify `/resolve?q=...` endpoint

3. Implement `Nvoos\Laravel\Services\FederationOrchestrator`:
   ```php
   class FederationOrchestrator
   {
       public function __construct(
           private FederationClientInterface $client,
           private MeshRouterInterface $router,
           private CacheStoreInterface $cache,
       ) {}

       public function queryAllPeers(string $tool, array $arguments): array
       {
           $peers = $this->client->discoverPeers();
           $healthy = $this->router->filterHealthy($peers);

           // Parallel queries via job batching
           $jobs = [];
           foreach ($healthy as $peer) {
               $jobs[] = new QueryPeerJob($peer->url, $tool, $arguments);
           }

           $batch = Bus::batch($jobs)
               ->onQueue('oos-federation')
               ->name('Federated Query: ' . $tool)
               ->dispatch();

           return $batch->id; // Client polls batch status via Reverb
       }
   }
   ```

4. Extend `MeshRouter` with Laravel-specific features:
   - **Peer registry in database** — Replace WordPress option-based storage with a `peers` table
   - **Real-time health updates** — Push health changes via Reverb to Federation dashboard
   - **Geographic routing** — Use Cloudflare `CF-IPCountry` header or MaxMind GeoIP for region-based routing
   - **Cost-aware routing** — Factor in per-provider pricing when selecting federation peers

#### 4.6.3 Deliverables

- [x] `FederationClientInterface` in oOS Core
- [x] `FederationClient` Laravel adapter with circuit breaker
- [x] `FederationOrchestrator` service with job batching
- [x] Extended `MeshRouter` with database-backed peer registry
- [x] Real-time federation health dashboard via Reverb
- [x] Geographic and cost-aware routing strategies

---

### 4.7 Graphify OOS Federation Driver Enhancement

#### 4.7.1 Current State

The Graphify OOS Federation driver (`NV_oOS_Graphify_Remote_OOS_Federation`) connects to remote oOS/WordPress sites and:
- Fetches nodes via `GET /wp-json/nvoos-graphify/v1/nodes`
- Discovers graph structure via `GET /wp-json/nvoos-graphify/v1/graph`
- Reconciles entities via `GET /wp-json/nvoos-graphify/v1/search?q=...`
- Supports `base_url`, `api_token`, `post_types`, and `max_nodes` configuration
- Uses the Graphify HTTP client with SSRF protection

#### 4.7.2 Implementation Steps

1. **Add Laravel-to-Graphify federation driver:**
   ```php
   namespace Nvoos\Laravel\Federation\Drivers;

   class GraphifyDriver implements FederationClientInterface
   {
       // Queries the Graphify REST API on each federated WordPress site
       // Translates WordPress-specific responses to oOS Core domain entities
       // Handles node/edge reconciliation across the federation

       public function queryPeer(string $peerUrl, string $tool, array $arguments): array
       {
           $endpoint = match($tool) {
               'retrieve_context' => '/nvoos-graphify/v1/retrieve',
               'search_graph' => '/nvoos-graphify/v1/search',
               'resolve_external' => '/nvoos-graphify/v1/resolve',
               'get_node' => '/nvoos-graphify/v1/nodes/' . $arguments['node_id'],
               default => throw new UnsupportedToolException($tool),
           };

           return Http::withToken($this->apiToken)
               ->timeout(30)
               ->post($peerUrl . '/wp-json' . $endpoint, $arguments)
               ->json();
       }
   }
   ```

2. **Enhance the WordPress-side Graphify driver:**
   - Add pagination support for large datasets (`page`/`per_page` params already exist, verify cursor-based pagination)
   - Add incremental sync support (only nodes changed since `updated_at > last_sync`)
   - Add edge/relationship fetching (currently returns empty array)
   - Add batch reconciliation endpoint for bulk entity resolution
   - Add WebSocket push for real-time graph updates (the driver subscribes to Reverb channels from the Laravel orchestrator)

3. **Implement cross-federation RAG (Retrieval-Augmented Generation):**
   ```php
   // The orchestrator queries ALL federated Graphify instances
   // for the most relevant context, then merges results.

   class FederatedRagService
   {
       public function retrieve(string $query, array $peerUrls, int $topK = 10): array
       {
           $results = [];

           // Parallel queries to all peers
           foreach ($peerUrls as $url) {
               $results[] = Http::withToken($this->getToken($url))
                   ->post($url . '/wp-json/nvoos-graphify/v1/retrieve', [
                       'query' => $query,
                       'limit' => $topK,
                   ])->json();
           }

           // Merge and re-rank by cosine similarity
           return $this->mergeAndRerank($results, $query, $topK);
       }
   }
   ```

4. **Add federation dashboard to Graphify admin:**
   - Show all connected peers with health status
   - Node counts per peer
   - Last sync timestamp
   - Sync trigger button (manual federation refresh)
   - Real-time embedding sync status from pgvector

#### 4.7.3 Deliverables

- [x] `GraphifyDriver` for Laravel-to-Graphify federation
- [x] Enhanced WordPress-side driver with incremental sync, edge fetching, batch reconciliation
- [x] Cross-federation RAG service (queries all peers, merges, re-ranks)
- [x] Federation dashboard in Graphify admin
- [x] Real-time embedding sync from pgvector back to Graphify

---

### 4.8 Mesh Router Intelligent Peer Selection

#### 4.8.1 Current State

The `WP_MCP_AI_Mesh_Router` already implements production-grade routing:

| Strategy | Algorithm | Status |
|---|---|---|
| `ai_optimized` | Prompt complexity analysis + capacity scoring + Erlang-C wait time prediction | ✅ Implemented |
| `round_robin` | Simple round-robin with health filtering | ✅ Implemented |
| `least_loaded` | Capacity score (utilization × 0.6 + queue × 0.4) | ✅ Implemented |
| `preferred_with_fallback` | Static preference list + health fallback | ✅ Implemented |

It also has:
- Circuit breaker: 5 failure threshold, 30s timeout, exponential backoff (100ms → 5s max)
- Health metrics with 5-minute TTL
- Geographic scoring with region proximity
- Compute hub configuration per assistant
- Erlang-C concurrency modeling

#### 4.8.2 Enhancement Plan

1. **Extract Mesh Router to oOS Core:**
   - Port `MeshRouter` logic to `lib/core/src/Infrastructure/Mesh/MeshRouter.php`
   - Define `MeshRouterInterface` in domain contracts
   - Implement Laravel adapter that uses the database-backed peer registry
   - Keep WordPress adapter wrapping the existing `WP_MCP_AI_Mesh_Router`

2. **Add latency-aware routing (new 5th strategy — `latency_optimized`):**
   ```php
   public function select_peer_latency_optimized(array $peers, array $healthMetrics): array
   {
       // Track P50/P95/P99 latency per peer from health metrics
       // Use exponential weighted moving average (EWMA) for latency smoothing
       // Prefer peers with lowest P95 latency that are under capacity
       // Fall back to least-loaded within similar latency brackets

       usort($peers, function ($a, $b) use ($healthMetrics) {
           $latencyA = $healthMetrics[$a['name']]['latency_p95'] ?? 999;
           $latencyB = $healthMetrics[$b['name']]['latency_p95'] ?? 999;

           if (abs($latencyA - $latencyB) < 50) { // Within 50ms — tie-break by load
               return $this->calculate_peer_capacity_score($a) <=>
                      $this->calculate_peer_capacity_score($b);
           }
           return $latencyA <=> $latencyB;
       });

       return $peers[0];
   }
   ```

3. **Add cost-aware routing (6th strategy — `cost_optimized`):**
   - Each peer declares its AI provider costs in well-known endpoint
   - Router calculates per-query cost estimate before routing
   - Prefers cheaper peers for non-urgent queries, falls back to any for urgent
   - Respects cost budgets per assistant/team

4. **Enhance Erlang-C modeling for federation:**
   ```php
   // Already have WP_MCP_AI_Erlang_C class with:
   // - erlang_c(): probability of waiting
   // - average_wait_time(): expected wait in queue
   // - service_level(): probability wait < target

   // Enhancement: add federation-aware methods
   public function federation_capacity_required(array $peers, float $targetServiceLevel): int
   {
       // Calculate total arrivals across all peer channels
       // Determine optimal peer allocation to meet SLA
   }
   ```

5. **Implement A/B peer scoring:**
   - Maintain two scoring models: current (A) and experimental (B)
   - Route 10% of queries via B model for testing
   - Compare outcomes (latency, success rate, cost) after statistical significance
   - Automatically promote B → A when B outperforms A

#### 4.8.3 Deliverables

- [x] `MeshRouterInterface` in oOS Core domain contracts
- [x] `MeshRouter` implementation in oOS Core infrastructure
- [x] Laravel adapter for database-backed peer registry
- [x] `latency_optimized` routing strategy (5th strategy)
- [x] `cost_optimized` routing strategy (6th strategy)
- [x] Enhanced Erlang-C federation capacity modeling
- [x] A/B peer scoring framework

---

## 5. Migration Strategy — Strangler Fig Pattern

### 5.1 Overview

The migration follows the same **Strangler Fig** pattern already proven by the cross-platform extraction. The WordPress plugin continues to operate normally while the Laravel orchestrator gradually takes over responsibilities.

### 5.2 Phase 1: Shadow Mode (Week 1–4)

```
[WordPress Plugin] ——— Primary ———→ [AI Providers]
       │
       └——— Mirror ———→ [Laravel Orchestrator] ——— Shadow ———→ [Log Only]
```

- Laravel orchestrator receives copies of all chat requests (via webhook from WordPress)
- Processes them in parallel but logs results instead of responding to clients
- Compare WordPress responses vs Laravel responses for correctness
- Measure latency, throughput, error rates on both paths

### 5.3 Phase 2: Async Offload (Week 4–8)

```
[WordPress Plugin] ——— Primary ———→ [AI Providers]
       │
       ├——— Async Jobs ———→ [Redis Queue] ———→ [Laravel Workers]
       └——— Federation ———→ [Laravel Orchestrator] ———→ [Federated Peers]
```

- Tool execution jobs are dispatched to Redis queue instead of Action Scheduler
- Federation queries route through Laravel orchestrator with enhanced mesh routing
- Vector embeddings generated asynchronously via Horizon workers
- WordPress still handles chat orchestration and streaming directly

### 5.4 Phase 3: Streaming Migration (Week 8–12)

```
[WordPress Plugin]
       │
       ├——— Chat ———→ [Laravel Orchestrator] ———→ [AI Providers]
       │                     │
       │                     └———→ [Reverb WebSocket] ———→ [Client]
       │
       └——— Tools ———→ [Laravel Orchestrator] ———→ [Redis Queue + Horizon]
```

- Chat orchestration moves to Laravel Octane
- SSE clients connect via the SSE-to-WebSocket bridge
- New clients connect directly via Reverb WebSocket
- WordPress handles only: content storage, CPT management, admin UI, shortcodes

### 5.5 Phase 4: Full Migration (Week 12–16)

```
[WordPress Sites] — Content + Admin + Graphify
        │
        └——— Federation ———→ [Laravel Orchestrator]
                                    │
                                    ├——— AI Chat (Octane)
                                    ├——— Tool Execution (Horizon)
                                    ├——— Vector Search (pgvector)
                                    ├——— Streaming (Reverb)
                                    └——— Federation Routing (Mesh Router)
```

- WordPress instances become "content+admin" nodes in the federation
- All AI orchestration runs on Laravel
- Federation mesh routes queries intelligently across all nodes
- Graphify on each site feeds into centralized pgvector

### 5.6 Feature Flag Control

```php
// Feature flags for gradual rollout
define('OOS_USE_LARAVEL_QUEUE', true);     // Phase 2
define('OOS_USE_LARAVEL_STREAMING', false); // Phase 3 (false until ready)
define('OOS_USE_LARAVEL_CHAT', false);      // Phase 4 (false until ready)
define('OOS_USE_PGVECTOR', true);           // Phase 2
```

---

## 6. Infrastructure & DevOps

### 6.1 Docker Compose (Development)

```yaml
# deploy/laravel-orchestrator/docker-compose.yml
services:
  app:
    build:
      context: .
      dockerfile: Dockerfile
    ports:
      - "8000:8000"   # Octane HTTP
      - "8080:8080"   # Reverb WebSocket
    environment:
      - DB_CONNECTION=pgsql
      - DB_HOST=postgres
      - REDIS_HOST=redis
      - OOS_OPENAI_API_KEY=${OOS_OPENAI_API_KEY}
    depends_on:
      - postgres
      - redis

  postgres:
    image: pgvector/pgvector:pg16
    environment:
      POSTGRES_DB: oos
      POSTGRES_USER: oos
      POSTGRES_PASSWORD: secret
    volumes:
      - pgdata:/var/lib/postgresql/data
    ports:
      - "5432:5432"

  redis:
    image: redis:7-alpine
    ports:
      - "6379:6379"

  horizon:
    build: .
    command: php artisan horizon
    depends_on:
      - redis
      - postgres

volumes:
  pgdata:
```

### 6.2 Production Deployment (Docker Swarm / Kubernetes)

- **Octane workers:** 4 replicas, 2 CPU / 2GB RAM each
- **Horizon workers:** 3 replicas (chat), 5 replicas (tools), 2 replicas (federation), 1 replica (embeddings)
- **PostgreSQL:** Managed (AWS Aurora / DigitalOcean Managed / self-hosted with streaming replication)
- **Redis:** Managed (AWS ElastiCache / DigitalOcean Managed / self-hosted sentinel)
- **Reverb:** 2 replicas behind a load balancer (Redis pub/sub handles cross-node message delivery)
- **Traefik:** Edge router with TLS termination, rate limiting middleware

### 6.3 CI/CD

- GitHub Actions workflow: `deploy-laravel-orchestrator.yml`
- Build steps: composer install, npm build, run tests, build Docker image, push to registry
- Deploy: `docker stack deploy` or `kubectl apply`
- Rollback: `docker service rollback` or `kubectl rollout undo`

### 6.4 Monitoring

- **Laravel Horizon:** Queue throughput, job failures, runtime metrics
- **Laravel Telescope:** Request lifecycle, exceptions, queries, cache hits
- **Reverb dashboard:** Active connections, messages/second, channel subscriptions
- **pgvector monitoring:** `pg_stat_user_indexes` for HNSW index usage, query latency
- **Prometheus + Grafana:** Custom metrics exported via `laravel-prometheus` package
- **OTel:** Existing OTel hooks fire from oOS Core — wire to Grafana Tempo or Jaeger

---

## 7. Testing & Quality Strategy

### 7.1 Testing Pyramid for Laravel Orchestrator

| Layer | Tool | Scope | Coverage Target |
|---|---|---|---|
| **Unit** | PHPUnit + Mockery | Domain services, value objects, individual tools | 90%+ |
| **Integration** | PHPUnit + Orchestra Testbench | Adapter implementations, provider clients | 80%+ |
| **Feature** | PHPUnit HTTP tests | REST endpoints, WebSocket channels, queue jobs | 70%+ |
| **E2E** | Laravel Dusk / Playwright | Full chat flow with real AI providers (record/replay) | Critical paths |
| **Performance** | k6 / Artillery | Load testing with simulated AI responses | <200ms P95 for tool calls |

### 7.2 Key Test Scenarios

1. **ChatOrchestrator with mocked AI provider:** Verify agentic loop iterations, tool calling, context window management
2. **Redis Queue job lifecycle:** Enqueue, process, retry, fail, dead-letter
3. **Reverb streaming:** Connect via WebSocket, subscribe to channel, receive streaming tokens, cancel mid-stream
4. **pgvector search:** Insert 100K vectors, verify sub-10ms cosine similarity queries
5. **Federation failover:** Circuit breaker opens after 5 failures, half-open recovery, peer health propagation
6. **Mesh router strategies:** Verify each of 6 strategies selects expected peer under known conditions
7. **Provider failover:** OpenAI rate limit → automatic switch to Gemini → switch back when OpenAI recovers

### 7.3 Quality Gates

- All tests pass before merge
- PHPStan level 9 on `lib/core/`, level 6 on `deploy/laravel-orchestrator/`
- PHP CS Fixer with Laravel preset
- No new security vulnerabilities (OWASP ZAP scan)
- Performance regression <5% on benchmark suite

---

## 8. Risk Analysis & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **PHP 8.1+ requirement** for oOS Core on Laravel | Low (already required) | Medium | WordPress adapter stays at PHP 7.4; Laravel requires 8.1+ |
| **Redis dependency** adds infrastructure complexity | Medium | Medium | Provide Docker Compose for dev; managed Redis for prod |
| **pgvector maturity** — HNSW indexes are still evolving | Low | Low | pgvector 0.7+ is stable; Aurora PostgreSQL supports it |
| **Reverb scaling** — WebSocket connections are stateful | Medium | Medium | Redis pub/sub enables horizontal scaling; sticky sessions or connection affinity |
| **Federation latency** — cross-site queries add overhead | Medium | Low | Circuit breaker + parallel queries + caching mitigate this |
| **Data consistency** — dual-write to MySQL + PostgreSQL | High | Medium | Use eventual consistency; GraphifyVectorSyncJob; idempotent upserts |
| **WordPress plugin dependency** — Laravel orchestrator needs WordPress sites for content | Low | High | This is by design — the orchestrator augments, not replaces, WordPress |

---

## 9. Timeline & Milestones

```
Week 1–2   Phase 1: Scaffold Laravel app, Octane install, DI wiring
Week 2–3   Phase 1: config/oos.php, NvoosServiceProvider, Supervisor configs
Week 3–4   Phase 2: Horizon install, queue partitions, job classes, ShouldBeUnique
Week 4–5   Phase 3: Reverb install, channel architecture, SSE bridge
Week 5–6   Phase 4: Provider integration, health checks, failover logic
Week 6–7   Phase 5: pgvector setup, VectorStoreInterface, migration script
Week 7–8   Phase 6: FederationClientInterface, FederationOrchestrator, peer registry
Week 8–9   Phase 7: Graphify driver enhancement, cross-federation RAG
Week 9–10  Phase 8: Mesh Router port to oOS Core, latency_optimized + cost_optimized
Week 10–11 Integration testing, end-to-end flows, performance benchmarking
Week 11–12 Shadow mode deployment, compare with WordPress production
Week 12–14 Async offload migration (Phase 2–3), streaming migration (Phase 3–4)
Week 14–16 Full migration (Phase 4), monitoring, documentation, handover
```

**Total: 16 weeks (4 months) for complete migration to Laravel orchestrator.**

---

## 10. Resource Estimate

| Role | FTE | Duration |
|---|---|---|
| **Senior Laravel Engineer** — Orchestrator, Octane, Horizon, Reverb | 1.0 | 16 weeks |
| **oOS Core Engineer** — Domain contracts, Mesh Router port, Federation interfaces | 0.5 | 12 weeks |
| **DevOps Engineer** — Docker, CI/CD, monitoring, production deployment | 0.5 | 8 weeks (weeks 4–12) |
| **WordPress/Graphify Engineer** — Graphify driver enhancement, federation dashboard | 0.5 | 6 weeks (weeks 7–13) |
| **QA Engineer** — Test suite, performance benchmarks, E2E testing | 0.5 | 8 weeks (weeks 8–16) |

**Total: ~3.0 FTE over 16 weeks.**

---

## 11. Appendices

### A. Key Decisions

| Decision | Rationale | Alternatives Considered |
|---|---|---|
| **FrankenPHP over Swoole** | Lower memory, simpler deployment, HTTP/3 support | Swoole (coroutines but complex), RoadRunner (Golang) |
| **pgvector over Pinecone/Qdrant** | Zero licensing cost, relational data joins, existing PostgreSQL expertise | Pinecone (managed but $$), Qdrant (good but another service) |
| **Reverb over Pusher/Soketi** | First-party Laravel integration, no external service dependency | Pusher (managed but $$), Soketi (compatible but community) |
| **Horizon over plain Supervisor** | Dashboard, auto-balancing, job metrics, tag monitoring | Plain Supervisor (simpler but no visibility) |
| **Database peer registry over config file** | Dynamic peer management, real-time health, no deploy on peer changes | Config file (simpler but static) |

### B. Glossary

| Term | Definition |
|---|---|
| **Octane** | Laravel's application server that boots the framework once and serves many requests from memory |
| **Horizon** | Laravel's queue monitoring dashboard and process manager for Redis queues |
| **Reverb** | Laravel's first-party WebSocket server with Redis pub/sub horizontal scaling |
| **pgvector** | PostgreSQL extension for vector similarity search (IVFFlat + HNSW indexes) |
| **HNSW** | Hierarchical Navigable Small World — graph-based approximate nearest neighbor index |
| **Strangler Fig** | Migration pattern where new system gradually replaces old system behind a feature flag |
| **Mesh Router** | Intelligent peer selector that routes AI queries to the optimal federation node |
| **Circuit Breaker** | Failure pattern that temporarily disables a peer after consecutive failures |

### C. References

- [oOS Cross-Platform Extraction Architecture](./cross-platform-extraction-architecture.md)
- [`.context/cross-platform-extraction.md`](../../../.context/cross-platform-extraction.md)
- [Laravel Octane](https://laravel.com/docs/12.x/octane)
- [Laravel Horizon](https://laravel.com/docs/13.x/horizon)
- [Laravel Reverb](https://laravel.com/docs/12.x/reverb)
- [pgvector](https://github.com/pgvector/pgvector)
- [Agentic Mesh Pattern](https://medium.com/@visrow/agentic-mesh-revolutionizing-distributed-ai-systems-in-the-agentic-ecosystem-1062d036769a)
- [AI Agent Federation Architecture](https://fast.io/resources/ai-agent-federation/)
- [Agent Orchestration Patterns](https://gurusup.com/blog/agent-orchestration-patterns)
- [Laravel Octane Benchmark](https://terrylinooo.github.io/laravel-octane-benchmark)
- [pgvector Performance Guide (2026)](https://dbadataverse.com/tech/postgresql/2025/12/pgvector-postgresql-vector-database-guide)
