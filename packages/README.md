# NV oOS NPM Packages

## Overview

Standalone NPM packages extracted from the [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin. Each package is independently usable in any JavaScript/TypeScript project.

All packages are **ES modules** with full TypeScript definitions. See each package's README for detailed API documentation.

---

## 📦 Packages

### Tier 1 — Core Utilities (Initial Set)

| Package | Description | Dependencies |
|---------|-------------|--------------|
| [`nvoos-storage`](./nvoos-storage/) | Async JSON via Web Worker | Zero |
| [`nvoos-markdown`](./nvoos-markdown/) | XSS-safe markdown renderer | marked, dompurify |
| [`nvoos-events`](./nvoos-events/) | SSE client + job event bus | @microsoft/fetch-event-source |

### Tier 2 — Extended Utilities

| Package | Description | Dependencies |
|---------|-------------|--------------|
| [`nvoos-http-client`](./nvoos-http-client/) | HTTP client with retry/backoff | ky |
| [`nvoos-clipboard`](./nvoos-clipboard/) | Clipboard copy with fallback | Zero |
| [`nvoos-offline-sync`](./nvoos-offline-sync/) | IndexedDB offline-first sync | Zero |

### Tier 3 — Chat UI Utilities

| Package | Description | Dependencies |
|---------|-------------|--------------|
| [`nvoos-slash-commands`](./nvoos-slash-commands/) | Slash command system with fuzzy-search autocomplete | Zero |
| [`nvoos-audio`](./nvoos-audio/) | TTS, STT, translation, and voice chat with VAD | Zero |
| [`nvoos-dom-batcher`](./nvoos-dom-batcher/) | RAF DOM batcher, scroll batcher, and UI utilities | Zero |

### Tier 4 — Browser AI Runtime Utilities

| Package | Description | Dependencies |
|---------|-------------|--------------|
| [`nvoos-llm-worker`](./nvoos-llm-worker/) | Web Worker manager for non-blocking LLM operations | Zero |
| [`nvoos-model-loader`](./nvoos-model-loader/) | Progressive 4-stage AI model loading UI | Zero |
| [`nvoos-transformers-client`](./nvoos-transformers-client/) | HuggingFace Transformers.js task wrapper (summarize, sentiment, NER, translate, QA, embed) | @huggingface/transformers (optional peer) |

### Tier 5 — Chat Service Utilities

| Package | Description | Dependencies |
|---------|-------------|--------------|
| [`nvoos-client-tools`](./nvoos-client-tools/) | Browser-native AI tool registry (summarize, sentiment, translate, embed, image, audio) | @huggingface/transformers (optional peer) |
| [`nvoos-chat-memory`](./nvoos-chat-memory/) | Promise-based REST client for an AI chat memory bridge (wake-up, recall, store, audit, preferences) | Zero |

### Tier 6 — TypeScript-Native SDK (New)

| Package | Description | Dependencies |
|---------|-------------|--------------|
| [`nvoos-types`](./nvoos-types/) | Canonical TypeScript type definitions (AI providers, chat, tools, SSE, attachments, history, memory, agents) | Zero (pure types) |
| [`nvoos-api`](./nvoos-api/) | Typed REST API client — endpoint builders, payload constructors, auth headers, typed fetch helpers | Zero |
| [`nvoos-sse-client`](./nvoos-sse-client/) | TypeScript-native SSE connection manager with lifecycle tracking, per-connection status, and auto-cleanup | @microsoft/fetch-event-source (peer) |
| [`nvoos-attachments`](./nvoos-attachments/) | File attachment helpers: type detection, validation, normalisation, segment builders | Zero |
| [`nvoos-cron-status`](./nvoos-cron-status/) | SSE-first cron/job status monitor with REST polling fallback | nvoos-events (optional peer) |
| [`nvoos-transcription`](./nvoos-transcription/) | MediaRecorder-based audio recording + tool-call transcription pipeline | Zero |

---

## Package Details

### @nvdigitalsolutions/nvoos-storage

- Async JSON parsing/stringifying via Web Workers (prevents main-thread blocking)
- Automatic fallback for small data and unsupported browsers
- Includes companion `storage-worker.js` script

```bash
npm install @nvdigitalsolutions/nvoos-storage
```

---

### @nvdigitalsolutions/nvoos-markdown

- Renders markdown to sanitized HTML using `marked` + `DOMPurify`
- Pre-configured security profile for AI-generated content
- `MarkdownRenderer` class with configurable CSS classes and allowed tags
- Standalone helper exports: `escapeHtml`, `sanitizeUrl`, `renderInlineLabel`

```bash
npm install @nvdigitalsolutions/nvoos-markdown marked dompurify
```

---

### @nvdigitalsolutions/nvoos-events

- Enhanced SSE client (POST support, auto-reconnect, max retry limit)
- Job event bus (mitt-compatible) with LRU cache eviction
- Promise-based `watchJob()` for async job completion tracking

```bash
npm install @nvdigitalsolutions/nvoos-events @microsoft/fetch-event-source
```

---

### @nvdigitalsolutions/nvoos-http-client

- `postJson`, `uploadFile`, `get`, `delete` with automatic retry
- Exponential backoff with configurable limits
- Request/response hooks for auth failure detection, logging, and instrumentation
- `parseError()` helper for structured error handling

```bash
npm install @nvdigitalsolutions/nvoos-http-client ky
```

---

### @nvdigitalsolutions/nvoos-clipboard

- `copyTextToClipboard()` — Clipboard API with `execCommand` fallback
- `attachCopyButton()` — attaches a self-managing copy button to any element
- Configurable CSS class names via `configure()`
- Zero external dependencies

```bash
npm install @nvdigitalsolutions/nvoos-clipboard
```

---

### @nvdigitalsolutions/nvoos-offline-sync

- IndexedDB-backed message persistence (works offline immediately)
- Automatic sync queue that drains on reconnect
- Configurable `syncUrl`, `syncHeaders`, `dbName`, offline UI toggle
- Zero external dependencies

```bash
npm install @nvdigitalsolutions/nvoos-offline-sync
```

---

### @nvdigitalsolutions/nvoos-slash-commands

- Fuzzy-search autocomplete dropdown triggered by `/` in chat inputs
- Keyboard navigation (↑ ↓ Enter Tab Escape)
- `SlashCommandsHandler` — intercepting form submission, REST execution with correlation IDs, 5-minute command list cache
- `CommandAutocomplete` — attaches to any text input; keyboard-navigable dropdown
- Dispatches `slash-command-event` CustomEvent on `window`
- Zero external dependencies

```bash
npm install @nvdigitalsolutions/nvoos-slash-commands
```

---

### @nvdigitalsolutions/nvoos-audio

- Text-to-speech playback via `attachSpeechButton()` (caching, lifecycle, error state)
- Microphone recording → transcription via `handleTranscribeButtonClick()`
- File-based transcription/translation via `handleTranscribeFileSelection()` / `handleTranslateFileSelection()`
- Voice chat with Voice Activity Detection (VAD) via `handleVoiceChatButtonClick()`
- `configure()` for overriding default CSS class names
- `registerObjectUrl()` / `revokeObjectUrls()` for blob URL lifecycle management
- Zero external dependencies (uses MediaRecorder, Web Audio API, SpeechSynthesis, Fetch)

```bash
npm install @nvdigitalsolutions/nvoos-audio
```

---

### @nvdigitalsolutions/nvoos-dom-batcher

- `domUpdateBatcher.schedule()` — batches DOM writes into a single `requestAnimationFrame` per tick
- `scrollBatcher.scrollToBottom()` — deduplicates scroll operations per element per frame
- Formatting helpers: `escapeHtml`, `formatBytes`, `formatDuration`, `formatElapsedTime`
- Status helpers: `setStatus`, `clearStatus`
- Button utilities: `toggleButtonClass`, `setButtonState`, `setButtonIcon`, `updateButtonLabel`
- Cross-instance messaging: `broadcastMessage`, `listenToChatEvents`
- Attachment library: `validateAttachment`, `addToAttachmentLibrary`, `getFromAttachmentLibrary`, `removeFromAttachmentLibrary`
- `displayRecordingTimer()` — animated timer for recording UIs
- `configure({ debug })` to disable RAF batching during tests
- Zero external dependencies

```bash
npm install @nvdigitalsolutions/nvoos-dom-batcher
```

---

### @nvdigitalsolutions/nvoos-llm-worker

- `LLMWorkerManager` — manages a Web Worker hosting a WebLLM (or compatible) engine
- Promise-based API: `createWorker()`, `loadModel()`, `generate()`, `unloadModel()`, `getStats()`, `terminate()`
- Streaming generation via `onChunk` callback
- 10s `worker_ready` handshake; 5-min model-load timeout
- Configurable `workerUrl` and `workerOptions` (defaults to `{ type: 'module' }`)
- Zero external dependencies (you supply the worker script)

```bash
npm install @nvdigitalsolutions/nvoos-llm-worker
```

---

### @nvdigitalsolutions/nvoos-model-loader

- `ProgressiveModelLoader` — 4-stage loading UI: checking → downloading → initializing → ready
- Generic over any engine factory accepting `initProgressCallback` (WebLLM, Transformers.js, custom)
- Cache check via `caches.open('webllm-models')`
- Configurable CSS class names and stage definitions
- Zero external dependencies

```bash
npm install @nvdigitalsolutions/nvoos-model-loader
```

---

---

### @nvdigitalsolutions/nvoos-client-tools

- Browser-native AI **tool registry** powered by Transformers.js
- Seven OpenAI-style tools: `client_summarize`, `client_sentiment`, `client_translate`, `client_embed`, `client_describe_image`, `client_detect_objects`, `client_transcribe_audio`
- Pipeline factory is injectable — bundle, load from CDN, or mock in tests
- Pairs with `nvoos-transformers-client`

```bash
npm install @nvdigitalsolutions/nvoos-client-tools
# Optional: bundle Transformers.js instead of using a CDN
npm install @huggingface/transformers
```

---

### @nvdigitalsolutions/nvoos-chat-memory

- Promise-based REST client for an AI chat memory bridge
- Eight verbs: `wakeUp`, `recall`, `store`, `storeBeacon`, `update`, `remove`, `audit`, plus `getPreferences` / `setPreferences`
- Inject `endpoints`, `headers`, `fetch`, and `credentials` via `configure()` — no WP globals
- `keepalive: true` beacon variant survives `pagehide` / unload

```bash
npm install @nvdigitalsolutions/nvoos-chat-memory
```

---

### @nvdigitalsolutions/nvoos-attachments

- 17 helpers for file uploads in chat: type detection, MIME-aware iconography, URL safety, normalisation, OpenAI-style segment builders
- Pure functions over plain data — no DOM, no fetch, no globals
- Zero external dependencies

```bash
npm install @nvdigitalsolutions/nvoos-attachments
```

---

### @nvdigitalsolutions/nvoos-cron-status

- SSE-first cron/job status monitor with REST polling fallback
- Exponential back-off (30 s → 5 min cap, 60-attempt limit)
- Auth-flexible — accepts nonce *or* guest token, sends both as header + querystring
- Plug-and-play with `nvoos-events` (`SSEService` and `JobEventBus` satisfy its adapter contracts)

```bash
npm install @nvdigitalsolutions/nvoos-cron-status
# Optional: pair with the SSE service & job bus from nvoos-events
npm install @nvdigitalsolutions/nvoos-events
```

---

### @nvdigitalsolutions/nvoos-transcription

- 13 methods covering the full voice-input lifecycle: record → upload → transcribe → insert
- `MediaRecorder` capture with auto-stop on 25 MB cap; falls back to a file-picker on browsers without `getUserMedia`
- Tool-call pipeline (default tool name: `transcribe_openai_audio`) is endpoint-driven — drop in any backend
- Zero external dependencies

```bash
npm install @nvdigitalsolutions/nvoos-transcription
```

---

## Building All Packages

Each package has an `adapt-for-npm.js` build script that transforms the WordPress
plugin source into a clean ES module:

```bash
for pkg in nvoos-storage nvoos-markdown nvoos-events nvoos-http-client nvoos-clipboard nvoos-offline-sync nvoos-slash-commands nvoos-audio nvoos-dom-batcher nvoos-llm-worker nvoos-model-loader nvoos-transformers-client nvoos-client-tools nvoos-chat-memory nvoos-attachments nvoos-cron-status nvoos-transcription; do
  (cd $pkg && node adapt-for-npm.js)
done
```

---

## Additional Package Candidates

The following files were identified as potential future extraction candidates
(see `FINAL_SUMMARY.md` for full analysis):

| File | Candidate Package | Portability |
|------|------------------|-------------|
| `chat-memory-drawer.js` (~1300 LOC) | `nvoos-chat-memory-ui` | Medium — depends on `nvoos-chat-memory`; may need a UI-vs-state split |
| `chat-ui-utilities-service.js` (~800 LOC) | Fold into `nvoos-dom-batcher@next` | Skip new package — substantial overlap with existing `nvoos-dom-batcher` |
| `ajax-error-service.js` (~440 LOC) | `nvoos-error-toaster` | Low — jQuery dependency, needs full rewrite |
| `accessibility-enhancements.js` (~500 LOC) | `nvoos-a11y` | Low — jQuery dependency |

---

## License

All packages are MIT licensed. See `LICENSE` in each package directory.

---

## 🏗️ Package Structure

Each package follows this layout:

```
packages/nvoos-{name}/
├── {source}.js          ← copy of the original WordPress plugin source
├── adapt-for-npm.js     ← build script (node adapt-for-npm.js to rebuild)
├── dist/
│   ├── nvoos-{name}.js  ← generated ES module
│   └── nvoos-{name}.d.ts ← TypeScript definitions
├── package.json         ← with "exports", "module", "types" fields
└── README.md            ← installation + full API docs
```

## 🔧 Build Process

Each package includes a custom `adapt-for-npm.js` script that:

1. **Removes WordPress Dependencies**: Strips out `window.wpMcpAi*` globals
2. **Converts to ES Modules**: Removes IIFE wrappers, adds ES exports
3. **Adds Configuration**: Replaces hardcoded values with injectable config
4. **Generates TypeScript Definitions**: Creates .d.ts files for type safety
5. **Preserves Comments**: Maintains JSDoc documentation

## 📊 Package Comparison

| Tier | Packages | External deps |
|------|----------|---------------|
| Tier 1 | storage, markdown, events | 0 / 2 peer / 1 peer |
| Tier 2 | http-client, clipboard, offline-sync | 1 peer / 0 / 0 |
| Tier 3 | slash-commands, audio, dom-batcher | 0 / 0 / 0 |
| Tier 4 | llm-worker, model-loader, transformers-client | 0 / 0 / 1 optional peer |
| Tier 5 | client-tools, chat-memory, attachments, cron-status, transcription | 1 optional peer / 0 / 0 / 1 optional peer / 0 |

All 17 packages: **WP dependencies = none, TypeScript = ✅, Tree-shakeable = ✅**.

## 🔗 Links

- **Main Repository**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- **Documentation**: /docs/npm-packages/
- **Publishing Guide**: /docs/npm-alpha-publishing.md
- **Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **NV Digital**: https://nvdigitalsolutions.com

---

**Status**: ✅ **All 17 packages ready for publication** (9 original + 3 Tier 4 browser AI runtime + 5 Tier 5 chat services)

**Last Updated**: 2026-05-03  
**Maintained By**: NV Digital Solutions
