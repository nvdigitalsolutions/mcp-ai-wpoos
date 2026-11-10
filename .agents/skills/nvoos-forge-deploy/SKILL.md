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
| Managed artifact | `server.js` + `dist/client/` + `dist/server/` + `package.json` at root |

The profile splits two origins that are easy to conflate:

- `url` — the **CMS** host (`https://cms.client.com`). `WP_HOME`/`WP_SITEURL` point here.
- `deploy.publicOrigin` — the **public** host (`https://client.com`), baked into `ASTRO_SITE`.
  Omit it and every sub-domain site canonicalizes to the CMS host.

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
- Velocity settings: Framework `Astro-ssr` · Output Directory `.` ·
  Entry **`server.js`** (PM2 must start the repo-root entry) · Node 24.x.

## The step everyone forgets

**A mirror push does not redeploy the app.** The CI job force-pushes
`site/<name>` and exits green; Cloudways keeps serving whatever it last pulled.
Two deploys in this repo's history were "done" while the live site was hours old.

After a push, **pull** the app (Cloudways panel → Deployment via Git → Pull, or
the `git_pull` API action) and confirm the artifact changed. Three cheap tells,
in order of strength:

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

## Gotchas — each one cost a deploy

1. **Green mirror push, stale site.** Nothing auto-pulls. Check `last-modified`
   against the run time before believing a deploy.
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

## Where the rest lives

- Generation, scaffolds, gates, media localization → `mcp-ai-wpoos-astro-forge`
  (`reference/performance-budget.md`, `reference/proposal-135-managed-tier.md`).
- PR/merge discipline for this repo → `mcp-ai-wpoos-git-ops`.
- The bridge plugin side (`nvoos-forge-bridge`, cache tags, preview tokens) →
  `mcp-ai-wpoos-astro-storefront`.
