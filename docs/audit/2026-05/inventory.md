# Phase 1 — Inventory & Baseline

> **As-of:** 2026-05-25 · branch `alpha-working` · v1.1.22
> Reproducible from the commands recorded in [`automated-scan-results.md`](./automated-scan-results.md).

## 1. PHP file census

| Tree | Path | PHP files (excl. `vendor/` & `node_modules/`) |
|---|---|---:|
| Base entry points | `mcp-ai-wpoos.php` | 1 |
| Base | `includes/` | **942** |
| Pro addon | `addons/pro/` | 1,141 |
| Algorave addon | `addons/algorave/` | 20 |
| Canvas addon | `addons/canvas/` | 2 |
| Cornerstone3D addon | `addons/cornerstone3d/` | 2 |
| Embedded addon | `addons/embedded/` | 36 |
| Fantasy-football addon | `addons/fantasy-football/` | 25 |
| Graphify addon | `addons/graphify/` | 22 |
| Docs-hub addon | `addons/docs-hub/` | ~15 |
| Other addons | `addons/{chat-spa,canvas-toolkit,document-editor,media-studio,toolkit-shell,saas-controller,cloud-worker}/` | ~120 |
| Bundled `core/` / `shared/` / `packages/` / `src/` | various | ~250 |
| **Total** | — | **~2,600** |

> **Note:** April 2026 counted ~1,460 base PHP files. The 35% reduction (1,460 → 942) is primarily from consolidation of admin section files, removal of the `professions/` legacy format, and cleanup of redundant helper classes. The exact delta should be attributed to the May 9 compliance pass inline-script migration and the May 19–23 tool canonical-envelope refactor.

## 2. Tool classes

| Location | Tool classes |
|---|---:|
| `includes/tools/` (and recursive) | **247** |
| `addons/pro/includes/tools/` (and recursive) | **584** |
| `addons/{algorave,graphify,fantasy-football,embedded,...}/.../tools/` | ~30 |
| **Total** | **~831** |

The April 2026 audit reported 231 base tools; the delta (+16) comes from new tools added in May (sitekit-analytics rewrite, payhere/flowhub availability gates, plus new tools landed alongside the agents subsystem).

## 3. REST API surface

- **Single namespace:** `mcp-ai/v1` (**127** `register_rest_route` calls across controllers — down from 190 in April).
- The reduction is attributable to the May compliance pass consolidating redundant route registrations.
- **34** `__return_true` references detected in `includes/`. Of those:
  - **22** are `auth_callback => '__return_true'` on `register_meta()` calls for the Professions CPT (20) and Teams CPT (2) — this is the standard WordPress pattern for OAuth/SSO-driven meta endpoints and is **acceptable**.
  - **1** is `permission_callback => '__return_true'` on the MCP controller OPTIONS preflight (`class-wp-mcp-ai-rest-mcp-controller.php:145`) — **acceptable** (CORS preflight).
  - **1** is `permission_callback => '__return_true'` on the **new** Triggers controller webhook endpoint (`class-wp-mcp-ai-rest-triggers-controller.php:122`) — **needs review** (see F-AUTHZ-05). Must verify webhook signature inside callback.
  - **2** are filter documentation comments in `class-wp-mcp-ai-lm-studio-client.php:87` and `class-wp-mcp-ai-workflow-engine-v2.php:31` — **informational only** (not permission callbacks).
  - **8** additional occurrences in `includes/professions/` and `includes/teams/` CPT `auth_callback` entries — already counted in the 22 above.

## 4. AJAX handlers

| | Count |
|---|---:|
| `add_action( 'wp_ajax_…', … )` | ~310 |
| `add_action( 'wp_ajax_nopriv_…', … )` | **3** |

The 3 `wp_ajax_nopriv_` handlers are all in `includes/class-wp-mcp-ai-professional-selector-shortcode.php`:
- `wp_mcp_ai_get_professional_config`
- `wp_mcp_ai_get_models_for_provider`
- `wp_mcp_ai_render_professional_chat`

These are the highest-priority manual-review targets — each must be verified for nonce + rate-limit coverage. See **F-AUTHZ-06**.

April 2026 had 6 `wp_ajax_nopriv_` handlers; the reduction comes from the Wave 16 audit (R-S-07) removing the dead `wp_ajax_nopriv_` registration from `wp_mcp_ai_execute_quick_action`.

## 5. CLI commands

23 `WP_CLI::add_command()` registrations (unchanged from April). Namespaces:
`mcp-ai`, `mcp-ai assistant`, `mcp-ai connection`, `mcp-ai content`, `mcp-ai credential`, `mcp-ai dlq`, `mcp-ai log`, `mcp-ai measurement`, `mcp-ai plugins`, `mcp-ai pro status`, `mcp-ai project`, `mcp-ai queue`, `mcp-ai rabbitmq`, `mcp-ai settings`, `mcp-ai sla`, `mcp-ai slash`, `mcp-ai stdio`, `mcp-ai task`, `mcp-ai token`, `mcp-ai tool`, `mcp-ai toolkit`, `profession orchestration-stats`, `profession seed-orchestration`.

## 6. Cron jobs

**63** calls to `wp_schedule_event` / `wp_schedule_single_event` in `includes/` (down from 89 in April). Recurring hooks include:
`wp_mcp_ai_daily`, `wp_mcp_ai_hourly_forecast_check`, `wp_mcp_ai_check_license`, `wp_mcp_ai_dependency_scan`, `wp_mcp_ai_cleanup_*` (5 cleanup hooks), `wp_mcp_ai_asset_discovery`, `wp_mcp_ai_model_catalog_discovery`, `wp_mcp_ai_prune_expired_contexts`, `wp_mcp_ai_quarterly_audit`, `wp_mcp_ai_send_report`, `wp_mcp_ai_supplier_review`, `wp_mcp_ai_annual_training_reminder`, `wp_mcp_ai_dlq_cleanup`, `wp_mcp_ai_audit_trail_prune` (new), `wp_mcp_ai_approval_queue_cleanup` (new), `wp_mcp_ai_async_job_queue`, `wp_mcp_ai_async_job_queue_cleanup`, `wp_mcp_ai_cleanup_token_tracking`, `wp_mcp_ai_cleanup_job_cache`.

**May 23 fix (W2):** All 11 cron hooks now unscheduled on deactivation via `wp_mcp_ai_deactivate_single_site()`.

## 7. Shortcodes

24 shortcodes registered (unchanged from April). Highlights:
- `algorave_live_coder`, `algorave_pattern_library` (algorave)
- `nvoos_graphify` (graphify)
- `health_chart` (pro / health-wellness)
- `mcp_calendar_booking_form`, `mcp_calendar_services`, `mcp_calendar_staff` (pro)
- 9 `mcp_*_*` shortcodes for Pro CCT/CPT renderers
- `mcp_ai_telegram_login`, `mcp_ai_tool_builder_*`
- `mcp_ai_professional_selector` (new — powers the `wp_ajax_nopriv_` handlers)

## 8. Gutenberg blocks

**12** `register_block_type` registrations in `includes/` (down from 14 in April).

## 9. New: `includes/agents/` subsystem (May 22–23, 2026)

| File | Lines | CoSAI Principle | Purpose |
|---|---|---|---|
| `class-wp-mcp-ai-agent-capability-boundary.php` | 638 | P2 — Bounded & Resilient | Defines what each agent role can/cannot do |
| `class-wp-mcp-ai-agent-audit-trail.php` | 1,664 | P3 — Transparent & Verifiable | Logs every agent action to `mcp_ai_audit_event` CPT |
| `class-wp-mcp-ai-agent-approval-gate.php` | 494 | P1 — Human-Governed | Requires human approval for high-risk actions |
| `class-wp-mcp-ai-agent-code-sandbox.php` | 696 | MCP-T3/T5 — Sandbox | `proc_open` sandbox with timeout, output caps, stripped env |
| `class-wp-mcp-ai-agent-harness-bootstrap.php` | 788 | — | Agent harness lifecycle management |
| `class-wp-mcp-ai-agent-harness-evolver.php` | 1,964 | — | Harness self-improvement / evolution |
| `class-wp-mcp-ai-agent-role-base.php` | 221 | — | Abstract base for agent role classes |
| `class-wp-mcp-ai-agent-role-critic.php` | 281 | — | Critic agent role implementation |
| `class-wp-mcp-ai-agent-role-executor.php` | 975 | — | Executor agent role implementation |
| `class-wp-mcp-ai-agent-role-planner.php` | 244 | — | Planner agent role implementation |
| **Total** | **7,965** | — | — |

See **F-AGENT-01** for the first-pass CoSAI compliance review.

## 10. Top-level entry-point manifest

| File | Plugin header? | Role |
|---|---|---|
| `mcp-ai-wpoos.php` | ✅ | Main plugin entry (full version, network: true) |
| `mcp-ai-wpoos-base.php` | ✅ (alt) | Base-only entry for WP.org distribution |
| `addons/pro/mcp-ai-wpoos-pro.php` | ❌ (intentional) | Pro entry, auto-loaded by main when present |
| `addons/embedded/uninstall.php` | n/a (uninstall) | Uses `WP_UNINSTALL_PLUGIN` guard ✅ |

## 11. Dependency SBOM (root)

### 11.1 Composer (root) — production

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

`composer audit` (root): **0 vulnerabilities** (verified May 2026).

### 11.2 npm (root, production-only)

`npm audit --omit=dev` — **not re-run** for this audit (requires `npm install` in a full environment). The April 2026 audit reported 10 moderate advisories; R-Q-02 reduced these through `npm audit fix` + `path-to-regexp` override. A re-audit should be performed before the next release tag.

## 12. ABSPATH guard coverage

All **942** files in `includes/` have the `ABSPATH` guard at the top. **Zero** missing. This is a significant improvement from April (4 flagged files, now all resolved).

## 13. Changes since April 2026 audit

| Area | April 2026 | May 2026 | Delta |
|---|---|---|---|
| Base PHP files | ~1,460 | 942 | −518 (−35%) |
| Base tools | 231 | 247 | +16 |
| REST route registrations | 190 | 127 | −63 |
| `wp_ajax_nopriv_` | 6 | 3 | −3 |
| Cron schedules | 89 | 63 | −26 |
| Test files | ~365 | 1,077 | +712 (+195%) |
| ABSPATH guards missing | 4 | 0 | −4 |
| `__return_true` in REST | 14 | 3 (REST only) | −11 |
| `eval()` in product | 0 | 0 | 0 |
| `shell_exec`/`exec` in base | 0 | 0 | 0 |
| `sslverify => false` | 4 | 2 | −2 |
| New: `includes/agents/` | N/A | 10 files / 7,965 lines | +7,965 |
| New: `triggers-controller` webhook | N/A | 1 route with `__return_true` | +1 finding |

## 14. CI / lint status

| Command | Outcome |
|---|---|
| `composer install` | ✅ 52 packages |
| `composer audit` (root) | ✅ 0 vulnerabilities |
| `composer run lint:base` | ✅ 0 errors / 0 warnings on shipped tree (verified May 23) |
| `composer run lint:base:compat` | ✅ 0 errors (PHP 7.4–8.3) |
| ABSPATH guard scan | ✅ 942/942 files |
| Dangerous functions scan | ✅ 0 eval/shell_exec/exec/system/passthru in base |
| Inline script/style scan | ✅ All 144 uses go through `wp_print_inline_script_tag`/`wp_add_inline_style` |
| `__return_true` REST scan | ⚠️ 1 new webhook route needs verification (F-AUTHZ-05) |
| `wp_ajax_nopriv_` audit | ⚠️ 3 new handlers need nonce verification (F-AUTHZ-06) |
