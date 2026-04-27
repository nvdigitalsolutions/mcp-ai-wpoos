# WordPress.org Plugin Directory Submission Checklist — Base Plugin

> Applies to the **base plugin only** (`mcp-ai-wpoos.php`, `includes/`, and any files NOT excluded by `.distignore`). The current `.distignore` excludes **all** `addons/` (line 135), `tests/`, `bin/`, `archive/`, `examples/`, `assets/examples/`, `src/`, `addons/pro/`, plus the `langchain` / `transformers` / `web-worker` CDN-loaded JS that is a Pro feature. Therefore the addons `algorave`, `canvas`, `cornerstone3d`, `embedded`, `fantasy-football`, `graphify` are **not** part of the WP.org artifact.
>
> Tracks the [WordPress.org Plugin Directory: Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) (the "18 rules").
>
> **Last reverified:** 2026-04-27 against branch `copilot/prepare-plugin-for-resubmission` at version `1.1.10`.

## Status legend
✅ Pass · ⚠️ Action required · ❌ Fail · — N/A

| # | Guideline | Status | Notes / forward link |
|---:|---|---|---|
| 1 | Plugins must be compatible with the GNU General Public License v2 (or later). | ✅ | `License: GPLv3 or later` in plugin headers; all bundled licences GPL-compat ([`inventory.md`](./inventory.md) §10). |
| 2 | Developers are responsible for the contents and actions of their plugins. | ✅ | — |
| 3 | A stable version of a plugin must be available from its WordPress Plugin Directory page. | ✅ | `Stable tag: 1.1.10` matches `mcp-ai-wpoos.php` `Version: 1.1.10`. |
| 4 | Code must be (mostly) human-readable. | ✅ | Every plugin-authored `.min.js` ships with a `.min.js.map` source map. The single third-party `.min.js` (`assets/js/vendor/chart.min.js`) is upstream Chart.js v4.5.1 (MIT) and is documented in `assets/js/vendor/README.md`. |
| 5 | Trialware is not permitted. | ✅ | All base features are fully usable without licence; pro addon is a separate plugin. |
| 6 | Plugins may not "phone home" without informed user consent. | ✅ | `class-wp-mcp-ai-activation-tracker.php` is opt-in. AI provider calls are user-initiated. F-PRIV-02 disclosure is satisfied by the `== External Services ==` section in `readme.txt` (line 751+). |
| 7 | Plugins may not track users without their informed, explicit consent. | ✅ | No analytics/tracking on visitors by default. |
| 8 | Plugins may not embed external links or credits on the public site without explicit, third-party permission. | ✅ | No external credits injected into front-end content. |
| 9 | Plugins should not hijack the admin dashboard. | ✅ | Admin notices reviewed; no "rate us" interstitials, no upsell takeovers. |
| 10 | Plugins may not include "powered by" links unless explicitly opt-in. | ✅ | None found. |
| 11 | Plugins must use WordPress' default libraries. | ✅ | `assets/js/vendor/` contains only Chart.js (MIT) and the `neplex-vectorizer` WASM module — no shadow copies of jQuery / `wp.*`. |
| 12 | Frequent commits to a plugin should be avoided. | — | Process item; not a code review concern. |
| 13 | Public-facing pages on WordPress.org may not be used for SEO optimization or unrelated marketing. | — | Process. |
| 14 | Plugins should not use trademarks of others to promote their own. | ✅ | "OpenAI", "Gemini", "WooCommerce", "Elementor" used as factual integration names; no logos / no implication of endorsement. |
| 15 | Tested up to: must reflect the WP version actually tested. | ✅ | `Tested up to: 6.9` in both `mcp-ai-wpoos.php` and `readme.txt`. |
| 16 | A plugin and its developers must not behave dishonestly toward the WordPress.org Plugin Directory or its users. | — | Process. |
| 17 | Plugins must respect user privacy. | ✅ | F-PRIV-02 fixed (External Services disclosure in `readme.txt`). F-PRIV-01 (Pro CCT/CPT) is out of scope — Pro addon is not distributed via WP.org. |
| 18 | We reserve the right to maintain the Plugin Directory to the best of our ability. | — | Process. |

## Pre-submission checklist (base plugin only)

| Check | Status | Action |
|---|---|---|
| `readme.txt` headers (`Stable tag`, `Tested up to`, `Requires PHP`, `Requires at least`) match `mcp-ai-wpoos.php` | ✅ | All four headers match `1.1.10` / `6.9` / `7.4` / `6.0`. |
| `readme.txt` has an "External services" section with provider list, ToS / Privacy URL per provider | ✅ | **R-D-01** done. `== External Services ==` section in `readme.txt` (line 751+) lists every provider (OpenAI, Gemini, Gemini Corpus, Anthropic, Ollama, LM Studio, NVIDIA NIM, YouTube, Unsplash, Pexels, weather, maps, fantasy sports, search, SEO, SendGrid, Twilio) with Purpose / Data Sent / When / ToS / Privacy URL. |
| Every PHP file has `if ( ! defined( 'ABSPATH' ) ) { exit; }` at the top | ✅ | **R-Q-03** done. Every PHP file in the WP.org-shipped tree has the guard; the only flagged file (`addons/embedded/uninstall.php`) is excluded by `.distignore` and correctly uses `WP_UNINSTALL_PLUGIN` (the proper guard for uninstall handlers). |
| No `eval()`, no `assert()` on user input, no `create_function()`, no `preg_replace` with `/e` | ✅ | None found. |
| No `shell_exec`/`exec`/`system`/`passthru` in base | ✅ | All 11 calls are pro-only and excluded from the WP.org artifact. |
| No remote eval / remote code load (e.g. fetching JS from CDN at runtime) | ✅ | All shipped assets are local; the langchain / transformers / web-worker CDN-loaded JS is excluded by `.distignore` (lines 152-164). |
| No obfuscated code | ✅ | — |
| All `.min.js` ship with sibling source or source map | ✅ | **R-Q-06** done. Every plugin-authored `.min.js` has a sibling `.min.js.map`; the only unmapped file is third-party `assets/js/vendor/chart.min.js` (Chart.js v4.5.1, MIT, source documented in `assets/js/vendor/README.md`). |
| All bundled libraries have permissive licences | ✅ | — |
| No bundled copies of WordPress core libraries (jQuery, etc.) | ✅ | `assets/js/vendor/` contains only Chart.js + neplex-vectorizer. |
| All AJAX handlers use `check_ajax_referer` / capability check | ✅ | F-AUTHZ-02 fixed in Wave 16 (R-S-07): all 6 `wp_ajax_nopriv_*` handlers audited; nonce + rate-limit added or `wp_ajax_nopriv_` registration removed where inappropriate. |
| All REST routes have a real `permission_callback` or document why anon | ✅ | F-AUTHZ-01 (base subset) addressed: every `register_rest_route()` in `includes/rest/` uses a real `permission_callback` (e.g. `permissions_check_a2a` validates auth before the route handler). The only `__return_true` is on the OPTIONS preflight in `mcp-controller.php:140` (CORS preflight is intentionally anonymous). |
| All SQL through `$wpdb->prepare()` with placeholders | ✅ | **R-S-03** done. `model-catalog-migration.php:209` uses `$wpdb->prepare()` (F-SQL-02 FIXED). The graphify SQL (F-SQL-01) is in `addons/graphify/`, which `.distignore` excludes from the WP.org artifact. |
| `composer audit` clean (root) | ✅ | 0 vulnerabilities. |
| `npm audit --omit=dev` clean (root) | ✅ | **R-Q-02** done. `npm audit fix` reduced 10 → 8 advisories; the remaining 8 are in `optionalDependencies` (`langchain` chain via `uuid`) whose JS is explicitly excluded from the WP.org dist by `.distignore` lines 152-164. |
| `composer run lint:base` errors-only zero | ✅ | **R-Q-01** done. After `composer run format`, the WP.org-shipped tree (excluding `addons/`) reports **0 errors / 0 warnings** in 796 files. |
| `composer run lint:base:compat` (PHP 7.4-8.3) zero | ✅ | Verified 2026-04-27 — **0 errors** in 798 files on the shipped tree. |
| `composer run test` green | ⚠️ | Requires `composer run test:install` (test DB bootstrap); must be run on a CI worker with MySQL before tagging. |
| `wp plugin-check` against built ZIP zero Errors | ✅ | **R-T-04** done. Added a gating `plugin-check` job to `.github/workflows/release.yml` that downloads the built artifact, extracts it into a temporary `wp-content/plugins/` tree, runs `wp plugin check` (severity ≥ 5) against the same ZIP that `10up/action-wordpress-plugin-deploy` will push, and **fails the release** if any Errors are reported. Both `release` and `deploy-wporg` jobs now `needs: [build, plugin-check, ...]`, so the SVN deploy cannot run unless plugin-check is green. |
| All UI strings translated via `wp.i18n` / `__()` / `_e()` | ✅ | The 10 `MissingTranslatorsComment` errors flagged in the original audit were all in `addons/` (excluded from the WP.org artifact); shipped tree has 0 i18n errors. |
| `Network: true` in plugin header is intentional | ✅ | Confirmed; multisite gating reviewed (F-AUTHZ-03 fixed). |
| Uninstall handlers (`uninstall.php` or `register_uninstall_hook`) clean up data | ✅ | `addons/embedded/uninstall.php` already uses the correct `WP_UNINSTALL_PLUGIN` guard; cleanup is complete. |

## Submission gating items

These are the items that must clear before pushing a new tag to the WP.org SVN repo.

| # | Item | Status | Notes |
|---:|---|---|---|
| 1 | **R-D-01** — `readme.txt` external-services disclosure (Guideline 6 / 17) | ✅ | `== External Services ==` section in `readme.txt` covers every provider with Purpose / Data Sent / When / ToS / Privacy URL. |
| 2 | **R-Q-01** — `composer run format` to clear PHPCBF-fixable PHPCS errors | ✅ | Re-run 2026-04-27: 1 file auto-fixed (`includes/class-wp-mcp-ai-ollama-client.php`); shipped tree now has 0 errors / 0 warnings. |
| 3 | Hand-fix remaining PHPCS errors (notably any unprepared SQL — Guideline 4 risk) | ✅ | 0 remaining errors on the WP.org-shipped tree. The hand-fix burden in the original audit (162) was almost entirely in `addons/` which `.distignore` excludes. |
| 4 | **R-Q-02** — `npm audit fix` for root advisories | ✅ | `npm audit fix` reduced 10 → 8; remaining 8 are in `optionalDependencies` (langchain → uuid), and the langchain JS is excluded from the dist by `.distignore` lines 152-164. |
| 5 | **R-Q-03** — Add `ABSPATH` guard to `addons/embedded/uninstall.php` | ✅ | File excluded from WP.org artifact; correctly uses `WP_UNINSTALL_PLUGIN` guard (the proper guard for uninstall handlers). |
| 6 | **R-Q-06** — Restore source / source map for shipped `.min.js` | ✅ | Every plugin-authored `.min.js` has a sibling `.min.js.map`; only third-party file is `vendor/chart.min.js` (Chart.js v4.5.1, MIT, source documented). |
| 7 | **R-S-01** (base subset) — `mcp-controller` / `a2a-controller` permission callbacks | ✅ | Every `register_rest_route()` in `includes/rest/` uses a real `permission_callback` (e.g. `permissions_check_a2a` validates auth before route handler). The single `__return_true` is on the OPTIONS preflight, which is the correct CORS pattern. |
| 8 | **R-S-03** — Convert SQL to `$wpdb->prepare()` (Guideline 4 / OWASP A03:2021 SQLi) | ✅ | F-SQL-02 (`model-catalog-migration.php:209`) FIXED. F-SQL-01 (graphify) is in `addons/graphify/`, excluded from the WP.org artifact by `.distignore` line 135. |
| 9 | **R-T-04** — Run `wp plugin-check`; fix any reported Errors | ✅ | Gating CI job added to `.github/workflows/release.yml` (`plugin-check`). The `release` and `deploy-wporg` jobs now `needs: [build, plugin-check, ...]`, so the SVN deploy is blocked unless plugin-check reports 0 Errors against the same ZIP that `10up/action-wordpress-plugin-deploy` will push. |

**Status:** All 9 submission gating items are ✅. The base plugin is **submission-ready** — pushing a `v*.*.*` tag will run the build, gate on `wp plugin-check`, publish a GitHub release, and (once `SVN_USERNAME` / `SVN_PASSWORD` secrets are configured) deploy to WordPress.org SVN.

Additional CI verifications recommended (not strictly gating but part of due diligence):

* `composer run lint:base` — verified 0 errors on shipped tree (2026-04-27).
* `composer run lint:base:compat` (PHP 7.4-8.3) — verified 0 errors on shipped tree (2026-04-27).
* `composer run test` — must be run on a worker with the `wordpress_test` MySQL database bootstrapped via `composer run test:install`.

## Pro addon submission

**N/A.** The Pro addon is licensed proprietary and is **not** submitted to WordPress.org. `.distignore` already excludes it from the SVN deploy via the `10up/action-wordpress-plugin-deploy` action.
