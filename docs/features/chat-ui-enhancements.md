# Chat UI Enhancements

**Added:** May 29, 2026 (v1.1.25)  
**Tier:** Base (all features available in base plugin jQuery chat UI + Pro React SPA)

## Overview

A comprehensive set of **7 chat interface enhancements** shipped across both the base jQuery chat UI (`assets/js/chat.js`) and the Pro React SPA (`addons/chat-spa/`). These features improve user experience, provide more control during AI interactions, and add professional polish to the chat surface.

## Features

### 1. Profile Card

A collapsible profile panel at the top of the chat window showing:
- **Assistant name and avatar** — pulled from the assistant CPT featured image or Gravatar
- **Assistant description** — one-line summary of what this assistant does
- **Active model** — which AI model is currently selected (e.g., "GPT-4o")
- **Capability level** — Write / Ask / Minimal indicator

The profile card auto-hides on scroll-down and reappears on scroll-up, conserving vertical space during long conversations.

### 2. Stop Generation

A **Stop** button (■) appears in the chat input area whenever the assistant is actively generating a response. Clicking it:
- Aborts the current SSE stream
- Preserves any partial response already rendered
- Transitions the chat state back to "ready"
- Emits `data-stream-stopped` attribute on the message bubble for styling

Under the hood: calls `EventSource.close()` on the active SSE connection and fires the `wp_mcp_ai_chat_stop_generation` JavaScript event.

### 3. Feedback Widget

Each assistant message bubble now includes a **thumbs-up / thumbs-down** feedback pair:
- **👍 Thumbs Up** — Records positive feedback with optional free-text comment
- **👎 Thumbs Down** — Records negative feedback; prompts for a brief explanation
- Feedback is stored as post meta on the assistant CPT (`_wp_mcp_ai_feedback_{message_id}`)
- Aggregated feedback visible in the assistant editor → Feedback tab
- Feedback data feeds into the **Continual Harness** self-improvement loop (Pro)

### 4. Code Copy Button

Every code block in assistant responses now renders with a **Copy** button in the top-right corner:
- One-click copy of the entire code block to clipboard
- Visual confirmation: button text changes to "Copied!" for 2 seconds
- Uses the `nvoos-clipboard` npm package (with `document.execCommand('copy')` fallback)
- Respects the code block's language for syntax-highlighted copying

### 5. Dark Mode

A **☀/🌙 toggle** in the chat header switches between light and dark themes:
- **System default** — follows `prefers-color-scheme` media query on first load
- **Manual override** — user's choice persisted in `localStorage` (`nv_oos_dark_mode`)
- **CSS custom properties** — theme colours defined as CSS variables on `:root` and `[data-theme="dark"]`
- Applies to chat bubble, chat page, and embedded shortcode surfaces
- Respects WordPress admin colour scheme when in wp-admin context

### 6. Saved Prompts Panel

A slide-out **Prompts** panel (accessible via 📋 icon in chat header):
- **Save prompt** — star any message you sent to save it as a reusable prompt
- **Prompt library** — browse, search, and click-to-use saved prompts
- **Prompt categories** — user-defined tags for organisation
- **Quick insert** — clicking a saved prompt inserts it into the chat input
- Stored in `localStorage` with key `nv_oos_saved_prompts`

### 7. Prompt Search

A **real-time search** input above the prompts panel:
- Filters saved prompts by title, category, or content fragment
- Debounced at 150ms for performance
- Keyboard shortcut: `Ctrl+K` / `Cmd+K` focuses search
- Empty state: "No prompts match your search. Save a prompt by starring a message."

## Implementation Details

### Base Chat UI (jQuery — `assets/js/chat.js`)

- All 7 features implemented in vanilla JS with jQuery DOM helpers
- No additional dependencies (code copy uses `document.execCommand` fallback)
- Dark mode respects `prefers-color-scheme` + `localStorage` override
- All strings translatable via `wp.i18n.__()`

### Pro React SPA (`addons/chat-spa/`)

- Profile card rendered as `<ProfileCard />` React component
- Stop generation uses `AbortController` API
- Feedback widget uses `<FeedbackWidget />` with optimistic UI updates
- Code copy uses `navigator.clipboard.writeText()` with fallback
- Dark mode managed via React context + CSS modules
- Saved prompts use IndexedDB via `idb` for larger storage
- Prompt search uses Fuse.js for fuzzy matching

## Filters & Events

| Filter | Type | Description |
|--------|------|-------------|
| `wp_mcp_ai_chat_show_profile_card` | bool | Enable/disable profile card display |
| `wp_mcp_ai_chat_show_feedback` | bool | Enable/disable feedback widget |
| `wp_mcp_ai_chat_dark_mode_default` | bool | Override default dark mode state |
| `wp_mcp_ai_chat_max_saved_prompts` | int | Limit saved prompts (default: 100) |

| JS Event | Trigger | Payload |
|----------|---------|---------|
| `wp_mcp_ai_chat_stop_generation` | Stop button clicked | `{ assistant_id, stream_id }` |
| `wp_mcp_ai_chat_feedback_submitted` | Thumbs up/down clicked | `{ message_id, rating, comment }` |
| `wp_mcp_ai_chat_dark_mode_toggled` | Theme toggle clicked | `{ enabled: bool }` |
| `wp_mcp_ai_chat_prompt_saved` | Message starred | `{ title, content, category }` |

## See Also

- [Chat SPA Addon](../../addons/chat-spa/README.md)
- [Continual Harness P5](../architecture/continual-harness.md)
- [Unified Blueprint System](unified-blueprint-system.md)
