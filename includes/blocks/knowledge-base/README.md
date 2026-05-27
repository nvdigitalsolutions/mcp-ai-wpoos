# Knowledge Base Upload Block

## Purpose

Provides a file upload interface for adding documents to an AI assistant's knowledge base. Supports drag-and-drop with configurable file type, count, and size limits.

## Tier

**Base** / PHP 7.4+

## Public Surface

- **Block name:** `mcp-ai-wpoos/knowledge-base`
- **Category:** `mcp-ai-wpoos`
- **Attributes:** `title`, `description`, `allowedTypes` (default `.pdf,.txt,.md,.doc,.docx,.csv,.json`), `maxFiles` (default 10), `maxFileSizeMB` (default 10), `showPreview`, `uploadedFileIds`

## Inputs / Outputs / Neighbors

- **Input:** Block attributes (7 options)
- **Output:** Server-side rendered upload zone HTML with inline `<script type="application/json">` config containing REST URLs, nonces, and upload parameters
- **Used by:** `assistant-builder/render.php` (rendered inline when `showKnowledgeBase=true`)
- **Depends on:** WordPress Media API, REST API `mcp-ai/v1`, block editor JS, frontend JS
- **Registered by:** `WP_MCP_AI_Assistant_Builder_Blocks::register_blocks()`

## Conventions

- Dynamic block; `render: "file:./render.php"` in `block.json`
- API Version 3, `html: false`
- Sanitizes at entry (`absint`, `sanitize_text_field`)
- Escapes output via `esc_attr()`, `esc_html()`, `wp_json_encode()`
- File validation enforced in JavaScript (before upload) and server-side (during upload)

## Tests

No dedicated block tests exist.

## Also Load

- `.context/conventions.md`
- `.context/rest-api.md`
- `.context/security-checklist.md`
