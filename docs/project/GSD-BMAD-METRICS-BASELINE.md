# GSD × BMAD Metrics Baseline

**Version:** 1.0.0
**Date:** March 2026
**Status:** Active — tracking begins with first completed feature cycle
**Methodology Reference:** `docs/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md` (Phase 6)

---

## Purpose

This document establishes the **baseline success metrics** for the GSD × BMAD development
methodology in NV oOS. Three feature cycles must be tracked before drawing conclusions about
the methodology's impact. All metrics have defined targets, measurement methods, and
Phase 6 owners.

---

## Metric Definitions and Targets

### Metric 1 — Feature Cycle Time

**Definition:** Total elapsed time from Phase 0 initialization to Phase 7 release tag.

**Measurement:** `Phase 7 release timestamp` − `Phase 0 session start timestamp`

**Source:**
- Phase 0 start: Date of first commit to `.context/active/[feature-slug].md`
- Phase 7 end: Date of the Git tag for the release that includes the feature

**Target (after 3 cycles):** Establish baseline in cycles 1–3; improve by ≥ 20% by cycle 6.

| Segment | Sub-metric | Notes |
|---------|-----------|-------|
| Discovery | Phase 1 duration | Brief approved within 1 working day for small features |
| Planning | Phase 2 duration | PRD complete within 2 working days |
| Architecture | Phase 3 duration | Arch spec within 1–2 working days |
| Story Breakdown | Phase 4 duration | Task plan created in < 2 hours |
| Implementation | Phase 5 duration | Depends on story count |
| Validation | Phase 6 duration | Per-story gate < 4 hours |
| Release | Phase 7 duration | Release process < 2 hours |

---

### Metric 2 — Context Setup Time

**Definition:** Time from session open to first implementation keystroke (context loaded, memory seeded, feature context initialized).

**Measurement:** Manually recorded at the start of each Phase 0 and Phase 5 sub-session.

**Target: < 5 minutes per session**

**How to measure:**
1. Note the time when the AI session opens
2. Note the time when the first code change or tool call begins
3. Record in the table below

**Optimization levers:**
- Pre-baked `batch_manage_memory` export JSON in `.context/active/[feature-slug]-memory.json`
- Correct subsystem context selection (load only what's needed)
- Phase 0 automation template: `.context/templates/phase-0-initialization.md`

---

### Metric 3 — Story Completion Rate

**Definition:** Percentage of stories completed without rework (i.e., no "REVISE" verdict from QA on first validation pass).

**Measurement:** `Stories passing QA on first pass` ÷ `Total stories` × 100

**Target: ≥ 90% without rework**

**Source:** QA Engineer verdict log in the task plan (tracked via `get_task_plan` output).

**Improving this metric:**
- Ensure stories have explicit acceptance criteria before implementation (Phase 4)
- Load `.context/security-checklist.md` in every Phase 5 sub-session
- Run `composer run lint` before marking a story complete

---

### Metric 4 — Defect Rate Post-Merge

**Definition:** Number of bug-fix commits merged within 30 days of a feature's Phase 7 release, attributable to that feature.

**Measurement:** Count of commits with `fix(scope):` message referencing the feature slug, merged ≤ 30 days after the release tag.

**Target: 30–50% reduction vs. pre-GSD×BMAD baseline**

**Baseline (pre-methodology):** Estimate by counting bug-fix commits for the last 3 features shipped before adopting GSD × BMAD.

| Feature | Release Tag | Bug Fixes (30d) | Rate |
|---------|------------|----------------|------|
| [pre-methodology feature 1] | v?.?.? | [count] | [count/story] |
| [pre-methodology feature 2] | v?.?.? | [count] | [count/story] |
| [pre-methodology feature 3] | v?.?.? | [count] | [count/story] |
| **Pre-baseline average** | | | **[avg]** |

---

### Metric 5 — AI Token Usage Per Phase

**Definition:** Approximate input tokens consumed per GSD × BMAD phase per feature, tracked to identify context budget optimization opportunities.

**Measurement:** Record context size at Phase 0 session start (see `.context/templates/phase-0-initialization.md`) and estimate total tokens per phase from session logs.

**Target:** Phase 0 context < 30% of context window (GSD 0–30% rule).

| Phase | Lead Agent | Expected Token Range | Notes |
|-------|-----------|---------------------|-------|
| 0 — Context Init | Scrum Master | 5,000–7,000 | Base + subsystem context + memory seed |
| 1 — Discovery | Analyst | 8,000–15,000 | Research tool calls (deep_research output) |
| 2 — Planning | Product Manager | 6,000–12,000 | Brief + PRD generation |
| 3 — Architecture | Architect | 10,000–18,000 | Pattern analysis + spec generation |
| 4 — Story Breakdown | Scrum Master | 4,000–8,000 | Architecture spec + task plan creation |
| 5 — Implementation | Developer | 5,000–9,000 per story | Story spec + context files per sub-session |
| 6 — Validation | QA Engineer | 4,000–8,000 per story | Acceptance criteria + security checklist |
| 7 — Release | Scrum Master | 2,000–4,000 | Release checklist only |
| 9 — Retrospective | Scrum Master | 3,000–6,000 | Context harvest + memory export |

---

## Cycle Tracking Tables

### Feature Cycle 1

| Metric | Target | Actual | Status |
|--------|--------|--------|--------|
| Feature slug | — | [slug] | — |
| Release version | — | v?.?.? | — |
| Phase 0 start date | — | YYYY-MM-DD | — |
| Phase 7 release date | — | YYYY-MM-DD | — |
| **Cycle time (total)** | — | [days] | ⬜ Not yet |
| **Context setup time (avg)** | < 5 min | [min] | ⬜ Not yet |
| **Story completion rate** | ≥ 90% | [%] | ⬜ Not yet |
| **Bug fixes (30d post-release)** | ≤ baseline | [count] | ⬜ Not yet |
| Peak token % of window | < 30% | [%] | ⬜ Not yet |

### Feature Cycle 2

| Metric | Target | Actual | Status |
|--------|--------|--------|--------|
| Feature slug | — | [slug] | — |
| Release version | — | v?.?.? | — |
| Phase 0 start date | — | YYYY-MM-DD | — |
| Phase 7 release date | — | YYYY-MM-DD | — |
| **Cycle time (total)** | ≤ Cycle 1 | [days] | ⬜ Not yet |
| **Context setup time (avg)** | < 5 min | [min] | ⬜ Not yet |
| **Story completion rate** | ≥ 90% | [%] | ⬜ Not yet |
| **Bug fixes (30d post-release)** | ≤ Cycle 1 | [count] | ⬜ Not yet |
| Peak token % of window | < 30% | [%] | ⬜ Not yet |

### Feature Cycle 3

| Metric | Target | Actual | Status |
|--------|--------|--------|--------|
| Feature slug | — | [slug] | — |
| Release version | — | v?.?.? | — |
| Phase 0 start date | — | YYYY-MM-DD | — |
| Phase 7 release date | — | YYYY-MM-DD | — |
| **Cycle time (total)** | ≤ Cycle 2 | [days] | ⬜ Not yet |
| **Context setup time (avg)** | < 5 min | [min] | ⬜ Not yet |
| **Story completion rate** | ≥ 90% | [%] | ⬜ Not yet |
| **Bug fixes (30d post-release)** | 30–50% below baseline | [count] | ⬜ Not yet |
| Peak token % of window | < 30% | [%] | ⬜ Not yet |

---

## Measurement Cadence

| Event | Action |
|-------|--------|
| Phase 0 start | Record session start time; note context token estimate |
| Phase 5 sub-session start | Record setup time from session open to first code change |
| QA verdict (Phase 6) | Record APPROVED / REVISE / ESCALATE per story |
| Phase 7 release | Record release date; calculate cycle time |
| 30 days post-release | Count attributable bug-fix commits |
| After Cycle 3 | Produce summary report; present to team |

---

## Token Usage Per Phase — Optimization Log

Use this table to record actual vs. expected token usage and note optimization actions taken.

| Date | Feature | Phase | Actual Tokens | Expected Range | Delta | Optimization Applied |
|------|---------|-------|--------------|----------------|-------|---------------------|
| YYYY-MM-DD | [slug] | 0 | [count] | 5,000–7,000 | [±] | [e.g., dropped rest-api.md] |

### Key Optimization Techniques

1. **Pre-bake memory exports:** Store `batch_manage_memory` JSON in `.context/active/[feature-slug]-memory.json` so the next session loads in one tool call instead of re-seeding from scratch.

2. **Lazy context loading:** Only load subsystem context files (tool-registry, rest-api, chat-ui) when the story actually touches that subsystem — not at session start for every story.

3. **Story-scoped context:** For Phase 5 sub-sessions, load only the story spec + two base files (conventions + security-checklist). Architecture spec sections can be quoted inline in the story rather than loading the full document.

4. **Memory tagging:** Use `batch_manage_memory` (action: `tag_add`) to tag high-importance contexts with the feature slug so future `batch_manage_memory` (action: `export`) filters return only relevant memories, not the entire memory store.

---

## References

- Context initialization procedure: `.context/templates/phase-0-initialization.md`
- Full methodology: `docs/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md`
- Pro Dashboard monitoring setup: `docs/PRO_DASHBOARD_MONITORING.md`
- Phase 8 monitoring workflow: `.github/workflows/post-deploy-health.yml`
- Agent definitions: `.bmad/agents/`
