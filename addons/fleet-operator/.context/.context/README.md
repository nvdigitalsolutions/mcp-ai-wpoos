# Workspace Context Files (GSD-style)

> Local instantiation of the GSD context-engineering pattern proven in the mcp-ai-wpoos repo (`F:\GITHUB\worktrees\mcp-ai-wpoos\<branch>\mcp-ai-wpoos\.context\`). The repo's files encode *plugin* conventions; these files encode *workspace/agent* conventions.

## Purpose

Without a persistent context layer, agent sessions lose track of: where things live, security rules, Hermes/MCP operational quirks, and decisions made in previous sessions. These files are the fast, focused reference loaded at session start.

## Files

| File | Load When |
|------|-----------|
| `conventions.md` | **Always** — paths, naming, task lifecycle |
| `security-checklist.md` | **Always** — secrets, untrusted data, WP security |
| `hermes-ops.md` | Working on Hermes config, hooks, kanban, cron, memory |
| `wp-plugin-dev.md` | Working on mcp-ai-wpoos plugin code |
| `mcp-integration.md` | Using or debugging the `nv-oos-sophie-agent` MCP toolkit |
| `design-content.md` | Marketing / design / content tasks (Sophie ecosystem) |

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `active/` | One file per in-progress task (`<task-slug>.md`) |
| `archive/` | Completed task files (historical reference) |
| `templates/` | Templates for task files, memory entries |
| `roles/` | Sub-agent role prompts for Hermes delegation |

## Loading Strategy (30% rule)

1. Load base context (`conventions.md` + `security-checklist.md`).
2. Load only the subsystem file(s) relevant to the task.
3. Active tasks: create/update `.context/active/<task>.md`.
4. Skills: load `SKILL.md` content only when the task matches.
5. Keep it lean — do not load everything.
