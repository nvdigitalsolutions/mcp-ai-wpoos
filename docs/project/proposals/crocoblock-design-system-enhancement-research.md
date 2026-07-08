# Crocoblock Design System — Industry Standards Research & Enhancement Roadmap

**Date:** July 5, 2026
**Based on:** Web research into W3C DTCG, Style Dictionary, WordPress core patterns, accessibility standards, and enterprise design system practices.

---

## Executive Summary

Web research across 6 domains reveals 8 concrete enhancements the Crocoblock
Design System should adopt to meet 2025–2026 industry standards. The most
critical is **DTCG-compliant token format** (the W3C Community Group standard
released October 2025), which would make our tokens interoperable with Figma,
Style Dictionary, and 10+ design tools.

---

## 1. DTCG-Compliant Token Format

### What It Is

The **Design Tokens Community Group (DTCG)** — a W3C community group with members from Adobe, Google, Microsoft, Meta, Shopify, Figma, Salesforce, and 20+ others — released the first stable **Design Tokens Specification (2025.10)** on October 28, 2025.

### Why It Matters

The DTCG spec is becoming the **universal interchange format** for design tokens. Adoption means:
- **One source of truth** that exports to CSS, iOS, Android, Flutter, and Figma
- **Interoperability** with Tokens Studio for Figma, Style Dictionary, Terrazzo, Penpot, Sketch, Framer, Supernova, zeroheight, and Knapsack
- **Theming and multi-brand support** without file duplication
- **Modern color spaces**: Display P3, Oklch, and all CSS Color Module 4 spaces

### Current CDS Token Format (flat)

```json
{
  "color_surface": "#1a1a1a",
  "color_accent": "#8b9f48",
  "space_md": "16px"
}
```

### DTCG-Compliant Format (tiered)

```json
{
  "color": {
    "surface": {
      "$type": "color",
      "$value": "#1a1a1a"
    },
    "accent": {
      "$type": "color",
      "$value": "#8b9f48"
    }
  },
  "space": {
    "md": {
      "$type": "dimension",
      "$value": "16px"
    }
  }
}
```

### Enhancement: DTCG Export

- **Story**: Add a "DTCG Export" button alongside the existing JSON export
- **Implementation**: A new `NV_oOS_Crocoblock_DS_DTCG_Exporter` class that maps our flat token format to the DTCG hierarchical `$type`/`$value` structure
- **Benefit**: Users can import tokens directly into Tokens Studio for Figma, Style Dictionary, or any DTCG-compliant tool

---

## 2. Tiered Token Architecture (Global → Semantic → Component)

### What It Is

The industry standard for multi-brand / white-label design systems uses **3–4 token tiers**:

```
Global (primitive) tokens     →  blue-500: #2563eb
Semantic (alias) tokens       →  color-accent: {blue-500}
Component tokens              →  button-bg: {color-accent}
Theme overrides               →  brand-b: { color-accent: #dc2626 }
```

This was confirmed by Reddit r/DesignSystems and r/DesignTokens discussions, and the Clearleft article on multi-brand design systems. The DTCG spec natively supports this via **aliases** (`{reference.to.other.token}`).

### Current CDS Architecture (1 tier)

All 40 tokens are semantic (directly mapped to CSS variables). There are no primitive tokens, no aliasing, and no component-level tokens.

### Enhancement: 3-Tier Token Model

- **Tier 1 — Primitives** (internal): Raw values never exposed as CSS variables. E.g., `blue-500`, `green-600`, `spacing-4`, `radius-2`.
- **Tier 2 — Semantics** (current tokens, mapped to CSS): Purpose-driven aliases. E.g., `color-accent` references `green-600`.
- **Tier 3 — Components** (new): Token groups tied to specific Crocoblock widgets. E.g., `filter-button-bg`, `card-padding`, `form-input-border`.

Benefits:
- **Multi-brand**: Change the primitives, and all semantic/component tokens cascade
- **Dark mode**: One set of semantic overrides per theme
- **SaaS/agency use**: White-label a Crocoblock site by swapping primitives only

---

## 3. CSS `@property` — Typed Custom Properties

### What It Is

The CSS `@property` at-rule (CSS Houdini, now supported in all modern browsers) allows declaring CSS custom properties with:
- **Syntax validation** (`<color>`, `<length>`, `<number>`, etc.)
- **Initial values**
- **Inheritance control**
- **Animation capability** (the browser can interpolate typed custom properties)

### Current CDS Output

```css
:root {
  --cds-color-accent: #8b9f48;
  --cds-transition-fast: 150ms ease;
}
```

### Enhanced Output

```css
@property --cds-color-accent {
  syntax: '<color>';
  inherits: true;
  initial-value: #8b9f48;
}

@property --cds-transition-duration {
  syntax: '<time>';
  inherits: false;
  initial-value: 150ms;
}

:root {
  --cds-color-accent: #8b9f48;
  --cds-transition-fast: var(--cds-transition-duration) ease;
}
```

Benefits:
- **Type safety**: The browser rejects invalid values instead of silently breaking
- **Animatable tokens**: Transitions between token values (e.g., theme switching) animate smoothly
- **DevTools**: Browsers show typed custom properties with proper color pickers and unit controls

### Enhancement: `@property` Generation

- **Opt-in toggle** in admin: "Generate typed custom properties (@property)"
- **PHP**: Extend `CSS_Generator` to output `@property` blocks before the `:root` block
- **Fallback**: Graceful degradation in older browsers (the `:root` block still works)

---

## 4. Accessibility Tokens & User Preference Media Queries

### What It Is

Modern design systems include tokens that respond to OS-level user preferences:
- `prefers-color-scheme: dark` — Dark mode
- `prefers-contrast: high` — High contrast mode
- `prefers-reduced-motion: reduce` — Disable animations
- `forced-colors: active` — Windows High Contrast Mode

WCAG 2.2 requires that content respects these preferences.

### Current CDS (no accessibility tokens)

The system has no awareness of user preference media queries. Dark mode requires a completely separate token set.

### Enhancement: Accessibility Token Extensions

- **New token group**: `a11y` with tokens like `a11y_reduced_motion`, `a11y_high_contrast`
- **Dark mode preset**: `NV_oOS_Crocoblock_DS_Preset_Dark` that provides dark-mode overrides
- **CSS output**: Generate `@media (prefers-color-scheme: dark) { :root { ... } }` blocks for dark mode tokens
- **`prefers-reduced-motion`**: Automatically set `--cds-transition-fast` and `--cds-transition-normal` to `0ms` when the user prefers reduced motion

```css
@media (prefers-reduced-motion: reduce) {
  :root {
    --cds-transition-fast: 0ms;
    --cds-transition-normal: 0ms;
  }
}

@media (prefers-color-scheme: dark) {
  :root {
    --cds-color-surface: #1a1a1a;
    --cds-color-text-primary: #f5f0e8;
  }
}

@media (prefers-contrast: high) {
  :root {
    --cds-color-border: #ffffff;
    --cds-color-text-secondary: #ffffff;
  }
}
```

---

## 5. Style Dictionary Integration (Build Pipeline)

### What It Is

[Style Dictionary](https://styledictionary.com/) by Amazon is the industry-standard build tool for design tokens. It takes a token JSON file and generates platform-specific output:
- **Web**: CSS custom properties, SCSS variables, JS modules
- **iOS**: Swift, Objective-C
- **Android**: XML resources, Compose
- **Flutter**: Dart
- **Docs**: Markdown token tables

The v5 release (2025) added full DTCG spec support.

### Current CDS (no build pipeline)

Tokens are compiled by PHP at runtime and cached in a transient. There is no offline/CI build step.

### Enhancement: Style Dictionary as Optional Export

- **Admin button**: "Export for Style Dictionary" — downloads a DTCG-compliant `tokens.json` file
- **CI integration**: A `style-dictionary.config.js` shipped with the plugin that users can run in their build pipeline: `npx style-dictionary build`
- **Output**: Generates `variables.css`, `_variables.scss`, `tokens.js`, and `tokens.md` from the same source

---

## 6. WordPress theme.json Integration (Block Editor)

### What It Is

WordPress 6.x+ **block themes** use `theme.json` to define:
- Global styles and settings
- Design tokens via `settings.color.palette`, `settings.typography.fontSizes`, `settings.custom`
- Editor controls (disable custom colors, lock font sizes)

The WP core Gutenberg project has an active discussion ([#76509](https://github.com/WordPress/gutenberg/issues/76509)) about standardizing design token extensibility.

### Current CDS (Elementor-only, no block editor awareness)

Tokens are injected as CSS custom properties but are invisible to the block editor.

### Enhancement: theme.json Token Bridge

- **Opt-in setting**: "Expose tokens to Block Editor"
- **PHP hook**: `wp_theme_json_data_theme` filter to inject CDS tokens into `theme.json` at runtime
- **Result**: Block editor color pickers, font size selectors, and spacing controls show CDS tokens alongside theme tokens

```php
add_filter( 'wp_theme_json_data_theme', function ( $theme_json ) {
    $cds_tokens = NV_oOS_Crocoblock_DS_Plugin::token_registry()->get_values_map();

    $new_data = array(
        'version'  => 2,
        'settings' => array(
            'color' => array(
                'palette' => array(
                    array(
                        'slug'  => 'cds-accent',
                        'color' => $cds_tokens['color_accent'],
                        'name'  => 'CDS Accent',
                    ),
                    // ...
                ),
            ),
            'custom' => array(
                'cds' => array(
                    'card-shadow' => $cds_tokens['shadow_card'],
                    // ...
                ),
            ),
        ),
    );

    return $theme_json->update_with( $new_data );
} );
```

---

## 7. Visual Regression Testing (Token Change Safety)

### What It Is

Enterprise design systems use **Storybook + Chromatic** for visual regression testing:
- Every component is rendered in isolation across all token variants
- When a token changes, Chromatic takes pixel-perfect snapshots of every affected component
- PR reviews show visual diffs: "changing `--cds-color-accent` affects these 47 components"
- Automated in CI; blocks merges that cause unintended visual changes
- **TurboSnap** (Chromatic feature) reduces CI time by 50–80% by only snapshotting changed stories

### Current CDS (no testing beyond unit tests)

Unit tests verify token values but cannot catch visual regressions when a token change breaks a listing card or filter button.

### Enhancement: Token Change Audit

- **Admin feature**: "Preview token changes" — renders a gallery of the 10 most common Crocoblock component states (card normal, card hover, filter normal, filter active, form input, form error, etc.) with current vs. proposed token values side-by-side
- **CI hook** (future): Export token changes as a JSON diff; feed into Chromatic for full visual regression testing

---

## 8. Token-Based Animation & Micro-interactions

### What It Is

With `@property` (see #3), CSS custom properties can be **animated**. This enables:
- Smooth theme transitions (light → dark mode with crossfade, not flash)
- Hover states that interpolate between token values
- Scroll-driven animations using token values

### Current CDS (static tokens)

`--cds-transition-fast` and `--cds-transition-normal` are the only motion-related tokens.

### Enhancement: Animation Tokens

- **New tokens**: `--cds-easing-standard`, `--cds-easing-decelerate`, `--cds-easing-accelerate`, `--cds-duration-instant`, `--cds-duration-short`, `--cds-duration-medium`, `--cds-duration-long`
- **Standard easing functions**: `cubic-bezier(0.2, 0, 0, 1)` (Material Design standard), `cubic-bezier(0, 0, 0.2, 1)` (decelerate), `cubic-bezier(0.3, 0, 1, 1)` (accelerate)
- **Usage in component CSS**:

```css
.cds-card {
  transition:
    box-shadow var(--cds-duration-medium) var(--cds-easing-standard),
    transform var(--cds-duration-medium) var(--cds-easing-decelerate);
}

.cds-card:hover {
  transform: translateY(-2px);
}
```

---

## Prioritization Matrix

| # | Enhancement | Impact | Effort | Phase | Dependencies |
|---|---|---|---|---|---|
| 1 | DTCG Export Format | 🟢 High | 🟢 Low | Phase 5+ | None |
| 2 | Tiered Token Architecture | 🟢 High | 🟡 Medium | Phase 5+ | Refactors `Token_Registry` |
| 3 | CSS `@property` Typed Tokens | 🟡 Medium | 🟢 Low | Phase 5+ | None |
| 4 | Accessibility Tokens | 🟢 High | 🟡 Medium | Phase 5+ | None |
| 5 | Style Dictionary Export | 🟡 Medium | 🟢 Low | Phase 5+ | #1 (DTCG format) |
| 6 | theme.json Bridge | 🟡 Medium | 🟡 Medium | Phase 5+ | None |
| 7 | Visual Regression Testing | 🟡 Medium | 🔴 High | Post-Phase 5 | Storybook setup |
| 8 | Animation Tokens | 🟢 Low | 🟢 Low | Phase 5+ | #3 (`@property`) |

---

## Recommended Phase 5+ Scope

Based on the impact/effort analysis, **Phase 5** (Polish & Ecosystem) should be expanded to include:

1. **DTCG Export** (low effort, high impact — interoperability with the entire design tool ecosystem)
2. **CSS `@property` generation** (low effort, enables animation and type safety)
3. **Accessibility token extensions** (high impact for WCAG compliance)
4. **theme.json bridge** (medium effort, unlocks block editor integration)

Items #2 (tiered architecture) and #7 (visual regression) should become **Phase 6** (Enterprise) due to higher implementation complexity.

---

## Sources

1. **W3C Design Tokens Community Group** — [DTCG Spec v1 (2025.10)](https://www.designtokens.org/tr/2025.10/) — Stable specification for design token interchange
2. **Style Dictionary** — [styledictionary.com](https://styledictionary.com/) — Amazon's build tool for design tokens, now with DTCG support
3. **WordPress Gutenberg #76509** — [Design Token Extensibility](https://github.com/WordPress/gutenberg/issues/76509) — Active discussion on standardizing token extension in theme.json
4. **MRW Web Design** — [Standardized Design Tokens and CSS for WordPress](https://mrwweb.com/standardized-design-tokens-css-wordpress-future/) — Proposal for CSS utility classes + site-specific tokens
5. **Trew Knowledge** — [Design Systems at Scale in WordPress](https://trewknowledge.com/2025/04/14/design-systems-at-scale-in-wordpress-implementation-and-governance/) — Enterprise WordPress design system governance
6. **Clearleft** — [Multi-brand design system token architecture](https://clearleft.com/thinking/designing-with-tokens-for-a-flexible-multi-brand-design-system) — Tiered token model for white-label products
7. **Tokens Studio for Figma** — [docs.tokens.studio](https://docs.tokens.studio/) — DTCG-compliant Figma → code token pipeline
8. **Chromatic** — [Visual testing for Storybook](https://www.chromatic.com/storybook) — Visual regression testing for design systems
9. **MDN** — [@property](https://developer.mozilla.org/en-US/docs/Web/CSS/@property) — CSS typed custom properties
10. **Sparkbox Design Systems Survey 2025** — Teams using design systems ship 34% faster, reduce design-related bugs by 47%
