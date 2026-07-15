# Implementation Plan: Research → Paper Store → WordPress Draft Pipeline

**Based on:** Proposal 013 (`docs/project/proposals/013-research-paper-store-pipeline.md`)  
**Date:** 2026-07-15  
**Status:** In Progress  
**Target release:** v1.4.0

---

## Executive Summary

Three implementation phases across 4 files. Phase 1 (Base, PHP 7.4+) adds Paper Store persistence to `web_search`. Phase 2 (Pro, PHP 8.1+) adds Paper Store + draft-post creation to `generate_research_report`. Phase 3 (Pro) creates the standalone `create_post_from_research` bridge tool.

---

## Phase 1: Web Search → Paper Store (Base)

### Task 1.1 — Add parameters to `web_search` schema

**File:** `includes/tools/class-wp-mcp-ai-tool-web-search.php`  
**Method:** `get_parameters_schema()`

Add three optional parameters:
- `save_to_paper_store` (boolean, default false)
- `paper_store_collection` (string)
- `paper_store_tags` (array<string>)

### Task 1.2 — Add `save_search_to_paper_store()` helper

**File:** `includes/tools/class-wp-mcp-ai-tool-web-search.php`

New private method that:
1. Generates a unique record ID: `wp_mcp_ai_ws_` . md5($query . time())
2. Builds a Paper Store record with `id`, `title` (query), `type`, `tags`, `status: "draft"`, `body`, `meta`
3. Calls `WP_MCP_AI_Paper_Store_Manager::get_instance()->get_repository($collection)->save($record)`
4. Returns the record ID or WP_Error

### Task 1.3 — Wire into `execute()`

**File:** `includes/tools/class-wp-mcp-ai-tool-web-search.php`  
**Method:** `execute()`

After `validate_and_normalize_result()` and before the action hook, check `$arguments['save_to_paper_store']` and call the helper. Add `paper_store_id` and `paper_store_collection` to the response.

### Task 1.4 — Fire action hook

Add `do_action( 'wp_mcp_ai_web_search_saved_to_paper_store', ... )` after saving.

---

## Phase 2: Research Report → Paper Store + Draft Post (Pro)

### Task 2.1 — Add parameters to `generate_research_report`

**File:** `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-generate-research-report.php`  
**Method:** `get_definition()`

Add these optional parameters:
- `save_to_paper_store` (boolean)
- `paper_store_collection` (string)
- `create_draft_post` (boolean)
- `draft_post_type` (string)
- `draft_post_status` (string)
- `draft_post_category` (integer)
- `draft_post_tags` (array<string>)

### Task 2.2 — Wire Paper Store save into `execute_research_mode()`

After `parse_and_format_research()` succeeds, check `save_to_paper_store` and write the formatted report to Paper Store. Add `paper_store_id` to response.

### Task 2.3 — Wire draft post creation into `execute_research_mode()`

After Paper Store save (or independently), check `create_draft_post` and call `wp_insert_post()` with the report content. Add `draft_post_id` and `draft_post_edit_url` to response.

### Task 2.4 — Add helper methods

- `save_report_to_paper_store( $report_data, $arguments )` — writes to Paper Store
- `create_draft_post_from_report( $report_data, $arguments )` — creates WordPress post

---

## Phase 3: Standalone Bridge Tool (Pro)

### Task 3.1 — Create new tool class

**New file:** `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-create-post-from-research.php`

Implements `WP_MCP_AI_Tool_Interface`, `WP_MCP_AI_Tool_Capability_Flags_Interface`.  
Uses `WP_MCP_AI_Tool_Chat_Response`.

Tool slug: `create_post_from_research`

Parameters:
- `paper_store_record_id` (string, required if no raw data)
- `paper_store_collection` (string, required if no raw data)
- `data` (object, alternative to paper_store_record_id)
- `post_type` (string, default "post")
- `post_status` (string, default "draft")
- `category_id` (integer)
- `tags` (array<string>)
- `author_id` (integer)
- `update_paper_status` (boolean)

### Task 3.2 — Register tool

**File:** `addons/pro/includes/tools/orchestration/init.php` (or similar init file)

---

## Validation

After each phase:

```bash
# Lint changed files
composer run lint:errors-only -- --filter=gitmodified

# Auto-fix style issues
composer run format -- F:/GITHUB/mcp-ai-wpoos/includes/tools/class-wp-mcp-ai-tool-web-search.php

# PHP compatibility check (Phase 1 — 7.4+)
composer run lint:compat

# Full lint (after all phases)
composer run lint:errors-only
```
