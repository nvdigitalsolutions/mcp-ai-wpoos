# Crocoblock Design System Addon — Proposal

**Date:** July 5, 2026
**Status:** 📋 DRAFT
**Target:** NV oOS Addon (standalone, works independent of oOS)
**Estimated Duration:** 8 weeks (20 stories across 5 phases)

---

## Executive Summary

Crocoblock sites (JetEngine + JetSmartFilters + JetFormBuilder) suffer from
per-widget CSS duplication, no centralised design tokens, and inconsistent
styling that requires copy-pasting styles across dozens of Elementor templates.
This addon introduces a **design token system** for the Crocoblock suite —
CSS custom properties, preset templates for listings/filters/forms, and
admin-controlled visual configuration — eliminating the "style drift" that
plagues large Crocoblock builds.

---

## Problem Statement

### Current State (from a live Crocoblock inventory page)

| Pain Point | Evidence |
|---|---|
| **CSS duplication** | `.jet-color-image-list__button` styles repeated identically across templates 3722, 3754, and inline `<style>` blocks |
| **No token system** | `var(--e-global-color-text)`, `#1B1B1B`, `#F4EDDF` hardcoded in multiple widgets with no single source of truth |
| **Inconsistent stock display** | Some items use `heading` widget, others use `dynamic-field` for the quantity; conditional visibility (`jedv-enabled--yes`) is ad-hoc |
| **Manual template wiring** | Each filter tab references separate Elementor templates (3700, 3722, 3731, 3754) with repeated boilerplate structure |
| **Per-instance overrides** | Custom CSS blocks like `.elementor-2357 .elementor-element-...` patching individual widget instances |
| **No guardrails** | Nothing prevents a content editor from breaking the visual system with inline styles |

### Industry Context

The WordPress ecosystem is moving toward design systems:
- **WordPress Core**: `theme.json` with `settings.custom` generates CSS custom properties (e.g., `--wp--custom--my-token`) — [Gutenberg #76509](https://github.com/WordPress/gutenberg/issues/76509)
- **Gutenberg Design System**: Tiered token architecture (primitive → semantic → component tokens) via `@wordpress/theme` package
- **Standardisation push**: [MRW Web Design proposal](https://mrwweb.com/standardized-design-tokens-css-wordpress-future/) advocates CSS utility classes + site-specific design tokens as a standardised toolkit for themes and plugins
- **Enterprise adopters**: NASA.gov (50+ custom blocks, atomic design system), Amnesty International (Benenson block framework), U.S. Government (USWDS integration)

Crocoblock has no equivalent. There is no centralised token system, no preset template library, and no design governance layer. This addon fills that gap.

---

## Proposed Solution

A WordPress plugin addon that lives inside the NV oOS ecosystem (`addons/crocoblock-ds/`) and provides:

### 1. Design Token Registry
- **7 token groups**: Colors, Typography, Spacing, Borders, Shadows, Sizing, Transitions
- **~40 tokens** covering all Crocoblock widget styling surfaces
- Tokens compiled to `:root { --cds-* }` CSS custom properties on `wp_head`
- **Admin token editor** with color pickers, range sliders, and live preview
- **Preset system**: one-click apply `Minimal`, `Ecommerce`, or `Directory` token sets
- **Export/Import**: JSON export of tokens for reuse across sites

### 2. Template Library (Phase 2–3)
- **JetEngine listing templates**: Card (product), Card (person/directory), Row (compact table)
- **JetSmartFilters layouts**: Horizontal bar, vertical sidebar, tabbed filters
- **JetFormBuilder form presets**: Stacked, inline, two-column
- All templates use `--cds-*` tokens exclusively — no hardcoded colors
- Import via JetEngine's native template import mechanism

### 3. Crocoblock Integration Layer (Phase 2–4)
- Inject `cds-*` CSS classes into listing grids, filter wrappers, and form containers
- Override default widget styles via token-driven CSS (additive, not destructive)
- Bidirectional sync with Elementor Global Colors where configured
- Hooks-based integration — uses Crocoblock's own filter/action hooks, degrades gracefully

### 4. Admin Experience
- Settings page under **Settings → Crocoblock Design System**
- Live preview pane rendering a sample card + filter bar with current tokens
- Visual token pickers (no raw CSS knowledge required)
- "Reset to preset" and "Export tokens" one-click actions

---

## Architecture

```
addons/crocoblock-ds/
├── nvoos-crocoblock-ds.php                 # Plugin entry point (ABSPATH guard, constants, autoloader)
├── includes/
│   ├── class-nvoos-cds-plugin.php          # Composition root (singleton, hooks into plugins_loaded)
│   ├── class-nvoos-cds-token-registry.php  # Central store of all design tokens
│   ├── class-nvoos-cds-css-generator.php   # Compiles tokens → :root {} + utility classes
│   ├── class-nvoos-cds-assets.php          # CSS enqueuing, editor styles
│   ├── class-nvoos-cds-preset-minimal.php  # Bare-minimum token set (default)
│   ├── class-nvoos-cds-preset-ecommerce.php # Pre-tuned for product grids
│   ├── class-nvoos-cds-preset-directory.php # For listing/directory sites
│   ├── base/
│   │   └── class-nvoos-cds-data-token.php  # Single token model (value object)
│   ├── admin/
│   │   └── class-nvoos-cds-admin-page.php  # Settings page + token editor UI
│   └── integrations/
│       ├── class-nvoos-cds-jetengine.php   # JetEngine hooks (Phase 3)
│       ├── class-nvoos-cds-jsf.php         # JetSmartFilters hooks (Phase 2)
│       ├── class-nvoos-cds-jfb.php         # JetFormBuilder hooks (Phase 4)
│       └── class-nvoos-cds-elementor.php   # Elementor sync (Phase 5)
├── assets/
│   ├── css/
│   │   ├── tokens.css                      # Compiled :root variables
│   │   └── components.css                  # .cds-card, .cds-filter-bar utility classes
│   └── js/
│       └── token-preview.js                # Admin live preview (Phase 1)
├── languages/
│   └── .gitkeep
├── tests/
│   ├── test-token-registry.php
│   ├── test-css-generator.php
│   └── test-admin-page.php
├── uninstall.php
├── README.md
└── .gitignore
```

---

## Token Categories

| Group | Example Tokens | Applies To |
|---|---|---|
| **Colors** | `--cds-color-surface`, `--cds-color-surface-hover`, `--cds-color-text-primary`, `--cds-color-text-secondary`, `--cds-color-accent`, `--cds-color-accent-hover`, `--cds-color-border`, `--cds-color-success`, `--cds-color-warning` | Filter buttons, listing cards, form fields |
| **Typography** | `--cds-font-family`, `--cds-font-size-xs/sm/base/lg/xl`, `--cds-font-weight-normal/bold`, `--cds-line-height` | Labels, product names, prices, form labels |
| **Spacing** | `--cds-space-xs/sm/md/lg/xl`, `--cds-gap-grid`, `--cds-gap-filter` | Card padding, filter gaps, grid gutters |
| **Borders** | `--cds-radius-sm/md/lg`, `--cds-border-width`, `--cds-border-color` | Filter buttons, cards, form inputs |
| **Shadows** | `--cds-shadow-card`, `--cds-shadow-card-hover`, `--cds-shadow-dropdown` | Listing cards, dropdowns |
| **Sizing** | `--cds-filter-button-min-width`, `--cds-card-image-height`, `--cds-input-height` | Consistent component sizing |
| **Transitions** | `--cds-transition-fast`, `--cds-transition-normal` | Hover states |

### Token Data Model

```php
class NV_oOS_Crocoblock_DS_Data_Token {
    public string $id;           // e.g., 'color_surface'
    public string $label;        // e.g., 'Surface Color'
    public string $group;        // e.g., 'colors'
    public string $css_var;      // e.g., '--cds-color-surface'
    public string $type;         // 'color' | 'size' | 'font' | 'shadow' | 'transition'
    public string $default;      // e.g., '#1B1B1B'
    public string $value;        // Current value (may differ from default via admin)
    public string $description;  // Help text for admin UI
}
```

---

## Implementation Phases

### Phase 1: Foundation (Week 1–2) — 5 stories

**Goal:** Token registry, CSS generation, admin page, and one preset ship. No Crocoblock integration yet.

| Story | Files | Deliverable |
|---|---|---|
| 1.1 Plugin Bootstrap | `nvoos-crocoblock-ds.php`, `class-nvoos-cds-plugin.php` | ABSPATH guard, constants, autoloader, `plugins_loaded` hook |
| 1.2 Token Registry | `class-nvoos-cds-token-registry.php`, `class-nvoos-cds-data-token.php` | Register 40 tokens, get/set/reset, filter by group |
| 1.3 CSS Generator | `class-nvoos-cds-css-generator.php` | Compile tokens → `:root { }` CSS block, enqueue on `wp_head` |
| 1.4 Admin Page | `class-nvoos-cds-admin-page.php` | Settings page with color pickers + range sliders, live preview |
| 1.5 Presets | `class-nvoos-cds-preset-minimal.php`, `class-nvoos-cds-preset-ecommerce.php`, `class-nvoos-cds-preset-directory.php` | Three preset token sets, "Apply Preset" button |

### Phase 2: JetSmartFilters Skin (Week 3–4) — 4 stories

**Goal:** Filter widgets inherit design tokens. One preset filter layout ships.

| Story | Files | Deliverable |
|---|---|---|
| 2.1 JSF Integration | `class-nvoos-cds-jsf.php` | Inject `.cds-filter-bar` classes, output token-driven filter styles |
| 2.2 Filter Component CSS | `assets/css/components.css` | `.cds-filter-bar .jet-color-image-list__button`, `.cds-filter-bar .jet-search-filter__input`, etc. |
| 2.3 Filter Bar Preset | Template JSON | Horizontal filter bar: [Location Pills] [Search] [Sort] [Brand Pills] |
| 2.4 Tabbed Filter Preset | Template JSON | Tabbed filters matching the Kaya inventory page pattern |

### Phase 3: JetEngine Listing Templates (Week 5–6) — 4 stories

**Goal:** Listing items styled by tokens, importable via JetEngine.

| Story | Files | Deliverable |
|---|---|---|
| 3.1 JetEngine Integration | `class-nvoos-cds-jetengine.php` | Inject `.cds-card` classes, register templates |
| 3.2 Listing Card CSS | `assets/css/components.css` | `.cds-card` styles for product card layout |
| 3.3 Card Template (Product) | Template JSON | Product card: image → category → name → location → quantity → price |
| 3.4 Card Template (Compact) | Template JSON | Compact row listing for table-style layouts |

### Phase 4: JetFormBuilder (Week 7) — 3 stories

**Goal:** Form inputs/buttons styled by tokens.

| Story | Files | Deliverable |
|---|---|---|
| 4.1 JFB Integration | `class-nvoos-cds-jfb.php` | Inject `.cds-form` class, apply token defaults to fields |
| 4.2 Form Component CSS | `assets/css/components.css` | `.cds-form` input/button/error state styles |
| 4.3 Form Presets | Template JSON | Stacked + inline form layouts |

### Phase 5: Polish & Ecosystem (Week 8–9) — 4 stories

**Goal:** Elementor sync, export/import, docs, wp.org readiness.

| Story | Files | Deliverable |
|---|---|---|
| 5.1 Elementor Sync | `class-nvoos-cds-elementor.php` | Bidirectional CDS ↔ Elementor Global Colors (opt-in) |
| 5.2 Token Export/Import | Admin page extension | JSON export/import of full token configuration |
| 5.3 Documentation | `README.md`, in-admin help | User guide, API docs, filter hook reference |
| 5.4 Cleanup & Polish | All files | Uninstall handler, `.gitignore`, linter compliance, tests |

---

## Key Design Decisions

| Decision | Rationale |
|---|---|
| **CSS Custom Properties over SCSS** | Runtime-themeable, no build step, Elementor-compatible, WordPress-standard |
| **Utility classes (`.cds-card`) not forced inheritance** | Works alongside existing custom CSS; non-destructive overlay |
| **Tokens stored as WordPress options** | Persists across theme changes, survives plugin updates, no custom table needed |
| **Hooks over monkey-patching** | Uses Crocoblock's documented filter/action hooks; degrades gracefully on plugin updates |
| **Addon not a theme** | Works with any theme; design tokens are site-configuration, not theme-dependent |
| **No `theme.json` dependency** | Avoids coupling to block themes; works with classic + Elementor builds |
| **PHP 7.4 floor** | Matches Crocoblock's minimum; aligns with NV oOS base plugin compatibility |
| **Single autoload option key** | `nvoos_cds_settings` stores all token values as one serialised array → one DB read |

---

## Concrete Before/After (from the Kaya inventory page)

### Before (current)

```html
<style id="elementor-post-3722">
  .elementor-3722 .elementor-element-a6df821 .jet-color-image-list__button {
    color: var(--e-global-color-text);
    padding: 1px 5px 1px 2px;
    border-radius: 3px;
  }
  .elementor-3722 .elementor-element-a6df821 .jet-color-image-list__button:hover {
    background-color: var(--e-global-color-2061d9e4);
  }
  /* ... 50+ more lines of per-instance CSS across 4 templates ... */
</style>
```

### After (with CDS)

```html
<style id="cds-tokens">
  :root {
    --cds-color-text-primary: #F4EDDF;
    --cds-color-surface-hover: #2a2a2a;
    --cds-color-accent: #8B9F48;
    --cds-radius-sm: 3px;
    --cds-filter-button-padding: 1px 5px 1px 2px;
    --cds-filter-gap: 30px;
  }
</style>

<div class="cds-filter-bar cds-filter-tabbed" data-cds-preset="ecommerce">
  <!-- All JetSmartFilters widgets auto-inherit CDS styles -->
</div>
```

**Result:** Zero per-instance CSS. One `:root` block controls every filter button, listing card, and form input across the entire site.

---

## Pre-Implementation Checklist

- [x] Review live Crocoblock inventory page (Kaya Herb House) for real-world patterns
- [x] Research WordPress design system best practices (Gutenberg token architecture, theme.json patterns)
- [x] Confirm Crocoblock filter/action hooks exist for CSS class injection
- [x] Verify PHP 7.4 compatibility for all planned code
- [ ] Clone test environment with JetEngine + JetSmartFilters + JetFormBuilder + Elementor
- [ ] Identify all Crocoblock hooks for Phase 2–4 integrations
- [ ] Collect exact Crocoblock CSS selectors that need to be overridden

---

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Crocoblock changes internal CSS selectors | Use their filter hooks where available; fall back to higher-specificity utility classes |
| Token proliferation (too many tokens) | Start with 40 tokens; only add tokens when a real use case demands it |
| Elementor Global Colors conflicts | Bidirectional sync is opt-in; default is additive overlay, not replacement |
| Performance: many CSS variables | CSS custom properties are resolved at render time; 40 `:root` variables is negligible |
| Adoption: editors ignore the system | Admin page with visual pickers (not raw CSS); live preview reduces learning curve |

---

## Open Questions

1. **Scope:** Standalone addon usable without NV oOS, or tightly coupled?
2. **JetFormBuilder depth:** Full form builder skinning or just preset form layouts?
3. **Elementor sync:** Bidirectional or CDS → Elementor only (one-way)?
4. **Migration path:** Should the addon scan existing Elementor templates and suggest token replacements?
5. **Multisite:** Should token values be site-specific or network-wide configurable?
