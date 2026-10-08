# WordPress.org 18-Point Guideline Review — Sign-Off

**Plugin:** NV oOS Content Graph (`nvoos-content-graph`)
**Version under review:** 1.0.7
**Review date:** 2026-09-11
**Reviewer:** AI Agent (mcp-ai-wpoos-wporg-submission skill, 18-point pass)
**Tree:** `alpha-working` @ `4d7a97df5c` (tag `content-graph-v1.0.7`)

> Internal review record. Excluded from the distribution ZIP via
> `.distignore` (`WPORG-REVIEW-*.md`), mirrored in
> `.github/workflows/build-nvoos-content-graph.yml` and
> `bin/build-nvoos-content-graph.sh`.

---

## Overall Verdict

**18/18 guidelines satisfied** (16 verified PASS, 2 informational process
items). No blocking findings. Two follow-up actions remain outside the code
tree: the v1.0.7 SVN upload and the wp.org reviewer's re-check of the
commerce flow (notes pre-answered in `WPORG-REVIEW-COMMERCE-NOTES.md`).

---

## Guideline-by-Guideline Results

| # | Guideline | Status | Evidence |
|---|---|---|---|
| 1 | GPL-compatible licensing | ✅ PASS | Header `License: GPL-3.0-or-later` + `License URI`; root `LICENSE` (GPLv3, added in PR #6595); `composer.json` `license: GPL-3.0-or-later`; PCP readme license check clean |
| 2 | Developer responsible for all contents; third-party licenses | ✅ PASS | `readme.txt == Third-Party Libraries ==` credits Cytoscape.js 3.28.1, cytoscape-fcose 2.2.0, layout-base 2.0.1, cose-base 2.2.0 (all MIT, repo links); each ships `LICENSE` in `assets/vendor/<lib>/` (verified present for all four) |
| 3 | Stable version available from directory | ✅ PASS ⚠️ | Directory live at https://wordpress.org/plugins/nvoos-content-graph/ (v1.0.3). **Follow-up:** upload v1.0.7 to SVN `trunk/` + `tags/1.0.7` |
| 4 | Human-readable code (no obfuscation) | ✅ PASS | Plugin source ships unminified; only minified file is vendored `assets/vendor/cytoscape/cytoscape.min.js` (upstream official build, MIT, LICENSE alongside). No packers/mangled names |
| 5 | No trialware / paywalled features | ✅ PASS | Core engine complete without payment; checkout sells the separate NV oOS Complete bundle off-directory. `WPORG-REVIEW-COMMERCE-NOTES.md` guideline mapping rows 5 + 18; readme FAQ "Do I have to pay…" |
| 6 | SaaS permitted; services documented with ToS links | ✅ PASS | `readme.txt == External services ==`: Wikidata (Wikimedia ToS + Privacy URLs), user-configured remote sources, Stripe (privacy URL), vendor checkout server, GitHub download — all with what/when/why sent |
| 7 | No tracking without consent; no phone-home | ✅ PASS | No analytics/telemetry (grep: 0 tracking calls). `wp_remote_*` only in `src/Remote/HttpClient.php` (SSRF-guarded fetcher) and `src/Commerce/Vendor.php` (user-initiated checkout). Cron only for the opt-in Scheduled Rebuild (default "Never"). `== Privacy Notice ==` present |
| 8 | No third-party executable code; no CDN-loaded JS/CSS | ✅ PASS ⚠️ | Grep for cdnjs/unpkg/jsdelivr/googleapis/bootstraps CDNs: 0 matches. Documented exception: `js.stripe.com/v3/` injected only when the purchase modal opens (`content-graph-commerce.js` L713 comment + L726), disclosed in readme. Installer uses core `download_url()`/`Plugin_Upgrader` after explicit purchase; manual install is the documented primary path |
| 9 | Nothing illegal, dishonest, or offensive | ✅ PASS | Manual review: no fake scarcity, anchors, or release-date promises (hard rules in `docs/checkout-enhancement-plan.md`); no black-hat SEO |
| 10 | No unsolicited embedded links/credits | ✅ PASS | Grep "powered by / credit / attribution": 0 matches. Upsell is a settings-page card opened on click only |
| 11 | Dismissible, contextual admin notices | ✅ PASS | `Plugin::renderAdminNotices()` (L366–406): transient success notice is `is-dismissible` + self-removes; OpenSSL and "graph not enabled" warnings scoped to the plugin page via `get_current_screen()` + `is-dismissible` |
| 12 | Readme not spammy (≤5 tags, no affiliates) | ✅ PASS | 5 standard tags; no affiliate/referral links (grep: 0); written for end users |
| 13 | Use WP bundled libraries; don't ship own copies | ✅ PASS | Runtime composer deps: `php ^8.1` only. Dev deps (phpunit/wpcs) excluded from ZIP via `composer install --no-dev` in CI + `.distignore` (`/vendor.bak-phpunit-conflict`, `/tests`, `/node_modules`). No jQuery/SimplePie copies ship |
| 14 | Avoid frequent SVN commits; descriptive messages | ℹ️ INFO | Process note: commit-per-release discipline; SVN upload runbook in `.wordpress-org/README.md` |
| 15 | Version increments; trunk readme reflects current version | ✅ PASS | Header `Version: 1.0.7` == readme `Stable tag: 1.0.7`; `Tested up to: 7.1` (current stable); `CHANGELOG.md` 1.0.7 entry dated 2026-09-11; readme changelog section current |
| 16 | Complete plugin at submission | ✅ PASS | `readme.txt` at plugin root; `LICENSE`; `languages/nvoos-content-graph.pot`; PSR-4 autoloader ships in ZIP (CI assemble step + `bin/build-nvoos-content-graph.sh` both verify `vendor/autoload.php`) |
| 17 | Trademark-safe slug | ✅ PASS | Slug `nvoos-content-graph` — no third-party term as initial term ("Graphify" removed in v1.0.2); "WordPress" used descriptively only |
| 18 | Directory team reserves maintenance rights | ℹ️ INFO | Factored into submission timeline; review-feedback loop assumed |

---

## Verification Sweep (executed 2026-09-11)

| Check | Command / Target | Result |
|---|---|---|
| CDN hosts in tree | grep `cdn./cdnjs/unpkg/jsdelivr/googleapis` over `plugins/nvoos-content-graph/**` | 0 matches |
| Remote calls | grep `wp_remote_*` over `src/**/*.php` | 2 files only: `Remote/HttpClient.php`, `Commerce/Vendor.php` |
| Background scheduling | grep `wp_schedule_*` / `as_enqueue_async_action` | 0 unsolicited; cron files = Scheduled Rebuild (opt-in) + schema |
| Attribution / credit links | grep `powered by/credit/attribution` | 0 matches |
| Affiliate links | grep `ref=/affiliate/referral` over readme/README | 0 matches |
| Stray text domains | grep `'mcp-ai-wpoos'/'nvoos-graphify'/'wp-mcp-ai'` | 0 matches (domain is `nvoos-content-graph` throughout) |
| External enqueues | grep `wp_enqueue_*` containing `http` | 0 matches (Stripe.js injected on modal open, not enqueued) |
| Minified files | `find … -name "*.min.js"` | 1 shipping file: vendored cytoscape (upstream build); dev-only phpunit templates excluded from ZIP |
| Vendor licenses | `assets/vendor/{cytoscape,cytoscape-fcose,layout-base,cose-base}/LICENSE` | All 4 present |
| Root license | `LICENSE` | Present (GPLv3) |
| Listing assets | `.wordpress-org/assets/` | All 10 PNGs present (2 icons, 2 banners, 6 screenshots), matching readme `== Screenshots ==` |
| Packaging exclusions | `.distignore` + workflow rsync + build script | Tri-synced; `WPORG-REVIEW-*.md`, `docs/checkout-enhancement-plan.md`, `vendor.bak-*`, tests, node_modules all excluded |
| i18n | `languages/nvoos-content-graph.pot` | Present; header `Text Domain: nvoos-content-graph` == slug |
| Notices | `src/Plugin.php` L366–406 | Transient success (dismissible, self-removes); warnings plugin-page-scoped + dismissible |

## Automated Gates (independent of this review)

- **Plugin Check (PCP):** passed on the 1.0.7 tree earlier today
  (check run "Plugin Check (content-graph)" on merge commit `f63a589f8d`,
  success at 2026-09-11T08:20Z; PR #6595 measured **0 ERRORs** against the
  ZIP-shaped tree). Re-running on the release tag pipeline
  (`content-graph-v1.0.7`, run #34585244143) at the time of writing.
- **PHPUnit:** 116 tests / 570 assertions green (PR #6595 evidence;
  `phpunit-content-graph.yml` also re-running on the tag).
- **phpcs** (`WordPress-Extra`): clean. **`php -l`**: all files pass.
  No PHP 8.2+ syntax (plugin requires 8.1).

---

## Follow-Up Actions (outside this repo's code)

1. Upload `build/nvoos-content-graph-v1.0.7.zip` to the wp.org SVN
   (`trunk/` + `tags/1.0.7`) via the "Add your plugin" flow.
2. Expect a commerce-flow question from the review team — reply material
   pre-prepared in `WPORG-REVIEW-COMMERCE-NOTES.md`.
3. Listing assets already in `.wordpress-org/assets/` (icon v5 master,
   banner, 6 screenshots) — re-upload with the new version if changed.

## Re-verification (2026-09-13, final pre-upload pass)

Three commerce PRs landed on `alpha-working` after the 18-point pass above
(#6603 vendor-price sync, #6609 seller-of-record copy, #6612
price-subject-to-change note). All were re-checked before upload:

- Full deltas reviewed against guidelines 1–18 — benign commerce
  copy/price/i18n changes; no new remote hosts, endpoints, or notices.
- PCP re-run on the exact upload ZIP: 0 ERRORs, 160 WARNINGs (unchanged
  known/false-positive categories: direct-DB queries against
  constant-derived table names, prefixed-hook constant indirection,
  slow meta queries).
- CI green on the shipping tree (`fca2a957df`): build, plugin-check,
  publish, and the `phpunit-content-graph` suite (116 tests).
- Fixes applied in this pass: tri-sync gap (the build script and workflow
  rsync lists were missing the `.distignore` `node_modules` exclude, so
  local rebuilds shipped 9.4 MB of node_modules); POT
  `Project-Id-Version` bumped to 1.0.7; readme changelog dated
  2026-09-12 with the three late fixes; `CHANGELOG.md` "Unreleased"
  folded into 1.0.7.
- ZIP rebuilt (480 KB) via `bin/build-nvoos-content-graph.sh`;
  SHA-256 recorded alongside the artifact in `build/`.

## Re-verification (2026-10-08, 1.0.9 pre-upload pass)

The 1.0.9 release folds in the purchase-modal polish (loading indicator,
free base-version link, development-status note), the remote-source driver
fixes, and the wp.org review findings fixed in this pass. Everything below
was re-checked against guidelines 1–18 before upload:

- **Reviewer-category fix — pre-init cron scheduling:** the boot-path
  `syncRebuildSchedule()` call ran on `plugins_loaded`; when no rebuild
  event existed, `wp_schedule_event()` consulted `wp_get_schedules()`
  before translations load on WP 6.7+, so a WooCommerce site could emit the
  "translation loading triggered too early" notice. Scheduling now hooks to
  `init` (activation-time scheduling is unchanged — it runs post-init).
- **Tri-sync fix:** `bin/build-nvoos-content-graph.sh` was missing the
  `blueprints/` rsync exclude that the CI workflow and `.distignore`
  already carried (the PR #6662 drift class). Added, so local rebuilds can
  no longer leak the dev blueprint seeds into the ZIP.
- **Readme disclosure:** the new free base-version download link is
  disclosed in `== External services ==` (browser-side GitHub link, no data
  sent) and the payment FAQ; the 1.0.9 changelog entry lists the modal
  changes and the cron fix.
- **Guideline 15 — version bump:** header `Version` and
  `NVOOS_CONTENT_GRAPH_VERSION` 1.0.8 → 1.0.9; readme `Stable tag: 1.0.9`;
  readme changelog 1.0.9 entry dated 2026-10-08; `CHANGELOG.md` top
  "Unreleased" section folded into 1.0.9.
- **i18n:** POT regenerated via `wp i18n make-pot` — `Project-Id-Version`
  1.0.9, +3 msgids (payment loading, free option, dev status), 0 msgids
  lost. Text Domain `nvoos-content-graph` == slug throughout.
- **Sweeps re-run clean:** no `Tested up to` in the PHP header (readme
  only); no `load_plugin_textdomain` calls; admin notices plugin-page
  scoped + dismissible (transient success self-removes); no secrets in
  localized payloads; every REST route has a named capability callback
  (commerce routes `manage_options`-only, no `__return_true`);
  deactivation clears all cron hooks and uninstall.php drops tables,
  options, transients, and hooks with no recursive file deletes.
- **Commerce copy:** loading spinner, free-version link, and the
  development-status note are benign, honest additions (no dates, no
  urgency tactics); the guarantee wording matches the published Refund
  Policy. Commerce notes updated (`WPORG-REVIEW-COMMERCE-NOTES.md` v1.0.9).
- **Automated gates:** PHPUnit Unit 154 tests / 725 assertions and
  Integration 18 tests / 57 assertions green (Docker, WP 6.9); `node
  scripts/verify-commerce-fallback.js` 7/7; phpcs 0 errors on changed
  files; `php -l` clean. Local PCP run on the ZIP-shaped tree: see the
  1.0.9 check record below.

### PCP (Plugin Check) — 1.0.9 pre-upload run

Executed 2026-10-08 on a ZIP-shaped tree staged with the workflow's exact
rsync exclude list (4,879 files, `readme.txt` at the root, `vendor/autoload.php`
intact): **PCP exit 0, 0 ERRORs, 161 WARNINGs** — all in the known non-blocking
categories (constant-derived table names flagged as DirectDB/UnescapedDBParameter,
dynamic hook names derived from prefixed constants, slow meta-query pattern on
the graph tables). No new categories versus the 1.0.7 record (160).
