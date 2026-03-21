# GSD + BMAD Methodology Proposal for NV oOS

**Date:** March 2026
**Status:** Proposal
**Author:** NV Digital Solutions
**Applies To:** NV oOS Plugin Development Workflow

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [What is GSD (Get Shit Done)?](#what-is-gsd-get-shit-done)
3. [What is BMAD (Breakthrough Method for Agile AI-Driven Development)?](#what-is-bmad)
4. [Why These Methods Matter for NV oOS](#why-these-methods-matter-for-nv-oos)
5. [Current State Assessment](#current-state-assessment)
6. [Proposed Hybrid Workflow: GSD × BMAD for NV oOS](#proposed-hybrid-workflow)
7. [Agent Role Mapping](#agent-role-mapping)
8. [Implementation Phases](#implementation-phases)
9. [Spec-Driven Development Templates](#spec-driven-development-templates)
10. [Context Engineering Strategy](#context-engineering-strategy)
11. [Tool System Integration](#tool-system-integration)
12. [Quality Gates and Checklists](#quality-gates-and-checklists)
13. [Expected Benefits](#expected-benefits)
14. [Risks and Mitigations](#risks-and-mitigations)
15. [References](#references)

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
- [GSD Official Repository](https://github.com/gsd-build/get-shit-done)
- [GSD Overview](https://gsd.build/)
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

The hybrid GSD × BMAD workflow for NV oOS combines BMAD's structured planning with GSD's execution efficiency:

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

- [ ] Create `.bmad/` directory with agent role definitions
- [ ] Create `.context/` directory for GSD context preservation files
- [ ] Create standardized proposal templates (Brief, PRD, Architecture)
- [ ] Document the hybrid workflow in this proposal
- [ ] Add phase-completion checklists to CONTRIBUTING.md

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

- [ ] Add checklist validation to PR template
- [ ] Create GSD context files for major subsystems (tool registry, REST API, chat UI)
- [ ] Train on agent role definitions for consistent AI prompting
- [ ] Establish context preservation for cross-session development
- [ ] Measure: cycle time, defect rate, documentation drift

### Phase 4: Optimization (Ongoing)

**Goal:** Refine based on experience and evolving best practices.

- [ ] Update agent definitions based on lessons learned
- [ ] Expand context files as new subsystems are developed
- [ ] Community feedback on template usability
- [ ] Evaluate automated spec validation tools
- [ ] Track metrics: shipping speed, code quality, test coverage

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

### Qualitative Improvements

1. **Predictability** — Structured phases with clear artifacts reduce surprises
2. **Traceability** — Every code change traces back to a story, PRD, and architecture decision
3. **Onboarding** — New contributors follow the same templates and checklists
4. **AI Effectiveness** — Context engineering makes AI agents more reliable and productive
5. **Security Confidence** — Structured gates ensure security review is never skipped
6. **Documentation Quality** — Spec-driven approach keeps docs as first-class artifacts

---

## Risks and Mitigations

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Over-process** — Too much ceremony for small changes | Medium | Medium | Scale-adaptive: use full workflow for major features, lightweight for patches |
| **Template fatigue** — Contributors avoid templates | Medium | Low | Keep templates concise; automate where possible |
| **Context file staleness** — `.context/` files become outdated | Medium | Medium | Review context files during retrospectives; keep them short |
| **Learning curve** — Team needs to learn new workflow | Low | Low | Pilot on one feature first; document lessons learned |
| **Tool incompatibility** — GSD/BMAD tools don't integrate with existing CI/CD | Low | Medium | Use only the methodology, not specific tooling; adapt to existing workflows |

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

---

## Next Steps

1. **Review this proposal** — Gather team feedback on the hybrid approach
2. **Select a pilot feature** — Choose a medium-complexity feature from the roadmap
3. **Create foundation files** — `.bmad/` agent definitions, `.context/` base files
4. **Execute pilot** — Run one full cycle through the GSD × BMAD workflow
5. **Retrospective** — Document results, adjust templates, and refine workflow
6. **Adopt or adapt** — Based on pilot results, decide on broader adoption

---

*This proposal is a living document. Update it as the team gains experience with the GSD + BMAD workflow.*
