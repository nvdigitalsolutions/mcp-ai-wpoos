# WordPress.org Plugin Directory Submission Checklist — Base Plugin

> Applies to the **base plugin only** (`mcp-ai-wpoos.php`, `mcp-ai-wpoos-base.php`, `includes/`, plus the addons `algorave`, `canvas`, `cornerstone3d`, `embedded`, `fantasy-football`, `graphify` if they ship in the same SVN repo). The Pro addon (`addons/pro/`) is **not** distributed via WP.org and `.distignore` already excludes it.
>
> Tracks the [WordPress.org Plugin Directory: Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) (the "18 rules").

## Status legend
✅ Pass · ⚠️ Action required · ❌ Fail · — N/A

| # | Guideline | Status | Notes / forward link |
|---:|---|---|---|
| 1 | Plugins must be compatible with the GNU General Public License v2 (or later). | ✅ | `License: GPLv3 or later` in plugin headers; all bundled licences GPL-compat ([`inventory.md`](./inventory.md) §10). |
| 2 | Developers are responsible for the contents and actions of their plugins. | ✅ | — |
| 3 | A stable version of a plugin must be available from its WordPress Plugin Directory page. | ✅ | `Stable tag: 1.1.9` matches `mcp-ai-wpoos.php` Version. |
| 4 | Code must be (mostly) human-readable. | ⚠️ | Several `.min.js` files in `assets/js/` ship without sibling source / source maps. **R-Q-06** must be done before submission. F-CMP-05. |
| 5 | Trialware is not permitted. | ✅ | All base features are fully usable without licence; pro addon is a separate plugin. |
| 6 | Plugins may not "phone home" without informed user consent. | ✅ | `class-wp-mcp-ai-activation-tracker.php` is opt-in. AI provider calls are user-initiated. **But** F-PRIV-02: data-flow disclosure missing in `readme.txt` — fix before submission. |
| 7 | Plugins may not track users without their informed, explicit consent. | ✅ | No analytics/tracking on visitors by default. |
| 8 | Plugins may not embed external links or credits on the public site without explicit, third-party permission. | ✅ | No external credits injected into front-end content. |
| 9 | Plugins should not hijack the admin dashboard. | ✅ | Admin notices reviewed; no "rate us" interstitials, no upsell takeovers. |
| 10 | Plugins may not include "powered by" links unless explicitly opt-in. | ✅ | None found. |
| 11 | Plugins must use WordPress' default libraries. | ⚠️ | Confirm no bundled jQuery / wp.* shadow copies in `assets/js/vendor/` (excluded from ESLint — sample needed). |
| 12 | Frequent commits to a plugin should be avoided. | — | Process item; not a code review concern. |
| 13 | Public-facing pages on WordPress.org may not be used for SEO optimization or unrelated marketing. | — | Process. |
| 14 | Plugins should not use trademarks of others to promote their own. | ✅ | "OpenAI", "Gemini", "WooCommerce", "Elementor" used as factual integration names; no logos / no implication of endorsement. |
| 15 | Tested up to: must reflect the WP version actually tested. | ✅ | `Tested up to: 6.9` in both `mcp-ai-wpoos.php` and `readme.txt`. |
| 16 | A plugin and its developers must not behave dishonestly toward the WordPress.org Plugin Directory or its users. | — | Process. |
| 17 | Plugins must respect user privacy. | ⚠️ | F-PRIV-01 (Pro CCT/CPT — out of WP.org scope) does not affect submission, but base plugin Privacy-API exporter coverage should be re-verified. F-PRIV-02 disclosure required. |
| 18 | We reserve the right to maintain the Plugin Directory to the best of our ability. | — | Process. |

## Pre-submission checklist (base plugin only)

| Check | Status | Action |
|---|---|---|
| `readme.txt` headers (`Stable tag`, `Tested up to`, `Requires PHP`, `Requires at least`) match `mcp-ai-wpoos.php` | ✅ | — |
| `readme.txt` has an "External services" section with provider list, ToS / Privacy URL per provider | ❌ | **R-D-01** — add before submission. |
| Every PHP file has `if ( ! defined( 'ABSPATH' ) ) { exit; }` at the top | ⚠️ | 4 non-test files missing; 1 (`addons/embedded/uninstall.php`) ships in the WP.org ZIP. **R-Q-03**. |
| No `eval()`, no `assert()` on user input, no `create_function()`, no `preg_replace` with `/e` | ✅ | None found. |
| No `shell_exec`/`exec`/`system`/`passthru` in base | ✅ | All 11 calls are pro-only. |
| No remote eval / remote code load (e.g. fetching JS from CDN at runtime) | ✅ | All assets local. |
| No obfuscated code | ✅ | — |
| All `.min.js` ship with sibling source or source map | ❌ | **R-Q-06**. |
| All bundled libraries have permissive licences | ✅ | — |
| No bundled copies of WordPress core libraries (jQuery, etc.) | ⚠️ | Re-verify `assets/js/vendor/` contents. |
| All AJAX handlers use `check_ajax_referer` / capability check | ⚠️ | F-AUTHZ-02 — verify all 6 `wp_ajax_nopriv_*` before submission. |
| All REST routes have a real `permission_callback` or document why anon | ⚠️ | F-AUTHZ-01 — fix `mcp-controller`, `a2a-controller` for base. |
| All SQL through `$wpdb->prepare()` with placeholders | ⚠️ | F-SQL-01 (graphify), F-SQL-02 (model-catalog-migration). Both ship in WP.org ZIP. |
| `composer audit` clean (root) | ✅ | 0 vulnerabilities. |
| `npm audit --omit=dev` clean (root) | ⚠️ | 10 moderate, all auto-fixable. **R-Q-02**. |
| `composer run lint:base` errors-only zero | ❌ | 330 errors / 73 files. **R-Q-01** auto-fixes 168; remaining 162 must be hand-fixed before submission. |
| `composer run lint:base:compat` (PHP 7.4-8.3) zero | ⚠️ | Not run in this audit; must run before submission. |
| `composer run test` green | ⚠️ | Not run in this audit (test DB required). Must run before submission. |
| `wp plugin-check` against built ZIP zero Errors | ❌ | **R-T-04** — not yet run. **Required** before submission. |
| All UI strings translated via `wp.i18n` / `__()` / `_e()` | ⚠️ | 10 `WordPress.WP.I18n.MissingTranslatorsComment` errors flagged by PHPCS — fix in **R-Q-01** wave. |
| `Network: true` in plugin header is intentional | ✅ | Confirmed; multisite gating still needs review (F-AUTHZ-03). |
| Uninstall handlers (`uninstall.php` or `register_uninstall_hook`) clean up data | ⚠️ | `addons/embedded/uninstall.php` missing ABSPATH; verify cleanup is complete. |

## Submission gating items (must be done first)

These are the items that **must** clear before pushing a new tag to the WP.org SVN repo:

1. **R-D-01** — `readme.txt` external-services disclosure (Guideline 6 / 17).
2. **R-Q-01** — `composer run format` to clear 168 auto-fixable PHPCS errors.
3. Hand-fix the remaining 162 PHPCS errors (notably the 7 unprepared SQL — Guideline 4 risk).
4. **R-Q-02** — `npm audit fix` for the 10 root advisories.
5. **R-Q-03** — Add `ABSPATH` guard to `addons/embedded/uninstall.php` (Guideline 4).
6. **R-Q-06** — Restore source / source map for all shipped `.min.js` (Guideline 4).
7. **R-S-01** (the base-plugin-affecting subset) — `mcp-controller` and `a2a-controller` permission callbacks (Guideline 17 / OWASP).
8. **R-S-03** — Convert graphify SQL to `$wpdb->prepare()` (Guideline 4 / OWASP A03:2021 SQLi).
9. **R-T-04** — Run `wp plugin-check`; fix any reported Errors.

Once all 9 are done, the base plugin should be **submission-ready**. The remaining roadmap items can ship in subsequent dot releases.

## Pro addon submission

**N/A.** The Pro addon is licensed proprietary and is **not** submitted to WordPress.org. `.distignore` already excludes it from the SVN deploy via the `10up/action-wordpress-plugin-deploy` action.
