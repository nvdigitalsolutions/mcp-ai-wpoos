# Proposal 135 — two-tier deploy, domain topology, managed sites (Astro Forge)

`SKILL.md` keeps the summary only; this file holds the full operational notes.
Proposal + implementation plan:
`docs/project/proposals/135-forge-managed-tier-domain-topology-{proposal,implementation-plan}.md`.

## The topology rule

- **WordPress is never the public origin.** A profile's `url` is the CMS host
  (`https://cms.client.com`); the public site is `ASTRO_SITE`
  (`https://client.com`) on the mirror branch. Set `WP_HOME`/`WP_SITEURL` to
  the CMS host or REST `link` / media `source_url` / canonical / preview URLs
  all point at routes the Astro app does not serve. Keep the CMS `noindex` and
  reachable only by the builder (static tier) or the Node server (managed).
- **`deploy.runtime`** in `sites/<name>.json` — `static` (default) or
  `managed`. It flows profile → `deploy-config --runtime` → `mirror-push.mjs`;
  every branch gets a `deploy.json` manifest (`runtime`, `entry`,
  `outputDirectory`) so the Velocity settings are reproducible.
- **`deploy.publicOrigin`** is the public host baked into `ASTRO_SITE`
  (`resolveSiteUrl()` in the CLI + the workflow). Without it the workflow
  canonicalizes to `profile.url` — the CMS host — on every sub-domain site.
  It must be computed BEFORE `deploy-config`/build (it is used there first).

## Tier 1 — static (Class A, e.g. jayainc)

Flat `dist/` force-pushed to `site/<name>`, Velocity serves the branch root
with a no-op build. No runtime, no `/api`, no preview.

## Tier 2 — managed (Class B)

- **Managed fails closed.** With `runtime: managed`, mirror-push exits 1 when
  the deploy tree has no `server.js` — a branch that cannot boot is never
  published. Velocity runs Node (Entry `server.js`, PM2 needs the root entry),
  so the static-flat shape was an emission choice, not a platform limit.
- **Managed implies SSR.** Inferred in the scaffold; an explicit
  `--render static` throws *before* any writes.
- The scaffold emits **proxy endpoint routes** (`src/pages/api/[...path].ts`,
  `api/forms/submit.ts`, `media/[...path].ts`) plus a token-gated
  `src/pages/api/revalidate.ts`, `server.js`, and
  `scripts/sync-deploy-tree.mjs`; `astro.config.mjs` gains
  `cache: { provider: memoryCache() }` + `routeRules`.
- **Do not use `src/fetch.ts`.** A custom fetch handler replaces Astro's
  request pipeline: Astro then warns that the project's sessions, actions,
  middleware and cache handlers are NOT wired, so a managed site silently
  loses customer sessions and route caching. Endpoint routes run inside the
  normal pipeline — that is the shipped design.
- **Cache tags.** Bridge public GETs carry `Cache-Tag` / `Surrogate-Key`
  (collecton + `collection:<id>`). The SSR product route calls
  `Astro.cache.set({ tags: ['products', 'products:<id>'] })` **guarded by the
  generated `MANAGED` flag** (`src/lib/managed.generated.ts`) — calling
  `Astro.cache` in an ssr-without-managed build breaks, because there is no
  cache provider. `nvoos-forge-bridge` accumulates tags per content change and
  posts them to the site's `/api/revalidate` (settings: Revalidate endpoint +
  token), independently of the GitHub dispatch.
- **Preview deployments:** `workflow_dispatch` `preview: true` writes
  `site/<name>-preview`. **Smoke check:** opt-in `NVOOS_FORGE_SMOKE` performs a
  post-push 200 + `noindex` check (warn only). Both are env-vars, not new
  inputs.

## Phase 5 — local content + draft preview (shipped 2026-11-09)

**D4: local content is a WordPress CPT behind the bridge**, not a Git store.
Keystatic would duplicate the editing surface, the auth model and the audit
trail. The CPT is `nvoos_forge_local` (not `forge_local`: a bare slug collides
on a shared install, and new identifiers carry the `nvoos_` prefix).

**D6: preview tokens are bridge-verified and frontend-transported.**
`HMAC(wp_salt('auth'), 'nvoos-bridge-preview-v1.<post_id>.<exp>')`. The forged
app stores the token in a host-only cookie and never verifies it, so no key
material leaves WordPress and the frontend cannot mint a preview.

**D7: `/manage` is NOT bundled into the forge template.**
`addons/nvoos-astro-bridge/LICENSE` is proprietary and the template is a
separate distribution, so a compiled SPA bundle cannot be redistributed with
it. Editors use the CMS-hosted Astro CMS page; a future `/manage` route may
*load* the bundle from the licensed CMS host (no redistribution) once the auth
walkthrough (login + nonce through the managed proxy) is written and tested.

### The forge side

- **`source: 'local'`** on a collection reads `/nvoos-commerce/v1/local`
  through the same Payload envelope as `source: 'payload'`. `restBase` is
  optional — it resolves to the canonical local base (`collectionRestBase()`
  in the scaffold, `payloadRestBase()` in the loader). `optional: true`
  degrades a missing collection to an empty one (both branches).
- **Local documents are slug-authoritative.** `extractPayloadRaw()` prefers an
  explicit `entry.slug` and only falls back to `slugify(title)` — a URL must
  survive a title edit. Products carry no `slug`, so their behaviour is
  unchanged.
- **`/api/preview`** (`renderPreviewApiTs()`): `?token&slug` → validate the slug
  shape → set `nvoos_preview` (`Path=/`, `HttpOnly`, `SameSite=Lax`,
  `Secure` on https, `Max-Age=900`, **no `Domain`**) → 302 to
  `/preview/<slug>/` with `cache-control: no-store`.
- **`/preview/<slug>/`** (`renderPreviewPageTs()`): SSR only, emitted for
  `runtime: managed`. Reads the cookie, asks the bridge for
  `local?preview_token=…&where[slug][equals]=…`, renders with `sanitizeHtml`,
  and pins `Cache-Control: private, no-store` + `X-Robots-Tag: noindex,
  nofollow` on **every** path (including the 404s). Separate from the real
  content routes on purpose: a draft has no published URL.
- A `.astro` file emitted as text **must** start with the `---` frontmatter
  fence. Forgetting it produced 61 parse errors; the regression test asserts
  `src.startsWith('---\n')`.

### The bridge side

- `LocalController` extends `PostTypeController`: draft-first creates,
  governed statuses (`publish_posts` to go live), revisions, blocks + SEO
  write-through, `where[slug][equals]`.
- The document serves two consumers from one shape: the content document
  (title/content/excerpt/seo/status) **plus** the envelope aliases (`name`,
  `description`) and `slug`.
- Drafts 404 publicly (no existence leak). A valid `preview_token` unlocks
  exactly one document, by id or by slug filter.
- `BaseController::is_preview_request()` runs **before** the public-cache
  decision in both header filters — a cached draft would be served to every
  visitor. It checks the parameter's *presence*, not validity.
- `/manage` SPA: `local` joins `CollectionSlug`, the collection config
  (`previewable: true`) and `NAV`. The row Preview button mints the link and
  opens it (the tab is opened synchronously — `window.open` after an `await`
  is blocked as a popup).

## Scaffold hazards found in Phase 5 (all fixed)

1. **The generated drift manifest was hashed too early.** `nvoos.generated.json`
   was written at step 3a, before the managed block (3f) rewrote
   `src/lib/managed.generated.ts` → every managed site failed its own
   `npm run check` with a phantom "drifted" report. It is now
   `writeGeneratedManifest()` at step 3g, after every generated write.
2. **`frontPage` disappeared from `nvoos.json`** when a profile omitted it
   (`JSON.stringify` drops `undefined`), breaking `[slug].astro`,
   `sitemap.xml.ts`, `index.astro` and `redirects.json.ts` under `astro check`.
   Emitted as `''` now; the template already treats a falsy slug as "no front
   page".
3. **A `local` collection without `restBase` crashed the scaffold**
   (`tsString(undefined)` → TypeError). `collectionRestBase()` resolves it; the
   loader also throws a legible `no restBase configured` instead of requesting
   `/wp-json/undefined`.
4. **`manifest.frontPage` is not the only JSON-typed key with this failure
   mode.** Any optional profile key emitted verbatim (`foo: profile.foo`) can
   vanish and break the template's inferred manifest type. Emit a concrete
   fallback.

## Live verification recipe (managed, Windows-friendly)

```bash
# 1. profile (note: restBase omitted — resolved for source: 'local')
#    { url, name, collections:[{slug:'posts',source:'local',fields:[],kind:'post',optional:true}],
#      deploy:{runtime:'managed',revalidate:true,manage:true,forms:'bff-proxied'} }
node scripts/forge.mjs forge <profile> --target <dir> --preset blog
npm --prefix <dir> install --no-audit --no-fund     # npm run scripts lose PATH? use --prefix for install only
npm --prefix <dir> run check                        # 0 errors on a good profile
npm --prefix <dir> run build && npm --prefix <dir> run sync-deploy-tree
node scripts/wp-fixture-server.mjs &               # :8473
PORT=4398 NVOOS_TARGET_SITE=http://localhost:8473 node <dir>/.deploy/server.js &
curl -D - -o /dev/null 'http://localhost:4398/api/preview?token=x&slug=behind-the-scenes'
curl -H 'Cookie: nvoos_preview=x' http://localhost:4398/preview/behind-the-scenes/
```

`--overwrite` **deletes `node_modules`** in the target — install after the last
scaffold, not before. `astro check` needs the collections the template's
routes reference (`posts` **and** `pages`), or you get type errors that are
profile faults, not feature faults.

## Deploy-tree `build` script must be shell-safe

Velocity runs the mirror branch's `npm run build` through `sh -c`.
An unquoted `(` is a shell syntax error, so a stub emitted as
`node -e process.exit(0)` fails the whole deploy:

```
[stderr] sh: 1: Syntax error: "(" unexpected
[exit_code: 2]
```

while the forge build and the mirror push both report success. The emitted
value must be `node -e "process.exit(0)"`. The static stub already quoted its
argument; only the managed branch (`renderSyncDeployTree`, `mirrorPushScript`)
did not — now pinned by `tests/managed-runtime.test.ts` and
`tests/deploy-config.test.ts`.

## Deploy-tree manifest must declare the project's dependencies

The node adapter **externalizes** its runtime, so the built `dist/server/`
imports bare packages — on Virtually Jamaica: `astro`, `@oslojs/encoding`,
`clsx`, `cookie`, `devalue`, `html-escaper`, `htmlparser2`, `limax`, `mrmime`,
`piccolore`, `picomatch`, `sanitize-html`, `send`, `server-destroy`,
`unstorage`, `zod`. They are satisfied by the project's `dependencies` plus
their transitive closure, not by the build output.

A manifest without `dependencies` therefore produces an **unbootable artifact**:

```
npm install --omit=dev   → up to date, audited 1 package
node server.js           → ERR_MODULE_NOT_FOUND: Cannot find package '@oslojs/encoding'
```

Velocity fails that release and keeps the previous one serving, so the site
looks frozen while the workflow stays green — five successful mirror pushes on
2026-11-10 changed nothing on Virtually Jamaica.

Contract, now enforced:

1. `renderSyncDeployTree()` copies the project `dependencies` (and
   `package-lock.json` / `npm-shrinkwrap.json` / `.npmrc` when present) into
   `.deploy/package.json`, and **fails closed** when the project declares none.
2. It walks the build output's bare imports through the installed dependency
   closure and fails when one is not reachable — an under-declared manifest is
   caught at build time, with the package named.
3. `mirrorPushScript()` **merges** that manifest (normalizing
   `name`/`version`/`scripts`/`engines`) instead of writing a stub over it, and
   refuses a managed tree with no manifest or no `dependencies` *before* it
   clones the mirror.
4. `renderVerifyDeployTree()` emits `scripts/verify-deploy-tree.mjs`: it copies
   `.deploy/` to a temp dir, runs `npm install --omit=dev`, boots
   `node server.js`, and requires an HTTP answer (any status — the runner may
   not reach the CMS). `forge-sites.yml` runs it as a **blocking** gate before
   the push.

Pinned by `tests/managed-runtime.test.ts`, `tests/deploy-config.test.ts`,
`tests/deploy-tree.test.ts` (runs the emitted assembler against fixtures) and
the managed block of `tests/scaffold.test.ts`.
