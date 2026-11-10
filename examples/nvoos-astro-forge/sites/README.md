# Forge sites — committed site profiles

One `.json` profile per client site. The profile is the ONLY committed
artifact of a forged site: the generated Astro project stays disposable
and is never committed (built assets and `node_modules` are gitignored
by the generated project itself).

## Convention

| Item | Rule |
|---|---|
| Profile | `sites/<name>.json` — `<name>` is lowercase, hostname-derived (e.g. `jayainc`) |
| Mirror branch | `site/<name>` on the shared mirror repo `nvdigitalsolutions/nvoos-forge-sites` (private) |
| Branch content | The built `dist/` at the branch ROOT (force-pushed, flat history — never commit there directly) |
| Velocity app | Connect the mirror repo, Branch = `site/<name>`, Root directory = repo root |
| Runtime tier | `deploy.runtime` — `static` (default, flat files) or `managed` (adds a Node entry + BFF), Proposal 135 |

## The pipeline

`.github/workflows/forge-sites.yml` (monorepo) rebuilds every affected
site:

- `push` to `alpha-working` touching `sites/**` (or the forge libs/templates)
- `workflow_dispatch` with an optional `site` input
- `repository_dispatch` of `nvoos-forge-content-changed` (the
  `nvoos-forge-bridge` plugin fires this on content changes; the payload's
  optional `site` field narrows the run, absent = all sites; the HMAC proof
  is verified before anything builds)

Per site the workflow: scaffold → `deploy-config --to velocity
--mirror-repo … --site-branch site/<name> --runtime <tier>` → `npm install`
+ `ASTRO_SITE` build → (`managed` only) `sync-deploy-tree` + the
**blocking** `verify-deploy-tree` boot gate → `node scripts/mirror-push.mjs`
(force-pushes `dist/` to the site branch).

## Runtime tiers (Proposal 135)

| Tier | Profile block | Branch content | Velocity settings |
|---|---|---|---|
| `static` (default) | absent, or `"deploy": { "runtime": "static" }` | prebuilt flat files + a no-op `package.json` + `deploy.json` | Output directory `.` |
| `managed` | `"deploy": { "runtime": "managed", "manage": true, "revalidate": true, "forms": "bff-proxied" }` | the BFF deploy tree (`dist/` + `server.js` + runtime `package.json` **with the project `dependencies` and lockfile**) + `deploy.json` | Entry file `server.js`, Output directory `.` |

`mirror-push.mjs` writes a `deploy.json` manifest at every branch root so
the Velocity settings are reproducible from the branch itself. A `managed`
profile **fails the mirror push closed** when the deploy tree has no
`server.js` **or no `dependencies`** — a branch that cannot boot is never
published. The node adapter externalizes its runtime (`cookie`, `devalue`,
`send`, `unstorage`, …), so an empty manifest installs nothing and
`node server.js` dies at import time; Velocity then keeps serving the last
good release while CI stays green. `scripts/verify-deploy-tree.mjs` proves
the tree boots (`npm install --omit=dev` + `node server.js` + an HTTP answer)
before the push.

A `managed` profile implies server output (an explicit `--render static`
throws) and emits the proxy routes (`src/pages/api/[...path].ts`,
`api/forms/submit.ts`, `media/[...path].ts`), the token-gated
`api/revalidate.ts`, `server.js`, `scripts/sync-deploy-tree.mjs` and
`scripts/verify-deploy-tree.mjs`; the
emitted `astro.config.mjs` carries `cache: { provider: memoryCache() }` +
`routeRules`. Set `NVOOS_TARGET_SITE` (the CMS origin), `NVOOS_REVALIDATE_TOKEN`,
and `ASTRO_SITE` in the Velocity app.

## Domains (Proposal 135)

WordPress is never the public origin. The profile `url` is the **CMS** host
(`https://cms.client.com`); the public site is `deploy.publicOrigin` (defaults
to `url`), which the CLI and the workflow bake into `ASTRO_SITE`
(`https://client.com`) and mirror on the branch. Set `WP_HOME` / `WP_SITEURL` to
the CMS host, keep it `noindex`, and allowlist the builder (static tier) or the
Node server (managed tier).

POST `{ tags }` to the site's `/api/revalidate`. The token must equal the site's
`NVOOS_REVALIDATE_TOKEN`. Preview deployments: run the workflow manually with
`preview: true` — it writes `site/<name>-preview` and leaves production alone.
Post-push smoke is opt-in via the repo variable `NVOOS_FORGE_SMOKE` (warns on a
non-200 or a `noindex` on the public origin; never fails the job).

## Local content + draft preview (Proposal 135 Phase 5)

Content the site **owns** (not mirrored from the source WordPress) lives in the
bridge's local CPT and is read with a normal collection — `restBase` is optional
for it:

```json
{ "slug": "posts", "source": "local", "kind": "post", "optional": true }
```

`optional: true` turns a missing collection into an empty one instead of failing
the build. Local documents are slug-authoritative: renaming the title does not
move the URL.

A `managed` site also gets `/api/preview` and `/preview/<slug>/`. The `/manage`
SPA mints the link (`POST /local/<id>/preview-token`), the forged app stores the
token in a host-only cookie, and the bridge verifies it — the signing key never
leaves WordPress, the link expires in 15 minutes, and the preview is
`noindex` + `no-store`. Set **Settings → Domains → Public site origin** on the
bridge or the mint returns no `previewUrl`.

The forged app does **not** ship `/manage` (Proposal 135 D7: the bridge SPA is
proprietary and this template is a separate distribution). Editors use the CMS,
or the template's collections still read the bridged content.

## Required repo secrets (monorepo)

| Secret | Purpose |
|---|---|
| `NVOOS_FORGE_MIRROR_URL` | `https://github.com/nvdigitalsolutions/nvoos-forge-sites.git` |
| `NVOOS_FORGE_MIRROR_TOKEN` | Repo-scoped deploy token (contents:write on the mirror) |
| `FORGE_WEBHOOK_SECRET` | Shared HMAC secret with `nvoos-forge-bridge` on the client WP sites |

Repo variable: `NVOOS_FORGE_MIRROR_REPO` = `nvdigitalsolutions/nvoos-forge-sites`
(not a secret — it is baked into the emitted deploy config).

Unset mirror secrets degrade gracefully: the mirror push skips with a
warning and the workflow stays green (build-only mode).

## Operator staging path (local, no CI)

```bash
node scripts/forge.mjs deploy sites/jayainc.json --target ./jayainc-frontend \
  --to velocity --mirror-repo nvdigitalsolutions/nvoos-forge-sites --site-branch site/jayainc
# …with GITHUB_TOKEN set and dist/ already built in the target.
# Managed tier: add --runtime managed (requires the BFF deploy tree — see
# Proposal 135 Phase 2 before pointing traffic at it).
```
