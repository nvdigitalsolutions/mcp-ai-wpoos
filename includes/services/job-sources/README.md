# Job Sources

## Purpose

Contains the three cron-status job source adapters — Crawl4AI, HITL Approvals, and Transcript Mining — plus their `job-sources-init.php` bootstrap, each implementing `Interface_WP_MCP_AI_Cron_Status_Job_Source` so the Tasks Drawer can discover and display async jobs from disparate origins through a uniform interface.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `job-sources-init.php` hooks `wp_mcp_ai_register_base_job_sources()` onto `wp_mcp_ai_cron_status_job_sources` filter |
| **Optional dependencies** | `WP_MCP_AI_Crawler` (for Crawl4AI source; gracefully returns empty if absent) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Job_Source_Crawl4AI` | `class-wp-mcp-ai-job-source-crawl4ai.php` | cron status service via filter |
| `WP_MCP_AI_Job_Source_Hitl_Approvals` | `class-wp-mcp-ai-job-source-hitl-approvals.php` | same |
| `WP_MCP_AI_Job_Source_Transcript_Mining` | `class-wp-mcp-ai-job-source-transcript-mining.php` | same |
| `wp_mcp_ai_register_base_job_sources()` | `job-sources-init.php` | WordPress filter `wp_mcp_ai_cron_status_job_sources` |

## Inputs / Outputs / Neighbors

- **Reads from:** Crawl4AI transients (keyed by `WP_MCP_AI_Crawler::JOB_STORAGE_PREFIX`), HITL approval state, transcript mining results.
- **Writes to:** nothing persistent — returns normalised job arrays keyed by `task_id`.
- **Upstream callers:** `services/class-wp-mcp-ai-cron-status-service.php` (via the `wp_mcp_ai_cron_status_job_sources` filter).
- **Downstream collaborators:** WordPress transients API, `WP_MCP_AI_Crawler` (for Crawl4AI source).
- **Events fired:** none.
- **Events listened to:** `wp_mcp_ai_cron_status_job_sources` (filter — registration point).

## Conventions

- Each adapter implements `Interface_WP_MCP_AI_Cron_Status_Job_Source` with two methods: `get_slug()` and `get_jobs($user_id, $assistant_id)`.
- `get_jobs()` returns `array<string,array<string,mixed>>` — a map of `task_id` → normalised job record with keys: `job_id`, `kind`, `status`, `created_by`, `assistant_id`, `started_at`, `updated_at`, `eta`, `progress`, `message`, `cancellable`, `retryable`, `source`.
- Status values are normalised to: `pending`, `running`, `polling`, `completed`, `failed`, `cancelled`.
- User-scoping: non-admin users see only their own jobs; admins see all.
- Registration happens through the `job-sources-init.php` bootstrap, which hooks `wp_mcp_ai_register_base_job_sources()` onto the `wp_mcp_ai_cron_status_job_sources` filter at priority 10.

## Tests

```bash
vendor/bin/phpunit tests/test-cron-status-service.php
```

Job source tests are covered within the cron-status service test suite.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — user-scoping, database queries (always)
- Parent folder: [`includes/services/README.md`](../README.md) — full services layer overview

## See Also

- Upstream parent: [`includes/services/`](../) — services layer
- Consumer: [`includes/services/class-wp-mcp-ai-cron-status-service.php`](../class-wp-mcp-ai-cron-status-service.php)
- Interface: `Interface_WP_MCP_AI_Cron_Status_Job_Source` (defined in `includes/interfaces/`)
