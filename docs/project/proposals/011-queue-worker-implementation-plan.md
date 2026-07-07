# Implementation Plan: Infrastructure Scaling & Queue Worker

**Branch:** `feat/infrastructure-scaling-queue-worker`  
**Base:** `alpha-working`  
**Date:** 2026-07-07  
**Related Proposals:** [009](./009-rabbitmq-integration-proposal.md), [010](./010-infrastructure-scaling-mitigation.md)

---

## Scope

This implementation delivers **Phase 3** from Proposal 010 and **Phases 2-5** from Proposal 009 — focused on the changes that are independent, backward-compatible, and immediately testable:

### What's Included

| # | Change | Files |
|---|---|---|
| 1 | Custom DB table replacing `wp_options` in `WP_MCP_AI_Job_Queue_Manager` | `includes/class-wp-mcp-ai-job-queue-manager.php` |
| 2 | Custom DB table replacing `wp_options` in `WP_MCP_AI_Dead_Letter_Queue` | `includes/class-wp-mcp-ai-dead-letter-queue.php` |
| 3 | Health check REST endpoint (`/mcp-ai/v1/health`) | `includes/rest/class-wp-mcp-ai-rest-health.php` (new) |
| 4 | Queue worker CLI script | `bin/queue-worker.php` (new) |
| 5 | Site Health integration for cache backend | `includes/class-wp-mcp-ai-site-health.php` |
| 6 | Activation hook for new tables | `includes/bootstrap/activation.php` |

### What's NOT Included (deferred to follow-up PRs)

- RabbitMQ async dispatch in the agentic loop (requires Proposal 009 Phase 4)
- SSE chunked reconnect (requires JS changes)
- Cloudflare Worker SSE offload (separate addon)
- Production Docker Compose (separate infra PR)
- Admin UI for new queue tables (separate UI PR)

---

## Implementation Order

```
Step 1: Create new DB tables on activation
   ↓
Step 2: Migrate WP_MCP_AI_Dead_Letter_Queue → custom table
   ↓
Step 3: Migrate WP_MCP_AI_Job_Queue_Manager → custom table
   ↓
Step 4: Add health check REST endpoint
   ↓
Step 5: Add queue worker CLI script
   ↓
Step 6: Add Site Health cache backend detection
   ↓
Step 7: Run lint + phpcs
```

---

## Design Decisions

### DB Table Pattern
Follow the pattern established by `WP_MCP_AI_Async_Job_Queue`:
- `dbDelta()` for schema management
- `CREATE TABLE IF NOT EXISTS` for idempotency
- Called from `wp_mcp_ai_activate_single_site()` alongside existing table creation
- All direct DB queries tagged with `phpcs:ignore` comments as per codebase convention

### Backward Compatibility
- Old `wp_options` rows are preserved (not deleted) for one release cycle
- New code reads from custom table, falls back to old option if table is empty
- Old option writes are removed; only the custom table is written
- A WP-CLI migration command (`wp nvoos queue migrate`) will be added in a follow-up PR to move historical data

### Atomic Job Claiming
- Uses `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 8.0+)
- Feature-detects MySQL version; falls back to `FOR UPDATE` without `SKIP LOCKED` on older versions
- Wrapped in `START TRANSACTION` / `COMMIT` via `$wpdb`

### Queue Worker Design
- PHP CLI script, bootstrap WordPress minimally
- Signal handling (SIGTERM, SIGINT) for graceful shutdown
- Memory limit watchdog (exit cleanly at 90% of `memory_limit`)
- Configurable via CLI flags: `--queue`, `--memory-limit`, `--timeout`, `--max-jobs`
- Processes jobs from the custom DB table (RabbitMQ consumer deferred to follow-up)

---

## File List

```
MODIFIED:
  includes/class-wp-mcp-ai-job-queue-manager.php
  includes/class-wp-mcp-ai-dead-letter-queue.php
  includes/bootstrap/activation.php
  includes/class-wp-mcp-ai-site-health.php

NEW:
  includes/rest/class-wp-mcp-ai-rest-health.php
  bin/queue-worker.php

DOCS:
  docs/project/proposals/009-rabbitmq-integration-proposal.md
  docs/project/proposals/010-infrastructure-scaling-mitigation.md
  docs/project/proposals/011-queue-worker-implementation-plan.md
```
