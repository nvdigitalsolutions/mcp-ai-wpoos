# Engine

## Purpose

Wave E6 port surface. Sub-cluster 1 — OOS: the OOS shadow subsystem
from the base plugin's `includes/oos/` + `includes/bootstrap/oos-bridge.php`
(Proposal 029, Phase 4.1): the sampled parallel shadow runner, the
engine/shadow gate helpers, the write-class tool suppression waterfall,
and the parity CLI. Sub-cluster 2 — markup: the markup elicitation
subsystem from the base plugin's `includes/markup/` +
`includes/markup-init.php` (plus the markup-owned slash command and
telemetry admin page) — the interrupt-and-resume canvas flow (see
`Markup/README.md`). Sub-cluster 3 — paper-store: the flat-file
knowledge store from the base plugin's `includes/paper-store/` — the
JSON driver, inverted index, repository, fluent query builder, and
root manager (see `PaperStore/README.md`). Sub-cluster 4 — OKF: the
Open Knowledge Format engine from the base plugin's `includes/okf/` —
the YAML frontmatter parser, bundle reader/writer, bundle manager with
ZIP distribution, and the skill-knowledge generator (see
`Okf/README.md`). Sub-cluster 5 — crawler: the
Crawl4AI background coordinator from the base plugin's
`includes/crawler/` plus its required inline-async-tick trait
collaborator (see `Crawler/README.md`). All ported into the AI
addon per decision D4 (engine pieces fold into `nvoos-content-graph-ai`
under the `Engine\` namespace).

## Tier

| | |
|---|---|
| **Distribution** | AI addon (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAi\Plugin::registerEngine()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base bridge (`oos-bridge.php`) owns the same subscriber + waterfall monolith |
| **Optional dependencies** | `nvoos/core` + `nvoos/wordpress-adapter` (bundled via Composer); the base plugin's `wp_mcp_ai_oos_orchestrator()` factory + `WP_MCP_AI_Logger` (monolith-only) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Engine\OosShadowRunner` | `OosShadowRunner.php` | hooked on `wp_mcp_ai_before_chat_request` priority 1 (standalone-only); the parity CLI reads its store |
| `NvoosContentGraphAi\Engine\OosEngineFlags` | `OosEngineFlags.php` | `OosShadowRunner`, `OosShadowSuppression` — shadow/engine gates + write-class classifier |
| `NvoosContentGraphAi\Engine\OosShadowSuppression` | `OosShadowSuppression.php` | `Plugin::registerEngine()` — `tools/execute` waterfall (priority 20) |
| `NvoosContentGraphAi\Cli\OosParityCommand` | `../Cli/OosParityCommand.php` | `wp nvoos-cg-ai oos parity [diff <run-id>]` (standalone-only) |
| `NvoosContentGraphAi\Engine\Markup\MarkupBootstrap` | `Markup/MarkupBootstrap.php` | `Plugin::registerEngine()` — wraps the base `markup-init.php` hook surface (standalone-only); see `Markup/README.md` for the full 15-class surface |
| `NvoosContentGraphAi\Engine\PaperStore\PaperStoreBootstrap` | `PaperStore/PaperStoreBootstrap.php` | `Plugin::registerEngine()` — wraps the base `paper-store-init.php` hook surface (standalone-only); see `PaperStore/README.md` for the full 8-class surface |
| `NvoosContentGraphAi\Engine\Okf\OkfBootstrap` | `Okf/OkfBootstrap.php` | `Plugin::registerEngine()` — wraps the base `okf-init.php` hook surface (standalone-only); see `Okf/README.md` for the full 6-class surface |
| `NvoosContentGraphAi\Engine\Crawler\Crawler` | `Crawler/Crawler.php` | `Plugin::registerEngine()` — the Crawl4AI background coordinator (standalone-only); see `Crawler/README.md` for the trait collaborator |

Stable contract: `STORE_OPTION = 'wp_mcp_ai_oos_shadow_runs'`,
`STORE_MAX = 100`, the run-record shape (`build_record()`), the hook
contract (`wp_mcp_ai_before_chat_request`, 4 args, priority 1), and the
option/filter surface (`enable_oos_shadow`, `oos_shadow_sample_rate`,
`enable_oos_engine`, `wp_mcp_ai_oos_shadow_*` filters,
`WP_MCP_AI_OOS_ENGINE`, `X-WP-MCP-AI-Engine` header, `?engine=oos`).

## Inputs / Outputs / Neighbors

- **Reads from:** the `wp_mcp_ai_settings` option (shadow/engine flags,
  sample rate), the `wp_mcp_ai_oos_shadow_runs` option (parity store).
- **Writes to:** the `wp_mcp_ai_oos_shadow_runs` option (100-run cap,
  newest first, non-autoloaded) and (monolith) the base audit log via
  `WP_MCP_AI_Logger::log_event( 'oos_shadow_run', … )`.
- **Upstream callers:** `Plugin::registerEngine()`; any future standalone
  emitter of `wp_mcp_ai_before_chat_request`; the parity CLI.
- **Downstream collaborators:** `CoreBridge::instance()->chat`
  (`ChatOrchestrator::handleChat`, non-streaming, deadline-bounded via
  `CancellationToken`), `CoreBridge::instance()->events` /
  `->tools` (suppression waterfall), the base
  `wp_mcp_ai_oos_orchestrator()` / `wp_mcp_ai_oos_*` functions /
  `WP_MCP_AI_Assistant_CPT` (monolith, `function_exists`- /
  `class_exists`-gated).
- **Events fired:** none public (records + audit only).
- **Events listened to:** `wp_mcp_ai_before_chat_request` (priority 1),
  `tools/execute` (core waterfall, priority 20).

## Conventions

- **Per-mode discriminator is `defined( 'WP_MCP_AI_PATH' )`** — never
  bare `class_exists()` (the monorepo classmap resolves base classes
  standalone). The base *functions* additionally defer via
  `function_exists()` so monolith behavior is byte-identical.
- **Shadow safety invariants (do not weaken):** write-class tools never
  execute in shadow (the `tools/execute` suppression waterfall
  short-circuits with the synthetic
  `(shadow: write-class tool suppressed)` result); shadow never emits
  output (non-streaming `handleChat` only); shadow failures are
  contained (try/catch + deadline, recorded with an `error` key); no
  shadow on the OOS path (engine-flag + REST-request guards).
- **Byte-identical dormancy:** no standalone surface emits
  `wp_mcp_ai_before_chat_request` yet, so the runner is dormant
  standalone — same as the base on a legacy-path-only install.
- **Orchestrator seam is deliberately untyped** — the base factory
  declares no return type, and the characterization suite substitutes a
  fake orchestrator.
- Byte-identical option keys / filters / error messages with the base;
  deviations documented in the class docblocks (PSR-4 class names,
  global functions → static methods with function deferral, CLI path
  `nvoos-cg-ai` vs base `mcp-ai`).

## Tests

- `tests/Ecosystem/test-oos-shadow-runner.php` — constants,
  registration, the full gate chain, the parity-record shape, both
  containment paths, the capped newest-first store, flag helpers,
  classifier, the suppression waterfall (real dispatcher + registry),
  per-mode seams, and the CLI aggregate/diff data methods.
- `tests/Ecosystem/test-markup-*.php` — markup core (value objects,
  store, validator, rasterizer), loop/REST (interceptor gate chain,
  per-mode wiring, REST contract, tool-resume seam), telemetry/UI
  (recorder, slash command, admin page, assets). See
  `Markup/README.md`.
- `tests/Ecosystem/test-paper-store-*.php` — paper-store core (driver
  error codes/timestamps/per-mode filesystem seam, index quirk
  preservation, manager traversal guard/security files) and
  repository/query/bootstrap (save defaults, immutable updates,
  index-vs-post-filter clauses, priority-30 hook surface). See
  `PaperStore/README.md`.
- `tests/Ecosystem/test-okf-*.php` — OKF core (parser scalar
  casting/serialization, reader containment/traversal/search/broken
  links, writer validation/soft-delete/logs) and bundle manager
  (resolution/containment, lifecycle, real ZIP round-trips + ZipSlip
  defenses, per-mode generation, priority-32 hook surface). See
  `Okf/README.md`.
- `tests/Ecosystem/test-crawler.php` — crawler registration paths
  (URL gate, defaults/clamps, cron scheduling), tick lock + poll
  outcomes (expiry, retry backoff, permanent failure), storage
  helpers, per-mode seams, trait primitives. See `Crawler/README.md`.

```bash
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-oos-shadow-runner.php
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-markup-core.php
```

Markup suites (also `test-markup-loop-rest.php` and
`test-markup-telemetry-ui.php`) — see `Markup/README.md`. Paper-store
suites (`test-paper-store-core.php` and
`test-paper-store-repository-query.php`) — see `PaperStore/README.md`.
OKF suites (`test-okf-core.php` and `test-okf-bundle-manager.php`) —
see `Okf/README.md`. Crawler suite (`test-crawler.php`) — see
`Crawler/README.md`.

## Also Load

- [`Markup/README.md`](Markup/README.md) — the markup sub-cluster (sub-cluster 2)
- [`PaperStore/README.md`](PaperStore/README.md) — the paper-store sub-cluster (sub-cluster 3)
- [`Okf/README.md`](Okf/README.md) — the OKF sub-cluster (sub-cluster 4)
- [`Crawler/README.md`](Crawler/README.md) — the crawler sub-cluster (sub-cluster 5)
- [`../README.md`](../README.md) — composition root + subsystem index
- [`../CoreBridge.php`](../CoreBridge.php) — the standalone engine seam
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (shadow safety invariants)

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E6 row status
- [`includes/oos/`](../../../../includes/oos/) + [`includes/bootstrap/oos-bridge.php`](../../../../includes/bootstrap/oos-bridge.php) — the base OOS subsystem (the sub-cluster 1 port's origin)
- [`includes/markup/`](../../../../includes/markup/) + [`includes/markup-init.php`](../../../../includes/markup-init.php) — the base markup subsystem (the sub-cluster 2 port's origin)
- [`includes/paper-store/`](../../../../includes/paper-store/) + [`includes/tools/paper-store/`](../../../../includes/tools/paper-store/) — the base paper-store subsystem (the sub-cluster 3 port's origin) and its deferred tool surface
- [`includes/okf/`](../../../../includes/okf/) + [`includes/tools/okf/`](../../../../includes/tools/okf/) — the base OKF subsystem (the sub-cluster 4 port's origin) and its deferred tool surface
- [`includes/crawler/`](../../../../includes/crawler/) + [`includes/traits/trait-wp-mcp-ai-inline-async-tick.php`](../../../../includes/traits/trait-wp-mcp-ai-inline-async-tick.php) — the base crawler subsystem (the sub-cluster 5 port's origin) and its trait collaborator
- `docs/project/proposals/029-oos-orchestration-runtime-consolidation-implementation-plan.md` — Phase 4 gates and kill criteria
