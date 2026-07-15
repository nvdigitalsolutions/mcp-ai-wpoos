# Research → Paper Store → WordPress Draft Pipeline

**Proposal 013**  
**Date:** 2026-07-15  
**Status:** Proposal  
**Target release:** v1.4.0

---

## 1. Executive Summary

The NV oOS platform has two mature subsystems that currently operate in isolation:

- **Research Tools** (`web_search`, `generate_research_report`, `research_product`, etc.) — gather data via web search + AI synthesis and return it inline to the chat client.
- **Paper Store** (`WP_MCP_AI_Paper_Store_Manager` + 8 CRUD/import/export tools) — a flat-file staging layer for AI-managed knowledge with JSON and Markdown+YAML drivers.

This proposal bridges these subsystems by adding an optional `save_to_paper_store` parameter to every research tool and a `create_draft_post` parameter to orchestration tools, enabling a **Research → Stage (Paper Store) → Review → Publish (WordPress draft)** pipeline.

### Why this matters

- **AI-generated content needs human review.** Dumping raw research directly into WordPress posts bypasses editorial quality gates.
- **Research is expensive.** Web searches consume API credits and AI tokens. Caching results in Paper Store lets users reuse research across sessions without re-executing costly operations.
- **The Paper Store already exists.** All the infrastructure (flat-file I/O, indexes, queries, admin UI, Git sync, scheduled delivery) is built and tested. This proposal only adds the parameter plumbing to connect research tools to it.

---

## 2. Industry Best Practices

### 2.1 Editorial Workflow Standards

Professional content teams use multi-stage pipelines (Edit Flow, Oasis Workflow, PublishPress) that map to WordPress custom statuses: `draft` → `pending review` → `approved` → `published`. AI-generated content should follow the same pattern — land as `draft` or `pending`, not `publish`.

### 2.2 Staging / Scratch Area Pattern

AI writing tools (Jasper, Copy.ai, Writesonic) universally stage generated content in a "documents" or "projects" area before the user promotes it to their CMS. This decouples generation from publication, enabling:
- Iterative refinement
- Fact-checking against sources
- Approval workflows
- Version history

### 2.3 Tool Composition Over Monoliths

The MCP protocol and Unix philosophy both favor small, composable tools. A `research` tool should produce structured output; a separate `create_post` tool should consume it. This proposal follows that pattern while also offering convenience `create_draft_post` parameters for common single-call workflows.

### 2.4 Idempotent, Cacheable Research

Web search results have a shelf life. The Paper Store's TTL/retention model (inherited from the schedule manager's `paper_store` delivery channel) allows stale research to be expired automatically. Records carry `created_at`/`updated_at` timestamps for audit.

---

## 3. Architecture

### 3.1 Data Flow

```
User/AI Agent
    │
    ├─ web_search(query, save_to_paper_store=true)
    │       │
    │       ├─ Perform search (Brave/DuckDuckGo/Tavily/...)
    │       ├─ Cache result (existing)
    │       └─ Write to Paper Store ──► wp-content/uploads/.../paper-store/web-search-results/{id}.json
    │
    ├─ generate_research_report(topic, save_to_paper_store=true, create_draft_post=true)
    │       │
    │       ├─ gather_research_information() — calls web_search
    │       ├─ perform_ai_research() — AI synthesis
    │       ├─ parse_and_format_research() — structured report
    │       ├─ Write to Paper Store ──► .../paper-store/research-reports/{id}.json
    │       └─ wp_insert_post() ──► WordPress post (status: draft)
    │
    └─ create_post_from_research(paper_store_record_id, collection)
            │
            ├─ Read from Paper Store
            ├─ Map fields to WP_Post
            └─ wp_insert_post() ──► WordPress post (status: draft)
```

### 3.2 New Parameters (per tool)

#### `web_search` (Base — PHP 7.4+)

| Parameter | Type | Default | Description |
|---|---|---|---|
| `save_to_paper_store` | `boolean` | `false` | Auto-save results to Paper Store |
| `paper_store_collection` | `string` | `"web-search-results"` | Target collection name |
| `paper_store_tags` | `array<string>` | `[]` | Tags for the Paper Store record |

#### `generate_research_report` (Pro — PHP 8.1+)

| Parameter | Type | Default | Description |
|---|---|---|---|
| `save_to_paper_store` | `boolean` | `false` | Save report to Paper Store |
| `paper_store_collection` | `string` | `"research-reports"` | Target collection name |
| `create_draft_post` | `boolean` | `false` | Create a WordPress draft post |
| `draft_post_type` | `string` | `"post"` | WordPress post type |
| `draft_post_status` | `string` | `"draft"` | `draft` or `pending` |
| `draft_post_category` | `integer` | `0` | Category term ID |
| `draft_post_tags` | `array<string>` | `[]` | Post tags |

#### `research_product` (Pro — PHP 8.1+)

Same pattern as `generate_research_report`, plus `create_woo_product` integration.

### 3.3 New Tool: `create_post_from_research`

A standalone Pro tool that bridges Paper Store ↔ WordPress:

| Parameter | Type | Required | Description |
|---|---|---|---|
| `paper_store_record_id` | `string` | Yes¹ | Record ID in Paper Store |
| `paper_store_collection` | `string` | Yes¹ | Collection name |
| `post_type` | `string` | No | Default `"post"` |
| `post_status` | `string` | No | Default `"draft"` |
| `category_id` | `integer` | No | Category term ID |
| `tags` | `array<string>` | No | Post tags |
| `author_id` | `integer` | No | Post author |
| `update_paper_status` | `boolean` | No | Mark Paper Store record as `"published"` |

¹ *Alternatively accepts `data` array directly (no Paper Store round-trip).*

---

## 4. Security & Capabilities

| Operation | Required Capability |
|---|---|
| `save_to_paper_store` (read-only research) | `edit_posts` |
| `create_draft_post` | `publish_posts` |
| `create_post_from_research` | `publish_posts` |

- Paper Store paths are validated against traversal via `WP_MCP_AI_Paper_Store_Manager::validate_path()`.
- Post content is sanitized via `wp_kses_post()` before insertion.
- All parameters follow the two-gate sanitisation rule (sanitize at entry, escape at exit).

---

## 5. Action Hooks

| Hook | Fires when |
|---|---|
| `wp_mcp_ai_web_search_saved_to_paper_store` | Web search results saved to Paper Store |
| `wp_mcp_ai_research_saved_to_paper_store` | Any research tool saves to Paper Store (unified) |
| `wp_mcp_ai_research_draft_post_created` | A draft post is created from research data |

---

## 6. Phase Rollout

### Phase 1 — Web Search Paper Store (Base)
- **File:** `includes/tools/class-wp-mcp-ai-tool-web-search.php`
- **Add:** `save_to_paper_store`, `paper_store_collection`, `paper_store_tags` parameters
- **Add:** `save_to_paper_store()` helper method
- **Add:** `wp_mcp_ai_web_search_saved_to_paper_store` action hook

### Phase 2 — Research Report Paper Store + Draft Post (Pro)
- **File:** `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-generate-research-report.php`
- **Add:** `save_to_paper_store`, `create_draft_post`, and related parameters
- **Add:** `wp_mcp_ai_research_saved_to_paper_store` action hook
- **Add:** `wp_mcp_ai_research_draft_post_created` action hook

### Phase 3 — Create Post from Research Tool (Pro)
- **New file:** `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-create-post-from-research.php`
- **Add:** `create_post_from_research` tool

### Phase 4 — Rollout to Domain Research Tools (Pro)
- `research_product`, `research_company`, `research_eca`, `research_quiz_topic`, `research_site_best_practices`

---

## 7. Backward Compatibility

- All new parameters default to `false`/empty. Existing tool invocations are unaffected.
- The `format_success_response()` envelope is preserved; new fields (`paper_store_id`, `draft_post_id`) are additive.
- No database schema changes. No new dependencies.

---

## 8. References

- [Paper Store Architecture Proposal](./paper-store-architecture.md)
- [Schedule Result Delivery Pipeline](./schedule-result-delivery-pipeline.md)
- [Unix Theory Compliance Enhancement](./UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md)
- [WordPress Editorial Workflow Best Practices](https://pressable.com/blog/wordpress-editorial-workflow/)
