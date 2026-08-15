# Active Task Contexts

One file per in-progress task, created at task start and updated during work — the workspace equivalent of the repo's `.context/active/` feature files.

## Lifecycle

1. **Created** — at task start, from `../templates/active-task-template.md`.
2. **Updated** — after each working session: status, progress log, decisions, gotchas.
3. **Archived** — on completion, moved to `../archive/<task-slug>-YYYY-MM-DD.md`.

## Naming

`<task-slug>.md` — kebab-case, e.g. `mcp-timeout-tuning.md`, `context-layer-v1.md`.

## Rules

- Under 500 lines per file.
- One task per file; do not merge unrelated work.
- Keep the "Context loading plan" section accurate — it tells the next session what to load.
