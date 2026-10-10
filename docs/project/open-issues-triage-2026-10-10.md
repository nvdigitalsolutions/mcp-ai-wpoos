# Open Issues Triage — 2026-10-10

> **Purpose:** One-shot snapshot of the full open-issue triage performed on
> 2026-10-10 against `alpha-working` (commit `144115125e`). Groups the 36
> remaining open issues by what it would take to complete them, so the next
> session can pick work from the right tier instead of re-triaging.
> **As of:** 2026-10-10. The live list drifts fast — re-derive with
> `gh issue list --state open` before relying on this file.
> **Session context:** five issues were closed this session because the work
> had already landed or was verified complete: #6814 (memory agent_id
> resolution, PR #6815), #6860 (safe API error verbosity, PR #6869), #6901
> (MCP gateway secret + sync — verified mirror parity), #6651 and #6642
> (completed by PR #6991). PR #6991 ("Add multi-recipient notify_email plus
> quick wins", branch `fix/quick-wins-oct-10` against `alpha-working`) carries
> #6642, #6651, and #6975 item 3; it is open and unmerged at the time of this
> snapshot.

---

## Tier A — Quick wins (one sitting)

| Issue | What's left |
|---|---|
| #6903 | Fix the Windows timing-race flake in `bin/test-mcp-bridge-ssh.js` (harness fix + 5x green + mirror in `packages/nvoos-mcp-bridge/test/`). Reproducible on the dev machine |
| #6975 | Items 1-2 only: server-side model-override enforcement for non-admins + model-store reset in `useModelStore` (item 3 done via PR #6991) |
| #6775 | Session-budget UX: reword the frozen-counter error, effective-limit messaging, budget visibility/reset affordance in `includes/class-wp-mcp-ai-tool-token-limits.php` |
| #5970 | Conversation-import: confirm or make configurable the 128 MB size cap, surface the memory-mining toggle in admin UI |
| #6646 | Mostly stale: root docs already carry content-graph 1.0.10. Remaining = create release tags (`content-graph-v1.0.9`/`v1.0.10`, `checkout-api-v0.1.2`) — needs maintainer tag push |

## Tier B — Well-scoped, medium (hours)

| Issue | What's left |
|---|---|
| #6888 | Sweep 34 dead `wp_mcp_ai_log_activity()` call sites over to `log_event()` (mechanical; test-suite skill pattern #49) |
| #6652 | Wire `calculate_cost_at()` into the usage tracker / analytics cost paths (no consumer exists yet) |
| #6650 | CRM scoring: light (deterministic) vs full (LLM) depths + provider fallback wiring |
| #6821 | Port `mcp_require_assistant_scope` to CG Pro / CG-AI (ecosystem-port loop) |
| #6648 | CG Pro port: Gmail reply poller + pipeline digest (PR #6641 follow-up) |
| #6763 | MCP Apps: auto re-test connection after editor save (small frontend) |
| #6762 | Pro SPA v2: per-assistant adaptive tool-cap parity (frontend) |
| #6653 | Fix 3 pinned latent bugs: ICP compute/manage + PM workflow-rule trigger (shapes documented in OI-6; fix-first-then-re-port to CG Pro) |
| #5956 | 56 base tools declaring weaker capabilities than they enforce (sweep) |
| #5968 | Reconcile parked `@since` docblock tags (anomaly groups; parked by user decision — see OI-1) |
| #6724 | Usage-guidance blocks for files outside the swept base+pro trees |

## Tier C — Large sweeps / ports / backlogs (days)

| Issue | What's left |
|---|---|
| #6884 | CG Pro standalone: guard 46 unguarded base-plugin class call sites (33 Credential_Resolver + 13 Tool_Registry) |
| #6741 | Port usage-guidance blocks to ~957 CG Pro mirror tools (ecosystem-port sweep) |
| #6647 | AI SDK v1 -> v5 cross-major upgrade for chat-spa + spa-v2 |
| #6649 | Port the reworked slash-command system to the CG platform |
| #6765 | TypeSafe Jev deferred workstreams (4 tracks) |
| #6366 | Pre-existing build/lint failures in Schedule Anything SPA, Pro SPA, Workflow Builder |
| #6877 | Only Wave 3 remains: Real-ESRGAN super-resolution on `/api/image/upscale` (Waves 1-2 merged) |
| #6380 | Agentic engineering gap-closure W1-W8 (proposal-scale) |
| #5978 | Multi-tenant toolkit rollout Phase 4 (42 CRM + 22 regulatory tools) |
| #5974 | 10 remaining Chat SPA v2 gaps (GAP-05, 07-10, 15-19) |
| #5976 | Queue-hardening residuals (022 caps) + 011 deferred follow-ups |
| #5971 | 2026-04 remediation roadmap residuals (R-T-01/03, R-A-03, R-Q-04/05) |
| #5969 | Toolkit MCP server enhancements backlog (A.2, A.3, A.6, Phases B/D/E/F) |
| #6811 | wp.org submission of `nvoos-design-system` (docs-hub parity — long pipeline; maintainer-heavy at submission time) |

## Tier D — Blocked from here / needs external access or a decision

| Issue | Why |
|---|---|
| #6971 | Demo-video CI Docker debugging — needs GitHub Actions iterations (manual-only trigger already shipped as interim fix) |
| #6902 | Cloudways deployment + MCP directory submissions — ops with external accounts |
| #6930 | Dependabot sweep — the 138 `fix_started` alerts only clear when `alpha-working` merges to `main` |
| #5977 | Decide proposals 029 (OOS consolidation) and 015 (MCP protocol upgrade) — architecture decision |
| #6654 | Base->Pro purchase flow — proposal decision + big feature build |
| #5967 | Re-derive live tool counts — needs a fully provisioned environment (all addons active) |

## Suggested next targets

- Tier A in order: #6903 (flake fix) then #6775 (budget UX), or #6975 items 1-2.
- Tier B fastest: #6888 (pure mechanical sweep) or #6763 (small frontend).
