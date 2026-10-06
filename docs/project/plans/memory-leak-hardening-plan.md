# Memory Leak Hardening Plan — Base + Pro Plugin

> Status: **Implemented (2026-10-06)** · Audit + all Phases 1–3 fixes landed; Phase 4 partially (Node regression tests + docs done; PHP regression tests & PHPCS sniff deferred — see §6).
> Scope: `includes/` + `lib/` (base PHP), `addons/pro/includes/` (pro PHP), Node workers (`addons/media-worker`, `addons/cloud-worker`, `addons/pro/node-services`, `addons/mcp-gateway`), browser JS (`assets/js/chat.js`, SPA).
> Method: targeted grep sweeps + section reads, split across three parallel read-only agents, spot-verified against source. No code changed.

---

## 1. Industry standards consulted

| Standard | Source | Implication for this repo |
|---|---|---|
| Autoloaded options budget < ~800 KB; transients churn `wp_options` | Delicious Brains "Art of the WordPress Transient" | Keep append-style options **non-autoloaded**; prefer object cache for high-churn keys; add proactive cleanup crons |
| Transients don't expire if WP-Cron isn't running → unbounded buildup | Ben Ryan "Backend Performance Optimization" | Every high-frequency transient store needs a self-prune fallback (already a pattern here: `sync-log-manager`, schedule manager) |
| Persistent object cache absorbs transient/option churn; DB bloat is a symptom | Pressable / DoHost | Recommend external object cache (Redis) as first-line mitigation; fix DB growth regardless |
| Node leak detection: compare heap snapshots under load, `v8.writeHeapSnapshot()`, metrics on `nodejs_external_memory_bytes`/heap/RSS | Better Stack, Halodoc | Media worker: add snapshot-on-near-limit + Prometheus/PM2 metrics |
| Ops guardrail: `--max-old-space-size` + PM2 `max_memory_restart`, `--heapsnapshot-near-heap-limit=2` | AppSignal, Halodoc | No memory flags exist in any worker `package.json` today — add them |
| mysql2 pools: create once at startup, `connectionLimit` + `maxIdle` + `idleTimeout`, `queueLimit` for backpressure, release in `finally` | mysql2 maintainers / daily.dev / OneUptime | Applies to any future DB worker; currently workers use Redis, WP side uses `$wpdb` |
| Pub/Sub is at-least-once → dedupe before enqueueing | Gmail Pub/Sub docs | `crm-gmail-pubsub-handler` must guard `as_enqueue_async_action` with `as_has_scheduled_action` / historyId |
| Event listeners/timers must be paired with teardown; `unref()` timers that shouldn't hold the loop | Node best practice | Multiple findings in `media-worker` + `chat.js` |

## 2. Findings inventory

### 2.1 Base PHP (`includes/`, `lib/`)

| SEV | Location | Problem | Fix |
|---|---|---|---|
| Med | `includes/class-wp-mcp-ai-analytics-engine.php:720-723` | `rebuild_usage_from_transcripts()` selects the **entire transcript CCT** (no LIMIT) and `json_decode`s every `metadata` blob | Page with `_ID > $last` batched iterator; decode per-row |
| Med | `includes/class-wp-mcp-ai-generation-provenance.php:373` | `verify_chain_integrity()` loads the full hash chain (`SELECT *`, 365d retention) into memory | Verify in `WHERE id > %d LIMIT 500` chunks, streaming the chain |
| Med | `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php:2518-2525` | `wp_mcp_ai_agent_roles` filter LIKE-scans **all of wp_options** without LIMIT/autoload filter | Add `AND autoload = 'yes'` + `LIMIT 100`, or a single registry option of evolved role IDs |
| Low-Med | `includes/class-wp-mcp-ai-chat-response-cache.php:117` | One `_transient_*` row per unique conversation; TTL'd but relies on lazy WP cleanup → table churn on busy sites | Object-cache-first + LRU registry + proactive cleanup cron |
| Low | `includes/class-wp-mcp-ai-semantic-cache.php:230-287` | Tier-2 store = single option up to 1,000 entries × ~12 KB embedding vectors; full read+reserialize per `set()` | Per-hash object-cache keys or dedicated table; lazy prune |
| Low | `includes/class-wp-mcp-ai-agentic-workflow-optimizer.php:364` | `static $low_necessity_count` keyed by session, never unset (grows in stdio/WP-CLI daemons) | Unset after nudge / cap with `array_slice` |
| Low | `includes/class-wp-mcp-ai-token-tracking-database.php:380-384` | `get_user_usage()` `SELECT * ... ORDER BY timestamp DESC` with optional (not required) date filter | Require date range or enforce LIMIT |
| Low | `includes/cache/class-wp-mcp-ai-cache-service.php:92-98` | Fresh `new Redis()` + connect per request → TCP churn under load | `pconnect()` + PING health check, or WP object-cache drop-in |
| Low | `includes/admin/class-wp-mcp-ai-admin-orchestration-dashboard.php:1517-1525` | Loads every `_transient_mcp_ai_ctx_index_%` value with no LIMIT (admin) | LIMIT/paginate or store counts in a registry |
| Low | `includes/class-wp-mcp-ai-rest-cache.php:181-194` | Per-endpoint cache-key registry appends with no cap | FIFO cap + evict transient when key is dropped |
| Low | `includes/services/class-wp-mcp-ai-tool-load-monitor.php:474-494` | One option row **per tool slug** (~347 rows), each a 1,000-entry ring buffer | Single option keyed by slug, or smaller `MAX_HISTORY_ENTRIES` |

Already hardened (verified, do not duplicate): logger buffers (capped + non-autoloaded), SSE session registry (caps + TTL + `finally` slot release), async job queue (daily cleanup, `SKIP LOCKED` claims), token tracking (90d cron), transcripts (retention sweep), provenance (365d prune), approval queue (weekly cleanup).

### 2.2 Pro PHP (`addons/pro/includes/`)

| SEV | Location | Problem | Fix |
|---|---|---|---|
| Med | `rest/class-wp-mcp-ai-chat-channels-rest-controller.php:571` | Contacts list `SELECT *` no LIMIT/pagination | LIMIT/OFFSET |
| Med | `tools/analytics/class-wp-mcp-ai-tool-data-warehouse-sync.php:256,273` | Order + custom-metrics sync loads full date range, no LIMIT | Chunk by page/limit |
| Med | `admin/class-wp-mcp-ai-media-command-center-page.php:2274` | `get_broken_reference_count()` loads all `post_content` with `wp-image-` refs on every render | Cap to recent N posts / count-only query |
| Med | `class-wp-mcp-ai-jetengine-vitals-log-cct.php:722,731` | `get_for_member()` defaults `$limit=0` → full `SELECT *`; **no retention cron** | Enforce default limit + add prune |
| Med | `tools/analytics/class-wp-mcp-ai-tool-create-custom-report.php:460` | `wp_schedule_event` without `wp_next_scheduled` guard → duplicate recurring events stack in the autoloaded `cron` option | Dedupe before scheduling |
| Med | `class-wp-mcp-ai-execution-history-cct.php:199` | `get_session_history()` no default limit; no prune for execution history rows | Default limit + daily retention prune |
| Med | `tools/crm/inbound/class-wp-mcp-ai-crm-gmail-pubsub-handler.php:157,179` | `as_enqueue_async_action` per push with no dedupe; Pub/Sub is at-least-once → duplicate import jobs flood AS | Guard with `as_has_scheduled_action` / historyId idempotency |
| Low | `admin/class-wp-mcp-ai-pro-agent-command-center.php:1947,2006,2037` | Dashboard loads + unserializes every session/workflow transient blob | Fetch `option_name` only, or paginate |
| Low | `tools/healthcare/.../class-wp-mcp-ai-tool-create-health-reminder.php:287,291` | `update_option(..., 2-arg)` → autoloads the reminders blob; full `reminder_data` embedded in cron args; unguarded `wp_schedule_single_event` | No-autoload + inline cap + `wp_next_scheduled` guard |
| Low | `admin/class-wp-mcp-ai-media-command-center-page.php:2387` | AJAX compression sweep queues an AS job per click, no pending-check → duplicate full-library sweeps | `as_has_scheduled_action` guard |
| Low | `services/class-wp-mcp-ai-ocr-service.php:562` | `convert_pdf_with_imagick` renders all PDF pages to temp PNGs in `$images[]` | Enforce default page cap |

Already hardened: sync-log-manager (50/200 caps + hourly prune), Pro Schedule Manager (ring buffer 50 + hourly prune + no-autoload), all `set_transient` calls carry TTLs, most recurring syncs guarded, retention crons for memory/chat-channels/CRM/healthcare/QMS/document-gen, no Redis client usage in pro PHP, no whole-archive buffering in OCR.

### 2.3 Worker / Node (`addons/media-worker` et al.)

| SEV | Location | Problem | Fix |
|---|---|---|---|
| Med | `addons/media-worker/src/queue.js:204-208` | Retry re-adds the job with fresh `attempts: 0` → persistently failing job retries **forever** (CPU/log churn) | Pass `attempts: job.attempts` through `add()` |
| Med | `addons/media-worker/src/queue.js:128-131` | Delayed in-memory fallback `setTimeout` not `.unref()`ed → holds event loop | `.unref()` (pattern exists at line 183) |
| Med | `addons/media-worker/src/utils/browser.js:67-94` | `acquireSlot()` released right after `launch()`, not after `browser.close()` → unbounded live Chromium processes | Release slot in callers' `finally` after `browser.close()` |
| Med | `addons/media-worker/src/status/alerts.js:66-87` | New `nodemailer.createTransport()` (SMTP pool + sockets) per alert, never closed | Memoize one transporter; close on shutdown |
| Med | `addons/media-worker/src/routes/video.js:230-232,351-352` | multer temp uploads leak on early 503/400 returns before `unlinkSync` | `finally`-style unlink on all paths |
| Med | `assets/js/chat.js:20718-20740` | `visibilitychange` + `MutationObserver` never removed → detached DOM + dead EventSource per re-init | Store/remove listener; `observer.disconnect()` on stop |
| Low | `addons/media-worker/src/routes/crawl4ai.js:59-84,198` | `tasks` Map holds full crawl results 30 min, no size cap | Cap retained bytes / evict after N polls |
| Low | `addons/media-worker/src/index.js:374-377` | Shutdown never disconnects ioredis clients or stops queue loops | `redisClient.disconnect()` + `queue.stop()` |
| Low | `assets/js/chat.js:12336-12342` | Permanent 30s `setInterval` per chat container, never cleared | Clear on container removal / `isConnected` self-check |
| Low | `assets/js/chat.js:19858-19862` | `keydown` listener per drawer init, never removed | Delegated single listener / teardown |
| Low | `assets/js/cron-status-service.js:44-46` | `cache` map entries survive `stopMonitoring()` | Clear in `stopMonitoring()` |
| Low | `addons/chat-spa/src/hooks/useJobBus.ts:163-185` | `jobs` map grows for SPA session lifetime | Auto-prune terminal jobs / cap N |
| Low | `src/workflow-builder/utils/workflowExecutor.js:565-573` | `waitForResume()` 100 ms interval never cleared if cancelled while paused | Watch `cancelRequested`/`status` in tick |
| Low | `addons/pro/node-services/ffmpeg-service.js:24-44` | fluent-ffmpeg has no timeout → hung child holds CLI process | Kill timer on `start` event |

Verified clean: `mcp-gateway` (stateless, `AbortSignal.timeout()`), `cloud-worker` (stateless D1 handlers), Redis reconnect storm hardening, TLS socket destruction, SSE reconnect caps in `chat.js`, React effect cleanup in chat-spa, workflow undo history cap.

**Missing guardrail:** no `--max-old-space-size` / `max_memory_restart` / heap-snapshot-on-limit anywhere in worker configs.

## 3. Remediation plan (phased)

### Phase 0 — Measure first (Docker, ~½ day)
1. `docker compose up -d` → confirm `wordpress`, `db`, `media-worker` healthy.
2. Baseline autoloaded size: `docker compose exec db mysql -uwordpress -pwordpress wordpress -e "SELECT COUNT(*), SUM(LENGTH(option_value)) FROM wp_options WHERE autoload='yes';"` — flag anything > ~800 KB.
3. Transient table bloat: same query for `option_name LIKE '\_transient\_%'`.
4. Worker baseline: `docker stats oos-media-worker` + `curl http://localhost:3100/api/health`; run a small image pipeline, then `docker exec oos-media-worker node -e "console.log(process.memoryUsage())"` before/after; capture a heap snapshot after load if needed.
5. Cron health: `docker compose run --rm wp-cli cron event list` — look for stacked duplicate events (report sender, Gmail polls).

### Phase 1 — Quick wins (High/Med only, ~2-3 days)
- `queue.js` retry attempts passthrough + `unref()` (worker).
- Browser slot release after `browser.close()` in `browser.js`/`pdf.js`/`crawl.js` callers.
- Nodemailer transporter memoization; video route `finally` unlink.
- `chat.js` listener/observer teardown + quota interval self-clear.
- Unbounded SELECTs → LIMIT/paging: analytics engine, provenance chain, harness-evolver options scan, chat-channels contacts, data-warehouse sync, broken-reference count, vitals `get_for_member`, execution-history.
- `create-custom-report` `wp_next_scheduled` guard; Gmail pubsub `as_has_scheduled_action` dedupe; media sweep AJAX pending-check.

### Phase 2 — Structural hardening (~3-5 days)
- Add retention crons where missing: vitals log CCT, execution history CCT (mirror existing 90d/365d prune patterns).
- Semantic cache → object-cache-first or dedicated table (drop the 10 MB single-option blob).
- Tool load monitor → one consolidated option instead of ~347 rows.
- REST cache registry → FIFO cap with eviction.
- Health reminders → no-autoload + inline cap.
- Redis cache service → `pconnect()` or defer to WP object cache drop-in.
- Docs update: `docs/features/performance/RESOURCE-MANAGEMENT.md` + `docs/developer/testing-docs/performance-testing-guide.md` gains a "memory budgets" section.

### Phase 3 — Worker observability & guardrails (~1 day)
- Add `NODE_OPTIONS=--max-old-space-size=2048` (and `--heapsnapshot-near-heap-limit=2 --diagnostic-dir=...`) to media-worker `package.json`/Dockerfile; add PM2 `max_memory_restart` for deployments.
- Expose `process.memoryUsage()` + Redis queue depth on the `/api/health` payload.
- Graceful shutdown: disconnect ioredis clients, `queue.stop()`.

### Phase 4 — Prevention (~1-2 days)
- Regression tests (PHPUnit via Docker, per `mcp-ai-wpoos-test-suite` conventions) for: retry attempts cap, AS dedupe guards, `get_for_member` default limit, report-schedule dedupe.
- Optional: custom PHPCS sniff "direct DB queries must carry LIMIT or explicit unbounded justification" (pattern: existing `WPMCPAI.Tools.*` sniffs in `phpcs/`).
- Document the audit in `docs/project/plans/` and note the "verified clean" list so future agents don't re-audit hardened areas.

### Validation per fix
- PHP: `docker compose run --rm wp-cli ...` to trigger the code path; re-run Phase 0 queries to confirm growth stops.
- Worker: exercise failing-job path, confirm `'failed'` emits after maxAttempts; `docker stats` flat across runs.
- PHPUnit: `composer run test` scoped to the touched test files before PR.

## 4. Out of scope / decisions needed
- External object cache (Redis) recommendation is deployment-level — document, don't bundle.
- `--max-old-space-size` value depends on production box size — propose 2048 MB default, make env-tunable.
- `chat.js` is a large legacy file — teardown fixes should be minimal; a full rewrite stays out of scope.

## 5. Effort summary
| Phase | Effort |
|---|---|
| 0 Measure | 0.5 d |
| 1 Quick wins | 2-3 d |
| 2 Structural | 3-5 d |
| 3 Observability | 1 d |
| 4 Prevention | 1-2 d |
| **Total** | **~8-11 days** |

## 6. Implementation status & verification (2026-10-06)

All findings in §2 were fixed (34 files, +1204/−274), including one extra
regression test each for the queue retry cap and the crawl4ai store cap.

### Verified
- **PHP**: `php -l` on all 21 changed files — clean. `phpcs
  --standard=phpcs.xml.dist --error-severity=1 --warning-severity=8` on the
  same files — **0 errors, exit 0** (Docker `wp-cli` container).
- **PHP live smoke**: plugin active in Docker WP; `wp eval` loads without
  fatals; `wp_mcp_ai_chat_response_cache_cleanup` daily cron registered.
- **Node**: `node --check` on all 9 changed worker/browser JS files — clean.
- **Worker tests (Docker, rebuilt image)**: `node --test` — 182 tests,
  **181 pass**; the single failure is pre-existing and environmental
  (`EACCES` on `/srv/worker-tmp` when tests run as the non-root `node` user),
  unrelated to these changes. `queue.test.js` + `crawl4ai.test.js` (incl. the
  two new regression tests) — **25/25 and 17/17 pass**.
- **Worker runtime**: image rebuilt with `NODE_OPTIONS` guardrails; `/api/health`
  now returns `memory: { rss, heap_used, heap_total, external }`.

### Deviations from the plan
- §3 Phase 4 PHPUnit regression tests for the PHP fixes were **not added** —
  the Docker `wp-cli` container cannot bootstrap the repo's PHPUnit env
  (dedicated test environment required), and unverified tests must not be
  committed. Follow-up: add them in the standard test env (see
  `mcp-ai-wpoos-test-suite`).
- §3 Phase 4 PHPCS "unbounded query" sniff deferred (larger effort; worth a
  dedicated issue).
- `pro-agent-command-center` dashboard queries genuinely need `option_value`,
  so they were bounded with `LIMIT 500` instead of switching to
  `option_name`-only (behavior preserved).
- Browser-slot fix uses Puppeteer's `disconnected` event to release the
  semaphore (equivalent guarantee, zero caller changes).

### Known follow-ups
- Run the full PHPUnit suite in the dedicated test env (CI will also cover
  this on push).
- Consider a dedicated registry-option pattern for evolved agent roles
  (noted in-code at the `LIMIT 100` workaround).
- Track worker memory under load with `docker stats` / the new `/api/health`
  payload over a soak period; tune `NODE_MAX_OLD_SPACE_SIZE` if needed.
- Unrelated: untracked empty `nul` file at repo root predates this work
  (Windows redirect artifact) — safe to delete.
