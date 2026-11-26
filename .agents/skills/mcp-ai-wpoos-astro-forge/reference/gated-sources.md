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
  evidence, remediation, bridge }`.
- **`augmentWithBridge(probe, base, fetchImpl, timeoutMs, authenticated)`** —
  asks the forge bridge for its verdict on **every** probe (one cheap round
  trip; `404` = plugin absent). When the HTML read looks `open` *and* the read
  was **anonymous**, a bridge `maintenance: true` re-classifies it as `gated`
  with the plugin slug as the fingerprint — that is the cached-anonymous-page
  case, where the edge served a public copy and no HTML evidence survives.
  A bridge that says `maintenance: false` over a gated HTML read records the
  disagreement in `evidence` but keeps the detected state (HTML wins: the page
  really is a holding page).
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

### The bridge route (`plugins/nvoos-forge-bridge` ≥ 0.3.2, P4)

`GET /wp-json/nvoos-forge/v1/gate` answers the site's **anonymous** gate state,
so the forge stops guessing from HTML:

| Field | Meaning |
|---|---|
| `maintenance` | bool — the site is gating anonymous visitors |
| `mode` | `off` · `maintenance` · `coming_soon` |
| `plugin` | the gate's slug (`elementor`, `wp-maintenance-mode`, …) or `null` |
| `checkedAt` | ISO-8601 UTC |

Sources, in order: `wp_is_maintenance_mode()`; Elementor
(`elementor_maintenance_mode_mode` ∈ {`maintenance`, `coming_soon`} **and** a
non-zero template id); WP Maintenance Mode
(`wpmm_settings['general']['status']`). Anything else joins through the
`nvoos_forge_bridge_gate_state` filter — a filter that returns garbage keeps
the detected state, and `maintenance: false` forces `mode=off`. The payload is
booleans, a mode keyword and a slug only: no titles, no copy, no template
text, so a holding page's content never leaves the site.

The route is public by design (the forge is anonymous when it asks) and carries
**no** credential — it is a state signal, not an unlock. It is not on the
public-write allowlist because it is `GET`/`READABLE`.

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
| `GET /wp-json/nvoos-forge/v1/gate` (bridge) | the site's anonymous gate state; `404` when the plugin is absent |

## 6. Tests and the fixture

`scripts/wp-fixture-server.mjs` grows `WP_FIXTURE_GATE=503|holding|edge` (plus
`/wp-login.php` and `/echo-headers`) and `WP_FIXTURE_BRIDGE_GATE=off|coming_soon|maintenance|absent`
for the bridge route. `/wp-json/` and `/wp-content/` stay open
in every mode. Authorization: the `wordpress_logged_in_fixture=1` cookie, the
`forge_bypass=1` query, or Basic `forge:secret`.

- `tests/gate.test.ts` — probe table, login, cookie scoping, redirect safety,
  no-store, Playwright options, bridge corroboration (including "an
  authenticated open read is NOT downgraded").
- `tests/gate-cli.test.ts` — real CLI: exit codes, JSON output, fail-closed
  `analyze`.
- `tests/gate-e2e.test.ts` — the whole feature: a child fixture in
  `503` + `coming_soon` mode forges **end to end** with `WP_GATE_USERNAME` /
  `WP_GATE_PASSWORD` (analyze → design block → scaffold → generated design),
  and the same source without credentials exits non-zero with
  `refusing to forge the holding page` plus the `bridge` evidence.

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
  Because `analyze` now probes the rendered page on every run, a loopback dev
  site needs that env for `--write`-only runs too — the e2e's `site_analyze`
  case and `tests/gate-cli.test.ts` both set it on the child process.
- **The bridge reports the ANONYMOUS mode, so it may only override an
  anonymous read.** The first cut let a bridge `maintenance: true` downgrade
  *any* `open` HTML read — including one fetched with a valid session. On a
  gated fixture the login works, the HTML is the real site, and the probe still
  said `gated`: `analyze` refused a source it had just unlocked. The gated e2e
  caught it. Gate the override on `( options.cookies?.length ?? 0 ) > 0`
  (`authenticated`) and pass it into `augmentWithBridge()`.
- **Do not skip the bridge query on `open` reads.** The cached-anonymous case
  *is* an `open` HTML read — that is the only shape it produces.
- **`wp_settings_errors`-style side effects do not exist here:** the bridge
  route is a pure read. Do not make it set a transient, touch an option, or
  log — every `forge gate` / `analyze` run hits it.

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

## 9. A CACHED holding page is the trap the probe cannot see (2026-11-23)

An Elementor Maintenance Mode gate behind Cloudways Breeze served the
"Coming Soon" page **from cache** to anonymous readers while the origin was
already live for a logged-in session:

- `x-cache: HIT` + `s-maxage=2592000` (30 days) on the holding page — the
  cached copy outlives the gate being switched off.
- `app_purge_cache` on that app answered `403 insufficient_scope` (the API
  token lacks the cache scope), so the operator could not flush it from the
  CLI either.
- The anonymous HTML was **74 KB** and the authenticated HTML **268 KB** — the
  size is the tell. Never trust an anonymous read for gate state, and never
  conclude "the gate is off" from it.
- Practical consequence: `--allow-blocked` would have scaffolded the holding
  page (the one artifact the pipeline must never produce). Refuse instead; the
  remediation is a purge at the edge/host, then re-probe.

## 10. One REST page can be 1.2 MB and ~30 s (raise the timeout)

An Elementor page whose `content.rendered` carries every widget's markup
returned **1.2 MB for one `wp/v2/pages` entry** and took ~30 s on a cold
origin. The generated project's loader inherits `WpClient`'s default timeout,
so a build dies on `TimeoutError` with no page named. Set
`WP_FETCH_TIMEOUT_MS=60000` (the scaffold wires it into `content.config.ts`)
and check `--check` sizes before scaffolding — a collection's `maxPages`
times the per-page cost is the build's real budget.
