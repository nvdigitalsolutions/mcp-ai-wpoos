# Security

## Purpose

Security infrastructure ported from the base plugin's `includes/security/`
stack into the content-graph AI addon (Wave D4): provider circuit breaker,
encrypted API key store, security audit logger, request guard (SSE slots,
body/JSON-depth limits, error verbosity), SSRF URL guard, security posture
scoring, concurrency guard + subscriber, cost tracker + subscriber,
destructive operations gate, CSP headers, and the REST load guard.

## Tier

| | |
|---|---|
| **Distribution** | Addon plugin (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **License** | Proprietary (commercial license required) |
| **Loaded by** | `Plugin::register()` (standalone-only hook registration for the runtime guards); static utilities are called by consumers directly |
| **Optional dependencies** | base plugin classes via documented seams (monolith installs only): `WP_MCP_AI_Encryption`, `WP_MCP_AI_Settings_Registry`, `WP_MCP_AI_Resource_Manager`, `WP_MCP_AI_SSE_Rate_Limiter`, `WP_MCP_AI_HTTP_Helper`, `WP_MCP_AI_Job_Queue_Manager`, `WP_MCP_AI_Async_Job_Queue`, `WP_MCP_AI_Restriction_Registry` |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Security\ProviderCircuitBreaker` | `ProviderCircuitBreaker.php` | Provider layer (dormant until providers adopt it) |
| `NvoosContentGraphAi\Security\ApiKeyStore` | `ApiKeyStore.php` | Legacy `wp_mcp_ai_*` key options (tools wave) |
| `NvoosContentGraphAi\Security\SecurityAuditLogger` | `SecurityAuditLogger.php` | `Plugin::register()` (standalone-only REST route + purge cron); `DestructiveOpsGate` |
| `NvoosContentGraphAi\Security\RequestGuard` | `RequestGuard.php` | `Plugin::register()` (standalone-only REST/SSE hooks) |
| `NvoosContentGraphAi\Security\UrlGuard` | `UrlGuard.php` | Outbound-HTTP consumers (MCP probing, webhooks) |
| `NvoosContentGraphAi\Security\SecurityPosture` | `SecurityPosture.php` | Security Center UI (later wave) |
| `NvoosContentGraphAi\Security\ConcurrencyGuard` | `ConcurrencyGuard.php` | `ConcurrencyGuardSubscriber`, tools wave |
| `NvoosContentGraphAi\Security\ConcurrencyGuardSubscriber` | `ConcurrencyGuardSubscriber.php` | `Plugin::register()` (standalone-only) |
| `NvoosContentGraphAi\Security\CostTracker` | `CostTracker.php` | `CostTrackerSubscriber`, analytics consumers |
| `NvoosContentGraphAi\Security\CostTrackerSubscriber` | `CostTrackerSubscriber.php` | `Plugin::register()` (standalone-only) |
| `NvoosContentGraphAi\Security\DestructiveOpsGate` | `DestructiveOpsGate.php` | `Plugin::register()` (standalone-only) |
| `NvoosContentGraphAi\Security\CspHeaders` | `CspHeaders.php` | `Plugin::register()` (standalone-only) |
| `NvoosContentGraphAi\Security\LoadGuard` | `LoadGuard.php` | `Plugin::register()` (standalone-only) |
| `NvoosContentGraphAi\Security\Exceptions\{ConcurrencyLimitReached,CostBudgetExceeded,DestructiveConfirmationRequired}` | `Exceptions/` | Subscriber/gate rejection flow → REST 429/428 envelopes |

## Inputs / Outputs / Neighbors

- **Reads from:** settings (base settings repository monolith / `nvoos_content_graph_settings` standalone), transients, custom tables (`wp_mcp_ai_security_log`, `mcp_ai_concurrency_slots`, `mcp_ai_job_queue` monolith), user meta (`wp_mcp_ai_hourly_budget`), options (`wp_mcp_ai_cost_tracker_spend`, `wp_mcp_ai_*_api_key` legacy namespace)
- **Writes to:** the same stores (audit rows, slot counters, spend records, encrypted key options)
- **Upstream callers:** `Plugin::register()` (standalone-only registration), tool-execution pipeline, REST controllers
- **Downstream collaborators:** `CredentialStore` (CG-AI provider keys — separate namespace from `ApiKeyStore`), `SseRateLimiter` (D1b, standalone SSE slot delegation), ported analytics stack (D3)
- **Events fired:** `wp_mcp_ai_security_event`, `wp_mcp_ai_destructive_gate_rejected`, `wp_mcp_ai_per_session_limit_exceeded` (via other waves)
- **Events listened to:** `wp_mcp_ai_before_tool_execution`, `wp_mcp_ai_after_tool_execution`, `wp_mcp_ai_sse_stream_*`, `wp_mcp_ai_purge_security_events`, `rest_pre_dispatch`, `rest_post_dispatch`, `admin_init` (all standalone-only)

## Conventions

- Every class is a behaviour-preserving 1:1 port of its base counterpart
  (D-NOBASE: base copies retained permanently). Option/meta/table names,
  hook names, priorities, error codes, and envelopes are byte-identical.
- Seams gate on `defined( 'WP_MCP_AI_PATH' )` — never bare `class_exists`
  (the monorepo classmap makes base classes resolvable standalone).
- Registration methods (`register()`, subscribers) are wired
  standalone-only in `Plugin.php`; in monolith installs the base plugin
  owns the same hooks, tables, routes, and crons.
- Documented deviations: `src/Security/Exceptions/` mirrors
  `includes/exceptions/`; `ApiKeyStore` uses the parent `Remote\Crypto`
  standalone; the workload/queue integrations degrade to documented
  defaults standalone (see the ecosystem port tracker).

## Also Load

- [`../README.md`](../README.md) — plugin source map
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — security conventions
- [`.context/conventions.md`](../../../../.context/conventions.md) — coding conventions
