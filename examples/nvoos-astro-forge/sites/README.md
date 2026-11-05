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
--mirror-repo … --site-branch site/<name>` → `npm install` + `ASTRO_SITE`
build → `node scripts/mirror-push.mjs` (force-pushes `dist/` to the site
branch).

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
```
