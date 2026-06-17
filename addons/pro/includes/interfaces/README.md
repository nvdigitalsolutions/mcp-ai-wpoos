# Pro Interfaces

## Purpose

Holds Pro-only PHP `interface` declarations — pure contracts (no implementation) that Pro services, tools, and data stores depend on so swappable backends (CCT vs CPT, vector stores, ERP connectors, …) can be wired without coupling callers to concrete classes.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | On-demand `require_once` from each implementer (e.g. [`../data-stores/class-wp-mcp-ai-toolkit-cct-store.php`](../data-stores/class-wp-mcp-ai-toolkit-cct-store.php), [`../data-stores/class-wp-mcp-ai-toolkit-cpt-store.php`](../data-stores/class-wp-mcp-ai-toolkit-cpt-store.php), and the factory [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php)) |
| **Optional dependencies** | none — pure contracts |

## Public Surface

Every file in this folder is part of the public surface; the whole point is to be depended on. Concrete implementations live in [`../data-stores/`](../data-stores/), [`../services/`](../services/), and [`../`](../) (root Pro `includes/`).

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Toolkit_Data_Store` | `interface-wp-mcp-ai-toolkit-data-store.php` | [`../data-stores/`](../data-stores/) (CCT + CPT stores), [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php), [`../admin/class-wp-mcp-ai-consolidate-add-base.php`](../admin/class-wp-mcp-ai-consolidate-add-base.php), [`../admin/class-wp-mcp-ai-research-add-base.php`](../admin/class-wp-mcp-ai-research-add-base.php), [`../class-wp-mcp-ai-pro-toolkit-shortcodes.php`](../class-wp-mcp-ai-pro-toolkit-shortcodes.php), CRM / research-add / consolidate-add tools in [`../tools/`](../tools/) |

The ERP-connector contract for the Pro-only `WP_MCP_AI_ERP_Ezuite` adapter lives at [`../interface-wp-mcp-ai-erp-connector.php`](../interface-wp-mcp-ai-erp-connector.php) (root `addons/pro/includes/`, not in this folder). New Pro interfaces should be added here rather than at the root.

## Inputs / Outputs / Neighbors

- **Reads from:** nothing — contracts only.
- **Writes to:** nothing.
- **Upstream callers:** the factory in [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php) and every toolkit caller that type-hints `WP_MCP_AI_Toolkit_Data_Store` (admin Research-Add / Consolidate-Add base classes, Pro toolkit shortcodes, CRM / e-commerce / research-add tools).
- **Downstream collaborators:** [`../data-stores/`](../data-stores/) supplies the canonical CCT and CPT implementations.
- **Events fired:** none.
- **Events listened to:** none.

## Conventions

- Files contain **only** `interface` declarations and PHPDoc. No method bodies, no constants beyond what an interface legally allows, no `use` of traits, no static state.
- **No** WordPress API calls anywhere in this folder — the whole point is for [`../data-stores/`](../data-stores/) and other adapters to adapt these contracts to WordPress.
- Naming follows Base's accepted forms (see [`../../../../includes/interfaces/README.md`](../../../../includes/interfaces/README.md)). The current Pro contract uses the unsuffixed form (`WP_MCP_AI_Toolkit_Data_Store`) because it predates the `Interface_` prefix convention; match neighbours when adding a new contract rather than renaming.
- Return shapes that may fail must declare `int|WP_Error`, `bool|WP_Error`, or `array|WP_Error` — Pro contracts compose with Base's canonical tool envelope.

## Tests

Interfaces themselves are not unit-tested; their contracts are exercised through the implementers:

```bash
vendor/bin/phpunit addons/pro/tests/test-cross-toolkit-mounts.php
vendor/bin/phpunit tests/test-jetengine-data-stores-activation.php
vendor/bin/phpunit tests/test-jetengine-pro-tool-cct-crud.php
```

No standalone `test-toolkit-data-store-interface.php` exists — coverage is intentionally indirect via the CCT/CPT store tests and the toolkit integration suites.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — input/output rules implementers must satisfy
- [`../../../../.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Base/Pro placement rules
- [`../../../../CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat, canonical tool envelope
- [`../../../../docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../../../docs/project/architecture-decisions/ADR_001_module_boundaries.md) — why these contracts exist

## See Also

- Upstream parent: [`../`](../) (Pro `includes/`)
- Base counterpart: [`../../../../includes/interfaces/`](../../../../includes/interfaces/) — naming forms + ADR-001 rationale
- Canonical implementers: [`../data-stores/`](../data-stores/)
- Sibling folders: [`../services/`](../services/), [`../providers/`](../providers/), [`../migrations/`](../migrations/)
