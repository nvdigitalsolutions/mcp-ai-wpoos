# Proposal 106 — Implementation Plan: Shopify→WP Headless — Variants, CMS Content (+ Hybrid Local), Checkout Handoff

**Status:** IMPLEMENTED (2026-11-03) — all phases shipped; see the proposal status line for the summary and the cluster validation counts (bridge 128 tests × WP 6.9/7.1; storefront 290 unit / 57 e2e / LHCI / prod-sim; forge 250 unit / 25 e2e incl. offline build).
**Companion to:** `106-shopify-to-wp-headless-migration-proposal.md` + `-research.md`
**Branches/PRs:** cluster commits on `feat/astro-105-106-theming-content`, one PR against `alpha-working`.

Contract shapes are fixed in §1 — all surfaces implement against them verbatim. Phases 1–3 map to the three gap closures; Phase 4 is the playbook documentation.

---

## 1. The contracts (fixed — do not deviate)

### 1.1 Variants (additive, single-doc GETs only)

```json
"variants": [
  { "id": 456, "sku": "SOF-3S-OAK", "price": 1299, "oldPrice": null,
    "stock": 4,
    "attributes": [ { "name": "Size", "value": "3-Seater" } ],
    "image": "https://…/sofa-oak.jpg" }
]
```

- Present **only** on product single-doc GETs; listings stay flat (Payload pattern). Absent = simple product. `oldPrice`/`image`/`stock` nullable. `attributes` ordered as the product's option order (Woo attribute order).
- Woo mode only (`WooProductSource`): variable products hydrate children via `wc_get_product($child_id)`; simple products → no `variants` key. Native mode never emits `variants` (documented limitation).
- Saleor validation (research): variants own pricing/stock/attributes; checkout references **variant IDs**. Our cart line items therefore store `{ productId, variantId, attributes, quantity }`.

### 1.2 CMS content — `content/pages` + `content/posts`

Routes (public READABLE; CREATABLE/EDITABLE/DELETABLE gated by `manage_astro` — the PostTypeController pattern):

- `GET/POST /content/pages` · `GET/PUT/DELETE /content/pages/{id}`
- `GET/POST /content/posts` · `GET/PUT/DELETE /content/posts/{id}`
- Listings: `page`, `limit` (≤100) + `where[slug][equals]`; posts additionally `where[category][equals]` (WP category). Publish-only in public listings (drafts excluded; the synthetic draft id 9902 pins this).

```json
{ "id": 21, "slug": "about", "title": "About", "excerpt": "…",
  "content": "<!-- raw post_content -->",
  "contentRendered": "<p>…</p>",
  "type": "page", "featuredImage": "https://…",
  "seoTitle": "…", "seoDescription": "…",
  "category": { "id": 3, "name": "Lookbooks" },
  "createdAt": "2026-09-10 10:00:00", "updatedAt": "2026-10-20 09:30:00",
  "status": "publish" }
```

- `contentRendered` = `apply_filters( 'the_content', $post->post_content )` (WPGraphQL RENDERED precedent); falls back to raw `content` when the render is empty. `excerpt` = `get_the_excerpt` fallback to trimmed content. `featuredImage` = `get_the_post_thumbnail_url($post, 'large')` or null. `category` = first WP category (posts only), else null.
- SEO meta read **directly from post meta** (robust — no dependency on the SEO plugin's REST toggle, research §3): Rank Math `rank_math_title`/`rank_math_description` → Yoast `_yoast_wpseo_title`/`_yoast_wpseo_metadesc` → fallback `title`/`excerpt`. Absent everywhere → empty strings.
- `createdAt`/`updatedAt` format matches the existing envelope (`Y-m-d H:i:s`, the fixture/golden convention).

### 1.3 Shop globals — `globals/shop`

```json
{ "checkoutUrl": "https://nvoos.pro/checkout/", "cartUrl": "https://nvoos.pro/cart/",
  "currency": "USD", "currencySymbol": "$", "updatedAt": "2026-11-03T09:00:00Z" }
```

- `GET /globals/shop` public · `PATCH` `manage_astro`-gated. Option `funiq_bridge_shop` seeded from `home_url('/checkout/')` / `home_url('/cart/')` + Woo currency (`get_woocommerce_currency()` when active, else USD). Cache: `s-maxage=60` (same as the 105 policy doc).

## 2. Phase 1 — Variants (bridge + storefront)

**Bridge:** `Sources/WooProductSource.php` — single-doc hydration: when `wc_get_product` returns `WC_Product_Variable`, build `variants` from `get_children()`/`get_available_variations()` (id, sku, price/regular+sale → price/oldPrice, stock via `get_stock_quantity()`, attributes from `get_variation_attributes()` ordered by the parent's `get_attributes()` order, image from the variation image or parent fallback). Transformer output keyed identically for the `nvoos-commerce/v1` shape; golden test updated with one variable fixture product (format-only discipline: run `npm run sync:bridge-fixtures` after the transformer change).

**Storefront:**
- `src/lib/commerce/types.ts` — `ProductVariant` type; `Product` gains optional `variants`.
- PDP (`src/pages/products/[id]/[...slug].astro`) — server-side: pass variants; JSON-LD `offers` becomes `Offer[]` from variants (schema-dts; the 104 `productJsonLd` extended — keep the existing `Product` branch pattern).
- `src/islands/VariantSelector.tsx` (new, `client:load` on the PDP) — attribute groups → option buttons (radio semantics, `aria-pressed`/checked), sold-out options disabled, selection updates displayed price (and the cart line stores `variantId` + attributes), state persists across client-side navigations via the storage module (`nvoos-cart` line items already live in `src/lib/store/cart` — extend the line-item shape).
- Fixture server: one variable product (id 3, "Aurora Sofa" variants: 3-Seater/2-Seater/Loveseat + colours) with `variants` on the single-doc route only; listing route unchanged.
- Tests: `tests/unit/variants.test.ts` (hydrate/normalize/ladder), e2e `tests/e2e/variants.spec.ts` (select variant → price + JSON-LD update; sold-out disabled; selection survives a client-side link navigation — the Defect A regression class).

## 3. Phase 2 — CMS content + hybrid local (closes the pages/posts + local gaps)

**Bridge:** `REST/PagesController.php` + `REST/PostsController.php` (new) extending `PostTypeController` (`post_type(): 'page'|'post'`; `transform()` per §1.2; `post_args()`/`save_meta()` for title/content/excerpt/status; posts additionally category). `Plugin::register_rest_routes()` gains both. Note: `PostTypeController::get_items` hardcodes `post_status => publish` — keep (public listings) and add the `where[slug]`/`where[category]` handling in the child controllers (simple `WP_Query` args, not the source layer). PHPUnit: transform shape (incl. contentRendered shortcode execution — use a test shortcode), draft exclusion (create id 9902 as draft in the test factory), slug/category where-clauses, permission gates, cache headers.

**Storefront (velocity):**
- `src/lib/commerce/client.ts` — `getPages/getPosts/getPage/getPost/getShop` (+ `getThemePolicy` from 105) via the `globals/content` path pattern; posts listing carries where/category + pagination params.
- Routes: `src/pages/pages/[id]/[...slug].astro` (SSR; 301 canonical to `/pages/{id}/{slug}` — the 104 PDP pattern; `WebPage` JSON-LD via schema-dts; `s-maxage=300`, cache tags `['content','pages']` + policy revision); `src/pages/blog/index.astro` (paginated listing, PLP pattern, `CollectionPage` JSON-LD); `src/pages/blog/[id]/[...slug].astro` (`BlogPosting` JSON-LD; canonical + 301). Render `contentRendered` inside a styled `.prose` container (reuse existing content styles; add minimal `.content-body` styles in `global.css`).
- **Hybrid local (§4.4):** `src/content.config.ts` (new) — `defineCollection` with `glob()` loaders over `src/content/posts/*.mdx` + `src/content/pages/*.mdx` (frontmatter: `title`, `description`, `date`, `draft`); `src/lib/content/merge.ts` (new) — L2 merge helper: envelope docs win on slug collision; local fills gaps; sorted by date desc for posts. `/blog/` and `/blog/[id]/[...slug]` merge both sources; `/pages/[id]/[...slug]` renders envelope docs and falls back to local MD pages rendered via the collection entry when no envelope doc matches (`about`/`terms` keep working through this fallback — O-4). Demo seed: one local post + keep the existing MD pages as local pages.
- `src/pages/sitemap.xml.ts` — add pages + posts (both sources). Nav: add "Blog" link in `BaseLayout.astro`.
- Fixture server: `content/pages` + `content/posts` (2 pages incl. `about`-slug page, 3 posts, one draft id 9902 excluded from listings), `where[slug]`/`where[category]` support, `globals/shop` route.
- Tests: `tests/unit/content-merge.test.ts` (L2 collision matrix), client tests, e2e `tests/e2e/content.spec.ts` (page renders contentRendered; blog paginates; canonical/301; JSON-LD; local post renders with no envelope doc; axe clean).

**Forge (parity + hybrid local):**
- `templates/blog/src/content.config.ts` — add `glob()` loaders to the `posts` + `pages` collections (merge with the WP loaders; collision = WP wins, L2) and scaffold dirs `templates/blog/src/content/posts/` + `templates/blog/src/content/pages/` with one example post (demo-runnable with no WP connection).
- `lib/wp-loader.ts` — add optional Rank Math (`rank_math_title`, `rank_math_description`) + Yoast (`yoast_head_json`) to the posts/pages `fields` lists (conditional when present — the loader must tolerate absent fields/plugins).
- Template JSON-LD parity: `Article`/`BlogPosting`/`WebPage` in `posts/[...parts].astro` + `[slug].astro` matching velocity's shapes (via the existing `lib/seo.ts`).
- Tests: `tests/wp-loader.test.ts` (fields list incl. SEO meta; plugin-absent tolerance), `tests/theme.test.ts` or a new `tests/content.test.ts` (glob+WP merge), e2e + CLI smoke (scaffold contains `src/content/posts/` example).

## 4. Phase 3 — Checkout handoff

- **Bridge:** `REST/ShopGlobalsController.php` (new; `globals/shop` per §1.3 — GET public / PATCH gated / `s-maxage=60`; option `funiq_bridge_shop` seeded in `seed_default_options()`).
- **Storefront:** `src/pages/cart/index.astro` — reads line items (`src/lib/store/cart`), builds the Woo checkout URL: `${checkoutUrl}?add-to-cart=${ids.join(',')}` for simple-product lines (comma form — research §4); variant lines render a note ("Variants checkout opens in the next release" — honest UX) and are excluded from the URL; a "Checkout on WooCommerce" button links out (absolute, target `_self`, no BFF proxy); after click, clear local cart. `getShop()` cached 60 s.
- Fixture: `globals/shop` doc; e2e `tests/e2e/checkout.spec.ts` (cart with 2 simple products → button href contains both IDs; variant line → note present, excluded; empty cart → disabled button; axe clean).
- **Pin the comma-form claim against the repo's WC matrix** during implementation (test bootstrap stubs Woo — the URL builder is unit-tested as a pure function: `buildCheckoutUrl(shop, lines)` in `src/lib/commerce/checkout.ts`, unit-tested without WC).

## 5. Phase 4 — Playbook docs

- `docs/operations/deployment/nvoos-astro-velocity-setup.md` — new "Shopify → WP headless migration" section: the P0–P6 runbook from the proposal (import tools, dry-run gates, bridge `woo` mode, design-import + 105 policy, redirect map → forge `redirects.json`, checkout handoff, content routes, launch gates).
- Skills: `mcp-ai-wpoos-astro-storefront` (new resources: content, shop, variants, theme policy) + `mcp-ai-wpoos-astro-forge` (hybrid local collections, `--theme-policy` preset) — keep both under Zed's ~100 KB load limit.
- Proposal statuses: 105 + 106 → IMPLEMENTED; `README.md` entries left to the docs owner (foreign file — do not edit).

## 6. Cross-cutting gates (every phase)

Bridge: `php -l` + phpcs (bridge ruleset) + PHPUnit (Docker `oos-wp`, DB `wordpress_test_astro`; WP 6.9 + 7.1 where the matrix runs). Storefront: `check` (8 GB heap on Windows) + `lint` + `test` + `test:e2e` + LHCI + `build:prod-sim`. Forge: typecheck + test + e2e + CLI smoke. Fixture/golden parity: `npm run sync:bridge-fixtures` after any transformer change; format-only diffs only.

## 7. Shared pitfalls

- Same as the 105 plan §6 (trailing-slash POST→GET on `/internal/*`-style routes; CSP inline-script hashing; delegated handlers + `astro:after-swap`; generated-TS lint style; cluster-scoped staging).
- `contentRendered` runs `the_content` — in PHPUnit, shortcodes registered in the test bootstrap must be deterministic (register a `[fixture_seo]` shortcode in the test, assert it executed).
- The `where` params on the new listings must be sanitized per the two-gate rule (sanitize entry, escape/type-coerce exit) — follow `Sources/WhereClause` conventions.
- Do not touch `docs/project/proposals/README.md` (another agent's in-flight edit) and do not stage anything — the coordinator owns git.
