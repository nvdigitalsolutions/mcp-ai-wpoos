# Proposal 105 — Research: Bridge Theme Policy (Q6) — Owner-Set Theming Standards

**Date:** 2026-11-03
**Companion to:** `105-bridge-theme-policy-proposal.md`

This document sources every load-bearing claim in the proposal. Structure: (1) scope and method, (2) each external standard with citations, (3) local implementation evidence the design builds on, (4) what the findings imply for the design.

---

## 1. Scope and method

Q6 was deferred from Proposal 104: *"adopt the bridge-level `theme_policy` option (Foxi's `mode: auto|light|dark`) plus the named-theme menu + high-contrast axis now, or fold them into the next ADS increment"* (104 proposal §4, owner decision Q6). Proposal 104 shipped the app-side theme runtime (persistence, contrast axis, registry) and left the **owner-set policy layer** — a store-level setting that can force light/dark/brand and lock the switcher — to the next ADS increment.

This research answers one question: **what is the industry-standard way for a backend (CMS/commerce platform) to publish an owner-set theme policy that a headless frontend consumes without FOUC, cache poisoning, or authority drift?**

Method: web search of current standards and reference implementations (DTCG, next-themes, Shopify themes, WordPress core), cross-referenced with the Proposal 104 ecosystem repo analysis (`104-astro-ecosystem-repo-analysis.md`) and the shipped code in `examples/nvoos-astro-velocity` (branch `feat/astro-104-storefront-runtime`) and `addons/nvoos-astro-bridge`.

---

## 2. External standards

### 2.1 DTCG 2025.10 — the token exchange format the policy references

The Design Tokens Community Group shipped the **first stable Design Tokens Specification, version 2025.10, on 2025-10-28**, backed by 40+ organizations including Adobe, Figma, Google, Microsoft, Shopify, and Salesforce.

Citations:
- W3C Community Group announcement: https://www.w3.org/community/design-tokens/2025/10/28/design-tokens-specification-reaches-first-stable-version/
- Format module (2025.10): https://www.designtokens.org/tr/drafts/format/
- Third-party explainer (2026): https://blog.codercops.com/blog/design-tokens-2026-w3c-format-guide

**Implication:** the ADS already emits DTCG 2025.10 documents (`src/tokens/*.json`, `nds_export`). The policy doc is *not* a token document — it is configuration that *references* theme names. Per DTCG, any future cross-surface payload can ride the spec's `$extensions` key, so the policy doc reserves `$extensions` for the Proposal 097 NDS mapping instead of inventing parallel fields.

### 2.2 next-themes `forcedTheme` — the canonical policy-above-preference pattern

next-themes (pacocoursey) is the most widely adopted React theming library. Its `forcedTheme` prop is the industry reference for owner-set policy:

- *"If `forcedTheme` is set, you should disable any theme switching UI."* — https://www.npmjs.com/package/next-themes (also https://github.com/pacocoursey/next-themes)
- The SSR architecture guide documents the hydration contract and `disableTransitionOnChange`: https://aima.space/en/technologies/next-themes

The maintained fork `@teispace/next-themes` documents the cache-safety rule that is load-bearing for this proposal:

- *"Do not let a per-user value reach the inline script. The script is emitted through useServerInsertedHTML, so it becomes part of the prerendered static shell. Passing a request-derived initialTheme or forcedTheme bakes one user's theme into a [prerendered page]."* — https://www.npmjs.com/package/@teispace/next-themes

**Implications (design rules R1–R3 in the proposal):**
1. A forced policy **disables the switching UI** — exactly the Foxi behaviour (below).
2. Only the **policy** (identical for every visitor) may be baked into HTML at build/render time.
3. **Per-user values** (localStorage preference, system signal) must stay client-side and never enter the static shell — this is why the policy is baked, but the user preference is not.

### 2.3 Shopify themes — typed merchant settings with presets, served by the backend

Shopify's Liquid theme architecture is the leading headless-commerce precedent for "operator configuration lives in the backend, the storefront renders it":

- `settings_schema.json` — *"controls the content of the Theme settings area of the theme editor. Settings in this file translate to global theme settings, which can be accessed through the Liquid settings object."* — https://shopify.dev/docs/storefronts/themes/architecture/settings
- `settings_data.json` — *"enable users to customize the look and feel of their storefront without editing theme code"* — the runtime-editable layer separate from code: https://shopify.dev/docs/storefronts/themes/architecture/config/settings-data-json
- **Presets** — `schema.presets` ships *"preconfigured setting combinations"* (e.g. light vs dark colour schemes) so merchants pick policy values instead of authoring them: https://softwarehouse.au/blog/customizing-shopify-theme-settings-with-liquid-variables/ and https://www.shopify.com/partners/blog/creating-dynamic-color-schemes-with-theme-options-and-presets
- The `settings` Liquid object makes settings available **server-side at render time** (baked into HTML/CSS, not fetched client-side): https://shopify.dev/docs/api/liquid/objects/settings

**Implications:** the bridge (the operator-config surface for the storefront — banner, carousel, product source already live there) is the right home for the policy. The `/manage` settings UI should present **presets** (auto / light / dark / brand picker), not a free-text blob. And the policy is consumed **at render time** (build for static, request for SSR), mirroring Shopify's server-side settings access.

### 2.4 WordPress `wp/v2/global-styles` — the WP-native theme-config-over-REST precedent

Because the bridge lives inside WordPress, WP core's own global-styles REST surface is the closest native precedent:

- `GET /wp-json/wp/v2/global-styles/<id>` reads theme.json settings + user overrides; writes are capability-gated; unknown theme 404s: https://developer.wordpress.org/rest-api/reference/wp_global_styles/
- Since WP 6.6 the controller also **registers and serves theme style variations** (named theme presets) over REST: https://github.com/WordPress/WordPress/blob/master/wp-includes/rest-api/endpoints/class-wp-rest-global-styles-controller.php
- theme.json global settings and styles handbook: https://developer.wordpress.org/themes/global-settings-and-styles/

**Implications:** the bridge endpoint should mirror the discipline WP core already applies — public read, `manage_astro`-gated write, strict validation with typed errors, and a *named* value surface (the app's registry names). This also future-proofs Proposal 097: NDS cross-surface sharing can later consume the same doc the way core serves `global-styles`.

### 2.5 Foxi `mode: auto|light|dark` — typed config policy (from the 104 ecosystem scan)

From `104-astro-ecosystem-repo-analysis.md` §2 (foxi row):

> **Theme policy as config**: `mode: 'auto' | 'light' | 'dark'` in `src/config/config.ts` — auto follows system, light/dark **force** the site and render the switcher non-functional.

The 104 analysis already promoted this to a storefront-level analog of the Pro plugin's read-only profile gate and the bridge's product-source options, and recommended: *"Bridge option `funiq_bridge_theme_policy` (auto/light/dark) surfaced in the `/manage` settings → boot script reads it before user preference"* (recommendation matrix row 7, owner decision Q6).

**Implication:** the `policy` field of the contract doc is exactly Foxi's `mode` union, extended with `brand:<slug>` because the ADS runtime has named brand themes (Proposal 095/098) that Foxi doesn't.

### 2.6 CSS standards the runtime must respect

- `color-scheme` property / `<meta name="color-scheme">` — required for browser-provided UI (scrollbars, form controls, autofill) to follow dark mode. Already shipped in 104 (`applyTheme` sets `root.style.colorScheme`); the policy layer must keep it consistent with the forced theme. https://web.dev/color-scheme/
- `prefers-color-scheme` — the system-signal tier of the ladder; `auto` policy delegates here.
- `forced-colors` / high-contrast — the app's `data-contrast="high"` axis is the voluntary analogue of `forced-colors: active`. When the OS forces colors, the site's own toggle must not fight it; the policy `contrast` field is about the *site* axis only.

### 2.7 Local evidence the design builds on (file-verified)

| Fact | Evidence |
|---|---|
| Theme runtime shipped in 104: `[data-theme]` contract (absent = light), `[data-contrast]` axis, `color-scheme` sync | `src/lib/theme/theme.ts` (branch `feat/astro-104-storefront-runtime`) — `applyTheme`/`applyContrast` |
| Registry is app-side, generated from the ADS bundle | `src/lib/theme/registry.ts` — `ALL_THEMES = adsThemeNames` |
| Boot script applies before paint and re-applies on `astro:after-swap` | `src/layouts/BaseLayout.astro` inline `is:inline` script |
| ThemeToggle cycles ≤3 themes or opens a `role=menu`; ContrastToggle toggles the axis; both delegate clicks on `document` and re-sync on swap | `src/components/ThemeToggle.astro`, `ContrastToggle.astro` |
| Global singletons pattern exists (`globals/banner`, `globals/carousel`) | `src/lib/commerce/client.ts` `getGlobal(slug)` → `globals/{slug}` |
| Bridge options pattern: `Schema` constant, seeded in `seed_default_options()`, runtime filter read | `addons/nvoos-astro-bridge/includes/Schema.php` (`OPTION_PRODUCT_SOURCE = 'funiq_bridge_products_source'`), `Plugin::seed_default_options()` |
| Bridge route pattern: public READABLE + `manage_astro`-gated EDITABLE, double-registered on both namespaces | `addons/nvoos-astro-bridge/includes/REST/BannerController.php`, `Plugin::register_rest_routes()` |
| B1 caching: public GETs get `Cache-Control: public, s-maxage=300, stale-while-revalidate=60` + ETag | `BaseController::set_cache_headers_on_public_gets()` |
| Rebuild pipeline exists: bridge fires debounced webhook → GitHub dispatch → sync workflow rebuild | `addons/nvoos-astro-bridge/includes/RebuildWebhook.php`, `.github/workflows/sync-nvoos-astro-velocity.yml` |
| SSR pages use `Astro.cache` with tags and `s-maxage` headers (PLP proven pattern) | `src/pages/products/index.astro` |
| MCP `theme_*` tools + durable state file exist (095) | `mcp/tools/design.ts`, `NVOOS_MCP_STATE_FILE` persistence |
| Hermetic fixture server serves the contract incl. `globals/*` | `scripts/fixture-server.mjs` |

---

## 3. Findings → design decisions

| # | Finding | Design decision in the proposal |
|---|---|---|
| 1 | Forced policy must disable switching UI (next-themes, Foxi) | Forced modes render ThemeToggle/ContrastToggle disabled with an accessible "set by the store" note (§5.5) |
| 2 | Only owner-level values may enter the baked HTML; per-user values must not (teispace warning) | Bake the **policy** at build; user pref stays localStorage-applied at runtime (§5.3) |
| 3 | Merchant settings are typed, preset-driven, backend-owned (Shopify) | Policy lives in the bridge as a typed option with presets in the /manage settings (§4, §5.1) |
| 4 | Settings are consumed server-side at render time, not fetched post-paint (Shopify Liquid `settings`) | Static pages bake the policy at build; SSR reads it per request with a short TTL (§5.3) |
| 5 | WP core serves theme config + variations over REST with public read / gated write (global-styles) | `GET /globals/theme` public, `PATCH /globals/theme` `manage_astro`-gated, strict enum validation (§5.1) |
| 6 | Policy names themes it doesn't define — the registry lives in the frontend (Shopify theme settings reference theme files; WP global-styles is theme-scoped) | Authority split: bridge owns **policy values**, the ADS owns **theme names/definitions**; unknown `brand:<slug>` fail-opens to `auto` (§4) |
| 7 | Fast policy propagation needs a shorter cache TTL than catalog content | `globals/theme` gets `s-maxage=60` + rebuild webhook on PATCH (§5.1, §5.4) |
| 8 | DTCG `$extensions` is the sanctioned extension point for cross-surface payloads | Policy doc reserves `$extensions` for Proposal 097's NDS mapping (§5.2) |
