# Ecosystem Port Cluster Loop

**The repeatable workflow for completing the remaining port clusters.**
Created 2026-09-06 · Parent plan: [`base-pro-ecosystem-port-plan.md`](base-pro-ecosystem-port-plan.md) · Status: [`../ecosystem-port-tracker.md`](../ecosystem-port-tracker.md) · Driver: [`../../../bin/port-cluster.sh`](../../../bin/port-cluster.sh)

Every remaining cluster (E1, E3, E4, E5, E6, E-UI-1..3, F1..F7, F-UI-1..4, G1..G4, plus the D-UI-6 exit gate) runs through the same 10-step pipeline below. Locked constraints carry over from the parent plan: **D-NOBASE** (zero changes to `mcp-ai-wpoos`), **D-NOCORE** (zero changes to `nvoos-content-graph`), **D-UI**, **D-SPA**.

## 1. Cluster queue (current ordering)

Process strictly top-to-bottom; skip a cluster only when its notes say "blocked".

| # | Cluster | Scope | Size | Notes |
|---|---|---|---|---|
| — | E2 | Queues/ops | — | **PR #6344 open — await merge + CI before starting anything that touches `src/Queues/`.** |
| 1 | E3 | `ApprovalQueue` + audit wiring | S | Unblocks E-UI-1 dashboards; `OutboundWebhook`'s `wp_mcp_ai_approval_requested` listener is already ported (E2). |
| 2 | E5 | A2A REST receive routes | S–M | Closes MIGRATION-GAPS. Controller extends `WP_MCP_AI_REST_Controller_Base` and holds the main REST instance — needs per-mode seams, do NOT drag in `includes/a2a/` beyond what the controller requires. |
| 3 | E1 | Workflow engine + CPTs + triggers | XL | Split into sub-clusters (CPTs first, then dispatcher, then WorkflowEngineV2 + optimizer). One PR per sub-cluster. |
| 4 | E4 | Tenant + integrations (OAuth, Calendar, site-builder, conversation-import) | L | Split per integration. |
| 5 | E6 | OOS/markup/paper-store/OKF/crawler | M | Decision D4 resolved (fold into CG-AI under `Engine\`, gap-analysis §10); sub-clusters 1 (OOS shadow) + 2 (markup) + 3 (paper-store) + 4 (OKF) + 5 (crawler) + 6 (oos-bridge wave-1 helpers: session-log/telemetry + canary flags, semantic compressor, data budget, erlang C, error/cost tracking bridges) landed — E6 complete. The six wave-2 factory bridges (model catalog, token budget, file validation/upload/orchestration, rate limiter) remain base-owned pending their consumer ports. |
| 6 | E-UI-1 | Dashboards (multi-agent, orchestration, slash-commands, run timeline) | M | **Complete** — all four sub-clusters landed (`src/Admin/Dashboards/`: multi-agent + orchestration + slash-commands + run-timeline); the four operational dashboards mount as submenu pages under `PlatformDashboard::PAGE_SLUG` (they don't replicate settings-level tab/sub-tab/view routing — see tracker note); 16 + 29 + 20 + 15 characterization tests green both matrices, full suite 993/0 both matrices. Next cluster: E-UI-2 Managers (tool/token/cron/DAG/DLQ/approvals — split per manager). |
| 7 | E-UI-2 | Managers (tool/token/cron/DAG/DLQ/approvals) | L | In progress — sub-clusters 1 (approvals UI) + 2 (token manager) + 3 (cron manager) landed (`src/Admin/Managers/ApprovalsManager.php`, `TokenManager.php`, `CronManagerPage.php`); 13 + 16 + 23 characterization tests green both matrices, full suite 1045/0 both matrices. Next: DAG builder, then DLQ manager, media-library columns, asset inventory (one PR each). |
| 8 | E-UI-3 | Integrations screens | M | |
| 9 | F1 | pro-core (module registry, vault, vector-storage, skills-manager) | L | Gate for all of F2–F6. |
| 10 | F2–F6 | Pro toolkits (business/media/dev/healthcare/legal/education/content/data/platform) | XL | Disjoint by toolkit dirs — parallelizable once F1 lands. |
| 11 | F7 | Node-service bridges (`services/`, `nv-cloud`, `cloudways`) | M | |
| 12 | F-UI-1..4 | SPA v2 pipeline + runtime + pro UI | L | Sequential (build pipeline → runtime → UI). |
| 13 | G1..G4 | Release & distribution | S | **Release-gated — do not start before the release freeze; docs-only.** |

Parallel lanes (disjoint write scopes, per plan §4): E3/E5 (Wave E) can run in parallel with D-UI-6 (AI addon). E-UI-1 can start once E3 merges. F2–F6 only after F1.

## 2. The 10-step pipeline (one cluster per branch/PR)

1. **Load context.** Read `docs/project/plans/base-pro-ecosystem-port-plan.md` (scope row), the tracker row, and the base class(es) being ported. Check `git log` for anyone already working the cluster (multi-agent repo). If a prior PR exists for the cluster, continue it — never start a duplicate.
2. **Branch.** `bin/port-cluster.sh new <slug>` — always off a **fresh `origin/alpha-working`** (fetch first). Convention: `feat/ecosystem-port-<wave>-<slug>` (e.g. `feat/ecosystem-port-e3-approval-queue`).
3. **Port.** Byte-identical constants/hooks/error codes/shapes. Documented deviations go in the class docblock (same pattern as all prior waves). Standalone-only wiring via `Plugin::register*()` gated on `! defined('WP_MCP_AI_PATH')`; collaborators resolve per install mode through protected `*_class()` seams. Text domain `nvoos-content-graph-ai-platform`. Tool-like code obeys the canonical envelope + two-gate sanitization rules (CLAUDE.md).
4. **Folder README.** New PHP-bearing folder → add the 7-section `README.md` (template in `.context/templates/`); update the parent README's surface table when the public surface grows.
5. **Characterization tests.** New `tests/test-<slug>.php` mirroring the base's contract, not its implementation. Seam classes expose protected statics; multiple classes per file need `// phpcs:disable Generic.Files.OneObjectStructurePerFile`.
6. **Gates.** `bin/port-cluster.sh gates` — phpcs (`plugins/nvoos-content-graph-ai-platform/phpcs.xml.dist`) + both docker matrices (standalone `WP_MCP_AI_PLATFORM_STANDALONE=1` and monolith). Acceptable: 0 failures from this cluster, 0 risky. Pre-existing failures must be proven pre-existing on `alpha-working` (report, don't fix — or fix in a separate PR).
7. **Tracker.** Update the cluster's row in `docs/project/ecosystem-port-tracker.md` (status, byte-identical notes, real test counts, "Remaining:" tail). Keep the top "Last updated" date fresh.
8. **Commit.** Explicit paths only — **never `git add -A`, never `vendor/`**. Imperative subject ≤50 chars, body only when useful. Never commit scratch debug files.
9. **PR.** Against `alpha-working`, title `Ecosystem port Wave <X>: <cluster>`. Body: ported surface, documented deviations, wiring, test counts per matrix, harness notes. If `alpha-working` advanced, merge it into the branch and resolve drift (build/ ZIP checksums take the remote's version).
10. **Merge gate.** Green CI (phpunit + phpcs workflows) and no conflicts. Merge, confirm the tracker row, then return to step 1 with the next cluster. Keep at most **1 unmerged cluster PR per parallel lane**.

## 3. Per-cluster definition of done

- [ ] Port byte-identical where possible; every deviation documented in a class docblock
- [ ] Standalone-only wiring + per-mode seams; no base/core changes (D-NOBASE/D-NOCORE)
- [ ] Folder README(s) present + surface tables updated
- [ ] Characterization suite green in **both** matrices (standalone + monolith)
- [ ] phpcs clean; no risky tests introduced
- [ ] Tracker row updated with real counts and truthful "Remaining:" status
- [ ] PR merged to `alpha-working` with green CI

## 4. Known pitfalls (accumulated from prior clusters)

- `date('c', float)` throws under `strict_types` — cast `(int)` first.
- `__return_wp_error()` does not exist — use a closure returning `new WP_Error(...)`.
- `register_rest_route()` before `rest_api_init` → "doing it wrong" — `do_action('rest_api_init')` in the route test (pattern in `tests/test-blueprints.php`).
- Sanitizers are byte-identical, not "clean": consecutive dots collapse, `<script>` is stripped char-by-char; `floatval()` → assert `50.0`.
- Real-DDL tests: leave the DLQ/JQM **real tables in place (truncate), never drop** — dropping behind the memoized SHOW TABLES probes makes later suites risky (see `test-job-notifier.php` `close_dlq_table()` and `test-dead-letter-queue.php` teardown).
- The monorepo autoloader resolves base classes even in the standalone matrix — per-mode resolution must key off `defined('WP_MCP_AI_PATH')`, not `class_exists()` alone.
- Docker matrix runs must use `MSYS_NO_PATHCONV=1` on Windows; the local WooCommerce volume is the known-failing matrix — CPT-registration tests need the #6343 wiring pattern.
