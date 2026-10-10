# Standalone Pro SPA on Cloudways Velocity — Setup & Operations Guide

Deploy the standalone **NV oOS Pro SPA Vite app** (`examples/nvoos-pro-spa-vite/`)
to **Cloudways Velocity** and connect it to any WordPress site running the
plugin (the production pairing is `chat.nvoos.cloud` → `nvoos.pro`). The app is
a static React shell that mounts the Pro SPA v2 sources directly from
`addons/pro/assets/spa-v2/` — no fork, no WordPress host page required.

> **Architecture decision — app and media worker stay separate Velocity apps.**
> The SPA is a static host + Node static file server; the Design Stack media
> worker is a long-running service with its own auth hardening (`X-Site-Token`,
> SSRF guard), rate limits, and binary needs (ffmpeg/Chromium — not available
> on Velocity images). The app **fronts** the worker (URL + token entered in
> the connection screen, every request carries the token), so the user sees one
> product across two independently deployable apps. See
> `media-worker-velocity-setup.md` for the worker half.

---

## 1. Repo & Sync Model

- **Monorepo source:** `examples/nvoos-pro-spa-vite/` + the vendored SPA
  sources `addons/pro/assets/spa-v2/`.
- **Standalone mirror:** `nvdigitalsolutions/nvoos-pro-spa-standalone`
  (branch `main`), assembled and force-pushed one-way by
  `.github/workflows/sync-nvoos-pro-spa-standalone.yml` on every push to
  `main`/`alpha-working`. The mirror keeps the monorepo layout —
  `examples/nvoos-pro-spa-vite/` + `addons/pro/assets/spa-v2/` — so the
  Vite alias resolves unchanged. **Never commit to the standalone repo
  directly** — the next sync overwrites it.
- **Deploy:** `.github/workflows/deploy-nvoos-pro-spa-standalone.yml`
  (manual `workflow_dispatch`) assembles the same tree and pushes it to the
  `VELOCITY_SPA_DEPLOY_URL` secret. That secret is the **git deploy remote
  URL from the Velocity app's deployment settings** (it embeds deploy
  credentials) — not the public domain. Velocity auto-deploys on push to
  `main`.

## 2. Provision the Velocity Application

1. **Connect the repo:** import `nvdigitalsolutions/nvoos-pro-spa-standalone`,
   branch `main`.
2. **Build settings:**

   | Setting | Value |
   |---|---|
   | Node version | **22** |
   | Package manager | npm |
   | **Root directory** | **`examples/nvoos-pro-spa-vite`** |
   | Build command | `npm ci && npm run build` |
   | Entry file / start | `scripts/serve.mjs` (static server over `dist/`; `PORT` is injected by the platform) |

   > **Root-directory pitfall (this exact failure ships a red build):**
   > leaving the root directory empty/at the repo root makes Velocity run
   > `npm install` where there is **no `package.json`**, and the build dies
   > with `npm error enoent Could not read package.json … /private_html/package.json`
   > (exit 254). The `package.json` lives one level down — the root directory
   > must be `examples/nvoos-pro-spa-vite`.

3. **Environment variables** (build-time):

   | Variable | Required | Value |
   |---|---|---|
   | `VITE_DEFAULT_SITE_URL` | recommended | `https://nvoos.pro` — pre-fills the connection screen so users don't paste the backend URL |

   The connection screen itself stores the site URL + auth credential in
   `localStorage` (per-browser, cleared via the ⚙ button). `NVOOS_TARGET_SITE`
   and `NVOOS_WORKER_URL` are **dev-proxy-only** variables — they do nothing
   on Velocity.

4. **Domain:** point `chat.nvoos.cloud` at the app in the Velocity console.

## 3. Auth Modes

| Mode | Transport | Production-ready? | Notes |
|---|---|---|---|
| **Assistant credential** | Cross-origin, `Authorization: Bearer cred_xxxxx.SECRET` | **Yes — the production path** | Create the credential in the NV oOS plugin (assistant-scoped; server re-checks every capability). `manage_options` on the credential only unlocks the admin layout client-side. |
| **Guest** | Cross-origin, `X-WP-MCP-AI-Guest` | Yes (public chat only) | Assistant ID + optional guest token minted by the site's `[nvoos_pro_spa]` page. |
| **Cookie (dev proxy)** | Same-origin via the Vite dev proxy | No — development only | Log into WordPress through the proxy; keep it out of the Velocity config. |

**Transcripts/approvals caveat:** the user-scoped REST surfaces (chat
transcripts, approvals) reject a **pure assistant credential** (token auth ≠
WP user) — tracked in issue #6987. On the Velocity app, expect those surfaces
in guest or cookie mode only until that lands.

## 4. Deploy Flow (One-way Sync)

```
monorepo PR (examples/nvoos-pro-spa-vite/** or addons/pro/assets/spa-v2/**)
  → merge to alpha-working/main
  → sync-nvoos-pro-spa-standalone.yml (assemble tree, ~2 min)
  → force-push main on nvoos-pro-spa-standalone
  → workflow_dispatch: deploy-nvoos-pro-spa-standalone.yml
  → push to VELOCITY_SPA_DEPLOY_URL
  → Velocity auto-deploy
```

First-run: after adding the `VELOCITY_SPA_DEPLOY_URL` secret, run the deploy
workflow once manually (it also no-ops cleanly while the secret is unset).

## 5. CORS on the WordPress Backend (chat.nvoos.cloud allowlist)

The browser enforces CORS on every cross-origin call the app makes
(`wp-json/mcp-ai/v1/*`, `wp-json/mcp-ai-pro/v1/*`, and the SSE chat stream).
**Do not flip the dropdown to "Allow All"** — that silently allows every
origin to call the API with credentials.

### 5a. Plugin v1.2.2+ (recommended — the guard is built in)

`WP_MCP_AI_CORS_Guard` (PR #6986) enforces the "Same Origin" setting, which
WordPress core's origin reflection previously made a no-op. On the backend
site:

1. **Security → Network:** keep `cors_allow_origin` = **Same Origin**.
2. Add `https://chat.nvoos.cloud` to the **CORS Allowed Origins** textarea
   (one origin per line; the site's own origin is always allowed).
3. The guard's default route scope is `/mcp-ai/v1` only — the Pro SPA also
   calls `/mcp-ai-pro/v1/*`, so widen it:

   ```php
   add_filter(
       'wp_mcp_ai_cors_guard_route_prefixes',
       static function ( array $prefixes ): array {
           $prefixes[] = '/mcp-ai-pro/v1';
           return $prefixes;
       }
   );
   ```

### 5b. Pre-1.2.2 installs (mu-plugin fallback — also harmless on 1.2.2+)

Replicates the guard exactly (allowlist echo + credentials, everything else
→ site origin + credentials off) for both the REST responses core reflects
and the SSE stream the plugin emits. Mu-plugins load before the plugin, and
the guard's own priority-20 hook runs after and sets the same values, so
keeping this file after upgrading to 1.2.2+ is safe — remove it once the
setting/textarea path is verified.

```php
<?php
/**
 * Plugin Name: NV oOS SPA CORS Allowlist
 * Description: Allow the standalone Pro SPA origin (Cloudways Velocity) through the plugin REST/SSE CORS surface while blocking everything else. Redundant once the site runs plugin v1.2.2+ with the cors_allowed_origins setting.
 * Version: 1.0.0
 */

$nvoos_spa_allowed_origins = array(
	untrailingslashit( get_site_url() ), // the WordPress site itself
	'https://chat.nvoos.cloud',          // the standalone Pro SPA
);

/**
 * Override core's origin reflection (rest_send_cors_headers, priority 10)
 * for the plugin's REST routes, mirroring WP_MCP_AI_CORS_Guard (v1.2.2+).
 */
add_filter(
	'rest_pre_serve_request',
	static function ( $served, $result, $request, $server ) use ( $nvoos_spa_allowed_origins ) {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/mcp-ai/v1' ) && 0 !== strpos( $route, '/mcp-ai-pro/v1' ) ) {
			return $served; // leave core routes and other plugins alone
		}

		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( '' === $origin || 'null' === strtolower( $origin ) ) {
			return $served; // no Origin header, or a sandboxed iframe — never allow 'null'
		}

		$allowed = false;
		foreach ( $nvoos_spa_allowed_origins as $allowed_origin ) {
			if ( hash_equals( $allowed_origin, $origin ) ) {
				$allowed = true;
				break;
			}
		}

		if ( $allowed ) {
			header( 'Access-Control-Allow-Origin: ' . $origin, true );
			header( 'Access-Control-Allow-Credentials: true', true );
		} else {
			header( 'Access-Control-Allow-Origin: ' . $nvoos_spa_allowed_origins[0], true );
			header( 'Access-Control-Allow-Credentials: false', true );
		}

		return $served;
	},
	20,
	4
);

/**
 * SSE path: the plugin emits CORS headers itself (outside rest_pre_serve_request)
 * through the wp_mcp_ai_cors_allow_origin filter — echo the allowlisted origin
 * there too.
 */
add_filter(
	'wp_mcp_ai_cors_allow_origin',
	static function ( $origin ) use ( $nvoos_spa_allowed_origins ) {
		$request_origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		foreach ( $nvoos_spa_allowed_origins as $allowed_origin ) {
			if ( hash_equals( $allowed_origin, $request_origin ) ) {
				return $allowed_origin;
			}
		}
		return $origin;
	}
);
```

## 6. Other Backend Settings Worth Checking (nvoos.pro profile)

- **Rate limiting** (`rate_limit_requests` = 100/3600s): assistant-credential
  tool calls are exempt (`tool_rate_limit_exempt_tokens` ON), but chat
  requests still count. If the SPA logs 429s under real use, raise
  `rate_limit_requests` (300–1000 is the recommended agent range) or bind the
  credential to a user with a larger budget.
- **`require_https`** stays ON — `chat.nvoos.cloud` is HTTPS.
- **SSE connections** (`max_sse_connections_per_user` = 5): fine for
  per-user chat; raise only for heavy multi-tab users.
- **`mcp_app_allowed_hosts`** is unrelated (server-side SSRF egress for MCP
  Apps) — the SPA needs no entry there.

## 7. Verification

```bash
# App serves the built SPA.
curl -sI https://chat.nvoos.cloud | grep -i 'HTTP/\|content-type'

# Backend echoes the app origin with credentials for the SPA…
curl -s -D - -o /dev/null -H "Origin: https://chat.nvoos.cloud" \
  "https://nvoos.pro/wp-json/mcp-ai/v1/assistants" | grep -i access-control
# …and the site origin WITHOUT credentials for a bogus origin:
curl -s -D - -o /dev/null -H "Origin: https://bogus.example.com" \
  "https://nvoos.pro/wp-json/mcp-ai/v1/assistants" | grep -i access-control
# Preflight (OPTIONS) must pass for the SPA origin:
curl -s -D - -o /dev/null -X OPTIONS -H "Origin: https://chat.nvoos.cloud" \
  -H "Access-Control-Request-Method: GET" \
  "https://nvoos.pro/wp-json/mcp-ai/v1/assistants" | grep -i access-control
```

Then open `https://chat.nvoos.cloud`, paste the site URL + an assistant
credential, and confirm the chat stream (SSE) renders.

## 8. Rollout Checklist

- [ ] Standalone mirror synced (`examples/nvoos-pro-spa-vite/` + spa-v2 tree)
- [ ] Velocity app: Node 22, **root directory `examples/nvoos-pro-spa-vite`**,
      `npm ci && npm run build`, entry `scripts/serve.mjs`
- [ ] `VITE_DEFAULT_SITE_URL=https://nvoos.pro` set
- [ ] `VELOCITY_SPA_DEPLOY_URL` secret set (deploy remote URL, not the domain);
      deploy workflow run once manually
- [ ] `chat.nvoos.cloud` domain pointed at the app; HTTPS working
- [ ] Backend: `cors_allow_origin` = site + `https://chat.nvoos.cloud`
      allowlisted (setting/textarea on 1.2.2+, mu-plugin §5b otherwise) +
      route-prefix filter for `/mcp-ai-pro/v1`
- [ ] §7 curl checks pass (echoed for the SPA, site-origin/credentials-off
      for a bogus origin)
- [ ] Assistant credential created and pasted into the connection screen;
      chat + streaming verified
- [ ] Uptime monitor on `https://chat.nvoos.cloud`
