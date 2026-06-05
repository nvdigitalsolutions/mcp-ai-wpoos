# NV oOS BMAD Teams

> The underlying GSD methodology is now standardised as [`open-gsd/gsd-core`](https://github.com/open-gsd/gsd-core). These team definitions are the NV oOS-specific instantiation — mapping BMAD roles to NV oOS Pro assistants and WordPress-aware toolchains.

This directory contains team composition definitions for the GSD × BMAD multi-agent workflow.

## Available Teams

| File | Description | Use When |
|------|-------------|----------|
| `feature-development.yaml` | Full GSD × BMAD team (6 agents, Phases 0–9) | Medium-to-major features and integrations |

## How Teams Work

A **team** definition maps BMAD agent roles to specific NV oOS default assistants
(`includes/class-wp-mcp-ai-default-assistants.php`). The Orchestrator agent delegates
to specialists at each phase gate, enforcing quality before advancing.

## Scale-Adaptive Usage

Not every feature requires the full team. Choose based on complexity:

| Feature Size | Agents | Phases |
|-------------|--------|--------|
| **Patch / Bug Fix** | Developer + QA | 5, 6, 7 |
| **Small Feature** | Orchestrator, Developer, QA | 0, 4, 5, 6, 7, 9 |
| **Medium Feature** | Orchestrator, Researcher, Developer, QA | 0, 1, 2, 3, 4, 5, 6, 7, 9 |
| **Major Feature** | Full 6-agent team | 0–9 |

## Adding New Teams

Create a new YAML file following the schema in `feature-development.yaml`. Key fields:

- `team.pattern` — `orchestrator` (supervisor) or `parallel` (independent agents)
- `team.members[]` — list of agent roles with `agent_id`, `assistant`, `phases`
- `team.phase_gates` — completion criteria for each phase transition
- `team.scale_adaptive` — predefined subsets for different feature sizes

## Related Files

- `.bmad/agents/` — Individual agent role definitions
- `.context/` — GSD context files loaded by agents
- `docs/proposals/templates/` — PRD, Architecture Spec, Project Brief templates
- `docs/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md` — Full methodology documentation
