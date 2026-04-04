# NV oOS GSD Context Files

This directory contains **GSD (Get Shit Done)** context engineering files for the NV oOS plugin development workflow. These lightweight files are loaded at the start of AI development sessions to preserve architectural knowledge and coding conventions across context windows.

## Purpose

NV oOS has a complex codebase (519+ tools, 570+ docs, multiple AI providers). Without context engineering, AI agents lose track of:
- Coding conventions and naming rules
- Security requirements for each subsystem
- Interdependencies between tool registry, REST API, and chat UI
- Architectural decisions made in previous sessions

These context files solve that problem by giving agents a fast, focused reference to load at session start.

## Files

| File | Load When |
|------|-----------|
| `conventions.md` | **Always** — naming, code style, PHP compatibility |
| `security-checklist.md` | **Always** — security requirements for all code changes |
| `tool-registry.md` | Working on tool implementations |
| `rest-api.md` | Working on REST API endpoints |
| `chat-ui.md` | Working on frontend chat interface |
| `testing.md` | Writing or reviewing PHPUnit tests |
| `pro-vs-base.md` | Making Base vs Pro version placement decisions |

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `active/` | Active feature context files (one per in-progress feature) |
| `archive/` | Completed feature context files (for historical reference) |
| `templates/` | Templates for creating new context files |

## Context Loading Strategy (GSD Principle)

Following the GSD 0–30% context window rule:

1. **Load base context** — always load `conventions.md` + `security-checklist.md`
2. **Load subsystem context** — only load subsystem files relevant to the current task
3. **Load feature context** — load `.context/active/[feature].md` for active features
4. **Keep context lean** — only load what's needed; avoid loading everything

## Active Feature Contexts

Each active feature gets its own context file in `active/`. These are initialized at Phase 0
from the Project Brief summary, updated during development, and archived during Phase 9.

Use the template at `templates/active-feature-template.md` as the starting point.

## GSD × BMAD Workflow

Context files are a core part of the **GSD × BMAD methodology** implemented in NV oOS.
The full workflow is documented in `docs/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md`.

Agent definitions that consume these context files are in `.bmad/agents/`.
