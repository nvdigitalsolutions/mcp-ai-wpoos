# NV oOS Pro SPA — Standalone Vite App

A standalone **Vite + React** application that mounts the NV oOS **Pro SPA v2**
(from `addons/pro/assets/spa-v2`) and connects it to **any WordPress site
running the NV oOS plugin** — no WordPress host page required.

## How it works

The plugin SPA is already fully decoupled from WordPress chrome: everything it
needs arrives in a single global, `window.NVOOS_PRO_SPA`
(`apiUrl`, `proApi`, `nonce`, `endpoints`, `user`, `assistants`, `config`),
localized by the plugin's PHP loader. This app:

1. Shows a small connection screen (site URL + auth mode).
2. Builds the same runtime object, following the plugin's REST conventions:
   - `{site}/wp-json/mcp-ai/v1/{chat,chat-client,chat-transcripts,chat-memory,threads,tools,assistants,settings,approvals}`
   - `{site}/wp-json/mcp-ai-pro/v1/{workflows,analytics,tool-shortcuts,slash-commands,okf}`
   - `{site}/wp-json/mcp-ai/v1/session/nonce` — fresh `wp_rest` nonce (cookie auth)
3. Sets the global and renders the spa-v2 `<App/>` (admin surface) or
   `<EmbeddedApp/>` (chat surface) — **imported directly from the spa-v2 source
   tree via a Vite alias**, so there is no fork and the app tracks the plugin SPA.
4. Shims `@wordpress/i18n` (the plugin loads it from `window.wp.i18n`; here a
   tiny drop-in stands in) and wraps `window.fetch` to inject the
   `Authorization: Bearer cred_…` / `Authorization: Basic` (WordPress
   application password) / `X-WP-MCP-AI-Guest` headers — including the SSE
   chat stream, which the AI SDK adapter opens through the global fetch.

## Run it

```bash
cd examples/nvoos-pro-spa-vite
npm install
npm run dev          # plain Vite (no proxy target)
npm run dev:proxy    # proxies /wp-json → http://localhost:8000 (override: NVOOS_TARGET_SITE)
```

![Standalone Pro SPA connected to WordPress](standalone-fullscreen.png)

Validate:

```bash
npm run typecheck
npm run build
```

## Auth modes

| Mode | Transport | Setup | Surface |
|---|---|---|---|
| **Cookie (proxy)** | Same-origin via the dev proxy or the built-in serve.mjs proxy | `NVOOS_TARGET_SITE=https://your-site.com npm run dev:proxy` (or the same env var on `scripts/serve.mjs`); use the **Log in to WordPress** link on the connection screen — it opens `/wp-login.php` in a new tab, and after login you land back on the app with the session cookie set | Full admin |
| **WordPress login (application password)** | Cross-origin, `Authorization: Basic` (username + application password) | Create an application password under Users → Profile → Application Passwords; paste username + password in the connection screen. Authenticates as a real WP user with their real permissions. | Full admin (capability-gated server-side) |
| **Assistant credential** | Cross-origin, `Authorization: Bearer cred_xxxxx.SECRET` | Create a credential in the NV oOS plugin; paste it in the connection screen | Full admin layout (server enforces permissions) |
| **Guest** | Cross-origin, `X-WP-MCP-AI-Guest` token | Assistant ID (+ optional guest token minted by the site's `[nvoos_pro_spa]` page) | Public chat surface |

If cookie mode is selected without a proxy, the connection screen detects it
(the app origin does not serve the WordPress REST API) and explains how to
start one instead of failing with a JSON parse error.

## CORS

The plugin's REST and SSE layers send `Access-Control-Allow-Origin` and already
allow-list `Authorization`, `X-WP-Nonce`, and `X-WP-MCP-AI-Guest` headers.
Configure the allowed origin in the plugin under **Security → Network**
(default: the site's own origin), or via the `wp_mcp_ai_cors_allow_origin`
filter. In cookie mode the Vite proxy keeps everything same-origin, so no CORS
configuration is needed at all. WordPress login (application password) needs
no extra CORS work either — it uses the already allow-listed `Authorization`
header and sends no cookies.

## Verified against a live site (docker-compose WordPress)

- **Full screen** — `shell.css` re-creates the height chain the SPA expects
  from the WP admin (`#wpwrap` → root), so the three-column layout fills the
  viewport exactly.
- **Cookie mode** — connect → nonce resolved → `wp/v2/users/me` → full admin
  surface with the real assistant catalogue and **working chat transcripts**.
- **Bearer mode** — cross-origin with an assistant credential; the shell's
  fetch wrapper attaches `Authorization` to every request (including the SSE
  chat stream) and strips the empty `X-WP-Nonce` the SPA sends, which the
  plugin's REST layer rejects for token auth.
- **Server-side notes** — the plugin's `session/nonce` endpoint can mint an
  unauthenticated nonce on hardened installs (tracked in
  [#6985](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/6985)), so
  cookie mode prefers the nonce from the wp-admin page HTML. User-scoped
  endpoints (transcripts, approvals) intentionally reject a pure assistant
  credential — use cookie or guest auth for those.

## Deploying standalone

`npm run build` produces a static `dist/` you can host anywhere. Cookie auth
works whenever the server that hosts `dist/` also proxies the WordPress
routes: `scripts/serve.mjs` has a built-in, zero-dependency reverse proxy —
set `NVOOS_TARGET_SITE` (it proxies `/wp-json`, `/wp-admin`, `/wp-login.php`
and `/wp-includes`, keeps login redirects on the app origin, and strips
cookie domains). Any other reverse proxy in front of both the app and
WordPress works the same way. Assistant credentials and guest tokens work
cross-origin as-is.

### Cloudways Velocity (managed Node hosting)

Same deploy model as the media worker (`docs/operations/deployment/media-worker-velocity-setup.md`):

1. **Repo sync** — `examples/nvoos-pro-spa-vite/` is subtree-mirrored one-way to
   `nvdigitalsolutions/nvoos-pro-spa-standalone` by
   `.github/workflows/sync-nvoos-pro-spa-standalone.yml` on every push to
   `main`/`alpha-working`. Never commit to the standalone repo directly.
2. **Velocity app** — import the standalone repo (branch `main`): Node 22,
   **root directory `examples/nvoos-pro-spa-vite`**, build command
   `npm ci && npm run build`, entry `scripts/serve.mjs` (`PORT` is injected
   by the platform). Point the domain (e.g. `chat.nvoos.cloud`) at the app,
   and optionally set the build-time env
   `VITE_DEFAULT_SITE_URL=https://your-backend.example.com` to pre-fill
   the connection screen.
3. **Deploy** — `.github/workflows/deploy-nvoos-pro-spa-standalone.yml` pushes
   the subtree to the `VELOCITY_SPA_DEPLOY_URL` deploy remote (Velocity
   auto-deploys on push to main). **Important:** that secret is the git
   deploy remote URL from the Velocity app's deployment settings (it embeds
   deploy credentials), not the public domain.
4. **CORS on the WordPress backend** — with the app on `chat.nvoos.cloud`,
   the backend site must allow that origin: Security → Network →
   `cors_allow_origin` (`star`), or the `wp_mcp_ai_cors_allow_origin`
   filter with the exact origin. Bearer/guest modes then work cross-origin.
   For cookie mode, set `NVOOS_TARGET_SITE` on the Velocity app — the
   built-in proxy makes the WordPress REST API same-origin, so no CORS
   configuration is needed. Set it to a site you control: the proxy forwards
   cookies for the proxied routes.

### Design Stack media worker (optional)

Enter the worker URL + `X-Site-Token` in the connection screen and the app
fronts the media worker directly: every request to the worker origin carries
the token (strict-mode auth), and in dev the Vite proxy exposes it at
`/worker/*` (`NVOOS_WORKER_URL=...`). The worker itself keeps its own sync +
Velocity deploy (`.github/workflows/sync-media-worker.yml`,
`deploy-media-worker.yml`) — the app and worker are separate Velocity apps
that talk to each other, so image/video generation, document pipelines, OCR
and crawling remain one app from the user's perspective.

## Security notes

- The connection (site URL + credential/token) is stored in `localStorage` on
  your machine — treat it like a password; clear it with the ⚙ button.
- Credentials are assistant-scoped and the server re-checks every capability;
  the `manage_options` capability in bearer mode only unlocks the admin layout
  client-side.
- In WordPress login mode the application password authenticates as your WP
  user with that user's full permissions. It is revocable per-app in your
  WordPress profile, and it is attached **only** to requests targeting the
  configured site origin — never to the media worker or third-party URLs.
  Prefer a dedicated, least-privilege WP user for this app rather than an
  administrator account.
