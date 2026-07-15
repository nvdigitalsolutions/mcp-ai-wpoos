# Content Format Awareness

**Status:** Stable — v1.1.40
**Source:** `includes/helpers/class-wp-mcp-ai-content-format-helper.php`
**Tests:** `tests/test-content-format-helper.php`

---

## Overview

The Content Format Awareness system ensures that AI-generated content retains its intended structure (Markdown, HTML, or plain text) through the full WordPress create/update pipeline. Post-modifying tools and analysis tools use `WP_MCP_AI_Content_Format_Helper` to detect the format of incoming content and preserve it through storage and retrieval.

## Why This Matters

AI models commonly produce Markdown output, but WordPress stores content as HTML. Without format awareness:

- Markdown is stored verbatim and rendered as raw text in the frontend
- HTML-entity issues cause broken formatting
- Mixed-format content (e.g., HTML email templates embedded in Markdown) is mishandled

The helper solves this with detection, conversion, and preservation logic.

## Architecture

```
AI Model (Markdown/HTML/Plain)
    │
    ▼
WP_MCP_AI_Content_Format_Helper::detect_format()
    │
    ├─ Markdown  ──► convert to HTML ──► wp_insert_post()
    ├─ HTML      ──► pass through   ──► wp_insert_post()
    └─ Plain     ──► wrap in <p>    ──► wp_insert_post()
```

## Key Methods

| Method | Description |
|--------|-------------|
| `detect_format( $content )` | Returns `'markdown'`, `'html'`, or `'plain'` |
| `convert_to_html( $content, $from )` | Converts Markdown/plain to HTML-safe content |
| `is_markdown( $content )` | Heuristic detection — checks for Markdown syntax patterns |
| `is_html( $content )` | Checks for HTML tag presence |
| `format_for_storage( $content )` | Prepares content for `wp_insert_post()` |
| `format_for_display( $content )` | Prepares content for frontend rendering |

## Detection Heuristics

The helper uses the following signals, checked in order:

1. **HTML tags present** (`<p>`, `<div>`, `<h1>`, etc.) → `'html'`
2. **Markdown syntax present** (`##`, `**bold**`, `*italic*`, `- list`, `[link](url)`, code fences) → `'markdown'`
3. **Fallback** → `'plain'`

## Integration Points

The helper is used by:

- **`class-wp-mcp-ai-tool-create-post.php`** — detects format before `wp_insert_post()`
- **`class-wp-mcp-ai-tool-save-post.php`** — preserves format on post updates
- **`class-wp-mcp-ai-tool-analyze-content.php`** — preserves format in analysis results
- **Research tools** — `generate_research_report`, `research_post`, `research_page`, etc.

## Two-Gate Sanitization

Following the Unix Theory P0/P1 sanitization rule:

- **Gate 1 (entry):** `detect_format()` is called on incoming `$arguments['content']` before any processing
- **Gate 2 (exit):** `wp_kses_post()` applied at storage boundary via `wp_insert_post()`

## Hooks

| Hook | Fires when |
|------|-----------|
| `wp_mcp_ai_content_format_detected` | Format detection completes |
| `wp_mcp_ai_content_format_converted` | Markdown-to-HTML conversion completes |

## Related

- [Tool Presets System](tool-presets-system.md) — content tools that consume this helper
- [Research → Paper Store Pipeline](research-paper-store-pipeline.md) — preserves format through staging
