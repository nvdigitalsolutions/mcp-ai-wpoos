# `extract_site_design_from_mockups`

> Pro tool — Site Creator Toolkit · Research & Discovery tier
>
> Slug: `extract_site_design_from_mockups`
> Capability: `manage_options`
> Flags: `pro`, `write`, `cacheable`, `external-api`, `requires-capability`

Ingests a bag of design inputs — mockup **images** (PNG/JPG/WebP), reference
**HTML/CSS files**, optional **live URLs**, and a free-text **brief** — and
emits a single install-ready PHP **"site design snippet"** that prints the
extracted design tokens, a small JS interaction layer, and a JetFormBuilder
form skin shaped exactly like the Aerlinn-style examples bundled with the plan.

The tool is opt-in. Both of these gates must be enabled:

1. `Settings → NV oOS → Tools → Features → Enable Site Creator Toolkit`
2. `Site Creator → Permissions → Design Extractor`

The second checkbox is off by default because vision providers can be called
once per supplied mockup image.

## Inputs

```jsonc
{
  "inputs": {
    "images": [                       // optional — max 8, 4 MB each
      { "media_id": 123, "role": "mockup" },
      { "url": "https://example.com/hero.png", "role": "reference" }
    ],
    "html_files": [                   // optional — sanitized + parsed, never executed
      { "content": ":root { --nv-bg: #0f110e; --nv-accent: #2d6a4f; }" }
    ],
    "urls": [ "https://example.com" ],// optional — delegates to analyze_competitor_sites
    "brief": "Luxury serene hospitality brand."
  },
  "targets":      ["wordpress","elementor","jet-form-builder"], // default: all three
  "skin_variant": "auto",                                       // luxury|panel|minimal|auto
  "features":     ["scroll_reveal","header_scroll_state","custom_cursor","hover_link_underline","mobile_drawer","rotating_steps"],
  "output": {
    "format":                    "php_snippet",  // or "package"
    "persist_as_wpcode":         false,           // calls create_wpcode_snippet
    "persist_as_site_template":  false,           // writes a wp_site_template CPT row
    "write_theme_json_partial":  false            // returns Theme_JSON_Generator partial
  },
  "dry_run": false
}
```

## Outputs

```jsonc
{
  "success": true,
  "design_system": { /* palette, typography, spacing, radii, shadows, motion */ },
  "contrast_report": [
    { "pair": "text on bg", "ratio": 18.5, "minimum": 4.5, "wcag_aa": true },
    { "pair": "accent on bg (non-text)", "ratio": 4.2, "minimum": 3.0, "wcag_aa": true }
  ],
  "is_draft": false,
  "warnings": [],
  "snippet": "<?php /* ... full PHP file ... */",
  "fingerprint": "abc123def456",
  "skin_variant": "luxury",
  "features": ["scroll_reveal", "header_scroll_state"],
  "targets": ["wordpress","elementor","jet-form-builder"],
  "persisted": { /* wpcode_snippet_id, site_template_post_id, dry_run */ },
  "apply_to_elementor": "## How to apply in Elementor ..."
}
```

When `output.format = "package"` the response also contains:

```jsonc
{
  "package": {
    "tokens_css":         ":root { --nv-bg: #0f110e; ... }",
    "interactions_css":   "@media (prefers-reduced-motion: reduce) { ... }",
    "interactions_js":    "(function(){ document.addEventListener('DOMContentLoaded', ... )})();",
    "jfb_css":            ".jet-form-builder { ... }",
    "theme_json_partial": { "$schema": "...", "version": 3, "settings": { ... } }
  }
}
```

## How inputs become a Design System

1. **HTML/CSS inputs** (highest weight, 1.0) — parsed for `:root { --x: y; }`
   custom properties, `font-family`, `border-radius`, and color literals.
2. **Vision over images** (0.7) — each image is sent to the existing provider
   abstraction via the `wp_mcp_ai_design_extractor_vision` filter; production
   wires this to OpenAI / Gemini / Anthropic, tests can short-circuit with a
   fixture array.
3. **URL analysis** (0.5) — delegated to `analyze_competitor_sites`.
4. **Defaults** (0.1) — built-in Aerlinn-style fallback fills any gaps.

After merging, every `text↔bg` and `accent↔bg` pair is verified against
WCAG 2.2 AA (4.5:1 for text, 3:1 for non-text). Failures don't block emission;
the snippet's file-level header is marked `STATUS: DRAFT` and a warning is
returned.

## Snippet shape

The emitted PHP file follows the same shape as the example snippets:

* File-level PHPDoc with `@link` / `@credit`, contrast report, provenance.
* `if ( ! defined( 'ABSPATH' ) ) { exit; }` guard.
* `add_action( 'wp_head', ..., 99 )` — `:root` token block, hover-only cursor
  styles (`@media (hover: hover) and (pointer: fine)`), reveal-on-scroll
  classes, `prefers-reduced-motion` short-circuit, header transitions, and
  the JFB skin block scoped under `.jet-form-builder`.
* `add_action( 'wp_footer', ..., 99 )` — cursor markup (when enabled) and a
  single feature-detected `<script>` IIFE with `IntersectionObserver` and
  `mousemove` passive listeners.

## Elementor companion

Authors paste these utility classes into Elementor's per-widget "CSS Classes"
field to opt in:

* `nv-reveal` (with `nv-reveal-delay-1`, `-2`, `-3`)
* `nv-scroll-nav` on the header section
* `nv-hover-link` on links, `nv-outline-accent` on accented buttons
* `nv-hiw-step` on each "How it works" step
* `id="nv-hamburger"` on the mobile menu trigger and `id="nv-drawer"` on the drawer container

## Security model

* `manage_options` capability check before any extraction or persistence.
* Image rows are capped (8 max, 4 MB each); only `role=mockup|reference`
  trigger vision calls.
* HTML inputs are size-capped, NUL-stripped, and only ever tokenized — never
  rendered or executed.
* URLs are validated through `wp_http_validate_url()` before being delegated.
* Emitted PHP is shaped from a tightly-scoped renderer with explicit
  per-value sanitizers (`sanitize_color`, `sanitize_length`, `sanitize_shadow`,
  `sanitize_easing`, `sanitize_font_stack`, `sanitize_token_key`).
* Activity logs record image / file / URL counts and the fingerprint —
  never the brief text or any user content.

## See also

* `analyze_competitor_sites` — used internally for URL inputs.
* `create_wpcode_snippet` — used for `output.persist_as_wpcode`.
* `WP_MCP_AI_Theme_JSON_Generator` — used for the theme.json partial.
* `wp_site_template` CPT — receives the snippet body when persisted.
