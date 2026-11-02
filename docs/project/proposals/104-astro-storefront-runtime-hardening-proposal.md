# Proposal 104 — Storefront Runtime Hardening: Theme Persistence, Correct Product Pages, and the Forge Inheritance Contract

**Status:** IMPLEMENTED (2026-11-02) — P1–P5 shipped in `examples/nvoos-astro-velocity` and `examples/nvoos-astro-forge`; owner decisions Q1/Q2/Q3/Q4/Q5 approved and shipped (Q3 = `/internal/revalidate/`; Q4 = forge `--render ssr` variant; Q5 = OG cards on both tracks); Q6 (bridge theme policy) remains deferred to the next ADS increment.
**Date:** 2026-11-02
**Relates to:** Proposal 092 (storefront layer) · Proposal 095 (ADS tokens + runtime themes — the theme system this hardens) · Proposal 096 (Astro Forge — the downstream consumer that must inherit the fixed patterns) · Proposal 098 (design-import — the source of runtime brand themes) · `mcp-ai-wpoos-astro-storefront` skill · `mcp-ai-wpoos-astro-forge` skill
**Author:** NV oOS coding agent (Zed)

---

## 1. Problem Statement

`examples/nvoos-astro-velocity` (the live storefront behind `astro.nvoos.cloud`) has two user-visible defects. Both were reproduced from source; neither is covered by the existing e2e gates.

### 1.1 Defect A — theme reverts to white on client-side navigation

A user picks dark (or a brand) theme on the homepage, then clicks any internal link. The page arrives **white**; the header toggle also forgets its state and starts cycling from light again.

Root cause, in three layers (file evidence):

1. **The whole `<html>` element is swapped on navigation.** `BaseLayout.astro` mounts Astro's `ClientRouter` (`astro:transitions`). On client-side navigation Astro replaces the document element with the incoming page's element — the new element has **no `data-theme` attribute** (the server never renders one), so the `[data-theme="dark"]` / `[data-theme="example-brand"]` override blocks in `src/styles/generated/tokens.css` stop matching and every token falls back to the light `:root` block.
2. **The only boot script runs once per hard load.** The no-FOUC script in `BaseLayout.astro` (L55–72) lives in `<head>` as `is:inline`. Astro executes head scripts only on initial load — they do **not** re-run on client-side navigation. There is no `astro:after-swap` / `astro:page-load` re-apply handler anywhere in `src/` (grep-verified).
3. **The toggle reads reset state.** `src/components/ThemeToggle.astro` derives `currentTheme()` from `document.documentElement.getAttribute('data-theme')`. After a swap that attribute is gone, so the next click computes `light → dark` — skipping the stored brand theme, persisting the wrong value back to `localStorage`, and leaving the button label desynced.

Missing standards pieces that make "white page" feel wrong even when the attribute *is* set: the app never sets the CSS `color-scheme` property (or a `<meta name="color-scheme">`), so scrollbars, form controls, autofill and other browser-provided UI stay light in dark mode (web.dev: *"Improve dark mode default with color-scheme"*).

**Why the tests didn't catch it:** `tests/e2e/theme.spec.ts` covers hard load + `page.reload()` only. A reload re-runs the head script; a client-side navigation does not. The missing case is exactly the bug.

### 1.2 Defect B — product pages don't work in production

On the public mirror, product detail pages 404 (or, where IDs collide, show fixture content) even though the homepage and category pages render the *live* catalog.

Root cause: **the production artifact prerenders PDPs against the fixture contract server.**

1. `src/pages/products/[id]/[...slug].astro` is the app's only `prerender = true` route. `getStaticPaths()` fetches the *entire catalog* at build time (`getAllProducts(undefined)` → `NVOOS_API_BASE`).
2. `.github/workflows/sync-nvoos-astro-velocity.yml` (the mirror assemble step, "Assemble mirror tree") runs the production rebuild with `NVOOS_API_BASE: http://localhost:8123/wp-json/nvoos-commerce/v1` — **the hermetic fixture server** (products `Aurora 3-Seater Sofa`, ids 1…N). The hermetic-build discipline (a feature for CI) is therefore baked into the shipped artifact.
3. At runtime the same-origin `/api/*` proxy serves the **real** backend (`nvoos.pro`), so the SSR'd home/PLP pages render real products and link to `/products/<live-id>/<slug>/`. Those URLs were never prerendered → **404**. The SSR `/sitemap.xml` endpoint lists the same live URLs → the sitemap advertises 404s (crawl-budget + Search Console damage).
4. There is no runtime fallback: `getProduct()` exists in `src/lib/commerce/client.ts` but the PDP never calls it. The route's own comment acknowledges another cost of prerendering: slug drift **cannot 301** on static pages.

The category PLP is already on-demand SSR with `s-maxage` caching — so the fix moves the PDP onto the architecture the rest of the app (and the industry) already uses.

### 1.3 Why this matters for the Forge

The storefront is the template donor for Proposal 096's generated projects. Today the forge inherits the **design tokens** (`tokens_apply`), but not yet the **runtime behaviours** (theme boot, View Transitions persistence, PDP rendering/caching). Whatever we fix here must be packaged so the forge's storefront template inherits the *fixed* pattern — not re-discovers the same bugs. Section 3.5 defines that contract.

---

## 2. Research & Industry Standards

Full sourcing in `104-astro-storefront-runtime-hardening-research.md`. Load-bearing findings:

- **Theme persistence across View Transitions is a solved, documented problem.** Astro's own docs prescribe re-applying the theme in an `astro:after-swap` handler — it fires "immediately after the new page has replaced the old page and **before the DOM elements are painted**", so there is no light-mode flash. Head scripts run once; `data-astro-rerun` only re-runs *non-bundled* inline scripts and is the wrong tool for a state-owning boot. Community reports (Flavio Copes; Stack Overflow; kr4t3rdev/portfolio#13; codeatlasdev/ui#265) describe the exact symptom we have and converge on the same fix. Starlight's stored-theme → `prefers-color-scheme` fallback boot (already partially adopted) is the ecosystem reference for the no-FOUC half.
- **`color-scheme` is table stakes for dark mode.** web.dev + MDN: browser-provided UI (scrollbars, spellcheck underlines, form controls, autofill) follows the *element's* color scheme, not the page's palette. It must be set on the root and kept in sync with theme switches. The app sets it nowhere.
- **Headless-commerce rendering consensus: default SSR for catalog pages.** Shopify Hydrogen's own guidance: "most Hydrogen stores should default to SSR for commerce pages and reserve SSG or ISR for supporting content"; naturaily: "ISR for category pages, ISR plus on-demand revalidation for product pages, SSR for authenticated/transactional"; headless-SEO guides (Patrick Stox, 1Digital, beecommerce) agree — catalogs change and crawlers (including AI crawlers, which never execute JS) must see fresh, complete HTML. Prerendering an entire PDP catalog from a build-time snapshot is the anti-pattern; it is only correct for catalogs that are stable *at deploy time*, which the mirror's fixture build violates.
- **Astro 7 ships the ISR-equivalent natively: route caching.** `cache: { provider: memoryCache() }` + `Astro.cache.set({ maxAge, swr, tags })` + `cache.invalidate({ tags, path })` + config `routeRules`. Tags accumulate and merge with route rules; `cache.enabled` is the dev-mode-safe guard. This is the platform-native replacement for hand-rolled `Cache-Control` headers on SSR PDPs, and Netlify's Astro e-commerce guide calls on-demand revalidation "the holy grail of page caching".
- **Ecosystem scan (AdminLTE.io 2026 roundup):** dark mode + toggle is present in essentially every current Astro 7 theme (AstroWind, Starlight, AstroPaper, Astroplate, Accessible Astro Starter); the Accessible Astro Starter adds the a11y bar we should match (WCAG 2.2 AA toggles incl. reduced-motion respect); the premium "Shopper" storefront template validates the surface set (PLP + category + PDP + cart, with a wishlist we lack). Current-Astro-major discipline is itself called out as a selection criterion — we are already on Astro 7.4.4.

---

## 3. Proposed Design

### 3.1 Theme runtime contract (fixes Defect A)

One module, one source of truth, three consumers.

**New: `src/lib/theme/` (pure, unit-tested)**

- `THEMES = ['light', 'dark', 'example-brand']` (derive the brand list from `src/tokens/bundle.generated.json` themes later — v1 keeps the static list).
- `resolveStoredTheme(): string` — `localStorage['nvoos-theme']` → `matchMedia('(prefers-color-scheme: light)') ? 'light' : 'dark'`. **localStorage is the source of truth**, not the DOM attribute (the attribute is lost on every swap; the storage value is not).
- `applyTheme(theme, root = document.documentElement)` — sets/removes `data-theme` (contract preserved: light = attribute absent — pinned by `theme.spec.ts` L9) and sets `root.style.colorScheme = schemeFor(theme)` (`dark → 'dark'`, everything else `'light'`; brand themes may later declare a scheme via a `$scheme` key in `themes/*.json`).

**`BaseLayout.astro` boot (unchanged position, extended logic)**

The inline head script keeps its no-FOUC role and additionally registers — exactly once per hard load — a persistent document listener:

```js
document.addEventListener('astro:after-swap', () => applyTheme(resolveStoredTheme()));
```

`document` listeners survive the swap (the element is replaced; the `document` is not), so one registration covers every subsequent client-side navigation, and the re-apply happens before first paint (Astro docs' anti-flash guarantee). This is the documented Astro pattern, not a workaround.

**`ThemeToggle.astro` (state fix + a11y)**

- `currentTheme()` switches from the DOM attribute to `resolveStoredTheme()` — ordering between the boot's `after-swap` listener and the per-navigation re-run of the bundled component script no longer matters.
- On module run, sync the button label from the resolved theme (so the label survives navigation).
- `aria-pressed` on the button, `prefers-reduced-motion` respected for any transition polish (Accessible Astro Starter parity).

**CSS fallback (no-JS)** — in `global.css`: `:root { color-scheme: light; } [data-theme="dark"] { color-scheme: dark; }`. JS users get the exact per-theme value from the boot script; JS-disabled users get a correct-enough fallback.

### 3.2 PDP rendering model (fixes Defect B)

`src/pages/products/[id]/[...slug].astro` becomes on-demand SSR:

1. Drop `prerender = true` + `getStaticPaths`. Resolve `id` from `Astro.params.id`; `getProduct(id, Astro.url)`; `null → 404`; non-integer → 404.
2. **Canonical slug enforcement becomes a real 301**: if the requested suffix ≠ `slugify(name)`, `Astro.redirect(productPath(id, name), 301)` — replacing the current comment ("prerendered pages cannot 301"). Slug drift now converges instead of duplicating.
3. Related products are fetched at request time (`getProducts` with `where.category.equals`, `limit: 8`, `sort: '-date'`, self excluded) — no more whole-catalog prop injection.
4. **Caching (Astro 7 route caching):** `astro.config.mjs` gains `cache: { provider: memoryCache() }` and a route rule for `/products/[...path]` (`maxAge: 300, swr: 600, tags: ['products']`); the page calls `Astro.cache.set({ tags: [... , \`product-${id}\`] })` behind `Astro.cache.enabled`, and keeps the existing `Cache-Control: public, s-maxage=300, stale-while-revalidate=600` header as the CDN/edge layer. Tag invalidation (`cache.invalidate`) is wired in 3.4.
5. JSON-LD, gallery, islands, breadcrumbs, `/_image` pipeline: unchanged — the SSR page emits the same initial HTML the crawlability spec already pins.

**Mirror workflow:** the "Assemble mirror tree" step drops `NVOOS_API_BASE` entirely — the production build becomes **fully hermetic** (only `ASTRO_SITE` needed). The fixture base stays on the localhost smoke/e2e build only, where it belongs. This deletes the entire class of "fixture data shipped to production" bugs.

### 3.3 Catalog surface (completes Defect B's story)

New `src/pages/products/index.astro` — an all-catalog PLP mirroring the category PLP's proven pattern (filters: category/brand/color + `q` + sort + pagination; canonical on `/products/`; `noindex,follow` when filtered; `s-maxage` caching; `routeRules` tag `products`). Gains: shoppers get a catalog entry point, the header nav gains a Products link, the sitemap gains the `/products/` base. (The current surfaces — categories/brands/colors/search — remain.)

### 3.4 Invalidation loop (completes the caching story)

- **Within a deployment:** new internal BFF route `POST /internal/revalidate` (shared-secret bearer, host-scoped, rate-limited) calling `cache.invalidate({ tags })`; the backend bridge webhook can hit it in addition to (or instead of) the full GitHub-dispatch redeploy. Owner decision Q3.
- **Across deployments:** unchanged — Velocity redeploys on every mirror sync, which resets the in-process memory cache naturally. Nothing to build.

### 3.5 Forge inheritance contract (the foundation ask)

The forge must inherit **behaviour**, not just tokens. Deliverables:

1. **Theme runtime module** is authored as a copy-paste-ready, zero-Astro-dependency unit (`resolveStoredTheme`/`applyTheme` + boot snippet + CSS fallback). Phase 4 copies it into the forge template with the same e2e pattern.
2. **A "storefront runtime contract" section** added to the forge's template README (and to Proposal 096's implementation plan): the four behaviours every generated storefront must satisfy — (a) no-FOUC boot + `astro:after-swap` re-apply + `color-scheme` sync, (b) PDP canonical URL + 301 slug convergence, (c) `Product` JSON-LD in initial HTML, (d) cache headers + tags on commerce routes.
3. **Forge rendering option (owner decision Q4):** today the forge's product template is static content-layer output — correct for its static-first model (build from the customer's live REST, webhook → rebuild). The proposal defines, but does not default to, an `NVOOS_FORGE_RENDER=ssr` variant of the product template for large catalogs, using the same SSR+cache pattern fixed here.

---

## 4. Phases

| Phase | Scope | Exit gate |
|---|---|---|
| **P1** | Theme runtime contract (3.1): module, boot listener, toggle fix, color-scheme, CSS fallback | New e2e: theme persists across client-side navigation; unit tests for the module; existing 101 vitest + 2 Playwright specs stay green |
| **P2** | PDP SSR + 301 + caching (3.2) + mirror workflow change | Smoke/e2e on fixture BFF: PDP 200 w/ JSON-LD, unknown id 404, wrong slug 301; prod rebuild runs with no `NVOOS_API_BASE` |
| **P3** | `/products/` PLP + nav + sitemap entry (3.3) | e2e: PLP renders, filters work, canonical/noindex discipline holds |
| **P4** | Invalidation route (3.4) + forge inheritance contract (3.5) | `/internal/revalidate` auth-gated tests; forge template PR consumes the theme module + contract docs |
| **P5** | Docs/skills: update Proposal 092 references, `mcp-ai-wpoos-astro-storefront` skill (rendering model + theme gotchas), `docs/operations/deployment/nvoos-astro-velocity-setup.md`, app README | `composer run docs:check-folder-readmes` unaffected; skill stays under the description-cap budget |

P1 and P2 are independent and can land in one PR cluster; P3–P5 follow.

---

## 5. Owner Decisions

- **Q1 — PDP on-demand SSR.** Approve replacing the 092 §5.4 build-time-prerender model with on-demand SSR + route caching (industry default per §2). The prerendered-PDP approach is not recoverable as-is: the mirror build is hermetic by design and must not reach the production backend at build time.
- **Q2 — Astro 7 `memoryCache` provider on Velocity.** In-process only; edge caching remains the CDN's `s-maxage` layer. (Alternative: skip `Astro.cache` and keep headers-only — strictly inferior for SSR compute, but lower risk. Recommended: adopt.)
- **Q3 — `/internal/revalidate` BFF route.** Same-deploy tag invalidation needs a shared secret env on Velocity. Worth it only if content changes must be visible within a deploy's lifetime without a redeploy. Default recommendation: **defer** (redeploy loop already covers it) unless the bridge webhook cadence becomes too slow.
- **Q4 — Forge `ssr` rendering option.** Define-only now, or implement the template variant in this proposal? Default recommendation: **define-only**; implementation rides the next forge storefront-parity increment.
- **Q5 — Dynamic OG images (Satori) for PDPs** surfaced as a gap by the ecosystem scan — include as a P3 stretch or defer to a later proposal? Default recommendation: defer.

---

## 6. Risks & Rollback

- **P2 changes the public URL behaviour** (404/301). Mitigation: 301 convergence only tightens SEO (Google: consolidate, don't drop); the crawlability e2e pins initial-HTML JSON-LD; rollback = restore `prerender` + workflow env (reverts to the known bug, not worse).
- **`astro:after-swap` listener ordering** with the per-navigation toggle script. Mitigated by making localStorage the single source of truth (§3.1) so ordering cannot corrupt state; e2e asserts both attribute and label after navigation.
- **`color-scheme: dark` surfaces form-control contrast bugs** (the webspecification.com warning). Mitigation: apply per-theme from the map, audit the PLP filter form + promo field in dark mode during P1 (the ADS WCAG contrast tooling already exists for token pairs).
- **memoryCache memory growth** on Velocity under crawl load (cached PDP × catalog size). Mitigation: tags + `maxAge: 300` bound the set; `cache.invalidate` clears on webhook; monitor via existing app metrics. If it ever misbehaves, Q2's alternative (headers-only) is a one-line rollback.
- **Mirror build hermeticity change** touches the deploy workflow — any regression blocks deploys, not the live site (mirror force-push is atomic). The workflow is exercised by the PR's own CI before merge.

---

## 7. Success Metrics & Gates

- **Zero theme resets:** new e2e navigates `/` → `/tokens/` → `/categories/1/sofas/` client-side under `data-theme="dark"` and asserts the attribute, the `colorScheme` style, and the toggle label survive every swap. Fails before P1, passes after.
- **Zero PDP 404s from the sitemap:** crawl gate asserts every `/products/` URL in `/sitemap.xml` returns 200 on the mirror (scripted smoke post-deploy; the in-CI smoke covers the fixture equivalent).
- **Existing gates unchanged and enforced:** vitest (101 tests), Playwright (2 specs, extended), `astro check`, eslint, tokens drift gate, LHCI (perf ≥ 0.8, a11y ≥ 0.9, best-practices ≥ 0.9, seo ≥ 0.95), CI grep gates (no backend URLs in `src/`, "funiq" ban untouched).
- **Forge:** the contract section (3.5) is copy-pasteable and its first consumer PR demonstrates the theme module port with its own e2e.

---

## 8. Open Questions

1. Should `data-theme="light"` be set explicitly instead of the absent-attribute contract? (Recommendation: keep the current contract — it is pinned by tests and by the generated CSS structure; changing it is churn with no user-visible gain.)
2. Brand-theme colour schemes: `$scheme` key in `themes/*.json` now, or static `dark → dark` mapping for v1? (Recommendation: static mapping for v1; `$scheme` rides the next ADS token-schema increment.)
3. Does the `/products/` PLP need a search-input hero (duplicating `/search/`), or is the nav + filter set sufficient? (Recommendation: filters only; search stays a dedicated surface.)
4. **Q6 (new — ecosystem scan):** adopt the bridge-level `theme_policy` option (Foxi's `mode: auto|light|dark`) plus the named-theme menu + high-contrast axis now, or fold them into the next ADS increment? (Recommendation: policy + theme-registry menu in 104; high-contrast axis with the NDS cross-surface work in 097.)

---

## References

- `104-astro-storefront-runtime-hardening-research.md` (full sourcing) · `104-astro-storefront-runtime-hardening-implementation-plan.md` (file-level work items) · `104-astro-ecosystem-repo-analysis.md` (the 15-repo deep read with the Pro-toolkit mapping — source of the P3-stretch items in §3)
- Astro docs: View Transitions (script lifecycle, `astro:after-swap`, `data-astro-rerun`) · Route caching (`Astro.cache`, providers, `routeRules`, invalidation)
- web.dev: *Improve dark mode default with color-scheme and a meta tag* · MDN: `color-scheme`
- Headless-commerce rendering: Shopify Hydrogen SEO guide (1Digital) · Patrick Stox · beecommerce · naturaily (Next.js ecommerce architecture) · Netlify: *Modern e-commerce caching with Astro*
- AdminLTE.io: *15 Best Free Astro Templates for Developers (2026)* — ecosystem/dark-mode/a11y scan
- Repo: `examples/nvoos-astro-velocity/src/{layouts/BaseLayout.astro, components/ThemeToggle.astro, pages/products/[id]/[...slug].astro, pages/sitemap.xml.ts, styles/global.css}` · `.github/workflows/sync-nvoos-astro-velocity.yml` · `tests/e2e/theme.spec.ts`
