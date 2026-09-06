# Proposals Retirement Log

**Created:** June 29, 2026
**Purpose:** Track proposals that have been superseded, merged, rejected, or are otherwise no longer active. This keeps the proposals directory clean and prevents confusion from stale documents.

---

## Retired Proposals

### 1. FEATURE_GAP_ANALYSIS_PROPOSAL_2026_03.md
- **Retired:** June 29, 2026
- **Reason:** Superseded by v1.1.35 roadmap update. All gaps identified in March 2026 have been closed (rate limiting, SSE limits, Anthropic, PM features, threat model). Remaining open items tracked in ROADMAP.md v2.0.
- **Replacement:** [`../ROADMAP.md`](../../ROADMAP.md) — Current Capability Snapshot + v1.4.0 sections

### 2. DEEPSEEK-V4-ACTUAL-STATUS.md / DEEPSEEK-V4-IMPLEMENTATION-STATUS.md
- **Retired:** June 29, 2026
- **Reason:** Contradictory completion percentages (60% vs 85-90% vs 100%). All DeepSeek V4 phases (1-5) are complete. Multiple status files create confusion.
- **Canonical status:** DeepSeek V4 Multi-Agent Orchestration is **100% complete** (delivered January 2026). The DEEPSEEK-V4-STATUS-AND-ROADMAP.md is the authoritative status document.
- **Replacement:** [`DEEPSEEK-V4-STATUS-AND-ROADMAP.md`](./DEEPSEEK-V4-STATUS-AND-ROADMAP.md)

### 3. PHASE-1-AND-5-INTEGRATION-PLAN.md
- **Retired:** June 29, 2026
- **Reason:** Phase 5 is 100% complete (January 2026). Phase 1 was 70% at last check and the remaining scope has been absorbed into ongoing work.
- **Replacement:** Tracked in PROPOSALS_COMPLETION_STATUS.md

### 4. IMPLEMENTATION_SESSION_SUMMARY.md / FINAL_IMPLEMENTATION_SUMMARY.md
- **Retired:** June 29, 2026
- **Reason:** Session-level notes from January 2026 implementation work. No longer relevant.
- **Replacement:** Historical reference only. See CHANGELOG.md for release-level summaries.

### 5. SESSION_PROGRESS_SUMMARY.md
- **Retired:** June 29, 2026
- **Reason:** Session-level notes. Superseded by formal documentation.
- **Replacement:** N/A — purely historical.

### 6. RALPH-WIGGUM-CCT-ORCHESTRATION.md (and the 3 related Ralph docs)
- **Retired:** August 26, 2026 (decision recorded; implementation shipped v1.1.29, January–April 2026)
- **Reason:** **Implemented** — not deferred. The Ralph Wiggum pattern was built as a native enhancement of the Project Management toolkit, not a standalone integration (see `RALPH-REQUIREMENTS-CLARIFICATION.md`): 13 orchestration PHP tools (`addons/pro/includes/tools/orchestration/`), four JetEngine CCT schemas (`autonomous-sessions`, `task-plans`, `execution-history`, `task-templates` via `ralph_cct_schemas` in the module registry), a browser-side autonomous orchestrator with circuit breaker, and LangChain ReAct loop support. Advertised as shipped in the PM toolkit settings pages and CHANGELOG ("Ralph Loop CCT Migration & Orchestration Tools").
- **Replacement:** [`RALPH-WIGGUM-GAP-REMEDIATION-PLAN.md`](./RALPH-WIGGUM-GAP-REMEDIATION-PLAN.md) — production-hardening follow-up (six gaps; Draft for Review), tracked in `PROPOSALS_COMPLETION_STATUS.md`.

---

## Recommendations for Remaining Stale Proposals

The following proposals have been pending since early 2026 with no active development. Recommended actions:

| Proposal | Recommendation | Rationale |
|----------|---------------|-----------|
| Firefly III Integration | **Defer to post-v2.0.0** | No active demand or development since proposal |
| Bitwarden/Vaultwarden Integration | **Defer to post-v2.0.0** | No active demand; research phase stalled |
| WP Native Password Manager | **Defer** | Low priority; no community votes |
| WebLLM Phases 4-8 | **Park as Future Research** | Phases 1-3 complete; no urgency for remaining phases |
| Toolkit Enhancement (Extended Features) | **Park** | Core toolkit system is feature-complete |
| Future Service Worker Support | **Defer** | No active demand |

---

## Maintenance

- New retirement entries should be added when proposals are merged, rejected, or superseded.
- Proposals retired >12 months ago may be archived to `docs/history/` or deleted.
- Always update `PROPOSALS_COMPLETION_STATUS.md` when retiring a proposal.
