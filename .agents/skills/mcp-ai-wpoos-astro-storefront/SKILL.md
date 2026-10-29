---
name: mcp-ai-wpoos-astro-storefront
description: Operational guide for the NV-authored headless storefront (Proposal 092) — the funiq-bridge Payload-shaped commerce contract (`nvoos-commerce/v1`), the `examples/nvoos-astro-velocity` Astro app, its hermetic dev/test loop (fixture server, vitest, Playwright, Lighthouse CI), the contract drift guard, the rebuild webhook, and the sync workflow + public mirror deployment. Use when working on the storefront, the bridge contract, image optimization, SEO/crawlability, or the mirror deploy loop.
---

# NV oOS Astro Storefront — ops guide (Proposal 092)

## What this is

The public, NV-authored headless storefront: an Astro 7 app consuming a
Payload-shaped commerce envelope over same-origin `/api/*`, replacing the
licensed Funiq PWA (owner decision Q1: keep A/B until parity review, then
retire). Full context: `docs/project/proposals/092-astro-storefront-layer-proposal.md`.

| Piece | Location | Role |
|---|---|---|
| Bridge addon | `addons/funiq-bridge/` | Serves the Payload envelope from WordPress + WooCommerce data (transitional backend; Laravel takes over at T1 per Proposals 085/090) |
| Storefront source | `examples/nvoos-astro-velocity/` | Astro SSR + prerendered catalog, React islands (cart badge, gallery), View Transitions |
| Sync workflow | `.github/workflows/sync-nvoos-astro-velocity.yml` | Builds + tests, then rebuilds for the mirror |
| Public mirror | `nvdigitalsolutions/nvoos-astro-velocity` (PUBLIC) | Assembled deploy tree (Astro `dist/` + server shim) — zero licensed bytes |

## Contract rules (CI-enforced — never weaken)

- The Astro app binds **only** to the Payload envelope (`nvoos-commerce/v1`
  REST namespace alias). Never call WP-specific endpoints from `src/`.
- The word **"funiq" is banned from `examples/nvoos-astro-velocity/src/`**
  (CI grep gate). The bridge addon and docs may still name it (data-layer
  identifiers); the app may not. The standalone admin bundle is built output
  in `public/` and contains zero brand strings anyway.
- Golden fixtures are the contract test: `examples/nvoos-astro-velocity/tests/fixtures/products.json`
  must stay semantically identical to the bridge transformer output pinned by
  `addons/funiq-bridge/tests/phpunit/Transformers/TestTransformerGolden.php`.

## WooCommerce integration (2026-10-29)

- The bridge has two product backends behind one envelope: **`native`**
  (CMS Basic — the built-in `funiq_product` CPTs, the default) and
  **`woo`** (WooCommerce `product`), plus **`both`** (merged). Mode via
  option `funiq_bridge_products_source` / filter
  `funiq_bridge_products_source`; `woo`/`both` degrade to `native` when
  WooCommerce is missing. Product docs carry an additive `source` field
  (`native`|`woocommerce`).
- **Source layer:** `addons/funiq-bridge/includes/Sources/` —
  `ProductSource` interface, `NativeProductSource`, `WooProductSource`,
  `ProductSources` (registry: `active()`/`primary()`/`resolve()`/
  `from_name()`), `WooTaxonomy` (pa_brand/pa_color/product_cat/stock-status
  mapping), `WhereClause`/`SortClause` (shared operators).
  `ProductsController` is a thin delegator — the contract never changes.
- **Woo availability check is `class_exists('WooCommerce') &&
  function_exists('wc_get_product')`** — the repo's test bootstrap defines a
  stub `WooCommerce` class when the plugin is absent, so class_exists alone
  is a false positive (WP 7.1 matrix caught this).
- **Listing uses `WP_Query` over the `product` post type + `wc_get_product`
  hydration, NOT `WC_Product_Query`** — WC's data store drops `s` and
  `meta_query` from its query vars (verified against WC 11.1.2 source).
  Woo meta keys: `_price`, `_wc_average_rating` for price/rating sort and
  filters.
- In `both` mode, create routes via the body `source` field; merged
  listings concatenate per-source pages (documented merged-pagination
  semantics). Statuses in woo mode = stock-status pseudo-docs (ids 1-3,
  read-only — writes 405). Categories in woo mode = `product_cat`
  (productsCount via `term->count`).
- Tests: `tests/phpunit/REST/TestWooProductSource.php` (10 tests — skipped
  when Woo is absent, e.g. the WP 7.1 matrix env).

## Dev loop (hermetic — no live site needed)

```bash
cd examples/nvoos-astro-velocity
node scripts/fixture-server.mjs            # :8123 — hermetic contract + media
cd ../../addons/funiq-bridge && npm install && cd -   # standalone CMS bundle deps
NVOOS_API_BASE=http://localhost:8123/wp-json/nvoos-commerce/v1 npm run build   # prerenders catalog + /manage bundle
NVOOS_TARGET_SITE=http://localhost:8123 PORT=4321 npm run serve                # BFF + app (/, /manage/, /api/*, /media/*)
npm run check && npm run lint && npm run test          # astro check · eslint · vitest (unit + contract)
npx playwright install chromium && npm run test:e2e    # 2 specs — JS-disabled SEO + islands
npm run lhci:ci                                        # CHROME_PATH → Playwright's Chromium
```

- The fixture server returns **401 on `/auth/me` without a cookie** and serves
  a real 1×1 PNG on `/wp-content/uploads/` — keep both behaviours.
- PHP side (bridge): `php -l` + phpcs, then PHPUnit in Docker (`oos-wp`
  container, isolated DB `wordpress_test_astro`) — see the
  `mcp-ai-wpoos-test-suite` skill for the exact recipe. Run against WP 6.9
  **and** WP 7.1.

## Image optimization (Phase 1.7) — the two rules that cost a session

- The runtime endpoint is **`/**_image/**` (trailing slash required)**; the
  no-slash variant 404s.
- `src/lib/images.ts` must emit **absolute same-origin hrefs** — relative
  hrefs 404. Use `renderOrigin()` (`Astro.site` when prerendering, `Astro.url`
  at request time) via `responsiveImage`/`fixedImage`.
- `astro.config.mjs` derives `image.domains` from `ASTRO_SITE` — so a build
  against the fixture server uses a `localhost` origin, and the mirror
  workflow rebuilds with the **prod host** in `ASTRO_SITE`.

## Key gotchas (learned the hard way)

- **CartBadge** must hydrate `client:load transition:persist` — `client:idle`
  is too slow for Playwright e2e.
- Playwright islands with `client:visible` hydrate lazily → use `toPass()` +
  scroll; keep header elements on `client:load`.
- **RebuildWebhook** (`addons/funiq-bridge/includes/RebuildWebhook.php`):
  shutdown closures double-fire on Cloudways and full `shutdown` actions trip
  output buffers — the class keeps a static `$pending` flag with
  `send_pending()`/`reset_pending()` seams. Test through those seams, not the
  action.
- **TestTransformerGolden**: paths use `dirname(__DIR__, 2)`; golden JSON and
  transformer output must both be normalized (`normalize_golden_fields()` —
  floats like `1299.0` vs `1299`, name-sorted terms) before comparison.
- `updated_option`/`edited_term` hooks need all 3 args in PHPUnit —
  WooCommerce handlers are strict.
- `do_action('init')` re-fires WooCommerce "already registered" notices —
  register CPTs directly (test pattern #2) and grant capabilities in the
  bootstrap; PHPUnit never runs activation.
- Drift guard: after touching the bridge transformer, run
  `npm run sync:bridge-fixtures` and confirm `tests/fixtures/products.json`
  shows a format-only reflow, not semantic changes.
- **Base resolution precedence (incident 2026-10-29):** the mirror build bakes
  `NVOOS_API_BASE` (fixture `localhost:8123`) into `dist/`. `resolveBase()` in
  `src/lib/commerce/client.ts` must give the **request URL priority** at
  runtime (same-origin `/api/*` proxy) and only fall back to the baked env
  when there is no request URL (build-time `getStaticPaths`). The old
  env-first order made every SSR render fetch localhost → 500 homepage while
  `/api/*` stayed green. Regression tests pin this in
  `tests/unit/client.test.ts` ("base resolution" describe block).
- **ASTRO_SITE is bake-time only** — setting it at runtime on Velocity does
  nothing. Canonical host comes from the mirror rebuild
  (`sync-nvoos-astro-velocity.yml`, default `https://astro.nvoos.cloud`,
  overridable via repo variable `ASTRO_SITE` / dispatch `client_payload.astro_site`).
- **PORT on Velocity:** Velocity injects `PORT`; a manual or committed `.env`
  `PORT` (e.g. 3025) must match the routing target or pages won't be served —
  remove manual PORT if in doubt.
- **Bridge late-load immunity (PR #7281):** the Complete bundle can load
  addon files mid-request (after `plugins_loaded`). `Plugin::init()` is
  idempotent and registers CPTs/taxonomies/REST routes directly when
  `init`/`rest_api_init` already fired. The capability migration
  (`Plugin::maybe_migrate_caps`) follows the same rule — it must run
  directly when `did_action('init')` (fix 2026-10-29: the original
  `add_action('init', …)` registration never fired on the fleet's
  mid-request load, so `manage_astro` was never granted and managers hit
  the /manage permission wall). If the contract returns 404 on a
  fleet site, check for a late-loader before touching the bridge.
- **No admin proxy (2026-10-29 decision, replaces PR #7294's login gateway):**
  the storefront BFF (`scripts/serve.mjs`) proxies **only** `/api/*`
  (contract) and `/media/*` (uploads). `wp-admin`, `wp-login.php`,
  `wp-json` and `wp-includes` are deliberately NOT proxied — the admin
  experience is the standalone **Astro CMS** on `/manage`: `src/pages/manage/index.astro`
  + `src/islands/ManageGate.tsx` (sign-in via the contract's `/api/auth/*`,
  `loginOnly` AuthForms, then injects the prebuilt bundle which self-mounts
  on `#astro-admin-root`). The bundle lives in `public/manage-assets/` —
  built by the bridge's **standalone esbuild target**
  (`npm run build:standalone` in `addons/funiq-bridge/`, output
  `build/standalone/astro-admin.{js,css}`; bundles `@wordpress/*` + React
  instead of the wp-admin externals) and copied by
  `scripts/sync-manage-assets.mjs` (wired into `npm run build` +
  `build-hermetic.mjs`). Manager login never touches a proxied login page:
  `POST /api/auth/login` sets the auth cookie, the BFF strips `Domain=`
  (host-only), and `/api/auth/me`'s `canManage` gates everything. The sync
  workflow installs bridge deps (`npm ci` in `addons/funiq-bridge/`) before
  the app build and re-runs on bridge path changes.
- **Bridge media endpoint:** image fields use a native uploader against
  `POST /media` (multipart, manage-cap gated; `GET /media/{id}` for
  previews) — `wp_handle_upload` with a filterable action
  (`funiq_bridge_media_upload_action`, default `wp_handle_upload` keeps the
  `is_uploaded_file` gate; tests switch it to `wp_handle_sideload` since CLI
  cannot produce real uploads).
- **Astro CMS rebrand (2026-10-29):** user-facing funiq branding is gone —
  menu/slug `astro-cms`, sidebar brand "Astro CMS", mount id
  `astro-admin-root`, config global `AstroAdminConfig`, capability
  `manage_astro` (one-shot migration from `manage_funiq` in
  `Plugin::maybe_migrate_caps()`; uninstall removes both). Data-layer
  identifiers (CPT/meta/option names, `funiq/v1` REST namespace, PHP
  namespaces, folder) are deliberately unchanged — renaming them is a
  separate data-migration project.
- **Mirror deploy tree (PR #7291):** `sync-dist.mjs` must ship every runtime
  import of `serve.mjs` (now `scripts/lib/`); a staged-tree boot smoke fails
  the assemble step when the tree crashes at boot (the 2026-10-29 502).

## Deploy & revalidation

- `npm run sync:dist` assembles the deploy tree; the workflow has **two
  builds**: localhost-origin (smoke/e2e/LHCI) and prod-host rebuild (mirror).
- Rebuild trigger: bridge fires a debounced `nvoos-storefront-content-changed`
  → GitHub `repository_dispatch` → the sync workflow.
- Mirror repo is public (owner Q6). A/B coexistence rules and rollback:
  `docs/operations/deployment/nvoos-astro-velocity-setup.md`.
- **Bridge packaging for zip upload (learned 2026-10-29):** the deploy zip
  must contain the plugin as a **top-level `nvoos-funiq-bridge/` folder**
  (main file `funiq-bridge.php` inside). Build it with PowerShell
  `Compress-Archive` of the folder — `tar -a` produces a broken zip, and
  zipping `folder\*` (files at zip root) installs `funiq-bridge.php` directly
  into `wp-content/plugins/`, colliding with other plugins' `includes/`.
  Uploading twice with the same folder name creates a random-suffix folder —
  delete the old copy first.
- **Bridge code standards:** the addon carries its own
  `addons/funiq-bridge/phpcs.xml.dist` (PSR-4 filename sniffs, short
  ternaries and the deliberate meta_key/tax_query slow-query warnings are
  excluded) and is excluded from the base `phpcs.xml.dist` scan. A dedicated
  step in `.github/workflows/php-linting.yml` enforces the addon ruleset on
  every PR/push.

## Standards this app is built to (research-backed)

- **Hybrid rendering** — prerender the catalog at build; SSR the dynamic
  pockets (stock, promos). This is the 2026 Astro headless-commerce standard.
- **Astro Image** for automatic WebP/AVIF + lazy loading via the runtime
  endpoint (above).
- **SEO**: canonical single product URL, `Product` JSON-LD in initial HTML
  with SKU-aligned IDs, `/sitemap.xml`, robots, cache headers + revalidation —
  verified by the JS-disabled crawlability spec and Lighthouse gates
  (perf ≥ 0.8, a11y ≥ 0.9, best-practices ≥ 0.9, seo ≥ 0.95).

## References

- Proposal 092 (design, phases, owner decisions) · `docs/operations/deployment/nvoos-astro-velocity-setup.md`
- `examples/nvoos-astro-velocity/README.md` (architecture, env, commands)
- `addons/funiq-bridge/README.md` (REST surface, data map)
- Related: Proposal 085 + appendix (contract invariant, Strangler Fig),
  Proposal 090 (Laravel backend flip — the storefront survives it unchanged).
