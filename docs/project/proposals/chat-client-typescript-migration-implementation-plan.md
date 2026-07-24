# Chat-Client TypeScript Migration — Implementation Plan

**Date:** 2026-07-24
**Status:** Proposal — implementation not yet started
**Related:** [`chat-client-typescript-migration-current-state.md`](./chat-client-typescript-migration-current-state.md), [`cross-platform-extraction-gap-analysis.md`](./cross-platform-extraction-gap-analysis.md)

---

## Table of Contents

1. [Research & Best Practices](#1-research--best-practices)
2. [Architecture Design](#2-architecture-design)
3. [Module Map: chat.js → TypeScript](#3-module-map-chatjs--typescript)
4. [Phased Migration Plan](#4-phased-migration-plan)
5. [Build Pipeline](#5-build-pipeline)
6. [Testing Strategy](#6-testing-strategy)
7. [Backward Compatibility Contract](#7-backward-compatibility-contract)
8. [Risk Assessment & Mitigation](#8-risk-assessment--mitigation)
9. [Timeline & Effort Estimate](#9-timeline--effort-estimate)
10. [Success Criteria](#10-success-criteria)

---

## 1. Research & Best Practices

### 1.1 Strangler Fig Pattern (Martin Fowler, 2004)

The same pattern proven by the `lib/core` PHP extraction applies here. The principle: **extract incrementally, keep the legacy working, remove only when the new path is proven.**

```
Phase 1:  chat.js ──► chat-core.ts (delegates back to chat.js for unextracted pieces)
Phase 2:  chat.js ──► chat-core.ts + streaming.ts (fewer delegations)
Phase N:  chat.js ──► all TS modules (chat.js is a thin shell)
End:      chat.js deleted, chat-bundle.js imports only from src/
```

### 1.2 Industry References

| Source | Key Principle | Application |
|---|---|---|
| **Martin Fowler, Strangler Fig** (2004) | Incremental replacement, route new calls to new code | Each TS module registered on `window.wpMcpAiChat*`; chat.js delegates when present |
| **Google TypeScript Style Guide** | `interface` over `type` for object shapes, strict null checks | All module boundaries use interfaces; `strict: true` in tsconfig |
| **WordPress JavaScript Standards** | `@wordpress/eslint-plugin` rules, tabs for indentation | Match existing `.eslintrc.json` for consistency |
| **Addy Osmani, "JavaScript Design Patterns"** | Module pattern, revealing module pattern for public API | Each TS module exports a clean public API; registers on `window` for backward compat |
| **React 19 / AI SDK patterns** (Pro SPA v2, chat-spa) | SSE adapter, Zustand stores, hooks | Inform but don't dictate — widget is vanilla TS, not React |
| **Pro SPA v2 shared logic** (in-repo reference) | `sse-adapter.ts` (597 LOC), `normalise-tool-result.ts` (465 LOC) — framework-agnostic, pure TS | **Extract to shared NPM packages before widget migration** to avoid duplicating SSE frame translation and tool-result normalization |
| **Unix Theory P0–P6** (project conventions) | Single responsibility, canonical return envelope, two-gate sanitize | Each module handles one concern; typed return types replace envelope checks |
| **chat.js current size** (measured 2026-07-24) | 20,684 lines total; ~11,800 extractable logic; ~8,900 IIFE/fallback dead weight | 16-module extraction plan targets the ~11,800 LOC of core logic, not the scaffolding that dissolves on extraction |

### 1.3 Key Design Decisions

| Decision | Rationale |
|---|---|
| **Stay vanilla TypeScript (no React)** | The widget must embed in arbitrary pages with minimal overhead. React adds ~40KB gzipped and conflicts with multiple instances. The existing DOM-based approach works well. |
| **One TypeScript module per concern** | Matches the single-responsibility principle already proven by the service extraction. Makes modules independently testable. |
| **Register on `window.wpMcpAiChat*` namespace** | Same pattern used by all 11 extracted service modules. Backward-compatible. chat.js already checks these globals. |
| **esbuild for bundling** | Same toolchain as Pro SPA v2, chat-spa, and all toolkit addons. Fast, produces IIFE, handles TypeScript natively. |
| **Vitest for testing** | Same as Pro SPA v2 (86 tests) and chat-spa. JSDOM for DOM-dependent tests. |
| **Interface-first export** | Each module exports a TypeScript interface and a concrete implementation. Allows mocking in tests and future DI. |

### 1.4 Pre-Migration: Extract Pro SPA v2 Shared Logic

Two Pro SPA v2 modules contain framework-agnostic logic that should be extracted to shared NPM packages **before** the widget migration duplicates them:

| Pro SPA v2 Source | Extract To | Rationale |
|---|---|---|
| `sse-adapter.ts` — `translateFrame()`, `parseSseBuffer()` | `nvoos-sse-client` (existing) | Pure data transform; widget needs identical SSE frame parsing |
| `utils/normalise-tool-result.ts` — `normaliseToolResult()` | `nvoos-client-tools` (existing) | Pure function; mirrors chat.js; widget needs identical normalization |

This extraction is **non-blocking** for Phase 1 (state + rendering) but should complete before Phase 2 (streaming + tool-execution).

---

## 2. Architecture Design

### 2.1 Target Structure

```
assets/js/src/
├── shared/
│   ├── types.ts              ✅ Already exists
│   ├── api.ts                ✅ Already exists
│   └── wp-rest.ts            ✅ Already exists
│
├── services/                 ✅ All 11 exist
│   ├── attachments.ts
│   ├── audio.ts
│   ├── clipboard.ts
│   ├── http-client.ts
│   ├── markdown.ts
│   ├── memory-service.ts
│   ├── sse.ts
│   ├── storage.ts
│   ├── storage-util.ts
│   ├── transcription.ts
│   └── ui-utilities.ts
│
└── chat/                     🟡 Only memory-drawer.ts exists
    ├── memory-drawer.ts      ✅ Already exists
    ├── types.ts              ← NEW: Chat-specific types
    ├── state.ts              ← NEW: State management (Phase 1)
    ├── rendering.ts          ← NEW: Message rendering (Phase 1)
    ├── streaming.ts          ← NEW: SSE stream processing (Phase 2)
    ├── tool-execution.ts     ← NEW: Sync + async tool execution (Phase 2)
    ├── chat-core.ts          ← NEW: Orchestrator (Phase 3)
    ├── attachments-core.ts   ← NEW: Upload pipeline (Phase 3)
    ├── speech-audio.ts       ← NEW: TTS/STT/voice (Phase 4)
    ├── transcription-core.ts ← NEW: Transcription pipeline (Phase 4)
    ├── voice-chat.ts         ← NEW: Voice chat (Phase 4)
    ├── history.ts            ← NEW: History/transcripts (Phase 5)
    ├── tool-results.ts       ← NEW: Tool result formatters (Phase 5)
    ├── agent-panel.ts        ← NEW: Agent panel + workflow (Phase 5)
    ├── message-handling.ts   ← NEW: Message strip/normalize (Phase 5)
    ├── embedded-llm.ts       ← NEW: Embedded LLM client (Phase 6)
    ├── cron-tasks.ts         ← NEW: Tasks drawer + toasts (Phase 6)
    ├── chat-session-stream.ts← NEW: Session SSE continuation (Phase 6)
    └── keyboard-darkmode.ts  ← NEW: Keyboard shortcuts + dark mode (Phase 6)
```

### 2.2 Module Dependency Graph

```
                        ┌─────────────┐
                        │  chat-core  │ (orchestrator)
                        └──────┬──────┘
                               │
          ┌────────────────────┼────────────────────┐
          │                    │                    │
   ┌──────▼──────┐    ┌───────▼───────┐    ┌───────▼──────┐
   │  streaming  │    │    state      │    │ rendering    │
   └──────┬──────┘    └───────────────┘    └──────┬──────┘
          │                                        │
   ┌──────▼──────┐                         ┌──────▼──────┐
   │ tool-exec   │                         │ speech-audio│
   └──────┬──────┘                         │ transcription│
          │                                │ voice-chat  │
   ┌──────▼──────┐                         └─────────────┘
   │tool-results │
   └─────────────┘

   ┌──────────────┐    ┌──────────────┐    ┌──────────────┐
   │  history     │    │agent-panel   │    │message-handle│
   └──────────────┘    └──────────────┘    └──────────────┘

   ┌──────────────┐    ┌──────────────┐    ┌──────────────┐
   │embedded-llm  │    │ cron-tasks   │    │session-stream│
   └──────────────┘    └──────────────┘    └──────────────┘

   ┌──────────────┐
   │keyboard-dark │
   └──────────────┘

   Shared dependencies (all modules):
   ├── shared/types.ts
   ├── shared/api.ts
   ├── services/*.ts
   └── chat/types.ts
```

### 2.3 Interface-First Design Pattern

Every module exports a clear interface. Example for `rendering.ts`:

```typescript
// chat/rendering.ts

import type { ChatMessage, DisplayPayload, AppendMessageOptions } from './types';

export interface MessageRenderer {
    appendMessage(
        listEl: HTMLElement,
        role: 'user' | 'assistant' | 'tool' | 'system',
        payload: DisplayPayload,
        allowMarkdown: boolean,
        options?: AppendMessageOptions,
    ): HTMLElement | null;

    renderMarkdown(text: string): string;
    escapeHtml(text: string): string;
    sanitizeUrl(url: string): string;
}

export function createMessageRenderer(
    markdownService?: MarkdownService,
): MessageRenderer;
```

---

## 3. Module Map: chat.js → TypeScript

### 3.1 State Management (`chat/state.ts`) — Phase 1

**Extracts from chat.js:**
- State object initialization (~60 properties)
- `saveConversationToStorage()` and fallback
- `loadConversationFromStorage()` and fallback
- `clearConversationFromStorage()` and fallback
- `restoreConversationFromStorage()`
- `saveConversationToCCT()` with retry logic
- `cleanupOldStorageEntries()` and fallback
- `getLocalStorageQuota()` / `getStorageKey()` / `sanitizeSessionKey()` / `formatBytes()`

**Dependencies:** `nvoos-storage` (TS), `shared/types.ts`

**TypeScript interface:**
```typescript
export interface ChatStateManager {
    createState(config: GlobalChatConfig, domElements: DomElements): ChatInstanceState;
    saveToStorage(state: ChatInstanceState, options?: { immediate?: boolean }): SaveResult;
    loadFromStorage(state: ChatInstanceState): LoadResult | null;
    clearFromStorage(state: ChatInstanceState): void;
    restoreFromStorage(state: ChatInstanceState): void;
    saveToCCT(state: ChatInstanceState, options?: SaveOptions): Promise<SaveResult>;
    cleanupOldEntries(): number;
}
```

### 3.2 Message Rendering (`chat/rendering.ts`) — Phase 1

**Extracts from chat.js:**
- `appendMessage()` — the core rendering function
- `renderMarkdown()` and fallback
- `escapeHtml()` and fallback
- `formatInline()` and fallback
- `sanitizeUrl()` and fallback
- `renderInlineLabel()` and fallback
- `replaceAll()`
- `createJsonResponseElement()`
- `createTruncatedResponseElement()`
- `createChartBlockElement()`
- `shouldDisplayJsonResponse()` / `isLikelyJson()` / `isTruncatedByOrchestration()`
- `getVideoMimeType()` / `isVideoAttachment()` / `sanitizeAttachmentUrl()` / `sanitizeToolResultUrl()`
- `normaliseContent()` / `extractTextFromContent()` / `extractNestedText()` / `dedupeTextParts()`
- `renderContentPiece()` / `renderReasoningSegment()` / `renderFunctionCallSegment()`
- `buildAttachmentMeta()` / `buildFileDownloadUrl()` / `getAttachmentUrlFromRecord()`
- `createObjectUrlFromBase64()`
- `buildDisplayAttachment()` / `createSegmentFromAttachment()` / `isRealAttachmentUrl()` / `stripSegmentDisplayData()`
- `normaliseList()` / `getFileExtension()` / `isFileTypeAllowed()`
- `registerObjectUrl()` / `revokeObjectUrls()`

**Dependencies:** `nvoos-markdown` (TS), `shared/types.ts`, `chat/types.ts`

### 3.3 SSE Streaming (`chat/streaming.ts`) — Phase 2

**Extracts from chat.js:**
- `sendChatStreaming()`
- `processSSEStream()` — the core SSE reader loop
- `handleStatusEvent()` — status SSE frames (thinking, generating, tool_execution, etc.)
- `handleToolExecutionEvent()` — tool_execution SSE frames
- `handleErrorEvent()` — error SSE frames
- `createStreamingMessage()` / `updateStreamingMessage()` / `updateStreamingStatus()`
- `streamingLogger` — diagnostic logger
- `getMessagesEndpoint()`

**Dependencies:** `nvoos-events` (TS), `nvoos-sse-client` (TS), `shared/types.ts`, `chat/rendering.ts`

### 3.4 Tool Execution (`chat/tool-execution.ts`) — Phase 2

**Extracts from chat.js:**
- `waitForAsyncToolResult()` — main entry point
- `waitForAsyncToolResultSSE()` — SSE-first approach
- `waitForAsyncToolResultPolling()` — REST fallback
- `waitForAsyncToolResultWithEventBus()` — event bus approach
- `displayAsyncToolResult()` — renders completed async results
- `fetchAsyncToolResult()` — REST status check
- `attemptTimeoutRecovery()` — final recovery check
- `executeToolViaOrchestrator()` — embedded LLM tool execution
- `displayToolResult()` / `showToolLoadingIndicator()` / `hideToolLoadingIndicator()`
- `startAsyncToolPolling()` / `createJobProgressCard()`
- `isAsyncPendingToolResult()` / `parseToolResultContent()`
- `ASYNC_PENDING_STATUSES` / timeout constants

**Dependencies:** `nvoos-events` (TS), `nvoos-http-client` (TS), `shared/types.ts`, `chat/state.ts`, `chat/rendering.ts`

### 3.5 Chat Core (`chat/chat-core.ts`) — Phase 3

**Extracts from chat.js:**
- `init()` — main initialization
- `handleSubmit()` — form submission
- `sendChat()` — main send function
- `sendChatEmbedded()` / `sendChatEmbeddedInternal()` — embedded LLM path
- `disableForm()` / `updateSubmitButtonForSend()` / `updateSubmitButtonForStop()`
- `setStatus()` / `clearStatus()` / `formatElapsedTime()`
- `scrollBatcher` / `domUpdateBatcher` / `quotaMonitorCache`
- `formatBytes()` / `formatDuration()` / `formatString()` / `getString()`
- `queueMessageForBundling()` / `sendBundledMessages()`
- `startNewConversation()` / `performConversationClear()`
- `handleSaveConversation()` / `handleExportConversation()` / `exportConversation()` / `downloadFile()`
- `extractConversationData()` / `handleCptActionClick()` / `storeToolResultForCptActions()`
- `renderToolShortcuts()` / `handleToolShortcutClick()` / `toggleToolShortcuts()`
- `renderCptActionButtons()` / `renderSuggestedPrompts()`
- `preloadVectorStore()` / `requestWakeUpContext()`
- `loadSessionIntoChat()` (public API)
- `wpMcpAiTestGetTranscript()` (console utility)

**Dependencies:** All chat/* modules, all services, `shared/types.ts`

### 3.6 Remaining Modules — Phases 4–6

| Module | Phase | Key Functions Extracted |
|---|---|---|
| `attachments-core.ts` | 3 | `handleFileSelection()`, `uploadAttachment()`, `prepareAttachment()`, `renderPendingAttachments()`, `removePendingAttachment()`, `updateAttachButtonState()`, `normaliseUploadResponse()`, `createContentDispositionHeader()`, `encodeRFC5987ValueChars()` |
| `speech-audio.ts` | 4 | Speech button lifecycle, `requestSpeechAudio()`, audio cache, play/stop |
| `transcription-core.ts` | 4 | `handleTranscribeButtonClick()`, recorder lifecycle, upload + request transcription |
| `voice-chat.ts` | 4 | `handleVoiceChatButtonClick()`, recorder, `processVoiceChatAudio()` |
| `history.ts` | 5 | History sidebar, session loading, CRUD, search, pagination |
| `tool-results.ts` | 5 | ~15 tool-specific result formatters (Crawl4AI, charts, site health, web search, deep research, environment, video, JetEngine, etc.) |
| `agent-panel.ts` | 5 | Agent team panel, workflow tracker, delegation notices |
| `message-handling.ts` | 5 | `extractDisplayMetadata()`, `createConversationMessage()`, `stripMessageDisplayMetadata()`, `stripContentDisplayData()`, `stripToolResultLargeContent()`, `ensureFinalMessagesPresent()`, `handleChatResponse()`, `extractFilteredResponseNotice()`, `prepareAssistantDisplay()`, `extractAttachmentsFromMessage()`, `buildAttachmentLookup()`, `findToolResultInConversation()`, `parseToolMessagePayload()`, `normaliseToolResultForDisplay()`, `normaliseArrayToolResult()`, `extractInlineContentData()` |
| `embedded-llm.ts` | 6 | `generateEmbeddedCompletion()`, `trackEmbeddedUsage()`, system prompt assembly |
| `cron-tasks.ts` | 6 | `initializeCronStatus()`, `initTasksDrawer()`, `initializeGlobalJobListeners()`, tasks drawer, toast system, tab badge |
| `chat-session-stream.ts` | 6 | `initChatSessionStream()`, `resolveChatSessionId()`, async continuation SSE |
| `keyboard-darkmode.ts` | 6 | `initializeKeyboardShortcuts()`, `toggleKeyboardShortcutsHelp()`, `initDarkMode()`, `updateDarkToggleIcon()` |

---

## 4. Phased Migration Plan

### Phase 1 — Foundation: State + Rendering (Week 1–2)

**Goal:** Extract the two foundational modules that all other modules depend on.

**Modules:**
- `chat/types.ts` — chat-specific type definitions
- `chat/state.ts` — state management (create, save, load, clear, restore)
- `chat/rendering.ts` — message rendering (appendMessage, markdown, escaping)

**Backward compat:**
- `window.wpMcpAiChatState` ← state module
- `window.wpMcpAiChatRendering` ← rendering module

**Verification:** chat.js checks for `window.wpMcpAiChatState` and `window.wpMcpAiChatRendering`, delegates `saveConversationToStorage()`, `loadConversationFromStorage()`, `appendMessage()`, `renderMarkdown()`, `escapeHtml()` etc. Unit tests for both modules.

### Phase 2 — Streaming + Tool Execution (Week 3–4)

**Goal:** Extract the SSE streaming pipeline and async tool execution, which depend on rendering (Phase 1) and SSE/HTTP services (already done).

**Modules:**
- `chat/streaming.ts`
- `chat/tool-execution.ts`

**Backward compat:**
- `window.wpMcpAiChatStreaming` ← streaming module
- `window.wpMcpAiChatToolExecution` ← tool execution module

**Verification:** Streaming and async tool execution work through TS modules. chat.js delegates `sendChatStreaming()`, `processSSEStream()`, `waitForAsyncToolResult()`.

### Phase 3 — Orchestrator + Attachments (Week 5–6)

**Goal:** Extract the chat orchestrator (`chat-core.ts`) that wires everything together, plus the attachment upload pipeline.

**Modules:**
- `chat/chat-core.ts`
- `chat/attachments-core.ts`

**Backward compat:**
- `window.wpMcpAiChatCore` ← chat core module
- `window.wpMcpAiChatAttachmentsCore` ← attachments core module

**Verification:** Full chat flow works through TS orchestrator. chat.js `init()` delegates to `wpMcpAiChatCore.init()`. Attachment upload uses TS module.

### Phase 4 — Speech, Transcription, Voice (Week 7–8)

**Goal:** Extract audio-related modules.

**Modules:**
- `chat/speech-audio.ts`
- `chat/transcription-core.ts`
- `chat/voice-chat.ts`

### Phase 5 — History, Tools, Agent Panel, Messages (Week 9–10)

**Goal:** Extract the remaining medium-complexity modules.

**Modules:**
- `chat/history.ts`
- `chat/tool-results.ts`
- `chat/agent-panel.ts`
- `chat/message-handling.ts`

### Phase 6 — Embedded LLM, Cron/Tasks, Session Stream, Keyboard (Week 11–12)

**Goal:** Extract the final modules. At this point, chat.js becomes a thin shell.

**Modules:**
- `chat/embedded-llm.ts`
- `chat/cron-tasks.ts`
- `chat/chat-session-stream.ts`
- `chat/keyboard-darkmode.ts`

### Phase 7 — Cleanup & Hardening (Week 13–14)

**Goal:** Remove inline fallbacks from chat.js, finalize build pipeline, expand test coverage.

**Tasks:**
- Remove all inline fallback implementations from `chat.js`
- `chat.js` becomes a thin shell that only imports and delegates
- Update `chat-bundle.js` to import from `src/` instead of flat JS files
- Replace flat JS service files with re-exports from TS
- Integration tests for the full widget
- Performance benchmarks vs. current chat.js
- Documentation updates

---

## 5. Build Pipeline

### 5.1 Target Configuration

```javascript
// esbuild.config.js — modeled after addons/pro/assets/spa-v2/esbuild.config.cjs

require('esbuild').build({
    entryPoints: ['assets/js/src/chat-bundle-entry.ts'],
    bundle: true,
    outfile: 'assets/js/chat-bundle.js',
    format: 'iife',
    target: ['es2020'],
    platform: 'browser',
    external: ['wp', 'jQuery'],
    define: {
        'process.env.NODE_ENV': JSON.stringify(process.env.NODE_ENV || 'production'),
    },
    minify: process.env.NODE_ENV === 'production',
    sourcemap: true,
}).catch(() => process.exit(1));
```

### 5.2 Bundle Entry Point

```typescript
// assets/js/src/chat-bundle-entry.ts

// 1. Core services (no dependencies)
import './services/storage-util';
import './services/sse';
import './services/http-client';

// 2. Service modules
import './services/markdown';
import './services/clipboard';
import './services/audio';
import './services/transcription';
import './services/attachments';
import './services/memory-service';
import './services/ui-utilities';

// 3. Chat modules (in dependency order)
import './chat/types';
import './chat/state';
import './chat/rendering';
import './chat/streaming';
import './chat/tool-execution';
import './chat/attachments-core';
import './chat/speech-audio';
import './chat/transcription-core';
import './chat/voice-chat';
import './chat/history';
import './chat/tool-results';
import './chat/agent-panel';
import './chat/message-handling';
import './chat/embedded-llm';
import './chat/cron-tasks';
import './chat/chat-session-stream';
import './chat/keyboard-darkmode';
import './chat/chat-core';
import './chat/memory-drawer';

// 4. Legacy chat.js as thin shell (eventually removed)
import '../chat.js';
```

### 5.3 TypeScript Configuration

```json
// tsconfig.json (additions to existing)
{
    "compilerOptions": {
        "strict": true,
        "target": "ES2020",
        "module": "ESNext",
        "moduleResolution": "bundler",
        "outDir": "./assets/js/dist",
        "rootDir": "./assets/js/src",
        "declaration": true,
        "declarationMap": true,
        "sourceMap": true
    },
    "include": ["assets/js/src/**/*.ts"]
}
```

---

## 6. Testing Strategy

### 6.1 Test Pyramid

```
     ┌──────────┐
     │   E2E    │  Playwright: full widget in WP environment (few)
     ├──────────┤
     │Integration│ Vitest + JSDOM: chat-core + rendering + streaming (some)
     ├──────────┤
     │   Unit   │  Vitest: each module in isolation (many)
     └──────────┘
```

### 6.2 Unit Tests per Module

| Module | Test File | Key Test Cases |
|---|---|---|
| `chat/state.ts` | `chat/state.test.ts` | create/destroy state, save/load localStorage, quota exceeded recovery, CCT save retry, session key rotation |
| `chat/rendering.ts` | `chat/rendering.test.ts` | appendMessage for each role, markdown → safe HTML, XSS vectors blocked, JSON/truncated/chart elements, video detection, URL sanitization |
| `chat/streaming.ts` | `chat/streaming.test.ts` | SSE event parsing (OpenAI, Gemini, Anthropic, Ollama formats), status events, tool_execution events, error events, [DONE] marker, stream abort, SSE→non-streaming fallback |
| `chat/tool-execution.ts` | `chat/tool-execution.test.ts` | sync tool completion, async pending, async completion, polling timeout, SSE fallback to REST, event bus integration, tool_call_id preservation |
| `chat/chat-core.ts` | `chat/chat-core.test.ts` | full send→stream→render cycle, form disable/enable, message bundling, new conversation, export, embedded LLM path, error recovery |

### 6.3 Test Infrastructure

```typescript
// vitest.config.ts — matches Pro SPA v2 pattern
import { defineConfig } from 'vitest/config';

export default defineConfig({
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./assets/js/src/test-setup.ts'],
        include: ['assets/js/src/**/*.test.ts'],
    },
});
```

### 6.4 Existing Tests to Preserve

The project already has Jest tests for individual services under `tests/js/`. These should be migrated to Vitest and co-located with the TS source:

```
assets/js/src/
├── services/
│   ├── storage.test.ts       ← migrate from tests/js/
│   ├── markdown.test.ts      ← migrate from tests/js/
│   └── ...
└── chat/
    ├── state.test.ts
    ├── rendering.test.ts
    └── ...
```

---

## 7. Backward Compatibility Contract

### 7.1 Window Namespace Registry

Every extracted module registers on `window` so `chat.js` can delegate. This is identical to the pattern already used by the 11 service modules:

```typescript
// chat/rendering.ts
import type { MessageRenderer } from './types';

const renderer: MessageRenderer = createMessageRenderer(/* deps */);

// Register for chat.js compatibility
(window as any).wpMcpAiChatRendering = {
    appendMessage: renderer.appendMessage.bind(renderer),
    renderMarkdown: renderer.renderMarkdown.bind(renderer),
    escapeHtml: renderer.escapeHtml.bind(renderer),
    sanitizeUrl: renderer.sanitizeUrl.bind(renderer),
};
```

### 7.2 chat.js Delegation Check

chat.js already uses this pattern for extracted services. The same check is added for each new module:

```javascript
// In chat.js (Phase 1 example)
const renderingService = window.wpMcpAiChatRendering || null;

function appendMessage(listEl, role, payload, allowMarkdown, options) {
    if (renderingService && renderingService.appendMessage) {
        return renderingService.appendMessage(listEl, role, payload, allowMarkdown, options);
    }
    // ... fallback (removed in Phase 7)
}
```

### 7.3 No Breaking Changes

- All existing `window.wpMcpAi*` globals continue to work
- `chat.js` public API (`window.wpMcpAiChatInit.init()`, `window.wpMcpAiLoadSession()`, `window.wpMcpAiTestGetTranscript()`) unchanged
- CSS class names unchanged
- REST endpoint contracts unchanged
- Shortcode/block/Elementor widget integration unchanged

---

## 8. Risk Assessment & Mitigation

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **chat.js regression** — TS module has subtle behavioral difference from inline fallback | Medium | High | Extract exact logic; compare DOM output in tests; run existing Jest tests against both paths |
| **Performance regression** — TS→JS bundle larger or slower than current concatenated bundle | Low | Medium | Measure bundle size at each phase; esbuild tree-shaking; minification |
| **Multi-instance conflict** — multiple widgets on same page share TS module state | Medium | Medium | TS modules are stateless factories; each widget creates its own instance; same pattern as services already work |
| **Backward compat break** — third-party code depending on internal chat.js functions | Low | Low | Internal functions are in a closure (IIFE), not accessible. Only `window.wpMcpAi*` is public. |
| **TypeScript strict mode compilation errors in existing services** | Low | Low | Services already compile under existing tsconfig; new modules follow same patterns |
| **chat.js inline fallback removal breaks edge case** | Medium | High | Phase 7 only after all TS modules are production-proven for 2+ weeks; feature flag for rollback |

---

## 9. Timeline & Effort Estimate

| Phase | Modules | Weeks | Effort |
|---|---|---|---|
| Phase 1 | `types.ts`, `state.ts`, `rendering.ts` | 2 | Foundation; ~2,300 LOC extracted |
| Phase 2 | `streaming.ts`, `tool-execution.ts` | 2 | ~2,700 LOC extracted |
| Phase 3 | `chat-core.ts`, `attachments-core.ts` | 2 | ~1,400 LOC extracted |
| Phase 4 | `speech-audio.ts`, `transcription-core.ts`, `voice-chat.ts` | 2 | ~1,200 LOC extracted |
| Phase 5 | `history.ts`, `tool-results.ts`, `agent-panel.ts`, `message-handling.ts` | 2 | ~3,000 LOC extracted |
| Phase 6 | `embedded-llm.ts`, `cron-tasks.ts`, `chat-session-stream.ts`, `keyboard-darkmode.ts` | 2 | ~1,800 LOC extracted |
| Phase 7 | Cleanup, testing, hardening | 2 | Remove fallbacks, integration tests, docs |
| **Total** | **16 modules** | **14 weeks** | **~11,800 LOC extracted** |

**Effort calibration:** The NPM package extraction (17 packages, similar scope) was completed across multiple PRs over ~4 weeks. The PHP tool migration (43 tools, each ~100-300 LOC) took ~8 weeks. This plan is calibrated at a sustainable pace with test coverage.

---

## 10. Success Criteria

### Phase Completion Gates

Each phase is complete when:
- [ ] All modules for that phase compile with `strict: true` and zero errors
- [ ] Unit tests pass (`npx vitest run`)
- [ ] `chat.js` delegates to new TS module (backward compat verified)
- [ ] Full widget works in browser (shortcode + block + Elementor)
- [ ] Bundle size does not regress more than 5%
- [ ] Existing Jest tests (if any for migrated functions) continue to pass

### Final Success Criteria (Phase 7)

- [ ] All ~11,800 LOC extracted to TypeScript under `assets/js/src/chat/`
- [ ] `chat.js` reduced to thin delegation shell (<500 LOC) or removed entirely
- [ ] `chat-bundle.js` imports only from `src/`
- [ ] 100% of chat widget functionality works identically to pre-migration
- [ ] Test coverage ≥ 70% for chat/ modules
- [ ] Bundle size within 10% of current `chat-bundle.js`
- [ ] All 11 `window.wpMcpAiChat*` service globals still work (no breaking changes)
- [ ] Documentation updated (`docs/`, `CLAUDE.md`, `AGENTS.md`)
- [ ] `.context/chat-ui.md` updated with new architecture
- [ ] TypeScript type exports available for IDE autocompletion

---

## Appendices

### A. Files Created per Phase

| Phase | New Files |
|---|---|
| 1 | `src/chat/types.ts`, `src/chat/state.ts`, `src/chat/rendering.ts`, `src/chat/__tests__/state.test.ts`, `src/chat/__tests__/rendering.test.ts` |
| 2 | `src/chat/streaming.ts`, `src/chat/tool-execution.ts`, `src/chat/__tests__/streaming.test.ts`, `src/chat/__tests__/tool-execution.test.ts` |
| 3 | `src/chat/chat-core.ts`, `src/chat/attachments-core.ts`, `src/chat/__tests__/chat-core.test.ts`, `src/chat/__tests__/attachments-core.test.ts` |
| 4 | `src/chat/speech-audio.ts`, `src/chat/transcription-core.ts`, `src/chat/voice-chat.ts`, corresponding test files |
| 5 | `src/chat/history.ts`, `src/chat/tool-results.ts`, `src/chat/agent-panel.ts`, `src/chat/message-handling.ts`, corresponding test files |
| 6 | `src/chat/embedded-llm.ts`, `src/chat/cron-tasks.ts`, `src/chat/chat-session-stream.ts`, `src/chat/keyboard-darkmode.ts`, corresponding test files |
| 7 | `src/chat-bundle-entry.ts`, updated `esbuild.config.js`, updated `chat-bundle.js` |

### B. Files Modified

| File | Change |
|---|---|
| `assets/js/chat.js` | Add delegation checks each phase; remove all fallbacks in Phase 7 |
| `assets/js/chat-bundle.js` | Update imports from flat JS → `src/` TypeScript |
| `tsconfig.json` | Ensure `src/chat/` is included |
| `esbuild.config.js` | Add chat-bundle entry point (or update existing) |
| `CLAUDE.md` | Update chat UI architecture section |
| `.context/chat-ui.md` | Update with TS module architecture |
| `AGENTS.md` | Add TypeScript migration to context-loading table |

### C. References

1. Fowler, M. (2004). *Strangler Fig Application*. https://martinfowler.com/bliki/StranglerFigApplication.html
2. Google TypeScript Style Guide. https://google.github.io/styleguide/tsguide.html
3. Osmani, A. (2023). *Learning JavaScript Design Patterns*. https://www.patterns.dev/
4. WordPress JavaScript Standards. https://developer.wordpress.org/coding-standards/wordpress-coding-standards/javascript/
5. Pro SPA v2 source code. `addons/pro/assets/spa-v2/src/`
6. Cross-Platform Extraction Architecture. `docs/project/proposals/cross-platform-extraction-architecture.md`
7. Chat-SPA v2 Parity Plan. `docs/project/proposals/chat-spa-v2-parity-plan.md`
8. Paper Store Architecture. `docs/project/proposals/paper-store-architecture.md`
9. Current State Assessment. `docs/project/proposals/chat-client-typescript-migration-current-state.md`
