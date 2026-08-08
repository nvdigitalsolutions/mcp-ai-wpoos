# AI Agents — Open Operator System (NV oOS)

> This document is the single source of truth for every AI coding agent that operates in this repository. It describes who they are, what they can do, which context files they load, and how they coordinate.
>
> Last reviewed: **August 6, 2026** · Version: **1.11**

### Related Files

| File | Purpose |
|------|---------|
| [`CLAUDE.md`](CLAUDE.md) | Claude Code per-turn context (naming, security, architecture) |
| [`MAINTAINER_MAP.md`](MAINTAINER_MAP.md) | Boot flow, directory map, build commands, canonical docs |
| [`CONTRIBUTING.md`](CONTRIBUTING.md) | PR process, quality gates, GSD × BMAD methodology |
| [`CODEOWNERS`](CODEOWNERS) | Auto-review assignment per path |
| [`SECURITY.md`](SECURITY.md) | Vulnerability disclosure policy |
| [`.github/copilot-instructions.md`](.github/copilot-instructions.md) | GitHub Copilot repo-level instructions |

---

## 1. Agent Inventory

### External AI Coding Agents

These are the AI assistants that human maintainers invoke when working on the repository.

| Agent | Provider | Context File | Trigger | Scope |
|-------|----------|-------------|---------|-------|
| **Claude Code** | Anthropic | [`CLAUDE.md`](CLAUDE.md) | Manual / Copilot Coding Agent | Full codebase — code generation, review, refactoring, docs |
| **GitHub Copilot** | GitHub / OpenAI | [`.github/copilot-instructions.md`](.github/copilot-instructions.md) | IDE completions, Copilot Chat, PR reviews | Inline suggestions, chat Q&A, PR summaries |
| **GitHub Custom Agents** | GitHub | [`.github/agents/*.agent.md`](.github/agents/) | Auto-discovered by GitHub Copilot Coding Agent and compatible runtimes | Role-specific agents (14 in this repo — reviewers, writers per subsystem, plus `addon-maintainer`, `toolkit-spa-maintainer` parameterised per addon, and `acp` for Agent Client Protocol). See each `*.agent.md` for scope. |
| **Zed Agent Profiles** | Zed Industries | [`.zed/settings.json`](.zed/settings.json) + [`.zed/README.md`](.zed/README.md) | Selected from the Agent Panel profile picker | Native Zed mirror of the `examples/agents/` roster (14 profiles) — same scopes, mapped to Zed's tool registry |
| **Zed Agent Skills** | Zed Industries | [`.agents/skills/*.md`](.agents/skills/) | Auto-discovered by Zed agent panel | 22 coding-time WordPress development skills (wp-abilities-api, wp-action-scheduler, wp-html-api, wp-i18n-audit, mcp-ai-wpoos-plugin, wp-plugin-*, wp-query-cache, wp-rest-api, wp-security-*, wp-utf8-text) — distinct from runtime bundled skills |
| **OpenAI Codex** | OpenAI | [`.codex/startup.sh`](.codex/startup.sh) | Codex sandbox tasks | Sandbox-based code generation and testing |

### Internal BMAD Agents (GSD × BMAD Workflow)

The NV oOS plugin itself includes an agentic multi-agent system for structured feature development. These agents run **inside** NV oOS assistants and follow the 10-phase GSD × BMAD methodology documented in [`CONTRIBUTING.md`](CONTRIBUTING.md).

> The GSD half of the methodology is now standardised upstream in [`open-gsd/gsd-core`](https://github.com/open-gsd/gsd-core) (`npx @opengsd/gsd-core@latest`) — NV oOS was an early adopter and proving ground. The `.bmad/` agent definitions and `.context/` files below are the NV oOS-specific instantiations.

| Agent ID | BMAD Role | NV oOS Assistant | Phases | YAML Definition |
|----------|-----------|-----------------|--------|-----------------|
| `nv-oos-analyst` | Analyst (Mary) | The Research Operative | 1 | [`.bmad/agents/nv-oos-analyst.yaml`](.bmad/agents/nv-oos-analyst.yaml) |
| `nv-oos-product-manager` | Product Manager (John) | The Content Drafter | 2 | [`.bmad/agents/nv-oos-product-manager.yaml`](.bmad/agents/nv-oos-product-manager.yaml) |
| `nv-oos-architect` | Architect (Winston) | The Unstructured Parser | 3 | [`.bmad/agents/nv-oos-architect.yaml`](.bmad/agents/nv-oos-architect.yaml) |
| `nv-oos-scrum-master` | Scrum Master (Bob) | The Orchestrator | 0, 4, 7, 9 | [`.bmad/agents/nv-oos-scrum-master.yaml`](.bmad/agents/nv-oos-scrum-master.yaml) |
| `nv-oos-developer` | Developer (Amelia) | The Publisher | 5 | [`.bmad/agents/nv-oos-developer.yaml`](.bmad/agents/nv-oos-developer.yaml) |
| `nv-oos-qa-engineer` | QA Engineer (Quinn) | The SEO & Compliance Auditor | 6, 8 | [`.bmad/agents/nv-oos-qa-engineer.yaml`](.bmad/agents/nv-oos-qa-engineer.yaml) |

Team composition and scale-adaptive usage are defined in [`.bmad/teams/feature-development.yaml`](.bmad/teams/feature-development.yaml).

### Agent Skills (runtime — distinct from coding agents)

Independent of the coding-time agents above, the plugin also exposes **Agent Skills** (per the [agentskills.io](https://agentskills.io/specification) specification) as a runtime mechanism. These are not AI agents themselves — they are portable behaviour packages (`SKILL.md` files) that any NV oOS assistant can load on demand. They are mentioned here so coding agents do not confuse them with the BMAD or external agent ecosystem.

| Aspect | Details |
|--------|---------|
| **Format** | A single `SKILL.md` per skill — Markdown body with a small YAML frontmatter (`name`, `description`, optional metadata). Stored on disk under `wp-content/uploads/mcp-ai-skills/{slug}/SKILL.md` after install. All bundled skills are **OKF v0.1-conformant** — they include `type: Skill` in frontmatter, making them navigable as Open Knowledge Format concepts (see [`docs/features/okf-integration.md`](docs/features/okf-integration.md)).
| **Bundled with base** | `includes/bundled-skills/` — 45 general-purpose Anthropic-authored skills (document handling, design, testing) + WordPress-developer skills (security, APIs, plugin patterns) + the new `ui-ux-pro-max` design system skill. |
| **Bundled with Pro** | `addons/pro/includes/bundled-skills/` — 28+ WordPress-developer skills curated from [`Lonsdale201/wp-agent-skills`](https://github.com/Lonsdale201/wp-agent-skills) (WooCommerce, JetEngine, JetFormBuilder, WP Rocket, etc.) plus a `THIRD_PARTY_NOTICES.md`. |
| **Remote catalogues (Pro)** | [`WP_MCP_AI_Skill_Catalogue_Service`](addons/pro/includes/services/class-wp-mcp-ai-skill-catalogue-service.php) and [`WP_MCP_AI_Skill_Catalogue_REST_Controller`](addons/pro/includes/rest/class-wp-mcp-ai-skill-catalogue-rest-controller.php) (`mcp-ai-pro/v1/catalogues/*`) install skills directly from registered public GitHub repos. SSRF-safe HTTPS-only fetcher. Pre-seeded with `Lonsdale201/wp-agent-skills` and `anthropics/skills`. |
| **Progressive disclosure** | Each assistant has a "Use progressive disclosure" checkbox; when on, the system prompt sees only `# Available Skills` (name + description) and the model calls the base-plugin `load_skill({ name })` tool to retrieve the full SKILL.md only when needed. |
| **Skill packs** | Curated, named bundles of related skills (e.g. "WordPress Developer") addressable as a single install unit via the Skill Manager admin UI. |
| **Reference** | [`docs/features/agent-skills.md`](docs/features/agent-skills.md) (full Phases 1–4 narrative) and [`docs/features/okf-integration.md`](docs/features/okf-integration.md) (OKF skill conformance).

**Coding-time skills (`.agents/skills/`) vs runtime skills:** The `.agents/skills/` directory contains 22 coding-time agent skills auto-discovered by the Zed editor. These are distinct from the runtime Agent Skills (45 base + 28+ Pro bundled skills loaded by NV oOS assistants at runtime). The `.agents/skills/` files are WordPress plugin development patterns that guide coding agents, while the runtime skills (`includes/bundled-skills/`) are SKILL.md files loaded by AI assistants during conversations.

When extending Agent Skills, see §6 ("Updating Agent Configuration") below for the file-update checklist.

---

## 2. Context-Loading Strategy

All agents follow the **GSD 30% Rule**: context files should consume no more than 30% of the agent's context window.

### Base context (always loaded)

Every agent session loads these two files:

| File | Content |
|------|---------|
| [`.context/conventions.md`](.context/conventions.md) | Naming conventions, PHP compatibility rules, code style |
| [`.context/security-checklist.md`](.context/security-checklist.md) | Security requirements for all code changes |

### Subsystem context (loaded per task)

| File | Load When |
|------|-----------|
| [`.context/tool-registry.md`](.context/tool-registry.md) | Working on tool implementations |
| [`.context/rest-api.md`](.context/rest-api.md) | Working on REST API endpoints |
| [`.context/chat-ui.md`](.context/chat-ui.md) | Working on frontend chat interface |
| [`.context/testing.md`](.context/testing.md) | Writing or reviewing tests |
| [`.context/pro-vs-base.md`](.context/pro-vs-base.md) | Making Base vs Pro placement decisions |
| [`docs/features/llm-harness.md`](docs/features/llm-harness.md) | Working on LLM Harnessing (Layers A–J) |
| [`docs/features/meta-harness-auto-optimization.md`](docs/features/meta-harness-auto-optimization.md) | Working on Meta-Harness trace capture, search, proposals, or auto-deploy |
| [`docs/features/agent-delegation-system.md`](docs/features/agent-delegation-system.md) | Working on agent delegation, cron resilience, or tasks drawer |
| [`docs/features/tool-presets-system.md`](docs/features/tool-presets-system.md) | Working on tool presets, essentials layers, or auto-upgrade |
| [`docs/features/memory/chat-client-integration.md`](docs/features/memory/chat-client-integration.md) | Working on the Chat-client Memory Bridge or Memory Drawer |
| [`docs/features/context-window-management.md`](docs/features/context-window-management.md) | Working on context-window validation, tiktoken, or token-budget capping |
| [`docs/features/pro-toolkit-optimization.md`](docs/features/pro-toolkit-optimization.md) | Working on Pro toolkit performance optimization classes |
| [`docs/features/dietpi-pro-toolkit.md`](docs/features/dietpi-pro-toolkit.md) | Working on DietPi server management tools |
| [`docs/features/layer-i-guardrails.md`](docs/features/layer-i-guardrails.md) | Working on jailbreak prevention or capability boundaries |
| [`docs/operations/production-hardening-guide.md`](docs/operations/production-hardening-guide.md) | Working on production security hardening (WAF, OAuth, DICOM, maintenance) |
| [`docs/developer/api-key-encryption.md`](docs/developer/api-key-encryption.md) | Working on API key storage and encryption |
| [`lib/core/README.md`](../lib/core/README.md) | Working on the framework-agnostic nvoos/core engine |

### Folder context (loaded per folder being edited)

Every PHP-bearing subdirectory under `includes/` (Base) and `addons/pro/includes/` (Pro) ships a `README.md` that follows the [folder README convention](docs/developer/folder-readme-convention.md). When an agent edits a file inside `includes/<folder>/`, it should first read `includes/<folder>/README.md` for the folder's purpose, public surface, neighbors, and which `.context/*.md` files to also load.

Folder READMEs are the **persistent, code-co-located, structural** layer of context. They:

- Restate **nothing** from the cross-cutting canonical sources (naming, security, PHP-compat). They link instead — same layering rule as `.github/agents/*.agent.md`.
- Are enforced by `composer run docs:check-folder-readmes` (part of `composer run ci:all`).
- Use the canonical template at [`.context/templates/folder-readme-template.md`](.context/templates/folder-readme-template.md).

Full convention: [`docs/developer/folder-readme-convention.md`](docs/developer/folder-readme-convention.md).

### Feature context (loaded per active feature)

Active features get a context file in `.context/active/[feature].md`. These are created at Phase 0, updated during development, and archived to `.context/archive/` at Phase 9.

Template: [`.context/templates/active-feature-template.md`](.context/templates/)

### Layering rule for `.github/agents/*.agent.md`

GitHub Custom Agent files (`.github/agents/*.agent.md`) are auto-discovered by GitHub's runtime and must stay small and role-specific. They sit on top of — not in place of — the canonical context above.

> **Layering rule:** `.github/agents/*.agent.md` files contain **only** agent-specific metadata (frontmatter: `name`, `description`, `tools`, `model`) and agent-specific behavior (scope, what to refuse, invocation examples, success criteria). They **MUST NOT** restate naming conventions, security rules, PHP-compat rules, tool patterns, or architecture. Instead, they link to the canonical sources:
>
> - [`AGENTS.md`](AGENTS.md) — inventory + coordination + handoff protocol
> - [`CLAUDE.md`](CLAUDE.md) — PHP compat, naming, tool pattern, security
> - [`.context/conventions.md`](.context/conventions.md) + [`.context/security-checklist.md`](.context/security-checklist.md) — always required reading
> - The relevant subsystem file(s) from `.context/` based on the agent's scope

This keeps the GSD 30% rule intact, prevents drift across `CLAUDE.md` / `AGENTS.md` / `.github/copilot-instructions.md` / `.github/agents/`, and preserves `AGENTS.md` as the single source of truth.

**Template + examples:**

- Canonical (empty) template: [`.context/templates/agent-file-template.md`](.context/templates/agent-file-template.md)
- Filled-in copy-ready examples: [`examples/agents/`](examples/agents/) — a 14-agent roster covering every major NV oOS subsystem, split between read-only reviewers (REST, security, WP.org compliance, PHP compat) and writers (tools, slash commands, chat UI, PHPUnit tests, agent skills, addon maintenance, **toolkit-SPA addons** following the [Toolkit SPA Blueprint](docs/developer/addons/toolkit-spa-blueprint.md), **ACP protocol** implementation, release engineering, docs). See [`examples/agents/README.md`](examples/agents/README.md) for the full table.

---

## 3. Agent Capabilities and Limitations

### Claude Code

| Aspect | Details |
|--------|---------|
| **Strengths** | Full codebase reasoning, multi-file refactoring, test generation, architecture analysis |
| **Context file** | `CLAUDE.md` — loaded automatically every turn |
| **Limitations** | Cannot push to git directly; uses `report_progress` tool. Cannot access `.github/agents/` directory |
| **PHP compat** | Must target PHP 7.4+ for base plugin, PHP 8.1+ for Pro addon |
| **Security** | Must follow all rules in `.context/security-checklist.md` |

### GitHub Copilot

| Aspect | Details |
|--------|---------|
| **Strengths** | Fast inline completions, Copilot Chat for Q&A, PR review summaries |
| **Context file** | `.github/copilot-instructions.md` — loaded per Copilot session |
| **Limitations** | Shorter context window; best for single-file or small-scope changes |
| **Configuration** | Follows `@wordpress/eslint-plugin` for JS, WPCS for PHP |

### OpenAI Codex

| Aspect | Details |
|--------|---------|
| **Strengths** | Sandboxed execution, can run builds and tests autonomously |
| **Context file** | `.codex/startup.sh` — bootstrap script for Codex sandbox |
| **Limitations** | Ephemeral sandbox; no persistent state between runs |

### BMAD Agents

| Aspect | Details |
|--------|---------|
| **Strengths** | Structured 10-phase workflow with phase gates; specialized per role |
| **Context files** | Each agent loads role-specific context from `.bmad/agents/*.yaml` |
| **Limitations** | Require NV oOS Pro tools for orchestration; depend on active NV oOS assistants |
| **Coordination** | Orchestrator (Scrum Master) delegates to specialists via `delegate_to_agent` tool |

---

## 4. Inter-Agent Coordination

### Avoiding duplication

When multiple agents work on the same repository:

1. **Check recent commits** before starting work — another agent may have already addressed part of the task.
2. **Use conventional commits** (`feat(scope):`, `fix(scope):`, `docs(scope):`) so other agents can parse intent from `git log`.
3. **Do not modify files another agent is actively editing** in the same PR.

### Handoff protocol

The BMAD workflow defines explicit phase gates (documented in [`.bmad/teams/feature-development.yaml`](.bmad/teams/feature-development.yaml)). Before an agent can start its phase, the previous phase's exit criteria must be met:

| Transition | Gate Criteria |
|------------|---------------|
| Phase 0 → 1 | Base context loaded, feature context initialized |
| Phase 1 → 2 | Project Brief complete and approved |
| Phase 2 → 3 | PRD approved with all acceptance criteria |
| Phase 3 → 4 | Architecture Specification reviewed |
| Phase 4 → 5 | All stories broken down and sequenced |
| Phase 5 → 6 | All story code committed with atomic commits |
| Phase 6 → 7 | All acceptance criteria verified; lint + test + CodeQL pass |
| Phase 7 → 8 | Version bumped, CHANGELOG updated, Git tag + Release published |
| Phase 8 → 9 | No new errors in first 48 hours |

### Scale-adaptive usage

Not every change requires the full 10-phase workflow:

| Change Size | Agents Involved | Phases |
|------------|----------------|--------|
| **Patch / Bug Fix** | Developer + QA | 5, 6, 7 |
| **Small Feature** | Orchestrator, Developer, QA | 0, 4, 5, 6, 7, 9 |
| **Medium Feature** | Orchestrator, Researcher, Developer, QA | 0, 1–7, 9 |
| **Major Feature** | Full 6-agent team | 0–9 |

---

## 5. Security and Privacy

### Data handling

- **No secrets in prompts.** API keys, tokens, and credentials must never appear in agent context files or commit messages.
- **No PII in agent output.** Sanitize any user data before including it in agent-visible context.
- **Credential storage.** All API keys are stored in WordPress options (encrypted at rest) — never in source code.

### Agent permissions

| Agent | Can Write Code | Can Push to Git | Can Access Secrets |
|-------|---------------|----------------|-------------------|
| Claude Code | ✅ | Via `report_progress` only | ❌ |
| GitHub Copilot | ✅ (suggestions) | Via human commit | ❌ |
| OpenAI Codex | ✅ (sandbox) | Via sandbox workflow | ❌ |
| BMAD Agents | ✅ (via NV oOS tools) | Via `manage_autonomous_session` | ❌ (tools check capabilities) |

### Vulnerability reporting

If an AI agent produces code with a security vulnerability, report it through the process described in [`SECURITY.md`](SECURITY.md). Do **not** open a public issue.

---

## 6. Updating Agent Configuration

### When to update which file

| Change | Files to Update |
|--------|----------------|
| New naming convention or security rule | `CLAUDE.md`, `.github/copilot-instructions.md`, `.context/conventions.md` |
| New tool, hook, or REST endpoint | `CLAUDE.md` (architecture section) |
| New BMAD agent or workflow change | `.bmad/agents/*.yaml`, `AGENTS.md`, `.bmad/teams/feature-development.yaml` |
| New subsystem context | `.context/`, `AGENTS.md` (context-loading table) |
| New external AI agent | `AGENTS.md` (agent inventory), `MAINTAINER_MAP.md` (AI coordination section) |
| New `includes/` or `addons/pro/includes/` subdirectory | Add `README.md` per [folder README convention](docs/developer/folder-readme-convention.md); run `composer run docs:check-folder-readmes` |
| New or changed GitHub Custom Agent | `.github/agents/*.agent.md` (per layering rule in §2), `AGENTS.md` (agent inventory in §1) — must be in the same PR. If a matching agent also exists in [`examples/agents/`](examples/agents/), update `.zed/settings.json` so the Zed profile's tool block stays in sync. |
| New bundled skill or skill pack | Add `SKILL.md` under `includes/bundled-skills/` (base) or `addons/pro/includes/bundled-skills/` (Pro); update the corresponding `THIRD_PARTY_NOTICES.md` if curated from an upstream catalogue; document in `docs/features/agent-skills.md` |
| New coding-time agent skill | Add `SKILL.md` under `.agents/skills/{slug}/`; update `AGENTS.md` (agent inventory in §1); verify the skill is auto-discovered by the Zed agent panel |
| New security infrastructure class | `CLAUDE.md` (architecture section), `.context/security-checklist.md`, `README.md` (features list), `docs/features/security-infrastructure.md` |

### Review cadence

These files should be reviewed whenever:
- A new AI coding agent is adopted
- The project's coding standards change materially
- A major version is released
- Quarterly, at minimum

---

## 7. References

- [GSD × BMAD Methodology Proposal](docs/project/proposals/GSD-BMAD-METHODOLOGY-PROPOSAL.md)
- [Agent Memory Management Guide](docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md)
- [Developer Hooks Reference](docs/reference/hooks/DEVELOPER_HOOKS_REFERENCE.md)
- [Architecture Decision Record #1 — Module Boundaries](docs/project/architecture-decisions/ADR_001_module_boundaries.md)
- [Context Engineering Files README](.context/README.md)
- [BMAD Agent Definitions README](.bmad/README.md)
- [GitHub CODEOWNERS Documentation](https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/customizing-your-repository/about-code-owners)
