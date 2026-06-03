# Ralph Wiggum Autonomous Loop Session Template

> **GSD Context Template** — Use this to configure autonomous development sessions.
> Based on: `docs/project/proposals/RALPH-WIGGUM-TASK-ORCHESTRATION-IMPLEMENTATION.md`
> Last reviewed: March 2026.

---

## Session Configuration Template

```yaml
session:
  id: "[feature-slug]-loop-[iteration-number]"

  context_files:
    - .context/conventions.md
    - .context/security-checklist.md
    - .context/tool-registry.md        # Include if working on tools
    - .context/rest-api.md             # Include if working on REST endpoints
    - .context/chat-ui.md              # Include if working on frontend
    - .context/testing.md              # Include for all implementation sessions
    - .context/active/[feature].md     # Current feature context

  token_budget: 10000       # GSD 0–30% rule — keeps context lean
  max_iterations: 15        # Circuit breaker for runaway loops

  exit_conditions:
    - type: completion_indicator
      check: "all stories in task_plan marked complete"
    - type: exit_signal
      value: "EXIT_SIGNAL: FEATURE_COMPLETE"

  retry:
    max_retries_per_story: 3
    on_retry: "log failure reason, reload story context, re-execute"

  on_exit:
    - update task_plan status to "complete"
    - emit Phase 9 (Retrospective) trigger
    - archive .context/active/[feature].md to .context/archive/[feature]-vX.Y.Z.md
    - call batch_manage_memory with key learnings
```

---

## Built-in Safeguards Reference

| Safeguard | Mechanism | Setting |
|-----------|-----------|---------|
| **Max iterations** | Loop limit | `max_iterations: 5–15` |
| **Token budget** | Context tracking | `token_budget: 8000–12000` |
| **Circuit breaker** | Error detection | `check_exit_conditions` tool |
| **Session timeout** | Cron expiry | 24-hour session expiration |
| **Dual-exit condition** | Completion + EXIT_SIGNAL | Both required |
| **Retry cap** | Story-level retries | `max_retries_per_story: 3` |

---

## NV oOS Tool Chain for the Loop

| Step | Tool | Purpose |
|------|------|---------|
| Initialize | `manage_autonomous_session(start)` | Create session with context + token budget |
| Load story list | `get_task_plan` | Retrieve pending/complete story status |
| Execute story | `delegate_to_agent` | Run story in isolated sub-agent context |
| Validate | `check_workflow_health` | Verify story outputs meet acceptance criteria |
| Update progress | `update_task_plan` | Mark story complete, capture notes |
| Check completion | `detect_completion_indicators` | Semantic check: all stories done + EXIT_SIGNAL |
| Loop control | `check_exit_conditions` | Enforce circuit breaker |
| Exit | `manage_autonomous_session(complete)` | Close session, trigger Phase 9 |

---

## Instantiation Example

```javascript
// Via NV oOS Pro tool:
manage_autonomous_session({
  "action":          "start",
  "session_id":      "my-feature-loop-1",
  "context_files":   [
    ".context/conventions.md",
    ".context/security-checklist.md",
    ".context/active/my-feature.md"
  ],
  "token_budget":    10000,
  "max_iterations":  15,
  "exit_signal":     "EXIT_SIGNAL: FEATURE_COMPLETE"
})
```

---

## Loop Execution Flow

```
Phase 0: Initialize Session
  → Load .context/active/[feature].md + context files
  ↓
Phase 4: Load Task Plan (get_task_plan)
  → Identify next pending story
  ↓
Phase 5: Execute Story (delegate_to_agent → Developer)
  ↓
Phase 6: Validate Story (check_workflow_health)
  → All acceptance criteria met?
  ├─ NO  → Fix issues → Re-execute (max 3 retries)
  └─ YES → update_task_plan(status: "complete")
           ↓
           All stories complete? (detect_completion_indicators)
           ├─ NO  → Back to "Load Task Plan"
           └─ YES + EXIT_SIGNAL emitted
                    ↓
                    Phase 7: Release Gate
                    Phase 9: Retrospective (batch_manage_memory)
```

---

## Per-Story Session (Smaller Scope)

For a single story in isolation (rather than a full feature loop):

```yaml
session:
  id: "story-X.X-[feature-slug]"

  context_files:
    - .context/conventions.md
    - .context/security-checklist.md
    - .context/tool-registry.md        # If story involves a tool
    - story-X.X-spec.md               # Story spec with acceptance criteria

  token_budget: 8000       # Lean context for single story
  max_iterations: 8

  exit_conditions:
    - type: exit_signal
      value: "EXIT_SIGNAL: STORY_COMPLETE"
```
