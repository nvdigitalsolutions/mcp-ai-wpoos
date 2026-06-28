# Domain

## Purpose

Holds the plugin's pure-PHP domain layer — value objects and constant catalogues (multi-agent patterns, risk levels, toolkit slugs) that encode policy without touching WordPress, the database, the filesystem, or the network.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` and on-demand via `class_exists()`-driven consumers |
| **Optional dependencies** | none (this is the leaf layer) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Pattern_Constants` (8 multi-agent pattern slugs) | `class-wp-mcp-ai-pattern-constants.php` | `includes/class-wp-mcp-ai-pattern-registry.php`, `includes/class-wp-mcp-ai-pattern-workflow-templates.php`, `agents/`, `teams/` |
| `WP_MCP_AI_Risk_Level_Constants` (`RISK_INFO`, `RISK_STANDARD`, `RISK_DESTRUCTIVE`, `RISK_IRREVERSIBLE`) | `class-wp-mcp-ai-risk-level-constants.php` | `tools/`, `services/class-wp-mcp-ai-tool-execution-orchestrator.php`, capability gates, `Necessity_Gate` |
| `WP_MCP_AI_Action_Safety_Profile` (irreversibility scores 0.0–1.0, necessity levels, gating verdicts, decision matrix) | `class-wp-mcp-ai-action-safety-profile.php` | `harness/class-wp-mcp-ai-necessity-gate.php`, tool metadata, safety annotations |
| `WP_MCP_AI_Toolkit_Constants` (toolkit slugs) | `class-wp-mcp-ai-toolkit-constants.php` | `includes/class-wp-mcp-ai-toolkit-enhancement-integration.php`, slash commands, profession service |

## Inputs / Outputs / Neighbors

- **Reads from:** nothing — the domain layer is pure.
- **Writes to:** nothing.
- **Upstream callers:** `services/`, `tools/`, `agents/`, `teams/`, `slash-commands/`, `professions/`, the pattern registry, and the toolkit enhancement integration.
- **Downstream collaborators:** none. Domain code must remain a leaf — it depends on nothing else inside `includes/`.
- **Events fired:** none.
- **Events listened to:** none.

## Conventions

- **Zero direct WordPress API calls.** Any options API, capability check, `$wpdb` access, or filesystem call in this folder is a defect. See [`docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../docs/project/architecture-decisions/ADR_001_module_boundaries.md) — the `WordPress.WP.GlobalVariablesOverride` PHPCS rule in [`phpcs.xml.dist`](../../phpcs.xml.dist) enforces the global-variable side of this.
- **No I/O of any kind** — no `file_*`, no `curl_*`, no `wp_remote_*`. If you need to load data from disk or HTTP, do it in `infrastructure/` and inject the result.
- Files in this folder are **constant catalogues and value objects only**. Classes here must be safe to instantiate (or use statically) at any point in the request lifecycle, including before `plugins_loaded`.
- Domain classes should be referenced via their fully-qualified class constants (`WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR`) rather than re-declared as raw strings in consumers.

## Tests

```bash
vendor/bin/phpunit tests/test-pattern-registry.php
vendor/bin/phpunit tests/test-pattern-workflow-templates.php
vendor/bin/phpunit tests/test-toolkit-constants.php
vendor/bin/phpunit tests/test-toolkit-registry.php
```

Risk-level constants are exercised indirectly via every tool execution test (capability + risk gate is invoked from the orchestrator).

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — even pure code must define safe defaults
- [`docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../docs/project/architecture-decisions/ADR_001_module_boundaries.md) — the rule that keeps this folder pure
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — where risk levels surface to the agent runtime

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders: [`interfaces/`](../interfaces/) (pure contracts that may reference domain types), [`infrastructure/`](../infrastructure/) (the layer permitted to call WordPress), [`repositories/`](../repositories/)
