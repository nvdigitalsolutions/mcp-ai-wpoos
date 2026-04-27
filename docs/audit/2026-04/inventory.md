# Phase 1 — Inventory & Baseline

> **As-of:** 2026-04-26 · branch `copilot/review-addons-plugins-security`
> Reproducible from the commands recorded in [`automated-scan-results.md`](./automated-scan-results.md).

## 1. PHP file census

| Tree | Path | PHP files (excl. `vendor/` & `node_modules/`) |
|---|---|---:|
| Base entry points | `mcp-ai-wpoos.php`, `mcp-ai-wpoos-base.php` | 2 |
| Base | `includes/` | ~1,460 |
| Pro addon | `addons/pro/` | 1,141 |
| Algorave addon | `addons/algorave/` | 20 |
| Canvas addon | `addons/canvas/` | 2 |
| Cornerstone3D addon | `addons/cornerstone3d/` | 2 |
| Embedded addon | `addons/embedded/` | 36 |
| Fantasy-football addon | `addons/fantasy-football/` | 25 |
| Graphify addon | `addons/graphify/` | 22 |
| Bundled `core/` / `shared/` / `packages/` / `src/` | various | ~250 |
| **Total** | — | **2,983** |

## 2. Tool classes

| Location | Tool classes |
|---|---:|
| `includes/tools/` (and recursive) | **231** |
| `addons/pro/includes/tools/` (and recursive) | **584** |
| `addons/{algorave,graphify,fantasy-football,embedded}/.../tools/` | ~30 |
| **Total** | **~845** |

The plugin's README/CLAUDE.md says "519 tools." That string is stale — the live count is materially higher and should be updated in `readme.txt`, `CLAUDE.md`, and `.github/copilot-instructions.md` (see roadmap **R-D-04**).

## 3. REST API surface

- **Single namespace** in production: `mcp-ai/v1` (190 `register_rest_route` calls across 21 controllers).
- 21 controllers register routes; 12 are in `includes/rest/`, the rest are scattered (`includes/integrations/`, `includes/class-wp-mcp-ai-*-rest.php`, `includes/admin/class-wp-mcp-ai-pro-dashboard-rest.php`).
- **14** `register_rest_route` calls bypass authentication via `'permission_callback' => '__return_true'`. Of those, **6 are in pro addon webhook handlers** (Telegram, Twitter, WhatsApp, Messenger, Google Chat) which is **expected** for inbound webhooks — but each must independently verify provider signatures (HMAC). See [`findings-register.md`](./findings-register.md) **F-AUTHZ-01**.
- The remaining 8 are in:
  - `includes/rest/class-wp-mcp-ai-rest-mcp-controller.php:140`
  - `includes/rest/class-wp-mcp-ai-rest-a2a-controller.php:104, 116`
  - `addons/pro/includes/src/ChatChannels/class-wp-mcp-ai-google-chat-webhook-handler.php:54`
  - and three more pro webhook controllers — each requires per-route review (finding **F-AUTHZ-01**).

## 4. AJAX handlers

| | Count |
|---|---:|
| `add_action( 'wp_ajax_…', … )` | 313 |
| `add_action( 'wp_ajax_nopriv_…', … )` | **6** |

The 6 `wp_ajax_nopriv_*` handlers are the highest-priority manual-review targets (CSRF-only protection). Listed in [`findings-register.md`](./findings-register.md) **F-AUTHZ-02**.

## 5. CLI commands

23 `WP_CLI::add_command()` registrations; namespaces:
`mcp-ai`, `mcp-ai assistant`, `mcp-ai connection`, `mcp-ai content`, `mcp-ai credential`, `mcp-ai dlq`, `mcp-ai log`, `mcp-ai measurement`, `mcp-ai plugins`, `mcp-ai pro status`, `mcp-ai project`, `mcp-ai queue`, `mcp-ai rabbitmq`, `mcp-ai settings`, `mcp-ai sla`, `mcp-ai slash`, `mcp-ai stdio`, `mcp-ai task`, `mcp-ai token`, `mcp-ai tool`, `mcp-ai toolkit`, `profession orchestration-stats`, `profession seed-orchestration`.

## 6. Cron jobs

89 calls to `wp_schedule_event` / `wp_schedule_single_event`. Recurring hooks include:
`wp_mcp_ai_daily`, `wp_mcp_ai_hourly_forecast_check`, `wp_mcp_ai_check_license`, `wp_mcp_ai_dependency_scan`, `wp_mcp_ai_cleanup_*` (5 cleanup hooks), `wp_mcp_ai_asset_discovery`, `wp_mcp_ai_model_catalog_discovery`, `wp_mcp_ai_prune_expired_contexts`, `wp_mcp_ai_quarterly_audit`, `wp_mcp_ai_send_report`, `wp_mcp_ai_supplier_review`, `wp_mcp_ai_annual_training_reminder`, `wp_mcp_ai_dlq_cleanup`, `wp_mcp_ai_google_chat_send_welcome_message`, `wp_mcp_ai_download_optional_components`.

## 7. Shortcodes

24 shortcodes registered. Highlights:
- `algorave_live_coder`, `algorave_pattern_library` (algorave)
- `nvoos_graphify` (graphify)
- `health_chart` (pro / health-wellness)
- `mcp_calendar_booking_form`, `mcp_calendar_services`, `mcp_calendar_staff` (pro)
- 9 `mcp_*_*` shortcodes for Pro CCT/CPT renderers (orders, products, glossaries, etc.)
- `mcp_ai_telegram_login`, `mcp_ai_tool_builder_*`

Each shortcode renders user/assistant data and must be re-verified for output escaping ([`findings-register.md`](./findings-register.md) **F-XSS-01**).

## 8. Gutenberg blocks

14 `register_block_type` registrations across base + pro + addons. Covered by `addons/pro/build/workflow-builder/` and `includes/blocks/`.

## 9. Top-level entry-point manifest

| File | Plugin header? | Role |
|---|---|---|
| `mcp-ai-wpoos.php` | ✅ | Main plugin entry (full version, network: true) |
| `mcp-ai-wpoos-base.php` | ✅ (alt) | Base-only entry for WP.org distribution |
| `addons/pro/mcp-ai-wpoos-pro.php` | ❌ (intentional in repo, header injected at build) | Pro entry, auto-loaded by main when present |
| `addons/embedded/uninstall.php` | n/a (uninstall) | **Missing `ABSPATH` guard** — finding **F-CMP-02** |

## 10. Dependency SBOM (root + pro)

### 10.1 Composer (root) — production

From `composer.json` `require`:

| Package | Version constraint | License | GPL-compat |
|---|---|---|---|
| `rahul900day/tiktoken-php` | ^1.0 | MIT | ✅ |
| `symfony/http-client` | ^6.1 \| ^7.0 | MIT | ✅ |
| `nyholm/psr7` | ^1.8 | MIT | ✅ |
| `symfony/validator` | >=6.4.36 | MIT | ✅ |
| `symfony/cache` | >=6.4.36 | MIT | ✅ |
| `symfony/filesystem` | ^6.4 \| ^7.0 | MIT | ✅ |
| `symfony/process` | ^6.4 \| ^7.0 | MIT | ✅ |
| `league/oauth2-client` | ^2.7 | MIT | ✅ |
| (transitive) `guzzlehttp/guzzle`, `psr-7`, `psr-promises` | — | MIT | ✅ |

`composer audit` (root): **0 vulnerabilities**.

### 10.2 Composer (pro) — production

| Package | License | GPL-compat | Notes |
|---|---|---|---|
| `dvdoug/boxpacker` | MIT | ✅ | |
| `phpoffice/phpspreadsheet` | MIT | ✅ | |
| `phpoffice/phpword` | LGPL-3.0-only | ✅ | LGPL is GPL-compatible. |
| `phpoffice/math` | MIT | ✅ | |
| `dompdf/dompdf` | LGPL-2.1 | ✅ | |
| `dompdf/php-svg-lib` | LGPL-3.0-or-later | ✅ | |
| `dompdf/php-font-lib` | LGPL-2.1-or-later | ✅ | |
| `tecnickcom/tcpdf` | LGPL-3.0-or-later | ✅ | |
| `smalot/pdfparser` | LGPL-3.0 | ✅ | |
| `thiagoalessio/tesseract_ocr` | MIT | ✅ | |
| `masterminds/html5` | MIT | ✅ | |
| `thecodingmachine/safe` | MIT | ✅ | |
| `sabberworm/php-css-parser` | MIT | ✅ | |
| `maennchen/zipstream-php` | MIT | ✅ | |
| `markbaker/{matrix,complex}` | MIT | ✅ | |
| `composer/pcre`, `psr/{log,simple-cache}`, `symfony/polyfill-mbstring` | MIT | ✅ | |

`composer audit` (pro): **0 vulnerabilities**.

### 10.3 npm (root, production-only)

`npm audit --omit=dev` reports **10 moderate** advisories — all auto-fixable:

| Package | Advisory | Severity | Fix |
|---|---|---|---|
| `langsmith` ≤ 0.5.23 | depends on vulnerable `uuid` | moderate | `npm audit fix` |
| `yaml` 2.0.0–2.8.2 | GHSA-48c2-rrv3-qjmp Stack-overflow on deeply nested YAML | moderate | `npm audit fix` |
| `uuid` < 14.0.0 | GHSA-w5hq-g745-h8pq missing buffer-bounds check (v3/v5/v6) | moderate | `npm audit fix --force` (breaking) |
| `exceljs` ≥ 3.5.0 | depends on vulnerable `uuid` | moderate | downstream of `uuid` fix |
| (and 6 transitive duplicates) | — | moderate | — |

### 10.4 npm (pro, production-only)

3 moderate advisories: `postcss < 8.5.10`, `uuid < 14.0.0`, downstream `exceljs`. All auto-fixable.

See [`automated-scan-results.md`](./automated-scan-results.md) for raw output.

## 11. CI / lint exclusions for `addons/pro/`

| File | Line / setting | Effect |
|---|---|---|
| `phpcs.xml.dist` | line 24 — `<exclude-pattern>*/addons/pro/*</exclude-pattern>` | **PHPCS skips all of `addons/pro/`.** |
| `composer.json` `lint:base` script | `--ignore=…,addons/pro,addons/pro/vendor,…` | The CI lint job (`composer run lint:base`) explicitly skips pro. |
| `phpunit.xml.dist` | `<directory suffix=".php">addons/pro/tests</directory>` | Pro tests **are included** — the only place pro is covered. |
| `.eslintignore` | does **not** explicitly exclude `addons/pro/` | Pro JS would be linted, but… |
| `.github/workflows/php-linting.yml` | uses `composer run lint:base` | CI inherits the pro exclusion. |
| `.github/workflows/security.yml` | runs `composer audit` and `npm audit` (root only) | Pro composer/npm audits **not run in CI**. |

**Implication.** Phase 2 of this audit ran PHPCS against the pro tree on a one-shot basis on this branch. The exclusion in `phpcs.xml.dist` has not been removed; doing so is roadmap item **R-T-01** because it will surface a large initial backlog and should land alongside an autofix pass. The pro `composer audit` / `npm audit` should be added to CI as roadmap item **R-T-02**.

## 12. Baseline CI snapshot (this branch, pre-changes)

| Command | Outcome |
|---|---|
| `composer install` | ✅ 52 packages |
| `composer audit` (root) | ✅ 0 vulnerabilities |
| `composer audit` (pro, run from `addons/pro`) | ✅ 0 vulnerabilities |
| `composer run lint:base` | ❌ **330 errors in 73 files** (902 files scanned). 168 auto-fixable by PHPCBF. See [`automated-scan-results.md`](./automated-scan-results.md). |
| `vendor/bin/phpcs` with WordPress.DB.PreparedSQL sniffs | ❌ **8 errors in 3 files** (`addons/graphify/...db.php`, `addons/graphify/...report.php`, `includes/class-wp-mcp-ai-model-catalog-migration.php`) — see **F-SQL-01**, **F-SQL-02** |
| `npm audit --omit=dev` (root) | ⚠️ 10 moderate (auto-fixable) |
| `npm audit --omit=dev` (pro) | ⚠️ 3 moderate (auto-fixable) |

PHPUnit was **not** executed in this audit (requires WordPress test DB; out of scope of read-only audit phase).

CodeQL workflow status (`.github/workflows/security.yml`) — exists, runs `composer audit`, `npm audit`, PHPStan with security rules, plus grep-based hardcoded-secret / SQL-injection / XSS / nonce / file-include checks. Adding `security-extended` query suite is roadmap item **R-T-03**.
