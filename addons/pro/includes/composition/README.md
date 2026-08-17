# Composition (Pro) — Scoped Assistant Compositions + composeFrom Child Binding

## Purpose

Delivers the Pro half of Proposal 029 Phase 5 (R4): a read-only composition service that resolves an assistant's **effective configuration** — visible tool slugs after allow/deny restriction intersection, prompt sections, guard slugs, and provider route — into a deterministic `generation_id`, and lets delegated child agents **bind to their parent's exact composition generation** (`composeFrom` semantics) instead of re-resolving their own toolset from scratch.

This fixes the correctness gap where a child agent (`delegate_to_agent`, `spawn_agent`) could resolve a different toolset than the one its parent's chat history was produced under. Everything here is flag-gated and default-off; no request path changes until Phase 4.2 canary.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ (hard requirement of `lib/core`) |
| **Loaded by** | `WP_MCP_AI_Pro_Module_Registry` module `oos_composition` (flag-gated: `enable_oos_composition` setting or `wp_mcp_ai_pro_enable_oos_composition` filter). The CLI command and tests require the class files directly and do not depend on the gate |
| **Optional dependencies** | `lib/core` (`Nvoos\Core` ToolScope/ToolRestriction/ToolResolverInterface via `includes/bootstrap/oos-bridge.php`) — absent → degraded composeFrom mode approximates the parent toolset from its restriction inputs |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Pro_Composition` | `class-wp-mcp-ai-pro-composition.php` | Value object: generation id, provenance chain, allow/deny/guard inputs, provider route, prompt sections, `to_array()` effective dump |
| `WP_MCP_AI_Pro_Composition_Service` | `class-wp-mcp-ai-pro-composition-service.php` | `compose()`, `compose_from()`, `effective()`, `assert_same_generation()`, static `generation_id()` |
| `WP_MCP_AI_Pro_Legacy_Tool_Resolver` | `class-wp-mcp-ai-pro-legacy-tool-resolver.php` | `ToolResolverInterface` over the WP-side `WP_MCP_AI_Tool_Registry` (duck-typed via Phase 1 `LegacyToolAdapter`) |
| `wp_mcp_ai_pro_compose()` / `wp_mcp_ai_pro_compose_from()` | `composition-init.php` | Flag-gated helper surface registered at boot when enabled |
| `WP_MCP_AI_Pro_CLI_Composition_Command` → `wp mcp-ai composition` | `../cli/class-wp-mcp-ai-pro-cli-composition-command.php` | `dump <assistant_id> [--json]`, `verify <assistant_id> [--against=<generation_id>]` |

Stable contracts: meta keys `_wp_mcp_ai_tools` (allow), `_wp_mcp_ai_denied_tools` (deny, new), `_wp_mcp_ai_guard_slugs` (guards, new), `_wp_mcp_ai_system_prompt`, `_wp_mcp_ai_provider`, `_wp_mcp_ai_model`; generation id format `gen_` + 20 hex chars; filters `wp_mcp_ai_pro_composition_config`, `wp_mcp_ai_pro_compose_from_overrides`.

## Inputs / Outputs / Neighbors

- **Reads from:** assistant post meta (see stable contracts above), the legacy `WP_MCP_AI_Tool_Registry` via `WP_MCP_AI_Pro_Legacy_Tool_Resolver`, `lib/core` scope classes through the OOS bridge autoloader.
- **Writes to:** nothing — the service is read-only by design (no meta writes, no request-state mutation). Generations are fingerprints of inputs, not persisted state.
- **Upstream callers:** `wp mcp-ai composition` CLI (Proposal 029, 5.3), tests; future consumers are the team-orchestrator delegation path (Phase 5.2 wiring, gated behind `wp_mcp_ai_pro_compose_from_overrides`) and the chat UI's plan/approval surface.
- **Downstream collaborators:** `Nvoos\Core\Application\Tool\ToolScope` (Phase 5.1 — including the seed-universe branch added for non-enumerable resolvers like `WP_MCP_AI_Pro_Legacy_Tool_Resolver`), `Nvoos\Core\Domain\ValueObject\ToolRestriction`, `Nvoos\WordPress\Tool\LegacyToolAdapter` (Phase 1).
- **Events fired:** none.
- **Events listened to:** none — filters only (`wp_mcp_ai_pro_composition_config`, `wp_mcp_ai_pro_compose_from_overrides`).

## Conventions

- **Read-only.** `compose()` / `compose_from()` must never write meta or options. Anything that needs persistence (plan-mode todos, steering inboxes — Proposal 029 5.4/5.5) belongs in the session log + Action Scheduler, not here.
- **Deterministic generations.** Every input to `generation_id()` must be canonicalized (sorted) before hashing. Never feed raw meta arrays — always through `normalize_slugs()`.
- **`composeFrom` narrows, never widens.** A bound child sees the parent's exact visible set intersected with its own restriction inputs; child scope-local registrations (`local_tools` override) are the only way to add tools, and they shadow, not merge. Preserve this property — it is the correctness guarantee.
- **`normalize_slugs()` accepts three storage shapes** (array, PHP-serialized string, JSON string) because registered array metas (`_wp_mcp_ai_tools`, `type: array`) may read back as JSON in some environments and as arrays in others. Do not assume a single shape.
- **Flag-gate anything boot-time.** New behavior registers behind `wp_mcp_ai_pro_composition_enabled()`; the service itself may be constructed directly (CLI/tests).
- **The legacy resolver is a stopgap.** When the OOS `ToolRegistry` owns the tool surface (Phase 6), swap the resolver default to the registry — the service API must not change.

## Tests

```bash
vendor/bin/phpunit --bootstrap tests/bootstrap.php addons/pro/tests/test-oos-composition-service.php
vendor/bin/phpunit -c lib/core/phpunit.xml.dist lib/core/tests/Unit/Tool/ToolScopeTest.php
```

Suite file: `test-oos-composition-service.php` (8 tests). The core `ToolScopeTest` covers the seed-universe branch added for non-enumerable resolvers.

## Also Load

- [`lib/core/src/Application/Tool/README.md`](../../../../lib/core/src/Application/Tool/README.md) — ToolScope / ToolRegistry / ToolRestriction semantics
- [Proposal 029](../../../../docs/project/proposals/029-oos-orchestration-runtime-consolidation-implementation-plan.md) — Phase 5.2/5.3 requirements
