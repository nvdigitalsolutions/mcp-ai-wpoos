# Proposal 135 — Implementation plan (managed tier + domain topology)

Companion to `135-forge-managed-tier-domain-topology-proposal.md`.
Status: **Phase 1 shipped 2026-11-09**; Phases 2–6 planned.

---

## 1. Phase map

| Phase | Deliverable | Depends on | Status |
|---|---|---|---|
| **1** | `deploy.runtime` profile block → emission → workflow plumbing → tests | — | **shipped** |
| **2** | Managed runtime: proxy routes + revalidate endpoint + `routeRules` + server entry + deploy tree | 1 | **shipped** (live collections remain 2b) |
| **3** | Bridge public origin (`OPTION_PUBLIC_ORIGIN`) + allowlist | 1 | **shipped** |
| **4** | Bridge cache tags + media origin + preview branches + post-push smoke | 2, 3 | **shipped** |
| **6** | Consent-mode v2 gate (`verify --tracking`) | 2 | **shipped** |
| **2b** | Tag emission on the SSR route + the forge-bridge dual revalidate target | 2 | **shipped** (live collections remain) |
| 2c | Live content collections (`defineLiveCollection`) | 2b | planned |
| 5 | Preview/draft exchange + one local-content store | 2, 3 | planned (D4 recorded: WordPress CPT) |

Phase 1 is deliberately the smallest change that makes the two-tier model
**expressible and safe**: nothing changes for existing profiles, and a
mis-configured managed site fails loudly instead of deploying a broken app.

---

## 2. Phase 1 — file-by-file (shipped)

### 2.1 `examples/nvoos-astro-forge/lib/types.ts`

Adds the operator-facing block and wires it into `SiteProfile`:

```ts
export type ForgeRuntime = 'static' | 'managed';

export interface ForgeDeploy {
	runtime?: ForgeRuntime;              // default 'static'
	publicOrigin?: string;               // defaults to ASTRO_SITE / profile.url
	revalidate?: boolean;                // managed only: /api/revalidate
	manage?: boolean;                    // managed only: the /manage surface
	forms?: 'bridge-direct' | 'bff-proxied';
}

export interface SiteProfile {
	// …
	deploy?: ForgeDeploy;
}
```

Every field is optional — an existing `sites/*.json` is byte-compatible.

### 2.2 `examples/nvoos-astro-forge/lib/deploy-config.ts`

- `DeployRuntime` type + `DeployConfig.runtime` (default `static`).
- `assertRuntime()` — rejects anything outside `static | managed`.
- `deploySteps()` passes `RUNTIME` into `mirror-push.mjs`.
- `mirrorPushScript( runtime )` becomes runtime-aware:
  - writes a **`deploy.json` manifest** at the branch root
    (`{ runtime, entry, outputDirectory, generatedAt }`) so the Velocity
    settings are reproducible;
  - `static` → the existing no-op `package.json` (unchanged output);
  - `managed` → a real `start` script (`node server.js`), `type: module`,
    `engines.node`, and a **fail-closed guard**: if `server.js` is absent from
    the copied deploy tree, exit 1 with the Phase-2 instruction instead of
    pushing a site that cannot boot.
- `emitDeployConfig()` validates the runtime before writing anything.

### 2.3 `examples/nvoos-astro-forge/scripts/forge.mjs`

- `--runtime <static|managed>` on **`deploy-config`** and **`deploy`**
  (default `static`).
- `assertRuntime()` in the shared validation path, `failRuntime()` for the
  message.
- Both call sites forward `runtime` into `emitDeployConfig`.

### 2.4 `.github/workflows/forge-sites.yml`

The per-site loop reads the profile's runtime and forwards it:

```bash
RUNTIME=$(node -e "…JSON.parse(fs.readFileSync('sites/$name.json')).deploy?.runtime ?? 'static'")
node scripts/forge.mjs deploy-config … --runtime "$RUNTIME"
```

Unset/invalid → `static` (the previous behaviour). No secret or env change.

### 2.5 `examples/nvoos-astro-forge/tests/deploy-config.test.ts`

Four new cases:

| Test | Asserts |
|---|---|
| static default writes the no-op package | `deploy.json` runtime `static`; `package.json` carries the no-op build |
| managed writes the start script | `deploy.json` runtime `managed`, entry `server.js`, outputDirectory `.`; `package.json` has `"start": "node server.js"` |
| managed refuses a tree without `server.js` | the emitted script exits 1 and prints the Phase-2 instruction |
| invalid runtime is rejected at emit time | `emitDeployConfig` rejects |

Existing assertions (no-op package, orphan checkout, force push, no secrets)
stay untouched — that is the regression gate for the static tier.

---

## 3. Phase 2 — the managed runtime (shipped 2026-11-09)

Emitted only when `runtime === 'managed'`. Managed **implies server output**
(`--render ssr` is inferred; an explicit `--render static` throws before any
writes).

### 3.1 The correction that shaped this phase

The original design used Astro 7's `src/fetch.ts`. A runtime smoke test showed
Astro warning that a custom fetch handler bypasses its feature middleware:

```
[WARN] [router] Your project uses sessions, but your custom src/fetch.ts
               does not call the sessions() handler.
[WARN] [router] Your project uses cache, but your custom src/fetch.ts
               does not call the cache() handler.
```

On a managed storefront that means **customer sessions and route caching
silently stop working**. The emitted surface is therefore **endpoint routes**,
which run inside Astro's normal pipeline:

| File | Route | Upstream |
|---|---|---|
| `src/lib/managed-proxy.ts` | — | the shared helper (`proxyToWordPress`, `sanitizeRestPath`, host-only cookies) |
| `src/pages/api/[...path].ts` | `/api/*` | `<CMS>/wp-json/nvoos-commerce/v1/*` |
| `src/pages/api/forms/submit.ts` | `/api/forms/submit` | `<CMS>/wp-json/nvoos-forge/v1/forms/submit` |
| `src/pages/api/revalidate.ts` | `/api/revalidate` | local (`cache.invalidate`), token-gated |
| `src/pages/media/[...path].ts` | `/media/*` | `<CMS>/wp-content/uploads/*` |

Static routes win over the rest param, so `/api/revalidate` and
`/api/forms/submit` are never proxied (verified at runtime).

Rules (unchanged from the design):

- **No browser request ever reaches the CMS host** — the CMS stays private and
  there is no CORS surface.
- Auth is same-origin; `Set-Cookie` keeps only the host-scoped attributes
  (`Domain=` is stripped).
- Rest params are traversal-guarded (`..` and absolute paths → 404).
- Revalidate is fail-closed: no token → `503`; wrong bearer → `401`; the token
  is never echoed.
- `NVOOS_TARGET_SITE` unset → every proxy route returns `503` with a named
  message (never a silent 200).

`/mcp` is **not** emitted: the forge template has no assistant surface today
(that is the velocity app's `nvoos-astro-mcp`). The `/manage` CMS tabs need
only `/api/*`; the Design System tab additionally needs `/mcp` and remains out
of scope until that surface is ported.

### 3.2 `routeRules` + cache provider (shipped)

`renderAstroConfig()` gains a `managed` flag: it swaps the import to
`{ defineConfig, memoryCache }` and injects

```js
cache: { provider: memoryCache() },
routeRules: {
  '/products/**': { maxAge: 300, swr: 600 },
  '/posts/**':    { maxAge: 300, swr: 600 },
  '/manage/**':   { maxAge: 0 },
  '/api/**':      { maxAge: 0 },
}
```

Per-entry tags (`products:<slug>`) are Phase 2b — the revalidate route already
accepts them.

### 3.3 Deploy tree + entry (shipped)

- `server.js` — `import './dist/server/entry.mjs';` (PM2 requires the root
  entry).
- `scripts/sync-deploy-tree.mjs` — `.deploy/` = `dist/` + `server.js` + a
  runtime `package.json` (`start: node server.js`); fails when `dist/` or
  `server.js` is missing.
- `deploy-config` managed mode runs the assembler and pushes
  `SOURCE_DIR=.deploy`; `forge-sites.yml` does the same per site.

### 3.4 Phase 2b — tag emission + the dual target (shipped 2026-11-09)

**Tag emission (forge).** The on-demand product route tags its response:

```ts
if (MANAGED) {
	Astro.cache.set({ maxAge: 300, swr: 600, tags: ['products', `products:${numericId}`] });
}
```

The guard is the generated `src/lib/managed.generated.ts` (`MANAGED`), rewritten
to `true` only for managed scaffolds. Without it an *ssr-without-managed* build
would call the cache API with no provider configured; with it, that build never
touches it. The module ships `false` in the template and is listed in the
drift manifest.

**Dual target (plugin `nvoos-forge-bridge`).** `Rebuild_Webhook` now notes
the tags a change affects and posts them to the managed frontend:

| Post type | Tags |
|---|---|
| `funiq_product`, `product`, `product_variation` | `products`, `products:<id>` |
| `post` | `posts`, `posts:<id>` |
| `page` | `pages`, `pages:<id>` |
| anything else | nothing (rebuild backstop) |

- `Settings::OPTION_REVALIDATE_URL` / `_TOKEN` (write-only token), with
  `revalidate_configured()`.
- `send_pending()` now runs the dispatch **and** `send_revalidate()`
  independently — one failing never suppresses the other, and revalidate works
  with no GitHub dispatch configured at all.
- `send_revalidate()` is fail-soft (returns the request result, never throws),
  bounded to 5 s, and never logs the token.

Live content collections (§3.5) remain Phase 2c — the loader-side change.

### 3.5 Phase 2c — live collections (planned)

---

## 4. Phase 3 — bridge public origin (shipped 2026-11-09)

`addons/nvoos-astro-bridge`:

1. `Schema::OPTION_PUBLIC_ORIGIN` (`funiq_bridge_public_origin`) +
   `Schema::public_origin()` (stored value → `home_url()` fallback, filterable
   via `funiq_bridge_public_origin`) + `Schema::normalize_origin()`.
2. `SettingsPage` — a **Domains** section with the "Public site origin" field,
   a `sanitize_public_origin` callback (normalizes; never persists an invalid
   value), and status rows showing both the public and WordPress origins.
3. `Schema::shop_defaults()` builds checkout/cart/return from the public
   origin.
4. `ShopGlobalsController::is_allowed_shop_url()` accepts the public-origin
   host **and** the `home_url()` host; a third host is still rejected.
5. Tests: the sanitizer normalizes/rejects; defaults follow the public origin;
   the public host passes the allowlist without a filter; a third host still
   400s.

Deliberately **not** shipped from the original plan: the fail-closed `Origin`
guard, and adding the option to `uninstall.php`. The bridge's `uninstall.php`
preserves all settings options today (only banner/carousel are removed), so
removing just this one would be inconsistent; and in the managed topology the
browser never calls the bridge cross-origin (the proxy is server-to-server),
so an Origin gate would add risk without coverage. Both move to Phase 4 if a
cross-origin consumer appears.

**Sequencing rule:** Phase 3 must ship before the first managed client goes
live, or checkout/cart URLs resolve to `cms.<client>`. — **satisfied.**

---

## 5. Phase 4 — cache tags, media, preview branches (shipped 2026-11-09)

### 5.1 Bridge cache tags (`BaseController`)

Public GET responses carry `Cache-Tag: <collection>[,<collection>:<id>]` and
`Surrogate-Key: <collection>[ <collection>:<id>]`, derived from the matched
route (`cache_tags()`). The namespace constants already include `/v1` — the
first run of `TestCacheTags` caught a double-`/v1` strip that produced
`nvoos-commerce` tags; fixed and pinned.

### 5.2 Bridge media origin (`Schema::OPTION_MEDIA_ORIGIN`)

- `cms` (default) — absolute `wp_get_attachment_url()` URLs (static sites).
- `frontend` — `/media/<relative>` paths for the managed media proxy.
Applied through `Schema::media_url()` in the product, category and promocode
transformers. The settings page warns that a static site cannot serve
`/media/*`.

### 5.3 Preview branches (`forge-sites.yml`)

`workflow_dispatch` gains `preview: true` → the run writes
`site/<name>-preview` instead of the production branch (same pipeline, no
smoke check).

### 5.4 Post-push smoke

Opt-in via the repo variable `NVOOS_FORGE_SMOKE`: after the push, `curl` the
**public origin** and warn (never fail) on a non-200 or a `noindex` meta on
the public site.

### 5.5 Topology fix surfaced here

`deploy.publicOrigin` is now honored end to end: `resolveSiteUrl()` (CLI
`deploy` + `deploy-config`) and the workflow bake `ASTRO_SITE` from
`deploy.publicOrigin ?? url`. Previously the workflow passed the CMS url
(`profile.url`) as `ASTRO_SITE`, which would have canonicalized every
sub-domain-CMS site to its own CMS host.

---

## 6. Phase 5 — preview + local content (shipped 2026-11-09)

**D4 (recorded 2026-11-09): a WordPress CPT read through the bridge.** Managed
sites already have an editing surface; a second Git-based content store
(Keystatic) would duplicate the editing surface, the auth model and the audit
trail. One store, one capability, one audit trail.

The CPT ships as **`nvoos_forge_local`**, not `forge_local`: a bare slug is a
collision hazard on a shared WordPress install, and every new identifier in the
addon carries the `nvoos_` prefix (the 112 meta-prefix decision).

**D6 (preview tokens):** bridge-verified, frontend-transported. The MAC is
`HMAC(wp_salt('auth'), 'nvoos-bridge-preview-v1.<post_id>.<exp>')`; the frontend
stores the token in a host-only cookie and never verifies it, so no key
material leaves WordPress and the frontend cannot mint a preview.

**D7 (`/manage` distribution):** **not** bundled into the forge template.
`addons/nvoos-astro-bridge/LICENSE` is proprietary; the template is a separate
distribution, so redistributing a compiled bundle is not covered. Editors use
the CMS-hosted Astro CMS page today; a future `/manage` route may *load* the
bundle from the licensed CMS host (no redistribution) once the auth walkthrough
(login + nonce through the managed proxy) is written and tested.

### 6.1 Files

| File | Change |
|---|---|
| `addons/nvoos-astro-bridge/includes/Schema.php` | `CPT_FORGE_LOCAL`, `PREVIEW_TTL` (900), `SOURCE_LOCAL` |
| `addons/nvoos-astro-bridge/includes/PostTypes/ForgeLocal.php` | **new** — private CPT, `show_in_rest` false, `capability_type: post` |
| `addons/nvoos-astro-bridge/includes/REST/PreviewToken.php` | **new** — `issue()` / `verify()`, base64url payload + HMAC, constant-time compare |
| `addons/nvoos-astro-bridge/includes/REST/LocalController.php` | **new** — `/local` CRUD (draft-first, governed statuses, revisions), dual-shape document, `preview_token` on single + listing, `POST /local/<id>/preview-token` |
| `addons/nvoos-astro-bridge/includes/REST/BaseController.php` | `is_preview_request()` guard in both cache-header filters — a preview response is never marked public |
| `addons/nvoos-astro-bridge/includes/Plugin.php` | register the CPT (init + activation) and the controller (both namespaces) |
| `addons/nvoos-astro-bridge/src/{types,config/collections,auth/permissions}.ts` | `local` collection slug + config + nav entry |
| `addons/nvoos-astro-bridge/src/hooks/useCollection.ts` | `createPreviewLink()` |
| `addons/nvoos-astro-bridge/src/components/ListView.tsx` | per-row **Preview** button (mints, opens a tab) |
| `addons/nvoos-astro-bridge/build/**`, `examples/nvoos-astro-velocity/public/manage-assets/**` | rebuilt bundles (the shared SPA) |
| `examples/nvoos-astro-forge/lib/types.ts` | `source: 'wp' \| 'payload' \| 'local'` |
| `examples/nvoos-astro-forge/lib/wp-loader.ts` | local branch, `payloadRestBase()`, slug-authoritative `extractPayloadRaw()`, optional-source degradation, missing-`restBase` guard |
| `examples/nvoos-astro-forge/lib/managed-runtime.ts` | `renderPreviewApiTs()` + `renderPreviewPageTs()` |
| `examples/nvoos-astro-forge/lib/scaffold.ts` | emit the preview surface for managed; `collectionRestBase()`; **`writeGeneratedManifest()` moved to step 3g**; `frontPage` always emitted |
| `examples/nvoos-astro-forge/scripts/wp-fixture-server.mjs` | `payloadLocal` fixture + `/nvoos-commerce/v1/local` (with the slug filter) |

### 6.2 Contract notes

- Local documents carry the content shape **and** the envelope aliases
  (`name`, `description`) plus an explicit `slug` — one wire format, two
  consumers.
- Reads are publish-only publicly; a draft is a **404**, never a 403 (no
  existence leak), unless a valid `preview_token` names it.
- `preview_token` narrows a listing to its own post, which is how a draft
  renders at a real URL.
- The cache filters check for the **parameter's presence**, not its validity: a
  garbage token still means "this caller asked for a draft", and a cached
  403/404 is indistinguishable from the real thing.

### 6.3 Bugs this phase found and fixed in shipped code

1. **Every managed site failed its own drift gate.** `nvoos.generated.json` was
   hashed at step 3a, *before* the managed block (3f) rewrote
   `src/lib/managed.generated.ts`, so `npm run check` reported a phantom
   "drifted" file. The manifest write is now `writeGeneratedManifest()` at step
   3g, after every generated write. Regression test asserts the hash matches
   the post-rewrite content.
2. **`frontPage` vanished from `nvoos.json` when the profile omitted it**
   (`JSON.stringify` drops `undefined`), which broke `[slug].astro`,
   `sitemap.xml.ts`, `index.astro` and `redirects.json.ts` under `astro check`.
   Now emitted as `''` (the template already treats a falsy slug as "no front
   page").
3. **A `local` collection without `restBase` crashed the scaffold**
   (`tsString(undefined)`). `collectionRestBase()` resolves the canonical base;
   the loader also fails with a legible message instead of requesting
   `/wp-json/undefined`.

---

## 7. Phase 6 — consent + tracking gate (shipped 2026-11-09)

The **emission** half already shipped with Proposal 130: `Tracking.astro`
emits `gtag('consent','default',{ ad_storage, ad_user_data,
ad_personalization, analytics_storage, … })` before any tag, then
`gtag('consent','update',…)` from the stored decision + GPC.

This phase adds the **gate** in `tracking-audit.ts`:

| Finding | Severity | Catches |
|---|---|---|
| `consent-default-call-missing` | fail | a consent block with no default call |
| `consent-update-missing` | fail | the classic bug — a banner records a choice but never updates the tags |
| `consent-v2-param-missing` | fail | missing `ad_user_data` / `ad_personalization` (EEA/UK) |

Existing gates (CSP host coverage, GTM+GA4 double-count, purchase-owner)
remain unchanged. Covered by three new cases in `tracking-audit.test.ts`, and
the shared `HEAD` fixture was updated to the real emitted shape.

---

## 8. Validation

### 8.1 Actually run (2026-11-09)

**Forge — Phase 1 + Phase 2**

| Gate | Command | Result |
|---|---|---|
| Forge typecheck | `npm run typecheck` | clean |
| Deploy-config suite | `npx vitest run tests/deploy-config.test.ts` | **24 tests pass** |
| Managed-runtime suite | `npx vitest run tests/managed-runtime.test.ts` | **11 tests pass** |
| Scaffold suite | `npx vitest run tests/scaffold.test.ts` | **72 tests pass** (incl. managed emission + the static-tier no-op guard) |
| Forge unit suite | `npm test` | **600 tests / 43 files pass** |
| Forge e2e | `npm run test:e2e` | **31 tests pass** |
| Managed typecheck (real project) | scaffold `--render ssr` + `npm install` + `npx astro check` | **0 errors, 0 warnings** (66 files) |
| Managed build | `npx astro build` | server output built (`dist/server/entry.mjs`) |
| Deploy tree | `npm run sync-deploy-tree` | `.deploy/{dist,server.js,package.json}` with `start: node server.js` |
| Runtime smoke | `PORT=4398 node server.js` + curl | `/` 200; `/api/products` 503 (target unset); `/api/revalidate` 503 (token unset) — static route wins over the rest param; **no feature warnings** |

**Bridge — Phases 3 + 4**

| Gate | Command | Result |
|---|---|---|
| PHP syntax | `php -l` on all 8 touched files | clean |
| Coding standard | `phpcs --standard=phpcs.xml.dist` (addon) | **0 errors, 0 warnings** |
| Bridge PHPUnit | `docker exec oos-wp … phpunit --group nvoos-astro-bridge` | **193 tests, 692 assertions, OK** |

The bridge suite ran against the Docker WordPress test library (WP 6.9 /
PHP 8.3) through `plugins_loaded` — the production boot path. `composer
install` was needed first: the vendored PHPUnit was installed `--no-dev`, so
the autoloader had no `PHPUnit\` mapping.

**Plugin `nvoos-forge-bridge` (2b)**

| Gate | Command | Result |
|---|---|---|
| PHP syntax | `php -l` on the 3 touched files | clean |
| Coding standard | `vendor/bin/phpcs --standard=phpcs.xml.dist` (plugin) | clean |
| Unit suite | standalone `vendor/bin/phpunit -c phpunit.xml.dist` | **not run** — its bootstrap needs `WP_TESTS_DOMAIN`/`WP_TESTS_TITLE`/`WP_TESTS_EMAIL`/`WP_PHP_BINARY`, which this environment does not define (pre-existing harness gap; the plugin's CI workflow defines them). The four new cases are in `tests/test-rebuild-webhook.php`. |

**Bridge — Phase 5 (local content + preview)**

| Gate | Command | Result |
|---|---|---|
| PHP syntax | `php -l` on the 7 touched/new files | clean |
| Coding standard | `phpcs --standard=phpcs.xml.dist` (addon) | **0 errors, 0 warnings** |
| Bridge PHPUnit | `docker exec oos-wp … --group nvoos-astro-bridge` | **206 tests, 739 assertions, OK** (13 new in `TestLocalController`) |
| SPA typecheck | `cd addons/nvoos-astro-bridge && npm run typecheck` | clean |
| SPA unit suite | `npm test` | **49 tests / 9 files** (8 new: catalogue + preview link) |
| SPA bundles | `npm run build && npm run build:standalone` + `sync-manage-assets` | rebuilt |

**Forge — Phase 5**

| Gate | Command | Result |
|---|---|---|
| Typecheck | `npm run typecheck` | clean |
| Unit suite | `npm test` | **615 tests / 43 files** |
| e2e | `npm run test:e2e` | **31 tests** |
| Live managed project | scaffold → `npm install` → `npm run check` | **0 errors, 0 warnings**, drift manifest matches |
| Build + deploy tree | `npm run build`, `npm run sync-deploy-tree` | `.deploy/{dist,server.js,package.json}` |
| Preview exchange | `curl /api/preview?token&slug` | **302**, `cache-control: no-store`, `Set-Cookie: nvoos_preview=…; Path=/; HttpOnly; SameSite=Lax` (**no `Domain`**) |
| Preview page | `curl /preview/<slug>/` | **404** without cookie; **200** with it, draft body rendered, `<meta name="robots" content="noindex, nofollow">`; **404** for a wrong slug |

### 8.2 Per-phase gates

| Gate | Command |
|---|---|
| Forge typecheck | `cd examples/nvoos-astro-forge && npm run typecheck` |
| Forge unit suite | `npm test` |
| Forge e2e | `npm run test:e2e` |
| Bridge PHP syntax | `php -l` on every touched file |
| Bridge standard | `vendor/bin/phpcs --standard=phpcs.xml.dist .` |
| Bridge PHPUnit | `vendor/bin/phpunit -c phpunit.xml.dist --no-coverage` |
| Workflow syntax | `actionlint .github/workflows/forge-sites.yml` (when available) |

Phase 1 must leave the static tier **byte-identical**: the prior
`deploy-config` assertions (no-op package, orphan checkout, force push, no
secrets) are the regression gate, and they still pass untouched.

---

## 9. Operator runbook

### 9.1 New static site (Class A)

1. `sites/<name>.json` with `url` = the **CMS** host, e.g.
   `https://cms.client.com`.
2. Leave `deploy` absent (runtime `static`).
3. Set `WP_HOME`/`WP_SITEURL` to the CMS host; add the CI runner IP to the
   allowlist; `noindex` + disallow-all `robots.txt`.
4. Velocity app: mirror repo, Branch `site/<name>`, Root = repo root, Output
   directory `.`.
5. Public DNS: apex + `www` → the Velocity app.

### 9.2 New managed site (Class B)

1. Same as 9.1, plus `deploy: { runtime: "managed", revalidate: true,
   manage: true, forms: "bff-proxied" }`.
2. Wait for Phase 2 before pointing traffic: the branch must contain
   `server.js` + `dist/`.
3. Velocity app: Entry file `server.js`, Root = repo root, Output directory `.`;
   env `NVOOS_TARGET_SITE`, `ASTRO_SITE`, `NVOOS_REVALIDATE_TOKEN`,
   `PORT` (injected).
4. Allowlist the **Node server** IP on the CMS (not the browser's).
5. Verify: `/api/products` returns the envelope, a content save invalidates the
   tag within a minute.

### 9.3 Local content + draft preview

1. Add the collection to the profile — the REST base is optional, `local`
   resolves to `nvoos-commerce/v1/local`:

   ```json
   { "slug": "posts", "source": "local", "kind": "post", "optional": true }
   ```

2. Set **Settings → Domains → Public site origin** on the bridge to the site's
   real public origin (`https://client.com`). Without it there is no preview
   URL (the mint still returns a token, and the `previewUrl` is empty).
3. Editors sign in to the CMS, open **Astro CMS → Local content**, write, save
   (draft), then press **Preview** on the row.
4. The preview opens `https://client.com/preview/<slug>/` via
   `/api/preview`. It expires in 15 minutes and is never cached or indexed.
5. Publish through the normal status transition (needs `publish_posts`); the
   forge-bridge posts `{tags:["local"]}` to `/api/revalidate`, so a managed
   site shows the change in seconds. A static site picks it up on the next
   rebuild.

**Known limits (deliberate):** the forged site does not ship `/manage` (D7) —
`/manage` stays on the CMS host, or the licensed bundle is loaded from it in a
future phase. A preview route requires the managed tier (it is SSR).

### 9.4 Flipping an existing site to managed

1. Confirm Phase 2 is merged and the template emits `server.js`.
2. Add `deploy.runtime: "managed"` to the profile; merge.
3. The workflow re-emits; `mirror-push` fails closed if `server.js` is missing
   — that failure is the signal to check the template, not to remove the flag.
4. Update the Velocity app's Entry file **before** the next deploy.

---

## 10. Rollback

- **Phase 1:** remove `deploy.runtime` from the profile (or set `static`).
  Nothing else changed. A managed branch that already shipped `server.js`
  continues to run until the next mirror push replaces the branch.
- **Phase 2:** a managed site can be reverted to `static` at the cost of
  `/manage`, `/api/*` and revalidate; the prerendered routes keep working
  because the build is unchanged.
- **Phase 3:** unsetting the public origin restores the current `home_url()`
  behaviour exactly.

---

## 11. Known limits

- The static tier cannot preview drafts, cannot edit local content, and cannot
  serve forms without a publicly reachable CMS. That is the tier boundary, not
  a bug.
- `memoryCache()` is per-process; a multi-instance Velocity app should move to
  a CDN provider when that ships beyond experimental.
- Astro 7.2 incremental static builds are experimental and are **not** a
  dependency of any phase.
- The `deploy.json` manifest documents Velocity settings; it cannot apply them
  (the Cloudways MCP exposes no deployment-settings tool).
