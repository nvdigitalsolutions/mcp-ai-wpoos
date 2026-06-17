# Phase 0: Automated Context Initialization Template

> **GSD Context File** — Use this template to automate Phase 0 of the GSD × BMAD workflow.
> Keep this file under 500 lines (GSD conciseness rule). Last reviewed: March 2026.

---

## Purpose

This template automates Phase 0 (Context Initialization) by documenting the exact sequence of
`batch_manage_memory` calls and context file loading needed to start a GSD × BMAD development
session in under 5 minutes (Phase 6 target).

**Lead Agent:** Scrum Master (Orchestrator)
**Target:** Context window < 30% full before implementation begins.

---

## Step 1 — Load Base Context Files (Always)

Before any feature-specific context, load these two files unconditionally:

```
.context/conventions.md         ← NV oOS naming, PHP compat, build commands
.context/security-checklist.md  ← Security requirements for every code change
```

## Step 2 — Load Subsystem Context (Scoped to Feature)

Choose only the files relevant to the current feature (GSD 0–30% rule — load the minimum):

| Subsystem | Context File | Load When |
|-----------|-------------|-----------|
| Tool registry | `.context/tool-registry.md` | Adding or modifying tools |
| REST API | `.context/rest-api.md` | Adding or modifying REST endpoints |
| Chat UI / Frontend | `.context/chat-ui.md` | Changing JavaScript or Elementor widgets |
| PHPUnit testing | `.context/testing.md` | Writing or reviewing tests |
| Base vs Pro gating | `.context/pro-vs-base.md` | Deciding where a feature lives |

## Step 3 — Load Feature Context

If a context file exists for the active feature, load it:

```
.context/active/[feature-slug].md
```

If no feature context exists yet, create one from the template:

```
.context/templates/active-feature-template.md  →  .context/active/[feature-slug].md
```

Populate it from the Project Brief (`docs/proposals/[FEATURE]-PROJECT-BRIEF.md`) before proceeding.

---

## Step 4 — Seed Working Memory via `batch_manage_memory`

Use the `batch_manage_memory` tool (action: `import`) to seed the agent with key architectural
facts from previous sessions. This prevents context loss between AI sessions.

### 4a. Import Previous Session Memory

Call `batch_manage_memory` with `action: import` using the exported JSON from the last session:

```json
{
  "action": "import",
  "agent_id": "[orchestrator-post-id]",
  "export_data": "[JSON from previous session export]",
  "options": {
    "dry_run": false
  }
}
```

### 4b. Tag New Session Memory

After seeding, tag the relevant contexts for this feature:

```json
{
  "action": "tag_add",
  "agent_id": "[orchestrator-post-id]",
  "filters": {
    "context_types": ["architectural_decision", "known_issue"],
    "tags": ["[feature-slug]"]
  },
  "tags": ["active-session", "[feature-slug]"]
}
```

### 4c. Export Snapshot at Session End (Phase 9)

At the end of the session, export memory for the next agent/session:

```json
{
  "action": "export",
  "agent_id": "[orchestrator-post-id]",
  "filters": {
    "tags": ["[feature-slug]"],
    "importance": ["high", "critical"]
  }
}
```

Store the `export_data` JSON in `.context/active/[feature-slug]-memory.json` for the next session.

---

## Step 5 — Estimate and Verify Context Budget

Before starting implementation, verify the context window is under 30%:

| Item | Approximate Tokens |
|------|--------------------|
| `.context/conventions.md` | ~1,800 tokens |
| `.context/security-checklist.md` | ~1,600 tokens |
| One subsystem context file | ~1,200–2,000 tokens |
| `.context/active/[feature].md` | ~500–1,500 tokens |
| Working memory (from import) | ~500–1,000 tokens |
| **Total target** | **< 7,000 tokens** (= ~30% of 25k window) |

If the total exceeds 30%, drop lower-priority subsystem files. The Scrum Master enforces this gate.

---

## Phase 0 Automation Checklist

- [ ] Base context files loaded (conventions + security-checklist)
- [ ] Subsystem context files loaded (only those needed for this feature)
- [ ] Feature context file loaded from `.context/active/[feature-slug].md`
- [ ] Previous session memory imported via `batch_manage_memory` (action: import)
- [ ] New session tagged with feature slug via `batch_manage_memory` (action: tag_add)
- [ ] Context window budget estimated and confirmed < 30%
- [ ] Scrum Master verified handoff criteria before advancing to Phase 1

---

## Token Usage Tracking (Phase 6 Metric)

Record actual token usage at session start for Phase 6 budget optimization tracking.
See `docs/project/GSD-BMAD-METRICS-BASELINE.md` for the full metrics tracking table.

| Session Date | Feature | Phase | Context Tokens | % of Window | Notes |
|-------------|---------|-------|---------------|-------------|-------|
| YYYY-MM-DD | [feature] | 0 | [count] | [%] | Initial load |

### Budget Alert Thresholds

| Usage | Status | Action |
|-------|--------|--------|
| < 20% | 🟢 Green | Proceed normally |
| 20–30% | 🟡 Yellow | Review if any context files can be deferred |
| 30–40% | 🔴 Red | Must reduce context before implementation |
| > 40% | ⛔ Blocked | Session cannot proceed until context is trimmed |

---

## Related Files

- `.context/templates/active-feature-template.md` — Feature context file template
- `.context/templates/ralph-loop-session.md` — Autonomous loop session configuration
- `docs/project/GSD-BMAD-METRICS-BASELINE.md` — Phase 6 metrics baseline and token tracking
- `docs/project/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md` — Full methodology (Phase 0 section)
- `.bmad/agents/nv-oos-scrum-master.yaml` — Scrum Master agent definition
