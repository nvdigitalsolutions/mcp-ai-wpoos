# Proposal 106 — Research: Shopify → WP Headless Migration — Tooling, Content Rendering, and Checkout Handoff Standards

**Date:** 2026-11-03
**Companion to:** `106-shopify-to-wp-headless-migration-proposal.md`

Sources every load-bearing claim in the proposal. Structure: (1) method, (2) migration-tooling citations, (3) the content-rendering standard (WPGraphQL precedent), (4) the checkout-handoff standard, (5) SEO/URL migration standards, (6) local code evidence for the three gaps.

---

## 1. Method

The question: a Shopify merchant moves to WordPress/WooCommerce as the backend, with `examples/nvoos-astro-velocity` + the `nvoos-astro-bridge` as the headless front. What does industry do for (a) the data migration itself, (b) serving CMS content headlessly, (c) handing checkout off to the platform, and (d) preserving SEO? Findings are cross-checked against the shipped code (bridge envelope, storefront routes, forge templates) so the proposal only specifies what the stack actually lacks.

## 2. Migration tooling (the import layer is bought, not built)

| Tool | What it imports | Citation |
|---|---|---|
| **WooCommerce Marketplace: "Migrate & Import from Shopify to WooCommerce"** (official) | Products, categories, orders, coupons, blogs, pages, customers; connection-status + completion email | https://woocommerce.com/products/migrate-import-shopify-to-woocommerce/ and https://woocommerce.com/document/migrate-import-shopify-to-woocommerce/ |
| **Shopify Data Migrator** (WooCommerce Marketplace) | Products, customers, orders, pages, blogs, coupons, reviews + **SEO redirects and webhook sync** | https://woocommerce.com/products/shopify-data-migrator/ |
| **S2W — Import Shopify to WooCommerce** (wordpress.org, community) | Products (incl. images) + categories, two-step flow | https://wordpress.org/plugins/import-shopify-to-woocommerce/ |
| **wpbeginner runbook** | End-to-end move incl. the rule: *"preserve your rankings by setting up 301 redirects from every old Shopify URL to its matching WooCommerce page"* | https://www.wpbeginner.com/wp-tutorials/how-to-properly-move-from-shopify-to-woocommerce-step-by-step/ |

**Implication:** the proposal treats import as an external step with a verification gate (dry-run, image check, redirect-list capture), then specifies everything *after* the data lands in WP/Woo. No Shopify SDK or extraction code enters the repo — it would violate the licensing split (Q8) and duplicate paid tooling.

## 3. Content rendering — the WPGraphQL precedent

The bridge must serve WP pages/posts through its own `nvoos-commerce/v1` namespace (the contract rule: the Astro app never calls WP-specific endpoints). WPGraphQL is the industry reference for how headless WP serves content:

- WPGraphQL exposes a `content` field with a `format` argument: **`RENDERED` is the default for public requests** (post content passed through `the_content` filters — shortcodes, embeds, and page-builder output execute server-side); **`RAW` is only available to authenticated requests** (content security decision): https://github.com/wp-graphql/wp-graphql/discussions/2863
- The compatibility guide documents that block-editor content "is exposed … as HTML … the rendered version of the content and not the raw JSON data": https://www.wpgraphql.com/docs/compatibility-guide
- Page-builder shortcodes (WPBakery/Elementor) render inside `RENDERED` queries **when the builder plugin is active on the WP side** — the known headless caveat (if the builder is retired, stored shortcodes degrade to raw text): https://medium.com/@chaminduappuhamy/how-to-render-wpbakery-element-shortcodes-in-graphql-2a4359085b62
- Structured content over raw HTML is a separate add-on (`wpgraphql-content-blocks`), confirming raw HTML output is the baseline standard: https://www.wpgraphql.com/extenstion-plugins/wpgraphql-content-blocks

**Implication (design rule C1):** the pages/posts doc ships **both** `content` (raw, for CMS edit round-trips) and `contentRendered` (`apply_filters('the_content', …)`, for the storefront). The storefront renders `contentRendered` with a documented builder caveat. SEO meta (Rank Math `rank_math_title`/`rank_math_description`, Yoast `_yoast_wpseo_title`/`_yoast_wpseo_metadesc`) is read additively when present — the bridge currently reads none (grep-verified), and the base plugin's Rank Math integration is unrelated to the addon.

### 3b. Hybrid local + remote content — the Astro Content Layer precedent

The "local posts/pages alongside WP content" model is native to Astro's Content Layer API: collections compose a `glob()` loader over `src/content/**` with a remote loader — the forge template's `createWpLoader` is already one such custom loader (verified in `templates/blog/src/content.config.ts` on `feat/astro-104-forge-phase4`, which defines WP-backed `posts`/`pages`/`products` collections). Entries merge into one collection; collision resolution is the site author's choice (entry ids), which is what design rule L2 formalizes (WP wins; local content is the fallback + demo seed). **Implementation note (verified 2026-11-03): Astro 7.4.4's `defineCollection` takes a SINGLE loader — there is no loaders-array merge** (checked against `node_modules/astro/dist/content/config.d.ts` + `content-layer.js`). The merge is therefore implemented inside the custom loader (glob first → WP second → local re-added only where WP produced no id). Reference: Astro content collections + loaders documentation (https://docs.astro.build/en/guides/content-collections/).

**Implication (design rules L1–L3):** the forge template merges `src/content/posts/` + `src/content/pages/` globs with the WP loaders inside `createWpLoader` (one collection, two sources); velocity gains the same local collections merged at the route layer (its `/blog/` merges envelope posts + local posts through one template). This also makes forged sites demo-runnable with no WP connection.

## 4. Checkout handoff — browse headless, transact on the platform

The industry-standard headless pattern (Shopify Hydrogen does exactly this — headless storefronts hand checkout to the hosted checkout domain) maps to WooCommerce as: **the storefront builds a Woo cart/checkout URL and links out to the WP frontend**.

- WooCommerce supports adding products to the cart via URL: `?add-to-cart=<id>` for a single simple product, and **comma-separated IDs** (`?add-to-cart=12,34`) to add multiple simple products in one hit. Variations require `variation_id` + attribute pairs and are **not** supported by the comma form (simple products only). **Implementation note:** pin this against the repo's WC 11.x matrix during Phase 3 — the test matrix is the authority, not this note.
- The BFF (`scripts/serve.mjs`) deliberately proxies only `/api/*` and `/media/*` (2026-10-29 decision, PR #7294 reversal) — so the handoff is an **absolute external link** to the backend's `home_url()` checkout path, exposed through the contract (`globals/shop`), never a proxy route. No BFF change.
- The storefront's `/cart/` is a client-side nanostores demo (`CartBadge` reads `loadCartFromStorage`); the handoff converts its line items into the checkout URL and clears local state after redirect.

**Implication (design rule H1):** a full headless checkout *resource* (cart/orders/customers in the envelope) is deliberately out of scope — it belongs to the **T1 Laravel backend** (Proposal 090), which replaces the transitional bridge anyway.

## 5. SEO / URL migration standards

- **301 every legacy URL** — the load-bearing rule from the wpbeginner runbook (above).
- The forge template already ships `redirects.json.ts` (Astro's static redirect file) — the natural landing spot for the Shopify redirect export: `examples/nvoos-astro-forge/templates/blog/src/pages/redirects.json.ts` (verified on `feat/astro-104-forge-phase4`).
- The velocity storefront is ID-based (`/products/[id]/[...slug]`) with 301 canonicalization shipped in Proposal 104 — so `/products/123` → `/products/123/<slug>`; handle-only Shopify URLs (`/products/my-handle`) resolve through the `where[slug][equals]` lookup + a redirect entry (design rule U1).
- Sitemap/JSON-LD/LHCI/JS-disabled crawl gates already exist in velocity CI (Proposal 104 P2/P3); the content routes must join the same gates (Article/BlogPosting JSON-LD, sitemap entries).

## 6. Local evidence — the three gaps (file-verified)

| Gap | Evidence |
|---|---|
| **Variants absent from the contract** | `grep -i "variation|variable|variant"` across `addons/nvoos-astro-bridge/includes/**` → zero matches. Product docs are flat: single `price`/`oldPrice`, no `variants` array (`tests/fixtures/products.json` on `feat/astro-104-storefront-runtime`) |
| **Cart is client-side only** | `src/islands/CartBadge.tsx` reads nanostores `loadCartFromStorage` → `/cart/`; the contract has no cart/orders/customers controllers (bridge `REST/` listing: auth, banner, brands, carousel, categories, colors, managers, media, post-type base, products, promocodes, promotions, statuses, terms) |
| **No pages/posts resource** | Bridge `PostTypeController` exists but is only extended by promotions/promocodes; velocity serves `about.md`/`terms.md` as hardcoded Markdown (`src/pages/`) and has no dynamic content routes; forge already generates a static content layer (`content.config.ts`, `posts/[...parts].astro`, `[slug].astro`, `redirects.json.ts`) |
| **No SEO-meta passthrough** | `grep -i "rank_math|yoast|_yoast"` across the bridge → zero matches |
| **Seam for the fix exists** | `PostTypeController` (abstract: `post_type()`, `transform()`, `post_args()`, `save_meta()`; public READABLE, `manage_astro`-gated writes) is the exact extension point for `PagesController`/`PostsController` |

## 7. Findings → design decisions

| # | Finding | Decision in the proposal |
|---|---|---|
| 1 | Import tooling is mature and bought, not built | Phase 0 uses the official Woo migrator + redirect export; repo stays Shopify-free |
| 2 | WPGraphQL serves RENDERED content publicly, RAW gated | Envelope doc ships `content` + `contentRendered`; storefront renders the latter |
| 3 | Builder shortcodes need the builder active server-side | Documented caveat + a `contentRenderedFallback` behaviour (raw when render returns empty) |
| 4 | Comma-form add-to-cart covers simple products only | v1 handoff = simple products; variant carts = T1 Laravel cart resource |
| 5 | BFF must not proxy WP checkout | `globals/shop.checkoutUrl` absolute link, no proxy change |
| 6 | 301s preserve rankings; Astro has a redirect file | Redirect export → forge `redirects.json` + bridge 301 canonical (104) + slug lookup |
| 7 | Velocity lacks content routes; forge already has them | P2 closes the gap on velocity (SSR routes); forge needs only SEO-meta parity |
| 8 | Neither app has local content collections (velocity: only hardcoded MD pages; forge: WP-loader-only) | §4.4 hybrid model — `glob()` loaders merged with WP loaders (Astro Content Layer multiple-loaders precedent) |
