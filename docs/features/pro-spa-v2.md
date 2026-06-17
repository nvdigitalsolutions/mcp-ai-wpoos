# Pro SPA v2

**Status:** Stable — v2.0.1  
**Category:** Pro Feature — Chat UI  
**Addon:** `addons/pro-spa-v2/`

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

## Version History

- **v2.0.1** (v1.1.31) — Admin layout fix within WordPress chrome, cache-bust version bump, production assets built and dist un-gitignored
- **v2.0.0** (v1.1.30) — Feature parity with chat-spa and old pro-spa. Rich markdown rendering, assistant scoping, agent selector. Conversations primary, threads read-only. CSS class-name fixes.
