# Pro SPA v2

**Status:** Stable — v2.1.0  
**Category:** Pro Feature — Chat UI  
**Addon:** `addons/pro-spa-v2/`  
**Last Updated:** July 13, 2026 (v1.1.39)

## Overview

Pro SPA v2 is the next-generation React-based single-page application chat interface for NV oOS Pro. It replaces the v1 Pro SPA with rich markdown rendering, per-assistant scoping, and an agent selector dropdown.

## Architecture

### Conversations vs. Threads

| Concept | Description | Editability |
|---------|-------------|-------------|
| **Conversations** | Primary chat sessions. Active, mutable. | Full CRUD (edit, delete, regenerate, copy) |
| **Threads** | Archived conversation snapshots. | Read-only |

### Component Model

- **HashRouter** lifted to Layout component (fixes router context error in v1)
- **Assistant Scoping** — dropdown filters conversations/threads by assistant
- **Agent Selector** — choose which agent profile drives the current session
- **Rich Markdown Rendering** — full CommonMark + GFM (tables, code blocks with syntax highlighting, task lists)

## Key Features

- **Message Actions** (Phase 8): edit, delete, regenerate, copy, and content enrichment cards on every assistant message
- **Auto-create Thread** — posting a message to a non-existent thread auto-creates it
- **Model & Profile Passing** — model and profile selections passed to `createThread` for accurate context
- **WordPress Admin Chrome** — layout fits within the WordPress admin sidebar/footer
- **CSS Module System** — scoped class names preventing style leakage

## Migration from v1

| v1 | v2 |
|----|----|
| Inline CSS classes | CSS modules with hashed names |
| Flat component tree | HashRouter at Layout level |
| Threads as primary view | Conversations primary, threads read-only |
| No assistant scoping | Per-assistant filter dropdown |
| No agent selector | Agent selector in chat header |
| Plain text responses | Rich markdown rendering |

## REST Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `POST` | `/threads` | Create a new thread |
| `POST` | `/threads/{id}/messages` | Post a message to a thread |
| `GET` | `/threads` | List threads (scoped by assistant) |
| `GET` | `/bootstrap` | SPA bootstrap (tools, config, turn count) |

## v2.1.0 — Polish & Fixes (v1.1.39, July 2026)

### Vector Store & Autocomplete
- Vector store indicator and slash autocomplete positioning fixed (PR #5666)
- Double path in vector store preload URL fixed (PR #5665)
- TDZ crash in `CommandAutocomplete` resolved by hoisting `activeIndex` declaration (PR #5664)
- Inline command autocomplete and Zed-style refresh added (PR #5663)

### Cost & Configuration
- Cost badges restored in response UI (PR #5661)
- `allowSensitiveTools` config type definitions added and propagated to chat-spa, Pro SPA v2 frontends, and delegation dispatch (PR #5658)

### Tool Results & Rendering
- Rich rendering for embedded tool results (PR #5651)
- Sidebar auto-refresh on new data (PR #5651)
- Media cache-busting via `filemtime` (PR #5651)
- Auto-save transcripts on `onFinish` callback (PR #5650)

### Attachments & Storage
- Attachment support with upload to WordPress Media Library (PR #5646)
- Save button for conversation persistence (PR #5646)
- Storage display showing media usage statistics (PR #5646)

### Tasks Drawer
- Toolbar button with failedCount badge (PR #5642)
- Delegation tasks listing with status indicators
- Retry mechanism for failed tasks

### Speech & Audio
- Response envelope fix in Pro SPA v2 and Chat SPA (PR #5636)
- Media insert button visibility restored (PR #5632)
- Speech button moved into message toolbar (PR #5632)

### Rendering Fixes
- Capability flags rendering fixed (PR #5635)
- Usage badges and image+text rendering fixed (PR #5634)
- Annotations and toolInvocations preserved in `initialMessages` mapping (PR #5633)
- Cost/model extracted from data annotations into usage badges (PR #5634)
- Truncated tool results now collapsible when loaded from CCT (PR #5633)

### Sidebar & Media
- Sidebar media panel visibility fixed (PR #5633)
- Date column replaced with ID column (PR #5633)
- Media-to-chat bridge restored (PR #5631)
- Sidebar empty state handling (PR #5631)

### System Prompt & State
- System prompt leak fixed (PR #5631)
- Tool display and conversation duplication fixed (PR #5626)

### Layout & Mobile
- Composer mobile bottom padding added so toolbar icons are visible (PR #5625)
- Media grid uses flexbox with fixed column widths instead of CSS grid to prevent overflow (PR #5624)
- Mobile hamburger button to open sidebar (from v1.1.38)
- Viewport height fixes via CSS height chain (from v1.1.38)
- `overflow:hidden` on height-chain ancestors (from v1.1.38)
- `filemtime` cache-busting across all SPA addons (from v1.1.38)

## Version History

- **v2.1.0** (v1.1.39) — 20+ PRs of polish and fixes: vector store/autocomplete, cost badges, allowSensitiveTools, tool result rendering, auto-save transcripts, attachments/storage, tasks drawer toolbar, speech/audio, capability flags, usage badges, sidebar/media, system prompt, layout/mobile
- **v2.0.1** (v1.1.31) — Admin layout fix within WordPress chrome, cache-bust version bump, production assets built and dist un-gitignored
- **v2.0.0** (v1.1.30) — Feature parity with chat-spa and old pro-spa. Rich markdown rendering, assistant scoping, agent selector. Conversations primary, threads read-only. CSS class-name fixes.
