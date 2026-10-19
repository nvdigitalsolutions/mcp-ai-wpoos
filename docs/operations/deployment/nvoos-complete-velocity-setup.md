# NV oOS Complete Console — Velocity Setup

Deploy runbook for the Complete Console on Cloudways Velocity. Companion to
`examples/nvoos-complete-velocity/README.md` and Proposal 083.

## One-time setup (owner steps)

1. **Mirror repo** — create `nvdigitalsolutions/nvoos-complete-velocity`
   (public, empty). The sync workflow force-pushes a flat history; never
   commit to the mirror directly.
2. **Deploy token secret** — add a repo-scoped token as the repository secret
   `NVOOS_COMPLETE_VELOCITY_REPO_TOKEN` (31 chars — under GitHub's 40-char
   secret-name limit).
3. **Backfill** — run `sync-nvoos-complete-velocity.yml` once via
   `workflow_dispatch` to populate the mirror.
4. **Velocity app** —
   - Connect the mirror repo.
   - **Root directory: repo root** (the app ships at the repo root —
     `package.json`, `server.js`, `dist/`, `scripts/` at the top level).
   - **Entry: `server.js`** (PM2 default; delegates to `scripts/serve.mjs`,
     which carries the mandatory shebang).
   - **No build command** — the sync workflow rewrites the mirror's
     `build` (and the other monorepo-only scripts) to no-op shims, so a
     Velocity build step left enabled succeeds harmlessly instead of
     failing (`tsc` has no tsconfig in the mirror). `start`/`serve` still
     run the shipped server. The workflow also strips
     `dependencies`/`devDependencies` (the runtime is zero-dependency
     Node), so the install step is a no-op and npm-audit noise never
     appears in deploy logs.
   - **Env:** `NVOOS_TARGET_SITE=<Complete-bundle WordPress site>`.

## Sync flow

- Merges touching `examples/nvoos-complete-velocity/**` **or**
  `addons/pro/assets/spa-v2/**` (main / alpha-working) trigger the sync:
  assemble tree (drop `scripts/sync-dist.mjs`) → syntax + asset-closure +
  boot smoke on `:4181` → force-push.
- Manual: `npm run sync:dist` in the app folder builds + stages `dist/`
  (git-tracked); commit; push; or `workflow_dispatch` the sync.
- The dist is **built in the monorepo** where the spa-v2 tsconfig paths
  resolve — never build in the mirror (TS2307 class of failures).

## Post-deploy smoke

1. Visit the app → redirected to `/wp-login.php` (re-scoped form).
2. Log in → back to the console → the **dashboard** (`#/`) is the home page
   with the chat dock on the right (global dock toggle in the nav rail;
   preference persists in `localStorage`).
3. Open `#/chat` → full-page breakout streams (SSE through the proxy);
   docked and full-page chat share one conversation.
4. Spot-check `#/assistants`, `#/tools`, `#/workflows` render and REST calls
   carry the session nonce.
5. Capability check: a lower-cap user gets server-enforced gating.

## Rollback

The mirror history is flat — redeploy the previous mirror commit on
Velocity. The monorepo rollback path is `git revert` + normal sync.

## Cross-app SSO (deferred, Proposal 083 §6.4)

Console and Schedule Anything app host cookies are host-only today (each
app's proxy strips `Domain`). Shared-domain cookie support is a follow-up:
set (instead of strip) `Domain=<shared-parent>` behind an env flag, in both
apps' `serve.mjs`, once both ship on the same registered domain.
