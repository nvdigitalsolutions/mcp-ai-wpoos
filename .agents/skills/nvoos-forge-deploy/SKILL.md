---
name: nvoos-forge-deploy
description: Deploy and operate forged Astro sites on Cloudways Velocity — the mirror-branch contract (site/<name>, artifact at the repo root, force-pushed), the static vs managed tiers and their deploy.json, Velocity app settings (Output Directory, build command, Entry server.js), the pull-after-mirror-push step, and the WAF / shell-safe build traps. Use when a forge deploy fails or a site serves a stale artifact, when wiring a new Velocity app to a mirror branch, or when debugging mirror-push, site/<name> branches or managed deploy trees.
---

# NV oOS Forge — deploy operations (Cloudways Velocity)

The generator is documented by `mcp-ai-wpoos-astro-forge`; the **deploy** half is
spread thin there. This skill is the operator's view: what the artifact must
look like, what the Velocity app must be told, and the three things that fail
silently in between.

Deep design notes: `.agents/skills/mcp-ai-wpoos-astro-forge/reference/proposal-135-managed-tier.md`
and `docs/project/proposals/135-forge-managed-tier-domain-topology-{proposal,implementation-plan}.md`.

## The contract

One repo hosts every client site; one branch per site.

| Piece | Value |
|---|---|
| Mirror repo (default) | `nvdigitalsolutions/nvoos-forge-sites` (`vars.NVOOS_FORGE_MIRROR_REPO`) |
| Production branch | `site/<name>` |
| Preview branch | `site/<name>-preview` (`workflow_dispatch` `preview: true`) |
| Artifact root | **repo root** — the branch is the publish root, not a subfolder |
| `deploy.json` | `{ runtime, entry, outputDirectory: '.', generator, proposal: 135, generatedAt }` |
| Static artifact | flat `dist/` at root: `index.html`, `_astro/`, `media/`, `_redirects`, `_headers`, plus the no-op `package.json` |
| Managed artifact | `server.js` + `dist/client/` + `dist/server/` + `package.json` at root — the manifest MUST carry the project `dependencies` (+ lockfile), or Velocity installs nothing |

The profile splits two origins that are easy to conflate:

- `url` — the **CMS** host (`https://cms.client.com`). `WP_HOME`/`WP_SITEURL` point here.
- `deploy.publicOrigin` — the **public** host (`https://client.com`), baked into `ASTRO_SITE`.
  Omit it and every sub-domain site canonicalizes to the CMS host.

### Staging on a Cloudways app host (2026-11-10)

Before DNS cutover the build is served on `<app>-<id>.cloudwaysnodeapps.com`.
Canonicals must follow the host it is ACTUALLY served on, so `ASTRO_SITE` is a
per-environment value, not a profile constant:

```sh
WP_SITE_URL=https://client.com                  # content source — unchanged
ASTRO_SITE=https://<app>-<id>.cloudwaysnodeapps.com   # public origin of THIS deploy
NVOOS_FORGE_NOINDEX=1                           # staging must not be indexed
```

- A staging host that self-canonicals **must not be indexable** — otherwise Google
treats the preview domain as a duplicate of production. `NVOOS_FORGE_NOINDEX=1`
emits `<meta name="robots" content="noindex, nofollow">` and `Disallow: /`.
It is read at render time, so the same artifact is indexable in production when
unset.
- Leave `deploy.publicOrigin` at **production**; set the staging origin per
environment (`FORGE_SITE_URL` in the emitted pipeline, or the app's env vars).
One profile, two deploys.
- **Cutover order:** set `ASTRO_SITE` to production, drop `NVOOS_FORGE_NOINDEX`,
rebuild, publish, then repoint DNS. Reversing it indexes the staging host first.
- Verify after any build: `canonical`, `og:url` and `sitemap <loc>` must all name
the host in the address bar, and `robots.txt` must match the intended posture.

## Tier 1 — static (`deploy.runtime` absent or `"static"`)

Flat `dist/` plus a **no-op `package.json`** stub, because Velocity always runs a
build even though nothing needs building. Deployment settings:

- Framework `Astro-csr` · Output Directory `.` · Build `npm run build` (the no-op) · Root `./`

No runtime, no `/api`, no preview route. Media localization (`media.mode`) lives
under `dist/media` — see the astro-forge `reference/performance-budget.md`.

## Tier 2 — managed (`deploy.runtime: "managed"`)

- **Fails closed.** `mirror-push` exits 1 when the tree has no `server.js`, so a
  branch that cannot boot is never published.
- **Implies SSR.** An explicit `--render static` against a managed profile throws
  before any file is written.
- Ships endpoint routes (`api/[...path].ts`, `api/forms/submit.ts`,
  `media/[...path].ts`, token-gated `api/revalidate.ts`), `server.js`, and
  `scripts/sync-deploy-tree.mjs`; `astro.config.mjs` gains `memoryCache` + `routeRules`.
- **Never `src/fetch.ts`.** A custom fetch handler makes Astro skip sessions,
  actions, middleware and cache — the site silently loses logins and caching.
- **Ships a real dependency manifest.** `sync-deploy-tree.mjs` copies the
  project `dependencies` and `package-lock.json` into `.deploy/package.json`, and
  `mirror-push` refuses a managed tree that declares none. The node adapter
  externalizes its runtime (`cookie`, `devalue`, `html-escaper`, `piccolore`,
  `send`, `server-destroy`, `unstorage`, `@oslojs/encoding`, …), so a
  dependency-free manifest is an unbootable artifact.
- **`verify-deploy-tree` is a blocking gate.** It copies `.deploy/` to a temp
  dir, runs `npm install --omit=dev`, boots `node server.js`, and requires an
  HTTP answer (any status — the runner may not reach the CMS). `forge-sites.yml`
  runs it before the push, so an unbootable tree never reaches the branch.
- Velocity settings: Framework `Astro-ssr` · Output Directory `.` ·
  Entry **`server.js`** (PM2 must start the repo-root entry) · Node 24.x.

## The step everyone forgets

**A mirror push does not always redeploy the app — check `auto_deploy` first.**
`velocity_vcs_app_repo_get` reports the linked repo/branch and its `auto_deploy`
flag:

- `auto_deploy: true` → the push fires a Velocity **webhook deployment**
  automatically. Do not pull again; poll `velocity_deployment_history_list` until
  the newest entry matches the pushed commit and reads `success` (2026-11-14:
  jayainc auto-deployed in 10–14 s). `operation_status` may return **403** for a
  Velocity operation id from the MCP — the deployment history is the reliable
  poll.
- `auto_deploy: false` → the CI job force-pushes `site/<name>` and exits green;
  Cloudways keeps serving whatever it last pulled. **Pull** the app (Cloudways
  panel → Deployment via Git → Pull, or the `git_pull` API action).

Either way, confirm the artifact changed. Three cheap tells, in order of
strength:

```bash
curl -sI "https://<app-host>/?cb=$(date +%s)" | grep -i last-modified   # compare to the run time
curl -s  "https://<app-host>/?cb=$(date +%s)" | grep -c '/media/'       # 0 means pre-localizer build
curl -s  "https://<app-host>/" | grep -o 'name="generator" content="[^"]*"'
# <meta generator="WordPress …"> means the DOMAIN still points at WordPress,
# not the app — a DNS/topology question, not a deploy one.
```

Read the app root, not the domain: `deploy.publicOrigin` may equal `url`, so
`https://<domain>/` can still be WordPress while the forged app runs on
`https://<something>.cloudwaysnodeapps.com/`.

### Locked / hand-edited sites

A locked site (`profile.locked` + `forge.lock.json`) is **not** rebuilt by
`forge-sites.yml`, and merging a profile PR does **not** deploy it. The live
source is the gitignored `<site>-frontend` project: edit it, rebuild it, then
force-push `dist/`.

**Locked with no tree (2026-11-15).** If the profile is locked and no
`<site>-frontend` exists anywhere, nobody can rebuild: CI skips, `scaffold`
refuses, and the only remaining artifact is the mirror branch — built output
with no source. `locked` is an ownership record, not a "site is finished" flag.
Recover the content first (`forge materialize`; see the astro-forge reference
`cms-removal-materialized-content.md`) or `forge unlock … --yes` deliberately.

**Returning a CMS-less site to the forge.** `forge unlock` needs `--target
<project>`, and a site handed off with no surviving tree (the mirror branch is
the only artifact) has none. Clear the flag on the profile instead — that is
exactly what `unlock` does to it after `removeLock`:

```sh
node -e 'const fs=require("fs");const p="sites/<name>.json";const o=JSON.parse(fs.readFileSync(p,"utf8"));delete o.locked;fs.writeFileSync(p,JSON.stringify(o,null,"\t")+"\n");'
```

Rehearse the merge, not just the edit: a push touching `sites/**` rebuilds
**every** profile, and the site’s own merge re-forges it with a cold media
cache. With `content.source: materialized` the CMS is gone, so which
`/wp-content/` derivatives 404 changes build to build (the CDN copy expires
piecemeal). Content itself is local-only (the loader never fetches, so the
build cannot die on the dead origin); `verify --minimal` still passes — check
that no `<img>` loses its last local `srcset` candidate before merging.

## Hand deploy without CI (2026-11-15)

`forge-sites.yml` is the contract; a hand deploy must reproduce it in order:

```sh
node scripts/forge.mjs sites/<name>.json --target <dir> [--content <dir>]
node scripts/forge.mjs deploy-config sites/<name>.json --target <dir> \
  --to velocity --mirror-repo <owner>/<repo> --site-branch site/<name> \
  --runtime <managed|static> --site-url <publicOrigin>
( cd <dir> && npm install && ASTRO_SITE=<publicOrigin> npm run build )
# demo guard: dist/posts/hello-world must NOT exist
node scripts/forge.mjs verify sites/<name>.json --target <dir> --minimal
( cd <dir> && node scripts/sync-deploy-tree.mjs && node scripts/verify-deploy-tree.mjs )
( cd <dir> && SOURCE_DIR=.deploy RUNTIME=managed SITE_BRANCH=site/<name> \
    MIRROR_URL=https://github.com/<owner>/<repo> MIRROR_TOKEN=<token> \
    node scripts/mirror-push.mjs )
```

- `mirror-push` **refuses** (exit 1) without `MIRROR_URL`/`MIRROR_TOKEN`;
  `FORGE_ALLOW_MIRROR_SKIP=1` is the explicit opt-out. Before that change it
  exited 0, so a hand deploy looked identical to a successful publish.
- `MIRROR_TOKEN` is a repo-scoped token; locally `gh auth token` works.
- **Check the artifact before pushing**: `verify-deploy-tree.mjs` is the gate.
  A managed tree that cannot boot is not a deploy.
- After a cutover the profile's `url` is no longer a CMS. Declare it gone
  (`content.source: 'materialized'` + a committed `sites/<name>.content/`):
  the scaffold then wires every collection local-only and the build never
  fetches. Without that flag every build dies at `Endpoint not found:
  wp/v2/pages` — see the astro-forge reference
  `cms-removal-materialized-content.md`.

**Never rebuild an EXISTING generated project as the deploy step (2026-11-16).**
`npm run build` re-renders that project's own `src/content` and generated
modules; both freeze at scaffold time. A tree scaffolded before a content or
profile fix merged ships the OLD site — a hand deploy of virtuallyjamaica that
ran only `npm run build` silently reverted #7832 (hardware heading above its
subtitle, footer 2023→2026, hero mobile centring, the carousel lightbox). No
gate failed: the build was internally consistent, it was just old.

The same stale tree carried the Cloudflare **test** site key
(`1x00000000000000000000AA`) in `nvoos.json`, because the manifest is baked at
scaffold time too. Patching that one value made the deploy look right while
every other reverted fix stayed reverted — a single wrong value in a generated
tree is a symptom of staleness, not the bug.

- Scaffold into a FRESH target for every hand deploy
  (`--target <new dir> --content sites/<name>.content`). Re-forging is offline
  (the media cache is local) and costs minutes, not a re-analysis.
- Building in place is only safe when you first re-copy the changed SOURCE —
  `sites/<name>.content/**` into `src/content`, and the generated modules
  (`src/lib/*.generated.ts`, `nvoos.json`) — never assume `npm run build`
  re-derives them.
- Prove freshness in the artifact, not in a status code: grep the built HTML
  for the exact string the commit changed (a reordered heading, a footer year)
  before `mirror-push`.
- Stale generated trees left on disk (`<name>-frontend/`, an old
  `tmp/<…>-out/`) are how this recurs — delete them once a newer target exists.

Hand-edited projects may not carry `scripts/mirror-push.mjs` (the scaffold owns
it). Emit it without touching the source:

```bash
node scripts/forge.mjs deploy-config sites/<name>.json --target ./<name>-frontend \
  --to velocity --mirror-repo nvdigitalsolutions/nvoos-forge-sites \
  --site-branch site/<name> --runtime static
```

`deploy-config` only writes `.github/workflows/{deploy,rebuild}.yml`,
`scripts/verify-dispatch.mjs` and `scripts/mirror-push.mjs`.

Run the push from the frontend:

```bash
MIRROR_URL=https://github.com/nvdigitalsolutions/nvoos-forge-sites.git \
MIRROR_TOKEN=<repo-scoped token> SITE_BRANCH=site/<name> RUNTIME=static \
SOURCE_DIR=./dist node scripts/mirror-push.mjs
```

Never echo the token. For an operator run, read it with `gh auth token` inside a
small wrapper (`execFileSync('gh', ['auth','token'])`) and pass it via env.

Then follow the `auto_deploy` branch above. Verify the live page with a
cache-buster (`?cb=deploy-<sha>`); `last-modified` should match the deploy and
`cf-cache-status` is normally `DYNAMIC` (no purge needed). Current example:
jayainc app `6771968` on server `1685799`, branch `site/jayainc`.

## Post-deploy checklist (a CMS-less site)

The build being green is not the check — the live artifact is. Run these against
the public origin after the deploy settles:

```bash
# 1. Identity: the live page must carry THIS build's marker.
node scripts/forge.mjs deployed sites/<name>.json --target <dir> \
  --expect-build "$FORGE_BUILD_ID" --strict
# 2. Icon fonts: every glyph family the page uses has a usable face.
node scripts/forge.mjs verify sites/<name>.json --target <dir> --minimal --fonts
# 3. First viewport (manual/Playwright): no blank icon boxes, the paragraph
#    ink matches bandFacts, and no <img> is a 404. A media snapshot (below)
#    is what keeps step 3 true as the old CDN derivatives expire.
```

- **Media.** A CMS-less profile should carry `media.snapshot: true` and a
  `sites/<name>.media/` snapshot (`forge media-snapshot <profile> --from
  <built-project>`). Re-run it whenever new media ships; the build then reads
  the snapshot before the network and a dead derivative cannot silently
  un-ship a font or photo.
- **Forms.** The PUBLIC Turnstile site key lives in `forms.turnstile.siteKey`;
  the paired secret must be set in the **Velocity app environment** as
  `TURNSTILE_SECRET` (never the profile/repo). After a cutover or key rotation,
  check the widget renders and submit one real enquiry — a key/secret mismatch
  is a runtime `siteverify` rejection, not a build failure.
- **Build id.** `FORGE_BUILD_ID` is baked into every page as
  `<meta name="forge-build">` and into `deploy.json`; CI sets it from
  `${GITHUB_SHA}-${GITHUB_RUN_ID}`. The monorepo smoke runs `forge deployed`
  post-push; the generated pipeline emits `scripts/verify-deployed.mjs` as a
  non-blocking step.

## CI — `.github/workflows/forge-sites.yml`

Triggers: a push to `alpha-working` touching `sites/**` (or forge libs/templates),
`workflow_dispatch` with an optional `site`, and `repository_dispatch`
`nvoos-forge-content-changed` from the bridge (HMAC-verified before anything runs).

Runs on the **self-hosted Windows runner** (`[self-hosted, forge-builder]`) with a
fixed IP, because bot-protected CMSs 403 GitHub's datacenter ranges.

Secrets: `NVOOS_FORGE_MIRROR_URL`, `NVOOS_FORGE_MIRROR_TOKEN`, `FORGE_WEBHOOK_SECRET`.
Vars: `NVOOS_FORGE_MIRROR_REPO`, `NVOOS_FORGE_SMOKE`. Unset mirror secrets degrade
to build-only and the job stays green — a green run does **not** imply a push.

Per site the loop is: `forge.mjs <profile> --target <dir>` → `deploy-config --to
velocity --runtime <tier> --site-url <publicOrigin>` → `npm install` → `npm run build`
→ **blocking** `forge verify --minimal` → full scorecard (warn-only) → demo-content
guard (`dist/posts/hello-world` must not exist) → `mirror-push`.

A failing **blocking** gate aborts before publish; the full scorecard never blocks.

## Verify the branch itself

```bash
# managed
gh api -H "Accept: application/vnd.github.raw" \
  "repos/nvdigitalsolutions/nvoos-forge-sites/contents/dist/client/index.html?ref=site/<name>" > /tmp/i.html
# static
gh api -H "Accept: application/vnd.github.raw" \
  "repos/nvdigitalsolutions/nvoos-forge-sites/contents/index.html?ref=site/<name>" > /tmp/i.html

grep -c '/media/' /tmp/i.html            # localized media present?
grep -c 'wp-content/uploads' /tmp/i.html # should be anchors only (documents/images by design)
grep -o 'fonts.googleapis.com[^"]*' /tmp/i.html  # should be empty: the sheet is self-hosted
ls dist media server.js deploy.json      # shape check
```

List the tree instead of guessing: `gh api "repos/.../contents?ref=site/<name>"`.

## Cloudflare rate limiting on a forged site (2026-11-16)

An app-side form gate (Turnstile, duration-of-fill, payload provenance) sees one
request at a time; a distributed spam run is only slowed at the edge. Measured
constraints on the zone that fronts a forged contact form:

- **Free plan: 1 rule, `Block` only**, `period` and `mitigation_timeout` both
  **10 s** (the API rejects the longer values Pro+ allows). Budget the single
  slot for the form endpoint, not the whole site.
- The legacy `/zones/{id}/rate_limits` API is **410 Gone** (`This API has been
deprecated. Please use the Rulesets API instead.`). The only path is the
  rulesets phase entrypoint:

  ```sh
  PUT /zones/{id}/rulesets/phases/http_ratelimit/entrypoint   # create/update
  GET /zones/{id}/rulesets/phases/http_ratelimit/entrypoint   # 404 = none yet
  ```

  Rules carry `ratelimit: { characteristics, period, requests_per_period,
  mitigation_timeout }`; `dry_run=true` validates without persisting.
- **Read access is not write access.** A Cloudflare MCP connection can `GET`
  the phase (and returns 404 `could not find entrypoint` when none exists) but
  the `PUT` needs **`account-waf.write`** or **`account-rulesets.write`** —
  without it every attempt is a `403 Authentication error` that lists the
  accepted scopes. Confirm the scope before planning an apply; reconnecting is
  a browser OAuth step the operator has to do.
- Shape that fits a forged contact form (POST-only, per IP):

  ```text
  expression: (http.request.method eq "POST" and http.request.uri.path eq "/api/forms/submit")
  characteristics: ["ip.src"]   period: 10   requests_per_period: 5   mitigation_timeout: 10
  ```

A rate rule does not replace the app gate and vice versa: the rule caps a
flood, the token/provenance checks stop a single crafted request.

## Gotchas — each one cost a deploy

1. **Green mirror push, stale site.** With `auto_deploy: false` nothing pulls.
   With `auto_deploy: true` the push triggers a webhook deploy — poll
   `velocity_deployment_history_list`, not `operation_status` (403 for Velocity
   ops from the MCP). Either way, check `last-modified` against the deploy time.
   **`last-modified` alone is not proof of the build you think is live.** On
   2026-11-15 virtuallyjamaica served a release older than the checked-in
   template: the local build emitted `[data-section-id] p{…color:rgb(255,255,255)}`
   and the live page omitted it, so the red band's copy rendered gray. The code
   was already correct — only the deploy was old. Verify a build-only marker
   (a stamped `<meta name="forge-build">`, a rule/asset introduced by the same
   commit, or the mirror branch's `deploy.json` build id), not just the clock.
   When the app has kept the last good release, a failed/partial deploy looks
   identical to a successful old one from the outside.
2. **`sh: 1: Syntax error: "(" unexpected` (rc=2).** Velocity runs `npm run build`
   through `sh -c`; a stub emitted as `node -e process.exit(0)` is invalid shell.
   It must be `node -e "process.exit(0)"`. The forge build and the push both stay
   green while every deploy fails.
3. **403 at content sync, mirror never reached.** A WAF, not credentials. The
   response *body* names it (`MalCare … Blocked because of Malicious Activities`,
   with a reference id). Clearing a block lets one run through — allowlist the
   runner's IP permanently or it re-arms.
4. **Hand edits to a mirror branch are destroyed.** Branches are force-pushed on
   every build; anything needed must come from the emission.
5. **`forge.mjs --overwrite` deletes `node_modules` in the target** — install after
   the last scaffold, not before.
6. **Static profile, managed tree (or vice versa).** `deploy.runtime` flows profile
   → `deploy-config --runtime` → `mirror-push`; a managed tree without `server.js`
   refuses to publish rather than booting into a 502.
7. **Canonicals pointing at the CMS host.** `deploy.publicOrigin` was omitted, so
   `ASTRO_SITE` fell back to `url`.
8. **The domain is still WordPress** (`<meta generator="WordPress …">`). That is
   DNS/topology, not the deploy: the app can be perfect on its
   `cloudwaysnodeapps.com` host while the public domain has not been cut over.
9. **A staging host that self-canonicals without `noindex`.** The canonical,
   `og:url` and sitemap all point at the preview domain (correct) — but the
   preview is then indexable as a duplicate of production. Always pair the two.
10. **Re-forging a site that a developer now owns.** Two guards, both fail-closed:
    `scaffold` refuses when the TARGET carries `forge.lock.json`, or when the
    PROFILE carries `locked` (the CI case — the runner scaffolds into a fresh temp
    dir, so only the committed profile can carry the record). `forge-sites.yml`
    reads `profile.locked` per site and SKIPS it with a `::warning` plus a
    run-summary line, so one locked site never aborts the others; the
    `workflow_dispatch` input `force_unlock: true` builds anyway (destroys
    hand-written work — abandoned or mistaken lock only). A skipped site means a
    WP content-change dispatch did NOT deploy: that is the handoff, not a failure.
    Full contract: astro-forge `reference/handoff-lock-and-band-gates.md`.
11. **A managed tree that installs nothing.** `package.json` with no
    `dependencies` → Velocity's `npm install` audits one package → `node
    server.js` throws `ERR_MODULE_NOT_FOUND` → the release never promotes and
    the app keeps serving the last good build, so the site freezes while every
    push stays green. This cost a day on Virtually Jamaica (2026-11-10).
    Tell: the branch's root `package.json` must contain `"dependencies": {…}`,
    and `npm run verify-deploy-tree` must print `booted (HTTP 200)`.
    Boot locally: `git clone -b site/<name> … && npm i --omit=dev && node
    server.js` — it must not throw at import time.
12. **"All the images are missing" is an origin mismatch until proven otherwise.**
    Every forged image is root-relative `/media/<hash>-<name>`. If the text renders
    and 100% of images break, the Astro document is being served from a host that
    does not have `media/` — nearly always the WordPress origin. One request
    settles it:
    `curl -o /dev/null -w '%{http_code}' https://<host-in-the-address-bar>/media/<any>.webp`.
    A 404 returning `text/html` means WordPress answered. Do not rebuild — fix the
    topology (gotcha 8). Also rule out a cached Astro document in the browser
    before believing the deploy is broken: `curl` the same URL fresh and compare;
    if `curl` gets WordPress HTML and the browser shows Astro, it is browser cache.
    Audit in this order — `<img>` srcs, CSS `url()` refs, then every network
    request — before touching the build; the first two can both be clean while a
    third cause (a 403 route, a WAF) is the real failure.
13. **A route directory holding only `index.md` answers 403, not 404.** The build
    can emit `<route>/index.md` (the LLM-facing markdown twin) beside
    `index.html`, but the profile's `frontPage` source page is rendered at `/` —
    so its own directory keeps only the `.md` and nginx refuses the directory
    outright. It surfaces as a console `403` on pages that link or prefetch it
    and looks like a broken asset. Exclude that directory from the artifact; it
    is a content source, not a route.
14. **Absolute links to the CMS host survive `ASTRO_SITE`.** Author-written
    `href="https://client.com/x/"` anchors are *content*, not generated URLs, so
    a staging build still bounces testers onto production. Count them before
    blaming the profile:
    `grep -o 'href="https://<production-host>[^"]*"' dist/index.html | sort -u`.
    The fix belongs in sanitisation (same-origin absolute → path), so every site
    becomes host-independent at once.

## Mirror-push mechanics (verified 2026-11-24)

- **`forge deploy --mirror-repo` is self-sufficient.** It emits
  `.github/workflows/{deploy,rebuild}.yml`, `scripts/{mirror-push,verify-deployed,verify-dispatch}.mjs`
  and then runs `mirror-push` itself — so a project scaffolded WITHOUT deploy
  options can still be mirrored. No re-scaffold, no missing-script error.
- **The token has three homes, in this order:** `GITHUB_TOKEN` env → the vault →
  fail. A green `gh auth status` does NOT help: `gh` never exports its token and
  the forge will not shell-substitute one into place, so a logged-in CLI still
  refuses the deploy.
- **The vault needs a key.** `NVOOS_FORGE_VAULT_KEY`, or the documented
  `NVOOS_FORGE_VAULT_PLAINTEXT=1` fallback. **A vault written in plaintext mode
  must be opened in plaintext mode** — `vault list` and `vault delete` otherwise
  throw `VaultError: Vault file … was written in plaintext mode`. A one-off
  plaintext push therefore leaves a credential that cannot be removed unless you
  remember the flag; re-run the delete with it and confirm the file
  (`~/.nvoos-forge/vault.json`) holds no `gh*_` prefix afterwards.
- **`deploy.json` ships `buildId: ""`** unless `FORGE_BUILD_ID` is set at BUILD
  time. The deployed-smoke then has no identity to compare against, so a stale
  artifact passes as fresh. Set it in CI, never by hand.
- **Never invent a `*.cloudwaysapps.com` canonical.** Those hostnames belong to
  specific apps — `eca-6797003…`, for instance, is the **Laravel runtime**, not
  whatever site is being forged. A guessed host bakes wrong `canonical`/`og:url`
  into every page. With no public origin yet, canonicalise to the eventual
  production domain and pair it with `NVOOS_FORGE_NOINDEX=1` (pitfall 9).

## Where the rest lives

- Generation, scaffolds, gates, media localization → `mcp-ai-wpoos-astro-forge`
  (`reference/performance-budget.md`, `reference/proposal-135-managed-tier.md`).
- PR/merge discipline for this repo → `mcp-ai-wpoos-git-ops`.
- The bridge plugin side (`nvoos-forge-bridge`, cache tags, preview tokens) →
  `mcp-ai-wpoos-astro-storefront`.
