# Chat SPA Addon (`addons/chat-spa/`)

**Version:** 0.6.0  
**Status:** All 7 phases complete  
**Location:** `addons/chat-spa/`  
**Requires:** WordPress 6.0+, PHP 7.4+, NV oOS base plugin

The Chat SPA addon is a modern React-based replacement for the legacy `[mcp_ai_chat]` JavaScript shortcode. It uses the **Vercel AI SDK UI** with a custom SSE → Data Stream Protocol adapter and delivers a significantly richer chat experience while remaining backward-compatible with all existing assistants and tool configurations.

---

## Contents

1. [Quick Start](#quick-start)
2. [Feature Overview by Phase](#feature-overview-by-phase)
3. [Migration from Legacy Chat](#migration-from-legacy-chat)
4. [Configuration](#configuration)
5. [Architecture](#architecture)
6. [Shortcode Reference](#shortcode-reference)

---

## Quick Start

1. Install and activate the `addons/chat-spa/` addon (ZIP available from the Releases page).
2. The `[mcp_ai_chat]` shortcode now renders the React SPA by default.
3. To opt out and keep the legacy JS, define in `wp-config.php`:
   ```php
   define( 'WP_MCP_AI_LEGACY_CHAT_JS', true );
   ```

---

## Feature Overview by Phase

| Phase | Feature | Version |
|-------|---------|---------|
| **Phase 1** | Vercel AI SDK UI + custom SSE → Data Stream Protocol adapter + REST routes | v0.1.0 |
| **Phase 2** | Tool-call cards (expandable), memory pills (🧠 badge), admin embed, SSE adapter type-ID fix | v0.2.0 |
| **Phase 3** | Transcripts sidebar — load, save, and delete transcripts from localStorage | v0.3.0 |
| **Phase 4** | Memory Drawer — three tabs: **Memories**, **Scope**, **Audit** | v0.4.0 |
| **Phase 5** | HITL approval bar with approve/deny buttons + 6 s polling | v0.5.0 |
| **Phase 6** | File attachments, message regenerate button, message branching | v0.6.0 |
| **Phase 7** | `WP_MCP_AI_LEGACY_CHAT_JS` opt-out constant + blueprint §20 migration guide | v0.6.0 |

---

## Migration from Legacy Chat

The Chat SPA addon is designed as a **drop-in replacement**. The `[mcp_ai_chat]` shortcode signature is unchanged. The SPA uses the same REST endpoints (`/wp-json/mcp-ai/v1/chat`) and the same authentication (WP nonce, assistant credentials, guest tokens).

**Differences to be aware of:**
- The SPA bundle is ~81 KB gzip (vs ~30 KB for the legacy JS). Ensure your CDN supports this.
- The Memory Drawer requires the Chat-client Memory Bridge to be enabled (**Orchestration → Settings → Enable Chat-Client Memory**).
- The HITL approval bar appears automatically when an assistant triggers `request_user_approval`.

**Opt-out:**
```php
// wp-config.php — falls back to the legacy mcp-ai-chat.js
define( 'WP_MCP_AI_LEGACY_CHAT_JS', true );
```

---

## Configuration

| Constant | Default | Effect |
|----------|---------|--------|
| `WP_MCP_AI_LEGACY_CHAT_JS` | `false` | Set to `true` to use the legacy JavaScript chat |

No other per-shortcode configuration changes are required.

---

## Architecture

The addon uses the **Toolkit SPA Blueprint** pattern:
- **esbuild IIFE bundle** → `assets/dist/chat-spa.js` + `assets/dist/chat-spa.css` (~81 KB gzip)
- **Vercel AI SDK UI** (`useChat()`) with a custom `SSEDataStreamAdapter` that converts NV oOS SSE events to the AI SDK Data Stream Protocol
- **REST routes** registered under `/wp-json/mcp-ai/v1/chat-client` (reuses the base-plugin chat endpoint with an adapter layer)
- **Memory Drawer** integrates with the Chat-client Memory Bridge via `/wp-json/mcp-ai/v1/chat-memory/*`
- **HITL bar** polls `/wp-json/mcp-ai/v1/approvals/{id}` every 6 seconds

See [`docs/addons/toolkit-spa-blueprint.md`](toolkit-spa-blueprint.md) for the full blueprint.

---

## Shortcode Reference

```php
[mcp_ai_chat assistant="123"]
[mcp_ai_chat assistant="123" allow_guests="true"]
[mcp_ai_chat assistant="123" height="600px" theme="dark"]
```

All existing `[mcp_ai_chat]` shortcode attributes are supported.
