# Shared-mirror deploy (branch per site, 2026-11-06)

Extracted from the skill body on 2026-11-26 to stay under Zed load limit.


Owner decision: forged client sites deploy through ONE private mirror repo
(`nvdigitalsolutions/nvoos-forge-sites`) — one `site/<name>` branch per
client, built `dist/` at the branch ROOT (force-pushed, flat; `main` holds
only the README clone anchor). Velocity apps connect the mirror and select
Branch = `site/<name>` (Root directory = repo root — Velocity supports
per-app Branch + Root Directory per the official deployment docs). Never
commit generated sites into the monorepo: `sites/<name>.json` profiles are
the only committed artifact per site.

- **Forge:** `deploy`/`deploy-config` gain `--mirror-repo <owner/repo>` +
  `--site-branch <branch>` (paired, validated). Mirror mode emits
  `scripts/mirror-push.mjs` next to the workflows (single template in
  `lib/deploy-config.ts` `mirrorPushScript()` — orphan-branch checkout +
  force push; the workflow and the CLI run the SAME emitted file, no
  drift). `deploy --mirror-repo` skips repo creation and mirrors a prebuilt
  `dist/` (operator staging path; needs `GITHUB_TOKEN`). Legacy velocity
  shape (per-site repo, `deploy` branch under `site/`) is unchanged.
- **Monorepo pipeline:** `.github/workflows/forge-sites.yml` — push on
  `sites/**`/templates/libs, `workflow_dispatch` (`site` input), and
  `repository_dispatch` `nvoos-forge-content-changed` (bridge-fired; HMAC
  verified by the committed `.github/forge/verify-dispatch.mjs`, generated
  by `emitDeployConfig`). Per site: scaffold → deploy-config → build →
  mirror-push. Unset `NVOOS_FORGE_MIRROR_*` secrets degrade to build-only
  (never a red job). Needs repo variable `NVOOS_FORGE_MIRROR_REPO` +
  secrets `NVOOS_FORGE_MIRROR_URL`/`_TOKEN`, `FORGE_WEBHOOK_SECRET`.
- Full runbook + research: `docs/project/proposals/114-shared-mirror-deploy-addendum.md`.
- **Adding a client site:** seed the branch on the mirror —
  `gh api repos/nvdigitalsolutions/nvoos-forge-sites/git/refs -f ref=refs/heads/site/<name> -f sha=<main sha>`
  (read the sha via `gh api …/git/refs/heads/main --jq .object.sha`) — commit
  `sites/<name>.json`, merge → the workflow mirrors on the next run. The
  mirror's `main` README lists the convention (update it via the
  `contents` API PUT — base64 content + the current file `sha`).
- **Operational learnings (2026-11-06, first live mirror push):** the
  Windows self-hosted runner's `npm install` phase runs ~15 min — a quiet
  job is NOT a hang, check `gh run view <id> --log` before touching code.
  The WCAG scaffold warnings fix in the PROFILE, not the template: the
  TOP-LEVEL `tokens` keys are the `--forge-*` names minus the prefix (`ink`,
  `muted`, `line-strong`, …) — a `design.tokens` block does NOT exist and is
  silently ignored (the merge is `defaults ← designToTokens(design) ←
  profile.tokens`). Pick values from the source's own unassigned pool that
  PASS — the audit's one-size suggestion (same dark color for all three)
  flattens hierarchy, `#505050` muted and `#666666` line-strong both clear
  4.5:1/3:1 and keep it. GitHub intermittently rejects the orphan force
  push with `remote: fatal error in commit_refs` — the emitted script
  retries 3× with a 5s `Atomics.wait` sleep (cross-platform). Mirror CI
  secrets on the monorepo:
  `NVOOS_FORGE_MIRROR_URL` + `NVOOS_FORGE_MIRROR_TOKEN` + variable
  `NVOOS_FORGE_MIRROR_REPO` — set via `gh auth token | gh secret set …` so
  the token never lands in a transcript. A prebuilt local `dist/` stages
  instantly: run the generated `scripts/mirror-push.mjs` with `MIRROR_URL`/
  `MIRROR_TOKEN`/`SITE_BRANCH` env.
- **Local preview of a built site** (a `dist/` exists): the Zed terminal
  reaps backgrounded children, so detach the server —
  `powershell.exe -NoProfile -Command "Start-Process -FilePath 'node' -ArgumentList 'node_modules/astro/bin/astro.mjs','preview','--port','4199' -WorkingDirectory '<abs target>' -WindowStyle Hidden"`.
  astro's bin is `bin/astro.mjs` (NOT `astro.js`); `astro preview` binds
  `localhost` (::1) only — probe `curl http://localhost:<port>/`, not
  `127.0.0.1`; stop via the PID from `netstat -ano | grep <port> | grep LISTEN`.
- **Velocity app — build-centric, no static preset (2026-11-06):** Velocity
  ALWAYS runs `npm install` + a build command; there is no pure static
  framework option. With `Astro-ssr` selected, a mirror branch (prebuilt
  flat files, no package.json) fails `ENOENT package.json` (rc=254). The
  working pattern: the mirror-push emission ships a **no-op package.json
  stub** (`nvoos-forge-site`, build = `node -e "…"` — quoted: `sh -c` rejects a bare `(`) beside the
  built files, and the Velocity Deployment Settings set the **Output
  Directory to the repo root (`.`)** with the default build command
  (`npm run build` — the no-op). The Cloudways MCP exposes no
  deployment-settings tool (the apps toolset's web-rules tools are
  header/redirect rules only) — these are Velocity-console UI settings.
  The mirror content itself is not the problem: verify with
  `gh api repos/nvdigitalsolutions/nvoos-forge-sites/contents/?ref=site/<name>`
  — `index.html` at root means the push is good. A hand-added stub does NOT
  survive the next mirror force-push (the push replaces branch content) —
  the stub must come from the emission, so merge the emission fix BEFORE
  the next forge-sites run.
