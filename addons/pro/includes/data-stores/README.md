# Pro Data Stores

## Purpose

Implements pluggable storage backends for Pro toolkit entities (CRM contacts, e-commerce products, research entries, etc.), giving every toolkit a single CRUD/query API whether the underlying storage is a JetEngine Custom Content Type (CCT) or a WordPress Custom Post Type (CPT) fallback.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php) via `require_once` (CCT-first, CPT fallback). Every consumer goes through the factory; no caller `require_once`s these files directly |
| **Optional dependencies** | JetEngine (required for the CCT store; the CPT store has no optional dependencies) |

## Public Surface

Both classes implement `WP_MCP_AI_Toolkit_Data_Store` from [`../interfaces/`](../interfaces/). Callers MUST depend on the interface and resolve the concrete class through the factory.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Toolkit_CCT_Store` | `class-wp-mcp-ai-toolkit-cct-store.php` | [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php) (when JetEngine CCT is available) |
| `WP_MCP_AI_Toolkit_CPT_Store` | `class-wp-mcp-ai-toolkit-cpt-store.php` | [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php) (fallback when JetEngine CCT is unavailable) |

Downstream consumers (always through the factory): [`../admin/class-wp-mcp-ai-consolidate-add-base.php`](../admin/class-wp-mcp-ai-consolidate-add-base.php), [`../admin/class-wp-mcp-ai-research-add-base.php`](../admin/class-wp-mcp-ai-research-add-base.php), [`../admin/class-wp-mcp-ai-crm-settings-page.php`](../admin/class-wp-mcp-ai-crm-settings-page.php), [`../class-wp-mcp-ai-pro-toolkit-shortcodes.php`](../class-wp-mcp-ai-pro-toolkit-shortcodes.php), CRM / consolidate-add / research-add tools in [`../tools/`](../tools/).

## Inputs / Outputs / Neighbors

- **Reads from:** `$args` query / payload arrays from the caller, JetEngine CCT module registry (CCT store), `WP_Post` + post meta (CPT store), field-schema arrays supplied by each toolkit, the constructor-injected toolkit slug + entity-type pair.
- **Writes to:** the JetEngine CCT table for the resolved CCT slug (CCT store), the `posts` + `postmeta` tables for the resolved CPT (CPT store). Returns `int` IDs on create, `array` payloads on read, `bool` on update/delete, `WP_Error` on failure.
- **Upstream callers:** the factory in [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php). Pro tools and admin pages obtain a store via the factory, never by `new`-ing these classes directly.
- **Downstream collaborators:** [`../interfaces/interface-wp-mcp-ai-toolkit-data-store.php`](../interfaces/interface-wp-mcp-ai-toolkit-data-store.php) (the contract), JetEngine (CCT only), WordPress core post/meta APIs (CPT only), [`../class-wp-mcp-ai-pro-cpt-meta-schema.php`](../class-wp-mcp-ai-pro-cpt-meta-schema.php) for CPT-side schema resolution.
- **Events fired:** none beyond WordPress core (`save_post_*`, `delete_post`, etc.) emitted by the CPT path.
- **Events listened to:** none directly — schema and routing decisions are passed in by the factory and by toolkit init files.

## Conventions

Folder-specific deltas (canonical rules in [`../../../../.context/conventions.md`](../../../../.context/conventions.md) and [`../../../../.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md)):

- Both stores MUST implement the **complete** `WP_MCP_AI_Toolkit_Data_Store` contract — partial implementations break the factory's substitutability guarantee. Add new methods to the interface first, then both stores.
- All public methods return a positive shape on success or `WP_Error` on failure — **never** `false` or a plain array with a `success => false` key. This composes with Base's canonical tool envelope.
- Caller-supplied query / payload data is treated as already-sanitised by the contract; each store still applies WordPress-level escaping (`$wpdb->prepare`, `sanitize_meta`, etc.) at the storage seam.
- New backends (e.g. custom-tables, remote API) go in this folder, implement the same interface, and are surfaced through the factory — never as a parallel hierarchy.
- Field schema is **passed in**, not duplicated here. CCT-vs-CPT schema mapping (column ↔ post-meta) lives alongside the entity's toolkit init, not in this folder.
- Capability checks and nonce verification belong to the caller (REST controller, admin AJAX, tool); these stores assume an authorised request.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-cross-toolkit-mounts.php
vendor/bin/phpunit tests/test-jetengine-data-stores-activation.php
vendor/bin/phpunit tests/test-jetengine-pro-tool-cct-crud.php
vendor/bin/phpunit tests/test-cpt-cct-sync.php
```

There is no standalone `test-toolkit-cct-store.php` / `test-toolkit-cpt-store.php`; coverage is integration-style through the toolkit + JetEngine-CCT activation suites. When adding a method to the interface, extend `test-jetengine-pro-tool-cct-crud.php` to exercise both backends.

## Also Load

- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — sanitisation at the storage seam (always)
- [`../../../../.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Base/Pro placement rules
- [`../../../../CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat, canonical envelope (success array or `WP_Error`)
- [`../../../../docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../../../docs/project/architecture-decisions/ADR_001_module_boundaries.md) — adapter layering rationale

## See Also

- Upstream parent: [`../`](../) (Pro `includes/`)
- Contract: [`../interfaces/`](../interfaces/) — `WP_MCP_AI_Toolkit_Data_Store`
- Factory: [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php)
- CPT meta schema helper: [`../class-wp-mcp-ai-pro-cpt-meta-schema.php`](../class-wp-mcp-ai-pro-cpt-meta-schema.php)
- Sibling folders: [`../services/`](../services/), [`../tools/`](../tools/), [`../migrations/`](../migrations/)
