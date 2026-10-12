# GTmetrix × WPCode Performance Optimization Toolkit

**Tier:** Pro · **Toggle:** `enable_gtmetrix_toolkit` (Settings → Pro Features) · **Tools:** 23
**Requires:** GTmetrix API key (NV oOS → Settings → API keys → `gtmetrix_api_key`)

The GTmetrix Performance Toolkit turns NV oOS into a WordPress performance
operator: run GTmetrix tests over the [GTmetrix REST API v2.0](https://gtmetrix.com/api/docs/2.0/),
assess results against Core Web Vitals thresholds and page-weight budgets
deterministically, map every failing audit to a concrete WordPress fix, and
deliver the fixes as WPCode-ready snippets or inactive WPCode drafts.

## Setup

1. Get an API key: GTmetrix → Account → API, then copy the key into
   **NV oOS → Settings → API keys** as `gtmetrix_api_key`.
2. Enable the toolkit: **NV oOS → Settings → Pro Features → Enable GTmetrix
   Performance Toolkit**.
3. Optional: set the daily credit budget, extra test hosts and the core pages
   list (option `wp_mcp_ai_gtmetrix_settings`).
4. Verify: `wp mcp-ai pro toolkit status` shows the toolkit enabled, and
   `gtmetrix_account_status` returns your credit balance.

## The audit → fix loop

1. `gtmetrix_start_test` — run a Lighthouse test on a page (1.0 credit;
   metrics-only reports cost 0.6).
2. `gtmetrix_get_test_status` — poll until completed; the toolkit also
   snapshots completed reports automatically (surviving GTmetrix's 1-month
   retention).
3. `gtmetrix_analyze_report` — CWV assessment (LCP < 2.5 s, TBT < 200 ms,
   CLS < 0.1), budget class, LCP phase breakdown and the impact-ordered
   audit list with the mapped fix set.
4. `gtmetrix_generate_wpcode_snippet` — WPCode-ready PHP containing only the
   blocks that match the report. Safe blocks are enabled; risky blocks are
   commented with staging warnings.
5. `gtmetrix_install_wpcode_fix` — saves the snippet as an **inactive WPCode
   draft** (tag `gtmetrix-fix`, note cites the report ID). Activation after
   staging verification is a human step.
6. `gtmetrix_retest_page` + `gtmetrix_compare_reports` — verify the change
   with a before/after delta.

## Credit discipline

| Report type | Cost |
|---|---|
| Lighthouse | 1.0 |
| PageSpeed + YSlow (legacy) | 0.7 |
| Both | 1.1 |
| Metrics-only (`none`) | 0.6 |
| PDF / full PDF / video | +0.2 / +0.3 / +0.9 |
| Retention 6 / 12 / 24 months | +0.4 / +0.9 / +1.8 |

The site daily budget (default 25 credits) fails closed before any charge.
`gtmetrix_account_status` shows the balance and refill time.

## Monitoring and regression governance

- `gtmetrix_set_monitoring` — enable GTmetrix monitoring per page
  (no/hourly/daily/weekly/monthly).
- `gtmetrix_set_alert_conditions` — alert on any GTmetrix metric
  (scores, CWV timings, size/requests) with `<`, `=` or `>` thresholds
  (max 10, full-replace semantics).
- `gtmetrix_get_alert_events` — alert trigger history with the values that
  fired.
- Schedule presets: **Weekly Performance Sweep** (metrics-only over core
  pages) and **Monthly Optimization Review** (full Lighthouse).
- Workflow presets: **Audit & Fix Pipeline** and **Deploy Regression Gate**.

## Assistant

`wp mcp-ai pro gtmetrix seed-assistant` installs the
**GTmetrix Performance Optimizer** assistant (read + generate tools;
snippet installation excluded by default).

## Safety notes

- Test targets are host-allowlisted (site host by default).
- `analysis-options` (contains HTTP auth) and HAR files are never fetched.
- INP is a field-data metric; TBT is the lab proxy — lab data cannot
  certify INP.
- WPCode writes are drafts only and restricted from the chat client.

## Development

- Fixture mode (`WP_MCP_AI_GTMETRIX_USE_FIXTURES`) runs the toolkit
  network-free against committed JSON fixtures.
- Standalone oracle harness:
  `php addons/pro/includes/tools/gtmetrix/tests/smoke-run.php` (41 checks).
- Proposal & implementation plan:
  `docs/project/proposals/068-gtmetrix-wpcode-performance-toolkit-*.md`.
