# Proposal: Pro Module Registry + PSR-4 Autoload

**Status:** Implemented
**Date:** 2026-07-31
**Author:** AI Agent (Zed)
**Risk:** Medium

## Problem Statement

### 1. Monolithic Init Function

`wp_mcp_ai_pro_init()` in `addons/pro/mcp-ai-wpoos-pro.php` (lines 351–974) is a ~625-line monolithic function containing ~79 discrete `require_once` blocks plus `add_action`/`add_filter` calls. This causes:

- **Maintenance burden:** Adding a new toolkit requires finding the right insertion point in a 625-line function.
- **Fragile load order:** Dependencies between subsystems are implicit (insertion order). A reorder breaks things silently.
- **No discoverability:** A developer cannot answer "what Pro subsystems exist?" without reading the entire function.
- **Mixed concerns:** Conditional loading (`is_admin()`, `enable_*` settings), file loading, and hook registration are interleaved.

### 2. No PSR-4 Autoload for Pro Classes

The Pro addon (~1,766 PHP files) loads every class via explicit `require_once` with hardcoded `WP_MCP_AI_PRO_PATH` paths. This causes:

- **IDE dead zone:** No autocomplete, no jump-to-definition, no refactoring support.
- **File path typos:** `require_once` strings are not validated until runtime.
- **Load-order fragility:** A `require_once` missing its dependency causes a fatal error.
- **Boilerplate:** Every `init.php` file repeats `require_once WP_MCP_AI_PRO_PATH . 'includes/...'` patterns.

## Proposed Solution

### Part 1: Module Registry

Introduce `WP_MCP_AI_Pro_Module_Registry` — a singleton that:

1. **Defines** all ~79 Pro subsystems as lightweight module descriptors (callable factories).
2. **Validates** dependencies via explicit `deps` arrays and `requires` class/function checks.
3. **Resolves** load order via topological sort (tier-based).
4. **Gates** modules by context (`is_admin()`, `REST_REQUEST`) and enable flags (`$settings['enable_*']`).
5. **Boots** all eligible modules in correct order with a single `boot()` call.

**Design decisions:**
- Callable factories (Pattern B) for existing subsystems — zero new classes required during migration.
- `WP_MCP_AI_Pro_Module_Interface` (Pattern A) for future subsystems.
- Existing `init.php` files are preserved as-is; the registry simply calls them in the right order.
- The `admin_sections` mega-function (`wp_mcp_ai_pro_load_admin_sections()`) is treated as one module.

### Part 2: PSR-4 Autoload (Phase A — classmap)

Add to `addons/pro/composer.json`:

```json
{
    "autoload": {
        "classmap": ["includes/"]
    }
}
```

This is Phase A (immediate, zero file renames):
- `composer dump-autoload` generates a static classmap of all 1,766 files.
- `require_once` calls in `wp_mcp_ai_pro_init()` become redundant and can be removed incrementally.
- Future phases (B-D) add PSR-4 for new files and gradually migrate existing files.

## Implementation Summary

| Component | Lines Before | Lines After | Delta |
|-----------|-------------|-------------|-------|
| `wp_mcp_ai_pro_init()` | ~625 | ~35 | -590 |
| `WP_MCP_AI_Pro_Module_Registry` | 0 | ~450 | +450 |
| `addons/pro/composer.json` | 30 | 36 | +6 |
| **Net** | | | **-134** |

## Module Inventory

79 modules organized in 7 dependency tiers:

| Tier | Count | Examples |
|------|-------|----------|
| T1 Infrastructure | 7 | NPM integration, CDN loader, JetEngine meta helper, CPT meta schema, Privacy, Schedule Manager, MCP Servers framework |
| T2 Utility classes | 3 | Product Type Helper, Remote Connection, ERP interface |
| T3 CPT subsystems | 3 | Maintenance, Incidents, Content Format Templates |
| T4 Admin sections | 5 | Admin sections, Remote Sites, Toolkit MCP Servers, Blueprints, AI CPT management |
| T5 REST + UI | 7 | SPA bootstrap, Tool Shortcuts, Slash Commands, SPA Loader, Inline Assistant, Parallel Dispatcher, Collaborative Presence |
| T6 Toolkits | 39 | Media, Project Management, Places, ECA, Quiz, Healthcare, Calendar Booking, E-commerce, + 31 more |
| T7 Bridges | 15 | Booking Adapters, Skills Manager, Harness, Services Phase 6, NV Cloud, Paper Store, Workflow Bridge, Chat Notifier, Toolkit Integration, Measurement, PARA, QMS, Slash Commands, Document Generation, Vault |

## Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| Load order regression | Explicit `deps` arrays + topological validation in WP_DEBUG |
| Missing file on activation | Classmap autoload is a drop-in replacement for `require_once`; no renames |
| REST routes not registered | REST modules are Tier 5 (unconditional, not gated by `is_admin()`) |
| Backward compatibility | `wp_mcp_ai_pro_init` action still fires; all hook registrations preserved |
| Performance | Classmap autoload is equal to or faster than manual `require_once` chains |

## Success Criteria

- [x] `wp_mcp_ai_pro_init()` ≤ 40 lines (was ~625)
- [x] All 79 Pro subsystems discoverable in `define_modules()`
- [x] Toolkit enable flags enforced at registry level
- [x] Zero fatal errors on activation with standard config
- [x] Existing PHP lint passes on all changed files
- [x] `composer.json` autoload section added
