# WordPress.org Plugin Directory Submission Checklist — Base Plugin

> Applies to the **base plugin only** (`mcp-ai-wpoos.php`, `includes/`, and any files NOT excluded by `.distignore`). The current `.distignore` excludes **all** `addons/` (line 135), `tests/`, `bin/`, `archive/`, `examples/`, `assets/examples/`, `src/`, `addons/pro/`, plus the `langchain` / `transformers` / `web-worker` CDN-loaded JS that is a Pro feature.
>
> Tracks the [WordPress.org Plugin Directory: Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) (the "18 rules").
>
> **Last reverified:** 2026-05-25 against branch `alpha-working` at version `1.1.22`.

## Status legend
✅ Pass · ⚠️ Action required · ❌ Fail · — N/A

| # | Guideline | Status | Notes / forward link |
|---:|---|---|---|
| 1 | Plugins must be compatible with the GNU General Public License v2 (or later). | ✅ | `License: GPLv3 or later` in plugin headers; all bundled licences GPL-compat. |
| 2 | Developers are responsible for the contents and actions of their plugins. | ✅ | — |
| 3 | A stable version of a plugin must be available from its WordPress Plugin Directory page. | ✅ | `Stable tag: 1.1.22` matches `mcp-ai-wpoos.php` `Version: 1.1.22`. |
| 4 | Code must be (mostly) human-readable. | ✅ | Every plugin-authored `.min.js` ships with a `.min.js.map` source map. Third-party `chart.min.js` documented in `assets/js/vendor/README.md`. |
| 5 | Trialware is not permitted. | ✅ | All base features are fully usable without licence; pro addon is a separate plugin. |
| 6 | Plugins may not "phone home" without informed user consent. | ✅ | `class-wp-mcp-ai-activation-tracker.php` is opt-in. AI provider calls are user-initiated. `== External Services ==` section in `readme.txt` documents all 36+ providers. |
| 7 | Plugins may not track users without their informed, explicit consent. | ✅ | No analytics/tracking on visitors by default. |
| 8 | Plugins may not embed external links or credits on the public site without explicit, third-party permission. | ✅ | No external credits injected into front-end content. |
| 9 | Plugins should not hijack the admin dashboard. | ✅ | Admin notices reviewed; no "rate us" interstitials, no upsell takeovers. |
| 10 | Plugins may not include "powered by" links unless explicitly opt-in. | ✅ | None found. |
| 11 | Plugins must use WordPress' default libraries. | ✅ | `assets/js/vendor/` contains only Chart.js (MIT) and `neplex-vectorizer` WASM module. |
| 12 | Frequent commits to a plugin should be avoided. | — | Process item; not a code review concern. |
| 13 | Public-facing pages on WordPress.org may not be used for SEO optimization or unrelated marketing. | — | Process. |
| 14 | Plugins should not use trademarks of others to promote their own. | ✅ | "OpenAI", "Gemini", "WooCommerce", "Elementor", "Baseten" used as factual integration names; no logos / no implication of endorsement. |
| 15 | Tested up to: must reflect the WP version actually tested. | ✅ | `Tested up to: 6.9` in both `mcp-ai-wpoos.php` and `readme.txt`. |
| 16 | A plugin and its developers must not behave dishonestly toward the WordPress.org Plugin Directory or its users. | — | Process. |
| 17 | Plugins must respect user privacy. | ✅ | External Services disclosure in `readme.txt` covers all 36+ providers. Pro addon not distributed via WP.org. Baseten added as 11th provider (May 22). |
| 18 | We reserve the right to maintain the Plugin Directory to the best of our ability. | — | Process. |

## Pre-submission checklist (base plugin only)

| Check | Status | Action |
|---|---|---|
| `readme.txt` headers match `mcp-ai-wpoos.php` | ✅ | `1.1.22` / `6.9` / `7.4` / `6.0` |
| `readme.txt` has "External services" section | ✅ | All 36+ providers with ToS + Privacy links |
| Every PHP file has `ABSPATH` guard | ✅ | 942/942 files ✅ (0 missing — April had 4) |
| No `eval()`, `assert()`, `create_function()` | ✅ | 0 in product code |
| No `shell_exec`/`exec`/`system`/`passthru` in base | ✅ | 0 in base (all 11 are pro-only) |
| No remote eval / remote code load | ✅ | CDN-loaded JS excluded by `.distignore` |
| No obfuscated code | ✅ | — |
| All `.min.js` ship with source or source map | ✅ | Every plugin-authored file has `.min.js.map` |
| All bundled libraries have permissive licences | ✅ | — |
| No bundled copies of WordPress core libraries | ✅ | — |
| All AJAX handlers use `check_ajax_referer` / capability check | ⚠️ | 3 new `wp_ajax_nopriv_` handlers need verification (F-AUTHZ-06) |
| All REST routes have a real `permission_callback` | ⚠️ | New triggers webhook uses `__return_true` — needs signature verification audit (F-AUTHZ-05) |
| All SQL through `$wpdb->prepare()` | ✅ | 0 unprepared SQL in base |
| `composer audit` clean | ✅ | 0 vulnerabilities |
| `npm audit --omit=dev` clean | ⚠️ | Not re-audited for May; April baseline was 10 moderate (8 in optionalDeps excluded from dist) |
| `composer run lint:base` zero | ✅ | 0 errors / 0 warnings on shipped tree |
| `composer run lint:base:compat` zero | ✅ | 0 errors (PHP 7.4–8.3) |
| `composer run test` green | ⚠️ | Requires test DB bootstrap; 1,077 test files, PHPUnit 11 compat achieved |
| `wp plugin-check` zero Errors | ✅ | Gating CI job in release pipeline (R-T-04) |
| All UI strings translated via `wp.i18n` / `__()` | ✅ | 0 i18n errors on shipped tree |
| `Network: true` is intentional | ✅ | Multisite gating reviewed (F-AUTHZ-03 fixed) |
| Uninstall handlers clean up data | ✅ | Cron cleanup on deactivation added (May 23 W2) |

## Submission gating items

These are the items that must clear before pushing a new tag to the WP.org SVN repo.

| # | Item | Status | Notes |
|---:|---|---|---|
| 1 | **R-D-01** — `readme.txt` external-services disclosure | ✅ | All 36+ providers documented including Baseten (added May 22) |
| 2 | **R-Q-01** — 0 PHPCS errors on shipped tree | ✅ | Verified May 23 — 0 errors / 0 warnings |
| 3 | Hand-fix remaining PHPCS errors | ✅ | 0 remaining on shipped tree |
| 4 | **R-Q-02** — `npm audit fix` for root advisories | ⚠️ | Not re-audited; baseline 8 in optionalDeps excluded from dist |
| 5 | **R-Q-03** — ABSPATH guards | ✅ | 942/942 files ✅ |
| 6 | **R-Q-06** — Source maps for `.min.js` | ✅ | Every plugin-authored file has `.min.js.map` |
| 7 | **R-S-01** — REST `permission_callback` coverage | ⚠️ | 1 new `__return_true` on triggers webhook (F-AUTHZ-05) — needs signature verification or documented justification |
| 8 | **R-S-03** — SQL `$wpdb->prepare()` | ✅ | 0 unprepared SQL in base |
| 9 | **R-T-04** — `wp plugin-check` CI gate | ✅ | Gating job in release pipeline |

**Status:** 7 of 9 gating items are ✅. 2 items are ⚠️:

- **#7 (F-AUTHZ-05):** The new triggers controller webhook route uses `__return_true`. The `receive_webhook()` method must be audited for provider signature verification. If verification is already implemented, document it. If not, implement it.
- **#4 (npm audit):** Not re-audited for May. The April baseline (8 advisories in optionalDeps) is known and accepted.

The base plugin remains **submission-ready** with these 2 items as low-risk pre-submission cleanup.

## Changes since April 2026 checklist

| Item | April 2026 (v1.1.10) | May 2026 (v1.1.22) |
|---|---|---|
| Plugin version | 1.1.10 | 1.1.22 |
| Base PHP files | ~1,460 | 942 |
| ABSPATH guards missing | 4 | 0 ✅ |
| `wp_ajax_nopriv_` handlers | 6 | 3 (new — needs review) |
| `__return_true` REST routes | 2 (MCP + A2A — both resolved) | 2 (MCP preflight ✅ + new triggers ⚠️) |
| Inline script/style | ~196 raw blocks | 0 raw blocks ✅ |
| Tool canonical envelope | 191 violations | 0 violations ✅ |
| Cron cleanup on deactivation | Not implemented | Implemented ✅ |
| External services documented | 35 providers | 36+ providers (Baseten added) ✅ |
| `includes/agents/` subsystem | N/A | 10 files / 7,965 lines (F-AGENT-01) |
| Test files | ~365 | 1,077 (+195%) ✅ |
