# Gated sources — maintenance / coming-soon / staging auth (Proposal 152)

Learned 2026-11-20, implemented the same day. Read this before touching
`lib/gate.ts`, the CLI's `analyze` flow, or any Playwright measurement.

## 1. The two planes (why `WP_APPLICATION_*` is not enough)

| Plane | Credential | Sets a cookie? |
|---|---|---|
| `/wp-json/` | Application Password (Basic), or a bearer token | **no** |
| rendered HTML | a real `wp-login.php` **session** | yes (`wordpress_logged_in_*`) |

WordPress states plainly that an Application Password *cannot* log into
`wp-login.php`. A plugin gate that checks `is_user_logged_in()` therefore
ignores the REST credential, and the forge's design/inventory/geometry passes
(which fetch HTML) went anonymous until 152.

The failure shape: `503 + Retry-After` on HTML, `200` on `/wp-json/`. Content
forges, design does not, and the build silently ships the **coming-soon page**.
That is the artifact the gate exists to prevent.

## 2. The env contract

Precedence (first match wins; edge headers are additive):

1. `WP_GATE_MODE=off` — no probe, no credentials (the public-site default).
2. `WP_GATE_COOKIE` — a raw `name=value; name=value` session (the only option on a 2FA site).
3. `WP_GATE_STORAGE_STATE` — a Playwright storageState JSON file.
4. `WP_GATE_USERNAME` + `WP_GATE_PASSWORD` — one login per process.
5. `WP_GATE_QUERY` (`a=1&b=2`) / `WP_GATE_HEADERS_JSON` — a plugin bypass token.
6. `CF_ACCESS_CLIENT_ID` + `CF_ACCESS_CLIENT_SECRET`, or `WP_GATE_HTTP_AUTH` — edge/staging auth.

`WP_GATE_LOGIN_URL` overrides the form path; `WP_GATE_NO_STORE=0` disables the
`no-store` header for debugging (documented as unsafe).

Resolution of the values themselves: process env → vault (`gate.<host>.password`
/ `.username` / `.cookie`) → nothing. `forge gate --login --vault` is the
interactive path; it never writes a profile.

The profile block (`SiteProfile.gate`) carries **references only**: `userEnv`,
`passwordEnv`, `session.cookieEnv`, `session.storageStateEnv`, `loginUrl`,
`bypass.query/headers`, and probe pins (`verify.rejectMarkers`,
`verify.minVisibleChars`). Most sites need no block at all — the default env
names apply.

## 3. What the module does (`lib/gate.ts`)

- **`probeGate(profile)`** — fetches `/` with `redirect: 'manual'`, then
  `/wp-json/` only when the first read is not obviously open. Returns
  `{ state, status, retryAfter, restHealthy, fingerprint, visibleChars,
  evidence, remediation }`.
- **`openGate(profile)`** — probe → (login | cookie | bypass) → re-probe.
  Installs a **host-scoped global fetch wrapper** and publishes the cookies for
  Playwright. `blocked = state !== 'open'`.
- **`installGateFetch(host, session)`** — the wrapper. Attaches the cookie and
  extra headers **iff** `url.host === host`; follows redirects MANUALLY and
  re-checks the host each hop (Node strips `Authorization` cross-origin but
  **not** a manually set `Cookie`); sets `cache-control: no-store`; appends the
  bypass query to GETs only.
- **`gateContextOptions(options)`** — merges the active cookies into
  `browser.newContext()`. Cookies carry `domain`/`path`, so Chromium scopes
  them per origin: a stale cookie from another site in the same process cannot
  leak. Every `newContext` site in `geometry.ts`, `interactions.ts`,
  `animations.ts`, `region-colors.ts`, `screenshot.ts`, `tracking.ts` and
  `band-diff.ts` calls it — **add it to any new browser entry point**.

## 4. Classification (the probe's table)

| Evidence | State |
|---|---|
| `503`/`429` + `Retry-After`, `/wp-json/` 200 | `gated` (the site's own mode) |
| `503` + `Retry-After`, `/wp-json/` not 200 | `rate-limited` (host/WAF) |
| `401`/`403` + `WWW-Authenticate: Basic` | `edge-auth` |
| `302` → `*.cloudflareaccess.com` | `edge-auth` |
| `200` + a holding-page marker (`wpmm-`, `seedprod`, `coming-soon`, `upc-bar`, core maintenance text …) | `gated` (+ fingerprint) |
| `200` + `< 200` visible chars + no builder/block markers + (`noindex` **or** REST unhealthy) | `gated` (fingerprint-free) |
| `200` + `cf-cache-status: HIT` | `unknown` — the edge may serve an anonymous copy |
| anything else | `unknown` |

**The low-text rule requires a corroborating signal on purpose.** The first cut
flagged any short page as gated; the hermetic fixture homepage (18 visible
chars, no `wp-block-*` markers) was misread, which is exactly the false
positive that blocks a legitimate forge. A holding page almost always says
`noindex` (or has a broken REST plane).

## 5. Surfaces

| Surface | Behaviour |
|---|---|
| `forge gate <profile>` | diagnose; exit `0` open · `3` gated · `4` edge-auth · `5` rate-limited · `6` unknown |
| `forge gate --login [--vault]` | interactive credential entry (TTY only); optional vault write |
| `forge analyze …` | opens the gate before the design pass; **refuses** a blocked source (`--allow-blocked` overrides) |
| `forge wizard … --design` | same, always fail-closed (never `--allow-blocked`) |
| MCP `design_apply` | typed error + `{ gate, probe }` payload when blocked |
| MCP `gate_probe` | read-only; same open-world policy as `site_analyze`/`design_apply` |

## 6. Tests and the fixture

`scripts/wp-fixture-server.mjs` grows `WP_FIXTURE_GATE=503|holding|edge` (plus
`/wp-login.php` and `/echo-headers`). `/wp-json/` and `/wp-content/` stay open
in every mode. Authorization: the `wordpress_logged_in_fixture=1` cookie, the
`forge_bypass=1` query, or Basic `forge:secret`.

- `tests/gate.test.ts` — probe table, login, cookie scoping, redirect safety,
  no-store, Playwright options.
- `tests/gate-cli.test.ts` — real CLI: exit codes, JSON output, fail-closed
  `analyze`.

## 7. Gotchas (each cost a fix)

- **`spawnSync` blocks an in-process fixture.** The CLI tests spawn the real
  CLI while the fixture runs in the test worker; a synchronous spawn freezes
  the event loop and every request times out. Use async `spawn`.
- **`process.exit()` after network activity dies on Windows** with
  `0xC0000409` (libuv `UV_HANDLE_CLOSING`). `forge gate` and the blocked-analyze
  path set `process.exitCode` and return; never exit explicitly after a probe.
- **`gateContextOptions` needs `<const T extends object>`.** A plain
  `T extends Record<string, unknown>` widens `reducedMotion: 'no-preference'`
  to `string`, and Playwright's `BrowserContextOptions` rejects it.
- **The fixture's `/wp-json/` root must report the request's host**, not the
  module's default `ORIGIN`. On an ephemeral port the draft profile otherwise
  points at `:8473` and every later fetch is `ECONNREFUSED`.
- **The fixture gate mode is read from the TEST process**, not the child's env
  — the CLI test sets `process.env.WP_FIXTURE_GATE` around the spawn.
- **`forge gate` must not be given a profile without `url`** — `loadProfile`
  resolves files or registry names, then `openGate` runs `assertSafeHttpUrl`.
- **Loopback needs the documented exemption** in tests: the CLI reads
  `NVOOS_FORGE_ALLOW_LOOPBACK=1`; the module reads `options.allowLoopback`.

## 8. Remediation ladder (what we tell the operator)

1. Add the automation user's role to the gate's allowlist (WP Maintenance
   Mode → General → "Roles allowed"; Elementor → Tools → Maintenance Mode →
   "Who can see it"), then set `WP_GATE_USERNAME` / `WP_GATE_PASSWORD`.
2. Or the plugin's bypass slug/token → `WP_GATE_QUERY`.
3. Or allowlist the builder's egress IP (composes with `WP_FETCH_PROXY`).
4. Or an edge credential — Cloudflare Access service token, or staging Basic
   auth.
5. Or build from staging where the gate is off.
6. Only if NV controls the host: pause the gate out of band, then restore it.

Never: spoof a crawler UA, solve a JS challenge, forge a cookie, brute-force a
password, or disable the gate on production to make a build pass.
