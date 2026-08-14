# db/

## Purpose

Houses durable, transport-agnostic job state storage for the async execution pipeline — the `wp_mcp_ai_jobs` custom table and its `WP_MCP_AI_Job_Store` accessor — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/activation.php` (table creation via `dbDelta()`); classes autoloaded on demand |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Job_Store` | `class-wp-mcp-ai-job-store.php` | CRUD + status lifecycle for `wp_mcp_ai_jobs`; `create_table()` during activation |

Anything not listed here is internal and may change without notice.

## Inputs / Outputs / Neighbors

- **Reads from:** the `wp_mcp_ai_jobs` table (job rows keyed by job ID).
- **Writes to:** the `wp_mcp_ai_jobs` table — statuses `queued`, `running`, `completed`, `failed`, `cancelled` (`VALID_STATUSES`).
- **Upstream callers:** `includes/class-wp-mcp-ai-job-notifier.php` (job lifecycle), `includes/rest/class-wp-mcp-ai-rest-tools-controller.php` (async tool results), `includes/admin/class-wp-mcp-ai-admin-cron-manager.php`, the WordPress `QueueClient` adapter in `lib/wordpress-adapter/`.
- **Downstream collaborators:** `$wpdb` + `dbDelta()` only — no other plugin subsystem is called.
- **Events fired:** none.
- **Events listened to:** none.

## Conventions

- The store is transport-agnostic: it tracks *state*, while the queue transport (Action Scheduler / RabbitMQ) tracks *delivery*. Do not duplicate delivery metadata here.
- All methods are static-free instance methods except `create_table()`; the class carries no WordPress hooks of its own.
- Status strings must come from `VALID_STATUSES` — never introduce ad-hoc status values.

## Tests

```bash
vendor/bin/phpunit tests/test-async-executor-job-notifier-integration.php
vendor/bin/phpunit tests/test-async-job-id-visibility.php
```

There is no dedicated unit suite for the store class; it is covered end-to-end through the async executor + job notifier integration tests and the async tool-call ID visibility tests.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (always)
- [`.context/settings-storage.md`](../../.context/settings-storage.md) — custom-table storage decisions

## See Also

- Upstream parent: [`includes/`](../)
- Siblings worth knowing about:
  - [`includes/class-wp-mcp-ai-job-notifier.php`](../class-wp-mcp-ai-job-notifier.php) — primary consumer
  - [`includes/class-wp-mcp-ai-async-job-queue.php`](../class-wp-mcp-ai-async-job-queue.php) — async execution pipeline
- Related: [`docs/project/proposals/023-database-connection-pooling-stance.md`](../../docs/project/proposals/023-database-connection-pooling-stance.md) — queue/DB pooling stance that governs how job state interacts with the transport layer
