# Proposal 106 — Shopify → WP Headless Migration Playbook, plus the Three Contract Gaps (Variants, Checkout Handoff, CMS Content)

**Status:** IMPLEMENTED (2026-11-03) — Phases 1–4 shipped on `feat/astro-105-106-theming-content`: variants (additive, Woo variable hydration, PDP selector island, per-variant JSON-LD offers), CMS content (`content/pages` + `content/posts` with contentRendered + SEO-meta passthrough, SSR page/blog routes with 301s + JSON-LD + sitemap), hybrid local content (velocity `.md` collections via glob loaders; forge `.mdx` glob + WP merge with WP-wins L2; both demo-runnable offline), checkout handoff (`globals/shop` + pure `buildCheckoutUrl` comma-form + variant note), and the P0–P6 migration runbook in the deployment doc. Owner decisions O-1…O-8 followed the recommendations.
**Date:** 2026-11-03
**Relates to:** Proposal 092 (storefront layer + contract) · Proposal 090 (T1 Laravel flip — the contract survives it) · Proposal 095 (ADS tokens/themes) · Proposal 096 (Astro Forge — already generates a static content layer) · Proposal 098 (design-import — pulls the Shopify theme's design language into ADS tokens) · Proposal 104 (SSR PDPs, 301s, JSON-LD, cache discipline) · Proposal 105 (bridge theme policy — forces the imported brand theme site-wide)
**Research:** `106-shopify-to-wp-headless-migration-research.md`
**Author:** NV oOS coding agent (Zed)

---

## 1. Problem Statement

A Shopify merchant moving to WordPress/WooCommerce + this headless stack hits three contract gaps, all verified against shipped code (research §6):

1. **Variants.** Shopify catalogs are variant-native (size/colour/option grids). The bridge envelope is flat — a single `price` per product, zero variant fields anywhere in `addons/nvoos-astro-bridge/includes/` (grep-verified). Importing a variant-heavy catalog into Woo `variable` products would render them without sizes or per-variant pricing.
2. **Checkout handoff.** The storefront's cart is a client-side demo (nanostores → `/cart/`); the contract has no cart/orders/customers resources, and the BFF deliberately proxies only `/api/*` + `/media/*`. There is no defined path from "add to cart" to a real WooCommerce checkout.
3. **CMS content (pages/blog).** The contract has no pages/posts resource. Velocity serves `about.md`/`terms.md` as hardcoded Markdown — a migrated Shopify site's About/FAQ/Shipping pages and blog articles have nowhere to live. (The forge already generates a static content layer from WP — `content.config.ts`, `posts/[...parts]`, `[slug].astro`, `redirects.json.ts` — so this gap is about the **live storefront** and the **bridge contract**, not the generator.)

The migration itself (Shopify → Woo data transfer) is solved by mature external tooling (research §2) and is a **playbook step**, not code in this repo.

## 2. Goals and non-goals

**Goals**
- G1: A documented, gated migration playbook: Shopify → WP/Woo → bridge envelope → velocity + forge, with SEO-parity verification at every stage.
- G2: Close the three gaps with additive, contract-preserving changes: a `variants` envelope extension, a checkout-handoff document + cart wiring, and a `content/pages` + `content/posts` resource with SSR storefront routes.
- G3: Everything rides the existing discipline: golden fixtures, hermetic fixture-server parity, cache headers, `manage_astro` gates, JSON-LD + sitemap + LHCI gates.

**Non-goals**
- NG1: No Shopify SDK/extraction code in the repo (licensing split Q8 + paid tooling exists).
- NG2: No headless checkout *resource* (cart/orders/customers) — that is the T1 Laravel backend's job (Proposal 090); v1 is a handoff.
- NG3: No multi-currency engine — `globals/shop` carries display currency only.
- NG4: No changes to the licensed PWA's legacy namespace behaviour.

## 3. The migration playbook (owner-approved runbook)

| Step | Action | Gate |
|---|---|---|
| P0 **Import** | Run the official WooCommerce "Migrate & Import from Shopify" tool (or Shopify Data Migrator for redirects/webhooks) with a dry-run; export Shopify URL redirects list | Products/categories/customers/orders/coupons/pages/blogs land in Woo; redirect CSV captured |
| P1 **Envelope** | Bridge `woo` mode (`funiq_bridge_products_source = woo`); run golden/phpunit + `sync:bridge-fixtures` | Fixture diff is format-only; contract-grep green |
| P2 **Brand** | `design_analyze` the live Shopify storefront (Proposal 098) → ADS theme; `PATCH /globals/theme` policy `brand:<slug>` or `auto` (Proposal 105) | Contrast audit passes; forced-brand e2e green |
| P3 **Redirects** | Redirect CSV → forge `redirects.json` entries (and/or Woo redirects); handle-only Shopify URLs resolve via slug lookup | Crawl-gate + LHCI green; zero 404s on the old URL sample |
| P4 **Commerce** | Checkout handoff (this proposal, §4.2): `globals/shop`, `/cart/` → Woo checkout URL | Handoff e2e; no BFF change |
| P5 **Content** | Content resource + routes (this proposal, §4.3): pages + blog live on velocity; forge site parity check | Content e2e; JSON-LD/sitemap assertions |
| P6 **Launch** | Full gates: JS-disabled crawl spec, axe, LHCI, prod-sim build, mirror rebuild | All green; A/B or cutover per Proposal 092 rules |

## 4. The three gap closures (specs)

### 4.1 Variants — additive envelope extension

- **Contract:** product docs gain an optional `variants` array (absent for simple products — additive, golden-fixture compatible):
  ```json
  "variants": [
    { "id": 456, "sku": "SOF-3S-OAK", "price": 1299, "oldPrice": null,
      "stock": 4, "attributes": [ { "name": "Size", "value": "3-Seater" } ],
      "image": "https://…/sofa-oak.jpg" }
  ]
  ```
- **Bridge:** `WooProductSource` hydrates `WC_Product_Variable` children via `wc_get_product` (the same hydration path as today); `NativeProductSource` is unchanged (native CPTs have no variant model — documented limitation; imported catalogs ride `woo` mode). Listing transforms stay flat (variant depth only on single-doc GET, per the Payload pattern of not bloating lists).
- **Storefront:** PDP gains a variant selector island (attributes → options, disabled sold-out options, price sync, `selectedVariantId`); cart line items store `{ productId, variantId, attributes }`; JSON-LD `Product` gains `offers` per variant (schema-dts `Offer[]`, the existing `Product` branch pattern from 104).
- **Gate:** fixture-server variants + golden transformer parity; unit tests for variable/simple/native paths; e2e selects a variant, sees price change, and persists the selection through a client-side navigation (the 104 Defect A lesson).

### 4.2 Checkout handoff — `globals/shop`

- **Bridge:** new option `funiq_bridge_shop` (seeded: `checkoutUrl` from `home_url('/checkout/')`, `currency`, `currencySymbol`), served at `GET /globals/shop` (public) / `PATCH /globals/shop` (`manage_astro`) — the BannerController pattern; `s-maxage=60` like the 105 policy doc.
- **Storefront:** `/cart/` line items → Woo URL (`{checkoutUrl}?add-to-cart=12,34` comma form for simple products — research §4); redirect + clear local cart. Variant line items are **out of scope** for the comma form (Woo limitation) — the cart page renders them with a note; the T1 Laravel cart resource replaces the handoff (owner decision O-3).
- **BFF:** unchanged — the handoff is an absolute link, not a proxy route.

### 4.3 CMS content — `content/pages` + `content/posts` (closes the pages/posts gap)

- **Bridge:** `PagesController` (`post_type: page`) and `PostsController` (`post_type: post`), both extending the existing `PostTypeController` seam (public READABLE, gated writes — the `/manage` CMS gets content CRUD for free). Doc shape:
  ```json
  { "id": 21, "slug": "about", "title": "About", "excerpt": "…",
    "content": "<!-- raw -->", "contentRendered": "<p>…</p>",
    "type": "page", "featuredImage": "https://…" , 
    "seoTitle": "…", "seoDescription": "…",
    "category": { "id": 3, "name": "Lookbooks" },
    "createdAt": "…", "updatedAt": "…", "status": "publish" }
  ```
  - `contentRendered` = `apply_filters('the_content', …)` (WPGraphQL RENDERED precedent — research §3); `content` raw for edit round-trips. Builder-shortcode caveat documented: pages edited in Elementor render server-side only while the builder is active; `contentRendered` falls back to raw when empty.
  - SEO meta read additively (Rank Math → Yoast → fallback title/excerpt); absent today (grep-verified).
  - Listings support `where[slug][equals]` and `where[category][equals]` (posts) via the existing `WhereClause` source pattern — this is what powers runtime slug resolution for redirects.
- **Storefront (velocity):**
  - `/pages/[id]/[...slug].astro` — SSR, 301 canonical to `/pages/{id}/{slug}` (the 104 PDP pattern), `WebPage`/`AboutPage` JSON-LD, `s-maxage=300`, cache tags `['content','pages']`.
  - `/blog/index.astro` — paginated listing (the PLP pattern); `/blog/[id]/[...slug].astro` — `BlogPosting` JSON-LD (schema-dts), canonical + 301.
  - `sitemap.xml.ts` gains pages + posts; nav gains a "Blog" link.
  - `/about` `/terms` MD pages stay for the demo; a page doc with a matching slug wins via redirect entry if the operator imports one (owner decision O-4).
  - OG cards: extend the 104 `/og/` route with `/og/content/[type]/[id].png` (P2-stretch, reuse `src/lib/seo/og.ts`).
- **Forge:** the generated content layer **already pulls WP pages and posts** (verified on `feat/astro-104-forge-phase4`: `content.config.ts` defines `pagesCollection` (`wp/v2/pages`) + `postsCollection` (`wp/v2/posts`) + `productsCollection` (`wc/v3/products`)). The gap for forge is therefore **parity, not capability**: (a) SEO-meta passthrough — add Rank Math (`rank_math_title`/`rank_math_description`) and Yoast (`yoast_head_json`) fields to the collection `fields` lists (conditional when present); (b) the same JSON-LD shapes as velocity (`Article`/`BlogPosting`/`WebPage`) in `posts/[...parts].astro` + `[slug].astro`; (c) optional stretch — a `nvoos-commerce/v1` source mode for `createWpLoader` so forged sites can pull through the bridge envelope instead of `wp/v2` app passwords (aligns with Proposal 096 Phase 4's pending bridge-plugin item).
- **Fixture server + tests:** `content/pages` + `content/posts` routes (incl. one draft doc, id 9902, per the 104 synthetic-draft pattern); e2e `content.spec.ts` (page renders `contentRendered`, blog listing paginates, canonical/301, JSON-LD present, axe clean).

### 4.4 Hybrid local content — repo-authored posts/pages (closes the "local" gap)

Verified today: velocity ships only hardcoded `about.md`/`terms.md`; the forge template has **no** `src/content/` directories — content is WP-loader-only. Astro's Content Layer API supports **multiple loaders per collection** (a `glob()` loader over `src/content/**` plus the WP loader), which is the industry-standard hybrid headless model: merchant content lives in WP, repo-controlled content (legal pages, launch posts, demo seed) lives in the repo, one route layer serves both.

- **Design rule L1 — one collection, two sources:** the forge template's `posts` and `pages` collections gain a `glob()` loader (`src/content/posts/*.mdx`, `src/content/pages/*.mdx`) alongside the WP loaders; entries merge by id/slug. The scaffold creates the directories + one example post so forged sites are demo-runnable with **no WP connection**.
- **Design rule L2 — deterministic precedence:** one rule on both apps: **the envelope/WP doc wins on slug collision; local content renders when no envelope doc matches** (consistent with O-4; the CMS is the merchant's authority, the repo is the fallback + demo seed). Pinned by unit tests on the merge helper.
- **Design rule L3 — one render path:** sitemap, JSON-LD, feed, and OG all flow through the shared route templates, so local and WP content get identical SEO treatment with zero extra code.
- **Velocity:** add `src/content.config.ts` with a local `posts` (and optional `pages`) collection; `/blog/` and `/blog/[id]/[...slug]` merge `getPosts()` with the local collection (sorted by date, deduplicated by slug under L2). `about.md`/`terms.md` stay as the demo's local pages — and become the *fallback* once an envelope page with the same slug exists (O-4 ladder).
- **Forge:** the collection merge ships in the template; the wp-client remains the WP source; no route changes needed (`posts/[...parts].astro`, `[slug].astro` already exist).

## 5. Implementation phases

- **Phase 1 — Variants** (bridge + storefront): §4.1. One PR cluster (contract + consumer inseparable).
- **Phase 2 — CMS content + hybrid local content** (bridge + storefront + forge parity): §4.3 + §4.4. **This is the explicit closure of the pages/posts gap** — no deferral.
- **Phase 3 — Checkout handoff**: §4.2 (bridge option + `/cart/` wiring + e2e).
- **Phase 4 — Playbook docs + mirrors**: write the runbook into the deployment doc + skills; update proposal statuses; mirror rebuild smoke.

Each phase exits with the standard gates: PHPUnit (bridge matrix WP 6.9 + 7.1), phpcs (bridge ruleset), velocity unit/smoke/e2e + axe + LHCI + prod-sim, forge unit/typecheck/e2e/CLI.

## 6. Owner decisions

| # | Question | Recommendation |
|---|---|---|
| O-1 | Ship variants as an additive optional array (vs a v2 contract bump)? | **Additive** — golden fixtures stay valid; consumers opt in. |
| O-2 | Variant-heavy carts: hand off to Woo for simple products only, variant line items noted (v1), vs build a variant-aware handoff now? | **Simple-only v1** — the Woo comma form is the documented limit; a variant-aware cart is a T1 Laravel cart resource (O-3), not a bridge hack. |
| O-3 | Confirm the full headless cart/orders resource is deferred to T1 (Proposal 090)? | **Confirm** — the contract survives the flip; investing in a bridge cart is throwaway work. |
| O-4 | Keep `about.md`/`terms.md` as demo fallbacks, with imported page docs winning via redirect, vs deleting the MD pages? | **Keep as fallback** — zero-friction demo; imported docs win through the slug lookup. |
| O-5 | Serve `contentRendered` (backend-rendered, builder caveat) vs raw content only? | **Both fields, render `contentRendered`** — WPGraphQL precedent; raw stays for round-trips. |
| O-6 | Scope `globals/shop` to checkout + display currency (v1) — no currency conversion? | **Yes** — conversion is a Woo plugin concern; the envelope carries display data only. |
| O-7 | Playbook as a docs-only deliverable (this proposal set + deployment doc) vs a live migration check-script? | **Docs + gates first**; a `scripts/check-migration.mjs` gate (redirect-sample crawl) can follow if a real migration is scheduled. |
| O-8 | Hybrid local content: envelope/WP wins on slug collision (local = fallback + demo seed), vs local wins (repo as override authority)? | **Envelope/WP wins** — one rule on both apps, consistent with O-4; local content keeps forged sites demo-runnable without a WP connection. |

## 7. Risks and mitigations

| Risk | Mitigation |
|---|---|
| Page-builder content degrades headlessly | Documented caveat; `contentRendered` falls back to raw; builder must stay active (or pages re-authored in blocks) |
| WC add-to-cart comma form drifts across WC versions | Pinned by the repo's WC 11.x test matrix at implementation time |
| Variant data bloats listings | Variants only on single-doc GETs (listings flat) |
| Handle-based Shopify URLs vs ID-based routes | Slug lookup + redirect export (playbook P3) + bridge 301 canonicals (104) |
| Native CPT mode has no variants | Documented limitation; imported catalogs ride `woo` mode; envelope absent-array = simple product |
| SEO meta plugins absent on the WP side | Additive read; fallback to title/excerpt; no errors when absent |

## 8. Acceptance criteria

1. A Woo variable product (3 variants) imported from Shopify renders on the PDP with a working variant selector; price/JSON-LD `offers` follow the selection; a simple product renders unchanged (golden fixtures byte-comparable modulo the additive field).
2. `GET /content/pages` + `GET /content/posts` serve published docs with `contentRendered`, `featuredImage`, SEO meta passthrough (when present), and `where[slug]` lookups; writes are `manage_astro`-gated; drafts are excluded from public listings (draft doc 9902 test).
3. `/pages/{id}/{slug}` and `/blog/{id}/{slug}` render server-side with canonical 301s, valid JSON-LD, sitemap entries, and pass axe; a page whose slug equals an imported Shopify page resolves from the redirect map.
4. `/cart/` renders a checkout link built from line items for simple products; the link hits the Woo checkout URL from `globals/shop`; no BFF change; variant line items show the documented note.
5. All existing gates stay green: velocity 230 unit + 19 smoke + 25 e2e (+ new specs), forge 215 unit + 20 e2e, bridge PHPUnit both WP matrices, lint, `astro check`, LHCI, `build:prod-sim`, contract-grep.
6. The playbook (§3) is documented in `docs/operations/deployment/` with the P0–P6 gates, and the skills reflect the new resources.
7. Forge parity: a forged site's `pages` + `posts` collections carry Rank Math/Yoast meta when present, and `posts/[...parts].astro` + `[slug].astro` emit `Article`/`BlogPosting`/`WebPage` JSON-LD matching velocity's shapes.
8. Hybrid local content: a forged site with **no WP connection** renders its example local post/page (demo-runnable); a slug collision between a local doc and a WP doc resolves to the WP doc on both apps (L2 pinned by unit tests); velocity's `/blog/` merges local + envelope posts with one JSON-LD/sitemap path (L3).

## 9. Open questions (recorded, non-blocking)

- **Q1:** real-migration schedule — if a specific Shopify store is in play, P0 tool choice (official vs Data Migrator) and the redirect CSV shape should be confirmed against it (O-7 follow-up).
- **Q2:** T1 cart-resource sketch (variant-aware carts, orders, customer accounts) — start it as the companion doc to Proposal 090 when the Laravel adapter spike (090) is scheduled.
- **Q3:** multi-site velocity — should `globals/shop` and the content resources be per-site? Assumed single-site in v1; additive later.

## Appendix — touched surfaces

**Bridge:** `Schema.php` (options), `Plugin.php` (controller registration), `REST/PagesController.php` + `REST/PostsController.php` + `REST/ShopGlobalsController.php` (new), `Sources/WooProductSource.php` (variant hydration), transformers, `src/` (settings + CMS wiring), phpunit.
**Storefront:** `src/lib/commerce/client.ts` (getShop, getPages, getPosts), `src/lib/commerce/types.ts`, `src/content.config.ts` (new — local collections), `src/content/posts/` + `src/content/pages/` (new — demo seed), `src/lib/content/merge.ts` (new — L2 merge helper), `src/pages/pages/[id]/[...slug].astro` + `src/pages/blog/index.astro` + `src/pages/blog/[id]/[...slug].astro` (new), PDP variant island, `src/pages/cart/index.astro` (checkout handoff), `src/lib/seo/jsonld.ts` (Article/BlogPosting/offers), `src/pages/sitemap.xml.ts`, `scripts/fixture-server.mjs`, tests (unit + e2e `content.spec.ts`, `variants.spec.ts`, `checkout.spec.ts`).
**Forge:** `templates/blog/src/content.config.ts` (glob + WP loaders), `templates/blog/src/content/posts/` + `templates/blog/src/content/pages/` (new — example seed), scaffold dir creation, SEO-meta fields + JSON-LD parity; no route changes (`posts/[...parts].astro`, `[slug].astro` already exist).
**Docs:** this proposal set, deployment doc, `mcp-ai-wpoos-astro-storefront` + `mcp-ai-wpoos-astro-forge` skills.
