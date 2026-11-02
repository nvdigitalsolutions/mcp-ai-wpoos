# Proposal 105 — Bridge Theme Policy: An Owner-Set Theming Layer for the Storefront Contract

**Status:** IMPLEMENTED (2026-11-03) — Phases 1–4 shipped on `feat/astro-105-106-theming-content`: bridge `globals/theme` policy (auto|light|dark|brand:<slug> + contrast + defaultTheme) with manage-gated PATCH, 60 s cache, rebuild-webhook fire; storefront policy ladder (policy → user → system → default) with forced-mode toggle disabling, build-baked + drift-gated policy for prerendered pages, per-request reads + cache revision for SSR; forge `--theme-policy` preset + template policy module; MCP `theme_apply` preview-only semantics under a forced policy (`NVOOS_THEME_PREVIEW=1` to preview). Owner decisions O-1…O-6 all followed the recommendations (envelope/WP wins; bake-at-build; contrast in v1; preview-only MCP).
**Date:** 2026-11-03
**Relates to:** Proposal 104 (deferred Q6 — this is that increment) · Proposal 092 (storefront layer + contract) · Proposal 095 (ADS tokens + runtime themes — the registry authority) · Proposal 096 (Astro Forge — inherits the policy block) · Proposal 097 (cross-surface NDS sharing — the `$extensions` consumer) · `mcp-ai-wpoos-astro-storefront` skill · `mcp-ai-wpoos-astro-forge` skill
**Research:** `105-bridge-theme-policy-research.md`
**Author:** NV oOS coding agent (Zed)

---

## 1. Problem Statement

Proposal 104 shipped the client-side theme runtime for `examples/nvoos-astro-velocity`: the `[data-theme]` / `[data-contrast]` contract, the ADS-driven theme registry, persistence + FOUC-free boot + `astro:after-swap` re-application, and the Theme/Contrast toggles. What 104 deliberately left out (owner decision Q6) is the **policy layer above user preference**:

1. **The operator cannot force a theme.** There is no way to pin the store to dark (or a brand theme) for all visitors, or to run a "force light while we fix the dark palette" window. Every visitor's `localStorage` preference wins; the only governance today is code or the MCP state file — a dev-level knob, not a store setting.
2. **The operator cannot lock the switcher.** next-themes and Foxi both define forced mode as *"disable any theme switching UI"*; the storefront has no such state, so a forced theme would desync the toggles (they'd still cycle and persist, fighting the policy on the next navigation).
3. **No contrast governance.** The 104 high-contrast axis is user-only. An accessibility-conscious operator (or one following WCAG-driven brand rules) cannot make high contrast the site default.
4. **No contract surface exists for any of this.** The bridge serves `globals/banner` and `globals/carousel` as owner-set settings; theme policy has no equivalent document, so the storefront has nothing to consume at build or render time.

This is the last deferred item from Proposal 104's owner-decision list. It is a cross-addon contract change: the bridge (PHP, `nvoos-commerce/v1`) gains a policy document and the storefront + forge consume it.

## 2. Goals and non-goals

**Goals**
- G1: An owner-set, typed, validated theme policy in the bridge (`auto | light | dark | brand:<slug>` + contrast + default fallback), editable from the existing admin surface with presets.
- G2: Cache-safe delivery: static pages bake the policy at build; SSR pages read it per request; per-user values never enter shared HTML (the teispace/next-themes rule).
- G3: A single precedence ladder enforced in one pure module: **policy → user preference → system → default** (Foxi/next-themes order), with forced modes disabling the switching UI accessibly.
- G4: Forge parity — the template inherits the policy block and gains a `--theme-policy` preset.
- G5: Fast propagation (≤ 60 s for SSR, rebuild-webhook for static) and fail-open behaviour on unknown values (never a white screen).

**Non-goals**
- NG1: The bridge does **not** define themes. Theme names and definitions remain the ADS registry's authority (Proposal 095/098). The policy references names opaquely.
- NG2: No cross-surface NDS token sharing here — that is Proposal 097. The contract reserves `$extensions` for it.
- NG3: No per-visitor server-side theming (segmentation, A/B theming). The policy is site-wide, which is exactly what makes it cache-safe.

## 3. Authority split (the core design)

| Concern | Authority | Evidence |
|---|---|---|
| Theme **names and definitions** (token values, light/dark blocks) | **Storefront ADS registry** (`adsThemeNames`, MCP `theme_create`/import flows) | 095/098 shipped; Shopify references theme files the same way |
| Theme **policy values** (force/auto/contrast/default) | **Bridge** (WP option + `/globals/theme`) | Shopify `settings_schema.json`; the bridge already owns banner/carousel/product-source |
| Validation of a policy against the registry | Storefront (fail-open to `auto` + warning) | Registry is app-side; the bridge cannot enumerate app themes without coupling |

Consequence: the bridge validates the *shape* (enums, slug regex); the app validates the *referenced theme exists*. An unknown `brand:<slug>` never breaks rendering — it resolves to `auto` with a console + admin warning.

## 4. Precedence ladder

One pure function, one place: `resolvePolicyTheme(policy, storage, media, registry) → { theme, contrast, forced }` (unit-test matrix in Phase 2).

1. **Forced theme** — `policy = light | dark | brand:<slug>` (slug validated against the registry): applied at boot, `localStorage` neither read nor written, toggles render disabled with an accessible note (Foxi: "switcher non-functional"; next-themes: "disable any theme switching UI").
2. **Auto theme** — `policy = auto`: stored preference → `prefers-color-scheme` → `defaultTheme`.
3. **Contrast axis** — `policy.contrast = standard | high`: forced, toggle disabled. `auto`: stored contrast → `standard`.
4. **MCP runtime override** — `theme_apply` remains a **preview** facility (dev / `/manage` Design tab preview). In production builds it serves previews only when `NVOOS_THEME_PREVIEW=1`; the serving authority is always the policy. (Owner decision O-5.)

## 5. Contract surface

### 5.1 Bridge: `GET` / `PATCH` `/globals/theme` (`nvoos-commerce/v1` + legacy alias)

Mirrors `BannerController` exactly: public READABLE (`__return_true`), EDITABLE gated by `manage_astro` (`Schema::CAP_MANAGE`), registered twice per the standard loop in `Plugin::register_rest_routes()`.

- Option: `Schema::OPTION_THEME_POLICY = 'funiq_bridge_theme_policy'`, seeded in `seed_default_options()` with the default doc (below). Runtime filter read (`funiq_bridge_theme_policy`) follows the product-source pattern.
- PATCH accepts a partial `{ policy?, contrast?, defaultTheme? }`; validates enums + `^[a-z0-9-]+$` slug; returns the full normalized doc; rejects unknown fields with a typed error (WP global-styles discipline).
- Caching: the B1 hook's `s-maxage=300` is overridden for this route to `public, s-maxage=60, stale-while-revalidate=15` (finding #7 — policy must propagate faster than catalog content). ETag from the serialized body as today.
- Rebuild: a successful PATCH fires the existing debounced `RebuildWebhook` path (`nvoos-storefront-content-changed`) because static pages bake the policy at build time.
- Admin surface: a "Theme policy" section in the bridge SPA settings with **presets** (auto / light / dark / brand slug input) — Shopify `schema.presets` pattern; the slug field notes that names come from the storefront's ADS registry.

### 5.2 Policy document (envelope)

```json
{
  "policy": "auto",
  "contrast": "auto",
  "defaultTheme": "dark",
  "updatedAt": "2026-11-03T09:00:00Z",
  "$extensions": {}
}
```

- `policy`: `"auto" | "light" | "dark" | "brand:<slug>"` — Foxi `mode` union + named theme.
- `contrast`: `"auto" | "standard" | "high"` — governs the 104 `data-contrast` axis.
- `defaultTheme`: `"light" | "dark"` — the auto-mode fallback when there is no stored preference and no system signal (replaces the boot script's hardcoded ternary fallback).
- `updatedAt`: server-stamped revision — the SSR HTML cache key component.
- `$extensions`: reserved for Proposal 097's NDS mapping (DTCG extension point); v1 always `{}`.

### 5.3 Cache-safe delivery (the load-bearing rule)

- **Prerendered pages** (home, categories, brands, colors, promo, 404): the policy is fetched once at build time (`NVOOS_API_BASE`) into a generated, committed, drift-gated module — `src/lib/theme/policy.generated.ts` — emitted by `scripts/build-policy.mjs` and verified by `scripts/check-policy.mjs` (chained into `npm run check`, same discipline as `build:tokens`/`check:tokens`). The boot script inlines the policy JSON.
- **SSR pages** (PDP, PLP): `getThemePolicy(Astro.url)` with a 60 s in-process TTL; HTML `Astro.cache` entry keys append `policy.updatedAt` so a policy change cannot be masked by a stale cached page.
- **BFF micro-cache**: `/api/globals/theme` is cached by the existing `proxyWithCache` for at most the backend's `s-maxage=60`.
- **Never baked:** localStorage values, system signals (teispace rule). The boot script keeps the 104 shape — it applies `policy → storage → media` but the *only* thing baked into HTML is the site-wide policy.

### 5.4 Propagation matrix

| Change | Static pages | SSR pages | Switcher state |
|---|---|---|---|
| PATCH policy | rebuild webhook → sync workflow (minutes) | ≤ 60 s (policy TTL) + cache-key revision | immediate on next render |
| New theme in ADS registry | normal token/build pipeline (existing) | same | appears in menu on next build |

### 5.5 Forced-mode UI

`ThemeToggle` / `ContrastToggle` gain a `data-policy="forced"` state: buttons render `disabled` + `aria-disabled="true"`, the menu is not mounted, the label shows the forced theme name, and a visually-hidden/plain-text note says the theme is set by the store. Both already delegate clicks on `document`, so the policy check is one guard in the existing handler (no new binding — the 104 Defect A lesson).

## 6. Phases

Each phase carries its own exit gate; P1 and P2 are one PR cluster (bridge + storefront contract are inseparable), P3 can ship in parallel.

### Phase 1 — Bridge policy document (PHP + admin)

- `Schema::OPTION_THEME_POLICY`, default doc, `seed_default_options()` entry.
- `ThemePolicyController` (`GET` public / `PATCH` manage-gated, validation + typed errors, per-route `s-maxage=60`).
- Rebuild-webhook firing on successful PATCH (through the existing static `$pending` seam).
- Bridge SPA settings section (presets + slug field), standalone + wp-admin bundles.
- Fixture parity note for Phase 2 (the hermetic fixture server gains the same route shape).
- **Gate:** PHPUnit (validation matrix, permission gates, cache headers, webhook fire), phpcs clean on the bridge ruleset, WP 6.9 + 7.1 matrix green.

### Phase 2 — Storefront consumption (velocity)

- `src/lib/theme/policy.ts` — pure parse/validate/resolve ladder (no Astro imports, forge-portable like `theme.ts`).
- `scripts/build-policy.mjs` + `scripts/check-policy.mjs` → `policy.generated.ts` (drift-gated, linted, repo-style TS emission).
- `BaseLayout.astro` boot script: policy inlined; ladder applied; `color-scheme` kept in sync with the *forced* theme.
- `ThemeToggle`/`ContrastToggle` forced states (5.5).
- `getThemePolicy()` in the commerce client; SSR cache keys append `policy.updatedAt`.
- Fixture server `globals/theme` route (+ PATCH seam for e2e); e2e `theme-policy.spec.ts`: forced light, forced dark, forced brand, unknown slug → fail-open auto, auto + stored pref, forced contrast, switcher disabled a11y assertions (axe).
- **Gate:** unit matrix (≥ 8 ladder cases + cache-key + drift gate), full e2e (25 existing + new spec), axe gate, lint, `astro check`, LHCI, `build:prod-sim` green; golden contract-grep unchanged for the existing surfaces.

### Phase 3 — Forge parity

- Template copies `policy.ts` + the policy block of the boot script verbatim (104's forge-portable rule).
- `forge.mjs` preset `--theme-policy auto|light|dark|brand:<slug>` writing the generated-project policy stub; template tests.
- **Gate:** forge unit (215 baseline + new), typecheck, e2e, CLI smoke green.

### Phase 4 — Preview semantics + docs

- MCP `theme_apply` preview-only semantics in production (`NVOOS_THEME_PREVIEW=1` gate), documented in the tool description + skill.
- Docs: 105 proposal set, deployment doc (`nvoos-astro-velocity-setup.md`), `mcp-ai-wpoos-astro-storefront` + `mcp-ai-wpoos-astro-forge` skills; `092`/`095` status notes.
- **Gate:** docs cross-check script, skill size under Zed's 100 KB limit, description-budget check.

## 7. Owner decisions

| # | Question | Recommendation |
|---|---|---|
| O-1 | Ship the policy as `globals/theme` (alongside banner/carousel) vs a dedicated `theme` route? | **`globals/theme`** — the established settings surface; a new top-level resource adds a controller pattern for one doc. |
| O-2 | In forced modes, should the policy override a returning visitor's stored preference? | **Yes** — that is the definition of forced (Foxi, next-themes). The stored value is left untouched so un-forcing restores it. |
| O-3 | Bake at build + rebuild-on-change (static) vs runtime-fetch-only for prerendered pages? | **Bake at build** — runtime-only fetches flash or block first paint (FOUC); the rebuild webhook already exists. |
| O-4 | Include `contrast` governance in v1, or defer the axis to 097 as 104's note suggested? | **Include in v1** — the axis already shipped in 104; governance is one enum + one guard. 097 remains the NDS *sharing* work, not this. |
| O-5 | Should MCP `theme_apply` keep overriding the policy at runtime in production, or become preview-only? | **Preview-only in production** (env-gated). Two serving authorities on a public storefront is how the 104 Defect A class of bug happens; preview stays available in dev and on the /manage Design tab. |
| O-6 | Ship `defaultTheme` now (the auto-mode fallback when there is no stored pref and no system signal)? | **Yes** — one field, removes the hardcoded ternary branch, matches Starlight's configurable fallback. |

## 8. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Per-user value leaks into baked HTML (cache poisoning) | Design rule: only the policy is baked; pinned by a unit test asserting the boot output contains no localStorage-derived values at build |
| Policy change masked by cached SSR HTML | SSR cache keys include `policy.updatedAt`; policy doc `s-maxage=60` |
| Unknown `brand:<slug>` (theme deleted app-side) | Fail-open to `auto` + console/admin warning; e2e case covers it |
| Toggle/policy desync on SPA navigation | Toggles already re-sync on `astro:after-swap`; the forced guard runs in the same delegated handlers (no new bindings) |
| Fixture-server drift from the bridge route | `globals/theme` added to the fixture server in the same PR cluster; contract-grep test extended |
| Prop 097 needs extra fields later | `$extensions` reserved now (DTCG-sanctioned); additive, non-breaking |

## 9. Acceptance criteria

1. `PATCH /globals/theme` with each valid partial updates the doc; invalid enums/slugs/fields return typed 400s; writes without `manage_astro` return 403 (PHPUnit + a live REST-index cross-check).
2. A forced policy renders the storefront in that theme for a **fresh** visitor (no localStorage) with zero FOUC, on both a static page and an SSR PDP; the toggles are disabled with accessible labels (axe passes).
3. A returning visitor's stored dark preference is **ignored** under `policy: light` and **restored** when the policy returns to `auto` (e2e).
4. `policy: brand:example-brand` applies `data-theme="example-brand"`; `brand:missing-theme` renders default auto without errors.
5. Policy PATCH → webhook fires (tested through the `$pending` seam) and `/api/globals/theme` reflects the new doc within 60 s without a redeploy.
6. All existing gates stay green: 230 velocity unit + 19 smoke + 25 e2e (plus new spec), forge 215 unit + 20 e2e, lint, `astro check`, axe, LHCI, `build:prod-sim`, phpcs + PHPUnit on the bridge.
7. Forge `--theme-policy dark` generates a project whose boot block enforces dark; `--theme-policy` omitted generates the `auto` default.

## 10. Open questions (recorded, non-blocking)

- **Q1 (deferred → 097):** the exact `$extensions` schema for NDS mapping (Pro addon palettes, Pro SPA v2) — intentionally not designed here; the key is reserved.
- **Q2:** should the policy doc later grow `perChannel`/`perLocale` scoping (multi-site Velocity)? Out of scope for v1; the doc shape is additive.
- **Q3:** the Pro plugin's read-only profile gate and the storefront policy are the same concept on two surfaces — worth one shared "policy vocabulary" doc later (flagged for the next ADS increment, not this proposal).

## Appendix — touched surfaces

**Bridge (P1):** `includes/Schema.php`, `includes/Plugin.php`, `includes/REST/ThemePolicyController.php` (new), `includes/RebuildWebhook.php` (fire-on-patch), `src/` settings section, `tests/phpunit/…`.
**Storefront (P2):** `src/lib/theme/policy.ts` (new), `src/lib/theme/policy.generated.ts` (generated), `scripts/build-policy.mjs` + `scripts/check-policy.mjs` (new), `src/layouts/BaseLayout.astro`, `src/components/ThemeToggle.astro` + `ContrastToggle.astro`, `src/lib/commerce/client.ts`, SSR pages (cache keys), `scripts/fixture-server.mjs`, `tests/unit/*`, `tests/e2e/theme-policy.spec.ts`.
**Forge (P3):** `examples/nvoos-astro-forge/scripts/forge.mjs`, `lib/presets.ts`, `templates/blog/src/lib/theme/policy.ts` (copy), `templates/blog/src/layouts/Base.astro`, template tests.
**MCP/docs (P4):** `mcp/tools/design.ts` (preview semantics), deployment doc, both astro skills, this proposal set.
