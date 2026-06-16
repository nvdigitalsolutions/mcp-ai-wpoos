# Pro SPA v2 — Immediate Next Steps

> **Status:** Migration in progress. `pro-spa-v2` (TypeScript/esbuild/AI-SDK) is the target;
> the legacy `pro-spa` (webpack) is the reference for feature parity.

---

## 1. Current State Assessment

### ✅ Architecture (Complete)
- **TypeScript strict mode** — `tsconfig.json` matching chat-spa
- **esbuild** — IIFE bundle with CSS extraction, WordPress externals, dev/prod builds
- **React 19** — Direct 19.1.0 (not `@wordpress/element`)
- **AI SDK useChat** — `useChatSpoke` wraps `@ai-sdk/react`
- **SSE adapter** — Byte-identical to chat-spa's `sse-adapter.ts`
- **Zustand stores** — 3 stores: `uiStore`, `modelStore`, `commandStore`
- **Multi-instance mounting** — `querySelector`-based multi-root
- **Hash routing** — React Router v6 with `React.lazy` code splitting
- **Vitest + Testing Library** — Same config/patterns as chat-spa
- **Feature folder structure** — `features/{analytics,assistants,chat,settings,tools,workflows}`
- **a11y testing** — `@axe-core/react` in dev builds

### ✅ Feature Pages (Complete)
- **ChatPage** — Full implementation with `useChatSpoke`, thread loading, model/profile selectors
- **AgentPanel** — Message list + composer with keyboard shortcuts, streaming indicator
- **MessageView** — Markdown rendering, copy, regenerate actions
- **SettingsPage** — Full API key management, model defaults, debug toggles
- **ToolsPage** — Full search/filter/sort tool registry browser
- **AssistantsPage** — Full CRUD with modal form, model/prompt/capability editing

### ⚠️ Feature Pages (Stubs / Coming Soon)
- **WorkflowsPage** — Placeholder with "Coming Soon" messaging
- **AnalyticsPage** — Placeholder with "Coming Soon" messaging

### ✅ Layout Components (Complete)
- **Layout** — 3-column layout with HashRouter, transcripts sidebar, status bar
- **ChatSidebar** — Dual-tab (Conversations/Threads) with full CRUD
- **RightPanel** — Model/profile selectors with a11y
- **StatusBar** — Connection status, model, profile, thread count
- **CommandPalette** — Ctrl+K overlay with fuzzy search
- **Toast** — Notification system

### ✅ API Clients (Complete)
- `api/config.ts` — `readProSpaConfig()` typed runtime reader
- `api/transcripts.ts` — `TranscriptsClient` (list/get/save/delete)
- `api/threads.ts` — `ThreadsClient` (list/create/archive/restore/summarize)
- `api/tools.ts` — `ToolsClient` (list/execute)
- `api/settings.ts` — `SettingsClient` (get/update)
- `api/assistants.ts` — `AssistantsClient` (list/create/update/delete)
- `api/memory.ts` — `MemoryClient` (getPreferences, recall, store, delete, audit) ✅ **NEW**
- `api/hitl.ts` — `HitlClient` (listPending, approve, deny) ✅ **NEW**
- `api/types.ts` — Shared DTO re-exports ✅ **NEW**

### ✅ Hooks (Complete)
- `useBootstrap.ts` — Runtime config reader + nav command registration
- `useChatSpoke.ts` — AI SDK wrapper with transcript persistence
- `useTranscripts.ts` — Session management (list/select/create/delete)
- `useThreads.ts` — Thread CRUD operations
- `useSettings.ts` — Settings fetch/update
- `useTools.ts` — Tool registry fetch/execute
- `useAssistants.ts` — Assistant CRUD
- `useCommandPalette.ts` — Command palette state
- `useCopyToClipboard.ts` — Clipboard utility
- `useCheckpoints.ts` — Checkpoint fetch/restore operations ✅ **NEW**

### ✅ Shared Components (Complete)
- `MarkdownContent.tsx` — Safe markdown rendering
- `Toast.tsx` — Notification system
- `CommandPalette.tsx` — Ctrl+K fuzzy search overlay
- `MemoryDrawer.tsx` — 3-tab memory drawer (Memories/Scope/Audit) ✅ **NEW**
- `HitlApprovalBar.tsx` — HITL approval polling bar ✅ **NEW**
- `CheckpointBar.tsx` — Checkpoint restore/review bar ✅ **NEW**
- `DiffReviewPanel.tsx` — Diff hunk review panel (dialog, ESC to close) ✅ **NEW**
- `CollaborativePresence.tsx` — Live user presence avatars (15s polling) ✅ **NEW**
- `ModelComparisonView.tsx` — Multi-model response comparison (tabs, a11y) ✅ **NEW**

### ✅ Tests (Comprehensive)
- `__tests__/pro-spa.test.ts` — Config, session key, SSE adapter (10 tests)
- `__tests__/memory.test.tsx` — MemoryClient + MemoryDrawer component (31 tests) ✅ **NEW**
- `__tests__/stores.test.ts` — uiStore, modelStore, commandStore (24 tests) ✅ **NEW**
- `__tests__/hitl.test.tsx` — HitlClient + HitlApprovalBar (21 tests) ✅ **NEW**

**Total: 86 tests, all passing**

### ❌ Missing / Needs Work

| Missing Feature | Priority | Notes |
|----------------|----------|-------|
| ~~**Memory Drawer**~~ | ~~P0~~ | ✅ **DONE** — `MemoryDrawer.tsx` ported from chat-spa |
| ~~**HITL Approval Bar**~~ | ~~P0~~ | ✅ **DONE** — `HitlApprovalBar.tsx` ported from chat-spa |
| ~~**PHP Loader Update**~~ | ~~P0~~ | ✅ **DONE** — Updated to enqueue spa-v2 assets + NVOOS_PRO_SPA config |
| ~~**`api/memory.ts`**~~ | ~~P0~~ | ✅ **DONE** — `MemoryClient` with pro text domain |
| ~~**`api/hitl.ts`**~~ | ~~P0~~ | ✅ **DONE** — `HitlClient` with pro text domain |
| ~~**ChatPage memory/HITL wiring**~~ | ~~P0~~ | ✅ **DONE** — Memory toggle in toolbar, HITL bar between toolbar & AgentPanel |
| ~~**Shared types (`api/types.ts`)**~~ | ~~P1~~ | ✅ **DONE** — Central type re-exports |
| ~~**Additional CSS**~~ | ~~P1~~ | ✅ **DONE** — Memory drawer + HITL bar styles + theme vars |
| ~~**Thread sidebar → chat wiring**~~ | ~~P1~~ | ✅ **DONE** — ChatSidebar navigates via HashRouter, ChatPage reads from URL params |
| ~~**Theme support**~~ | ~~P1~~ | ✅ **DONE** — CSS custom properties with light/dark/auto, localStorage persistence |
| ~~**Comprehensive tests**~~ | ~~P1~~ | ✅ **DONE** — 86 tests across 4 files (stores, memory, HITL, config/SSE) |
| ~~**Inline Assistant**~~ | ~~P2~~ | ⚠️ **SKIPPED** — This is a Gutenberg block editor plugin (`wp.plugins`, `wp.data`, `PluginSidebar`), not an SPA component. It should remain as a standalone WordPress block editor plugin. It cannot be ported to the SPA without a complete redesign. |
| ~~**Checkpoints**~~ | ~~P2~~ | ✅ **DONE** — `CheckpointBar.tsx`, `DiffReviewPanel.tsx`, `useCheckpoints.ts` |
| ~~**Collaboration**~~ | ~~P2~~ | ✅ **DONE** — `CollaborativePresence.tsx` (direct fetch, polling) |
| ~~**Model Comparison**~~ | ~~P2~~ | ✅ **DONE** — `ModelComparisonView.tsx` (multi-tab comparison, a11y) |
| **Analytics page (real)** | **P2** | Replace placeholder with actual charts (API usage, token counts, costs) |
| **Workflows page (real)** | **P2** | Visual workflow builder |

---

## 2. Immediate Next Steps (Concrete Tasks)

### Task P0-1: Add `api/memory.ts`
**Files to create:** `src/api/memory.ts`
**Reference:** `addons/chat-spa/src/api/memory.ts`
- Port `MemoryClient` class with all methods (getPreferences, updatePreferences, recall, store, update, delete, audit)
- Port `readPersistedScope`, `persistScope`, `memoryScopeStorageKey` helpers
- Change text domain from `nvoos-chat-spa` to `nvoos-pro-spa`

### Task P0-2: Add `api/hitl.ts`
**Files to create:** `src/api/hitl.ts`
**Reference:** `addons/chat-spa/src/api/hitl.ts`
- Port `HitlClient` class (listPending, approve, deny)
- Port `ApprovalRecord` interface
- Change text domain from `nvoos-chat-spa` to `nvoos-pro-spa`

### Task P0-3: Add MemoryDrawer component
**Files to create:** `src/components/shared/MemoryDrawer.tsx`
**Reference:** `addons/chat-spa/src/components/MemoryDrawer.tsx`
- Port the full drawer (3 tabs: Memories, Scope, Audit)
- Use `src/api/memory.ts` client
- Change text domain to `nvoos-pro-spa`
- Use pro BEM class prefix `nvoos-pro-spa-memory-drawer`

### Task P0-4: Add HitlApprovalBar component
**Files to create:** `src/components/shared/HitlApprovalBar.tsx`
**Reference:** `addons/chat-spa/src/components/HitlApprovalBar.tsx`
- Port the approval bar with polling, approve/deny buttons
- Use `src/api/hitl.ts` client
- Change text domain to `nvoos-pro-spa`
- Use pro BEM class prefix `nvoos-pro-spa-hitl-bar`

### Task P0-5: Wire Memory and HITL into ChatPage
**Files to edit:** `src/features/chat/ChatPage.tsx`
- Add MemoryDrawer toggle button in the chat toolbar
- Add HitlApprovalBar below the message list / above composer
- Read `endpoints.memory` and `endpoints.approvals` from runtime

### Task P0-6: Update PHP Loader for spa-v2
**Files to edit:** `addons/pro/includes/class-wp-mcp-ai-pro-spa-loader.php`
- Change asset paths from `assets/spa/dist/spa-bundle.js` to `assets/spa-v2/assets/dist/pro-spa.js`
- Change CSS path to `assets/spa-v2/assets/dist/pro-spa.css`
- Update `wp_localize_script` to use `NVOOS_PRO_SPA` global (matching `api/config.ts`)
- Add all endpoint URLs (chatClient, transcripts, memory, threads, tools, assistants, settings, workflows, analytics, approvals)
- Add mention types and user data

### Task P1-1: Add `api/types.ts` (Shared DTOs)
**Files to create:** `src/api/types.ts`
- Re-export all types from `api/*.ts` for convenient single-import

### Task P1-2: Add CSS for MemoryDrawer and HitlApprovalBar
**Files to edit:** `src/styles/main.css`
- Add BEM styles for memory drawer (`.nvoos-pro-spa-memory-drawer-*`)
- Add BEM styles for HITL bar (`.nvoos-pro-spa-hitl-bar-*`)
- Reference: chat-spa's CSS patterns

### Task P1-3: Expand Tests
**Files to create/edit:** `src/__tests__/`
- Add tests for MemoryClient, HitlClient
- Add tests for MemoryDrawer rendering
- Add tests for HitlApprovalBar rendering/polling
- Add tests for ChatPage interaction flow

### Task P2-1: Inline Assistant
**Files to create:** `src/components/shared/InlineAssistant.tsx`
**Reference:** `addons/pro/assets/spa/src/components/chat/InlineAssistant.jsx`
- Port the inline code assistant with TypeScript

### Task P2-2: Checkpoints
**Files to create:** `src/features/chat/CheckpointBar.tsx`, `src/features/chat/DiffReviewPanel.tsx`
**Reference:** `addons/pro/assets/spa/src/components/checkpoints/`

### Task P2-3: Collaboration Presence
**Files to create:** `src/components/shared/CollaborativePresence.tsx`
**Reference:** `addons/pro/assets/spa/src/components/collaboration/CollaborativePresence.jsx`

### Task P2-4: Model Comparison View
**Files to create:** `src/components/shared/ModelComparisonView.tsx`
**Reference:** `addons/pro/assets/spa/src/components/models/ModelComparisonView.jsx`

---

## 3. Build & Test Commands

```bash
# Navigate to spa-v2 directory
cd addons/pro/assets/spa-v2

# Install dependencies
npm install

# TypeScript type checking
npm run typecheck

# Run tests
npm test

# Build for production
npm run build

# Build for development
npm run build:dev

# Watch mode
npm run watch
```

---

## 4. What NOT to Do

- ❌ Do NOT reintroduce `@wordpress/scripts`/webpack
- ❌ Do NOT put chat streaming state into Zustand (useChat owns it)
- ❌ Do NOT change from IIFE build format
- ❌ Do NOT reduce scope to chat-only widget (preserve all feature pages)
- ❌ Do NOT remove React.lazy code splitting
- ❌ Do NOT hardcode CSS — use BEM with `nvoos-pro-spa-` prefix
- ❌ Do NOT lose the chat-spa text domain mapping (change `nvoos-chat-spa` → `nvoos-pro-spa` on port)
