# Research & Add

## Purpose

Houses the per-toolkit "Research & Add" admin UI implementations — each subclass of `WP_MCP_AI_Research_Add_Base` wires one Pro toolkit's CPTs/CCTs (entities, field schemas, AI-assisted entry creation, bulk import, document processing) into the shared Research & Add admin workflow.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Each toolkit's own `*-toolkit-init.php` (e.g. `crm-toolkit-init.php`, `media-toolkit-init.php`, `project-management-init.php`, `video-production-toolkit-init.php`) `require_once`s the matching class and `new`s it. Auto-discovery fallback in `WP_MCP_AI_Toolkit_Settings_Base::render_research_add_ui()` resolves a class file by `toolkit_slug` when the Settings page Research tab is rendered. |
| **Optional dependencies** | JetEngine (the `jetengine-cpt-research-add` / `jetengine-taxonomy-research-add` variants are only meaningful when JetEngine CCT/taxonomy APIs are available); the document-generation and image-production variants assume their respective toolkits are enabled. |

## Public Surface

All classes in this folder are concrete subclasses of [`WP_MCP_AI_Research_Add_Base`](../admin/class-wp-mcp-ai-research-add-base.php). Per the project naming convention (see [`.context/conventions.md`](../../../../.context/conventions.md)), each is named after its toolkit (e.g. `WP_MCP_AI_CRM_Research_Add`) and lives in `class-wp-mcp-ai-<toolkit-slug-dasherised>-research-add.php`.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_AI_Tool_Builder_Research_Add` | `class-wp-mcp-ai-ai-tool-builder-research-add.php` | AI tool builder toolkit init |
| `WP_MCP_AI_Architectural_Design_Research_Add` | `class-wp-mcp-ai-architectural-design-research-add.php` | Architectural design toolkit init |
| `WP_MCP_AI_Calendar_Booking_Research_Add` | `class-wp-mcp-ai-calendar-booking-research-add.php` | Calendar booking settings page (Research tab) |
| `WP_MCP_AI_CRM_Research_Add` | `class-wp-mcp-ai-crm-research-add.php` | CRM toolkit init |
| `WP_MCP_AI_DJ_Management_Research_Add` | `class-wp-mcp-ai-dj-management-research-add.php` | DJ management toolkit init |
| `WP_MCP_AI_Document_Generation_Research_Add` | `class-wp-mcp-ai-document-generation-research-add.php` | Document generation toolkit init |
| `WP_MCP_AI_Ecommerce_Research_Add` | `class-wp-mcp-ai-ecommerce-research-add.php` | E-commerce toolkit init |
| `WP_MCP_AI_Financial_Planner_Research_Add` | `class-wp-mcp-ai-financial-planner-research-add.php` | Financial planner toolkit init |
| `WP_MCP_AI_Image_Production_Research_Add` | `class-wp-mcp-ai-image-production-research-add.php` | Image production toolkit init |
| `WP_MCP_AI_JetEngine_CPT_Research_Add` | `class-wp-mcp-ai-jetengine-cpt-research-add.php` | `jetengine-cpt-research-init.php` (one instance per discovered CPT) |
| `WP_MCP_AI_JetEngine_Taxonomy_Research_Add` | `class-wp-mcp-ai-jetengine-taxonomy-research-add.php` | `jetengine-cpt-research-init.php` (per discovered taxonomy) |
| `WP_MCP_AI_Media_Research_Add` | `class-wp-mcp-ai-media-research-add.php` | Media toolkit init |
| `WP_MCP_AI_Multilingual_Research_Add` | `class-wp-mcp-ai-multilingual-research-add.php` | Multilingual toolkit init |
| `WP_MCP_AI_Project_Management_Research_Add` | `class-wp-mcp-ai-project-management-research-add.php` | Project management init |
| `WP_MCP_AI_Social_Media_Research_Add` | `class-wp-mcp-ai-social-media-research-add.php` | Social media toolkit init |
| `WP_MCP_AI_Video_Production_Research_Add` | `class-wp-mcp-ai-video-production-research-add.php` | Video production toolkit init |

Each class overrides `get_entity_types()` and (optionally) `filter_cpt_field_schema()` / `filter_cct_field_schema()` to declare its entity surface. The base class owns rendering, AJAX, nonce handling, and form submission.

## Inputs / Outputs / Neighbors

- **Reads from:** the toolkit data stores resolved through `WP_MCP_AI_Toolkit_Data_Store_Factory`, CPT/CCT field schemas, `$_GET['entity']` for the active tab, and per-toolkit settings options.
- **Writes to:** the toolkit's CPT / JetEngine CCT records (via the data stores), through nonce-protected POST handlers and `wp_ajax_wp_mcp_ai_research_*` AJAX endpoints (`add_item`, `delete_item`, `get_item`).
- **Upstream callers:** each toolkit's `*-toolkit-init.php` (direct instantiation) and `WP_MCP_AI_Toolkit_Settings_Base::render_research_add_ui()` (filename-convention auto-discovery).
- **Downstream collaborators:** [`../admin/class-wp-mcp-ai-research-add-base.php`](../admin/class-wp-mcp-ai-research-add-base.php) (parent class), [`../data-stores/`](../data-stores/) (storage abstraction), [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php), the relevant toolkit CPT classes.
- **Events fired:** the filters `wp_mcp_ai_toolkit_cpt_field_schema` and `wp_mcp_ai_toolkit_cct_field_schema` (each subclass attaches its own callback to shape fields for its toolkit).
- **Events listened to:** `wp_ajax_wp_mcp_ai_research_add_item`, `wp_ajax_wp_mcp_ai_research_delete_item`, `wp_ajax_wp_mcp_ai_research_get_item` (registered by the base class).

## Conventions

- Filename and class name must follow the per-toolkit naming convention (see [`.context/conventions.md`](../../../../.context/conventions.md)) — the auto-discovery in `WP_MCP_AI_Toolkit_Settings_Base::render_research_add_ui()` derives both from the toolkit slug, so any deviation makes the class invisible to the Settings shell.
- Constructors must call `parent::__construct( '{toolkit_slug}' )` with the canonical snake_case toolkit slug (matching `enable_{slug}_toolkit` settings keys).
- Field-schema shaping must use the two filters listed above — do not bypass them by writing meta directly.
- Nonces and capability checks live in the base class; subclasses must not duplicate or weaken them.
- Keep one toolkit per file — splitting a toolkit's entity types across multiple files breaks auto-discovery.

## Tests

There is no dedicated PHPUnit slice for the Research & Add layer. Related coverage is in `addons/pro/tests/test-ai-cpt-management-integration.php` and the per-toolkit research-page tests; the base class's AJAX surface remains a documented gap.

```bash
vendor/bin/phpunit addons/pro/tests/test-ai-cpt-management-integration.php
```

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — nonces, capability checks, AJAX hygiene
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro-only feature gating
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat policy, tool sanitisation rules

## See Also

- Parent class: [`../admin/class-wp-mcp-ai-research-add-base.php`](../admin/class-wp-mcp-ai-research-add-base.php)
- Settings shell that auto-discovers this folder: [`../admin/class-wp-mcp-ai-toolkit-settings-base.php`](../admin/class-wp-mcp-ai-toolkit-settings-base.php)
- JetEngine CPT/taxonomy registrar: [`../jetengine-cpt-research-init.php`](../jetengine-cpt-research-init.php)
- Storage layer: [`../data-stores/`](../data-stores/), [`../class-wp-mcp-ai-toolkit-data-store-factory.php`](../class-wp-mcp-ai-toolkit-data-store-factory.php)
