# Memory Layer 2026 Enhancements — Archived Context

> **GSD Context File** — Archived after feature completion in Phase 9.
> Keep this file under **500 lines** (GSD conciseness rule).
> Archived to `.context/archive/memory-layer-2026-v1.1.20.md`.

---

## Feature Overview

Eight-phase enhancement of the NV oOS agent memory layer, applying patterns from
`rohitg00/agentmemory` (RRF hybrid retrieval, auto-capture, decay, contradiction
detection, provenance, session replay) **alongside** the existing MemPalace /
Letta / Zep / mem0 / Cognee-derived foundation. Strictly additive — no breaking
changes to existing filters, actions, REST shapes, or CCT schema.

**Current Phase:** 9 (Archive + post-release monitoring). Phases 0–8 complete + merged into parent feature branch.
**Feature Version:** v1.1.20
**Branch:** `feature/memory-layer-2026-enhancements` (off `alpha-working`)
**Brief:** _N/A — proposed and approved inline in chat (Nov 2026)_
**Reference doc:** `docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md` (existing)

---

## Context Loading Strategy

Load these files at the start of each AI session for this feature (GSD 0–30% rule):

```
Always:
  .context/conventions.md
  .context/security-checklist.md
  .context/archive/memory-layer-2026-v1.1.20.md  ← archived feature context (reference only)
  docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md
  includes/services/README.md
  includes/tools/README.md

Subsystem (load only what's relevant to today's story):
  .context/tool-registry.md    # Phase 6 (provenance tool)
  .context/rest-api.md         # Phase 7c (session replay route)
  .context/chat-ui.md          # Phase 7b/7c (Memory Drawer additions)
  .context/testing.md          # every phase
```

---

## Component Map

Which parts of NV oOS are affected:

- [x] Tool registry — new tools: `trace_memory_provenance` (Phase 6)
- [x] REST API — new endpoints: `GET /chat-memory/sessions/{session_id}` (Phase 7c)
- [x] Admin settings — new subtab: Memory Health (Phase 7a)
- [x] Chat UI — Memory Drawer extensions (Phase 7b/7c)
- [x] Database schema — 5 new CCT meta fields (Phase 2), append-only
- [ ] External API integration — none new (reuses existing OpenAI/Ollama)
- [x] Base plugin (`includes/`) — PHP 7.4+ compatible
- [ ] Pro addon (`addons/pro/`) — no Pro work in this feature

---

## Phase Roadmap

| # | Title | Effort | Risk | Default | PR target |
|---|---|---:|---|---|---|
| 0 | Preflight | 0.5d | None | — | this branch |
| 1 | Privacy filter | 1.0d | Very low | ✅ enabled | sequential |
| 2 | CCT schema additions | 1.0d | Low | ✅ migrated | sequential |
| 3 | Auto-capture + dedup | 2.0d | Low (default-off) | ⚠️ **off by default** | parallel (sub-agent) |
| 4 | RRF fusion | 3.0d | Medium | ✅ enabled | parallel (sub-agent) |
| 5 | Decay + contradictions | 2.0d | Low–Medium | ✅ enabled | parallel (sub-agent) |
| 6 | Provenance tracer | 1.0d | Very low | ✅ tool registered | parallel (sub-agent) |
| 7 | UI/UX (3 sub-PRs) | 3.0d | Low | ✅ visible | sequential after 3–6 |
| 8 | Docs + release | 0.5d | None | v1.1.20 | sequential |

---

## Architectural Decisions

| Decision | Rationale | Date |
|----------|-----------|------|
| Branch off `alpha-working`, not `main` | Matches recent multi-PR feature workflow (Orchestration Phases 1–7, Skills v2) | 2026-11 |
| Auto-capture default OFF | Risk of memory explosion before tier-decay tuning; opt-in via filter | 2026-11 |
| RRF default ON | Booster API preserved as compat shim in REST response; existing UI unaffected | 2026-11 |
| Phase 2 ships schema additions for all later phases | Avoids three separate CCT migrations during feature work | 2026-11 |
| Phases 3/4/5/6 forked to parallel sub-agents | Confirmed disjoint write scopes (see "Files touched" per phase) | 2026-11 |
| Version bump 1.1.19 → 1.1.20 (not 1.2.0) | Maintain patch cadence; no breaking changes | 2026-11 |

---

## Known Issues / Gotchas

- **CCT schema migrator must be idempotent.** JetEngine's `maybe_register_cct()` only runs when the CCT is *missing*. Adding fields to an existing CCT requires a separate one-shot migrator gated by an option flag (`wp_mcp_ai_memory_cct_schema_version`).
- **`wp_mcp_ai_memory_pre_store_transform` runs at default priority 10.** Privacy filter MUST hook at priority 5 to redact secrets *before* any user transform (the existing comment in `class-wp-mcp-ai-memory-capture-service.php:125` documents this contract).
- **Auto-capture denylist MUST exclude retrieval tools** (`recall_memory`, `wake_up_context`, `retrieve_agent_memory`, `semantic_context_search`) to prevent capture-on-retrieval loops.
- **RRF backward-compat:** The `boost_breakdown` keys in `semantic_context_search` REST responses are consumed by `chat-memory-drawer.js`. When RRF is active, set those keys to `0.0` and add `rrf_breakdown` alongside — don't drop the legacy keys.
- **Graphify is optional.** Every reference to `RECALLS` edges or `NV_oOS_Graphify_Memory_Bridge` MUST be `class_exists()`-gated.
- **`maybe_register_cct` runs at `init` priority 11.** Anything that depends on the CCT meta fields existing must hook later than that.

---

## Story Status

| Story ID | Title | Status | Notes |
|----------|-------|--------|-------|
| 0.1 | Create branch + active context file | Complete | commit 47d2fa4a5 |
| 0.2 | Baseline test counts | Complete | composer not run on Windows dev; CI baseline used instead |
| 1.1 | Privacy filter service + tests | Complete | commit 3aff9e15c (22 tests, all linted) |
| 2.1 | CCT schema v2 + migrator + tests | Complete | commit 19995d6f1 (14 tests, all linted) |
| 3.1 | Auto-capture service + tests | Complete | PR #5011 merged (commit `3afdf95fe`); 15 tests; default OFF |
| 4.1 | RRF fusion service + tests | Complete | PR #5012 merged (commit `37c7b7d3c`); 13 tests; default ON |
| 5.1 | Decay sweep extension + tests | Complete | PR #5013 merged (commit `5009c7ee5`); 9 tests |
| 5.2 | Contradiction detector + tests | Complete | PR #5013 merged (commit `5009c7ee5`); 8 tests; auto-supersede OFF |
| 6.1 | Provenance tracer tool + tests | Complete | PR #5015 merged (commit `600d0b3dd`); 11 tests |
| 7.1 | Memory Health subtab | Complete | Phase 7a (orchestration Memory Health view) |
| 7.2 | Retrieval waterfall in drawer | Complete | Phase 7b (chat memory drawer retrieval waterfall panel) |
| 7.3 | Session Replay tab | Complete | Phase 7c (chat-memory sessions replay route + drawer tab) |
| 8.1 | Docs + version bump | Complete | Phase 8 (v1.1.20 metadata + changelog/docs sync) |

---

## Security Notes

- Privacy filter (Phase 1) is the first line of defence against secret-leakage
  into long-term memory. It runs at filter priority 5, *before* any user
  transform that might be tempted to log or echo data. Verbatim discipline does
  **not** bypass the privacy filter — verbatim guarantees *preservation of
  surviving content*, not *bypass of redaction*. The existing comment at
  `includes/services/class-wp-mcp-ai-memory-capture-service.php:125` documents
  this design contract.
- All new REST routes use the same auth chain as
  `WP_MCP_AI_REST_Chat_Memory_Controller` (nonce + capability + per-user
  kill-switch + site-wide gate).
- Auto-capture's denylist MUST be conservative to prevent capture loops.
- Contradiction auto-supersession is OFF by default — supersession is a
  data-destruction adjacent operation (older record is demoted, not deleted,
  but it leaves the retrieval pool).

---

## Next Step

> What is the immediate next action when this context file is loaded?

**Complete**: Phase 9 archival is finished. Continue post-release monitoring and
move any retrospective notes to docs as needed.
