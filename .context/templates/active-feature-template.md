# [Feature Name] — Active Context

> **GSD Context File** — Initialize from the Project Brief summary at the start of Phase 0.
> Keep this file under **500 lines** (GSD conciseness rule).
> Archive to `.context/archive/[feature-slug]-vX.Y.Z.md` during Phase 9.

---

## Feature Overview

> 2–3 sentence summary of what this feature does and why it exists.

[Description]

**Current Phase:** [0 / 1 / 2 / 3 / 4 / 5 / 6 / 7 / 8 / 9]
**Feature Version:** [vX.Y.Z]
**Brief:** `docs/proposals/[FEATURE]-PROJECT-BRIEF.md`
**PRD:** `docs/proposals/[FEATURE]-PRD.md` *(if Phase 2+ complete)*
**Architecture:** `docs/proposals/[FEATURE]-ARCHITECTURE.md` *(if Phase 3+ complete)*

---

## Context Loading Strategy

Load these files at the start of each AI session for this feature (GSD 0–30% rule):

```
Always:
  .context/conventions.md
  .context/security-checklist.md
  .context/active/[feature-slug].md        ← this file

Subsystem (load only what's relevant to today's story):
  .context/tool-registry.md    # if implementing tools
  .context/rest-api.md         # if implementing REST endpoints
  .context/chat-ui.md          # if implementing frontend changes
  .context/testing.md          # if writing/reviewing tests
  .context/pro-vs-base.md      # if making Base vs Pro gating decisions
```

---

## Component Map

Which parts of NV oOS are affected:

- [ ] Tool registry — new tools: `[slug1]`, `[slug2]`
- [ ] REST API — new endpoints: `[route1]`, `[route2]`
- [ ] Admin settings — new settings: `[key1]`, `[key2]`
- [ ] Chat UI — frontend changes in `assets/js/chat.js`
- [ ] Database schema — CPT/CCT/options changes
- [ ] External API integration — service: `[service_name]`
- [ ] Base plugin (`includes/`) — PHP 7.4+ compatible
- [ ] Pro addon (`addons/pro/`) — PHP 8.1+ allowed

---

## Architectural Decisions

> Record decisions made DURING development that deviate from or extend the Architecture Spec.
> Add new entries as decisions are made; never delete old ones.

| Decision | Rationale | Date |
|----------|-----------|------|
| [Decision description] | [Why this approach was chosen] | YYYY-MM-DD |

---

## Known Issues / Gotchas

> Record technical issues, quirks, and traps discovered during development.
> These are invaluable for the next AI session picking up this work.

- **[Issue title]:** [Description and workaround if known]

---

## Story Status

| Story ID | Title | Status | Notes |
|----------|-------|--------|-------|
| 1.1 | [Story title] | Pending / In Progress / Complete | [Notes] |
| 1.2 | [Story title] | Pending / In Progress / Complete | [Notes] |

---

## Security Notes

> Feature-specific security considerations not covered in the base security checklist.

- [Note 1]

---

## Next Step

> What is the immediate next action when this context file is loaded?

[Phase X: Specific action to take next]
