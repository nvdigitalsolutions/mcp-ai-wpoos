# NV oOS BMAD Agent Definitions

> **Last reviewed:** August 2026 (v1.1.50). The GSD half of the GSD × BMAD methodology is now standardized upstream in [`open-gsd/gsd-core`](https://github.com/open-gsd/gsd-core) (`npx @opengsd/gsd-core@latest`). NV oOS was an early adopter and implementation proving ground for the concepts that gsd-core now productises: meta-prompting, context engineering, fresh-context subagents, phase-loop governance, and spec-driven development. The `.bmad/`, `.context/`, and BMAD agent definitions below remain the NV oOS-specific instantiation — the BMAD roles and NV oOS Pro tool mappings that make the methodology work inside WordPress.
>
> All BMAD-role agents operate under the
> Unix Theory Compliance Phases P0–P7 constraints landed across the v1.1.15 →
> v1.1.27 cycle: the canonical return envelope (forbid
> `array( 'success' => false, ... )` for errors), the two-gate sanitisation
> rule (`WPMCPAI.Tools.SanitizeAtEntry` Gate 1 + escape at exit Gate 2), the
> optional `WP_MCP_AI_Tool_Data_Contract_Interface`, and the back-compat alias
> infrastructure for tool-decomposition PRs. See [`CLAUDE.md`](../CLAUDE.md) §
> "Tool Return Format" and § "Tool Sanitisation — Two-Gate Rule".

This directory contains the **BMAD (Breakthrough Method for Agile AI-Driven Development)** agent role definitions for the NV oOS plugin development workflow. These are the **Agent-as-Code** configurations that enable consistent, specialized AI prompting across development sessions.

## Overview

NV oOS uses a hybrid **GSD × BMAD** methodology for AI-assisted feature development. The GSD half is now standardised as [`open-gsd/gsd-core`](https://github.com/open-gsd/gsd-core) (`npx @opengsd/gsd-core@latest`); the NV oOS-specific BMAD workflow and agent definitions are documented in [`docs/project/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md`](../docs/project/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md).

Each agent definition (`.yaml` file) specifies a BMAD role with:
- **Persona** — role identity, communication style
- **Responsibilities** — which phases this agent leads
- **Critical rules** — non-negotiable requirements for this role
- **Tools** — NV oOS Pro tools this agent uses
- **Context files** — which `.context/` files to load
- **Handoff criteria** — what must be true before passing work to the next agent

## Agent Roster

| File | BMAD Role | NV oOS Assistant | Phases |
|------|-----------|-----------------|--------|
| `nv-oos-analyst.yaml` | Analyst (Mary) | The Research Operative | 1 |
| `nv-oos-product-manager.yaml` | Product Manager (John) | The Content Drafter | 2 |
| `nv-oos-architect.yaml` | Architect (Winston) | The Unstructured Parser | 3 |
| `nv-oos-scrum-master.yaml` | Scrum Master (Bob) | The Orchestrator | 0, 4, 7, 9 |
| `nv-oos-developer.yaml` | Developer (Amelia) | The Publisher | 5 |
| `nv-oos-qa-engineer.yaml` | QA Engineer (Quinn) | The SEO & Compliance Auditor | 6, 8 |

## Teams

Predefined multi-agent team compositions are in the [`teams/`](teams/) subdirectory.

The default team definition is `teams/feature-development.yaml`, which supports **scale-adaptive** usage:

| Feature Size | Agents | Phases |
|-------------|--------|--------|
| Patch / Bug Fix | Developer + QA | 5, 6, 7 |
| Small Feature | Orchestrator, Developer, QA | 0, 4, 5, 6, 7, 9 |
| Medium Feature | Orchestrator, Researcher, Developer, QA | 0, 1, 2, 3, 4, 5, 6, 7, 9 |
| Major Feature | Full 6-agent team | 0–9 |

## How to Use Agent Definitions

When starting an AI development session, load the appropriate agent definition to establish consistent role behavior:

```
1. Open your AI tool (GitHub Copilot, Claude Code, etc.)
2. Reference the agent YAML: `.bmad/agents/nv-oos-[role].yaml`
3. Load the context files listed in the agent's `context_files` field
4. The agent definition sets the persona, responsibilities, and rules for the session
```

## Relationship to Default Assistants

Each BMAD agent maps to one of the 6 pre-configured NV oOS assistants
(`includes/class-wp-mcp-ai-default-assistants.php`). The default assistant system prompts
include a **GSD × BMAD Development Mode** section that activates the corresponding BMAD
role behavior when working on feature development tasks.

## Related Files

- [`teams/`](teams/) — Multi-agent team compositions
- [`.context/`](../.context/) — GSD context engineering files
- [`docs/project/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md`](../docs/project/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md) — Full methodology documentation
- [`docs/project/proposals/templates/`](../docs/project/proposals/templates/) — PRD, Architecture Spec, and Project Brief templates
- [`CONTRIBUTING.md`](../CONTRIBUTING.md) — Phase-completion checklists
