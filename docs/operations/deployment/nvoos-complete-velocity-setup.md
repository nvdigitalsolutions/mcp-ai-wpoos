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

## Magic-link sign-in (passwordless, opt-in)

The console sign-in screen also offers a **Magic link** mode, backed by the
plugin's public `/wp-json/mcp-ai/v1/magic-login/*` routes
(`WP_MCP_AI_REST_Magic_Login_Controller` in `includes/rest/`). Disabled by
default — while disabled the routes 404.

**Enable it in the UI** on the `NVOOS_TARGET_SITE` WordPress install:
`Settings → NV oOS → Authentication → Magic Link Sign-In` — tick *Enable
Magic Link Sign-In* and set *Magic Link App Origin* to the console address
(e.g. `https://console.example.com`; empty falls back to the site address).

Or via WP-CLI (the controller also honours these standalone options):

```bash
wp option update wp_mcp_ai_magic_login_enabled 1
# Emailed links land on the console app (default: the site's home_url):
wp option update wp_mcp_ai_magic_login_app_origin https://console.example.com
```

Both options have matching filters (`wp_mcp_ai_magic_login_enabled`,
`wp_mcp_ai_magic_login_app_origin`) for code-based deployments.

Security contract: ~256-bit tokens via `wp_generate_password(43)`
(SHA-256-hashed at rest), 15-minute expiry, single-use, one outstanding
link per user, uniform anti-enumeration responses, transient rate limits
(3 requests/identifier/15 min, 10/IP/hour), and the confirm route fires
`wp_login` so login-auditing plugins still observe the sign-in. The client
never chooses the link target — the emailed URL is always the configured
app origin — and the console consumes the token at `#/magic-login` with a
hard reload into the dashboard on success.

## Caching — keeping load off WordPress

`scripts/serve.mjs` ships a three-layer caching contract; the Velocity
NGINX edge + CDN sit in front and honor the same headers.

| Layer | Behavior |
|---|---|
| Hashed `/assets/*` | `public, max-age=31536000, immutable` — cached by the browser **and the Velocity CDN** for a year; WordPress never sees these requests. Vite content-hashes every chunk, so a redeploy rotates the URLs and old entries age out naturally. |
| `index.html` | `no-cache` + weak ETag → browsers revalidate and get a 304 instead of a full re-download on every route change. |
| Unversioned root files (favicon…) | `public, max-age=86400` + ETag — bounded because their URLs cannot rotate. |
| Text assets (JS/CSS/HTML/SVG) | gzip/brotli from an in-memory file cache with `Vary: Accept-Encoding` (entry chunk ~90 KB gzip vs ~300 KB raw). |
| Proxied `GET /wp-json` | **Default-deny micro-cache**: responses are stored only when WordPress itself marks them cacheable (`Cache-Control: public` + `max-age`/`s-maxage`, no `Set-Cookie`, `Vary` ≠ Cookie/Origin/*), capped at `NVOOS_PROXY_CACHE_MAX_TTL` (default 300 s). The nonce + SSE endpoints already send `no-store` and can never be cached. `X-Cache-Status: HIT/MISS/BYPASS` + `Age` on every proxied response. |

Env knobs: `NVOOS_PROXY_CACHE=0` disables the micro-cache; `NVOOS_PROXY_CACHE_MAX_TTL=<seconds>`
changes the cap.

**Backend opt-in:** `GET /mcp-ai/v1/features` (user-invariant, fetched on
every console boot) now emits `Cache-Control: public, max-age=60,
s-maxage=60`, so the micro-cache absorbs it immediately; the WP status
endpoints emit `public, max-age=60/300` the same way. To offload more
console GETs, emit the same header from the controller. Never mark
per-user payloads (transcripts, `users/me`) cacheable.

**WP 6.9 caveat (handled):** core sends `no-cache, … no-store, private` on
every logged-in REST response (`rest_send_nocache_headers` defaults to
`is_user_logged_in()`), overwriting a controller's `Cache-Control`.
`WP_MCP_AI_REST_Cache_Control` (`includes/rest/class-wp-mcp-ai-rest-cache-control.php`)
opts the request out when — and only when — the response explicitly
declares `public`/`s-maxage`; default-deny is preserved for everything
else.

## Troubleshooting — nonce endpoint returns the Imunify360 challenge page

If the console fails with `nonce endpoint: expected JSON but got text/html`
and `/wp-login.php` on the app origin shows "One moment, please..." followed
by a redirect to
`/z0f76a1d14fd21a8fb5fd0d03e0fdc3d3cedae52f?wsidchk=…`, the blocker is
**Cloudways Anti-Bot Protection** (Imunify360 WebShield WAF) on the server
hosting the `NVOOS_TARGET_SITE` WordPress app — not Cloudflare. Fix:
Cloudways → that server → Security → Anti-Bot Protection: off (or allowlist
the Velocity app's egress IP in the Security Suite). Full diagnostic ladder
in the `mcp-ai-schedule-anything-ops` skill, §6.

## Rollback

The mirror history is flat — redeploy the previous mirror commit on
Velocity. The monorepo rollback path is `git revert` + normal sync.

## Cross-app SSO (deferred, Proposal 083 §6.4)

Console and Schedule Anything app host cookies are host-only today (each
app's proxy strips `Domain`). Shared-domain cookie support is a follow-up:
set (instead of strip) `Domain=<shared-parent>` behind an env flag, in both
apps' `serve.mjs`, once both ship on the same registered domain.
