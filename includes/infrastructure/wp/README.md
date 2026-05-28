# WP

## Purpose

Houses the two WordPress core-API adapters — capability checker and options store — that implement `Interface_WP_MCP_AI_Capability_Checker` and `Interface_WP_MCP_AI_Options_Store` so higher layers can consume WordPress state through injectable contracts instead of direct global function calls.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | DI container via `includes/class-wp-mcp-ai-container.php` |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_WP_Capability_Checker` | `class-wp-mcp-ai-wp-capability-checker.php` | DI container → tools / REST permission callbacks |
| `WP_MCP_AI_WP_Options_Store` | `class-wp-mcp-ai-wp-options-store.php` | DI container → `services/`, `repositories/` |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress `current_user_can()`, `user_can()`, `get_option()`.
- **Writes to:** WordPress `update_option()`, `delete_option()`.
- **Upstream callers:** `services/` (every service needing WordPress side effects), `repositories/`, `rest/` permission callbacks, `tools/`.
- **Downstream collaborators:** WordPress core API functions — this folder's job is to be the single seam that calls them.
- **Events fired:** none.
- **Events listened to:** none (imperative invocation).

## Conventions

- Exactly two classes, each mapping to one interface from `includes/interfaces/`:
  - `WP_MCP_AI_WP_Capability_Checker` → `Interface_WP_MCP_AI_Capability_Checker` (`current_user_can`, `user_can`).
  - `WP_MCP_AI_WP_Options_Store` → `Interface_WP_MCP_AI_Options_Store` (`get`, `update`, `delete`).
- Direct WordPress API calls are expected — this is the designated WordPress adapter folder.
- Construction happens through the DI container only. Higher layers receive these as injected dependencies so tests can substitute mock implementations.

## Tests

```bash
vendor/bin/phpunit tests/test-wp-options-store.php
```

Capability checker coverage is included in tool-execution and REST permission tests.

## Also Load

- [`.context/conventions.md`](../../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../../.context/security-checklist.md) — capability checks, option sanitisation (always)
- Parent folder: [`includes/infrastructure/README.md`](../README.md) — full infrastructure layer overview

## See Also

- Upstream parent: [`includes/infrastructure/`](../) — infrastructure adapters layer
- Interfaces: [`includes/interfaces/interface-wp-mcp-ai-capability-checker.php`](../../interfaces/interface-wp-mcp-ai-capability-checker.php), [`includes/interfaces/interface-wp-mcp-ai-options-store.php`](../../interfaces/interface-wp-mcp-ai-options-store.php)
- DI wiring: [`includes/class-wp-mcp-ai-container.php`](../../class-wp-mcp-ai-container.php)
