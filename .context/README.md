# NV oOS GSD Context Files

> The GSD context-engineering methodology is now standardised upstream in [`open-gsd/gsd-core`](https://github.com/open-gsd/gsd-core) (`npx @opengsd/gsd-core@latest`). NV oOS was an early proving ground for the context-engineering principles that gsd-core now productises: keeping the main context window below 30% utilisation, spawning fresh-context subagents for heavy work, and persisting decisions across session boundaries via structured artefacts.
>
> The `.context/` files below remain NV oOS-specific — they encode the naming conventions, security rules, subsystem knowledge, and architectural decisions unique to this WordPress plugin codebase.

This directory contains **GSD (Get Shit Done)** context engineering files for the NV oOS plugin development workflow. These lightweight files are loaded at the start of AI development sessions to preserve architectural knowledge and coding conventions across context windows.

## Purpose

NV oOS has a complex codebase (~1,500 tools, 1,600+ docs, multiple AI providers). Without context engineering, AI agents lose track of:
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
| `cross-platform-extraction.md` | Working on the cross-platform extraction engine (Laravel/Craft CMS adapters) |
| `settings-storage.md` | Working on plugin options, transients, or custom table storage |

## Subdirectories

| Directory | Purpose |
|-----------|---------|
| `active/` | Active feature context files (one per in-progress feature) |
| `archive/` | Completed feature context files (for historical reference) |
| `templates/` | Templates for creating new context files |

## Folder-Level Context (NEW)

In addition to the subsystem files above, every PHP-bearing subdirectory under `includes/` and `addons/pro/includes/` ships its own `README.md` that declares the folder's purpose, public surface, neighbors, and which `.context/*.md` files to load alongside it.

This is the **structural** layer of context engineering: it stays close to the code, doesn't drift, and slots between subsystem files (this directory) and feature files (`active/`). See:

- Template: [`templates/folder-readme-template.md`](templates/folder-readme-template.md)
- Convention: [`../docs/developer/folder-readme-convention.md`](../docs/developer/folder-readme-convention.md)
- Enforcement: `composer run docs:check-folder-readmes` (part of `composer run ci:all`)

## Context Loading Strategy (GSD Principle)

Following the GSD 0–30% context window rule:

1. **Load base context** — always load `conventions.md` + `security-checklist.md`
2. **Load subsystem context** — only load subsystem files relevant to the current task
3. **Load folder context** — when editing files inside `includes/<folder>/`, read `includes/<folder>/README.md` first
4. **Load feature context** — load `.context/active/[feature].md` for active features
5. **Keep context lean** — only load what's needed; avoid loading everything

## Active Feature Contexts

Each active feature gets its own context file in `active/`. These are initialized at Phase 0
from the Project Brief summary, updated during development, and archived during Phase 9.

Use the template at `templates/active-feature-template.md` as the starting point.

## GSD × BMAD Workflow

Context files are a core part of the **GSD × BMAD methodology** implemented in NV oOS.
The full workflow is documented in `docs/project/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md`.

Agent definitions that consume these context files are in `.bmad/agents/`.
