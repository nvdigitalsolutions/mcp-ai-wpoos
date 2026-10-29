# Proposal 092 — Astro Storefront Layer: Comprehensive Implementation Plan

> **Status:** Implementation Plan (companion to Proposal 092) · **Target:** Phases 0–2 in ~7–9 weeks (parallelizable with Proposal 090), Phase 3 gated on owner decision, Phase 4 optional · **Platform:** Astro 7.x SSR on Cloudways Velocity (Node adapter) + WordPress running `addons/funiq-bridge`
>
> **Last Updated:** 2026-10-29
> **Decisions:** Q1–Q7 answered by owner 2026-10-29 — recorded in §12. Namespace decision: the commerce contract is served under **`nvoos-commerce/v1`** (new) alongside legacy `funiq/v1` (PWA rollback only); DB-level renames ride the Laravel ETL.
> **Execution status (2026-10-29):** Phase 0 **shipped and verified** — JS gates: `astro check` clean, 41/41 vitest incl. the D3 contract gate, hermetic `astro build` prerendering the fixture catalog, 9/9 BFF smoke checks. **Bridge gates (Docker):** 21/21 PHPUnit green on **both** WP 6.9 and WP 7.1 (existing suite + new `TestCommerceContractExtensions` covering B1 headers, B2 where/sort, the `nvoos-commerce/v1` alias, and B4 auth/promocode validation); phpcs clean on all new code. Bridge test wiring: self-guarding `addons/funiq-bridge/tests/bootstrap.php` + root `phpunit.xml.dist` scan entry (activation-mirroring cap grant; `do_action('init')` re-fire replaced with direct CPT registration per test-suite skill pattern #2). **Public mirror `nvdigitalsolutions/nvoos-astro-velocity` created (gh CLI) and seeded** with the assembled deploy tree (dist/ + BFF + runtime package.json). Remaining: owner secrets (`NVOOS_ASTRO_VELOCITY_REPO_TOKEN`), Velocity app creation, `astro:assets` image optimization, Playwright/LHCI gates. Phase 3 = standing A/B (Q1). Phase 4 scheduled v1.5 (Q3).
> **Author:** NV oOS coding agent (Zed)
>
> **Based on:** Proposal 092 (`092-astro-storefront-layer-proposal.md`), the 085 appendix (contract invariant §2, topologies §4, Strangler Fig §5), the two proven Velocity apps (`examples/schedule-anything-velocity`, `examples/nvoos-complete-velocity`), the funiq-bridge REST surface (verified in `addons/funiq-bridge/includes/REST/` + `Transformers/`), and fresh web research (Astro docs — islands, view transitions, caching, WordPress guide; `withastro/storefront`; JSON-LD/e-commerce SEO guides; hydration-directive guidance; Astro MCP landscape).

---

## Table of Contents

1. [Research Synthesis — What Industry Standards Changed](#1-research-synthesis--what-industry-standards-changed)
2. [Architecture Decisions](#2-architecture-decisions)
3. [Contract & Data Model](#3-contract--data-model)
4. [Repository Structure & Licensing](#4-repository-structure--licensing)
5. [App Architecture](#5-app-architecture)
6. [Phase-by-Phase Build Plan](#6-phase-by-phase-build-plan)
7. [Bridge Enhancements (tracked separately)](#7-bridge-enhancements-tracked-separately)
8. [Testing Strategy](#8-testing-strategy)
9. [Deploy & Sync Runbook](#9-deploy--sync-runbook)
10. [Verification Matrix](#10-verification-matrix)
11. [Risks & Mitigations](#11-risks--mitigations)
12. [Open Decisions](#12-open-decisions)
13. [References](#13-references)

---

## 1. Research Synthesis — What Industry Standards Changed

Fresh research against the proposal's §2 produced one correction, two
confirmations, and five refinements that are now baked into this plan:

| # | Finding | Consequence in this plan |
|---|---|---|
| R1 | **Correction — the Astro MCP server is a docs-knowledge MCP** (`mcp.docs.astro.build`), not a code-editing agent runtime. It gives AI tools current framework knowledge; it does not scaffold or edit projects by itself. (Proposal §2.5 overstated the "AI-Ready" build loop.) | Plan treats Astro MCP as **assistant support**, with correctness enforced by CI (`astro check`, ESLint, vitest, Lighthouse) — agents write, gates verify. The community "WP Astro MCP" (WP→Astro migration) is flagged as a Phase 4 candidate only, third-party. |
| R2 | **Confirmation — route caching**: Astro 7 introduces route-level `Astro.cache({ revalidate })`; the pre-7 pattern is setting `Cache-Control: s-maxage + stale-while-revalidate` and letting the CDN cache. | Fetch layer uses `Astro.cache` where the pinned minor supports it; **always** emit explicit `Cache-Control` headers so the Velocity CDN and the `serve` layer cache regardless. |
| R3 | **Confirmation — hydration directives are the industry norm**: `client:load` only for above-the-fold interactivity, `client:idle` for below-fold, `client:visible` for lazy pockets. | Cart badge → `client:idle` (small, non-critical); gallery lightbox → `client:visible`; promo-code field → `client:visible`. Nothing but genuinely above-fold interactive pieces gets `client:load`. |
| R4 | **Refinement — View Transitions caveats**: with `ClientRouter`, tag managers/consent platforms don't fire pageviews (no real navigations); islands with state (cart) rehydrate fresh unless persisted; `transition:persist` preserves island state. | Analytics hook forwarding (`astro:page-load`) is a named Phase 1 task; the cart badge island uses `transition:persist`. |
| R5 | **Refinement — the "reuse the PWA's React components" claim collides with the Envato boundary**. The PWA's React sources are licensed code; copying cart/gallery components into the public MIT monorepo would violate the Regular License the same way the bundle injection would (085 D2/D3). | **Clean-room rule (D6)**: islands are re-authored NV-side from the *contract shape* (cart badge is trivial; gallery lightbox is small). Licensed-component CI injection is the documented fallback only if parity effort balloons. |
| R6 | **Refinement — cache-header synergy is free**: the existing Velocity `serve.mjs` pattern already ships a *default-deny* proxy micro-cache that only caches proxied GETs WordPress marks `Cache-Control: public`. The proposal's bridge enhancement (B1) therefore unlocks **two cache layers at once** (proxy micro-cache + Astro fetch cache) with a single bridge change. | B1 is scheduled in Phase 0/1, not deferred; it also keeps the PWA (rollback) fast during coexistence. |
| R7 | **Refinement — media URLs leak the backend origin and break the T1 flip**. The bridge returns absolute WordPress upload URLs (`image`, `images`). Keeping them absolute makes the storefront WP-aware and breaks when the Laravel service (Flysystem media per 085 appendix §2) serves the contract. | **Media normalization (D7)**: the Astro client rewrites media URLs to a same-origin `/media/*` path; a proxy rule maps `/media/*` to the current origin's uploads base. Zero bridge changes, zero app changes at T1, and `astro:assets` image optimization works same-origin without `image.domains` config. |
| R8 | **Refinement — the contract has gaps the plan must close**: `where` filtering today supports only `id.equals` and `category.equals` (verified in `ProductsController::apply_where_query`); there is no currency field; no slug field; no stock field. | B2 adds Payload-style `equals`/`in` operators for brand/color/status. Currency is an app-level config (`NVOOS_CURRENCY`) until contract liberation. URLs use `id` + locally slugified name (D5). `availability` defaults to `InStock` with a doc note. |
| R9 | **Refinement — prerender staleness needs a revalidation story**: a build-time-prerendered catalog goes stale between builds. | Sitemap `lastmod` from `updatedAt` + bridge "content changed" webhook → Velocity redeploy (Phase 2); on-demand SSR for any route not prerendered (new products) as the safety net. |

**Industry references that shaped the architecture** (full list in §13):

- **`withastro/storefront`** — Astro's official e-commerce reference: file-based product/collection routes, server-side checkout via `pages/api`, dynamic collection filtering, minimal islands. This plan mirrors its route taxonomy and rendering split, substituting React islands (R5) and the funiq contract for Shopify.
- **Astro docs** — islands architecture (isolated hydration), view transitions (`ClientRouter`, `transition:persist`), caching guide (`Astro.cache`, `s-maxage` + `stale-while-revalidate`), WordPress CMS guide (REST as content source), server islands (`server:defer`).
- **E-commerce SEO guides** (Shopify schema guide, 1SEO, Dynamic SEO, Glukhov breadcrumbs): the core four JSON-LD types are **Product + Offer + BreadcrumbList + Organization**, rendered server-side in the initial HTML; canonical on filtered PLPs; `noindex,follow` on secondary filter permutations for crawl-budget control; sitemap with `lastmod`.
- **Hydration guidance** (strapi.io, redlinesoft, Astro docs): "the more you scroll, the later you hydrate."

---

## 2. Architecture Decisions

| # | Decision | Alternatives Considered | Rationale | Industry Source |
|---|---|---|---|---|
| D1 | **Hybrid rendering**: `output: 'server'` with per-route `prerender = true` for the full product catalog + PLP page 1; on-demand SSR for filters/search and server islands | All-SSG (rebuild on every change); all-SSR (Velocity CPU + latency) | Product data is low-churn (proposal §3.4); hybrid is the documented e-commerce pattern — crawlers get instant HTML, filters stay fresh | Astro [on-demand rendering](https://docs.astro.build/en/concepts/on-demand-rendering/); `withastro/storefront` |
| D2 | **One Astro Node app doubles as the BFF**: `server.mjs` mounts Astro's standalone handler and falls through to proxy rules for `/api/*`, `/media/*`, `/wp-json/*`, `/wp-admin/*`, `/wp-login.php`, `/wp-includes/*` | Separate proxy process + Astro on a second port; Astro middleware for proxying | Preserves the 085 §3.2 same-origin BFF model and the Velocity single-app/PM2 deploy mechanics; one origin, one cookie scope, no CORS | Repo precedent: `scripts/serve.mjs` in both Velocity apps; Auth0 [BFF pattern](https://auth0.com/blog/the-backend-for-frontend-pattern-bff/) |
| D3 | **Contract-only binding, mechanically enforced**: the Astro app may not reference WP-specific endpoints, admin URLs, or backend nonce semantics. A vitest gate (`tests/unit/contract-grep.test.ts`) scans `src/` for `/wp-json|wp-admin|wp-login|wp_rest|wp-includes|funiq/i` and fails CI on drift. (The contract's own auth nonce path is allowed — Q5 auth is contract surface, not backend nonce semantics.) | Code-review-only discipline | Makes the T1 flip provably zero-change; CI-enforced invariants are cheaper than reviews | 085 appendix §2 (contract invariant); proposal §3.2 |
| D4 | **Fetch layer with `Astro.cache` + always-on HTTP headers**: collections/products cached keyed by URL+params (revalidate 60–300 s); every HTML response carries explicit `Cache-Control` (prerendered: `public, s-maxage=3600, stale-while-revalidate=86400`; SSR filter pages: `public, s-maxage=60`; `Vary` where relevant) | Rely on CDN defaults; cache at the bridge only | Astro 7's route caching is the documented primitive; explicit headers keep the Velocity CDN + micro-cache working even where `Astro.cache` is unavailable | Astro [caching guide](https://docs.astro.build/en/guides/caching/); MojoAuth [Astro cache guide](https://mojoauth.com/dev-guides/implement-caching-with-astro); Netlify [ISR guide](https://developers.netlify.com/guides/how-to-do-advanced-caching-and-isr-with-astro/) |
| D5 | **URL design**: `/products/{id}/{slugified-name}/` (id is the canonical key; slug suffix informational, mismatch → 301 to canonical). PLPs at `/categories/{id}/{slug}/`, `/brands/{id}/{slug}/`, `/colors/{id}/{slug}/`; filters as query strings (`?page=&color=&brand=&sort=`) | Pure `/products/{id}/`; slug-only URLs (requires a bridge slug field) | Contract has no slug; id-keyed URLs are stable under renames and survive contract liberation; slug suffix keeps human/SEO readability without bridge changes | Google [URL structure guidance](https://developers.google.com/search/docs/crawling-indexing/url-structure); standard e-commerce practice |
| D6 | **Clean-room islands (R5)**: cart badge, gallery lightbox, and promo-code field are re-authored NV-side against the contract shape — **no** PWA source is copied into the public repo | Inject licensed PWA components into the private mirror at CI time | The Envato Regular License prohibits redistribution of the PWA's code; the proposal's reuse claim (§3.3) must not leak licensed bytes into the MIT monorepo. Re-authored scope is small (3 components). CI injection remains the documented fallback if parity effort balloons | Envato [license FAQ](https://help.market.envato.com/hc/en-us/sections/200616950-Envato-Market-License-FAQ); repo precedent 085 D2/D3 |
| D7 | **Media normalization (R7)**: the Astro client rewrites `image`/`images` URLs to `/media/{...}` same-origin paths; `serve.mjs` proxies `/media/*` → configured uploads base of `NVOOS_TARGET_SITE` (allowlisted base, no open redirect) | Absolute bridge URLs as-is; bridge change to emit relative URLs | Contract-only binding survives the T1 flip untouched; `astro:assets` optimizes same-origin images without `image.domains`; single choke point to swap to Flysystem/Laravel media later | 085 appendix §2 (media becomes Flysystem at T2); Astro [images guide](https://docs.astro.build/en/guides/images/) |
| D8 | **Server islands for banner/carousel/promotions** (`server:defer`), keeping the prerendered shell cacheable | SSR them inline (loses full-page cacheability); client-fetch them into React islands (adds JS) | Dynamic pockets render separately; the static shell stays `s-maxage`-cacheable; fallback content ships in the initial HTML | Astro [server islands guide](https://docs.astro.build/en/guides/server-islands/) |
| D9 | **View Transitions with `ClientRouter` + `transition:persist` for the cart badge**, analytics forwarding via `astro:page-load` (R4) | No transitions (PWA parity gap); SPA router | SPA-feel navigation with zero-JS default pages; persists cart state across client-side navigations; analytics/GTM hook is a first-class task, not an afterthought | Astro [view transitions guide](https://docs.astro.build/en/guides/view-transitions/); practitioner caveats (r/astrojs GTM thread) |
| D10 | **JSON-LD generated from pure functions in `src/lib/seo/`** (Product+Offer, BreadcrumbList, Organization, WebSite), rendered in each route's initial `<head>` | Client-side injection; a single global script | Server-rendered structured data is the only kind crawlers reliably parse; pure functions are unit-testable; per-route granularity keeps Product vs Breadcrumb vs Organization scoping correct | Shopify [ecommerce schema guide](https://www.shopify.com/blog/ecommerce-schema); Dynamic SEO [structured data guide](https://dynamicseo.com/en/blog/tutorials/ecommerce-structured-data-guide) |
| D11 | **Sitemap via a custom SSR endpoint** (`sitemap.xml.ts`) — inverted from the plan draft: the sitemap integration only lists prerendered pages, which silently omits the SSR'd PLP bases (the highest-value SEO URLs). The custom endpoint generates the full map (PDPs + PLP bases + static pages, `lastmod` from `updatedAt`), cached 1 h | `@astrojs/sitemap` integration only | Correct coverage beats integration convenience; hybrid mode makes the integration the wrong tool | Astro [sitemap integration](https://docs.astro.build/en/guides/integrations-guide/sitemap/) (limitations) |
| D12 | **Build-time origin separation**: `getStaticPaths` at CI build time and server-side auth calls use `NVOOS_API_BASE` (full base URL, e.g. `https://site/wp-json/nvoos-commerce/v1`); runtime SSR page fetches use the same-origin `/api/*` proxy (relative URLs, zero origin config) | Single configured origin for both | Runtime stays origin-agnostic (the T1 flip is purely a `serve.mjs` env change); build needs a deterministic data source (fixtures) so CI is hermetic; the base-URL env keeps `src/` free of literal `/wp-json` strings (D3 gate) | Repo precedent: `NVOOS_TARGET_SITE` env in both Velocity apps; proposal §3.2 |

---

## 3. Contract & Data Model

### 3.1 The Payload-shaped envelope (fixed, authoritative)

**Namespace policy (owner decision, 2026-10-29):** the bridge now serves the
identical routes under two namespaces — **`nvoos-commerce/v1`** (the NV
commerce contract; the Astro storefront and all new consumers bind here) and
legacy **`funiq/v1`** (retained only because Q1 keeps the PWA A/B gate live;
retires with it). The `/api/*` proxy rule in `serve.mjs` targets
`nvoos-commerce/v1`. DB-level funiq names (CPTs, taxonomies, meta keys,
options) are **not** renamed in live WordPress — that rename rides the
planned Laravel ETL typing pass (085 appendix §2/§5) where it is free.

```json
{
  "docs": [ ... ],
  "totalDocs": 150,
  "limit": 10,
  "totalPages": 15,
  "page": 1,
  "hasNextPage": true,
  "hasPrevPage": false
}
```

Contract freeze per 085 appendix §5 step 1. The Astro client types this as a
generic `PaginatedCollection<T>` and treats it as the only shape the backend
may produce.

### 3.2 Product document (verified against `ProductTransformer.php`)

The client's `Product` type mirrors the transformer exactly:

```ts
interface Product {
  id: number;
  name: string;
  price: number;                 // no currency field — NVOOS_CURRENCY config (R8)
  oldPrice: number | null;
  description: string;           // raw HTML (wp post_content) — sanitize before render
  category: { id: number; name: string } | null;
  brand:    { id: number; name: string } | null;
  colors:   { id: number; name: string; hexCode: string }[];
  width: number; height: number; depth: number;
  rating: number | null;         // JSON-LD aggregateRating (reviewCount omitted — not in contract)
  isBestseller: boolean;
  isFeatured: boolean;
  image: string;                 // absolute URL — normalized to /media/* at the client edge (D7)
  images: string[];              // gallery URLs — same normalization
  imageId: number | null;        // admin-facing; unused by the storefront
  imagesIds: number[];           // admin-facing; unused by the storefront
  statuses: { id: number; name: string }[];
  promotion: { id: number; title: string; description: string;
               startDate: string; endDate: string; active: boolean } | null;
  createdAt: string;             // ISO-ish WP date — sitemap lastmod source
  updatedAt: string;
}
```

Collections also consumed: `categories`, `brands`, `colors`, `statuses`,
`promotions`, `promocodes` (display-only in v1), `globals/banner`,
`globals/carousel`. Exact shapes are captured as golden fixtures (§8).

### 3.3 Query surface & known gaps

| Capability | Today (verified) | Gap → Bridge work |
|---|---|---|
| Pagination | `page`, `limit` (max **100**) | None — `getStaticPaths` loops pages at `limit=100` |
| Filter `where` | `id.equals`, `category.equals` only | **B2**: `brand.equals`, `colors.in`, `statuses.in`, `category.in` (Payload-style operators) for Phase 1 filters |
| Sorting | Fixed `orderby=date DESC` | B2 (optional): `sort` param (`-price`, `price`, `rating`) — else sort client-side on the loaded page |
| Search | None | Deferred — SSR PLP search is Phase 1-optional (client-side filter of the collection until the bridge grows `where[name][like]`) |
| Cache headers | None on public GETs | **B1**: `Cache-Control: public, s-maxage=300` + `Last-Modified`/`ETag` from `post_modified` (R6 — unlocks serve.mjs micro-cache + Astro fetch cache simultaneously) |

### 3.4 Client-side normalization rules (`src/lib/funiq/`)

- **Media**: `normalizeMediaUrl(url)` rewrites the origin of `image`/`images`
  to `/media/` (keeps path + query). Applied at the client edge so no
  component ever sees a WP URL.
- **HTML**: `description` is `wp_kses_post`-sanitized upstream but still raw
  HTML — rendered through Astro's `set:html` only after a sanitize pass
  (`sanitize-html` allowlist) in the client.
- **Dates**: `new Date(updatedAt)` for `lastmod`; guard invalid dates
  (sitemap must never emit malformed W3C datetimes).
- **Money**: `formatPrice(price)` using `Intl.NumberFormat` +
  `NVOOS_CURRENCY` (default `USD`; env-overridable per deployment).

---

## 4. Repository Structure & Licensing

```
Public monorepo — examples/nvoos-astro-velocity/          (NV-authored, MIT)
├── package.json              # "license": "MIT", scripts: dev/build/test/lint/sync:dist
├── astro.config.mjs          # output 'server', node adapter (standalone), @astrojs/react, @astrojs/sitemap
├── tsconfig.json             # strict; extends astro/tsconfigs/strict
├── eslint.config.js          # eslint-plugin-astro + @typescript-eslint + jsx-a11y (tabs, single quotes — repo JS style)
├── .env.example              # NVOOS_TARGET_SITE (build+proxy), NVOOS_CURRENCY, ASTRO_SITE (canonical host)
├── README.md                 # mirror notice, architecture, contract-invariant rule, MIT license note
├── LICENSE                   # MIT
├── public/
│   ├── robots.txt            # Sitemap: directive
│   └── favicon.*
├── src/
│   ├── pages/                # routes: index, products/[id]/[...slug], categories/, brands/, colors/, search, about, terms, 404
│   ├── layouts/              # BaseLayout.astro (head, JSON-LD slots, View Transitions, analytics hooks)
│   ├── components/           # Astro components: ProductCard, ProductGrid, PLPFilters, Banner, Carousel, Footer…
│   ├── islands/              # React islands (clean-room per D6): CartBadge, GalleryLightbox, PromoCodeField
│   ├── lib/
│   │   ├── funiq/            # client.ts (fetch layer + Astro.cache), types.ts, normalize.ts, money.ts
│   │   └── seo/              # jsonld.ts (pure generators), meta.ts (title/desc/OG builders), canonical.ts
│   ├── content/              # content collections: marketing pages (about, terms, landing) — type-safe MD/MDX
│   ├── middleware.ts         # (if needed) media-url or analytics header plumbing
│   └── env.d.ts
├── scripts/
│   ├── serve.mjs             # BFF: mounts Astro standalone handler → falls through to proxy rules (/api, /media, /wp-json, /wp-admin, /wp-login.php, /wp-includes)
│   ├── record-fixtures.mjs   # records golden fixtures from a live/seed origin
│   └── sync-dist.mjs         # stages dist/ for the mirror (CI-only, mirrors 085 §3.3)
├── tests/
│   ├── fixtures/             # golden contract fixtures (products, categories, globals, …)
│   ├── unit/                 # vitest: client, normalize, money, jsonld, meta
│   └── e2e/                  # Playwright (opt-in): navigation, filters, no-JS crawlability, JSON-LD snapshot
└── dist/                     # git-tracked per repo convention? NO — dist/ lives only in the mirror (built by CI); see runbook §9
```

Public mirror — `nvdigitalsolutions/nvoos-astro-velocity` (flat history, force-push only — **Q6 decided public**: no licensed Funiq assets are used anywhere; the Astro storefront replaces the PWA)

```
├── package.json              # real runtime deps (astro SSR needs them — unlike the zero-dep console mirrors)
├── server.js                 # Velocity default entry shim → scripts/serve.mjs (PM2 requirement, SA pattern)
├── scripts/serve.mjs         # the BFF server (from the monorepo)
├── dist/                     # Astro build output (client/ + server/), injected by the sync workflow
└── README.md                 # one-way mirror notice
```

**Contrast with the console mirrors (documented because it matters):** the
Complete Console / Schedule Anything mirrors strip dependencies because their
runtime is zero-dependency Node static serving. An Astro SSR runtime
**needs** `astro`, the node adapter, and React (for island server-render) —
so the mirror keeps real dependencies and Velocity runs its normal
`npm install` (no-op build shim, prebuilt `dist/`). The mirror is **public**
(Q6) — it contains no licensed bytes; the established flat-history force-push
discipline still applies so deploy history stays clean.

**Boundary rules (enforced in review + CI):**

1. No file under `examples/nvoos-astro-velocity/` may contain PWA source or
   assets by value (D6 — clean-room islands).
2. No WP-specific endpoint strings outside `scripts/serve.mjs` (D3 — the
   grep gate fails CI on `/wp-json|wp-admin|wp-login|nonce/i` in `src/`).
3. `LICENSE` = MIT; `package.json` license field = MIT (deliberate contrast
   with the proprietary `UNLICENSED` examples).

CI sync workflow: `.github/workflows/sync-nvoos-astro-velocity.yml`
(pattern-identical to `sync-nvoos-complete-velocity.yml`): trigger on
`examples/nvoos-astro-velocity/**` pushes to `main`/`alpha-working` +
`workflow_dispatch`; build (`npm ci`, `astro check`, `astro build` against
fixture origin, vitest), assemble mirror tree (dist/ + server + package.json),
smoke-test (boot server, curl `/`, `/products/1/`, `/api/products`), force-push
one flat commit to the private mirror → Velocity redeploys.

---

## 5. App Architecture

### 5.1 Rendering map (implements proposal §3.3 + decisions)

| Surface | Route(s) | Rendering | Mechanism |
|---|---|---|---|
| Home | `/` | SSR (300 s cache) | Bestsellers/featured fetch + banner/carousel inline (server islands deferred — home is not a static shell, so they add no value yet) |
| PLP page 1 + filters | `/categories/{id}/{slug}/`, `/brands/{id}/{slug}/`, `/colors/{id}/{slug}/` | **SSR (300 s cache)** — prerendered page-1s were dropped: a static page would silently ignore filter query strings | Query-string params → `where` operators (B2); canonical + `noindex,follow` discipline |
| PDP full catalog | `/products/{id}/{slug}/` | **Prerendered** | `getStaticPaths` paginates `/api/products` at `limit=100` |
| New products not in the last build | PDP | n/a (404 until next build) | Rebuild via bridge webhook → Velocity redeploy (R9) |
| Banner / carousel / promotions | embedded on home | Inline SSR fetch | Same fetch layer + cache headers |
| Cart badge | header | React island `client:idle` + `transition:persist` | nanostores + localStorage (display-only in v1) |
| Gallery lightbox | PDP | React island `client:visible` | Re-authored (D6) |
| Promo-code field | PDP/cart | React island `client:visible` | **Live validation** via the same-origin `/api` proxy → `promocodes/validate` (Q5). Proxy fetch, not Astro Actions — the bridge auth cookies flow through the BFF, which is the proven console pattern |
| Account (Q5) | `/account` | React island `client:load` | `auth/*` surface through the proxy (cookie BFF flow) |
| Marketing pages | `/about`, `/terms`, landing pages | SSG | Markdown pages with the base layout (content collections as follow-up) |
| `sitemap.xml`, `robots.txt` | — | **SSR endpoints** | Custom endpoints generate the FULL map (the sitemap integration cannot list SSR'd PLP bases — D11 inverted at implementation) |

### 5.2 Fetch layer (`src/lib/funiq/client.ts`)

- `getCollection<T>(slug, { page, limit, where })` → parsed `PaginatedCollection<T>`
  with runtime validation (zod-style hand-rolled validators — no new deps, or
  `astro/zod` which ships with Astro 7).
- `getAll<T>(slug)` → pagination accumulator for `getStaticPaths`
  (respects `totalPages`, fails loudly on envelope mismatch).
- `getOne<T>(slug, id)` → single document.
- `getGlobal(slug)` → banner/carousel globals.
- All wrapped in `Astro.cache(…, { revalidate })` where available (R2);
  explicit `Cache-Control` headers always set by routes (D4).
- **Runtime** calls use relative URLs (`/api/products`) — same-origin proxy,
  zero origin config (D12). **Build** calls use `NVOOS_TARGET_SITE` against
  the fixture origin.

### 5.3 SEO module (`src/lib/seo/`)

| Generator | Output | Contract inputs |
|---|---|---|
| `productJsonLd(p)` | `Product` + `Offer` (+ `AggregateRating` when `rating` present) | name, image (normalized URLs), description, price, oldPrice, currency, rating, canonical URL |
| `breadcrumbJsonLd(items)` | `BreadcrumbList` (Home → Category → Product) | category name + URL |
| `organizationJsonLd()` | `Organization` (site-wide, in BaseLayout) | site config |
| `webSiteJsonLd()` | `WebSite` + `SearchAction` (optional, home only) | site config |
| `metaFor(p)` | `<title>`, meta description (plain-text excerpt ≤ 160 chars), canonical, OG/Twitter (`og:type=product`, `og:image`) | product fields |

All are pure functions — unit-tested against schema-structure assertions.

### 5.4 Head & SEO plumbing (BaseLayout.astro)

- Canonical tags: PDPs and PLP page 1 → self; filtered PLPs → base category
  URL; slug mismatches → 301 to canonical (D5).
- `noindex,follow` on secondary filter permutations (R9/crawl budget).
- JSON-LD `<script type="application/ld+json">` in initial HTML — never
  client-injected (D10).
- `<ClientRouter />` + `transition:persist` on the cart badge; analytics
  forwarding listener on `astro:page-load` (D9, R4).
- `prefetch` default on (Astro built-in).

### 5.5 Serve layer (`scripts/serve.mjs` — BFF, D2)

Ports the proven SA/console proxy core and adds:

1. Mount Astro standalone handler (`dist/server/entry.mjs` → `handler`).
2. Fall-through proxy rules: `/api/*` → `${NVOOS_TARGET_SITE}/wp-json/funiq/v1/*`
   (the 085 §3.2 rule, now server-side), `/media/*` → configured uploads base
   (D7, allowlisted), plus the standard `/wp-json`, `/wp-admin`,
   `/wp-login.php`, `/wp-includes` passes.
3. Caching contract: `/_astro/*` (hashed chunks) → `public, max-age=31536000,
   immutable`; prerendered HTML → route headers (D4); SSR'd HTML → short
   `s-maxage`; default-deny proxy micro-cache for proxied GETs carrying
   `Cache-Control: public` (B1 makes `/api/*` eligible — R6);
   `X-Cache-Status` observability; `NVOOS_PROXY_CACHE` knobs preserved.
4. Hardening inherited: hop-by-hop header stripping, Set-Cookie `Domain`
   dropping, `wp-login.php` re-scoping, 502 `nvoos_bad_gateway` JSON.

**Env vars:** `NVOOS_TARGET_SITE` (build + proxy origin), `NVOOS_CURRENCY`,
`ASTRO_SITE` (canonical host for sitemap/canonicals), `PORT` (Velocity),
`NVOOS_PROXY_CACHE` / `NVOOS_PROXY_CACHE_MAX_TTL` (inherited knobs).

---

## 6. Phase-by-Phase Build Plan

Timelines match the proposal (total through Phase 2: ~7–9 weeks, disjoint
from Proposal 090's file scope — no shared files).

### Phase 0 — Spike (`examples/nvoos-astro-velocity/`, ~2–3 weeks)

**Goal:** prove SSR + prerender + island + Velocity-entry feasibility with
the smallest honest surface. Exit criteria P1–P6 below are the proposal's,
mapped to concrete commands/evidence in §10.

| Task | Detail | PR |
|---|---|---|
| 0.1 Scaffold | `npm create astro@latest` (minimal, TS strict), node adapter standalone, `@astrojs/react`, `@astrojs/sitemap`, ESLint/`astro check`, MIT license, README skeleton | PR 1 |
| 0.2 Contract client | `lib/funiq/types.ts` (Product + collections, §3.2), `client.ts` (envelope validation, pagination accumulator, media/date/money normalization §3.4), golden fixtures recorded from a seeded Docker WP (bridge fixtures) | PR 1 |
| 0.3 PLP | `/categories/{id}/{slug}/` SSR route: server-rendered `ProductGrid` with **real `<a href>` links**; page 1 prerendered via `getStaticPaths` | PR 2 |
| 0.4 PDP | `/products/{id}/{slug}/` with JSON-LD Product+Offer+Breadcrumb in initial HTML; slug-mismatch 301 (D5) | PR 2 |
| 0.5 React island | Clean-room `CartBadge` (`client:idle`) proving the island claim (D6); zero-JS default verified on PDP without islands | PR 2 |
| 0.6 BFF serve | `scripts/serve.mjs` mounting the Astro handler + `/api/*` proxy rule (D2); `server.js` shim; boots on plain Node (Velocity entry) | PR 3 |
| 0.7 Bridge B1 | Cache headers on public GETs (R6) — separate bridge PR, tests `--group=funiq-bridge` | bridge PR |
| 0.8 CI sync | `sync-nvoos-astro-velocity.yml` (build → smoke → force-push mirror); owner creates the private mirror repo (runbook §9) | PR 3 |

**Exit:** P1 `astro check` + `astro build` clean in CI · P2 PLP SSR with
crawlable links · P3 PDP JSON-LD in initial HTML · P4 zero-JS default with one
hydrated island · P5 golden-contract smoke (client tests replay fixtures;
`astro build` succeeds against fixture origin) · P6 `node server.js` serves
`dist/` on plain Node and proxies `/api/products` to the seed origin.

### Phase 1 — Parity (~3–4 weeks)

**Goal:** feature parity with the PWA's public (unauthenticated) surface.

| Task | Detail | PR |
|---|---|---|
| 1.1 Bridge B2 | `where` operators: `brand.equals`, `colors.in`, `statuses.in`, `category.in`; optional `sort` param; bridge tests | bridge PR |
| 1.2 Filters | PLP query-string filters (color, brand, status, sort, pagination) with canonical/noindex discipline (D5, §5.4) | PR 4 |
| 1.3 Promotions & promocodes | Promotion ribbons on PDP/PLP from `promotion` refs (active + date-window check); promocodes listing page; promo-code React island wired to **live validation** (Astro Action → B4 endpoint) | PR 4 |
| 1.4 Banner/carousel globals | Server islands with fallback content (D8); image optimization via `astro:assets` on normalized `/media/*` URLs (D7) | PR 5 |
| 1.5 View Transitions | `ClientRouter`, `transition:persist` cart badge, analytics forwarding on `astro:page-load` (D9/R4); prefetch audit | PR 5 |
| 1.6 Media proxy rule | `/media/*` allowlist proxy in `serve.mjs` (D7); media normalization verified end-to-end | PR 5 |
| 1.7 Image optimization | `<Picture>`/`<Image>` on product grids + PDP gallery with widths/densities; layout-shift-free dimensions | PR 6 |
| 1.8 Marketing content | About/terms/landing markdown pages (type-safe MD) | PR 6 |
| 1.9 Accounts & auth (Q5) | `nvoos-commerce/v1/auth/*` bridge surface (B4); Astro Actions for register/login/logout/me; account pages; promo-code validation wired end-to-end | PR 6 |
| 1.10 Deploy doc | `docs/operations/deployment/nvoos-astro-velocity-setup.md` (Velocity app, env vars, cache contract, A/B hostname note) | PR 6 |

**Exit:** every public PWA screen has an Astro equivalent (visual + link +
data parity, golden fixtures as the shared data source — 085 appendix §5);
the private mirror deploys with same-origin routing; Lighthouse mobile
accessibility ≥ 90 on PDP/PLP.

### Phase 2 — SEO deliverables (~2 weeks)

| Task | Detail | PR |
|---|---|---|
| 2.1 Full-catalog prerender | `getStaticPaths` paginates all products + all PLP page-1s at build; SSR fallback for post-build products (R9) | PR 7 |
| 2.2 Sitemap & robots | `@astrojs/sitemap` with `lastmod` from `updatedAt`; robots.txt Sitemap directive; filtered-PLP exclusions (D11) | PR 7 |
| 2.3 Cache + revalidation | Route cache headers per D4; bridge revalidation webhook → Velocity redeploy (R9); `X-Cache-Status` observability | PR 7 |
| 2.4 JSON-LD suite | Organization + WebSite site-wide; Product/Offer/Breadcrumb per PDP; validation snapshots in CI | PR 8 |
| 2.5 No-JS crawlability audit | Playwright with JS disabled: content, links, JSON-LD present and parseable on PLP/PDP/home | PR 8 |
| 2.6 Lighthouse CI gate | LHCI on seeded deploy: CWV (LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1), SEO 100, a11y ≥ 90 | PR 8 |
| 2.7 Contract drift guard | Fixture regeneration script + CI job diffing bridge test fixtures vs Astro fixtures (P5 becomes a standing gate) | PR 8 |

**Exit:** 085 §2.3 standards verifiable by inspection (JSON-LD in initial
HTML, real links, JS-disabled render) **plus** automated Lighthouse evidence
— the Phase 3 gate input.

### Phase 3 — Coexist A/B (Q1 decided: **keep A/B**)

The PWA is **not** retired in this phase. The A/B gate (hostname or cookie
split on Velocity) becomes the standing posture: the Astro app serves the
primary storefront, the PWA stays live as rollback and as a parity
reference. Retirement remains an owner-gated future decision — when it
happens, wind down `nvoos-funiq-pwa`, delete the injection step in
`sync-nvoos-funiq-velocity.yml`, and retire the legacy `funiq/v1` namespace
(the alias makes this a one-line removal per controller). Decision
recorded in Proposal 092's status line (decision #15 pattern).

### Phase 4 — Generic starter (**Q3 decided: scheduled**, v1.5 roadmap)

`nvoos-astro-starter`: Astro + core WP REST (posts/pages) + WooCommerce via
existing Pro REST surfaces; own README; zero bridge dependency. The
community "WP Astro MCP" (R1) is a candidate accelerator here — third-party
code, evaluate before adopting.

---

## 7. Bridge Enhancements (tracked separately)

Owned by the bridge track, sequenced into Phases 0–1, each with
`--group=funiq-bridge` tests:

| ID | Change | Files | Phase |
|---|---|---|---|
| B1 | `Cache-Control: public, s-maxage=300` + `Last-Modified`/`ETag` (from `post_modified`) on public GETs | `BaseController` | 0 (R6) |
| B2 | Payload-style `where` operators (`brand.equals`, `colors.in`, `statuses.in`, `category.in`); optional `sort` | `ProductsController::apply_where_query` | 1 |
| B3 | (Optional) `where[name][like]` for SSR search | `ProductsController` | 1-optional |
| B4 | **Auth surface (Q5 = include)**: `nvoos-commerce/v1/auth/*` — `GET /auth/nonce`, `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` (WP users + cookie auth through the same-origin proxy); `GET /promocodes/validate?code=` (idempotent read) | New `AuthController` + `PromocodesController` | 1 |

**Non-goals (explicit):** no slug/currency/stock fields in the contract —
URLs, currency, and availability are handled app-side per D5/R8 until
contract liberation (proposal §3.2).

---

## 8. Testing Strategy

| Layer | Tool | Scope | Gates |
|---|---|---|---|
| Static | `astro check` (tsc strict) + ESLint (astro/ts/a11y) | types, the D3 no-WP-string grep gate, a11y lint on islands | CI on every PR |
| Unit | vitest (+ jsdom for island logic) | funiq client (envelope parsing, pagination accumulation, filter serialization), normalize (media/dates/money), SEO generators (JSON-LD structure, meta/canonical rules), cart store | CI |
| Contract | golden fixtures (`tests/fixtures/*.json`) recorded from a seeded Docker WP running the bridge; `record-fixtures.mjs` regenerates | client unit tests replay fixtures; **drift guard** diffs bridge fixtures vs Astro fixtures (P5 standing gate) | CI (Phase 2.7) |
| Build | `astro build` against the fixture origin | `getStaticPaths` full-catalog pagination, envelope mismatch → loud failure | CI |
| E2E | Playwright (opt-in, additive — no shared config with spa-v2) | PLP→PDP navigation, filter query strings, slug 301, cart badge persistence, **no-JS crawlability**, JSON-LD extraction | Phase 2 gates; nightly |
| Perf/SEO | Lighthouse CI | CWV thresholds, SEO 100, a11y ≥ 90 | Phase 2.6 exit |

**Conventions:** tabs, single quotes (repo JS style); fixtures are the
contract suite for **both** storefronts during coexistence (proposal §5 risk
mitigation); no test may require a live WP host — everything replays
fixtures or boots the seeded Docker WP.

---

## 9. Deploy & Sync Runbook

1. **One-time owner actions:** create the private mirror
   `nvdigitalsolutions/nvoos-astro-velocity`; add a deploy token secret to
   the monorepo (`NVOOS_ASTRO_VELOCITY_TOKEN`, same shape as the console
   mirror); create the Velocity Node app and connect the mirror repo.
2. **Velocity app settings:** root = repo root; entry = `server.js`
   (one-line shim → `scripts/serve.mjs`; PM2 requires it — SA pattern);
   build command = no-op shim (dist/ is prebuilt in the mirror); Node 20/22
   LTS per Astro 7 engines.
3. **Env vars (app-level `.env`):** `NVOOS_TARGET_SITE` (required),
   `NVOOS_CURRENCY`, `ASTRO_SITE` (canonical host), `PORT` injected,
   `NVOOS_PROXY_CACHE*` knobs optional.
4. **Sync workflow:** `sync-nvoos-astro-velocity.yml` — build (fixture
   origin), vitest, smoke (boot + curl `/`, a PDP, `/api/products`,
   `/media/…`), assemble mirror tree, force-push flat commit → Velocity
   redeploys.
5. **Cache contract on the app:** `/_astro/*` immutable 1 y; prerendered
   HTML `public, s-maxage=3600, stale-while-revalidate=86400`; SSR filter
   pages `public, s-maxage=60`; `/api/*` cached by the serve-layer
   micro-cache only when the bridge marks them `Cache-Control: public`
   (B1) — `X-Cache-Status` for observability.
6. **Revalidation:** bridge "content changed" webhook (B1 companion) →
   Velocity rebuild/deploy; interim manual redeploy until the webhook ships.
7. **Rollback:** the PWA app (`nvoos-funiq-velocity`) remains the rollback
   target until Phase 3; A/B is hostname/cookie-based at the CDN/routing
   layer (proposal §3.1, Q1).

---

## 10. Verification Matrix

| Gate | Evidence | Command / check |
|---|---|---|
| P1 | Clean typecheck + build | `npm run check && npm run build` (CI) |
| P2 | PLP server-rendered, crawlable links | curl PLP → HTML contains `ProductCard` markup + `<a href="/products/…">`; Playwright no-JS nav |
| P3 | JSON-LD in initial HTML | curl PDP → `application/ld+json` block parses; contains Product + Offer + BreadcrumbList |
| P4 | Zero-JS default + one hydrated island | curl PDP → no `<script type="module">` for content; Playwright: badge interactive with JS, full content without |
| P5 | Golden-contract smoke | `npm test` (fixtures replay) + `astro build` against fixture origin; drift guard green |
| P6 | Plain-Node Velocity entry | `NVOOS_TARGET_SITE=<seed> node server.js` → serves `/` + proxies `/api/products` |
| Phase 1 | Parity + routing in mirror | Parity checklist vs PWA public routes; mirror smoke test in sync workflow |
| Phase 2 | SEO standards | LHCI (CWV, SEO 100, a11y ≥ 90); no-JS audit green; sitemap/robots served; cache headers verified via `curl -I` |
| Phase 3 | Recorded decision | Proposal 092 status-line update; PWA sync workflow injection deleted |

---

## 11. Risks & Mitigations

Proposal §5's table stands; research adds/strengthens:

| Risk | Impact | Mitigation |
|---|---|---|
| (new) Envato leak via "reuse" claim (R5) | High — license violation in the public MIT repo | D6 clean-room rule; CI grep for PWA-identifying tokens; fallback = private CI injection only |
| (new) View Transitions break GTM/consent/analytics (R4) | Medium — marketing attribution silently lost | `astro:page-load` forwarding is a Phase 1 task with a test; keep `ClientRouter` opt-in per route |
| (new) Prerendered catalog staleness (R9) | Medium — price/promo drift | Sitemap `lastmod`; bridge webhook → redeploy; SSR fallback for missing routes; short `s-maxage` on SSR paths |
| (new) Media-origin leak breaks T1 flip (R7) | Medium | D7 normalization at the client edge + `/media/*` proxy; verified end-to-end in Phase 1.6 |
| (new) Astro 7 `Astro.cache` availability varies by minor (R2) | Low | Headers-first caching (D4) works regardless; `Astro.cache` is an optimization |
| Two storefronts = doubled QA | Medium | Shared golden fixtures (085 appendix §5); Phase 3 gate with recorded decision (proposal §5) |
| Velocity SSR load | Medium | Prerendered catalog; server islands; D4 cache headers; fallback static export + Cloudflare Pages (Q4) |
| REST N+1 / no cache headers | Medium | B1 + Astro fetch-layer batching (proposal §2.2 caveats) |
| Contract drift during bridge/Laravel work | Medium | Contract freeze + P5 fixture gate + Phase 2.7 drift guard |
| Toolchain diversity (spa-v2 esbuild + Astro Vite) | Low | Isolated app; no shared build; islands are clean-room React, not spa-v2 imports |
| Scope creep into React admins | High | §3.7 non-replacement stands; admin track stays on 085 §3.5 |

---

## 12. Decisions — Recorded (owner, 2026-10-29)

| ID | Decision | Recorded answer | Consequence applied in this plan |
|---|---|---|---|
| Q1 | Phase 3 posture: retire at parity vs keep A/B | **Keep A/B** — PWA stays live as rollback + parity reference; no retirement | Phase 3 rewritten as the standing coexist posture; `funiq/v1` namespace retained until PWA retirement |
| Q2 | Backend binding: contract-only? | **Yes** — binds only to the Payload-shaped envelope | D3 CI grep gate; `NVOOS_API_BASE` env keeps `src/` free of literal backend URLs |
| Q3 | Generic starter scheduled? | **Yes — schedule** in the v1.5 roadmap | Phase 4 marked scheduled |
| Q4 | Hosting: Velocity vs static/CDN | **Velocity first** | Runbook unchanged; D4 headers keep the static-export fallback cheap |
| Q5 | Authenticated flows included? | **Yes — include both** promo-code validation and accounts | Bridge B4 auth surface + Astro Actions + account pages added to Phase 1 (§1.9 task) |
| Q6 | Mirror repo visibility | **Public** — the licensed Funiq PWA is not being used; the Astro app replaces it | §4 mirror made public; no licensed-byte constraints remain for this app |
| Q7 | Currency default | `USD` (accepted) — env-overridable via `NVOOS_CURRENCY` | `money.ts` uses `Intl.NumberFormat` with the env currency |

---

## 13. References

**Industry / web (researched for this plan):**

- [Astro — islands architecture](https://docs.astro.build/en/concepts/islands/) · [view transitions](https://docs.astro.build/en/guides/view-transitions/) · [caching](https://docs.astro.build/en/guides/caching/) · [server islands](https://docs.astro.build/en/guides/server-islands/) · [headless WordPress guide](https://docs.astro.build/en/guides/cms/wordpress/) · [sitemap integration](https://docs.astro.build/en/guides/integrations-guide/sitemap/) · [images](https://docs.astro.build/en/guides/images/)
- [withastro/storefront](https://github.com/withastro/storefront) — official Astro e-commerce reference
- [MojoAuth — implement caching with Astro](https://mojoauth.com/dev-guides/implement-caching-with-astro) · [Netlify — ISR/advanced caching with Astro](https://developers.netlify.com/guides/how-to-do-advanced-caching-and-isr-with-astro/) · [withastro/roadmap #181 — page caching](https://github.com/withastro/roadmap/discussions/181)
- [Strapi — Astro islands guide](https://strapi.io/blog/astro-islands-architecture-explained-complete-guide) · [redlinesoft — islands hydration rules](https://blog.redlinesoft.net/posts/astro-islands-content-sites/)
- [Shopify — ecommerce schema guide](https://www.shopify.com/blog/ecommerce-schema) · [Dynamic SEO — ecommerce structured data](https://dynamicseo.com/en/blog/tutorials/ecommerce-structured-data-guide) · [Glukhov — breadcrumb schema](https://www.glukhov.org/post/2025/12/breadcrumbs-for-seo/) · [1SEO — schema tips](https://1seo.com/blog/schema-markup-tips-for-better-ecommerce-visibility-in-2025/)
- [Astro Docs MCP](https://mcp.docs.astro.build/) · [Astro — building with AI tools](https://docs.astro.build/en/guides/build-with-ai/)
- [Envato Market license FAQ](https://help.market.envato.com/hc/en-us/sections/200616950-Envato-Market-License-FAQ)
- [Auth0 — BFF pattern](https://auth0.com/blog/the-backend-for-frontend-pattern-bff/)

**Repo (verified while writing this plan):**

- Proposal 092 · Proposal 085 + `085-funiq-laravel-standalone-integration-appendix.md` (contract invariant §2, topologies §4, Strangler Fig §5) · Proposal 090 · Proposal 087 · Proposal 089
- `addons/funiq-bridge/README.md` · `includes/REST/ProductsController.php` · `includes/Transformers/ProductTransformer.php` (contract + `where` coverage verified)
- `examples/schedule-anything-velocity/README.md` · `examples/nvoos-complete-velocity/README.md` (serve.mjs BFF pattern, caching contract, mirror mechanics)
- `.github/workflows/sync-nvoos-complete-velocity.yml` · `sync-nvoos-schedule-anything-velocity.yml` (sync workflow template)
- `docs/project/plans/funiq-bridge-velocity-storefront-implementation.md` (plan conventions, D1–D7 of 085)
