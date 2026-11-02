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
