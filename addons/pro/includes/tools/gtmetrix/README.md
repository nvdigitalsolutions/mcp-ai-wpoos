# GTmetrix × WPCode Performance Optimization Toolkit

**Purpose:** Make NV oOS a first-class WordPress performance operator: run
GTmetrix tests over the GTmetrix REST API v2.0, assess results against Core
Web Vitals thresholds, grade bands and page-weight budgets, map failing
audits to concrete WordPress fixes, and deliver them as WPCode-ready snippets
or inactive WPCode drafts.

**Tier:** Pro (`enable_gtmetrix_toolkit`, default off). Requires a GTmetrix
API key (stored in the encrypted API key store under `gtmetrix_api_key`).
Fixture mode (`WP_MCP_AI_GTMETRIX_USE_FIXTURES`) runs the toolkit
network-free against committed JSON fixtures.

**Public surface (23 tools):**

| Group | Tools |
|---|---|
| Reference | `gtmetrix_account_status`, `gtmetrix_list_locations`, `gtmetrix_list_browsers`, `gtmetrix_list_simulated_devices` |
| Test lifecycle | `gtmetrix_start_test`, `gtmetrix_get_test_status`, `gtmetrix_list_tests` |
| Reports | `gtmetrix_get_report`, `gtmetrix_get_lighthouse_audits`, `gtmetrix_get_resource_summary`, `gtmetrix_list_report_resources` |
| Analysis | `gtmetrix_compare_reports`, `gtmetrix_analyze_report` |
| Fix delivery | `gtmetrix_generate_wpcode_snippet`, `gtmetrix_install_wpcode_fix` (draft-only, chat-restricted) |
| Pages & ops | `gtmetrix_list_pages`, `gtmetrix_get_page`, `gtmetrix_retest_page`, `gtmetrix_set_monitoring`, `gtmetrix_get_alert_conditions`, `gtmetrix_set_alert_conditions`, `gtmetrix_get_alert_events`, `gtmetrix_manage_page_tags` |

**Inputs:** GTmetrix API key · test URLs (host allowlist) · report IDs ·
page IDs · alert conditions · fix selections.

**Outputs:** scores/metrics/audits · CWV assessments · budget classes ·
regression deltas · WPCode snippet code · inactive WPCode drafts ·
monitoring and alert state.

**Neighbors:** `developer` toolkit (`create_wpcode_snippet`,
`list_wpcode_snippets` — the WPCode persistence shared via
`WP_MCP_AI_Pro_WPCode_Snippet_Repository`) · `cloudways` toolkit (host-level
ops; Breeze settings bridge is a deferred follow-up) · `security` toolkit
(API key store, cost tracking).

**Conventions:** canonical envelope (success array or `WP_Error`), two-gate
sanitisation, `WP_MCP_AI_GTmetrix_Tool_Base` guard + key guard, credit
budget gate via `WP_MCP_AI_GTmetrix_Scheduler::preflight_budget()`, drafts
only for WPCode writes, sensitive GTmetrix resources (`analysis-options`,
`net.har`) hard-blocked.

**Tests:** `tests/fixtures/*.json` (redacted API captures) ·
`tests/expected-values.php` (score-engine oracle) · `tests/smoke-run.php`
(standalone harness) · `tests/pro/tools/gtmetrix/test-*.php` (PHPUnit).

**Also load:** `.agents/skills/mcp-ai-wpoos-toolkit-creation/SKILL.md` ·
`.agents/skills/design-performance-audit/SKILL.md` (fix knowledge base) ·
`.context/testing.md` · proposal
`docs/project/proposals/068-gtmetrix-wpcode-performance-toolkit-proposal.md`.
