# Implementation Plan: BME Chat History Context Strategy

**Based on:** Proposal 006 (`docs/project/proposals/006-chat-history-context-strategy.md`)
**Phase:** 1 — Foundation
**Target:** Server-side BME architecture with backward-compatible defaults

---

## Implementation Checklist

### Step 1: New Settings (3 files)
- [x] `includes/admin/class-wp-mcp-ai-admin-settings-base.php` — Add 5 new defaults
- [x] `includes/admin/class-wp-mcp-ai-admin-settings.php` — Register + render 6 new fields (context_strategy, end_window_size, summary_trigger_count, summary_max_tokens, tool_result_summarize_threshold + filter)
- [x] Add sanitization for new fields (handled automatically by generic sanitizer)

### Step 2: Conversation Summarizer (1 new file)
- [x] `includes/class-wp-mcp-ai-conversation-summarizer.php` — New class
  - `should_summarize()` — threshold check
  - `summarize()` — LLM-based compression
  - `should_summarize_tool_result()` — tool result threshold check
  - `build_conversation_text()` — message-to-text formatter
  - `extract_summary_from_result()` — parse LLM response

### Step 3: BME Strategy in REST (1 file)
- [x] `includes/class-wp-mcp-ai-rest.php` — Add `trim_messages_bme()` method
- [x] Hook into `enforce_chat_request_limits()` via strategy dispatch
- [x] Add `wp_mcp_ai_context_strategy` filter
- [x] Add `generate_conversation_summary()` helper method
- [x] Fallback to sliding window on summarization failure

### Step 4: Client-Side Parameter (1 file)
- [x] `assets/js/chat.js` — Send `context_strategy` in payload
- [x] `assets/js/chat.js` — Add `contextStrategy`, `endWindowSize`, `summaryTriggerCount`, `toolResultSummarizeThreshold` to defaultGlobalConfig
- [x] `assets/js/chat.js` — localStorage: store `summary` and `contextStrategy` fields
- [x] `assets/js/chat.js` — `restoreConversationFromStorage()`: restore summary and contextStrategy
- [x] `assets/js/chat.js` — `summarizeToolResultIfNeeded()`: truncate large tool results
- [x] `assets/js/chat.js` — `estimateMessageTokensBME()`: client-side token estimation
- [x] `assets/js/chat.js` — Embedded provider: token-aware trimming (not just count-based)

### Step 4b: Server-Side Client Override (1 file)
- [x] `includes/class-wp-mcp-ai-rest.php` — `build_chat_limit_context()`: accept request, read client `end_window_size` and `context_strategy`
- [x] `includes/class-wp-mcp-ai-rest.php` — `trim_messages_bme()`: use context override for `end_window_size`
- [x] `includes/class-wp-mcp-ai-rest.php` — `enforce_chat_request_limits()`: use client context strategy override

### Step 5: Tests (2 new files)
- [x] `tests/test-conversation-summarizer.php` — 9 test cases
- [x] `tests/test-bme-context-strategy.php` — 7 test cases

### Step 6: Validation
- [x] `php -l` — All PHP files pass syntax check
- [x] `phpcs` — All modified files pass WordPress Coding Standards
- [x] `phpcs --standard=PHPCompatibilityWP` — PHP 7.4-8.3 compatible

---

## Files Created
1. `includes/class-wp-mcp-ai-conversation-summarizer.php`
2. `tests/test-conversation-summarizer.php`
3. `tests/test-bme-context-strategy.php`
4. `docs/project/proposals/006-chat-history-context-strategy.md`
5. `docs/project/proposals/006-chat-history-context-strategy-implementation-plan.md`

## Files Modified
6. `includes/admin/class-wp-mcp-ai-admin-settings-base.php`
7. `includes/admin/class-wp-mcp-ai-admin-settings.php`
8. `includes/class-wp-mcp-ai-rest.php`
9. `assets/js/chat.js`

## Validation Summary
- ✅ PHP syntax: All files pass `php -l`
- ✅ WP Coding Standards: All files pass `phpcs`
- ✅ PHP 7.4-8.3 Compat: All files pass `PHPCompatibilityWP`
- ✅ Backward compat: Default `context_strategy = 'sliding_window'` preserves existing behavior
- ✅ Both endpoints: `/chat` and `/chat-client` flow through BME strategy
