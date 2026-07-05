# NV oOS Crocoblock Design System

Design token system for the Crocoblock suite — unified CSS custom properties,
preset templates, and admin-controlled theming for **JetEngine**,
**JetSmartFilters**, and **JetFormBuilder**.

## What It Does

- **40 design tokens** across 7 groups: colors, typography, spacing, borders, shadows, sizing, transitions
- **CSS custom properties** injected as `:root { --cds-* }` on every page
- **Admin settings page** under Settings → Crocoblock DS with visual token editor and live preview
- **3 built-in presets**: Minimal (default), Ecommerce, Directory
- **Component CSS** that maps tokens to actual Crocoblock widget selectors (`.cds-form`, `.cds-filter-bar`, `.cds-card`, `.cds-grid`)
- **Token export** as JSON for backup or transfer between sites

## Quick Start

1. Activate the plugin.
2. Go to **Settings → Crocoblock DS**.
3. Choose a preset or customise individual tokens.
4. Add the CSS class `cds-form` to your JetFormBuilder form wrapper, `cds-filter-bar` to your filter container, or `cds-card` / `cds-grid` to your JetEngine listing grid.

## Token Reference

| Group | CSS Variable Pattern | Example |
|---|---|---|
| Colors | `--cds-color-{name}` | `--cds-color-surface` |
| Typography | `--cds-font-{name}` | `--cds-font-family` |
| Spacing | `--cds-space-{name}` | `--cds-space-md` |
| Borders | `--cds-{name}` | `--cds-radius-md` |
| Shadows | `--cds-shadow-{name}` | `--cds-shadow-card` |
| Sizing | `--cds-{name}` | `--cds-input-height` |
| Transitions | `--cds-transition-{name}` | `--cds-transition-fast` |

## Component Classes

Add these classes to Elementor containers/widgets to activate token-driven styling:

| Class | Applies To |
|---|---|
| `.cds-form` | JetFormBuilder form wrapper |
| `.cds-filter-bar` | JetSmartFilters filter container |
| `.cds-card` | JetEngine listing item |
| `.cds-grid` | JetEngine listing grid |

## Presets

| Preset | Vibe | Best For |
|---|---|---|
| **Minimal** | Neutral dark theme | Starting point for any site |
| **Ecommerce** | Bright, tight spacing | Product grids and shop pages |
| **Directory** | Professional, generous spacing | Directories, team pages, knowledge bases |

## Architecture

```
addons/crocoblock-ds/
├── nvoos-crocoblock-ds.php          ← Plugin entry point
├── includes/
│   ├── class-nvoos-cds-plugin.php   ← Composition root + DI
│   ├── class-nvoos-cds-token-registry.php ← Central token store
│   ├── class-nvoos-cds-css-generator.php  ← :root {} compiler
│   ├── class-nvoos-cds-assets.php   ← CSS enqueuing
│   ├── class-nvoos-cds-preset-minimal.php    ← Default preset
│   ├── class-nvoos-cds-preset-ecommerce.php  ← Ecommerce preset
│   ├── class-nvoos-cds-preset-directory.php  ← Directory preset
│   ├── base/
│   │   ├── class-nvoos-cds-data-token.php    ← Token value object
│   │   └── class-nvoos-cds-data-preset.php   ← Preset interface
│   ├── admin/
│   │   └── class-nvoos-cds-admin-page.php    ← Settings page
│   └── integrations/
│       ├── class-nvoos-cds-integration-jsf.php       ← JetSmartFilters (Phase 2)
│       ├── class-nvoos-cds-integration-jetengine.php ← JetEngine (Phase 3)
│       ├── class-nvoos-cds-integration-jfb.php       ← JetFormBuilder (Phase 4)
│       └── class-nvoos-cds-integration-elementor.php ← Elementor (Phase 5)
├── assets/
│   ├── css/
│   │   ├── admin.css         ← Admin page styles
│   │   └── components.css    ← Token→widget selector mappings
│   └── js/
│       └── token-preview.js  ← Admin live preview + reset buttons
├── languages/
├── tests/
├── uninstall.php
└── README.md
```

## Requirements

- WordPress 6.0+
- PHP 7.4+
- Crocoblock plugins (JetEngine, JetSmartFilters, JetFormBuilder) — optional; the addon works standalone but component classes only apply when plugins are active

## Hooks

### Actions

| Hook | Description |
|---|---|
| *(none yet — Phase 2+ will add integration hooks)* | |

### Filters

| Hook | Description |
|---|---|
| *(none yet)* | |

## Roadmap

| Phase | Status | What |
|---|---|---|
| 1 — Foundation | ✅ Done | Token registry, CSS generation, admin page, 3 presets, component CSS |
| 2 — JetSmartFilters | 🔜 Planned | Auto-inject `.cds-filter-bar`, filter template presets |
| 3 — JetEngine | 🔜 Planned | Auto-inject `.cds-card`/`.cds-grid`, listing template presets |
| 4 — JetFormBuilder | 🔜 Planned | Auto-inject `.cds-form`, form template presets |
| 5 — Polish | 🔜 Planned | Elementor sync, import/export, docs |

## License

GPLv3 or later — see [LICENSE](../../LICENSE).
