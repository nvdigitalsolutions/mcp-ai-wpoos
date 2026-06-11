# [Feature Name] — Product Requirements Document

**Date:** YYYY-MM-DD
**Phase:** 2 — Planning
**Status:** Draft / In Review / Approved
**Author:** [Name / Agent: nv-oos-product-manager]
**Brief Reference:** `docs/proposals/[FEATURE]-PROJECT-BRIEF.md`
**Version:** 1.0

---

## Goals & Success Metrics

| Goal | Metric | Target |
|------|--------|--------|
| [Goal 1] | [Measurable metric] | [Target value] |
| [Goal 2] | [Measurable metric] | [Target value] |

---

## Functional Requirements

### FR-1: [Requirement Name]
- **Description:** [What the system must do]
- **Priority:** Must Have / Should Have / Nice to Have
- **Acceptance Criteria:**
  - [ ] [Criterion 1 — testable, specific]
  - [ ] [Criterion 2]
  - [ ] [Criterion 3]

### FR-2: [Requirement Name]
- **Description:** [What the system must do]
- **Priority:** Must Have / Should Have / Nice to Have
- **Acceptance Criteria:**
  - [ ] [Criterion 1]

---

## Non-Functional Requirements

### Performance
- Response time: [Target, e.g., < 2 seconds for tool execution]
- Throughput: [Target if applicable]

### Security
- Authentication: [Method — WP Nonce / Bearer Token / Guest Token]
- Authorization: [Required capabilities for each operation]
- Data handling: [How sensitive data is stored and transmitted]
- Input validation: [Sanitization strategy]

### Accessibility
- WCAG compliance level: [2.1 AA / 2.1 A / N/A for API-only features]

### Compatibility
- PHP versions: 7.4+
- WordPress versions: 6.0+
- Required plugins: [List or "None"]
- Optional plugins: [List or "None"]

---

## Tool Definitions

> For each new NV oOS tool, define the full specification:

| Tool Slug | Class Name | Description | Required Capability | Base/Pro |
|-----------|-----------|-------------|-------------------|---------|
| `example_tool` | `WP_MCP_AI_Tool_Example` | [Description for LLM] | `edit_posts` | Base |

### Tool: `[slug]`

**Class:** `WP_MCP_AI_Tool_{Name}`
**File:** `includes/tools/class-wp-mcp-ai-tool-{name}.php`
**Required Capability:** `[capability]`
**Version:** Base / Pro

**Actions:**
| Action | Description | Required Parameters | Optional Parameters |
|--------|-------------|-------------------|-------------------|
| `create` | [Description] | `name` (string) | `description` (string) |
| `list` | [Description] | — | `limit` (integer) |
| `delete` | [Description] | `id` (integer) | — |

---

## REST API Endpoints

> For each new REST endpoint, define the full specification:

| Method | Route | Permission Callback | Description |
|--------|-------|-------------------|-------------|
| GET | `/mcp-ai/v1/[resource]` | `current_user_can('read')` | [Description] |
| POST | `/mcp-ai/v1/[resource]` | `current_user_can('edit_posts')` | [Description] |
| DELETE | `/mcp-ai/v1/[resource]/(?P<id>[\d]+)` | `current_user_can('delete_posts')` | [Description] |

### Endpoint: `POST /mcp-ai/v1/[resource]`

**Request Body:**
```json
{
  "name": "string (required)",
  "type": "string (enum: create|list|delete)"
}
```

**Response:**
```json
{
  "success": true,
  "data": { ... }
}
```

**Error Responses:**
- `400 Bad Request` — Invalid parameters
- `401 Unauthorized` — Not authenticated
- `403 Forbidden` — Insufficient capability
- `404 Not Found` — Resource not found

---

## Epics & Stories

### Epic 1: [Name]
*Goal: [What this epic achieves]*

- **Story 1.1:** As a [role], I want to [action], so that [value].
  - Dependencies: None
  - Estimated complexity: Small / Medium / Large

- **Story 1.2:** As a [role], I want to [action], so that [value].
  - Dependencies: Story 1.1
  - Estimated complexity: Small / Medium / Large

### Epic 2: [Name]
*Goal: [What this epic achieves]*

- **Story 2.1:** As a [role], I want to [action], so that [value].

---

## Story Sequencing

```
Story 1.1 (no deps)
    ↓
Story 1.2 (requires 1.1)
    ↓
Story 2.1 (can run in parallel with 1.2)
    ↓
Story 2.2 (requires 1.2 + 2.1)
```

---

## PRD Validation Checklist

- [ ] All goals have measurable success metrics
- [ ] All functional requirements have acceptance criteria
- [ ] Security requirements documented (auth, authz, sanitization, escaping)
- [ ] All tool definitions follow NV oOS patterns (slug, capability, parameters)
- [ ] All REST endpoints have `permission_callback` defined
- [ ] Stories are independent and testable (where possible)
- [ ] Story dependencies identified and sequencing documented
- [ ] Base vs Pro gating specified for every tool and endpoint
- [ ] PHP/WP version compatibility requirements stated
- [ ] Non-functional requirements (performance, accessibility) stated

---

*Next step: Architect (nv-oos-architect) creates the Architecture Specification using `docs/proposals/templates/ARCHITECTURE-SPEC-TEMPLATE.md`.*
