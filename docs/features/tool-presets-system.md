# Tool Presets System

**Status:** Stable — v1.1.52
**Category:** Pro Feature — Tool Management  
**Introduced:** July 2026 (PRs #5660, #5662)
**Last Updated:** August 11, 2026 (Design System preset — PR #5837)

## Overview

The Tool Presets system manages curated groupings of AI tools that can be assigned to assistants. In v1.1.39, the system was refactored with an essentials-layer architecture, deduplication, and auto-upgrade for validated variants.

**v1.1.41:** The Essentials Internal preset gained 7 read-only knowledge access tools (3 Paper Store read + 4 OKF read), and the Files & Documents preset gained all 6 OKF tools alongside the existing 6 Paper Store tools. Write tools remain only in Files & Documents.

## Architecture

### Essentials Layers

Tool presets are now organized in a layered hierarchy:

1. **Base Layer** — core tools available to all assistants (content, media, research).
2. **Essentials Layer** — commonly used tools within each domain (e.g., `create_post`, `read_post`, `upload_media` for content).
3. **Extended Layer** — domain-specific advanced tools (e.g., `schedule_post`, `generate_featured_image`, `seo_analyze` for content).
4. **Specialist Layer** — toolkit-specific tools (e.g., WooCommerce, CRM, JetEngine tools).

Layers are additive — assigning an "essentials" preset automatically includes the base layer. This prevents the common problem of assistants missing fundamental tools when presets are narrowly scoped.

### Deduplication

The refactored system strips duplicate tools across layers:

- **Within-layer dedup** — a tool appearing in multiple presets within the same layer is deduplicated.
- **Cross-layer dedup** — tools already included by a lower layer are not repeated.
- **Assistant-level dedup** — when multiple presets are assigned to an assistant, the final tool list is deduplicated.

### Auto-Upgrade for Validated Variants

Tools that have been "validated" (passed safety and capability checks) are automatically upgraded:

- **Validated status** — a tool variant that has passed all safety checks and capabilities verification.
- **Auto-upgrade** — when a validated variant exists for a tool, the preset system automatically uses it instead of the non-validated version.
- **Duplicate prevention** — auto-upgrade no longer causes duplicate tool names or "not-allowed" errors (fixed in PR #5662).

## Configuration

### Preset Categories

| Category | Example Presets | Layer |
|----------|----------------|-------|
| Content | Content Creator Essentials, Blog Publisher | Base + Essentials |
| Media | Media Studio Essentials, Image Generation | Base + Essentials |
| Commerce | WooCommerce Essentials, Shopify Management | Extended |
| CRM | Lead Manager Essentials, Deal Pipeline | Extended + Specialist |
| Knowledge | Paper Store Essentials, OKF Knowledge Access | Base + Essentials |
| Design | Design System (72 tools, 13 categories) | Extended |
| Development | Developer Copilot, Code Analysis | Base + Essentials |

### Tool Payload Cap

The tool payload cap has been raised from 50 to 100 tools per assistant. This is managed by the preset system to ensure compatibility with AI provider context limits.

### Chips Bar UI

Selected tools display as clickable chips below the tool selector:
- **+N overflow toggle** when more than 5 chips are selected.
- **Click-to-remove** individual tools from the selection.
- **Proper spacing** between chips for readability.

## Design System Preset (v1.1.52)

The **Design System** preset (`design-system`) was added in v1.1.52 (PR #5837). It bundles 72 tools across 13 categories for comprehensive design and content creation workflows:

| Category | Tool Count | Key Tools |
|----------|-----------|-----------|
| WordPress Content | 9 | `create_post`, `save_post`, `get_recent_posts`, `search_content`, `search_attachments` |
| Image Generation | 4 | `generate_openai_image`, `generate_gemini_image`, `edit_gemini_image`, `edit_openai_image` |
| Image Analysis | 4 | `analyze_image`, `extract_image_text`, `generate_image_caption`, `generate_image_alt_text` |
| Image Processing | 5 | `remove_background`, `resize_image`, `vectorize_image`, `optimize_image_sharp`, `create_image_variation` |
| Web Search & Research | 5 | `web_search`, `deep_research`, `semantic_content_search`, `run_crawl4ai_job_validated`, `scrape_product_validated` |
| Charts | 2 | `create_chart`, `create_chart_validated` |
| Document Generation | 14 | `pro_pdf_document`, `pro_word_document`, `pro_excel_document`, `generate_pdf`, `generate_word`, `generate_excel`, `html_to_pdf`, `merge_pdfs`, `add_watermark_to_pdf`, `extract_pdf_text`, `ocr_pdf_text`, `pro_document_ocr`, `excel_data_import`, `excel_data_export`, `generate_invoice_pdf` |
| Paper Store | 8 | All 8 `paper_store_*` CRUD + import/export tools |
| Video Generation | 2 | `generate_sora_video`, `generate_veo_video` |
| Video Processing | 5 | `extract_video_frames`, `trim_video`, `compress_video`, `convert_video_format`, `transcode_video` |
| Social Media | 3 | `schedule_social_post`, `generate_social_captions`, `publish_to_social` |
| AI & Memory | 6 | `create_text_embeddings`, `load_skill`, `retrieve_agent_memory`, `semantic_context_search`, `recall_memory`, `wake_up_context` |
| Utility | 4 | `count_tokens`, `list_available_models`, `list_mcp_tools`, `submit_document_prompt` |

This preset is ideal for assistants focused on brand asset creation, marketing content, and design workflows.

## SSE Adapter Fix

In v1.1.39, a double-execution bug in SSE (Server-Sent Events) adapters was fixed:

- Tool results were being dispatched twice in streaming mode — once via SSE and once via the completion callback.
- The fix ensures single execution with proper result routing.
- Media refresh filters are properly cleared between executions to prevent stale data.

## tool_call_id Handling

### DeepSeek Streaming Fix

DeepSeek's streaming implementation previously omitted `tool_call_id` from tool message blocks, causing tool result matching failures in the SPA v2 frontend. The fix (PR #5638):

- Always includes `tool_call_id` in `extract_request_messages` fallback.
- Properly threads `tool_call_id` through the SSE streaming pipeline.
- Tool messages without `tool_call_id` are stripped from the conversation (PR #5648).

## WP-CLI Commands

```bash
# List all tool presets
wp mcp-ai presets list

# Show details for a specific preset
wp mcp-ai presets show --preset=content-creator-essentials

# Validate all tools in a preset
wp mcp-ai presets validate --preset=woocommerce-essentials

# Auto-upgrade validated variants
wp mcp-ai presets upgrade --assistant-id=42
```

## Hooks & Filters

| Hook | Type | Description |
|------|------|-------------|
| `wp_mcp_ai_preset_layers` | Filter | Modify layer definitions and tool assignments. |
| `wp_mcp_ai_preset_dedup` | Filter | Control deduplication behavior (default: true). |
| `wp_mcp_ai_preset_auto_upgrade` | Filter | Enable/disable auto-upgrade for validated variants. |
| `wp_mcp_ai_tool_payload_cap` | Filter | Override max tools per assistant (default: 100). |

## Related Documentation

- [Agent Delegation System](agent-delegation-system.md) — tool availability in delegated contexts
- [Pro SPA v2](pro-spa-v2.md) — tool shortcuts and slash commands drawers
- [Tool Reference](../reference/tools/) — complete tool documentation
