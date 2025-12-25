# Label Strategy

**Repository:** nvdigitalsolutions/mcp-ai-wpoos  
**Version:** 1.0  
**Last Updated:** December 24, 2025

---

## Overview

This document defines the complete label taxonomy for issue and pull request management in the WP oOS repository. Consistent labeling enables efficient filtering, searching, prioritization, and automation.

## Label Categories

### 1. Type Labels (What kind of work?)

| Label | Color | Description | Auto-Applied |
|-------|-------|-------------|--------------|
| `type: bug` | `#d73a4a` | Something isn't working correctly | Bug template |
| `type: feature` | `#a2eeef` | New feature or functionality request | Feature template |
| `type: enhancement` | `#84b6eb` | Improvement to existing feature | Feature template |
| `type: documentation` | `#0075ca` | Documentation additions or updates | Docs template |
| `type: refactoring` | `#fbca04` | Code refactoring, no behavior change | Manual |
| `type: security` | `#b60205` | Security vulnerability or concern | Manual |
| `type: performance` | `#d4c5f9` | Performance improvement | Manual |
| `type: testing` | `#c5def5` | Testing infrastructure or coverage | Manual |

**Usage Rules:**
- Every issue MUST have exactly one type label
- Applied automatically by issue template when possible
- Changed manually if type shifts during investigation

---

### 2. Priority Labels (How urgent?)

| Label | Color | Description | SLA |
|-------|-------|-------------|-----|
| `priority: critical` | `#b60205` | Security, data loss, site down | 24 hours |
| `priority: high` | `#d93f0b` | Major bugs, important features | 1 week |
| `priority: medium` | `#fbca04` | Standard bugs, nice-to-haves | 1 month |
| `priority: low` | `#0e8a16` | Minor issues, future enhancements | No deadline |

**Assignment Rules:**
- `critical`: Security vulnerabilities, data loss bugs, breaking changes in production
- `high`: Major bugs affecting many users, high-value features in roadmap
- `medium`: Standard bugs with workarounds, planned features
- `low`: UI polish, documentation typos, low-impact enhancements

**Default:** If no priority label, assume `priority: medium`

---

### 3. Area Labels (Which part of codebase?)

| Label | Color | Description |
|-------|-------|-------------|
| `area: core` | `#5319e7` | Core plugin functionality |
| `area: pro` | `#7057ff` | Pro addon features |
| `area: tools` | `#8b9eb0` | AI tools implementation |
| `area: admin-ui` | `#c2e0c6` | Admin interface and settings |
| `area: frontend` | `#bfd4f2` | Frontend chat UI and widgets |
| `area: api` | `#0366d6` | REST API and endpoints |
| `area: testing` | `#d4c5f9` | Test infrastructure |
| `area: ci-cd` | `#ededed` | CI/CD pipelines and workflows |
| `area: docs` | `#fef2c0` | Documentation files |
| `area: integrations` | `#84b6eb` | Third-party plugin integrations |
| `area: security` | `#b60205` | Security features and monitoring |
| `area: performance` | `#d4c5f9` | Performance optimization |

**Usage Rules:**
- Issues can have multiple area labels if they span components
- Helps identify which team members to involve
- Used for generating component-specific reports

---

### 4. Status Labels (What's happening?)

| Label | Color | Description |
|-------|-------|-------------|
| `status: needs-triage` | `#ffffff` | Needs initial review and labeling |
| `status: investigating` | `#c5def5` | Under investigation |
| `status: in-progress` | `#1d76db` | Work actively in progress |
| `status: blocked` | `#d93f0b` | Blocked by external dependency |
| `status: review-needed` | `#fbca04` | PR ready for review |
| `status: changes-requested` | `#e99695` | PR has requested changes |
| `status: needs-info` | `#d876e3` | Waiting for more information |
| `status: wontfix` | `#ffffff` | Will not be addressed |
| `status: duplicate` | `#cfd3d7` | Duplicate of another issue |

**Lifecycle:**
1. Issue opened → Auto-labeled `status: needs-triage`
2. Reviewed → Change to appropriate status
3. Work starts → `status: in-progress`
4. PR opened → `status: review-needed`
5. Merged → Remove status labels, issue auto-closes

---

### 5. Effort Labels (How much work?)

| Label | Color | Description | Typical Time |
|-------|-------|-------------|--------------|
| `effort: xs` | `#c2e0c6` | Extra small task | < 2 hours |
| `effort: small` | `#bfd4f2` | Small task | 2-4 hours |
| `effort: medium` | `#fbca04` | Medium task | 4-16 hours (1-2 days) |
| `effort: large` | `#d93f0b` | Large task | 16-40 hours (1 week) |
| `effort: x-large` | `#b60205` | Extra large task | > 40 hours (> 1 week) |

**Usage:**
- Applied during triage or sprint planning
- Helps with capacity planning
- Used for "good first issue" identification

---

### 6. AI Provider Labels (Which AI service?)

| Label | Color | Description |
|-------|-------|-------------|
| `ai: openai` | `#00a67e` | OpenAI GPT models |
| `ai: gemini` | `#4285f4` | Google Gemini |
| `ai: ollama` | `#7f7f7f` | Ollama local AI |
| `ai: anthropic` | `#d97706` | Anthropic Claude |
| `ai: huggingface` | `#ff9d00` | Hugging Face models |

**Usage:**
- Applied when issue is specific to one AI provider
- Helps track provider-specific bugs
- Used for provider feature requests

---

### 7. Special Labels

| Label | Color | Description |
|-------|-------|-------------|
| `good first issue` | `#7057ff` | Good for first-time contributors |
| `help wanted` | `#008672` | Extra attention needed from community |
| `dependencies` | `#0366d6` | Dependency update (Dependabot) |
| `breaking-change` | `#d73a4a` | Contains breaking changes |
| `question` | `#d876e3` | Question needing clarification |
| `invalid` | `#e4e669` | Invalid issue or spam |
| `wip` | `#fbca04` | Work in progress, do not merge |

---

## Label Application Workflow

### Automatic Labeling

**Issue Templates:**
```yaml
# Bug Report → Automatic labels
type: bug
status: needs-triage

# Feature Request → Automatic labels
type: feature
status: needs-triage

# Documentation → Automatic labels
type: documentation
status: needs-triage
```

**GitHub Actions:**
- `auto-label.yml` - Auto-labels based on file paths
- `size-labeler.yml` - Auto-labels PR size based on lines changed
- `project-automation.yml` - Moves cards based on labels

### Manual Labeling Process

**Triage (Required):**
1. Verify/correct `type:` label
2. Add `priority:` label
3. Add relevant `area:` labels
4. Assign to milestone (if prioritized)
5. Add `effort:` estimate (optional)
6. Remove `status: needs-triage`

**During Development:**
- Update `status:` as work progresses
- Add `ai:` provider if relevant
- Add `breaking-change` if applicable

**Pull Requests:**
- Size label added automatically
- Add relevant `area:` labels
- Ensure linked issue has all labels

---

## Label Search Queries

### Common Filters

**Bugs by Priority:**
```
is:issue is:open label:"type: bug" label:"priority: high"
```

**Good First Issues:**
```
is:issue is:open label:"good first issue" label:"effort: small"
```

**Needs Triage:**
```
is:issue is:open label:"status: needs-triage"
```

**OpenAI Related:**
```
is:issue label:"ai: openai"
```

**Documentation Needed:**
```
is:issue is:open label:"type: documentation" label:"area: docs"
```

**PRs Ready for Review:**
```
is:pr is:open label:"status: review-needed"
```

---

## Label Management

### Creating New Labels

Before creating a new label:
1. Check if existing label can be used
2. Discuss with team if category needs expansion
3. Follow naming convention: `category: name`
4. Choose appropriate color for category
5. Update this document

### Deprecating Labels

To deprecate a label:
1. Create replacement label (if needed)
2. Update all issues using old label
3. Delete old label
4. Update this document
5. Announce in team channels

### Label Maintenance Schedule

**Weekly:**
- Review `status: needs-triage` issues
- Ensure all open issues have priority
- Update stale status labels

**Monthly:**
- Audit label usage statistics
- Remove unused labels
- Update label descriptions if needed

---

## Label Best Practices

### DO ✅

- Apply labels during triage
- Keep labels up-to-date as issues evolve
- Use multiple area labels if issue spans components
- Update status labels regularly
- Add effort labels during sprint planning

### DON'T ❌

- Don't use status labels on closed issues
- Don't create one-off labels for single issues
- Don't change type labels without discussion
- Don't use labels instead of milestones
- Don't over-label (max 5-6 labels per issue)

---

## Integration with Projects

Labels automatically determine project board columns:

**Roadmap Project:**
- `priority: high` + `type: feature` → Planned column
- `status: in-progress` → In Progress column
- Closed → Done column

**Bug Triage Project:**
- `status: needs-triage` → Triage Needed column
- `priority: critical` → Urgent column
- `status: investigating` → Under Investigation column

**Sprint Board:**
- `status: in-progress` → In Progress column
- `status: review-needed` → Review column
- Closed → Done column

---

## Label Statistics and Reports

### Monthly Label Report

Track these metrics:
- Total issues by type (bug vs feature vs enhancement)
- Priority distribution (critical/high/medium/low)
- Average time in each status
- Effort accuracy (estimated vs actual)
- Most active areas
- Label coverage (% of issues properly labeled)

### Report Generation

```bash
# Count issues by label
gh issue list --label "type: bug" --state all --json number | jq 'length'

# List untriaged issues
gh issue list --label "status: needs-triage" --state open

# Priority distribution
gh issue list --label "priority: critical" --state open
```

---

## Appendix: Complete Label List

### Quick Reference Table

| Category | Count | Labels |
|----------|-------|--------|
| Type | 8 | bug, feature, enhancement, documentation, refactoring, security, performance, testing |
| Priority | 4 | critical, high, medium, low |
| Area | 12 | core, pro, tools, admin-ui, frontend, api, testing, ci-cd, docs, integrations, security, performance |
| Status | 9 | needs-triage, investigating, in-progress, blocked, review-needed, changes-requested, needs-info, wontfix, duplicate |
| Effort | 5 | xs, small, medium, large, x-large |
| AI Provider | 5 | openai, gemini, ollama, anthropic, huggingface |
| Special | 7 | good first issue, help wanted, dependencies, breaking-change, question, invalid, wip |

**Total Labels:** 50

---

## Change Log

| Date | Version | Changes |
|------|---------|---------|
| 2025-12-24 | 1.0 | Initial label strategy document |

---

## See Also

- [PROJECT_MANAGEMENT_GAP_ANALYSIS.md](PROJECT_MANAGEMENT_GAP_ANALYSIS.md) - Complete PM gap analysis
- [MILESTONE_STRATEGY.md](MILESTONE_STRATEGY.md) - Milestone management
- [ROADMAP.md](ROADMAP.md) - Product roadmap
- [CONTRIBUTING.md](../CONTRIBUTING.md) - Contributing guidelines
