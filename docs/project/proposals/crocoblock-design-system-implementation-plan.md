# Crocoblock Design System — Implementation Plan

**Date:** July 5, 2026
**Status:** ✅ IMPLEMENTED (all 5 phases)
**Addon path:** `addons/crocoblock-ds/`

---

## Phase 1: Foundation ✅

| Story | Status | Files |
|---|---|---|
| 1.1 Plugin Bootstrap | ✅ | `nvoos-crocoblock-ds.php`, `class-nvoos-cds-plugin.php` |
| 1.2 Token Registry | ✅ | `class-nvoos-cds-token-registry.php`, `class-nvoos-cds-data-token.php`, `class-nvoos-cds-data-preset.php` |
| 1.3 CSS Generator | ✅ | `class-nvoos-cds-css-generator.php` |
| 1.4 Admin Page | ✅ | `class-nvoos-cds-admin-page.php`, `admin.css`, `token-preview.js` |
| 1.5 Presets | ✅ | `class-nvoos-cds-preset-minimal.php`, `class-nvoos-cds-preset-ecommerce.php`, `class-nvoos-cds-preset-directory.php` |

**Deliverables:** ~55 tokens across 7 groups, CSS `:root` block on frontend, admin token editor with live preview, 3 presets, JSON export, DTCG export, `@property` toggle.

---

## Phase 2: JetSmartFilters Skin ✅

| Story | Status | Files |
|---|---|---|
| 2.1 JSF Integration | ✅ | `class-nvoos-cds-integration-jsf.php` |
| 2.2 Filter Component CSS | ✅ | `assets/css/components.css` (`.cds-filter-bar` selectors) |
| 2.3 Token injection into JSF JS | ✅ | `inject_cds_tokens_into_js()` — passes transition/easing/duration/gap tokens to JSF front-end JS |

**Hooks used:**
- `jet-smart-filters/filter/container-classes` → inject `.cds-filter-bar`, `.cds-filter-type-pills`, `.cds-filter-type-search`, `.cds-filter-type-sort`
- `jet-smart-filters/filters/localized-data` → pass CDS tokens to JSF JS

---

## Phase 3: JetEngine Listing Templates ✅

| Story | Status | Files |
|---|---|---|
| 3.1 JetEngine Integration | ✅ | `class-nvoos-cds-integration-jetengine.php` |
| 3.2 Listing Card CSS | ✅ | `assets/css/components.css` (`.cds-card`, `.cds-grid` selectors) |
| 3.3 Template Registration | ✅ | `register_templates()` — registers CDS Product Card and Compact Row in JetEngine template selector |
| 3.4 Dynamic Inline Styles | ✅ | `output_listing_dynamic_styles()` — bridges CDS tokens to JetEngine grid CSS |

**Hooks used:**
- `jet-engine/listing/grid/wrapper-classes` → inject `.cds-grid`
- `jet-engine/listing/grid/item-classes` → inject `.cds-card`
- `jet-engine/listing/templates` → register CDS templates
- `wp_head` → output grid gap + card image height from CDS tokens

---

## Phase 4: JetFormBuilder ✅

| Story | Status | Files |
|---|---|---|
| 4.1 JFB Integration | ✅ | `class-nvoos-cds-integration-jfb.php` |
| 4.2 Form Component CSS | ✅ | `assets/css/components.css` (`.cds-form` selectors — inputs, buttons, radios, progress, success, errors) |
| 4.3 Dynamic Inline Styles | ✅ | `output_form_dynamic_styles()` — bridges CDS font tokens to JFB field defaults |

**Hooks used:**
- `jet-form-builder/form/container-classes` → inject `.cds-form`
- `wp_head` → output font-family/font-size defaults for form elements

---

## Phase 5: Polish & Ecosystem ✅

| Story | Status | Files |
|---|---|---|
| 5.1 Elementor Sync | ✅ | `class-nvoos-cds-integration-elementor.php` — registers CDS tab in Site Settings (opt-in) |
| 5.2 DTCG Export | ✅ | `class-nvoos-cds-dtcg-exporter.php` — exports tokens in W3C DTCG 2025.10 format |
| 5.3 @property Toggle | ✅ | Admin checkbox, `CSS_Generator` supports typed property output |
| 5.4 Accessibility Tokens | ✅ | Dark mode (`_dark`), high contrast (`_hc`), reduced motion media queries |
| 5.5 Animation Tokens | ✅ | 7 easing/duration tokens (`easing_standard`, `easing_decelerate`, `easing_accelerate`, `duration_instant/short/medium/long`) |
| 5.6 Plugin Wiring | ✅ | All 4 integrations booted on `init:20`, admin-post DTCG export handler |
| 5.7 Uninstall Cleanup | ✅ | Removes `nvoos_cds_settings`, `nvoos_cds_use_typed_properties`, `nvoos_cds_elementor_sync`, transient |

---

## File Inventory (20 files)

```
addons/crocoblock-ds/
├── nvoos-crocoblock-ds.php                            (entry point, autoloader)
├── uninstall.php                                       (cleanup)
├── .gitignore
├── README.md                                           (full documentation)
├── includes/
│   ├── class-nvoos-cds-plugin.php                      (composition root)
│   ├── class-nvoos-cds-token-registry.php              (token CRUD + persist)
│   ├── class-nvoos-cds-css-generator.php               (:root + @property + a11y)
│   ├── class-nvoos-cds-assets.php                      (component CSS enqueuing)
│   ├── class-nvoos-cds-dtcg-exporter.php               (W3C DTCG JSON export)
│   ├── class-nvoos-cds-preset-minimal.php              (~55 tokens)
│   ├── class-nvoos-cds-preset-ecommerce.php            (bright + tight)
│   ├── class-nvoos-cds-preset-directory.php            (professional + generous)
│   ├── base/
│   │   ├── class-nvoos-cds-data-token.php              (value object)
│   │   └── class-nvoos-cds-data-preset.php             (interface)
│   ├── admin/
│   │   └── class-nvoos-cds-admin-page.php              (settings page)
│   └── integrations/
│       ├── class-nvoos-cds-integration-jsf.php          (JetSmartFilters)
│       ├── class-nvoos-cds-integration-jetengine.php    (JetEngine)
│       ├── class-nvoos-cds-integration-jfb.php          (JetFormBuilder)
│       └── class-nvoos-cds-integration-elementor.php    (Elementor)
├── assets/
│   ├── css/
│   │   ├── admin.css                                   (settings page styles)
│   │   └── components.css                              (token→widget selectors)
│   └── js/
│       └── token-preview.js                            (admin live preview)
├── languages/.gitkeep
└── tests/
    ├── test-token-registry.php                         (12 tests)
    └── test-css-generator.php                          (4 tests)
```

---

## Test Coverage

| Test File | Tests | What's Covered |
|---|---|---|
| `test-token-registry.php` | 12 | Registry CRUD, grouping, presets, CSS var format, sanitization, reset |
| `test-css-generator.php` | 4 | :root block generation, token reflection, style tag, value changes |

---

## Token Count

| Group | Count | Examples |
|---|---|---|
| Colors | 13 | `color_surface`, `color_accent`, `color_surface_dark`, `color_border_hc`, ... |
| Typography | 9 | `font_family`, `font_size_base`, `font_weight_bold`, ... |
| Spacing | 7 | `space_xs`–`space_xl`, `gap_grid`, `gap_filter` |
| Borders | 4 | `radius_sm`–`radius_lg`, `border_width` |
| Shadows | 3 | `shadow_card`, `shadow_card_hover`, `shadow_dropdown` |
| Sizing | 3 | `filter_button_min_width`, `card_image_height`, `input_height` |
| Transitions | 11 | `transition_fast/normal`, `easing_*` (3), `duration_*` (4) |
| **Total** | **~55** | |

---

## WPCS Compliance

All 20 files pass `phpcs --error-severity=1 --warning-severity=8 --standard=phpcs.xml.dist` with **zero errors, zero warnings**.
