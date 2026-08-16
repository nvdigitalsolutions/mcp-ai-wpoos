# Attention Routing Truncating Assistant Tool Lists — Fix Details

## Problem Description

Attention routing (transformer-inspired semantic tool selection, QKV scoring)
activated for any assistant with more than **30** tools and trimmed the list
to the top **40** on every request. Assistants with 30–100 tools therefore
lost tools that were explicitly enabled — e.g. `product_actualization` was
hidden whenever routing ranked it outside the top 40.

## Root Cause

- `wp_mcp_ai_attention_filter_tool_slugs()` had a hardcoded threshold of 30
  tools, far below the chat payload cap (`wp_mcp_ai_max_chat_tools`, default
  100), so routing fired for assistants that had no size problem at all.
- When the vector service was unavailable or scoring failed, the router fell
  back to `fallback_selection()` — a static first-40 trim in registration
  order — instead of returning the complete list.
- Tools were scored against the assistant's system prompt alone, not the
  actual user query.
- The tool-definition token budget (`wp_mcp_ai_max_chat_tool_tokens`) was
  32,000 — too small for full 100-tool payloads, compounding the pressure to
  trim.

## Solution Implemented

Files: `includes/data/data-init.php`,
`includes/data/class-wp-mcp-ai-tool-attention-router.php`,
`includes/class-wp-mcp-ai-rest.php`,
`includes/admin/sections/class-wp-mcp-ai-section-tools.php`

1. **Route only above the payload cap** — routing activates only when the
   assistant's tool count exceeds `attention_routing_min_tools` (default 100,
   matching `wp_mcp_ai_max_chat_tools`); at or below the threshold the
   complete tool list is always sent.
2. **Fail open** — when the vector service is unavailable, there is no query
   text to score, or scoring throws, all allowed tools are returned. The
   `fallback_selection()` truncation helper was removed.
3. **Score against the real query** — the last user message is captured into
   the assistant config (`_last_user_message`) before the tools payload is
   built, and the router scores tools against it instead of the system prompt
   alone.
4. **Bigger token budget** — `wp_mcp_ai_max_chat_tool_tokens` default raised
   to 48,000 so full 100-tool payloads fit (~38% of a 128K context window).
5. **Admin controls** — Tools → Configuration gains Attention Tool Routing
   fields (`attention_routing_enabled`, `attention_routing_min_tools`,
   `attention_routing_top_k`), saved to `wp_mcp_ai_settings`. The
   `wp_mcp_ai_attention_routing_*` filters remain as code-level overrides and
   are clamped to safe ranges (0–128 for the threshold/top-K).

## Test Coverage

No dedicated unit test was added; the change is guarded by the existing
chat-flow tool-payload tests and the graceful-degradation contract now
documented in `includes/data/README.md` ("Every attention-routing feature
degrades to current behavior when dependencies are unavailable").

## Related

- [PR #5879](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5879)
- [`includes/data/README.md`](../../../includes/data/README.md)
