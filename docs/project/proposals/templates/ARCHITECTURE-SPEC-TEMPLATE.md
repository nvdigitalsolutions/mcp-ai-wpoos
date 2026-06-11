# [Feature Name] — Architecture Specification

**Date:** YYYY-MM-DD
**Phase:** 3 — Architecture
**Status:** Draft / In Review / Approved
**Author:** [Name / Agent: nv-oos-architect]
**PRD Reference:** `docs/proposals/[FEATURE]-PRD.md`
**Version:** 1.0

---

## System Overview

[High-level description of the feature and how it fits within NV oOS. 3-5 sentences maximum.]

---

## Component Diagram

```
┌─────────────────────────────────────────────────────┐
│                    NV oOS Core                       │
│                                                      │
│  ┌──────────────┐    ┌──────────────────────────┐   │
│  │  REST API    │───►│  WP_MCP_AI_{Feature}     │   │
│  │  /mcp-ai/v1/ │    │  (Main Coordinator)      │   │
│  └──────────────┘    └──────────┬───────────────┘   │
│                                  │                   │
│            ┌─────────────────────┤                   │
│            ▼                     ▼                   │
│  ┌──────────────────┐  ┌──────────────────────┐     │
│  │  WP_MCP_AI_Tool_ │  │  WP_MCP_AI_{Helper}  │     │
│  │  {ToolName}      │  │  (Data layer)        │     │
│  └──────────────────┘  └──────────────────────┘     │
└─────────────────────────────────────────────────────┘
```

---

## Class Hierarchy

| Class | File | Extends | Responsibility |
|-------|------|---------|---------------|
| `WP_MCP_AI_{Feature}` | `includes/class-wp-mcp-ai-{feature}.php` | — | [Description] |
| `WP_MCP_AI_Tool_{Name}` | `includes/tools/class-wp-mcp-ai-tool-{name}.php` | `WP_MCP_AI_Tool_Base` | [Description] |
| `WP_MCP_AI_{Feature}_REST` | `includes/rest/class-wp-mcp-ai-{feature}-rest.php` | `WP_REST_Controller` | [Description] |

---

## Data Model

### Custom Post Type (if applicable)

**CPT Slug:** `mcp_ai_{post_type}`

| Meta Key | Type | Description |
|----------|------|-------------|
| `_{meta_key}` | string | [Description] |
| `_{another_key}` | integer | [Description] |

### Custom Content Type / JetEngine CCT (if applicable)

**CCT Slug:** `{cct_name}`
**Table:** `wp_jet_cct_{cct_name}`

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `field_name` | VARCHAR(255) | [Description] |
| `created_at` | DATETIME | Creation timestamp |

> **Migration:** If adding columns to an existing CCT, use the versioned migration pattern
> (see `addons/pro/includes/class-wp-mcp-ai-jetengine-vitals-log-cct.php` for reference).

### WordPress Options

| Option Key | Type | Default | Description |
|------------|------|---------|-------------|
| `wp_mcp_ai_{key}` | array | `array()` | [Description] |
| `wp_mcp_ai_{another_key}` | string | `''` | [Description] |

---

## Hook & Filter Registry

| Hook / Filter | Type | When Fired | Parameters |
|--------------|------|-----------|-----------|
| `wp_mcp_ai_{feature}_before_{action}` | action | Before [action] | `$data` |
| `wp_mcp_ai_{feature}_{value}` | filter | During [action] | `$value, $context` |
| `wp_mcp_ai_{feature}_after_{action}` | action | After [action] | `$result, $data` |

---

## REST API Design

### `GET /mcp-ai/v1/{resource}`

**Purpose:** [Description]
**Permission:** `current_user_can('read')`

**Query Parameters:**
| Parameter | Type | Required | Description |
|-----------|------|---------|-------------|
| `page` | integer | No | Page number (default: 1) |
| `per_page` | integer | No | Results per page (default: 20, max: 100) |

**Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Example"
    }
  ]
}
```

### `POST /mcp-ai/v1/{resource}`

**Purpose:** [Description]
**Permission:** `current_user_can('edit_posts')`

**Request Body:**
```json
{
  "name": "string (required, max 200 chars)",
  "type": "string (enum: typeA|typeB)"
}
```

**Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "name": "Example"
  }
}
```

---

## Security Model

### Authentication
- [Method: WordPress Nonce / Bearer Token / Guest Token]
- Nonce action: `wp_mcp_ai_{feature}_{action}`

### Authorization

| Operation | Required Capability |
|-----------|-------------------|
| Read | `read` |
| Create / Update | `edit_posts` |
| Delete | `delete_posts` |
| Manage settings | `manage_options` |

### Input Sanitization

| Parameter | Function |
|-----------|---------|
| Text strings | `sanitize_text_field()` |
| Integers | `absint()` |
| HTML content | `wp_kses_post()` |
| URLs | `esc_url_raw()` |
| Slugs/keys | `sanitize_key()` |

### Output Escaping

| Context | Function |
|---------|---------|
| HTML text | `esc_html()` |
| HTML attributes | `esc_attr()` |
| URLs | `esc_url()` |
| JSON responses | `wp_json_encode()` |

### Data Storage Security
- API credentials: [Encrypted / Plain text — never plain text for secrets]
- PII data: [How it is protected]

---

## Integration Points

| System | Integration Type | Description |
|--------|----------------|-------------|
| WordPress Core | Hooks/filters | [Description] |
| NV oOS Tool Registry | Registration | [Description] |
| JetEngine (optional) | CCT | [Description — if applicable] |
| [External Service] | REST API | [Description — if applicable] |

---

## File Map

> Complete list of all files to create or modify for this feature.

### Files to Create

| File Path | Description |
|-----------|-------------|
| `includes/tools/class-wp-mcp-ai-tool-{name}.php` | Tool implementation |
| `includes/rest/class-wp-mcp-ai-{feature}-rest.php` | REST controller |
| `tests/test-{feature}.php` | PHPUnit tests |
| `docs/[FEATURE-DOC].md` | Feature documentation |

### Files to Modify

| File Path | Change Description |
|-----------|-------------------|
| `includes/tools-init.php` | Register new tool |
| `includes/class-wp-mcp-ai-rest.php` | Register new REST routes |
| `docs/tool-reference.md` | Document new tools |
| `CHANGELOG.md` | Document feature |

---

## Backward Compatibility

- [ ] New CPT/CCT fields are backward-compatible (nullable or have defaults)
- [ ] Existing hooks/filters are not changed (additive only)
- [ ] REST endpoint URLs follow existing patterns
- [ ] No changes to existing option key names
- [ ] Migration plan documented for any schema changes

---

## Architecture Review Checklist

- [ ] All components follow `WP_MCP_AI_` naming convention
- [ ] Security model covers authentication, authorization, sanitization, and escaping
- [ ] Data model uses appropriate WordPress storage (CPT/CCT/Options)
- [ ] REST endpoints follow NV oOS patterns with `permission_callback`
- [ ] Hooks/filters defined to enable extensibility
- [ ] File Map is complete and follows project structure
- [ ] Backward compatibility maintained (or migration plan documented)
- [ ] Base vs Pro version gating defined
- [ ] No direct `shell_exec()` — use `proc_open()` for external processes
- [ ] ABSPATH guard required on all new PHP files

---

*Next step: Scrum Master (nv-oos-scrum-master) breaks this spec into atomic stories in Phase 4.*
