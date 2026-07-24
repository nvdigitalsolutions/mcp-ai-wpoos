# Chat-Client TypeScript Migration — Current State Assessment

**Date:** 2026-07-24
**Status:** Live assessment — service extraction complete; core chat logic migration not yet started.
**Related:** [`cross-platform-extraction-gap-analysis.md`](./cross-platform-extraction-gap-analysis.md) (PHP side, same Strangler Fig pattern)

---

## Executive Summary

The embeddable chat widget (`assets/js/chat.js`, **20,684 lines** as of 2026-07-24) is the shortcode/block/Elementor chat surface. It has undergone a **service-layer extraction** (17 NPM packages + 11 TypeScript service modules) but the **core chat logic** — message rendering, SSE streaming, tool execution, attachment upload, speech/audio, history — remains as vanilla JS inside the monolithic IIFE.

This is the **frontend mirror** of the PHP cross-platform extraction: a Strangler Fig migration where services have been extracted first, and the core monolith is the remaining piece. The PHP side (`lib/core`) has **100% infrastructure complete** (ChatOrchestrator, ProviderRouter, ToolRegistry, SkillRegistry, all 8 WordPress adapters) with ~42% tool migration (~82 of ~195 base tools). The JS side has **100% service extraction** but **0% core chat logic migration** (only `memory-drawer.ts`, a UI component, not core logic).

---

## Current Architecture: Three Layers

```
┌─────────────────────────────────────────────────────────────┐
│ Layer 1: NPM Packages (packages/)                            │
│ ✅ 17 framework-agnostic ES modules                           │
│    nvoos-storage, nvoos-markdown, nvoos-events,              │
│    nvoos-http-client, nvoos-clipboard, nvoos-offline-sync,   │
│    nvoos-slash-commands, nvoos-audio, nvoos-dom-batcher,     │
│    nvoos-llm-worker, nvoos-model-loader,                     │
│    nvoos-transformers-client, nvoos-client-tools,            │
│    nvoos-chat-memory, nvoos-attachments, nvoos-cron-status,  │
│    nvoos-transcription, nvoos-types, nvoos-api,              │
│    nvoos-sse-client                                          │
├─────────────────────────────────────────────────────────────┤
│ Layer 2: TypeScript Services (assets/js/src/services/)       │
│ ✅ 11 service modules in TS (@since 1.2.0)                   │
│    attachments.ts, audio.ts, clipboard.ts, http-client.ts,   │
│    markdown.ts, memory-service.ts, sse.ts, storage-util.ts,  │
│    storage.ts, transcription.ts, ui-utilities.ts             │
├─────────────────────────────────────────────────────────────┤
│ Layer 2b: Shared Types/API (assets/js/src/shared/)          │
│ ✅ types.ts, api.ts, wp-rest.ts, index.ts                    │
├─────────────────────────────────────────────────────────────┤
│ Layer 3: Legacy chat.js (assets/js/chat.js)                  │
│ 🔴 20,684 lines of vanilla JS IIFE                            │
│    Uses compatibility layers to delegate to Layer 1/2:       │
│    const storageService = window.wpMcpAiChatStorage || null; │
│    Falls back to inline implementation when service absent   │
└─────────────────────────────────────────────────────────────┘
```

### How the Compatibility Layer Works

`chat.js` declares a service reference for each extracted module:

```javascript
const storageService = window.wpMcpAiChatStorage || null;
const clipboardService = window.wpMcpAiChatClipboard || null;
const markdownService = window.wpMcpAiChatMarkdown || null;
// ... etc for all 11 services
```

Each function then checks the service first:

```javascript
function saveConversationToStorage(state, options) {
    if (storageService && storageService.saveConversationToStorage) {
        return storageService.saveConversationToStorage(state, options);
    }
    // ... fallback inline implementation (~80 lines)
}
```

**The problem:** Every function carries its own fallback implementation. The external services always win (loaded before `chat.js` by `chat-bundle.js`), but the fallback code remains as dead weight.

---

## What's Been Extracted (Complete)

### Service Modules → NPM Packages

| chat.js Service | NPM Package | TS Module | Status |
|---|---|---|---|
| Storage / localStorage | `nvoos-storage` | `storage.ts`, `storage-util.ts` | ✅ |
| Markdown rendering | `nvoos-markdown` | `markdown.ts` | ✅ |
| SSE / EventSource | `nvoos-events`, `nvoos-sse-client` | `sse.ts` | ✅ |
| HTTP client (ky wrapper) | `nvoos-http-client` | `http-client.ts` | ✅ |
| Clipboard | `nvoos-clipboard` | `clipboard.ts` | ✅ |
| Offline sync | `nvoos-offline-sync` | — | ✅ |
| Slash commands | `nvoos-slash-commands` | — | ✅ |
| Audio (TTS/STT/voice) | `nvoos-audio` | `audio.ts` | ✅ |
| DOM batcher / UI utils | `nvoos-dom-batcher` | `ui-utilities.ts` | ✅ |
| Attachments | `nvoos-attachments` | `attachments.ts` | ✅ |
| Chat memory bridge | `nvoos-chat-memory` | `memory-service.ts` | ✅ |
| Cron status | `nvoos-cron-status` | — | ✅ |
| Transcription | `nvoos-transcription` | `transcription.ts` | ✅ |
| Types (zero runtime) | `nvoos-types` | `shared/types.ts` | ✅ |
| API endpoint builders | `nvoos-api` | `shared/api.ts` | ✅ |

### Chat UI Components → TypeScript

| Component | TS Module | Status |
|---|---|---|
| Memory Drawer | `src/chat/memory-drawer.ts` | ✅ |

---

## What Has NOT Been Migrated (The Gap)

All of the following live inside `chat.js` (20,684 lines total) with no TypeScript equivalent under `assets/js/src/chat/`:

| Module Needed | chat.js Functions | LOC (est.) | Dependencies |
|---|---|---|---|
| **chat-core.ts** | `init()`, `handleSubmit()`, `sendChat()`, `sendChatStreaming()`, `sendChatEmbedded()`, `disableForm()`, `updateSubmitButtonForSend/Stop()` | ~800 | All other modules |
| **streaming.ts** | `processSSEStream()`, `handleStatusEvent()`, `handleToolExecutionEvent()`, `handleErrorEvent()`, `createStreamingMessage()`, `updateStreamingMessage()`, `updateStreamingStatus()`, `streamingLogger` | ~1,200 | SSE service, markdown |
| **rendering.ts** | `appendMessage()`, `renderMarkdown()`, `escapeHtml()`, `formatInline()`, `sanitizeUrl()`, `createJsonResponseElement()`, `createTruncatedResponseElement()`, `createChartBlockElement()`, `attachSpeechButton()`, `attachCopyButton()`, `attachDeleteButton()`, `attachSaveButton()`, `attachFeedbackButtons()`, `attachRegenerateButton()` | ~1,500 | Markdown service, clipboard |
| **tool-execution.ts** | `waitForAsyncToolResult()`, `waitForAsyncToolResultSSE()`, `waitForAsyncToolResultPolling()`, `waitForAsyncToolResultWithEventBus()`, `displayAsyncToolResult()`, `fetchAsyncToolResult()`, `attemptTimeoutRecovery()`, `executeToolViaOrchestrator()`, `displayToolResult()`, `handleEmbeddedToolCalls()`, `startAsyncToolPolling()`, `createJobProgressCard()` | ~1,500 | SSE, HTTP client, job event bus |
| **state.ts** | State object shape (~60 properties), `saveConversationToStorage()`, `loadConversationFromStorage()`, `clearConversationFromStorage()`, `restoreConversationFromStorage()`, `saveConversationToCCT()`, `cleanupOldStorageEntries()`, quota monitoring | ~800 | Storage service |
| **attachments.ts** (core) | `handleFileSelection()`, `uploadAttachment()`, `prepareAttachment()`, `renderPendingAttachments()`, `removePendingAttachment()`, `updateAttachButtonState()`, `normaliseUploadResponse()`, `createSegmentFromAttachment()`, MIME validation | ~600 | HTTP client, attachments service |
| **speech-audio.ts** | `handleSpeechButtonClick()`, `requestSpeechAudio()`, `attachSpeechButton()`, `createSpeechAudio()`, `startSpeechPlayback()`, `stopSpeechPlayback()`, `ensureSpeechAudio()`, speech cache | ~400 | Audio service |
| **transcription.ts** (core) | `handleTranscribeButtonClick()`, `startTranscribeRecording()`, `stopTranscribeRecording()`, `handleTranscribeFileSelection()`, `transcribeAudioFile()`, `uploadAudioForTranscription()`, `requestTranscription()`, `insertTranscriptionResult()` | ~500 | Transcription service |
| **voice-chat.ts** | `handleVoiceChatButtonClick()`, `startVoiceChatRecording()`, `stopVoiceChatRecording()`, `processVoiceChatAudio()`, `setVoiceChatRecordingState()`, `updateVoiceChatButtonState()` | ~300 | Audio service |
| **history.ts** | `loadHistorySessions()`, `fetchHistorySessions()`, `fetchHistorySessionDetails()`, `renderHistorySessions()`, `toggleHistorySession()`, `loadHistorySessionIntoChat()`, `handleHistoryDelete()`, `toggleHistoryVisibility()`, `ensureHistorySessions()`, `refreshHistorySessions()`, `loadMoreHistorySessions()`, history sidebar | ~800 | HTTP client |
| **tool-results.ts** | `normaliseToolResultForDisplay()`, `normaliseCrawl4aiResult()`, `normaliseChartResult()`, `normaliseJetEngineRoutesResult()`, `extractGenericToolResponse()`, `extractWebSearchSummary()`, `extractSiteHealthSummary()`, `extractDeepResearchSummary()`, tool-specific result formatters | ~1,200 | Markdown service |
| **agent-panel.ts** | `initAgentPanel()`, `updateAgentPanel()`, `updateWorkflowTracker()`, `createDelegationNotice()`, `handleAgentToolResult()` | ~300 | None |
| **embedded-llm.ts** | `sendChatEmbedded()`, `sendChatEmbeddedInternal()`, `generateEmbeddedCompletion()`, `trackEmbeddedUsage()`, embedded client init, model loading, system prompt assembly | ~800 | Embedded LLM client, storage |
| **message-handling.ts** | `extractDisplayMetadata()`, `createConversationMessage()`, `stripMessageDisplayMetadata()`, `stripContentDisplayData()`, `ensureFinalMessagesPresent()`, `handleChatResponse()`, message cleanup/normalization | ~700 | None |
| **keyboard-darkmode.ts** | `initializeKeyboardShortcuts()`, `toggleKeyboardShortcutsHelp()`, `initDarkMode()`, `updateDarkToggleIcon()` | ~200 | None |
| **cron-tasks.ts** | `initializeCronStatus()`, `initTasksDrawer()`, `initializeGlobalJobListeners()`, `updateCronStatusDisplay()`, tasks drawer, toast system, tab badge | ~600 | Cron status service, job event bus |
| **chat-session-stream.ts** | `initChatSessionStream()`, `resolveChatSessionId()`, `handleChatResumedFrame()`, `handleChatStatusFrame()`, async continuation SSE | ~200 | SSE |

**Total estimated LOC to extract: ~11,800 lines** across ~16 TypeScript modules (remaining ~8,900 lines are IIFE structure, initialization bootstrapping, multi-instance wiring, and service fallback implementations that will dissolve when extraction completes).

---

## Relationship to Other Efforts

### vs. `lib/core` (PHP Cross-Platform Extraction)

| Aspect | PHP (`lib/core`) | JS (`chat.js` → `src/chat/`) |
|---|---|---|
| Pattern | Strangler Fig + Hexagonal | Strangler Fig + compatibility layers |
| Current status | 42% tools migrated, 100% infra | 100% services, 0% core logic |
| Feature flag | `?engine=oos` | N/A (services auto-detect) |
| Backward compat | Legacy path untouched | Inline fallbacks (dead weight) |

### vs. Pro SPA v2

Pro SPA v2 (`addons/pro/assets/spa-v2/`, ~40 TS files) is a **different product** — a React 19 + TypeScript admin-page SPA. It cannot substitute for the embeddable widget because:

| Dimension | Pro SPA v2 | Embeddable Widget (`chat.js`) |
|---|---|---|
| **Framework** | React 19 + `@ai-sdk/react` | Vanilla JS IIFE (DOM mutation) |
| **Delivery** | Admin page SPA (`[nvoos_chat_spa]`) | Shortcode/block/Elementor widget |
| **Build** | esbuild → IIFE (separate bundle) | Concatenated `chat-bundle.js` |
| **State** | Zustand stores (4 stores) | Manual state object (~60 props) |
| **SSE** | `sse-adapter.ts` + `createChatFetch` | Inline `fetch()` loop + `EventSource` |
| **Tests** | Vitest + jsdom (86 tests) | None |
| **Dependencies** | Pro-only (Auth0, Pro tools) | Base + Pro compatible |
| **Multi-instance** | Single SPA per page | Multiple widgets per page |

However, Pro SPA v2 serves as a **reference implementation** for patterns that inform the widget migration:

| Pro SPA v2 Module | Reusability |
|---|---|
| `sse-adapter.ts` (597 LOC) | **High** — SSE protocol parsing is framework-agnostic; extract to `nvoos-sse-client` |
| `utils/normalise-tool-result.ts` (465 LOC) | **High** — mirrors chat.js; pure function, zero React deps |
| `utils/export-conversation.ts` | **Medium** — pure data transform for JSON/Markdown export |
| `hooks/useKeyboardShortcuts.ts` | **Medium** — key definitions could be shared |
| Stores/hooks (`assistantStore`, `useJobBus`) | **Low** — architectural reference only (React-specific) |

**Bottom line:** Pro SPA v2 cannot fill the widget migration gap directly (wrong framework, wrong delivery), but two specific modules contain logic that should be extracted to shared NPM packages rather than duplicated. See [`chat-spa-v2-parity-plan.md`](./chat-spa-v2-parity-plan.md) for the 19 feature gaps tracked between the legacy widget and the React SPA.

### vs. `chat-spa` (Base SPA)

The base `chat-spa` addon is also TypeScript/React but for a standalone admin page (~3,500 LOC). Same framework mismatch — patterns are informative but not directly reusable.

### Server Orchestration (`lib/core`) — Fully Migrated

The **PHP orchestrator layer** has been **fully migrated** to `lib/core`:

```
lib/core/src/
├── Domain/
│   ├── Contract/     ✅ 9 domain interfaces
│   ├── Entity/       ✅ 10 domain entities
│   ├── Error/        ✅ 5 domain errors
│   └── Event/        ✅ 8 domain events
├── Application/
│   ├── Chat/
│   │   └── ChatOrchestrator.php   ✅ (626 LOC)
│   │       - handleChat()              non-streaming path
│   │       - handleChatStreaming()     SSE streaming path
│   │       - buildAllowedTools()       tool filtering
│   ├── Provider/
│   │   └── ProviderRouter.php    ✅ AI provider routing
│   ├── Tool/
│   │   └── ToolRegistry.php      ✅ Tool registry
│   └── Skill/
│       └── SkillRegistry.php     ✅ Skill registry
├── Infrastructure/
│   ├── Cost/          ✅ Cost tracking
│   ├── Provider/      ✅ Provider adapters
│   ├── Streaming/     ✅ SSE infrastructure
│   └── Token/         ✅ Token budget management
└── WordPress adapters  ✅ 31 adapters (10 original + 21 new)

**New — Contract Layer Complete (2026-07-24):** 35 domain contracts (11 original + 24 new) covering 71 of 84 base services (85%). See [`legacy-php-service-inventory-lib-core-gap.md`](./legacy-php-service-inventory-lib-core-gap.md) for full inventory.
```

**What's NOT migrated to `lib/core` yet:** ~113 base tools (42% done), ~830 Pro tools, Paper Store, Result Delivery Service, 13 uncovered service files (provider-specific or utility classes).

### Paper Store — Legacy PHP, Not Yet in `lib/core`

The Paper Store is a flat-file storage system for AI-generated research reports. It is **architected but remains in the legacy PHP layer**:

| Aspect | Status | Location |
|---|---|---|
| Architecture | ✅ Complete | `docs/project/proposals/paper-store-architecture.md` |
| Base implementation | 🟡 Legacy | `includes/paper-store/` |
| Pro implementation | 🟡 Legacy | `addons/pro/includes/paper-store/` (Markdown+YAML, Git sync) |
| `lib/core` migration | 🔴 Not started | — |
| Chat widget awareness | 🔴 None | Tool results surface generically through SSE |

**Relevance to widget TS migration:** Low for Phase 1–4. Becomes relevant when `tool-results.ts` handles Paper Store result types. Pro SPA v2's `normalise-tool-result.ts` already has a pattern.

### Result Delivery Service & New Pro Services

Several new Pro services exist outside `lib/core`:

| Service | Location | `lib/core` Status |
|---|---|---|
| **Result Delivery Service** | `addons/pro/includes/services/` (1,343 LOC) | 🔴 Not migrated |
| **Pro Schedule Manager** | `addons/pro/includes/` | 🔴 Not migrated |
| **Pro Schedule Presets** | `addons/pro/includes/` | 🔴 Not migrated |

**Full inventory** of ~230+ unmigrated legacy service/manager/infrastructure classes: see [`legacy-php-service-inventory-lib-core-gap.md`](./legacy-php-service-inventory-lib-core-gap.md).

**Relevance to widget TS migration:** LOW. The widget communicates through stable REST/SSE contracts independent of backend implementation. The widget TS migration can proceed without waiting for these services to migrate.

---

## Build Pipeline: Current vs. Target

### Current

```
chat.js ─────────────┐
chat-storage-service.js ─┤
chat-markdown-service.js ─┤
... (11 service files) ───┼──► chat-bundle.js (concatenation)
chat-memory-service.js ───┤
chat-memory-drawer.js ────┘
```

### Target

```
assets/js/src/
├── shared/*.ts ──────────┐
├── services/*.ts ────────┤
├── chat/*.ts ────────────┼──► esbuild ──► chat-bundle.js (IIFE)
└── (new chat-core,       │
     streaming,            │
     rendering, etc.) ─────┘
```

The existing service files under `assets/js/` (plain JS) would be **replaced by their TypeScript equivalents** in the bundle entry point. The `chat-bundle.js` would import from `src/` instead of the flat JS files.

---

## Key Insight

The chat widget TypeScript migration is the **frontend counterpart** to the PHP cross-platform extraction. Both follow the Strangler Fig pattern. The PHP side has **completed all infrastructure migration** (ChatOrchestrator, ProviderRouter, ToolRegistry, all 8 adapters) with ~42% tool migration remaining. The JS side has **completed all service extraction** (17 NPM packages + 11 TS modules) with 0% core chat logic migration.

**The two efforts are symmetrical but at opposite stages:** PHP is deep into core logic migration (tools, agentic loop); JS is starting core logic migration after completing the peripheral work first.

**Pro SPA v2** (React admin SPA) is a separate product and cannot substitute for the embeddable widget, but two of its modules (`sse-adapter.ts`, `normalise-tool-result.ts`) contain framework-agnostic logic that should be extracted to shared NPM packages to avoid duplication during this migration.

**Server-side services** (Paper Store, Result Delivery Service, Schedule Manager) remain in the legacy PHP layer. They have no direct impact on the widget TS migration — the widget only sees their outputs through the SSE tool-result stream.
