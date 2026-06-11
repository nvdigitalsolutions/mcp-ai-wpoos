# GSD + BMAD Methodology Proposal for NV oOS

**Date:** March 2026
> **Status:** 🚧 Partially adopted (v1.1.29) — GSD context files + folder README convention shipped; BMAD agent definitions not implemented
**Author:** NV Digital Solutions
**Applies To:** NV oOS Plugin Development Workflow

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [What is GSD (Get Shit Done)?](#what-is-gsd-get-shit-done)
3. [What is BMAD (Breakthrough Method for Agile AI-Driven Development)?](#what-is-bmad)
4. [Why These Methods Matter for NV oOS](#why-these-methods-matter-for-nv-oos)
5. [Current State Assessment](#current-state-assessment)
6. [Proposed Hybrid Workflow: GSD × BMAD for NV oOS (Phases 0–9)](#proposed-hybrid-workflow)
7. [Agent Role Mapping](#agent-role-mapping)
8. [Implementation Phases](#implementation-phases)
9. [Spec-Driven Development Templates](#spec-driven-development-templates)
10. [Context Engineering Strategy](#context-engineering-strategy)
11. [Tool System Integration](#tool-system-integration)
12. [NV oOS Pro Toolkit Integration](#nv-oos-pro-toolkit-integration)
13. [Multi-Agent Team Compositions](#multi-agent-team-compositions)
14. [Autonomous Development Loop](#autonomous-development-loop)
15. [Quality Gates and Checklists](#quality-gates-and-checklists)
16. [Expected Benefits](#expected-benefits)
17. [Risks and Mitigations](#risks-and-mitigations)
18. [References](#references)

---

## Executive Summary

This proposal recommends adopting a hybrid **GSD + BMAD** development methodology for the NV oOS (Open Operator System) plugin. These two frameworks complement each other to solve the primary challenges facing AI-driven WordPress plugin development:

- **GSD** (Get Shit Done) brings lightweight meta-prompting, context engineering, and spec-driven workflows that keep AI agents focused and productive.
- **BMAD** (Breakthrough Method for Agile AI-Driven Development) provides structured multi-agent orchestration, artifact-driven traceability, and agent-as-code definitions.

Together, they address context loss, inconsistent code quality, scope drift, and untraceable architectural decisions — all common pain points in AI-assisted development of complex systems like NV oOS (519+ tools, 570+ documentation files, multi-provider AI integration).

### Key Recommendations

| Area | Recommendation |
|------|---------------|
| **Planning** | Adopt BMAD artifact-driven planning (PRDs, architecture specs) for major features |
| **Execution** | Use GSD context engineering for AI-assisted coding sprints |
| **Agent Roles** | Map BMAD agent personas to NV oOS development workflows |
| **Documentation** | Embed spec-driven templates into existing proposal/architecture docs |
| **Quality** | Add BMAD phase-completion checklists to existing CI/CD gates |
| **Context** | Apply GSD context management to preserve architectural decisions across sessions |

---

## What is GSD (Get Shit Done)?

GSD is a lightweight, AI-native development framework designed for solo developers and small teams working with AI coding agents (Claude Code, Copilot, Codex, etc.). It was created to fight **"context rot"** — the degradation of AI output quality as context windows become overloaded.

### Core Principles

1. **Meta-Prompting** — Recursive prompts that describe how to generate, analyze, and improve other prompts. A chain of meta-prompts can take a high-level goal, clarify requirements, generate specs, validate them, and output clean code with tests.

2. **Context Engineering** — Keeps the main context window lean (ideally 0–30% full) by spawning sub-agents in fresh, dedicated windows for discrete tasks. Token prioritization and semantic chunking prevent hallucinations.

3. **Spec-Driven Development** — Every project starts with a complete specification captured via a standardized template. Tasks are broken into validated phases, executed autonomously by AI sub-agents in compliance with the original spec.

4. **Atomic Tasks** — Work is broken into the smallest possible independent units, each executed in an isolated pristine context for maximum quality.

5. **Minimal Ceremony** — No simulated corporate theater (unnecessary stand-ups, sprints, retrospectives). Focus on explicit specs, atomic tasks, and direct integration with AI coding runtimes.

### GSD Workflow

```
┌─────────────┐    ┌──────────────┐    ┌──────────────┐    ┌─────────────┐
│   Capture    │───►│    Plan      │───►│   Execute    │───►│   Verify    │
│    Spec      │    │   Phases     │    │   Tasks      │    │  & Ship     │
│              │    │              │    │  (sub-agents) │    │             │
└─────────────┘    └──────────────┘    └──────────────┘    └─────────────┘
     ▲                                        │
     └────────────────────────────────────────┘
              Feedback & spec updates
```

### Key GSD Commands

| Command | Purpose |
|---------|---------|
| `/gsd:plan-phase` | Break spec into validated task phases |
| `/gsd:execute` | Execute a task in isolated AI context |
| `/gsd:update-project` | Structured update ensuring docs stay in sync |
| `/gsd:brainstorm` | Guided idea generation |

**Sources:**
- [GSD Core — open-gsd/gsd-core](https://github.com/open-gsd/gsd-core) — the current upstream standard (`npx @opengsd/gsd-core@latest`)
- [GSD Official Website](https://gsd.build/)
- [Mastering GSD: Meta-prompting & Context Engineering](https://dev.to/arkacoc13/mastering-get-shit-done-integrating-meta-prompting-context-engineering-and-spec-driven-4dnl)

---

## What is BMAD?

BMAD (Breakthrough Method for Agile AI-Driven Development) is a comprehensive framework that orchestrates multiple specialized AI agents — each mirroring agile team roles — to deliver software with human-level rigor, transparency, and agility.

### Core Principles

1. **Multi-Agent System (MAS)** — Instead of one generic AI, BMAD orchestrates a team of specialized agents: Analyst, Architect, Product Manager, Scrum Master, Developer, QA, and Orchestrator.

2. **Agent-as-Code** — Agent definitions are Markdown/YAML files that describe roles, behaviors, prompts, and dependencies. These are versioned, portable, and reusable — like Infrastructure-as-Code for AI expertise.

3. **Artifact-Driven Traceability** — Every output (requirements, architecture, stories, code, tests) is documented, version-controlled, and auditable. Artifacts are the ultimate source of truth.

4. **Context-Engineered Development** — Planning documents and architectural blueprints are systematically embedded into downstream agent tasks, eliminating context loss between sessions.

5. **Human-in-the-Loop Governance** — Humans review, refine, and approve agent outputs at each stage, injecting domain knowledge and catching hallucinations.

6. **Scale-Adaptive Intelligence** — Workflow depth automatically adjusts based on project complexity — from quick bug fixes to enterprise system rollouts.

### BMAD Agent Roles

| Agent | Role | Persona | Key Artifacts |
|-------|------|---------|---------------|
| **Analyst (Mary)** | Research & ideation | Domain researcher | Project briefs, market analysis |
| **Product Manager (John)** | Requirements formalization | Market-savvy PM | PRDs, epics, success metrics |
| **Architect (Winston)** | System design | Technical architect | Architecture specs, diagrams, data models |
| **Scrum Master (Bob)** | Workflow orchestration | Process facilitator | Story breakdown, sprint plans, retrospectives |
| **Developer (Amelia)** | Implementation | Senior engineer | Code, documentation, atomic commits |
| **QA Engineer (Quinn)** | Testing & validation | Quality guardian | Test plans, bug reports, coverage reports |

### BMAD Workflow

```
┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐    ┌──────────┐
│ Analysis │───►│ Planning │───►│  Design  │───►│  Build   │───►│ Validate │
│ (Mary)   │    │ (John)   │    │(Winston) │    │ (Amelia) │    │ (Quinn)  │
└──────────┘    └──────────┘    └──────────┘    └──────────┘    └──────────┘
                                     ▲               │
                        ┌────────────┴───────────────┘
                        │ Bob (Scrum Master) orchestrates
                        │ handoffs and process governance
                        └────────────────────────────────
```

**Sources:**
- [BMAD Method Documentation](https://docs.bmad-method.org/)
- [BMAD GitHub Repository](https://github.com/bmad-code-org/BMAD-METHOD)
- [BMAD Standard Workflow Guide](https://dev.to/jacktt/bmad-standard-workflow-2kma)
- [BMAD Implementation Guide](https://buildmode.dev/blog/mastering-bmad-method-2025/)

---

## Why These Methods Matter for NV oOS

NV oOS is a complex, enterprise-grade WordPress plugin with unique characteristics that make GSD + BMAD adoption particularly valuable:

### Complexity Factors

| Factor | Scale | Challenge |
|--------|-------|-----------|
| **Tool count** | 519+ built-in tools | Consistency, testing coverage, documentation |
| **Documentation** | 570+ files | Keeping docs in sync with rapid development |
| **AI providers** | 3+ (OpenAI, Gemini, Ollama) | Multi-provider testing, compatibility |
| **Integration points** | 19+ external services | Regression risk, API stability |
| **Code surface** | Base + Pro + Addons | Version management, feature gating |
| **Architecture** | MCP protocol, SSE, REST API | Standards compliance, security |

### Pain Points These Methods Address

1. **Context Loss Between Sessions** — With 570+ docs and a massive codebase, AI agents frequently lose track of architectural decisions, coding conventions, and interdependencies. GSD's context engineering directly addresses this.

2. **Inconsistent Tool Implementation** — With 519+ tools, maintaining consistent quality, error handling, and documentation is challenging. BMAD's artifact-driven approach and QA gates enforce consistency.

3. **Documentation Drift** — Despite excellent docs (A grade, 95/100), rapid development creates drift between code and documentation. GSD's spec-driven approach keeps them in sync by making specs the source of truth.

4. **Complex Feature Planning** — Features like DICOM imaging, Shopify integration, and multi-agent orchestration require deep architectural planning. BMAD's multi-agent planning workflow ensures thoroughness.

5. **Security-Critical Development** — NV oOS handles credentials, API keys, and user data. BMAD's QA gates and checklist-driven validation add structured security review.

---

## Current State Assessment

NV oOS already has strong development practices that align well with GSD + BMAD principles:

### What NV oOS Already Does Well

| Practice | Current State | GSD/BMAD Alignment |
|----------|--------------|-------------------|
| **Proposal System** | 78+ structured proposals in `docs/proposals/` | Aligns with BMAD PRD artifacts |
| **Release Process** | Defined patch/minor/major workflows | Aligns with BMAD phase governance |
| **CI/CD Pipeline** | PHPUnit, PHPCS, ESLint, CodeQL | Aligns with BMAD QA gates |
| **Architecture Docs** | SETTINGS_METHODOLOGY.md (1,364 lines) | Aligns with BMAD architecture specs |
| **Tool Registry Pattern** | Extensible `execute()` interface | Aligns with GSD atomic tasks |
| **Security Practices** | Sanitization, nonces, capability checks | Aligns with BMAD security checklists |
| **Code Ownership** | CODEOWNERS, MAINTAINER_MAP | Aligns with BMAD role assignment |

### Gaps This Proposal Addresses

| Gap | Current State | Proposed Solution |
|-----|--------------|------------------|
| **Formal spec templates** | Proposals vary in structure | Standardized BMAD-style PRD + architecture templates |
| **Context preservation** | Ad-hoc between sessions | GSD context engineering with `.context/` files |
| **Agent role definitions** | No formal AI agent personas | BMAD agent-as-code definitions in `.bmad/` |
| **Phase-completion checklists** | Informal review | Structured BMAD checklists for each development phase |
| **Story breakdown process** | Feature-level planning | BMAD-style epic → story → task decomposition |
| **Automated spec validation** | Manual review | GSD spec validation commands |

---

## Proposed Hybrid Workflow

The hybrid GSD × BMAD workflow for NV oOS combines BMAD's structured planning with GSD's execution efficiency. The full workflow spans **10 phases (0–9)**, extending the original 6 with a pre-phase context initialization, a release management phase, a post-release monitoring phase, and a retrospective phase.

```
Phase 0        Phase 1        Phase 2        Phase 3        Phase 4
Context Init → Discovery   → Planning    → Architecture → Story Breakdown
(GSD)          (BMAD)         (BMAD)         (BMAD)         (BMAD)
                                                               │
                                                               ▼
Phase 9        Phase 8        Phase 7        Phase 6        Phase 5
Retrospective← Monitoring  ← Release     ← Validation  ← Implementation
(GSD)          (Pro Toolkit)  (BMAD)         (BMAD)         (GSD)
```

### Phase 0: Context Initialization (GSD-Led)

**Lead Agent:** Scrum Master
**Activities:**
- Load base context files (conventions, security requirements, testing patterns)
- Load subsystem context relevant to the feature scope (tool-registry, REST API, Pro vs Base)
- Initialize `.context/active/[feature].md` from the project brief summary
- Establish token budget and context window targets (GSD 0–30% rule)

**NV oOS Application:**
```
Context initialization order:
1. .context/conventions.md       ← Always loaded (naming, code style)
2. .context/security-checklist.md ← Always loaded (security requirements)
3. .context/pro-vs-base.md       ← Feature gating decisions
4. .context/[subsystem].md       ← Scoped to feature (tool-registry, rest-api, chat-ui)
5. .context/active/[feature].md  ← Created fresh from Project Brief summary

Context Budget Target: < 30% of context window (GSD principle)
```

**NV oOS Pro Tools Available:**
- `batch_manage_memory` — Seed working memory with key architectural facts from previous sessions
- `semantic_content_search` — Locate relevant existing implementations and patterns in the codebase

**Context Initialization Checklist:**
- [ ] Base context files exist (create from templates if missing)
- [ ] Feature context file initialized from Project Brief
- [ ] Context window budget estimated and under 30%
- [ ] Working memory seeded with relevant architectural decisions
- [ ] Subsystem context files identified and loaded

### Phase 1: Discovery & Analysis (BMAD-Led)

**Lead Agent:** Analyst
**Activities:**
- Research domain requirements (e.g., new integration, tool category)
- Competitive analysis and WordPress ecosystem review
- Produce a **Project Brief** with goals, constraints, and assumptions

**NV oOS Application:**
```
docs/proposals/[FEATURE]-PROJECT-BRIEF.md
├── Problem Statement
├── Target Users
├── WordPress Ecosystem Context
├── Competitive Alternatives
├── Feasibility Assessment
└── Initial Recommendations
```

### Phase 2: Planning & Requirements (BMAD-Led)

**Lead Agent:** Product Manager
**Activities:**
- Formalize brief into a **Product Requirements Document (PRD)**
- Define epics, user stories, acceptance criteria
- Map to NV oOS tool system, REST API, or UI

**NV oOS Application:**
```
docs/proposals/[FEATURE]-PRD.md
├── Goals & Success Metrics
├── Functional Requirements
├── Non-Functional Requirements (performance, security, accessibility)
├── Tool Definitions (slug, capabilities, parameters)
├── REST API Endpoints (routes, permissions, schemas)
├── Epics & Stories
├── Story Sequencing
└── PRD Validation Checklist
```

### Phase 3: Architecture & Design (BMAD-Led)

**Lead Agent:** Architect
**Activities:**
- Design system architecture based on PRD
- Define data models, hooks, filters, class hierarchy
- Produce **Architecture Specification**

**NV oOS Application:**
```
docs/proposals/[FEATURE]-ARCHITECTURE.md
├── System Overview
├── Component Diagram
├── Class Hierarchy (WP_MCP_AI_* naming)
├── Database Schema (CPT/CCT/Options)
├── Hook & Filter Registry
├── REST API Design
├── Security Model
├── Integration Points
├── Architecture Review Checklist
└── File Map (which files to create/modify)
```

### Phase 4: Story Breakdown (BMAD-Led)

**Lead Agent:** Scrum Master
**Activities:**
- Break architecture into atomic, implementable stories
- Embed architectural context into each story
- Sequence stories for parallel or serial execution

**NV oOS Application:**
```
Each story includes:
├── Story ID & Title
├── User Story (As a [role], I want [action], so that [value])
├── Architecture Reference (section of architecture doc)
├── Acceptance Criteria (testable checklist)
├── Files to Create/Modify
├── Dependencies
├── WordPress Hooks to Use
├── Security Requirements (sanitize, escape, nonce, capability)
└── Test Requirements
```

### Phase 5: Implementation (GSD-Led)

**Lead Agent:** Developer
**Activities:**
- Execute each story as an atomic GSD task
- Fresh AI context per story (context engineering)
- Automatic documentation and atomic commits

**NV oOS Application:**
```
For each story:
1. Load story spec + relevant architecture sections (context engineering)
2. Execute in isolated AI context (GSD style)
3. Follow NV oOS coding standards:
   - WP_MCP_AI_* class naming
   - WordPress Coding Standards (WPCS)
   - PHPDoc blocks on all classes/methods
   - Security: sanitize input, escape output, check capabilities
4. Commit atomically with story reference
5. Update documentation if needed
```

### Phase 6: Validation (BMAD-Led)

**Lead Agent:** QA Engineer
**Activities:**
- Run existing test suite (PHPUnit, ESLint)
- Validate against acceptance criteria
- Security review (CodeQL, manual inspection)
- Documentation completeness check

**NV oOS Application:**
```
QA Checklist:
- [ ] All acceptance criteria met
- [ ] PHPUnit tests pass (composer run test)
- [ ] PHPCS clean (composer run lint)
- [ ] ESLint clean (npm run lint:js)
- [ ] CodeQL scan passes
- [ ] Security review complete (sanitization, escaping, capabilities)
- [ ] Documentation updated
- [ ] Backward compatibility verified
- [ ] Base vs Pro version gating correct
```

### Phase 7: Release & Deployment (BMAD-Led)

**Lead Agent:** Scrum Master
**Activities:**
- Follow NV oOS release process (patch/minor/major) as defined in `docs/RELEASE_PROCESS.md`
- Bump version numbers in all locations (plugin header, `composer.json`, `package.json`)
- Update `CHANGELOG.md` with complete feature summary and affected files
- Create Git tag and draft GitHub Release with descriptive notes
- Verify WordPress.org compliance if base plugin is affected

**NV oOS Application:**
```
Release checklist (from docs/RELEASE_PROCESS.md):
├── Version bump: plugin header, composer.json, package.json
├── composer run build:autoload (regenerate classmap for production)
├── CHANGELOG.md entry: feature summary, affected files, API changes
├── Git tag: git tag -a vX.Y.Z -m "Release vX.Y.Z"
├── GitHub Release: title, release notes, attach build artifacts
├── WordPress.org plugin check passes (if base plugin affected):
│   ├── No output escaping violations
│   ├── ABSPATH guards on all non-root files
│   └── No hardcoded admin menu positions
└── Base vs Pro gating tested in both WP_MCP_AI_BASE_VERSION modes
```

**NV oOS Tools Available:**
- `check_wp_cli` — Verify WP-CLI is available and working for deployment operations
- `check_site_security` — Final security scan before release is tagged

**Release Gate (additional to existing):**
- [ ] `composer run build:autoload` completed and classmap committed
- [ ] All version strings updated consistently across all files
- [ ] CHANGELOG.md entry written with complete affected-file list
- [ ] Git tag created matching plugin header version
- [ ] GitHub Release drafted and published
- [ ] WordPress.org plugin check passes (for base plugin changes)

### Phase 8: Post-Release Monitoring (Pro Toolkit-Assisted)

**Lead Agent:** QA Engineer
**Activities:**
- Monitor feature health using Pro workflow health and orchestration monitoring tools
- Track error rates, API call patterns, and tool execution results for 48–72 hours post-deploy
- Alert on regressions or unexpected behavior via workflow health checks
- Confirm JetEngine CCT database migrations completed successfully (if applicable)

**NV oOS Application:**
```
Post-release monitoring points:
├── PHP error log: no new warnings or errors after deploy
├── Tool execution success rate > 95% (first 48 hours)
├── API token usage within budget projections
├── SSE connections healthy for any new streaming features
├── JetEngine CCT migration options set (wp_mcp_ai_*_migration_v*)
└── User-facing chat features: test via frontend chat widget
```

**NV oOS Tools Available:**
- `check_workflow_health` — Monitor active workflows and orchestration pipelines for anomalies
- `get_session_status` — Track autonomous session completion rates and errors
- `analyze_data_patterns` — Identify anomalies in tool usage data and API call distributions

**Post-Release Monitoring Checklist:**
- [ ] No new PHP errors in the first 48 hours post-deploy
- [ ] Tool execution success rate > 95%
- [ ] API token usage within budget; no unexpected spikes
- [ ] No regression in existing tool test suite
- [ ] User-reported issues triaged and tracked

### Phase 9: Retrospective & Context Harvest (GSD-Led)

**Lead Agent:** Scrum Master + All Agents
**Activities:**
- Capture lessons learned from the full development cycle (Phases 0–8)
- Update `.context/` files with new architectural decisions and discovered gotchas
- Update `.bmad/` agent definitions if workflow improvements were found
- Archive or close the active feature context file
- Feed learnings back into the next cycle's Phase 0 initialization

**NV oOS Application:**
```
Post-cycle context updates:
├── Archive: .context/active/[feature].md → .context/archive/[feature]-vX.Y.Z.md
├── Update subsystem context files with new patterns discovered
├── Add new gotchas or conventions to .context/conventions.md
├── Update .bmad/agents/*.yaml with improved critical_rules (if needed)
├── Document API/integration quirks found during implementation
└── Note any performance optimizations identified during monitoring
```

**NV oOS Tools Available:**
- `batch_manage_memory` — Persist key learnings to long-term agent memory for future sessions
- `manage_autonomous_session` (action: `complete`) — Close and archive completed development sessions

**Context Harvest Checklist:**
- [ ] All architectural decisions documented in updated context files
- [ ] Discovered gotchas added to `.context/conventions.md`
- [ ] `.context/active/[feature].md` archived (not deleted)
- [ ] Agent definitions updated if workflow improvements identified
- [ ] Retrospective summary added to feature's Architecture Specification doc

---

## Agent Role Mapping

Map BMAD agent roles to NV oOS development contexts:

### For AI-Assisted Development (GitHub Copilot, Claude Code, etc.)

| BMAD Role | NV oOS Context | System Prompt Focus |
|-----------|---------------|-------------------|
| **Analyst** | Feature research, integration feasibility | WordPress ecosystem knowledge, plugin architecture |
| **Architect** | System design, class hierarchy, data models | NV oOS patterns (CPT/CCT, tool registry, REST API) |
| **Product Manager** | PRD creation, story definition | User personas (site admin, developer, guest user) |
| **Scrum Master** | Story breakdown, context packaging | NV oOS file structure, dependency mapping |
| **Developer** | Code implementation | WPCS, security standards, tool interface patterns |
| **QA Engineer** | Testing, validation | PHPUnit patterns, WP_UnitTestCase, security checklist |

### Agent-as-Code Definitions

Store agent definitions as Markdown + YAML in the repository for consistent AI prompting:

```yaml
# .bmad/agents/nv-oos-developer.yaml
agent:
  metadata:
    id: nv-oos-developer
    name: Developer
    title: NV oOS Senior Developer
    icon: 👨‍💻
  persona:
    role: WordPress Plugin Developer
    identity: Expert in NV oOS architecture, MCP protocol, WordPress APIs
    communication_style: Direct, code-focused, security-conscious
  critical_rules:
    - Always use WP_MCP_AI_ class prefix
    - Follow WordPress Coding Standards (WPCS)
    - Sanitize all input, escape all output
    - Check capabilities before privileged operations
    - Use nonces for state-changing requests
    - PHPDoc blocks on all classes, methods, and functions
    - Tools must implement execute() method
    - Tools must declare required_capability
  context_files:
    - CONTRIBUTING.md
    - docs/architecture/SETTINGS_METHODOLOGY.md
    - includes/tools/class-wp-mcp-ai-tool-base.php
```

---

## Implementation Phases

### Phase 1: Foundation (Week 1-2)

**Goal:** Establish GSD + BMAD infrastructure without disrupting existing workflow.

- [x] Create `.bmad/` directory with agent role definitions
- [x] Create `.context/` directory for GSD context preservation files
- [x] Create standardized proposal templates (Brief, PRD, Architecture)
- [x] Document the hybrid workflow in this proposal
- [x] Add phase-completion checklists to CONTRIBUTING.md

### Phase 2: Template Adoption (Week 3-4)

**Goal:** Apply templates to one new feature as a pilot.

- [ ] Select a medium-complexity feature from the roadmap
- [ ] Create Project Brief using new template
- [ ] Create PRD with epics, stories, and acceptance criteria
- [ ] Create Architecture spec with file map and security model
- [ ] Break into stories with embedded architectural context
- [ ] Execute stories using GSD context engineering
- [ ] Retrospective: document what worked, what to adjust

### Phase 3: Workflow Integration (Week 5-8)

**Goal:** Integrate with existing CI/CD and development processes.

- [x] Add checklist validation to PR template
- [x] Create GSD context files for major subsystems (tool registry, REST API, chat UI)
- [ ] Train on agent role definitions for consistent AI prompting
- [x] Establish context preservation for cross-session development
- [ ] Measure: cycle time, defect rate, documentation drift

### Phase 4: Optimization (Ongoing)

**Goal:** Refine based on experience and evolving best practices.

- [ ] Update agent definitions based on lessons learned
- [ ] Expand context files as new subsystems are developed
- [ ] Community feedback on template usability
- [ ] Evaluate automated spec validation tools
- [ ] Track metrics: shipping speed, code quality, test coverage

### Phase 5: Multi-Agent Infrastructure (Weeks 9–12)

**Goal:** Leverage NV oOS's built-in multi-agent capabilities for fully automated GSD × BMAD execution.

- [x] Map the 6 default assistants (`includes/class-wp-mcp-ai-default-assistants.php`) to BMAD roles (Orchestrator → Scrum Master, Researcher → Analyst, Publisher → Developer, SEO Auditor → QA)
- [x] Create `.bmad/teams/feature-development.yaml` defining the Orchestrator pattern team composition
- [x] Add BMAD-specific instructions to each assistant's system prompt:
  - Orchestrator: enforce phase gates, load `.context/active/[feature].md` at session start
  - Researcher: produce Project Briefs using `deep_research` + `generate_research_report`
  - Publisher (Developer): enforce `.context/security-checklist.md` compliance on every story
  - SEO Auditor (QA): run `check_workflow_health` + acceptance criteria check after each story
- [ ] Test one complete Phase 0–6 cycle using `create_agent_team` + `delegate_to_agent`
- [x] Document team composition in `.bmad/teams/README.md`

### Phase 6: Automation & Metrics (Weeks 13–16+)

**Goal:** Automate gate checks, reduce manual overhead, and establish measurable success criteria.

- [x] Create automated context initialization workflow (Phase 0 automation via `batch_manage_memory`)
- [x] Integrate `create_task_plan` into PR template (auto-generate story breakdown artifact)
- [x] Add `check_workflow_health` call to post-deploy CI step (Phase 8 automation)
- [x] Establish baseline metrics for the first 3 completed feature cycles:
  - Feature cycle time (Phase 0 start → Phase 7 release)
  - Context setup time per AI session (target: < 5 minutes)
  - Story completion rate (target: > 90% without rework)
  - Defect rate post-merge (target: 30–50% reduction)
- [x] Configure Pro Dashboard monitoring (`docs/PRO_DASHBOARD_MONITORING.md`) for active development sessions
- [x] Track AI token usage per phase to identify context budget optimization opportunities

---

## Spec-Driven Development Templates

### Project Brief Template

```markdown
# [Feature Name] — Project Brief

**Date:** YYYY-MM-DD
**Phase:** Discovery
**Author:** [Name]

## Problem Statement
What problem does this solve? Who experiences it?

## Target Users
- Site administrators who...
- Developers who...
- End users who...

## WordPress Ecosystem Context
- Related plugins/solutions: [list]
- WordPress core features leveraged: [list]
- NV oOS components affected: [tool registry, REST API, chat UI, etc.]

## Feasibility Assessment
- Technical complexity: [Low/Medium/High]
- Security considerations: [list]
- Third-party dependencies: [list]
- Base vs Pro version: [which]

## Recommendations
Proceed to PRD? [Yes/No]
Key risks: [list]
```

### PRD Template

```markdown
# [Feature Name] — Product Requirements Document

**Date:** YYYY-MM-DD
**Phase:** Planning
**Status:** Draft / In Review / Approved
**Brief Reference:** [link to Project Brief]

## Goals & Success Metrics
| Goal | Metric | Target |
|------|--------|--------|
| [Goal 1] | [Measurement] | [Value] |

## Functional Requirements
### FR-1: [Requirement Name]
- Description: ...
- Priority: Must Have / Should Have / Nice to Have
- Acceptance Criteria:
  - [ ] Criterion 1
  - [ ] Criterion 2

## Non-Functional Requirements
- Performance: [response time, throughput]
- Security: [authentication, authorization, data handling]
- Accessibility: [WCAG level]
- Compatibility: [PHP versions, WP versions]

## Tool Definitions
| Tool Slug | Description | Capability | Parameters |
|-----------|-------------|------------|------------|
| [slug] | [description] | [cap] | [params] |

## REST API Endpoints
| Method | Route | Permission | Description |
|--------|-------|------------|-------------|
| [GET/POST] | /mcp-ai/v1/[route] | [callback] | [description] |

## Epics & Stories
### Epic 1: [Name]
- Story 1.1: As a [role], I want [action], so that [value]
- Story 1.2: ...

## Story Sequencing
[Order and dependencies]

## PRD Validation Checklist
- [ ] All goals have measurable success metrics
- [ ] All requirements have acceptance criteria
- [ ] Security requirements documented
- [ ] Tool definitions follow NV oOS patterns
- [ ] REST endpoints have permission callbacks
- [ ] Stories are independent and testable
- [ ] Dependencies identified
- [ ] Base vs Pro gating specified
```

### Architecture Spec Template

```markdown
# [Feature Name] — Architecture Specification

**Date:** YYYY-MM-DD
**Phase:** Design
**PRD Reference:** [link to PRD]

## System Overview
[High-level description and context within NV oOS]

## Component Diagram
[ASCII or Mermaid diagram showing components and relationships]

## Class Hierarchy
| Class | Extends | Responsibility |
|-------|---------|---------------|
| WP_MCP_AI_[Name] | [Parent] | [Description] |

## Data Model
### Custom Post Type / CCT
| Field | Type | Description |
|-------|------|-------------|
| [field] | [type] | [description] |

### WordPress Options
| Option Key | Type | Description |
|------------|------|-------------|
| wp_mcp_ai_[key] | [type] | [description] |

## Hook & Filter Registry
| Hook/Filter | Type | Description |
|-------------|------|-------------|
| wp_mcp_ai_[name] | action/filter | [description] |

## REST API Design
[Detailed endpoint specifications with request/response schemas]

## Security Model
- Authentication: [method]
- Authorization: [capabilities required]
- Input sanitization: [functions used]
- Output escaping: [functions used]
- Nonce verification: [yes/no, context]

## File Map
| File Path | Action | Description |
|-----------|--------|-------------|
| includes/tools/class-wp-mcp-ai-tool-[name].php | Create | [description] |
| tests/test-[name].php | Create | [description] |

## Architecture Review Checklist
- [ ] All components follow WP_MCP_AI_ naming convention
- [ ] Security model covers authentication, authorization, sanitization, escaping
- [ ] Data model uses appropriate WordPress storage (CPT/CCT/Options)
- [ ] REST endpoints follow NV oOS patterns
- [ ] Hooks/filters enable extensibility
- [ ] File map is complete and follows project structure
- [ ] Backward compatibility maintained
- [ ] Pro vs Base version gating defined
```

---

## Context Engineering Strategy

### Problem: Context Loss in AI-Assisted Development

NV oOS has 570+ documentation files and a complex codebase. When AI agents work on features, they frequently lose track of:
- Architectural decisions made in previous sessions
- Coding conventions specific to NV oOS
- Interdependencies between tool registry, REST API, and chat UI
- Security requirements for each subsystem

### Solution: GSD Context Files

Create lightweight context files that can be loaded at the start of each AI session:

```
.context/
├── conventions.md          # NV oOS coding conventions and naming rules
├── security-checklist.md   # Security requirements for all code changes
├── tool-registry.md        # How to add/modify tools
├── rest-api.md             # REST endpoint patterns and auth
├── chat-ui.md              # Frontend chat surface patterns
├── testing.md              # PHPUnit patterns and test utilities
├── pro-vs-base.md          # Feature gating guidelines
└── active/
    ├── [feature-name].md   # Current feature context (architecture + stories)
    └── ...
```

### Context Loading Strategy

Following GSD principles, each AI session should:

1. **Load base context** (conventions + security checklist) — always
2. **Load subsystem context** (tool-registry, rest-api, etc.) — based on task
3. **Load feature context** (from `.context/active/`) — for active features
4. **Keep context lean** — only what's needed for the current task (GSD's 0–30% rule)

### Context Preservation

After each development session:
- Update `.context/active/[feature].md` with decisions made
- Note any deviations from the architecture spec
- Record "known issues" for the next session
- Keep the context file under 500 lines (force conciseness)

---

## Tool System Integration

NV oOS's tool registry system is uniquely suited for GSD + BMAD adoption because each tool is an independent, atomic unit — exactly what GSD recommends.

### Tool Development Workflow (GSD × BMAD)

```
1. BMAD Architect: Define tool spec (slug, description, params, capability)
         ▼
2. BMAD Scrum Master: Create implementation story with full context
         ▼
3. GSD Developer: Execute in isolated context
   a. Load tool-registry context file
   b. Implement class extending WP_MCP_AI_Tool_Base
   c. Write PHPUnit test
   d. Atomic commit
         ▼
4. BMAD QA: Validate against acceptance criteria
   a. Test execution with valid/invalid inputs
   b. Security review (capability check, sanitization)
   c. Documentation check (PHPDoc, tool-reference.md)
```

### Example: Adding a New Tool Using GSD × BMAD

**Story:**
> As a site administrator, I want to use a `manage_redirects` tool so that I can create and manage URL redirects through the AI assistant.

**Context Package (loaded into AI session):**
```
.context/conventions.md       (naming, code style)
.context/security-checklist.md (security requirements)
.context/tool-registry.md     (tool patterns, base class API)
Story spec with acceptance criteria
```

**Acceptance Criteria:**
- [ ] Tool class: `WP_MCP_AI_Tool_Manage_Redirects`
- [ ] Tool slug: `manage_redirects`
- [ ] Required capability: `manage_options`
- [ ] Actions: `create`, `list`, `delete`
- [ ] Input sanitization on all parameters
- [ ] PHPDoc blocks on class and methods
- [ ] PHPUnit test with ≥3 test methods
- [ ] Registered in `includes/tools-init.php`

---

## NV oOS Pro Toolkit Integration

This section maps each phase of the GSD × BMAD workflow to **specific NV oOS tools** that AI agents can invoke to automate or accelerate each phase. This transforms the methodology from a process framework into a tool-driven automation pipeline.

### Phase-to-Tool Mapping

| Phase | Lead Agent | NV oOS Tool(s) | Purpose |
|-------|-----------|----------------|---------|
| **0** — Context Init | Scrum Master | `batch_manage_memory`, `semantic_content_search` | Seed working memory; locate existing patterns |
| **1** — Discovery | Analyst | `deep_research`, `verify_information`, `aggregate_research_data`, `generate_research_report` | Domain research, competitive analysis, fact verification |
| **2** — Planning | Product Manager | `create_task_plan`, `generate_research_report`, `extract_structured_data` | Formalize PRD, create task plan artifact |
| **3** — Architecture | Architect | `analyze_code_sequence`, `semantic_content_search`, `extract_structured_data` | Review existing patterns, define file map |
| **4** — Story Breakdown | Scrum Master | `create_task_plan`, `update_task_plan`, `get_task_plan` | Decompose architecture into atomic stories |
| **5** — Implementation | Developer | `manage_autonomous_session`, `delegate_to_agent`, `check_exit_conditions` | Execute stories in isolated context with loop control |
| **6** — Validation | QA Engineer | `check_workflow_health`, `get_session_status`, `verify_information` | Run validation suite, check acceptance criteria |
| **7** — Release | Scrum Master | `check_wp_cli`, `check_site_security` | Pre-release security scan and deployment checks |
| **8** — Monitoring | QA Engineer | `check_workflow_health`, `analyze_data_patterns`, `get_session_status` | Post-release health monitoring |
| **9** — Retrospective | Scrum Master | `batch_manage_memory`, `manage_autonomous_session` | Persist learnings, close/archive sessions |

### Architect Agent Toolkit (Pro)

The Pro addon includes dedicated **Architect Agent** tools (`addons/pro/includes/tools/architect-agent/`) that directly support Phases 1 and 3:

| Tool | Capability | BMAD Phase |
|------|-----------|------------|
| `analyze_code_sequence` | Trace execution paths and identify architectural patterns in existing code | Phase 3 |
| `extract_structured_data` | Parse existing file maps, schemas, class hierarchies, and API contracts | Phase 3 |
| `aggregate_research_data` | Consolidate research from multiple sources into a structured summary | Phase 1 |
| `generate_research_report` | Produce structured Project Briefs or Architecture Specifications as Markdown/PDF | Phase 1 |

### Task Planning Toolkit (Pro)

The `create_task_plan`, `update_task_plan`, and `get_task_plan` tools provide **persistent, structured task management** directly aligned to BMAD story breakdown in Phase 4:

```
Story Breakdown via Task Plan Tools:

1. Phase 4 — Create:
   create_task_plan(
     project_name: "[Feature]-v[X.Y.Z]",
     goal: "Epic from PRD",
     phases: ["Architecture Review", "Core Implementation", "Tests", "Docs", "QA"]
   )

2. Phase 5 — Per story update:
   update_task_plan(
     task_id: "story-X.X",
     status: "in_progress",
     context: "Architecture reference + acceptance criteria"
   )

3. Phase 6 — QA review:
   get_task_plan(project_name: "[Feature]-v[X.Y.Z]")
   → Verify all stories are "complete" before advancing to Phase 7
```

### Autonomous Session Management (Pro)

`manage_autonomous_session` bridges GSD's "isolated context execution" principle with the plugin's autonomous infrastructure:

```
GSD Execute Phase (5) via Autonomous Sessions:

Per-story session:
- session_id:     "story-X.X-[feature-slug]"
- context_files:  [conventions.md, security-checklist.md, tool-registry.md, story-spec.md]
- token_budget:   8000  ← keeps context lean (GSD 0–30% principle)
- exit_signal:    "STORY_COMPLETE"
- max_iterations: 15
```

### Memory & RAG Integration (Pro)

The plugin's RAG and memory tools extend GSD context engineering beyond the current session:

| Capability | Tool | Phase Used |
|-----------|------|-----------|
| Persistent architectural memory | `batch_manage_memory` | Phase 0 (load), Phase 9 (save) |
| Semantic pattern search | `semantic_content_search` | Phase 0, Phase 3 |
| Embedding-based context retrieval | `create_text_embeddings` | Phase 3 (for large codebases) |
| Gemini Corpus RAG | `semantic_retrieval` (via Gemini provider) | Phase 1 (research grounding) |

---

## Multi-Agent Team Compositions

NV oOS ships 6 pre-configured assistants (`includes/class-wp-mcp-ai-default-assistants.php`) that can be directly mapped to BMAD roles, enabling a fully NV oOS-native GSD × BMAD team without any external tooling.

### BMAD Role → NV oOS Assistant Mapping

| BMAD Role | NV oOS Assistant | Model | Specialty |
|-----------|-----------------|-------|-----------|
| **Analyst (Mary)** | The Research Operative | GPT-4o-mini | Domain research, competitive analysis, Crawl4AI web scraping, `deep_research` |
| **Architect (Winston)** | The Unstructured Parser | GPT-4o-mini | Data modeling, schema extraction, vector store patterns, `analyze_code_sequence` |
| **Product Manager (John)** | The Content Drafter | GPT-4o | Requirements formalization, story writing, PRD generation |
| **Scrum Master (Bob)** | The Orchestrator | GPT-4o | Task decomposition, agent handoff coordination, phase gate enforcement |
| **Developer (Amelia)** | The Publisher | GPT-4o-mini | Code execution, WordPress CRUD, atomic commits, WPCS compliance |
| **QA Engineer (Quinn)** | The SEO & Compliance Auditor | GPT-4o-mini | Test validation, security review, documentation completeness, `check_workflow_health` |

### Recommended Team Configuration

```yaml
# .bmad/teams/feature-development.yaml
team:
  name: NV oOS Feature Development Team
  pattern: orchestrator   # Supervisor delegates to specialized workers
  members:
    - role: orchestrator
      assistant: the-orchestrator
      responsibilities:
        - Load context from .context/active/[feature].md at session start
        - Route tasks to appropriate specialist agents via delegate_to_agent
        - Monitor progress via check_workflow_health
        - Enforce phase-completion gates before advancing
    - role: researcher
      assistant: the-research-operative
      responsibilities:
        - Phase 1 (Discovery): domain research, competitor analysis
        - Produce Project Brief using deep_research + generate_research_report
        - Verify claims via verify_information before proceeding to Phase 2
    - role: architect
      assistant: the-unstructured-parser
      responsibilities:
        - Phase 3 (Architecture): analyze existing patterns via analyze_code_sequence
        - Produce Architecture Specification using extract_structured_data
        - Define data models, class hierarchy, and file maps
    - role: developer
      assistant: the-publisher
      responsibilities:
        - Phase 5 (Implementation): atomic story execution via manage_autonomous_session
        - Follow WPCS + security-checklist.md strictly (capability checks, nonces, escaping)
        - Commit atomically with story reference; update task_plan on completion
    - role: qa_engineer
      assistant: the-seo-compliance-auditor
      responsibilities:
        - Phase 6 (Validation): run PHPUnit, PHPCS, CodeQL; verify acceptance criteria
        - Phase 8 (Monitoring): track post-release health via check_workflow_health
        - Report anomalies and unresolved issues to the Orchestrator
```

### Instantiating the Team via NV oOS

```
# Instantiate the BMAD team using NV oOS Pro tools:
create_agent_team({
  "team_name":          "GSD-BMAD Feature Team",
  "team_type":          "orchestrator",
  "team_composition": {
    "orchestrator": "the-orchestrator",
    "researcher":   "the-research-operative",
    "architect":    "the-unstructured-parser",
    "developer":    "the-publisher",
    "qa_engineer":  "the-seo-compliance-auditor"
  },
  "workflow_template": "feature_development",
  "context_files": [
    ".context/conventions.md",
    ".context/security-checklist.md",
    ".context/active/[feature].md"
  ]
})
```

### Scale-Adaptive Team Sizes

Not every feature requires the full 5-agent team. Scale based on complexity:

| Feature Size | Agents Used | Phases Run |
|-------------|-------------|-----------|
| **Patch / Bug Fix** | Developer + QA | 5, 6, 7 |
| **Small Feature** | Orchestrator, Developer, QA | 0, 4, 5, 6, 7, 9 |
| **Medium Feature** | Orchestrator, Researcher, Developer, QA | 0, 1, 2, 3, 4, 5, 6, 7, 9 |
| **Major Feature / Integration** | Full 5-agent team | 0, 1, 2, 3, 4, 5, 6, 7, 8, 9 |

---

## Autonomous Development Loop

For larger features spanning multiple development sessions, the **Autonomous Development Loop** (based on the Ralph Wiggum pattern from `docs/proposals/RALPH-WIGGUM-TASK-ORCHESTRATION-IMPLEMENTATION.md`) provides continuous execution with intelligent exit detection.

### Loop Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                  Autonomous Development Loop                  │
│                                                               │
│  Phase 0: Initialize Session                                  │
│     │ Load .context/active/[feature].md + context files      │
│     ▼                                                         │
│  Phase 4: Load Task Plan (get_task_plan)                      │
│     │ Identify next pending story                             │
│     ▼                                                         │
│  Phase 5: Execute Story (manage_autonomous_session)           │
│     │ delegate_to_agent → Developer assistant                 │
│     ▼                                                         │
│  Phase 6: Validate Story (check_workflow_health)              │
│     │ All acceptance criteria met?                            │
│     ├─ NO  → Fix issues → Re-execute story (max 3 retries)   │
│     └─ YES → update_task_plan (mark complete)                 │
│               │                                               │
│               ▼                                               │
│  All stories complete? (detect_completion_indicators)         │
│     ├─ NO  → Back to "Load Task Plan" (next story)            │
│     └─ YES + EXIT_SIGNAL emitted                              │
│               │                                               │
│               ▼                                               │
│  Phase 7: Release Gate                                        │
│  Phase 9: Retrospective (batch_manage_memory)                 │
└──────────────────────────────────────────────────────────────┘
```

### NV oOS Tool Chain for the Loop

| Step | Tool | Purpose |
|------|------|---------|
| Initialize | `manage_autonomous_session` (start) | Create session with context files + token budget |
| Load story list | `get_task_plan` | Retrieve stories with pending/complete status |
| Execute story | `delegate_to_agent` | Run story in isolated sub-agent context |
| Validate | `check_workflow_health` | Verify story outputs meet acceptance criteria |
| Update progress | `update_task_plan` | Mark story complete, capture implementation notes |
| Check completion | `detect_completion_indicators` | Semantic check: all stories done + EXIT_SIGNAL present |
| Loop control | `check_exit_conditions` | Enforce circuit breaker; prevent runaway loops |
| Exit | `manage_autonomous_session` (complete) | Close session, harvest context for Phase 9 |

### Built-in Safeguards

| Safeguard | Mechanism | Configuration |
|-----------|-----------|--------------|
| **Max iterations** | Agentic loop limit | 5-15 iterations per session (configurable per assistant) |
| **Token budget** | Token tracking | Per-session budget enforced by `manage_autonomous_session` |
| **Circuit breaker** | Error detection | `check_exit_conditions` monitors for unrecoverable states |
| **Session timeout** | Cron-based expiry | 24-hour session expiration with context preservation |
| **Dual-exit condition** | Completion + EXIT_SIGNAL | Both required; prevents premature loop termination |
| **Retry cap** | Story-level retry count | Maximum 3 re-executions per story before escalation |

### Loop Session Template

```yaml
# .context/templates/ralph-loop-session.md
session:
  id: "[feature-slug]-loop-[iteration]"
  context_files:
    - .context/conventions.md
    - .context/security-checklist.md
    - .context/tool-registry.md
    - .context/active/[feature].md
  token_budget: 10000
  max_iterations: 15
  exit_conditions:
    - type: completion_indicator
      check: "all stories in task_plan marked complete"
    - type: exit_signal
      value: "EXIT_SIGNAL: FEATURE_COMPLETE"
  on_exit:
    - update task_plan status to "complete"
    - emit Phase 9 (Retrospective) trigger
    - archive .context/active/[feature].md to .context/archive/
```

---

## Quality Gates and Checklists

### Pre-Implementation Gate (Before Writing Code)

- [ ] Project Brief approved
- [ ] PRD complete with acceptance criteria
- [ ] Architecture spec reviewed
- [ ] Stories broken down and sequenced
- [ ] Security model defined
- [ ] Test strategy defined

### Per-Story Gate (Before Merging)

- [ ] All acceptance criteria met
- [ ] PHPUnit tests pass
- [ ] PHPCS clean (no WPCS violations)
- [ ] ESLint clean (if JS changes)
- [ ] CodeQL scan clean
- [ ] PHPDoc blocks on new code
- [ ] Security checklist verified:
  - [ ] Input sanitized (`sanitize_text_field()`, `absint()`, etc.)
  - [ ] Output escaped (`esc_html()`, `esc_url()`, etc.)
  - [ ] Capabilities checked before privileged operations
  - [ ] Nonces verified for state-changing requests
- [ ] Documentation updated if needed
- [ ] Base vs Pro gating correct

### Release Gate (Before Shipping)

- [ ] All stories in milestone complete
- [ ] Full test suite passes
- [ ] Security audit complete
- [ ] Documentation review complete
- [ ] Backward compatibility verified
- [ ] Version numbers updated in all locations
- [ ] CHANGELOG.md updated
- [ ] Regression testing on key flows

---

## Expected Benefits

### Quantitative Improvements

| Metric | Current (Estimated) | Target with GSD+BMAD | Improvement |
|--------|--------------------|-----------------------|-------------|
| Feature cycle time | 2-4 weeks | 1-2 weeks | 40-60% faster |
| Context setup time per session | 15-30 min | 2-5 min | 80% reduction |
| Documentation drift | Some sections outdated | Specs always current | Near-zero drift |
| Defect rate (post-merge) | Variable | Reduced via checklists | 30-50% reduction |
| Security findings (post-release) | Occasional | Rare (caught in gates) | Significant reduction |
| AI agent utilization | Ad-hoc assistant selection | Mapped roles via default assistants | Consistent, specialized outputs |
| Autonomous execution overhead | Manual story-by-story handoffs | Ralph loop + task_plan tools | 60-80% reduction in manual handoffs |
| Post-release visibility | Manual log inspection | Pro workflow health monitoring | Real-time health metrics |

### Qualitative Improvements

1. **Predictability** — Structured phases with clear artifacts reduce surprises
2. **Traceability** — Every code change traces back to a story, PRD, and architecture decision
3. **Onboarding** — New contributors follow the same templates and checklists
4. **AI Effectiveness** — Context engineering makes AI agents more reliable and productive
5. **Security Confidence** — Structured gates ensure security review is never skipped
6. **Documentation Quality** — Spec-driven approach keeps docs as first-class artifacts
7. **Tool Utilization** — Pro toolkit tools replace manual, error-prone steps in each phase
8. **Continuous Learning** — Phase 9 retrospective feeds back into context files, compounding quality over time

---

## Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Over-process** — Too much ceremony for small changes | Medium | Medium | Scale-adaptive: use full workflow for major features, lightweight for patches (see Multi-Agent Team table) |
| **Template fatigue** — Contributors avoid templates | Medium | Low | Keep templates concise; automate where possible (Phase 6 automation) |
| **Context file staleness** — `.context/` files become outdated | Medium | Medium | Phase 9 retrospective review; keep files under 500 lines; archive rather than delete |
| **Learning curve** — Team needs to learn new workflow | Low | Low | Pilot on one feature first; document lessons learned in Phase 9 |
| **Tool incompatibility** — GSD/BMAD tools don't integrate with existing CI/CD | Low | Medium | Use only the methodology, not specific tooling; adapt to existing workflows |
| **Loop runaway** — Autonomous loop consumes excessive tokens | Low | Medium | Built-in safeguards: max_iterations, token_budget, dual-exit conditions, circuit breaker |
| **Agent role drift** — Default assistants diverge from BMAD personas over time | Low | Low | Review `.bmad/agents/*.yaml` during Phase 4 (Optimization) retrospectives |

---

## References

### GSD (Get Shit Done)
- [GSD Official Repository](https://github.com/gsd-build/get-shit-done) — Meta-prompting framework for AI-assisted development
- [GSD Website](https://gsd.build/) — Overview and getting started
- [Mastering GSD](https://dev.to/arkacoc13/mastering-get-shit-done-integrating-meta-prompting-context-engineering-and-spec-driven-4dnl) — Technical deep-dive
- [GSD Beginner's Guide](https://dev.to/alikazmidev/the-complete-beginners-guide-to-gsd-get-shit-done-framework-for-claude-code-24h0) — Step-by-step guide

### BMAD (Breakthrough Method for Agile AI-Driven Development)
- [BMAD Method Documentation](https://docs.bmad-method.org/) — Official documentation
- [BMAD GitHub Repository](https://github.com/bmad-code-org/BMAD-METHOD) — Open-source framework
- [BMAD Standard Workflow](https://dev.to/jacktt/bmad-standard-workflow-2kma) — Workflow guide
- [BMAD Implementation Guide](https://buildmode.dev/blog/mastering-bmad-method-2025/) — Practical implementation
- [Agent-as-Code: BMAD Method](https://dev.to/vishalmysore/agent-as-code-bmad-method-4no9) — Agent definitions
- [Applied BMAD](https://bennycheung.github.io/bmad-reclaiming-control-in-ai-dev) — Real-world case study

### Combined Approaches
- [Goodbye Vibe Coding: Spec-Driven Development](https://www.pasqualepillitteri.it/en/news/158/framework-ai-spec-driven-development-guide-bmad-gsd-ralph-loop) — GSD + BMAD integration
- [Spec-Driven Development Frameworks](https://www.vibesparking.com/en/blog/ai/2026-01-25-spec-driven-development-frameworks-bmad-gsd-ralph/) — Comparative analysis

### NV oOS Internal References
- `docs/proposals/RALPH-WIGGUM-TASK-ORCHESTRATION-IMPLEMENTATION.md` — Ralph Wiggum autonomous loop pattern and NV oOS tool implementations
- `docs/MULTI_AGENT_ORCHESTRATION_IMPLEMENTATION.md` — 6 default assistants and multi-agent team patterns
- `docs/PRO_DASHBOARD_MONITORING.md` — Post-release monitoring with Pro Dashboard
- `docs/proposals/TOOLKIT_ENHANCEMENT_PROPOSAL.md` — Industry best practices for tool organization (UiPath, Microsoft Azure, LangChain)
- `docs/RELEASE_PROCESS.md` — NV oOS release workflow for Phase 7
- `includes/class-wp-mcp-ai-default-assistants.php` — Pre-configured assistants for BMAD role mapping
- `addons/pro/includes/tools/architect-agent/` — Architect Agent toolkit tools for Phases 1 and 3

---

## Next Steps

1. ~~**Review this proposal** — Gather team feedback on the hybrid approach and the 10-phase workflow~~ ✅
2. **Select a pilot feature** — Choose a medium-complexity feature from the roadmap
3. ~~**Create foundation files** — `.bmad/` agent definitions, `.context/` base files, `.bmad/teams/feature-development.yaml`~~ ✅ Done
4. **Execute pilot (full cycle)** — Run one complete Phase 0–9 cycle using NV oOS default assistants and task planning tools
5. **Retrospective** — Document results via Phase 9 context harvest; update agent definitions and context files
6. **Multi-agent infrastructure** — Configure BMAD role mapping for the 6 default assistants (Implementation Phase 5)
7. **Automation & metrics** — Set up `create_task_plan` automation, post-deploy `check_workflow_health`, and dashboard monitoring (Implementation Phase 6)
8. **Adopt or adapt** — Based on pilot results, decide on broader adoption and refine team compositions

---

*This proposal is a living document. Update it as the team gains experience with the GSD + BMAD workflow.*
