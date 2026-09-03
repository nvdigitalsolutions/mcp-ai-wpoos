# Docs & Release Catch-Up — Standing Open-Items Tracker

> **Purpose:** Single registry of every open item identified (and parked or deferred) by the docs & release catch-up runs, so future passes carry from this file instead of re-copying items between plans.
> **Last reviewed:** 2026-09-03 (v1.1.68 pass)
> **Scope:** items raised in [`docs-catch-up-post-1157-fixes.md`](docs-catch-up-post-1157-fixes.md) and [`v1.1.58-docs-catch-up.md`](v1.1.58-docs-catch-up.md) through [`v1.1.68-docs-catch-up.md`](v1.1.68-docs-catch-up.md).
> **Rule for future passes:** read this file first; a catch-up plan's "Open items" section should point here and only add new items it introduces.

---

## Open items

### OI-1 · `@since` docblock reconciliation (PARKED — user-deferred since 2026-08-19)

- **Status:** 🔒 Parked by user decision. **Do not touch tags in a catch-up pass.**
- **Issue:** [#5968 — Reconcile parked `@since` docblock tags](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5968)
- **What:** four anomaly groups whose `@since` version does not match the release that shipped the code (verified on `alpha-working` 2026-08-26; one new instance noted by the v1.1.66 pass):

| Group | Tag | Location | Shipped in | First noted in |
|---|---|---|---|---|
| 1 | `@since 1.1.57` | `addons/pro/includes/composition/` — 3 files (PR #5881) | 1.1.58 | v1.1.58 plan |
| 2 | `@since 1.2.0` | `includes/class-wp-mcp-ai-tool-registry.php` (8×) + `mcp-ai-wpoos.php` export-provider block (2×) + `includes/class-wp-mcp-ai-rabbitmq-client.php` → `refresh_config()` (1×, PR #6007) | 1.1.x (implies a planned 1.2.0) | v1.1.59 plan |
| 3 | `@since 1.9.0` | `includes/harness/class-wp-mcp-ai-artifact-*.php` (19 files) + ~50 more base/Pro files (PR #5923) | 1.1.63 | v1.1.63 plan |
| 4 | `@since 1.0.0` | `includes/google/*` — 7 files (PR #5959) | 1.1.64 | v1.1.64 plan |
| 5 | `@since 1.1.65` | `includes/class-wp-mcp-ai-job-notifier.php` — 1 instance (PRs #6036–#6039, merged after the 1.1.65 catch-up) | 1.1.66 | v1.1.66 plan |
| 6 | `@since 1.11.0` | `includes/class-wp-mcp-ai-toolkit-registry.php` — 1 instance (PR #6117) | 1.1.67 | v1.1.67 plan |
| 7 | `@since 1.1.67` | `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` — `handle_session_nonce()` (PR #6225) | 1.1.68 | v1.1.68 plan |
| 8 | `@since 2.1.0` | `addons/pro/includes/class-wp-mcp-ai-pro-spa-shortcode.php` (5×) + `class-wp-mcp-ai-pro-spa-config.php` (11×, PR #6256) | 1.1.68 (Pro addon ships 1.1.68) | v1.1.68 plan |

- **Blocked on:** version-jump decision — does the next release stay on 1.1.x or jump to 1.2.0?
- **Broader drift (new finding, 2026-08-26):** non-1.1.x tags are repo-wide (`@since 1.0.0` ×1,928 · `1.2.0` ×1,707 · `1.1.0` ×1,269 · `1.3.0` ×795 · `1.9.0` ×734, PHP source ex vendor). Many are legitimate history. A full-tree audit is a scripted-sweep project needing explicit sign-off — tracked inside issue #5968, not a catch-up-pass task.

### OI-2 · Docker-based live tool-count re-derivation (PARKED — user-deferred since 2026-08-19)

- **Status:** 🔒 Parked by user decision. Counts stay delta-derived in catch-up passes.
- **Issue:** [#5967 — Re-derive live tool counts on a fully provisioned environment](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5967)
- **What:** run `WP_MCP_AI_Tool_Registry::get_tools()` on a fully provisioned environment (seeded toolkits + optional plugins) and replace the delta-based figure.
- **Current figure (v1.1.67):** ~303 base + ~1,262 Pro (~1,565 total), live-registry caveat retained on every count surface.
- **Known attempt:** QA container (`oos-qa-wp`) returns 363 tools because its DB is unprovisioned — not usable as source of truth.
- **First noted in:** v1.1.59 plan; carried every pass since.

### OI-3 · Test-suite remaining fixes (cross-reference — tracked elsewhere)

- **Status:** 🟡 Active workstream with its own tracker. Not owned by the docs catch-up.
- **Tracker:** [`docs/developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md`](../developer/testing-docs/TEST-SUITE-REMAINING-FIXES-PLAN.md)
- **Why listed here:** the v1.1.63/v1.1.64 catch-up passes repeatedly point future passes at this doc (post-#5929 sweep remediation, #5931/#5935 follow-ups). Do not duplicate its items here — follow the link.

---

## Doc-level open items → GitHub issues (verified 2026-08-26)

Open items recorded in docs **outside** the catch-up plans (audits, TODO files, implementation plans, security posture, proposals) that had no GitHub issue. Each was re-verified against the current tree before filing; only genuinely-open items were filed.

| Issue | Doc source | Items |
|---|---|---|
| [#5969](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5969) | `docs/developer/toolkit-mcp-server-enhancements-todo.md` | Toolkit MCP server backlog — A.2 (Site Creator settings), A.3 (Extended Cognition settings), A.6 (Healthcare, in-doc deferred), Phases B/D/E/F |
| [#5970](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5970) | `docs/project/plans/CONVERSATION-IMPORT-CCT-IMPLEMENTATION-PLAN.md` §9 | Import size-cap default; memory-mining toggle UI + `count_imported()` widget |
| [#5971](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5971) | `docs/project/audits/2026-04/remediation-roadmap.md` | R-T-01 (PHPCS pro close-out), R-T-03 (CodeQL security-extended), R-A-03 (upload-validator decide-or-close), R-Q-04 (120 `innerHTML` audit), R-Q-05 (pro nonce sweep) |
| [#5972](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5972) | `docs/operations/security/SECURITY_POSTURE.md` | F-AUTHZ-01 (remaining `__return_true` justifications), F-AI-01 (Algorave sandboxed iframe + strict CSP), F-CMP-04 (legacy source-map sweep) |
| [#5974](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5974) | `docs/project/plans/chat-spa-v2-phase1-implementation.md` | 10 remaining SPA gaps (GAP-05, 07, 08, 09, 10, 15–19) |

### Proposals directory (verified 2026-08-26)

| Issue | Doc source | Items |
|---|---|---|
| [#5975](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5975) | `028-media-worker-phase3-proposal.md`, `031-media-worker-crawl4ai-integration-plan.md` | Media worker: 028 open Q5 (W6 strict-path default flip) + 031 Phase 3 (Crawl4AI parity, `CRAWL4AI_FULL_URL`) |
| [#5976](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5976) | `022-request-queuing-job-pooling-hardening.md`, `011-queue-worker-implementation-plan.md` | No max-queue-size / per-user cap; `background-only` × `tools/call` close-out; 011 deferred follow-ups (RabbitMQ dispatch, SSE reconnect, queue admin UI) |
| [#5977](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5977) | `029-oos-orchestration-runtime-consolidation-implementation-plan.md`, `015-mcp-2026-07-28-protocol-upgrade.md` | 029 §2.1 OOS promotion decision (blocking); 015 review decision (+ §2.6 header routing, §2.7 `resources/list` caching minors) |
| [#5978](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5978) | `007-multi-tenant-toolkit-rollout-plan.md`, `007-multi-tenant-comprehensive-implementation-plan.md` | Phase 4 remaining: 42 CRM tools → Data Store, 22 regulatory tools; rollout vs comprehensive plan status reconciliation |

**Excluded after verification (not filed):** Model Manager UI (shipped — `render_model_manager_view()` + AJAX class present); R-T-02 / R-T-05 (landed in `security.yml` / `security-regression.yml`); R-Q-06 / R-S-02 / R-S-03 / R-S-04 / R-A-01 / R-A-02 / R-A-04 (roadmap-marked Done); TEST-SUITE fixes (own tracker, OI-3); `docs/ROADMAP.md` v1.4.0/v2.0.0 (roadmap, not actionable findings); `docs/history/**` retired docs (`ID: XXX` placeholders) are false positives.

**Proposals excluded after verification (not filed):** 005 WP-CLI gaps (`assistant delete --force --yes` shipped in `includes/cli/`); 021 `get_social_analytics` (shipped — analytics service + tool exist); 007 artifact-evolution Phase G (lineage/approval-queue/governance shipped); 024 Hermes fleet operator (`addons/fleet-operator/` shipped — status header stale); 030 Composio Connect (shipped — proposal status stale); Flowhub/Shopify/Ezuite CCT managers (shipped, multi-tenant Phase 3); 016 code-review L1/L2 fixes (landed) with admin-ajax→REST a documented non-goal; WebLLM Phases 4–8, Firefly III, Bitwarden (retirement-log decisions exist); v1.1.29-era proposals with no later status (CONTEXT-1 orchestration, CREDITS transferable, CHAT_PRELOAD) — parked in `proposals/README.md` as reference/low-priority; candidates for the retirement log, not new issues.

---

## Closed items (verified done — do not re-raise)

| Item | Raised in | Closed in | Evidence |
|---|---|---|---|
| `readme.txt` header "13 providers / 250+ tools" vs 15 / ~265 | post-1.1.57 plan (P4-14) | v1.1.58 pass | header now "15 … 300+ built-in tools" |
| `MAINTAINER_MAP.md` "Last reviewed" bump | post-1.1.57 plan (P4-15) | v1.1.58 pass | now August 26, 2026 (v1.1.64) |
| `addons/fleet-operator/.context/README.md` nested-path check | post-1.1.57 plan (P4-16) | v1.1.58 pass | verified no stale nested `.context/.context/` references |
| Media Worker version drift (3.0.0/3.1.0 → 3.2.0) | v1.1.59 pass drift | v1.1.59 pass | Media Worker keeps its own 3.2.0 track |
| Stale `.context/testing.md` "March 2026" stamp | v1.1.63 pass drift | v1.1.63 pass | stamped + sweep/exit-trap notes |
| Stale `.context/settings-storage.md` "July 2026" stamp | v1.1.64 pass drift | v1.1.64 pass | stamped + log-buffer compaction note |
| [#5973](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues/5973) Ralph Wiggum CCT Orchestration — decide implement or defer | filed 2026-08-26 | closed 2026-08-26 | **Implemented** (shipped v1.1.29): 13 orchestration tools, 4 Ralph CCT schemas, PM-toolkit native integration. Decision recorded in `proposals-retirement-log.md` entry #6 + `PROPOSALS_COMPLETION_STATUS.md` |

---

## Scope rules (binding for every future pass)

1. **Parked items stay parked** until the user re-opens them — a catch-up pass must not re-attempt OI-1/OI-2.
2. **Historical entries stay** — old changelog/README per-version blocks and their tool counts are never rewritten.
3. **Tool counts use "~"** with the live-registry caveat; delta-derived from the last published baseline.
4. **Media Worker keeps its own version track** (3.2.0); nvoos-content-graph its own (1.0.3).
5. **New items get added here**, and the introducing plan's "Open items" section points at this file.
