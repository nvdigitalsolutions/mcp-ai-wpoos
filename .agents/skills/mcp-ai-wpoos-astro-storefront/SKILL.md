---
name: mcp-ai-wpoos-astro-storefront
description: Operational guide for the NV-authored headless storefront (Proposal 092) — the nvoos-astro-bridge Payload-shaped commerce contract (`nvoos-commerce/v1`), the `examples/nvoos-astro-velocity` Astro app, its hermetic dev/test loop (fixture server, vitest, Playwright, Lighthouse CI), the sync workflow + mirror deployment, the ADS design system (DTCG tokens, drift gate, dark/light theming), and the `nvoos-astro-mcp` assistant-control surface at `/mcp` (dual-era Streamable HTTP, fail-closed write auth). Use when working on the storefront, the bridge contract, image optimization, design tokens/themes, the MCP mount, or the mirror deploy loop.
---

# NV oOS Astro Storefront — ops guide (Proposal 092)

## What this is

The public, NV-authored headless storefront: an Astro 7 app consuming a
Payload-shaped commerce envelope over same-origin `/api/*`, replacing the
licensed Funiq PWA (owner decision Q1: keep A/B until parity review, then
retire). Full context: `docs/project/proposals/092-astro-storefront-layer-proposal.md`.

| Piece | Location | Role |
|---|---|---|
| Bridge addon | `addons/nvoos-astro-bridge/` | Serves the Payload envelope from WordPress + WooCommerce data (transitional backend; Laravel takes over at T1 per Proposals 085/090). Display name: **NV oOS Astro Bridge** (renamed from "Astro Bridge" 2026-11-03; slug/version unchanged) |
| Storefront source | `examples/nvoos-astro-velocity/` | Astro SSR + prerendered catalog, React islands (cart badge, gallery), View Transitions |
| Sync workflow | `.github/workflows/sync-nvoos-astro-velocity.yml` | Builds + tests, then rebuilds for the mirror |
| Public mirror | `nvdigitalsolutions/nvoos-astro-velocity` (PUBLIC) | Assembled deploy tree (Astro `dist/` + server shim) — zero licensed bytes |
| Production backend | `nvoos.pro` (WP + WooCommerce, bridge addon live at `/wp-json/nvoos-commerce/v1`) | The app's `/api/*` proxy target — `/api/auth/login` lands here and `canManage` users resolve to `https://nvoos.pro/wp-admin/admin.php?page=astro-cms` |

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
- **Draft support (PR #7331):** create/update accept
  `status: 'draft' | 'publish'` (default publish —
  `NativeProductSource::normalize_status()`); listings accept `status=any` to
  include drafts. Internal/test products ride this via the MCP `cms_*` tools.
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

- **CI's "Build test artifact" step runs `astro check` as a GATE** — the
  sync workflow fails there before any deploy. Validate the same way
  locally: capture the exit code unmasked (`cmd > log 2>&1; echo exit=$?`),
  never through a `grep`/`head` pipe — a masked 2026-11-04 run shipped
  three `className` type errors in an `.astro` template and the CI gate
  caught them on the next mirror build. A bare `npx astro build` does NOT
  type-check; `npm run build` (which chains `astro check`) and CI both do.

- **Windows gotcha:** `astro check` OOMs at the default 4 GB heap on this
  machine — run `NODE_OPTIONS=--max-old-space-size=8192 npm run check` (and
  the same for `npm run build`, which chains `astro check` first).
- **Windows vitest fork-pool hang (2026-11-03):** the default forks pool
  times out starting workers for EVERY test file (pre-existing ones
  included — `[vitest-pool]: Failed to start forks worker`). Run
  `npx vitest run --pool=threads`. The bridge SPA's own suite
  (`addons/nvoos-astro-bridge`, `npm run test`) fails the same way — the
  same flag fixes it.
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
- **33 tools** (`SERVER_VERSION` 0.3.0): design (14 — `tokens_get/set/import_dtcg/rollback`,
  `theme_*`, `contrast_check`, `design_analyze/compare/plan/theme`; bulk writes transactional: validate → dry-run
  diff → snapshot → all-or-nothing), CMS (6 — `cms_list/get/create/update/delete`,
  `media_upload` over `commerce/client.ts`, collection allowlist, `limit` ≤ 100),
  content (8 — `content_list/get/create/update/delete/preview_url/restore/validate`
  over `content/pages` + `content/posts`, Proposal 107; writes manager-gated,
  draft-default creates, publish gated on the backend `publish_posts` cap,
  per-session write quota, post-write route-cache purge — see the
  "MCP scope — 33 tools" section below), ops (5 — `site_status`, `preview_url`,
  `rebuild_trigger`, `screenshot`,
  `nds_export` — the NDS DTCG document + mapping report for the 4.2 round-trip).
  The design-import tools (Proposal 098) fetch a source URL (Astro theme repo,
  WordPress site, or any website) and extract its design language — palette
  roles, typography, spacing, component vocabulary, concept prose, and an
  inspired-by adaptation brief — via `src/lib/design-import/` (SSRF-safe
  fetcher + deterministic analyzer + WP theme.json/GitHub-tree source modes +
  re-extraction diff + adaptation plan + theme emission with a WCAG audit).
  Never a clone: no source CSS/markup/copy or assets are copied; only token
  data + prose. The open-world tools (`openWorldHint: true`, handshake-pinned):
  `design_analyze`, `design_compare`, `design_plan`, `design_theme`;
  `design_theme` is write-gated (bearer or manager session).
  Every mutating design write (`tokens_set`, `tokens_import_dtcg`,
  `theme_create`, `theme_apply`, `theme_delete`) echoes the pre-write
  `snapshot_id` it captured, so `tokens_rollback` can undo exactly that write
  (not just the latest snapshot).
  Tool handlers read the request cookie/env through `mcp/context.ts`
  (AsyncLocalStorage set in `bff.ts`).
- **Tool annotations + titles (2026-10-31):** every tool carries a `title`
  and spec `annotations` (`readOnlyHint`/`destructiveHint`/`idempotentHint`/
  `openWorldHint` — presets in `mcp/tools/util.ts`: `READ_ONLY_TOOL`,
  `WRITE_TOOL`, `IDEMPOTENT_WRITE_TOOL`, `DESTRUCTIVE_WRITE_TOOL`,
  `OPEN_WORLD_READ_TOOL`; `openWorldHint` stays false everywhere EXCEPT
  `design_analyze`, which fetches external URLs and declares it).
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
  per MCP Security Best Practices). `design_analyze` target URLs go through
  the `src/lib/design-import/fetcher.ts` SSRF guard (scheme allowlist, no
  credential URLs, DNS-resolved private/reserved rejection per redirect hop,
  caps + timeouts); private hosts (e.g. a local dev site) require
  `NVOOS_MCP_FETCH_ALLOW_PRIVATE=1` — never set it on the public mount.
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
- **Never grep over the minified bundles (2026-11-03):**
  `addons/nvoos-astro-bridge/build/standalone/astro-admin.js` and the app's
  `public/manage-assets/astro-admin.js` are multi-MB single-line esbuild
  output — a bare grep over the addon or app directory matches the whole
  file and floods the context window. Always scope with an include pattern
  (`addons/nvoos-astro-bridge/src/**`) when hunting SPA code.
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
- **The wp-admin page serves the STANDALONE bundle (bridge 1.1.0, learned
  2026-11-03):** `AdminPage` enqueues `build/standalone/astro-admin.{js,css}`,
  NOT the externals-based `build/index.js`. Under WP 7.1.4 the externals +
  JSX-shim pipeline committed an inconsistent tree (products table rows were
  text-only, zero `<td>` elements — fiber tree and DOM disagreed) while the
  identical standalone bundle rendered perfectly when injected into the same
  page. The externals target stays in `esbuild.config.cjs` but nothing
  enqueues it. Two follow-ups of the same incident: the old enqueue pointed
  at a non-existent `build/index.css` (the SPA ran unstyled in wp-admin), and
  `standalone.css` now bleeds the shell edge-to-edge
  (`body.toplevel_page_astro-cms #wpcontent { padding-left: 0 }`).
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
- **Bridge npm publish track (`packages/nvoos-mcp-bridge`, learned
  2026-10-31):** published via `.github/workflows/npm-publish-nvoos-mcp-bridge.yml`
  (dispatch: version + `alpha`/`latest` tag + dry-run; gates = byte-sync
  drift, node:test suite, pack dry-run, then `npm publish --provenance`).
  - **Version forensics first:** `npm view @nvdigitalsolutions/nvoos-mcp-bridge time`
    before bumping — the 23-package wave published CI-side without
    committing, so the repo's `package.json` can lag the registry
    (`0.1.0-alpha.4` was taken while the repo still said alpha.3).
  - **Auth ladder:** npm `E404` on PUT = token lacks write permission on
    the scope (rotate the `NPM_TOKEN` repo secret — it 404s rather than
    403s to hide package existence); `E403 "cannot publish over the
    previously published versions"` = version collision, auth is fine;
    `EOTP` = classic/publish token on an auth-and-writes account — an
    **Automation** token bypasses OTP, otherwise publish with `--otp`.
  - **Local pack quirks (Windows):** `npm --prefix <dir> pack` hangs when
    invoked from inside the repo (workspace root wins over prefix) — use
    the folder-spec form `npm pack <abs-dir> --dry-run`. `git archive`
    cannot extract the package either (repo `.gitattributes` export-ignores
    `package.json` and `bin/` at every level) — copy from a worktree
    checkout instead. Local publish: `npm publish <abs-dir> --access public
    --tag latest` (skips CI-only provenance).

## Standards this app is built to (research-backed)

- **On-demand SSR for commerce pages + route caching** (Proposal 104 §3.2 —
  supersedes 092's build-time PDP prerender): Hydrogen's own guidance is
  "default to SSR for commerce pages"; the Astro 7 route cache
  (`memoryCache` provider + `Astro.cache.set` tags + config `routeRules`)
  is the ISR-equivalent, and the mirror build is now fully hermetic
  (`npm run build:prod-sim` — no `NVOOS_API_BASE`).
- **Astro Image** for automatic WebP/AVIF + lazy loading via the runtime
  endpoint (above).
- **SEO**: canonical single product URL, real 301s for slug drift, `Product`
  JSON-LD in initial HTML with SKU-aligned IDs, `/sitemap.xml`, robots,
  cache headers + revalidation — verified by the JS-disabled crawlability
  spec, the axe-core e2e gate (D6) and Lighthouse gates (perf ≥ 0.8,
  a11y ≥ 0.9, best-practices ≥ 0.9, seo ≥ 0.95).
- **Theme runtime (Proposal 104)**: `src/lib/theme/` is the client theme
  module — localStorage is truth, application is idempotent, and the boot
  **re-applies on `astro:after-swap`** (head scripts run once; the
  ClientRouter swaps the whole `<html>` element, wiping `data-theme`).
  Bundled component scripts are NOT re-executed on SPA navigation — Theme/
  ContrastToggle use document-level delegation + after-swap re-sync.
  `color-scheme` is applied alongside `data-theme` (browser UI parity); a
  high-contrast axis (`data-contrast="high"`) rides the existing
  `line-strong` token.
- **BFF security headers (D12)**: CSP per response with SHA-256 hashes of
  every inline script (no blanket `unsafe-inline`; inline `on*` handlers
  are forbidden in src — see the category PLP's delegated auto-submit).
- **Approved owner items (2026-11-02)**: Q3 — `POST /internal/revalidate/`
  (bearer `NVOOS_REVALIDATE_TOKEN`, tag-based purge of the in-process route
  cache; trailing slash is canonical, unset token = 503). Q5 —
  `GET /og/product/{id}.png` branded OG cards (satori + resvg + Inter via
  Fontsource, ADS tokens, day-long cache; render failures 302 to the
  product image); PDPs point `og:image` at it. Fixture server appends a
  synthetic draft (id 9901) at boot — goldens stay byte-identical.

## Proposals 105 + 106 surfaces (2026-11-03)

- **Bridge theme policy (105)**: `GET/PATCH /globals/theme` —
  `{ policy: auto|light|dark|brand:<slug>, contrast: auto|standard|high,
  defaultTheme: light|dark, updatedAt, $extensions }`; PATCH manage-gated,
  `s-maxage=60`, fires the rebuild webhook. Storefront ladder (one module,
  `src/lib/theme/policy.ts`): policy → stored pref → system → defaultTheme;
  forced modes disable the toggles (aria-disabled + note). Prerendered pages
  bake the policy (`scripts/build-policy.mjs` → `policy.generated.ts`,
  drift-gated by `check-policy`); SSR reads per request (60 s TTL,
  `NVOOS_THEME_POLICY_TTL=0` to disable) and cache entries carry
  `etag: policy.updatedAt`. Unknown brand slugs fail open to `auto`. MCP
  `theme_apply` is preview-only under a forced policy (`NVOOS_THEME_PREVIEW=1`).
  The forge template mirrors the ladder (canonical copy in the forge
  template — keep logic identical; documented divergences: velocity adds
  `contrastForced`/`storedContrast` and live-doc reads).
- **CMS content (106)**: `content/pages` + `content/posts` (PostTypeController
  seam; `contentRendered` = `the_content` output with raw fallback; Rank
  Math `rank_math_title`/`rank_math_description` → Yoast meta → title/excerpt
  fallback; `where[slug|category]`; drafts excluded — synthetic draft id
  9902). Routes `/pages/{id}/{slug}`, `/blog/`, `/blog/{id}/{slug}` (301
  canonicals, WebPage/CollectionPage/BlogPosting JSON-LD, `['content',…]`
  cache tags). **Hybrid local**: `src/content.config.ts` globs
  `src/content/posts|pages/*.md` (NOT mdx — the astro:jsx renderer probes
  React islands and logs per-render errors; forge has no islands so it uses
  mdx); `src/lib/content/merge.ts` — envelope wins on slug collision,
  local fills gaps (L2).
  **Write contract (107):** POST/PUT accept `title`, `content`
  (`wp_kses_post`-sanitized), `excerpt`, `status`
  (publish|draft|pending|future — create defaults to draft), `publishAt`
  (WP `future` scheduling; normalize the WP 6.9 `rest_parse_date()`
  timestamp), `blocks` (Portable Text stored as post meta, never rendered
  server-side), `seoTitle`/`seoDescription` (Rank Math → Yoast
  write-through), and `submittedBy`; posts also accept `category`
  (`{ id, name }` or bare id — WP core categories, NOT the commerce
  `categories` collection). Both types expose `GET /{id}/revisions` +
  `POST /{id}/restore`; the list's `?status=` filter serves only manager
  sessions (fail-closed publish-only).
- **Variants (106)**: additive `variants[]` on single-doc GETs only (woo mode;
  `WooProductSource` hydrates `WC_Product_Variable` children); listings flat.
  PDP `VariantSelector` island (radio semantics, sold-out disabled, price +
  JSON-LD `Offer[]` follow selection); cart lines carry `variantId` +
  `attributes`.
- **Checkout handoff (106) → embedded checkout (110)**: `GET/PATCH
  /globals/shop` (`checkoutUrl` from `home_url('/checkout/')`, display
  currency; `s-maxage=60`); `buildCheckoutUrl()` (pure) → comma-form
  `?add-to-cart=1,2` — the NATIVE-mode path and the fail-open fallback.
  In woo/both modes the cart syncs and continues to the EMBEDDED checkout
  (`/checkout/`) — see the "Embedded checkout (110)" section below.
- **Policy e2e isolation**: theme-policy specs run on a second fixture/BFF
  pair (8124/4322, `NVOOS_THEME_POLICY_TTL=0`) so PATCH→render is
  deterministic without racing the shared fixture's theme assertions.
- **Implementation gotchas (2026-11-03 session — each cost a fix):**
  - **Astro 7 `Astro.cache` has no entry-key args** — the memory-cache key
    is URL-based. The policy revision rides the cache entry's **`etag`**
    option (`Astro.cache.set({ ..., etag: policy.updatedAt })`); production
    invalidation stays the rebuild→redeploy loop.
  - **Playwright 1.64 has no per-project `webServer`** — isolated
    fixture/BFF pairs (the policy e2e) go in the **top-level** `webServer`
    array of `playwright.config.ts`, each with its own ports.
  - **Stray node processes on Windows survive shell exit** and poison
    `reuseExistingServer` (fixture/BFF/Playwright servers).
    `scripts/cleanup-stray-servers.mjs` kills them before test runs.
  - **Manage-assets cross-cluster sync**: when the bridge SPA changes,
    `npm run build` here rebuilds `public/manage-assets/astro-admin.js`
    from the bridge's standalone build — commit src + bundle **together**
    (the two-bundle drift gate fails the PR otherwise). An uncommitted
    bridge SPA change mid-session makes the local build overwrite the
    committed bundle — restore it until the bridge cluster commits.
  - `NVOOS_THEME_POLICY_TTL` (default 60 s, `0` disables) is the policy-TTL
    seam; mirrors the `NVOOS_PROXY_CACHE=0` precedent.
  - Pre-existing `Pagination.astro` bug fixed in this session: `??page=N`
    double question mark (`url.search` already carries `?`).

## Home page + MCP scope notes (2026-11-03)

- **The home page (`src/pages/index.astro`) is an explainer landing page +
  commerce pockets.** Hero + "What is this app?" cards + live stats + the
  stack explorer render first; Featured/Bestsellers (`ProductGrid`) only
  render when `hasCatalog` (`newest.totalDocs > 0`) — prod ran an empty
  catalog, so the explainer covers the empty state and the pockets appear
  automatically once products exist. `src/styles/home.css` is page-scoped
  CSS (imported in the frontmatter), token-driven via the `--nv-*` aliases.
- **Islands:** `HomeStats.tsx` (count-up counters; server HTML carries the
  real totals via `data-count` so no-JS visitors see them; animation gated
  on `prefers-reduced-motion`) and `StackExplorer.tsx` (WAI-ARIA tabs with
  arrow/Home/End keys; contract tab does ONE live `fetch('/api/products?limit=1')`
  on first activation; design tab reads `data-theme`/`data-contrast` from
  `<html>` and re-syncs on `astro:after-swap`). Both are `client:visible`
  with the `data-hydrated` marker; `tests/e2e/home.spec.ts` covers no-JS SEO,
  count-up, and tab keyboard + live reads (fixture counts: 8 products,
  3 categories, 2 brands, 3 published posts — draft 9902 excluded).
- **Homepage SSR fan-out:** the page makes six contract round-trips (banner,
  newest, top-rated, categories, brands, posts — `newest.totalDocs` doubles
  as the product count; don't add a 7th products fetch). Under full-suite
  parallel load the shared fixture can be slow before the first byte — the
  no-JS home spec gives the h1 assertion a 30 s budget.
- **React self-cancelling effect (cost a debugging session):** a
  `useEffect` whose deps include the state it sets (`contract.status`),
  with a `cancelled` cleanup flag, cancels the in-flight fetch the moment
  the `'loading'` update re-runs the effect — the panel spins forever.
  Trigger one-shot fetches from the activation dep alone and guard with a
  `useRef`, or use the updater-function form.
- **MCP scope — 33 tools (0.3.0, Proposal 107):** the `/mcp` surface writes
  commerce collections (`cms_*` allowlist: products, categories, brands,
  colors, media, promotions, promocodes), controls themes/tokens, imports
  design language, and runs ops. The **content slice** (`content_list/get/
  create/update/delete/preview_url/restore/validate`, Proposal 107) writes
  `content/pages` + `content/posts` through the bridge's approval ladder:
  creates default to **draft**, `publish`/`future`/unpublish require the
  backend `publish_posts` cap (`content_publish_forbidden` 403 otherwise),
  and successful writes purge the route-cache tags via `/internal/revalidate`
  when configured. `content_preview_url` mints a signed token that exchanges
  for a host-only `nvoos-preview` cookie at `/preview/{type}/{id}` and
  redirects to the canonical route — draft previews render for logged-in
  managers only (fail-closed 404), with a draft banner, `noindex` and no
  caching (the route invalidates its own cache entry first). Agent-authored
  docs render their Portable Text `blocks` through `src/components/blocks/`;
  raw-HTML `content` is sanitized at BOTH gates (bridge `wp_kses_post` on
  write, `sanitize-html` on render). There are still **no chart/dashboard
  tools** in the app; structural pages (home, layout, routes) remain
  `.astro`/island source edits shipped via the mirror workflow. On prod
  mounts, `screenshot` and `rebuild_trigger` are opt-in/disabled (check
  `site_status` first). Env: `NVOOS_PREVIEW_SECRET`/`NVOOS_PREVIEW_TTL_MS`
  (preview tokens), `NVOOS_MCP_CONTENT_WRITE_MAX`/`_WINDOW_MS` (write
  quota, default 30/10 min).
- **/manage content workbench (2026-11-03, 107 Phase 2 — the editor
  experience):** the Astro CMS admin SPA now surfaces the governed
  collections itself — a **Content** sidebar section (Pages, Posts) over
  `content/pages` + `content/posts`, list views with the status-workflow
  filter (all/published/draft/pending/scheduled riding the manager-only
  `?status=` listing), and config-driven edit forms (title, excerpt,
  content, SEO title/description, scheduled publish, approval status
  select). Two new config primitives power it: a `select` field type
  (options + zod enum from one config; `SelectField` associates its label
  via `useId`/`htmlFor`) and a `statusFilter` collection flag
  (`ListView` + `useCollection` `status` arg). The publish transition is
  still the bridge's `publish_posts` gate — the SPA only surfaces it.
  Bundle flow unchanged: `npm run build:standalone` →
  `scripts/sync-manage-assets.mjs` → committed
  `public/manage-assets/astro-admin.{js,css}` (both rebuilt in the same
  change). Posts category editing is NOT in the form (no WP-category
  picker source in the SPA; `where[category]` stays MCP-side).
- **Content-slice gotchas (107 session — each cost a fix):**
  - **Route-cache poisoning:** the memory provider serves fresh hits BEFORE
    the route runs and caches any response carrying `CDN-Cache-Control` with
    maxAge>0 — including a manual `new Response('…', {status:404})` with a
    body. `Astro.cache.set()` must run ONLY after the 404/redirect gates
    (never before them), or a public 404 for a draft URL is cached and the
    preview render is served the cached 404. 404s return
    `Cache-Control: private, no-store`. Preview renders skip `cache.set` AND
    `Astro.cache.invalidate({ path })` first.
  - **Preview cookie `Secure`:** `secure: import.meta.env.PROD` breaks
    plain-HTTP local dev/e2e (browser refuses to send it) — derive from
    `x-forwarded-proto` first, then `Astro.url.protocol`.
  - **ZodEffects has no `.extend`/`.partial`:** a `.refine()`-ed object
    schema cannot be extended for the create/update variants — extend the
    plain `z.object` form, refine separately. A registration-time throw
    breaks EVERY tool call on the mount (-32603).
  - **sanitize-html in the MCP bundle:** the esbuild CJS shim throws
    `Dynamic require of "path" is not supported` when the bundle is imported
    from ESM. `scripts/lib/mcp-build.mjs` injects a `createRequire` banner —
    any new CJS dep in `mcp/` re-triggers this without it.
  - **WP 6.9 `rest_parse_date()` returns a Unix timestamp** (older core
    returns the `'Y-m-d H:i:s'` string) — normalize before `post_date`.
  - **A future `post_date` always wins:** `wp_insert_post` flips an explicit
    `publish` to `future` — never publish early.
  - **Bridge auth-gate tests:** user-0 dispatches re-authenticate via the
    bootstrap's admin cookie — assert gates with a subscriber (logged in,
    wrong cap), never anonymous.
  - **Content e2e isolation:** the content-write spec runs serial on its OWN
    fixture/BFF pair (8125/4323) — publishing/deleting on the shared pair
    races the parallel listing-count specs.

## Embedded checkout (Proposal 110, shipped 2026-11-04)

- **The app no longer links out for the common path.** In woo/both modes the
  cart syncs (108) and continues to `/checkout/` — the embedded checkout.
  The bridge (`REST/CheckoutController.php`) proxies the Store API with a
  bridge-minted `wc_store_api` nonce + the session Cart-Token:
  `GET /checkout` (merged draft-order + cart doc), `POST /checkout/customer`
  (Woo-side address validation + totals/rates), `POST /checkout/shipping-rate`,
  `POST /checkout/coupon`, `POST /checkout` (place order — `expected_total`
  passthrough, 409 drift preserves the refreshed cart, per-session AND per-IP
  rate limits). All routes fail-closed (501/400 typed) and pin
  `private, no-store`; the B1 public-cache filter EXCLUDES `/checkout`
  (per-session draft orders must never be edge-cached).
- **Payment boundary (INV-1 refined):** no RAW card inputs in the app;
  gateway-hosted fields are sanctioned. Card collection = Stripe Payment
  Element (`@stripe/*` v10, `src/islands/CheckoutView.tsx` → StripeSection),
  token relayed via the Woo-documented `payment_data` keys
  (`lib/commerce/checkout-api.ts` → `buildStripePaymentData()`). PCI: SAQ A
  (redirect methods) / SAQ A-EP (embedded Elements). **Apple/Google Pay is
  DEFERRED** — Stripe.js v10 removed the token-yielding legacy Payment
  Request Button; ExpressCheckoutElement needs client-secret confirmPayment
  and can't feed the Store API flow (live-verified follow-up + Apple Pay
  domain registration).
- **CSP:** the checkout page opts into the payment scope via the
  `x-nvoos-csp-payment` response header (stripped before shipping) —
  `scripts/lib/security-headers.mjs` adds Stripe script/frame/connect/img
  directives for THAT document only.
- **Shop globals additions:** read-only `stripePublishableKey` (empty when
  Stripe inactive — the hermetic fixture ships NO key, so e2e never loads
  Stripe.js) + `guestCheckout` (Woo switch; the submit button disables when
  accounts are required).
- **Thanks page:** `src/pages/checkout/thanks.astro` (the 108 route) reads
  VALIDATED `order_id`/`order_key` params only (`parseThanksParams`).
- **CartView:** woo/both success → `window.location.assign('/checkout/')`,
  handoff event mode `embedded` (was `server-cart`); hosted URL = fail-open
  fallback on sync error; native mode unchanged.
- **Fixture:** stateful per-session checkout twin in `fixture-server.mjs`
  (cart/sync seeds lines; checkout routes mutate; 409 drift simulation;
  default flat_rate so totals include shipping).
- **T1 (085/090):** the app binds ONLY the `nvoos-commerce/v1` checkout
  envelope — Laravel re-implements the five routes and the app survives
  unchanged.
- **Tests:** bridge `tests/phpunit/REST/TestCheckoutController.php` (12
  tests; the header filters are asserted via DIRECT invocation —
  `rest_post_dispatch` does not run inside `rest_get_server()->dispatch()`,
  the shop-globals test pattern). App: vitest
  `tests/unit/checkout-api.test.ts`, e2e `checkout-embedded.spec.ts` +
  reworked `checkout-woo.spec.ts` on the woo pair (one-shot seedCart via a
  sessionStorage flag — `addInitScript` re-runs on every navigation and
  would resurrect the cleared cart; `click()` not `check()` on CONTROLLED
  radios — the selected state returns from the round-trip).
- Full doc: `docs/project/proposals/110-embedded-checkout-store-api-proposal.md`.
- **Version discipline (2026-11-04, owner rule):** `src/lib/version.ts`
  `APP_VERSION` is the single source of truth — the footer, the MCP
  handshake (`SERVER_VERSION = APP_VERSION` in `mcp/server.ts`) and the
  manifests all derive from it. Bump INCREMENTALLY (0.4.0 → 0.4.1, not
  0.5.0) in version.ts + package.json + package-lock.json (the two ROOT
  entries only — dependency versions must not move) + the
  `tests/e2e/home.spec.ts` footer pin (`NV Store v0.x.y`) in one commit.
  The BRIDGE has its OWN version (plugin header `nvoos-astro-bridge.php`
  + `CHANGELOG.md`; 1.1.0 → 1.1.1 for 110) — bump it whenever the bridge
  gains routes so the wp-admin Plugins screen proves the deploy landed.
- **The merge-deploys-app-not-bridge gap (2026-11-04 outage):** the sync
  workflow redeploys the APP on alpha-working merge, but the bridge plugin
  on nvoos.pro is a MANUAL deploy. A cluster that changes both sides leaves
  the live storefront broken the moment the app ships: the checkout page
  shows "The checkout could not be loaded — No route was found matching the
  URL and request method" (`rest_no_route` — the stale bridge has no
  `/checkout` routes) and the console shows a 404 (the `/api/checkout`
  fetch). Fix = build the bridge zip and upload ("Replace current with
  uploaded"): python zipfile (forward slashes, single top-level
  `nvoos-astro-bridge/` folder), include-set = the main file,
  `uninstall.php`, `LICENSE`, `README.md`, `CHANGELOG.md`,
  `THIRD_PARTY_NOTICES.md`, `includes/`, `build/` (the standalone SPA
  bundle is REQUIRED — wp-admin enqueues it); exclude `src/`, `tests/`,
  `node_modules/`. Verify the version on the Plugins screen, then retest
  `/checkout/`. The cart page got the checkout's `no-store` treatment in
  the same pass (`src/pages/cart/index.astro`).

## Component library (Q6, 2026-11-03)

Owner-suggested candidates were researched (registry facts verified
2026-11-03); the evaluation lives in the gap analysis §10. **Adopted
hybrid:** Incluud `accessible-astro-components` (MIT, zero-dep, a11y-first)
adapted into `src/components/ui/` — behavior/markup preserved, styling
re-tokenized onto ADS (`--nv-*`), attribution headers + NOTICE; Zag.js
(`@zag-js/dialog` + `@zag-js/combobox` via `@zag-js/react`) powers unstyled
islands (`src/islands/UiDialog.tsx`, `UiCombobox.tsx`). Demo: `/components/`
gallery + `tests/e2e/component-library.spec.ts` (axe + keyboard gates).
`astro-component-kit` (effect showcase), Starwind/bejamas (Tailwind),
Shoelace (stale) and Lion/AstroUXDS (heavy) were rejected — see the table.

- **Zag 1.47 API (three breaking shapes — each cost a fix):**
  `dialog.machine` is a machine DEFINITION object, not a factory — all props
  (incl. `id`) go in `useMachine(machine, userProps)`'s second arg; and
  `useMachine` returns the `Service` directly (no `[state, send]` tuple) —
  `connect(service, normalizeProps)` takes it. Type the callbacks from the
  package exports (`dialog.OpenChangeDetails`, `combobox.InputValueChangeDetails`).
- **Combobox `composite` default breaks axe:** the roving-tabindex default
  omits `role="listbox"` from the list while items carry `role="option"` —
  axe fails `aria-required-parent`/`only-listitems`. Set `composite: false`
  for the classic listbox ARIA shape.
- **Island DOM never carries the Astro scope attribute (caught
  post-deploy on /components/, 2026-11-03):** page-scoped styles targeting
  island-rendered classes silently don't match — the Zag combobox shipped
  with default list bullets and no hover highlight. Island-targeting rules
  in .astro page style blocks must be `:global()`; pin the regression in
  e2e with `toHaveCSS` (the component-library spec does).
- **Islands under parallel e2e load:** `page.goto` resolves before
  `client:load` islands hydrate; an interaction landing on an un-hydrated
  controlled input sticks its native value and React never sees the event
  (symptom: the collection never filters, `data-highlighted` never appears —
  flaky ONLY under full-suite load). Fix: the repo's `data-hydrated` marker
  convention (HomeStats/StackExplorer) — set it in a mount `useEffect` and
  `expect(...).toHaveAttribute('data-hydrated', '')` before interacting.

## Proposal 108 + blocks promotion (2026-11-03)

- **108 shipped in full (PR #7414 + follow-up):** Phase 1 — the
  same-site/allowlist rule on `checkoutUrl`/`cartUrl`/`returnUrl`
  (`funiq_bridge_checkout_url_allowlist`; userinfo + non-80/443 ports
  rejected; codes `astro_shop_{checkout_url,cart_url,return_url}_not_allowed`;
  the camel→snake mapping is `preg_replace('/([a-z])([A-Z])/', '$1_$2', …)`)
  + `rel="noopener noreferrer"` on the handoff link. Phase 2 — the bridge
  cart proxy: `POST /nvoos-commerce/v1/cart/sync` (public customer surface,
  `X-Nvoos-Cart-Session` UUID header, woo/both modes only) reconciles the
  local cart into the Woo session cart via `Woo/StoreApiClient` (Store API
  `Cart-Token` guest sessions, transient-cached per session id, zero-HTTP
  test seam `funiq_bridge_store_api_pre_response` + availability seam
  `funiq_bridge_store_api_available` — the env's Woo stubs define
  `wc_get_product`, so `remove_all_filters` alone cannot prove the 501
  path; add `__return_false`). The app island (`CartView`) syncs then hands
  off with a CLEAN checkout URL (variants included), fails OPEN to the
  legacy URL form with the variant note, and fires the
  `nvoos:checkout-handoff` CustomEvent analytics seam (mode/lines/variants).
  Phase 3 — `returnUrl` globals + `ReturnUrlFilter` (`woocommerce_get_return_url`
  → `/checkout/thanks/?order_id=&order_key=`), read-only `paymentMethods`
  (60 s transient) + `productsSource` in the shop doc, and the opt-in SSO
  route `GET /woo-login/{token}` (`funiq_bridge_woo_sso_enabled`, 5-min
  single-use per-user HMAC; `auth_cookie` filter is the test seam for
  `wp_set_auth_cookie`, which never populates `$_COOKIE` under CLI).
  Phase 4 — MCP `shop_get`/`shop_update` (SERVER_VERSION 0.4.0, 35 tools;
  write gate = manager cookie + `NVOOS_MCP_SHOP_WRITE_*` quota). Fixture:
  the `checkout-woo` e2e project rides its own pair (8126/4324,
  `NVOOS_FIXTURE_PRODUCTS_SOURCE=woo`) and the fixture serves PATCH
  `/globals/shop` + POST `/cart/sync`. Docs: `docs/project/proposals/108-*`
  trio (research + gap analysis with DECISION-1/2 + PCI INV-1) + bridge
  README route table.
- **Content blocks promoted to the adopted components:** `faq` renders as
  the accordion (`UiAccordionItem`, shared `name="nvoos-content-faq"` →
  native exclusive accordion; solo-item top border restored in scope) and
  a new `tabs` block type (nested Portable Text per tab, deterministic ids
  from `_key` or a djb2 of the node JSON, recursive schema). The type/mark
  map moved to `src/components/blocks/components.ts` (shared by
  Blocks.astro + TabsBlock.astro — no circular component imports).
- **Zod recursive-schema recipe (cost a session):** a `z.lazy` schema
  inside a `z.discriminatedUnion` cannot carry a `z.ZodType<T>` annotation
  (ts2502/ts2456/ts2345) — declare the recursive member as an INTERFACE
  (`blocks: PortableTextItem[]` property) and build the item union from
  the non-recursive members' `z.infer` + the interface. `z.lazy` in the
  schema, no annotation on the union const.
- **Deploy-preview recipe:** to preview an unmerged branch's routes on the
  live site, dispatch the sync workflow on the branch ref
  (`gh workflow run sync-nvoos-astro-velocity.yml --ref <branch>`) — the
  mirror push is what Velocity deploys. **Stale-process symptom:** after a
  mirror push, new static assets return 200 while a new ROUTE 404s — the
  files are deployed but the Node process still runs the old route table;
  it resolves when Velocity restarts it (or nudge with another dispatch).
  Static-asset 200-vs-404 is how you fingerprint which build NGINX serves.
  Route chunks for pages named `index.astro` hash as `index_<hash>` —
  there is no `components_*` chunk; locate a nested route by grepping
  chunk CONTENT (a unique page string), never the filename.

## References

- Proposal 092 (design, phases, owner decisions) · Proposal 095 (ADS + nvoos-astro-mcp, PR #7301) · Proposal 096 (Astro Forge — the WP→Astro **generator** in `examples/nvoos-astro-forge/`; ops in the `mcp-ai-wpoos-astro-forge` skill) · Proposal 097 (cross-surface token sharing) · Proposal 098 (design language import — `design_analyze`, `src/lib/design-import/`) · **Proposal 104** (runtime hardening: `-proposal.md`, `-research.md`, `-implementation-plan.md`, `-ecosystem-repo-analysis.md`) · `docs/operations/deployment/nvoos-astro-velocity-setup.md`
- `examples/nvoos-astro-velocity/README.md` (architecture, env, commands)
- `addons/nvoos-astro-bridge/README.md` (REST surface, data map)
- Related skills: `mcp-ai-sdk-v2-server-ops` (dual-era wire serving), `mcp-ai-wpoos-git-ops`, `mcp-ai-wpoos-astro-forge` (the generator that turns a WP/Woo site into an Astro frontend — this skill covers the serving side)
- Related: Proposal 085 + appendix (contract invariant, Strangler Fig),
  Proposal 090 (Laravel backend flip — the storefront survives it unchanged),
  **Proposal 107** (storefront content surface: gap analysis + implementation
  plan — the 8-tool content slice, Portable Text blocks, preview tokens,
  approval ladder; this skill's code companion).
