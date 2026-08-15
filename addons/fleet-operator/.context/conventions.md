# Workspace Conventions (ALWAYS loaded)

## Paths (canonical)

| Path | Role |
|------|------|
| `/home/ppuhgmkjff` | Workspace root — context layer + skills + Hermes home |
| `/home/ppuhgmkjff/.context/` | Layered context files (this file lives here) |
| `/home/ppuhgmkjff/.context/active/` | Active task files (`<task-slug>.md`) |
| `/home/ppuhgmkjff/.context/archive/` | Completed task files |
| `/home/ppuhgmkjff/.context/roles/` | Sub-agent role prompts |
| `/home/ppuhgmkjff/.agents/skills/` | Curated skills (progressive disclosure) |
| `/home/ppuhgmkjff/.hermes/` | Hermes runtime state |
| Plugin worktree | Host-dependent; Windows host: `F:\GITHUB\worktrees\mcp-ai-wpoos\<branch>\mcp-ai-wpoos` |

## File conventions

- Markdown everywhere; kebab-case filenames; tables over prose walls.
- Context files ≤ ~120 lines each (30% rule). Role prompts ≤ 60 lines.
- Task files: `.context/active/<task-slug>.md`, under 500 lines, from `templates/active-task-template.md`.
- Skills: one `SKILL.md` per skill; YAML frontmatter (`name`, `description`, optional metadata). Never strip upstream attribution.
- New `.context/` files get an entry in `AGENTS.md` tables where relevant.

## Task lifecycle

1. **New task** → create `.context/active/<task>.md` from the template; log on the kanban board.
2. **During work** → update Status / Progress log / Decisions & gotchas.
3. **Done** → move to `.context/archive/<task>-YYYY-MM-DD.md`; record 2–5 learnings in `~/.hermes/memories/` (template: `templates/memory-entry-template.md`).

## Commits (plugin repo)

`feat(scope): …` · `fix(scope): …` · `docs(scope): …` · `test(scope): …` — atomic per story, story reference in the message. Check recent commits before starting new work.

## Naming

- Roles: kebab-case matching `.context/roles/*.md` (`developer`, `analyst`, `product-manager`, `architect`, `scrum-master`, `qa-engineer`).
- Kanban columns: mirror the phase gates in `hermes-ops.md`.
- Do not invent new top-level directories in the workspace root — extend `.context/` instead.
