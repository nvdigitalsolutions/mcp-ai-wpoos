# 064 — Standalone SPA WordPress Login (Application Password) — Implementation Plan

> Status: **Implemented + verified (live matrix)** · Date: 2026-10-10
> Scope: `examples/nvoos-pro-spa-vite/` (the standalone Pro SPA shell only — **no plugin-side changes**)
> Branch: `add/standalone-spa-wp-login`

## Overview

Add a fourth auth mode to the standalone Pro SPA (`examples/nvoos-pro-spa-vite/`)
that authenticates as a **real WordPress user** via a WordPress **Application
Password** (Basic auth over REST). Today the app has three modes:

| Mode | Identity | Limitations |
|---|---|---|
| Cookie (dev proxy) | Real WP user | Same-origin only — needs the Vite proxy or a reverse proxy; no in-app login form |
| Assistant credential (bearer) | Assistant (not a WP user) | User-scoped endpoints (transcripts, approvals) intentionally reject it (issue #6987); client-side fake `manage_options` unlocks the admin layout |
| Guest | None | Public chat surface only |

The new mode gives the deployed app (Cloudways Velocity, `chat.nvoos.cloud`)
the full admin surface with a **real user identity**, cross-origin. The
plugin's REST layer has supported Application Passwords since 1.7.0
(`WP_MCP_AI_REST_Authenticator::validate_wp_basic_auth()`), but **live
verification (2026-10-10, docker-compose WordPress) proved three gaps that
had to be fixed in the plugin for the full admin surface to work over Basic
auth** — see "Plugin-side fixes" below. The changes are:

1. `WP_MCP_AI_REST::chat_transcripts_permissions_check()` demanded an
   `X-WP-Nonce` for ANY logged-in user. Application-password users have no
   session token and cannot mint a session nonce, so transcripts 401'd. WP
   core's `rest_cookie_check_errors()` already exempts non-cookie auth, and
   the threads controller already had the exemption — transcripts now skip
   the nonce check when an `Authorization` header is present (the
   CSRF-safety argument: browsers never send that header cross-origin).
2. `WP_MCP_AI_REST::permissions_check()` and
   `permissions_check_assistant_list()` had no Application Password branch,
   so `/assistants` and other capability-gated routes 401'd for Basic-auth
   users. Both now accept Basic-auth users with the same capability gates
   as nonce-authenticated users (admin bypass preserved).
3. The OOS engine's streaming chat path (`lib/core` `SseHandler`) emits **no
   CORS headers** and exits before `rest_pre_serve_request` runs, so every
   cross-origin browser chat stream was blocked — a pre-existing bug
   affecting bearer/guest modes too, not just wp mode. Fixed with
   `WP_MCP_AI_CORS_Guard::emit_stream_cors_headers()`, called by the WP
   adapter (`handle_chat_request_oos`) before `handleChatStreaming()`.
   `lib/core` stays framework-agnostic.

## Research & industry standards

### WordPress Application Passwords (WP 5.6+)

- The [Advanced Administration Handbook](https://developer.wordpress.org/advanced-administration/security/application-passwords/)
  defines application passwords as: tied to a specific user account, intended
  for **API authentication** (REST API / XML-RPC), **not** for interactive
  browser logins. They exist precisely so third-party apps never handle the
  user's real account password, and each password is independently revocable
  from Users → Profile → Application Passwords.
- Authentication is stateless: every request carries
  `Authorization: Basic base64(username:app_password)`. The username is the
  WP **login name**, not the email (Atto WP 2026 guide, oddjar 2025 guide).
  The password format is `xxxx xxxx xxxx xxxx xxxx xxxx` (4 groups of 6).
- **No nonce required**: Application Password (and Basic/bearer header) auth is
  inherently CSRF-safe because it relies on custom headers the browser never
  sends implicitly. The plugin codifies this in
  `includes/rest/class-wp-mcp-ai-rest-token-manager.php` ("Manual nonce
  verification here would break bearer-token and Application Password
  authentication, which are inherently CSRF-safe") and the threads controller
  explicitly exempts Application Password auth from nonce verification.
- Least privilege (WPRobo 2026, the "one dedicated WP user per integration,
  minimum role, one Application Password each" pattern): recommend in docs that
  operators create a **dedicated, least-privilege WP user** for the standalone
  app rather than using an admin's app password — the app password carries the
  full permissions of the user it belongs to.
- WordPress responds `401` with `WWW-Authenticate: Basic realm="..."` on failed
  application-password auth. With `fetch()` this surfaces as a normal 401
  response (no native browser dialog); the app must present a friendly error.

### SPA-side credential handling

- **Basic header construction in JS**: `btoa()` throws on code points outside
  Latin-1 (`InvalidCharacterError`), so a correct implementation must encode
  UTF-8 first via `TextEncoder` (Stack Overflow "Basic authentication with
  fetch"). WP usernames/passwords are effectively ASCII, but the encoder path
  is the robust, standards-correct one and costs nothing.
- **Storage**: OWASP ([HTML5 Security](https://cheatsheetseries.owasp.org/cheatsheets/HTML5_Security_Cheat_Sheet.html),
  [Session Management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html))
  recommends **not** storing secrets in `localStorage` (XSS can read it), and
  the 2018+ IETF OAuth BCP consensus prefers short-lived tokens in memory or
  HttpOnly cookies. Mitigations applied here:
  1. The SPA sanitizes all rendered AI/markdown output through DOMPurify
     (`dompurify` is already a dependency), which is the app-side XSS control.
     (The plugin's `WP_MCP_AI_CSP_Headers` class only fires on WP admin pages
     — it does not cover the standalone host, so it is not counted here.)
  2. Application passwords are **independently revocable** — a stolen value is
     revoked in wp-admin without touching the account password (the assistant
     credential has the same revocation property).
  3. The existing app already stores the assistant credential in
     `localStorage` (documented in its README); the new mode follows the same
     established pattern for consistency, with the same "treat it like a
     password" warning.
  4. The credential is **origin-scoped in transit**: the fetch wrapper only
     attaches the Basic header to requests whose destination origin is the
     configured WordPress site — never to the media worker or any third-party
     URL (see Security considerations).
- **Do not send cookies for Basic-auth requests**: `credentials: 'omit'` keeps
  the new mode completely stateless and avoids any interaction with
  cookie/nonce auth.
- **CORS**: the plugin already allow-lists the `Authorization` request header
  (documented in the standalone README's CORS section), and
  `WP_MCP_AI_CORS_Guard` echoes the configured allowlist origins. No plugin
  change needed — the same Security → Network allowlist entry used for bearer
  mode covers this mode.

### Why not a username/password login endpoint

WP core has no REST login route; `wp-login.php` sets first-party cookies that
don't attach cross-origin. A custom plugin login endpoint (cookie issuance +
`SameSite=None; Secure` + CSRF hardening + rate limiting) is a new attack
surface on a plugin with 11 security classes, and cookie mode already exists
for the same-origin/proxy case. Application Passwords are the WP-native,
stateless, cross-origin answer (WordPress Developer Handbook: "they are
designed to avoid sharing your main account password with third-party tools").

## Design

- **Auth mode id: `wp`** (user-facing label "WordPress login (application
  password)"). Stored in the existing `ConnectionSettings.auth` union.
- **Connection handshake**: `GET {site}/wp-json/wp/v2/users/me?context=edit`
  with the Basic header. Success proves the credentials AND returns the real
  user: `id`, `name`, `slug`, and `capabilities` (WP core exposes the full
  capability map only in `context=edit`, and only to the authenticated user).
  The real capability list replaces the bearer mode's hardcoded
  `manage_options` — the spa-v2 admin surface gates on `user.capabilities`
  (e.g. `manage_options` for the model selector), so a least-privilege user
  gets the *correct* surface, not the maximal one.
- **Runtime**: built with `viaProxy: false` (direct cross-origin), `nonce: ''`
  (stateless — no cookie session), and the real user object.
- **Fetch wrapper**: new `basic` option (pre-encoded header value) +
  `siteOrigin` option. The wrapper attaches `Authorization: Basic …` only when
  the request URL's origin equals the configured site origin (absolute URLs
  from `runtime.apiUrl`, including the SSE stream opened through global fetch,
  all qualify). The empty-`X-WP-Nonce` strip now also covers basic mode,
  mirroring bearer/guest.
- **UI**: fourth radio in the auth fieldset + username/application-password
  fields (password input). The credentials persist in the same
  `nvoos-standalone-connection` localStorage key; the ⚙ disconnect button
  clears them. A hint links users to Users → Profile → Application Passwords.
- **Validation errors**: distinguish 401/403 ("wrong username or application
  password, or the user can't use REST") from network/CORS failures.

## File manifest

### `examples/nvoos-pro-spa-vite/`

**Modified files**

| File | Change |
|---|---|
| `src/runtime-config.ts` | `AuthMode` gains `'wp'`; `ConnectionSettings` gains `wpUsername`/`wpAppPassword`; new `buildBasicAuthHeader()` (TextEncoder-safe base64); new `fetchCurrentUserBasic()` (users/me with Basic, `credentials: 'omit'`, real capability extraction); `buildRuntime` handles wp mode (no nonce, real user) |
| `src/fetch-wrapper.ts` | `FetchWrapperOptions` gains `basic?: string` and `siteOrigin?: string`; attach Basic header only to site-origin requests; include `basic` in the empty-nonce strip |
| `src/main.tsx` | Fourth auth radio + username/app-password fields; `connect()` wp branch (validate via `fetchCurrentUserBasic`, build runtime, install wrapper with `basic` + `siteOrigin`); hint copy for creating an application password |
| `README.md` | Auth-modes table row for WP login; storage/security note; note that no extra CORS config is needed beyond the existing origin allowlist |

**No new dependencies.** Plugin-side files (fixes found by live verification):

| File | Change |
|---|---|
| `includes/class-wp-mcp-ai-rest.php` | Transcripts permission check skips the nonce for `Authorization`-header requests (mirrors WP core + threads controller); `permissions_check()` + `permissions_check_assistant_list()` gain Application Password branches with the nonce path's capability gates; `handle_chat_request_oos()` emits CORS headers before the OOS streaming path (which exits before the guard runs) |
| `includes/security/class-wp-mcp-ai-cors-guard.php` | New `emit_stream_cors_headers()` static — shared CORS emission for direct-output SSE surfaces |

## Security considerations

- **Origin scoping**: the Basic header is attached only to requests targeting
  the configured WordPress site origin. The media worker keeps its own
  `X-Site-Token`, and any other fetch (e.g. external image URLs) receives no
  WP credential. (Pre-existing note: bearer mode attaches to every request —
  flagged in Deferred; not worsened by this change.)
- **No nonce, no cookies**: `credentials: 'omit'` + no `X-WP-Nonce` — the mode
  cannot be confused with cookie auth and introduces no CSRF surface.
- **Credential hygiene**: value is trimmed but never logged; errors carry no
  echo of the password. localStorage storage matches the existing credential
  mode; docs warn to treat it like a password and prefer a dedicated
  least-privilege WP user.
- **Revocability**: application passwords are revoked per-app in the WP profile
  (server-side only stores hashes — `class-wp-mcp-ai-tool-create-application-password.php`).

## Verification gate (all must pass)

```bash
cd examples/nvoos-pro-spa-vite
npm run typecheck && npm run build
```

Manual matrix (docker-compose WordPress, mirroring the README's existing
"Verified against a live site" section):

1. **wp mode, admin user** — connect with username + app password → full admin
   surface, real display name, assistant catalogue loads, chat streams (SSE
   carries the Basic header via the wrapper).
2. **wp mode, least-privilege user** (e.g. `read`-only role) — connect
   succeeds; capability-gated controls (model selector, workflows,
   approvals) are hidden/denied per the real capability list.
3. **wp mode, wrong password** — clear 401 error message, connection screen
   stays visible, no partial runtime mounted.
4. **wp mode + media worker configured** — worker requests carry `X-Site-Token`
   only, **never** the Basic header (origin-scope check).
5. **Bearer/guest/cookie regression** — each existing mode still connects
   (dev-proxy for cookie) with unchanged behavior.

## Verification results (2026-10-10 — full live matrix, docker-compose WordPress 7.1.3)

Environment setup (dev docker only, documented for reproducibility):

- WP 7.1 gates application passwords to `is_ssl() || env === 'local'`; the
  plain-HTTP dev site runs `development`, so a dev-only mu-plugin forces
  `wp_is_application_passwords_available` true (standard approach for http
  dev sites).
- CORS allowlist on the test site += `http://localhost:5199` (the SPA dev
  origin).
- Test identities: `admin` + application password; `spa-reader` (subscriber)
  + application password; one assistant credential; one bearer credential
  (`cred_…`); a CORS-enabled stub echo worker on :3199 to observe request
  headers (the repo's dev media worker has no `ALLOWED_ORIGINS`, so its
  health check is CORS-blocked from the SPA origin — a dev-config note, not
  a code issue).

Matrix:

1. **wp mode, admin** — ✓ `users/me` returns real id/capabilities; assistant
   catalogue loads; transcripts list (user-scoped) loads real conversations;
   approvals/slash-commands/cron-status 200; SSE status **Connected**;
   chat POST streams "OK" from the model end-to-end and saves the
   transcript.
2. **wp mode, least-privilege (subscriber)** — ✓ connects; assistant list
   visibility-filtered ("No assistants found"); `vector-store-preload` 403;
   chat denied server-side with `wp_mcp_ai_insufficient_permissions`
   ("Grant the account the edit_posts capability…").
3. **wp mode, wrong password** — ✓ clear error ("WordPress rejected the
   username or application password (HTTP 401)…"), connection screen stays,
   no partial mount.
4. **wp mode + media worker** — ✓ worker-origin requests carry
   `X-Site-Token` and **no** `Authorization` header; WP-origin requests
   carry Basic only (network-level header capture).
5. **Regressions** — ✓ bearer (admin surface + catalogue), ✓ guest
   (embedded surface), ✓ cookie (dev proxy + wp-login through the proxy;
   full admin surface, SSE connected).

Automated gates:

- `phpunit tests/security/test-cors-guard.php tests/test-rest-assistant-directory.php`
  — **29 tests / 108 assertions, pass** (deprecations only).
- `phpunit tests/test-chat-conversation-flow.php tests/test-chat-conversation-cct-integration.php`
  — **10 tests / 49 assertions, pass** (4 JetEngine-gated skips, expected
  locally).
- `phpcs` (errors gate) on both touched PHP files — **0 errors** (one
  pre-existing warning at line 8016, outside the changed hunks).
- SPA `npm run typecheck` + `npm run build` — pass (see below).

**Bug surfaced and fixed during verification (in this PR):** the OOS
streaming CORS gap (#3 in the overview) blocks ALL cross-origin browser chat
streams on OOS-routed sites, not just wp mode — worth calling out in the PR
body and changelog.

## Deferred / follow-ups (tracked)

- Bearer-mode origin-scoping (attach `Authorization: Bearer …` only to the
  site origin, like the new basic mode) — hardening opportunity found while
  implementing; separate PR to avoid mixing concerns.
- Optional in-app "create application password" deep-link for sites where the
  operator has no wp-admin access (would need a per-site wp-admin URL entry).
- Real cookie-mode login form (username/password → wp-login.php) — requires a
  plugin-side login endpoint; intentionally out of scope (see "Why not a
  username/password login endpoint").
