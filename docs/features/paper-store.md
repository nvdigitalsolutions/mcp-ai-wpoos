# Paper Store

**Version:** 1.1.52+
**Category:** Base feature
**Classes:** Paper Store tools (8), `WP_MCP_AI_Paper_Store_REST` (697 lines), `WP_MCP_AI_Paper_Store_Remote` trait (96 lines), `WP_MCP_AI_Tool_List_MCP_Tools` (234 lines)

## Overview

The Paper Store is a file-based JSON document store that provides persistent, queryable storage for AI agents. It is organized into named **collections** (e.g., `knowledge`, `prompts`, `workflows`) containing individual **records** identified by unique slugs. Records carry structured metadata (title, description, tags, status, body, meta) and are stored as JSON files within the plugin's data directory.

The Paper Store is available to AI assistants via 8 MCP tools covering full CRUD + search + import/export. As of v1.1.52, all 8 tools also accept an optional `connection_id` parameter to proxy operations through a Remote Site Manager connection, enabling cross-site paper store federation.

## Features

### Collections & Records

| Concept | Description |
|---------|-------------|
| **Collection** | A named grouping of records (e.g., `knowledge`, `prompts`, `workflows`). Created automatically when the first record is written. |
| **Record** | A single JSON document identified by a unique `id` slug within its collection. Carries title, description, tags, status, body content, and arbitrary metadata. |
| **Status** | Each record has a status: `published`, `draft`, or `archived`. |
| **Tags** | Free-form string tags for categorization. Used for filtering in list and search operations. |

### MCP Tools (8 + 1 discovery)

| Tool | Description |
|------|-------------|
| `paper_store_list` | List records in a collection with optional tag/status/type filtering and pagination |
| `paper_store_read` | Read a single record by collection + record ID |
| `paper_store_search` | Free-text search across one or all collections by title, description, or tags |
| `paper_store_write` | Create a new record (requires `id`, `title`; optional `description`, `tags`, `status`, `body`, `meta`) |
| `paper_store_update` | Update an existing record — only provided fields are changed |
| `paper_store_delete` | Permanently delete a record by ID |
| `paper_store_import` | Bulk import records from a JSON array (existing records with matching IDs are overwritten) |
| `paper_store_export` | Export all records from a collection as a JSON array, optionally filtered by tags/status |
| `list_mcp_tools` | **Discovery tool** — lists all available MCP tools and their schemas for agent self-discovery. Filterable by toolkit and search term. |

### Remote Site Support (v1.1.52)

All 8 Paper Store tools accept an optional `connection_id` parameter. When provided:

1. The tool dispatches the operation through the Remote Site Manager using the specified connection.
2. The remote site must have the Paper Store REST API controller active (`mcp-ai/v1/paper-store`).
3. Operations are proxied via `WP_MCP_AI_Paper_Store_Remote` trait, which handles HTTP transport, authentication, and response normalization.

### REST API

The Paper Store exposes a REST API at `mcp-ai/v1/paper-store` for remote site access:

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/paper-store` | List all collections |
| `GET` | `/paper-store/search?q=...` | Search across collections |
| `GET` | `/paper-store/{collection}` | List records in a collection |
| `POST` | `/paper-store/{collection}` | Create a record |
| `GET` | `/paper-store/{collection}/{id}` | Read a single record |
| `PUT` | `/paper-store/{collection}/{id}` | Update a record |
| `DELETE` | `/paper-store/{collection}/{id}` | Delete a record |
| `GET` | `/paper-store/{collection}/export` | Export collection |
| `POST` | `/paper-store/{collection}/import` | Import records |

All routes require authentication via the standard NV oOS auth mechanisms (nonce, bearer token, or guest token).

## Architecture

### Storage

Records are stored as JSON files under `wp-content/uploads/mcp-ai-paper-store/{collection}/{id}.json`. The directory structure is created automatically on first write. Records are plain JSON — no database tables are used.

### Remote Dispatch

```
Tool execute($args)
  ├─ connection_id provided?
  │   ├─ Yes → WP_MCP_AI_Paper_Store_Remote::dispatch($connection_id, $args)
  │   │         → Remote Site Manager → HTTP → Remote WP REST API
  │   └─ No  → Local file read/write
```

The `WP_MCP_AI_Paper_Store_Remote` trait provides:
- Connection validation against the Remote Site Manager
- REST API endpoint construction (`mcp-ai/v1/paper-store`)
- Response normalization to match local operation return shapes
- Error passthrough with remote context

### Tool Discovery

The `list_mcp_tools` tool (234 lines) enables AI agents to dynamically discover available MCP tools. It queries `WP_MCP_AI_Tool_Registry::get_tools()` and returns tool names, descriptions, and JSON Schema parameter definitions. Supports filtering by toolkit namespace and text search. This enables agents to self-configure without hardcoded tool knowledge.

## Use Cases

- **Knowledge persistence**: Store research findings, meeting notes, or reference data across chat sessions
- **Prompt libraries**: Maintain curated prompt templates in a `prompts` collection
- **Workflow storage**: Save and version workflow definitions
- **Cross-site federation**: Read/write Paper Store records on remote WordPress sites
- **Agent memory augmentation**: Use Paper Store as a structured complement to vector-based agent memory

## Related

- [Remote Sites](remote-sites.md) — Remote connection management
- [Tool Presets System](tool-presets-system.md) — Design System preset includes all 8 Paper Store tools
- [PR #5835: Paper Store remote connection_id](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5835)
