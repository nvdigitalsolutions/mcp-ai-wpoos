---
name: mcp-ai-wpoos-astro-storefront
description: Operational guide for the NV-authored headless storefront (Proposal 092) — the nvoos-astro-bridge Payload-shaped commerce contract (`nvoos-commerce/v1`), the `examples/nvoos-astro-velocity` Astro app, its hermetic dev/test loop (fixture server, vitest, Playwright, Lighthouse CI), the contract drift guard, the rebuild webhook, the sync workflow + public mirror deployment, the ADS design system (DTCG tokens, generator + drift gate, dark/light theming, Proposal 095), and the `nvoos-astro-mcp` assistant-control surface at `/mcp` (dual-era Streamable HTTP, design tool slice, fail-closed write auth). Use when working on the storefront, the bridge contract, image optimization, SEO/crawlability, design tokens/themes, the MCP mount, or the mirror deploy loop.
---

# NV oOS Astro Storefront — ops guide (Proposal 092)

## What this is

The public, NV-authored headless storefront: an Astro 7 app consuming a
Payload-shaped commerce envelope over same-origin `/api/*`, replacing the
licensed Funiq PWA (owner decision Q1: keep A/B until parity review, then
retire). Full context: `docs/project/proposals/092-astro-storefront-layer-proposal.md`.

| Piece | Location | Role |
|---|---|---|
| Bridge addon | `addons/nvoos-astro-bridge/` | Serves the Payload envelope from WordPress + WooCommerce data (transitional backend; Laravel takes over at T1 per Proposals 085/090) |
| Storefront source | `examples/nvoos-astro-velocity/` | Astro SSR + prerendered catalog, React islands (cart badge, gallery), View Transitions |
| Sync workflow | `.github/workflows/sync-nvoos-astro-velocity.yml` | Builds + tests, then rebuilds for the mirror |
| Public mirror | `nvdigitalsolutions/nvoos-astro-velocity` (PUBLIC) | Assembled deploy tree (Astro `dist/` + server shim) — zero licensed bytes |

## Contract rules (CI-enforced — never weaken)

- **Licensing split (owner decision Q8, drafted 2026-10-31):** the app is
  MIT; `addons/nvoos-astro-bridge/src/` (the admin SPA) is MIT via its own
  `src/LICENSE` carve-out; the bridge PHP plugin remains proprietary.
  `public/manage-assets/` ships in the public mirror, so never build it
  from non-MIT bridge source. The app's `NOTICE` records the bundled
  GPL-2.0-or-later `@wordpress/*` components; `sync-dist.mjs` ships
  `NOTICE` to the mirror.
- The Astro app binds **only** to the Payload envelope (`nvoos-commerce/v1`
  REST namespace alias). Never call WP-specific endpoints from `src/`.
- The word **"funiq" is banned from `examples/nvoos-astro-velocity/src/`**
  (CI grep gate). The bridge addon and docs may still carry it in data-layer
  identifiers (`funiq_product` CPTs, `funiq/v1` legacy namespace, `_funiq_*`
  meta — the deferred data-migration project); the app may not. The
  standalone admin bundle is built output in `public/` and contains zero
  brand strings anyway.
- Golden fixtures are the contract test: `examples/nvoos-astro-velocity/tests/fixtures/products.json`
  must stay semantically identical to the bridge transformer output pinned by
  `addons/nvoos-astro-bridge/tests/phpunit/Transformers/TestTransformerGolden.php`.

## WooCommerce integration (2026-10-29)

- The bridge has two product backends behind one envelope: **`native`**
  (CMS Basic — the built-in `funiq_product` CPTs, the default) and
  **`woo`** (WooCommerce `product`), plus **`both`** (merged). Mode via
  option `funiq_bridge_products_source` / filter
  `funiq_bridge_products_source`; `woo`/`both` degrade to `native` when
  WooCommerce is missing. Product docs carry an additive `source` field
  (`native`|`woocommerce`).
- **Source layer:** `addons/nvoos-astro-bridge/includes/Sources/` —
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
cd ../../addons/nvoos-astro-bridge && npm install && cd -   # standalone CMS bundle deps
NVOOS_API_BASE=http://localhost:8123/wp-json/nvoos-commerce/v1 npm run build   # prerenders catalog + /manage bundle
NVOOS_TARGET_SITE=http://localhost:8123 PORT=4321 npm run serve                # BFF + app (/, /manage/, /api/*, /media/*, /mcp)
npm run check && npm run lint && npm run test          # astro check + tokens drift gate · eslint · vitest (101 tests / 15 files)
npx playwright install chromium && npm run test:e2e    # 2 specs — JS-disabled SEO + islands
npm run lhci:ci                                        # CHROME_PATH → Playwright's Chromium
```

- **Windows gotcha:** `astro check` OOMs at the default 4 GB heap on this
  machine — run `NODE_OPTIONS=--max-old-space-size=8192 npm run check` (and
  the same for `npm run build`, which chains `astro check` first).
- The fixture server returns **401 on `/auth/me` without a cookie** and serves
  a real 1×1 PNG on `/wp-content/uploads/` — keep both behaviours.
- PHP side (bridge): `php -l` + phpcs, then PHPUnit in Docker (`oos-wp`
  container, isolated DB `wordpress_test_astro`) — see the
  `mcp-ai-wpoos-test-suite` skill for the exact recipe. Run against WP 6.9
  **and** WP 7.1.

## Design system + assistant control (Proposal 095, PR #7301)

### ADS tokens — architecture

- **Sources** (`src/tokens/`, DTCG 2025.10 JSON): `core.json` →
  `semantic.json` → `component.json` + `themes/{default,example-brand}.json`
  (light/dark mode blocks that override the semantic layer). Cross-file refs
  (`{core.palette.ink}`) are a documented ADS extension of DTCG.
- **Generated artifacts are COMMITTED and drift-gated:** `npm run
  build:tokens` regenerates `src/styles/generated/tokens.css`,
  `src/lib/tokens.generated.ts`, `src/tokens/bundle.generated.json`;
  `npm run check:tokens` (chained into `npm run check`) fails CI when the
  source JSON changed without regeneration.
- **Zero-visual-regression rollout:** the app binds `--ads-*` vars; legacy
  `--nv-*` names are aliases over the semantic layer in `global.css`.
  Theme contract: `[data-theme="light"|"dark"]`, `prefers-color-scheme`
  default, `ThemeToggle` island, `/tokens` reference page.
- **Runtime:** `src/lib/tokens/service.ts` `TokenService` — always read the
  **`_resolved`** bundle tree (raw groups keep unresolved `{core.x}` refs),
  `resolveActiveSemantic()`, `resolvedGroups()`, theme CRUD + rollback
  snapshots, WCAG `contrastCheck`.

### Token-pipeline gotchas (each cost a fix)

- `scripts/lib/tokens-build.mjs` `TOKENS_DIR` resolves relative to
  `scripts/` — the correct depth is `join(HERE, '..', '..', 'src', 'tokens')`
  (one `..` silently writes artifacts to the wrong tree).
- **`$comment` keys leak** as theme names / resolution targets — filter
  `mode.startsWith('$')` everywhere (theme names, brand-token skips).
- **`$value` arrays (`fontFamily`)** broke reference resolution when
  stringified with `join(' ')` — both validators must keep single-ref
  values verbatim (string OR array).
- Tests: `tests/unit/{dtcg,tokens,tokens-service}.test.ts` +
  `tests/e2e/theme.spec.ts` (Playwright).
- **Generated TS is linted** — `src/lib/tokens.generated.ts` sits under
  `src/`, so `npm run lint` covers it. The generator
  (`scripts/lib/tokens-build.mjs`) must emit repo style — tabs (one per
  level) and single-quoted strings via the `tsString()` helper, never raw
  `JSON.stringify` — or the lint gate fails (~149 errors; PR #7301 → #7302).
  `tests/unit/tokens.test.ts` pins the format (tabs + single quotes) so the
  regression cannot recur silently.

### nvoos-astro-mcp (`/mcp`)

- `mcp/` (`auth.ts`, `bff.ts`, `context.ts`, `ratelimit.ts`, `server.ts`,
  `stdio.ts`, `tools/{design,cms,ops,util}.ts`); the shared bundler
  `scripts/lib/mcp-build.mjs` writes `dist/mcp-entry.mjs` (which
  `scripts/serve.mjs` mounts at `/mcp`, deliberately NOT under `/api/*`).
  Two call sites: `npm run build:mcp` (dev/manual — `scripts/build-mcp.mjs`
  CLI wrapper) and the `nvoos-astro-mcp-build` Astro integration in
  `astro.config.mjs`, whose `astro:build:done` hook rebuilds the bundle
  after every `astro build` (astro wipes `dist/`, so a pre-build step in the
  npm chain would be erased — the 2026-10-30 /mcp outage).
  `npm run mcp:stdio` serves the same tools over stdio for local profiles.
- **Never spawn `node` against `node_modules/esbuild/bin/esbuild`
  (2026-10-30):** esbuild's install fallback can replace that file with the
  native platform binary — an ELF executable on Linux — and node dies with
  `SyntaxError: Invalid or unexpected token` on the ELF header. This broke
  the Linux CI `npm run build` at `build:mcp` (PR #7303). Use the esbuild JS
  API (`import { build } from 'esbuild'`) — it resolves the platform binary
  itself; `scripts/build-mcp.mjs` was rewritten this way.
- **21 tools** (`SERVER_VERSION` 0.2.0): design (10 — `tokens_get/set/import_dtcg/rollback`,
  `theme_*`, `contrast_check`; bulk writes transactional: validate → dry-run
  diff → snapshot → all-or-nothing), CMS (6 — `cms_list/get/create/update/delete`,
  `media_upload` over `commerce/client.ts`, collection allowlist, `limit` ≤ 100),
  ops (5 — `site_status`, `preview_url`, `rebuild_trigger`, `screenshot`,
  `nds_export` — the NDS DTCG document + mapping report for the 4.2 round-trip).
  Every mutating design write (`tokens_set`, `tokens_import_dtcg`,
  `theme_create`, `theme_apply`, `theme_delete`) echoes the pre-write
  `snapshot_id` it captured, so `tokens_rollback` can undo exactly that write
  (not just the latest snapshot).
  Tool handlers read the request cookie/env through `mcp/context.ts`
  (AsyncLocalStorage set in `bff.ts`).
- **Tool annotations + titles (2026-10-31):** every tool carries a `title`
  and spec `annotations` (`readOnlyHint`/`destructiveHint`/`idempotentHint`/
  `openWorldHint` — presets in `mcp/tools/util.ts`: `READ_ONLY_TOOL`,
  `WRITE_TOOL`, `IDEMPOTENT_WRITE_TOOL`, `DESTRUCTIVE_WRITE_TOOL`;
  `openWorldHint` stays false everywhere — no tool mutates external state).
  Pinned by `tests/mcp/handshake.test.ts`. `preview_url` / `screenshot`
  resolve their origin as `NVOOS_STOREFRONT_ORIGIN` → inbound request Host
  (scheme from `X-Forwarded-Proto`, then socket TLS) → `http://localhost:4321`;
  `requestOrigin()` lives in `mcp/bff.ts`, tool-side resolution in
  `storefrontOrigin()` (`mcp/tools/ops.ts`), pinned by
  `tests/unit/storefront-origin.test.ts` + the mcp-tools preview tests.
- **Auth is fail-closed, split by surface:** reads open; design writes accept
  bearer `NVOOS_MCP_TOKEN` (timing-safe) OR a manager session (`canManage`
  via `/auth/me`); **contract writes (`cms_*` writes, `media_upload`) require
  the manager cookie** (backend enforces `manage_astro` anyway — fail fast
  with a useful error). Same-origin browser requests (Origin host == the
  Host-validated request host) bypass the Origin allowlist — that is what
  the /manage Design tab rides on. **Zero-cookie coding-agent recipe:** the
  published bridge package (`npx -y @nvdigitalsolutions/nvoos-mcp-bridge@latest`,
  0.1.0-alpha.5+) accepts `MCP_AI_LOGIN_URL` + `MCP_AI_LOGIN_EMAIL`/
  `_PASSWORD` (or a raw `MCP_AI_LOGIN_BODY` override) and bootstraps the
  manager session itself — POSTs `{ email, password }` to `/api/auth/login`
  at startup, attaches the captured cookies to every upstream request, and
  re-establishes the session once on 401/403 or near expiry. `MCP_AI_COOKIE`
  is the static-cookie escape hatch. Canonical source: `bin/mcp-bridge.js`
  (byte-synced into `packages/nvoos-mcp-bridge`; tests in
  `packages/nvoos-mcp-bridge/test/session.test.js`). The relay advertises
  both Streamable HTTP media types and unwraps SSE-framed responses — the
  astro `/mcp` answers every request in SSE when the client accepts it
  (verified live 2026-10-31: handshake + `tools/list` + `cms_create`/`cms_delete`
  roundtrip against astro.nvoos.cloud). Per-IP rate limit (429 `-32029`,
  `NVOOS_MCP_RATE_MAX`/`_WINDOW_MS`, default 120/min), Host/Origin allowlists
  (`NVOOS_MCP_HOSTS` **must** include the public hostname — default is
  localhost-only), audit line per write call. `screenshot` is opt-in
  (`NVOOS_MCP_SCREENSHOT=1`) and path-only input (SSRF-safe by construction,
  per MCP Security Best Practices).
- **Env snapshot discipline:** `createAstroMcpNodeHandler` snapshots its env,
  and `serve.mjs` passes a pre-rewrite snapshot — serve rewrites
  `NVOOS_SSR_API_ORIGIN` to the loopback BFF origin for the SSR commerce
  client, and without the snapshot the manager-session check pointed at the
  BFF itself (every manager write 401; incident 2026-10-30, pinned by a
  handshake regression test). Boot paths that only set `NVOOS_TARGET_SITE`
  (the CI smoke step boots the BFF before Playwright, which **reuses** it,
  so the `playwright.config.ts` env never applies; the Velocity activation
  notes likewise) get the contract base seeded by `seedMcpEnv`
  (`scripts/lib/mcp-env.mjs`) — without it every manager write 401s and
  `cms_*` reports `no_commerce_base` (incident 2026-10-31, pinned by the
  rebrand-loop e2e + `tests/unit/mcp-env.test.ts`). Explicit
  `NVOOS_SSR_API_ORIGIN` / `NVOOS_API_BASE` always win.
- **Durability:** token/theme runtime state persists across restarts when
  `NVOOS_MCP_STATE_FILE` is set (atomic tmp+rename writes,
  `src/lib/tokens/persistence.ts`); otherwise process-local.
- **The /manage Design System tab** (`addons/nvoos-astro-bridge`:
  `DesignView.tsx` + `src/lib/mcp-client.ts`) is itself an MCP client — it
  posts to the same-origin `/mcp` with the manager cookie (no extra REST
  surface). Ships via `npm run build:standalone` → `scripts/sync-manage-assets.mjs`
  → committed `public/manage-assets/` (two-bundle drift gate).
- **NDS round-trip (4.2):** `nds_export` (app) emits the NDS DTCG shape;
  `addons/nvoos-design-system` gained `class-nvoos-nds-dtcg-importer.php`
  (static `prepare()` + all-or-nothing `import()`, 256 KB cap, rejects
  `{core.x}` references) + an admin import surface. Mapping table lives in
  `mcp/tools/ops.ts` (`ADS_TO_NDS`; accent-ink/danger/focus-ring reported
  unmapped — NDS has no equivalents).
- **Dual-era wire serving** (modern 2026-07-28 + 2025 stateless fallback) —
  all wire shapes, the validation ladder, and the `registerTool` factory
  gotcha live in the `mcp-ai-sdk-v2-server-ops` skill; conformance tests in
  `tests/mcp/handshake.test.ts` cover both eras + the write-auth gate.
- The hermetic assistant loop lives in `tests/mcp/rebrand-loop.test.ts`
  (fixture server on :8131: tokens_get → dry-run → apply → contrast →
  rollback → cms_list); the browser loop + the Design tab live in
  `tests/e2e/rebrand-loop.spec.ts` (fixture manager seam:
  `nvoos_fixture_manager=1` cookie grants `canManage`);
  `tests/unit/contrast-audit.test.ts` is the WCAG 2.2 gate (SC 1.4.3 4.5:1 /
  SC 1.4.11 3:1 per pair, with coverage meta-tests; `field.border` now rides
  the additive `line-strong` token).

### Known TODOs (annotated `TODO(095-*)` in code)

- ~~`astro build` empties `dist/` AFTER `build:mcp`, erasing
  `dist/mcp-entry.mjs` from production builds~~ — fixed 2026-10-30 via the
  `nvoos-astro-mcp-build` integration (`astro:build:done` →
  `scripts/lib/mcp-build.mjs`); the npm `build` chain no longer runs
  `build:mcp` pre-build.
- ~~Phase 1 contrast audit; Phase 3 CMS/ops slices + screenshot; Phase 4
  durability~~ — all closed 2026-10-30 (gap-closure cluster). Also closed in
  the follow-up cluster: the /manage Design tab (4.1), the NDS importer +
  `nds_export` round-trip (4.2), the `field.border` SC 1.4.11 fix
  (`line-strong`), and the browser rebrand e2e. **Remaining:** the 3.4 NV
  oOS connection recipe needs live-install verification (documented in the
  app README + this skill); optionally the NDS WP 7.1 WooCommerce-stub
  skip-gate fix (pre-existing, unrelated).
- Cross-surface sharing of these tokens (NDS addon + Pro SPA v2 palettes) is
  scoped in Proposal 097.

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
- **RebuildWebhook** (`addons/nvoos-astro-bridge/includes/RebuildWebhook.php`):
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
  (`npm run build:standalone` in `addons/nvoos-astro-bridge/`, output
  `build/standalone/astro-admin.{js,css}`; bundles `@wordpress/*` + React
  instead of the wp-admin externals) and copied by
  `scripts/sync-manage-assets.mjs` (wired into `npm run build` +
  `build-hermetic.mjs`). Manager login never touches a proxied login page:
  `POST /api/auth/login` sets the auth cookie, the BFF strips `Domain=`
  (host-only), and `/api/auth/me`'s `canManage` gates everything. The sync
  workflow installs bridge deps (`npm ci` in `addons/nvoos-astro-bridge/`) before
  the app build and re-runs on bridge path changes.
- **Standalone build — jsx-runtime (learned 2026-10-29):**
  `@wordpress/element` ships NO `./jsx-runtime` export — a standalone
  esbuild build with `jsxImportSource: '@wordpress/element'` fails with
  `Could not resolve "@wordpress/element/jsx-runtime"`. The standalone
  target sets `jsxImportSource: 'react'` (React is bundled anyway); the
  wp-admin build keeps the element shim (window.React.createElement) that
  piggybacks WP's React. Also: output naming comes from an object entryPoint
  (`{ 'astro-admin': 'src/standalone.tsx' }`), not `entryNames`.
- **`/manage` login triage:** a `401` on `/api/auth/me` in the network
  panel BEFORE sign-in is the expected auth probe (the gate then renders
  the form). A successful login landing on "does not have permission to
  manage this store" = `canManage:false` = the `manage_astro` cap grant is
  missing on the backend (the one-shot migration didn't run — the fleet
  late-load bug fixed by PR #7296). Remediate: `wp cap add administrator
  manage_astro` on the backend or re-activate the bridge plugin; never
  chase the frontend for this error.
- **REST cookie-auth on single-site HTTPS (the 2026-10-30 outage):** no
  cookie authenticates /wp-json before `rest_cookie_check_errors()` runs —
  the secure auth cookie is path-scoped to /wp-admin, and whenever the
  logged-in cookie DOES validate (via `determine_current_user`),
  `auth_cookie_valid` flips `$wp_rest_auth_cookie`, which makes
  `rest_cookie_check_errors()` skip its early return and force nonce-less
  requests back to user 0. Symptom: login 200 with `canManage:true`, then
  every follow-up 401s — while the same session works in wp-admin and the
  whole flow works in tests (HTTP, `is_ssl()` false). Fix (shipped): the
  bridge's permission callbacks re-resolve the session from the logged-in
  cookie right before the cap check (`REST/AuthSupport.php` — the console's
  per-endpoint pattern), plus a scoped `determine_current_user` filter.
  Nonces minted via `/api/auth/nonce` stay valid: `wp_create_nonce`/
  `wp_verify_nonce` derive the session token from the logged-in cookie
  directly, so uid 0 on both sides of the round-trip still verifies.
- **Deploy-zip separators (2026-10-30):** a PowerShell `Compress-Archive`
  zip carries backslash entry separators; on the backend's Linux PHP the
  upgrader then fails to see the single top-level folder and installs the
  zip into a random-suffix container (`nvoos-astro-bridge-v1.0.1-<rand>/
  nvoos-astro-bridge/...`). Build the zip with Python `zipfile` (forward
  slashes, top-level folder) — verified to install flat. And NEVER delete
  the bridge plugin via wp-admin: its `uninstall.php` wipes the live
  catalog + caps. Updates go through the upload flow's
  "Replace current with uploaded" step (no uninstall runs).
- **Bridge media endpoint:** image fields use a native uploader against
  `POST /media` (multipart, manage-cap gated; `GET /media/{id}` for
  previews) — `wp_handle_upload` with a filterable action
  (`funiq_bridge_media_upload_action`, default `wp_handle_upload` keeps the
  `is_uploaded_file` gate; tests switch it to `wp_handle_sideload` since CLI
  cannot produce real uploads). WP 6.9 note: `wp_handle_upload()` takes the
  action from `$overrides['action']` — there is NO 4th `$action` argument
  (passing one silently ignored it; verified against core source).
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
- **no-FOUC boot semantics:** `BaseLayout.astro`'s inline theme script
  resolves stored theme → `prefers-color-scheme` (`? 'light' : 'dark'`, the
  Starlight pattern) before paint. Editing that block for lint
  (`var`→`const`, `catch (e)`→`catch`) must keep the ternary meaningful —
  the #7301 merge shipped `? 'light' : 'light'`, silently killing the dark
  fallback (fixed in #7302).

## Deploy & revalidation

- `npm run sync:dist` assembles the deploy tree; the workflow has **two
  builds**: localhost-origin (smoke/e2e/LHCI) and prod-host rebuild (mirror).
- Rebuild trigger: bridge fires a debounced `nvoos-storefront-content-changed`
  → GitHub `repository_dispatch` → the sync workflow.
- Mirror repo is public (owner Q6). A/B coexistence rules and rollback:
  `docs/operations/deployment/nvoos-astro-velocity-setup.md`.
- **Bridge packaging for zip upload (learned 2026-10-29, revised
  2026-10-30):** the deploy zip must contain the plugin as a **top-level
  `nvoos-astro-bridge/` folder** (main file `nvoos-astro-bridge.php`
  inside). Build it with **Python `zipfile` (forward-slash separators)** —
  PowerShell `Compress-Archive` writes backslash entries that the backend's
  upgrader can't see as folders (random-suffix container install), and
  `tar -a` produces a broken zip. Zipping `folder\*` (files at zip root)
  installs the main file directly into `wp-content/plugins/`, colliding
  with other plugins' `includes/`. Re-uploading an existing folder goes
  through the "Replace current with uploaded" step — never delete the
  plugin via wp-admin, its uninstall.php wipes the live catalog + caps.
- **Bridge code standards:** the addon carries its own
  `addons/nvoos-astro-bridge/phpcs.xml.dist` (PSR-4 filename sniffs, short
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

- Proposal 092 (design, phases, owner decisions) · Proposal 095 (ADS + nvoos-astro-mcp, PR #7301) · Proposal 097 (cross-surface token sharing) · `docs/operations/deployment/nvoos-astro-velocity-setup.md`
- `examples/nvoos-astro-velocity/README.md` (architecture, env, commands)
- `addons/nvoos-astro-bridge/README.md` (REST surface, data map)
- Related skills: `mcp-ai-sdk-v2-server-ops` (dual-era wire serving), `mcp-ai-wpoos-git-ops`
- Related: Proposal 085 + appendix (contract invariant, Strangler Fig),
  Proposal 090 (Laravel backend flip — the storefront survives it unchanged).
