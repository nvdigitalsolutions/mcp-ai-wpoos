# Markup

## Purpose

Wave E6 port surface (sub-cluster 2 — markup). The markup elicitation
subsystem from the base plugin's `includes/markup/` + `includes/markup-init.php`
(plus the markup-owned slash command and telemetry admin page from
`includes/slash-commands/commands/` and `includes/admin/`) — the
MCP-spec workflow that lets a tool interrupt the agentic loop, hand the
user an editable canvas widget, validate the W3C Web Annotation result,
and resume execution with the validated markup data. Ported into the AI
addon per decision D4 (engine pieces fold into `nvoos-content-graph-ai`
under the `Engine\` namespace).

## Tier

| | |
|---|---|
| **Distribution** | AI addon (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAi\Plugin::registerEngine()` → `MarkupBootstrap::register()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base `markup-init.php` owns the same hooks monolith |
| **Optional dependencies** | GD (mask rasterization; degrades to structured shapes without it); the base plugin's tool registry / logger (monolith-only) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Engine\Markup\MarkupAwareToolInterface` | `MarkupAwareToolInterface.php` | Tools that elicit canvas markup (`needs_markup()` / `consume_markup()`) |
| `NvoosContentGraphAi\Engine\Markup\MarkupRequest` | `MarkupRequest.php` | Tools, the interceptor, the store |
| `NvoosContentGraphAi\Engine\Markup\MarkupResult` | `MarkupResult.php` | The validator (produces it); markup-aware tools (consume it) |
| `NvoosContentGraphAi\Engine\Markup\MarkupElicitation` | `MarkupElicitation.php` | Widget / MCP `elicitation/create` envelope builders |
| `NvoosContentGraphAi\Engine\Markup\MarkupStore` | `MarkupStore.php` | Transient-backed request storage; daily cleanup cron |
| `NvoosContentGraphAi\Engine\Markup\MarkupValidator` | `MarkupValidator.php` | Validates W3C Web Annotation payloads on resume |
| `NvoosContentGraphAi\Engine\Markup\MarkupRasterizer` | `MarkupRasterizer.php` | Optional server-side raster of the annotated canvas |
| `NvoosContentGraphAi\Engine\Markup\MarkupLoopInterceptor` | `MarkupLoopInterceptor.php` | Short-circuits the agentic loop before `execute()` when a tool requests markup |
| `NvoosContentGraphAi\Engine\Markup\MarkupRestController` | `MarkupRestController.php` | REST routes for fetching pending requests + submitting results |
| `NvoosContentGraphAi\Engine\Markup\MarkupAssets` | `MarkupAssets.php` | Registers + enqueues the chat-side widget assets |
| `NvoosContentGraphAi\Engine\Markup\MarkupAdminPage` | `MarkupAdminPage.php` | URL-mode elicitation admin fallback |
| `NvoosContentGraphAi\Engine\Markup\MarkupTelemetry` | `MarkupTelemetry.php` | Outcome counters (slash command + admin page) |
| `NvoosContentGraphAi\Engine\Markup\MarkupStatsSlashCommand` | `MarkupStatsSlashCommand.php` | `/markup-stats` command, registered on `wp_mcp_ai_default_slash_commands_loaded` |
| `NvoosContentGraphAi\Engine\Markup\MarkupTelemetryAdminPage` | `MarkupTelemetryAdminPage.php` | Read-only telemetry dashboard + reset action |
| `NvoosContentGraphAi\Engine\Markup\MarkupBootstrap` | `MarkupBootstrap.php` | Wraps the base `markup-init.php` hook surface |

## Inputs / Outputs / Neighbors

- **Reads from:** raw `$arguments` passed to a markup-aware tool's
  `needs_markup()`; pending-request transients (`wp_mcp_ai_markup_*`);
  the markup index option (`wp_mcp_ai_markup_index`); submitted
  W3C-Web-Annotation payloads via REST; the `wp_mcp_ai_settings`
  option (`markup_enabled`); current-user + assistant context.
- **Writes to:** transients (pending requests, TTL-bounded with a
  per-assistant cap of 16); the markup index option; mask attachments
  under the private `wp-mcp-ai-markup` uploads directory;
  telemetry counters (`wp_mcp_ai_markup_telemetry`, non-autoloaded);
  (monolith) the base recent-activity log.
- **Upstream callers:** `Plugin::registerEngine()`; the agentic loop
  via the `wp_mcp_ai_pre_execute_tool` filter; the chat client via REST
  submit; WP-Cron (daily cleanup); the platform's slash-command
  handler (fires `wp_mcp_ai_default_slash_commands_loaded`).
- **Downstream collaborators:** markup-aware tools; the base
  `WP_MCP_AI_Tool_Registry` (monolith) /
  `CoreBridge::instance()->tools` (standalone) via the REST controller
  seam; WordPress media library.
- **Events fired:** `wp_mcp_ai_markup_request_created`,
  `wp_mcp_ai_markup_submitted`, `wp_mcp_ai_markup_validated`,
  `wp_mcp_ai_markup_resolved`.
- **Events listened to:** `plugins_loaded` (interceptor + telemetry),
  `rest_api_init` (routes), `init` (assets, admin pages, cron),
  `wp_enqueue_scripts` (chat shim), `wp_mcp_ai_markup_cleanup`,
  `wp_mcp_ai_recent_activity_types`,
  `wp_mcp_ai_default_slash_commands_loaded`.

## Conventions

- **Per-mode discriminator is `defined( 'WP_MCP_AI_PATH' )`** — never
  bare `class_exists()` (the monorepo classmap resolves base classes
  standalone). The tool lookup / awareness seams in the REST controller
  accept the base interface monolith and this package's interface
  standalone.
- **Tools MUST keep `needs_markup()` deterministic and
  side-effect-free** — the interceptor relies on idempotency.
- **`consume()` MUST be replay-safe** — the store deletes on read.
- **Submitted markup MUST pass `MarkupValidator`** before reaching
  `consume_markup()` — bypassing the validator is a security
  regression.
- **Telemetry outcomes are an enumerated set** — new outcomes go in
  `MarkupTelemetry::outcomes()` so the recent-activity filter + slash
  command stay in sync.
- Byte-identical constants/option keys/transient prefixes/error codes/
  hook names with the base; deviations documented in the class
  docblocks (PSR-4 class names, inline requires → autoload + static
  `register()`, text domain, the telemetry page parent-menu dormancy
  standalone, the slash-command class superseding the platform's
  dormant E2 blanket-port copy).
- Standalone-only bootstrap registration — the base init owns the same
  hooks monolith.

## Tests

- `tests/Ecosystem/test-markup-core.php` — request/result/elicitation
  value objects, store, validator, rasterizer.
- `tests/Ecosystem/test-markup-loop-rest.php` — interceptor gate
  chain, per-mode wiring, REST contract, tool-resume seam.
- `tests/Ecosystem/test-markup-telemetry-ui.php` — telemetry recorder,
  slash command, admin telemetry page, assets.

```bash
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-markup-core.php
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-markup-loop-rest.php
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-markup-telemetry-ui.php
```

## Also Load

- [`../README.md`](../README.md) — the Engine wave (OOS + markup)
- [`../../README.md`](../../README.md) — composition root + subsystem index
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (validator + capability gates)

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E6 row status
- [`includes/markup/`](../../../../includes/markup/) + [`includes/markup-init.php`](../../../../includes/markup-init.php) — the base subsystem (the port's origin)
