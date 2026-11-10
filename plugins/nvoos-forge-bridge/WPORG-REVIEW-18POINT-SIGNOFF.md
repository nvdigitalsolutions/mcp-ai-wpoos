# NV oOS Forge Bridge — wp.org 18-Point Signoff (v0.1.0, 2026-11-02)

Pre-submission verification for the first upload of `nvoos-forge-bridge` to
the WordPress.org plugin directory. Excluded from the shipping ZIP via
`.distignore` (`WPORG-REVIEW-*.md`).

## Version sweep (all 0.1.0)

| Location | Value |
|---|---|
| `nvoos-forge-bridge.php` header `Version:` | 0.1.0 |
| `NVOOS_FORGE_BRIDGE_VERSION` constant | 0.1.0 |
| `readme.txt` `Stable tag:` | 0.1.0 |
| `readme.txt` `Tested up to:` | 7.1 (readme-only — no PHP-header duplicate) |
| `languages/nvoos-forge-bridge.pot` `Project-Id-Version` | NV oOS Forge Bridge 0.1.0 |
| ZIP | `build/nvoos-forge-bridge-v0.1.0.zip` (built by `bin/build-nvoos-forge-bridge.py`) |

## Validation run (2026-11-02)

| Gate | Result |
|---|---|
| `wp plugin check` (Plugin Check, WP latest, `--severity=5`) | **0 ERRORs, 0 WARNINGs** — clean report |
| `vendor/bin/phpcs --standard=phpcs.xml.dist .` | 0 violations (WPCS + PHPCompatibilityWP, min WP 6.0 / PHP 7.4+) |
| `php -l` on all 10 shipped PHP files | No syntax errors |
| PHPUnit (own config, wp-phpunit, WP latest, isolated DB) | **30/30 tests, 75 assertions, OK** |
| CI | `.github/workflows/phpunit-forge-bridge.yml` — phpcs + PHPUnit + PCP gate (ERRORs block the PR); staging list tri-synced with `.distignore` |

## The 18 guidelines

| # | Guideline | Evidence |
|---|---|---|
| 1 | GPL-compatible licensing | `License: GPLv3 or later` header + full GPLv3 `LICENSE` in the ZIP; POT header GPLv3 |
| 2 | Developer responsible for contents | Everything in the ZIP is first-party; no third-party bundled code (dev-only `vendor/` excluded) |
| 3 | Stable version always available | SVN runbook: `trunk/` + `tags/0.1.0/` on first commit (`.wordpress-org/README.md`) |
| 4 | Human-readable code | Ships readable PHP only — no minified/obfuscated assets, no build step; `== Source Code ==` links the public repo |
| 5 | No trialware | No paid tiers, upsells, or locked features — the plugin is complete as shipped |
| 6 | SaaS/service permitted | `== External Services ==` documents the GitHub API service (host, data sent, ToS + privacy links); send is disabled until configured |
| 7 | No tracking/phone-home | Single server-to-server POST to the admin-configured dispatches URL, only after enable+URL+token+secret saved; nothing sent to browsers |
| 8 | No remote code/CDN | No enqueued remote JS/CSS; no update/install hooks |
| 9 | Nothing dishonest | N/A — no marketing surfaces |
| 10 | No embedded attribution | No powered-by/credit links emitted anywhere |
| 11 | No admin hijacking | Notices render only on the plugin's own Settings → Forge Bridge page; no `admin_notices` hooks |
| 12 | Readme hygiene | 5 tags, no keyword stuffing, no affiliate links |
| 13 | No bundled WP libraries | Uses core WP APIs (`WP_REST_Controller`, transients, `wp_remote_post`) — no copies |
| 14 | Release discipline | One SVN commit per release (runbook) |
| 15 | Version increments | Version sweep above; trunk readme == stable tag |
| 16 | Complete plugin at submission | Full plugin + `readme.txt` at the ZIP root (verified in the built ZIP) |
| 17 | Trademarks | Slug `nvoos-forge-bridge` starts with our own prefix |
| 18 | Directory team rights | Informational — n/a |

## Reviewer-pattern sweep (recurring finding categories — all clear)

| Category | Result |
|---|---|
| Cron/translation before `init` | No `wp_schedule_event` or translation functions before `init` (debounce uses transients + `shutdown` only) |
| `Tested up to` in PHP header | Absent — readme-only |
| Symlink-safe recursive deletes | No filesystem deletion at all (`uninstall.php` deletes 5 options + 2 transients only) |
| Secrets in localized/admin output | Token/secret are write-only (`type="password"`, masked placeholder, never echoed); no `wp_localize_script` |
| Remote host disclosure | Only `wp_remote_post` to the configured dispatches URL (default `api.github.com`) — matches `== External Services ==` exactly |
| Public REST data leaks | `/health` returns versions + booleans + the URL **host** only; `/preview-tokens/verify` reveals `valid` + site host only after consuming a valid bearer token |
| GET with side effects | **Fixed pre-submission:** `/preview-tokens/verify` is POST-only (consuming a token is a state change) — GET returns 404 |
| Spurious rebuild triggers | **Fixed pre-submission:** autosaves, revisions, and auto-drafts are skipped in the `save_post` handler |
| Deletions missing from the rebuild hook | **Fixed pre-submission:** `delete_post` + `trashed_post` hooks added (the readme already promised deletions) |
| Capability vs docs drift | **Fixed pre-submission:** preview-token minting requires `edit_pages` (editors+) — matches the readme; authors (`edit_posts`) are rejected |
| `load_plugin_textdomain` | Not called (WP ≥ 4.6 auto-loads; `Text Domain`/`Domain Path` headers present) |
| Obscured code / eval / shell | None (grep-verified: no eval/base64/exec/shell/do_shortcode/set_time_limit) |
| Nonce + cap on every write | Settings POST: `check_admin_referer` + `manage_options`; REST mints: `edit_pages`; inbound rebuild: HMAC proof (fail-closed) |

## Design notes a reviewer may ask about (pre-answered)

- **`POST /preview-tokens/verify` consumes the token** (a POST with a
  side effect, deliberately): the endpoint is public by design — the bearer
  token IS the authorization proof, verification is single-use +
  constant-time, and the only side effect is burning a secret the caller
  already holds. This mirrors magic-link verification patterns. A GET on the
  route is not registered (404).
- **`POST /rebuild` is public** with signature-based authorization instead of
  a WP capability: it must be callable by the CI pipeline (an external
  system) without a WordPress login. Without the shared HMAC secret the route
  always returns 401.
- **`save_post` fires for every post type** — but revisions, autosaves, and
  auto-drafts are skipped in the handler, and the debounce (1 per 10
  minutes) plus the disabled-until-configured gate bound the rest.

## Remaining owner-side steps (not code)

1. Request the `nvoos-forge-bridge` slug via the wp.org submission form.
2. SVN checkout + `trunk/` + `tags/0.1.0/` + `assets/` per
   `.wordpress-org/README.md`.
3. Assets: screenshots 1–2, icon 128/256, banner 772/1544 (all captured).

---

## 0.2.0 re-submission — reviewer reply (2026-11-06, review P0TDX399590HGN)

The first review (v0.1.0) reported one finding: **`Rebuild_Webhook::send()`
declared `: array`, but a failed HTTP request returns `WP_Error` — an
uncaught TypeError at shutdown when content changes.** Full write-up in
`WPORG-REVIEW-FINDINGS.md`; the reply draft is `WPORG-REVIEW-REPLY.md`.

### Fix applied

- `send()` return type removed (PHP 7.4 has no union types; the docblock
  already declared `@return array|\WP_Error`). A failed delivery is now a
  silent no-op instead of a fatal error.
- Regression test `test_failed_request_returns_wp_error_without_typeerror`:
  `pre_http_request` returns `WP_Error` → `send()` returns it, and the
  `schedule()` → `send_pending()` shutdown path survives.
- Related-issue sweep: the only other `wp_remote_post` call (Turnstile
  verification) already handles `WP_Error`; no other typed return crosses
  an HTTP result.

### Version sweep (all 0.2.0)

| Location | Value |
|---|---|
| `nvoos-forge-bridge.php` header `Version:` | 0.2.0 |
| `NVOOS_FORGE_BRIDGE_VERSION` constant | 0.2.0 |
| `readme.txt` `Stable tag:` | 0.2.0 |
| `readme.txt` changelog | 0.2.0 entry + the fix bullet; 0.1.0 kept |
| `languages/nvoos-forge-bridge.pot` `Project-Id-Version` | NV oOS Forge Bridge 0.2.0 (regenerated — now includes the forms-bridge strings; 51 msgids) |
| `.wordpress-org/README.md` SVN runbook examples | 0.2.0 |
| ZIP | `build/nvoos-forge-bridge-v0.2.0.zip` (built by `bin/build-nvoos-forge-bridge.py`) |

### Validation run (2026-11-06)

| Gate | Result |
|---|---|
| `wp plugin check` (Plugin Check, WP latest, `--severity=5`) | **0 ERRORs, 0 WARNINGs** — clean report |
| `vendor/bin/phpcs --standard=phpcs.xml.dist .` | 0 violations (WPCS + PHPCompatibilityWP, min WP 6.0 / PHP 7.4+) |
| `php -l` on all shipped PHP files | No syntax errors |
| PHPUnit (own config, wp-phpunit, WP latest, isolated DB) | **48/48 tests, 133 assertions, OK** (incl. the new regression test) |
| Clean-install activation test (one-off container, `WP_DEBUG` + `WP_DEBUG_LOG` on) | Activation clean; front-page load: no warnings/notices/fatals; `debug.log` empty; no unexpected output |
| CI | `.github/workflows/phpunit-forge-bridge.yml` — phpcs + PHPUnit + PCP gate (ERRORs block the PR); staging list tri-synced with `.distignore` |

---

## 0.3.0 re-submission — stored redirects export (2026-11-09)

The 0.2.0 review fix is retained unchanged. 0.3.0 adds one read-only REST
surface (`GET /nvoos-forge/v1/redirects`) plus its settings/health wiring.

### Version sweep (all 0.3.0)

| Location | Value |
|---|---|
| `nvoos-forge-bridge.php` header `Version:` | 0.3.0 |
| `NVOOS_FORGE_BRIDGE_VERSION` constant | 0.3.0 |
| `readme.txt` `Stable tag:` | 0.3.0 |
| `readme.txt` changelog | 0.3.0 entry (redirect export + health flags); 0.2.0 + 0.1.0 kept |
| `languages/nvoos-forge-bridge.pot` `Project-Id-Version` | NV oOS Forge Bridge 0.3.0 (regenerated — includes the redirects strings) |
| `.wordpress-org/README.md` SVN runbook examples | 0.3.0 |
| ZIP | `build/nvoos-forge-bridge-v0.3.0.zip` — 15 files, built by `bin/build-nvoos-forge-bridge.py` |

### Readme hygiene (PCP warnings cleared)

| Item | Fix |
|---|---|
| Too many tags (6) | Trimmed to 5: `headless, rebuild, webhook, forms, redirects` |
| Short description > 150 chars (164) | Rewritten to 125 chars |

### Validation run (2026-11-09)

| Gate | Result |
|---|---|
| `php -l` on all shipped PHP files | No syntax errors |
| `vendor/bin/phpcs --standard=phpcs.xml.dist .` | 0 violations (exit 0) |
| PHPUnit (own config, wp-phpunit, WP 6.9 / PHP 8.3, isolated DB) | **58/58 tests, 167 assertions, OK** |
| `wp plugin check` (PCP, `--severity=5`) on the built ZIP's shipping shape | **0 ERRORs, 0 WARNINGs** |
| ZIP contents | 15 files; no dev files, hidden files, or `WPORG-REVIEW-*.md` |

### Reviewer notes for the new route (pre-answered)

- **Read-only and opt-in** — the route is registered always but returns the
  empty envelope (`enabled: false`) until the operator turns the export on
  in Settings → Forge Bridge. It never writes.
- **Bounded** — 500 exported rules maximum with an explicit `truncated`
  flag; the query adds `LIMIT MAX+1` so truncation is detectable.
- **Narrowed, never widened** — only enabled, exact-URL rules are exported.
  Regex, query-scoped, unsupported-action, and non-URL match types are
  counted under `skipped` instead of being approximated.
- **No data over-exposure** — no row ids, hit counts, group ids, titles, or
  timestamps leave the server. The response is `{ source, target, type }`.
- **Optional shared token** — an empty token keeps the route public (the
  operator opted in); a non-empty token requires an exact
  `X-Forge-Redirects-Token` / `Authorization: Bearer …` match (`hash_equals`,
  401 otherwise). The readme documents the trade-off.
- **Direct DB reads are to the redirect plugin's own tables only** —
  `{$wpdb->prefix}redirection_items` (+ `_groups`) and
  `{$wpdb->prefix}rank_math_redirections`, existence-checked first, with
  the limit bound through `$wpdb->prepare()`. Both call sites carry a
  scoped `phpcs:disable` for `DirectDB.UnescapedDBParameter` explaining that
  the table name is the fixed prefix + literal suffix.
- **No external calls** — the route reads local tables only; the
  `== External Services ==` section is unchanged (GitHub dispatches +
  optional Turnstile).

### Remaining owner-side steps (not code)

1. Upload `build/nvoos-forge-bridge-v0.3.0.zip` to the review thread / SVN.
2. SVN: copy the ZIP contents into `trunk/`, then tag `tags/0.3.0/`.
3. Optional: capture a third screenshot of the redirects settings section
   (the current two screenshots cover the webhook settings only).

---

## 0.3.1 re-submission — T2 review fixes (2026-11-10)

Review ID: `R nvoos-forge-bridge/vsamtani/5Nov26/T2 10Nov26/4.4RC1 (P0TDX399590HGN)`.
Full write-up in `WPORG-REVIEW-FINDINGS.md`; the reply draft is
`WPORG-REVIEW-REPLY.md`.

### Fixes

- **Form visibility gate** — JetFormBuilder and Elementor form resolution now
  requires a published, non-password-protected carrier. Non-public forms return
  the same typed 404 as a missing form.
- **IP source** — `REMOTE_ADDR` is authoritative; `X-Forwarded-For` is only
  honored behind a configured trusted proxy (`nvoos_forge_bridge_trusted_proxies`,
  empty by default) and then resolved from the rightmost untrusted hop.
- **Response capture** — native `wp_send_json()` calls are captured under an
  ajax-equivalent environment + output buffer instead of terminating the REST
  request.

### Version sweep (all 0.3.1)

| Location | Value |
|---|---|
| `nvoos-forge-bridge.php` header `Version:` | 0.3.1 |
| `NVOOS_FORGE_BRIDGE_VERSION` constant | 0.3.1 |
| `readme.txt` `Stable tag:` | 0.3.1 |
| `readme.txt` changelog | 0.3.1 security/fix entry; 0.3.0 down kept |
| `languages/nvoos-forge-bridge.pot` `Project-Id-Version` | NV oOS Forge Bridge 0.3.1 |
| `.wordpress-org/README.md` SVN runbook examples | 0.3.1 |
| ZIP | `build/nvoos-forge-bridge-v0.3.1.zip` (built by `bin/build-nvoos-forge-bridge.py`) |

### Validation run (2026-11-10)

| Gate | Result |
|---|---|
| `php -l` on modified PHP files | No syntax errors |
| `vendor/bin/phpcs --standard=phpcs.xml.dist .` | 0 violations (exit 0) |
| `phpcs` on `tests/test-forms.php` | 0 violations |
| PHPUnit (own config, wp-phpunit, WP 6.9 / PHP 8.2) | **71/71 tests, 204 assertions, OK** |
| Public write-route gate (`.github/scripts/check-forge-bridge-public-routes.php`) | Exit 0 — both public write routes reviewed; stale allowlist entries fail |
| `wp plugin check` (PCP) | Not re-run locally this round (Plugin Check is not installed in the local stack); run before upload |

### Remaining owner-side steps (not code)

1. Build + upload `build/nvoos-forge-bridge-v0.3.1.zip`.
2. SVN: copy the ZIP contents into `trunk/`, then tag `tags/0.3.1/`.
