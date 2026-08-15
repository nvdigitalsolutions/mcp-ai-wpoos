# Delegated Sub-Agent Roles (BMAD-style)

Role prompts for Hermes delegation (`delegation.*` in `config.yaml`; max 2 concurrent, depth 1). Each role file contains **only** role-specific behavior — persona, phases, critical rules, tools, handoff criteria. Shared rules are never restated here; they live in:

- `../conventions.md` + `../security-checklist.md` (always loaded)
- `../hermes-ops.md` (phase gates, kanban)
- `../wp-plugin-dev.md` / `../mcp-integration.md` / `../design-content.md` (subsystem, per task)
- `../../AGENTS.md` (inventory + layering rule)

## Roster

| File | Role | Phases |
|------|------|--------|
| `scrum-master.md` | Orchestrator | Plan, gate, ship |
| `analyst.md` | Research | Discovery, briefs |
| `product-manager.md` | Planning | PRD, acceptance criteria |
| `architect.md` | Architecture | Design, file maps |
| `developer.md` | Implementation | Code, commits, tests |
| `qa-engineer.md` | Verify, monitor | Lint, tests, post-release health |

## Handoff protocol

A phase starts only when the previous phase's exit criteria are met (gate table in `../hermes-ops.md`). Sub-agents emit `HANDOFF: <NEXT_ROLE> <task-slug>` signals with their deliverables.
