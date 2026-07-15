# Research → Paper Store → WordPress Draft Pipeline

**Status:** Stable — v1.1.40
**Proposal:** `docs/project/proposals/013-research-paper-store-pipeline.md`
**Source:** `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-create-post-from-research.php`

---

## Overview

The NV oOS platform has two mature subsystems that previously operated in isolation:

- **Research Tools** (`web_search`, `generate_research_report`, `research_product`, etc.) — gather data via web search + AI synthesis and return it inline
- **Paper Store** (`WP_MCP_AI_Paper_Store_Manager` + 8 CRUD tools) — flat-file staging layer for AI-managed knowledge

The pipeline bridges them, enabling **Research → Stage (Paper Store) → Review → Publish (WordPress draft)** — a multi-stage editorial workflow that decouples AI generation from publication.

## Data Flow

```
User/AI Agent
    │
    ├─ web_search(query, save_to_paper_store=true)
    │       │
    │       ├─ Perform search (Brave/DuckDuckGo/Tavily/...)
    │       └─ Write to Paper Store → paper-store/web-search-results/{id}.json
    │
    ├─ generate_research_report(topic, save_to_paper_store=true, create_draft_post=true)
    │       │
    │       ├─ gather_research_information() — calls web_search
    │       ├─ perform_ai_research() — AI synthesis
    │       ├─ Write to Paper Store → paper-store/research-reports/{id}.json
    │       └─ wp_insert_post() → WordPress post (status: draft)
    │
    └─ create_post_from_research(paper_store_record_id, collection)
            │
            ├─ Read from Paper Store
            ├─ Map fields to WP_Post
            └─ wp_insert_post() → WordPress post (status: draft)
```

## Unified Parameters (per tool)

### `web_search` (Base — PHP 7.4+)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `save_to_paper_store` | `boolean` | `false` | Auto-save results to Paper Store |
| `paper_store_collection` | `string` | `"web-search-results"` | Target collection name |
| `paper_store_tags` | `array<string>` | `[]` | Tags for Paper Store record |

### `generate_research_report` (Pro — PHP 8.1+)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `save_to_paper_store` | `boolean` | `false` | Save report to Paper Store |
| `paper_store_collection` | `string` | `"research-reports"` | Target collection |
| `create_draft_post` | `boolean` | `false` | Create WordPress draft post |
| `draft_post_type` | `string` | `"post"` | WordPress post type |
| `draft_post_status` | `string` | `"draft"` | `draft` or `pending` |
| `draft_post_category` | `integer` | `0` | Category term ID |
| `draft_post_tags` | `array<string>` | `[]` | Post tags |

### `create_post_from_research` (Pro)

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `paper_store_record_id` | `string` | Yes¹ | Record ID in Paper Store |
| `paper_store_collection` | `string` | Yes¹ | Collection name |
| `post_type` | `string` | No | Default `"post"` |
| `post_status` | `string` | No | Default `"draft"` |
| `category_id` | `integer` | No | Category term ID |
| `tags` | `array<string>` | No | Post tags |
| `author_id` | `integer` | No | Post author |
| `update_paper_status` | `boolean` | No | Mark record as `"published"` |

¹ *Alternatively accepts `data` array directly (no Paper Store round-trip).*

## Security & Capabilities

| Operation | Required Capability |
|-----------|-------------------|
| `save_to_paper_store` (read-only research) | `edit_posts` |
| `create_draft_post` | `publish_posts` |
| `create_post_from_research` | `publish_posts` |

- Paper Store paths validated against traversal via `WP_MCP_AI_Paper_Store_Manager::validate_path()`
- Post content sanitized via `wp_kses_post()` before insertion
- All parameters follow two-gate sanitisation rule

## Action Hooks

| Hook | Fires when |
|------|-----------|
| `wp_mcp_ai_web_search_saved_to_paper_store` | Web search results saved to Paper Store |
| `wp_mcp_ai_research_saved_to_paper_store` | Any research tool saves to Paper Store |
| `wp_mcp_ai_research_draft_post_created` | Draft post created from research data |

## Backward Compatibility

- All new parameters default to `false`/empty. Existing tool invocations unaffected.
- `format_success_response()` envelope preserved; new fields (`paper_store_id`, `draft_post_id`) are additive.
- No database schema changes. No new dependencies.

## Why This Pattern

- **AI-generated content needs human review.** Raw research should not bypass editorial quality gates.
- **Research is expensive.** Web searches consume API credits. Caching in Paper Store enables reuse across sessions.
- **The Paper Store already exists.** This only adds parameter plumbing to existing infrastructure.
- **Tool composition over monoliths.** Research tools produce structured output; `create_post_from_research` consumes it. Follows Unix philosophy.

## Related

- [Paper Store Architecture](../project/proposals/paper-store-architecture.md)
- [Schedule Result Delivery Pipeline](../project/proposals/schedule-result-delivery-pipeline.md)
- [Content Format Awareness](content-format-awareness.md) — format preservation in created posts
- [Proposal 013: Research Paper Store Pipeline](../project/proposals/013-research-paper-store-pipeline.md)
