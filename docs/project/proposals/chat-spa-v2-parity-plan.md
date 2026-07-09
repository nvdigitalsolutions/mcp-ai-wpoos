# Chat-SPA v2 Parity Plan: Bridging the Gap from Legacy `chat.js`

> **Status:** Draft  
> **Proposal date:** 2026-07-09  
> **Author:** AI Agent (Zed)  
> **Target version:** chat-spa v0.8.0+  

---

## 1. Summary

The NV oOS Chat SPA (`addons/chat-spa/`) is a React + Vercel AI SDK rewrite of the legacy jQuery-era `assets/js/chat.js`.  It currently ships at **v0.7.0** with ~3,500 lines of TypeScript covering the core chat loop, transcripts sidebar, memory drawer, HITL approvals, file attachments, and message actions (copy, delete, feedback, regenerate, edit).  

The legacy `chat.js` (~20,700 lines) contains **19 additional features** not yet present in the SPA, ranging from voice/audio pipelines to agent panels, job cards, keyboard shortcuts, and export.  This document catalogues every gap, proposes an implementation order, and provides per-feature design notes.

---

## 2. Gap Catalogue

### 2.1 🔴 MAJOR Gaps — Core user-facing functionality

#### GAP-01: Voice & Audio Pipeline

**Legacy location:** `assets/js/chat.js` § handleSpeechButtonClick, startTranscribeRecording, startVoiceChatRecording, createSpeechAudio, etc. (~1,500 LOC)

**What it does:**
- **TTS Playback:** Speech button on every assistant message bubble reads the response aloud using the NV oOS `speech` tool.  Audio is cached per-text-hash.
- **STT Transcribe:** Microphone-record button in the composer that records via `MediaRecorder`, uploads to WordPress media, and calls the `transcribe` tool.  Result is inserted into the composer.
- **Voice Chat:** Record → transcribe → auto-submit loop. Replaces the text input while active.
- **Audio Translate:** Button to record/upload audio and get a translated transcript.

**SPA implementation approach:**
- `useSpeechPlayback` hook — fetch `/chat` (tools endpoint), call `speech` tool, play audio, cache in `Map<string,HTMLAudioElement>`
- `useAudioRecording` hook — `MediaRecorder` lifecycle, blob assembly, upload to WP media library
- `useVoiceChat` hook — orchestrates record→upload→transcribe→submit flow
- `SpeechButton`, `TranscribeButton`, `VoiceChatButton`, `TranslateButton` components
- New UI in composer toolbar region and per-message bubble

#### GAP-02: Task / Job System

**Legacy location:** `assets/js/chat.js` § createJobProgressCard, initTasksDrawer, showJobToast, updateTabTitleBadge, initializeGlobalJobListeners, initWithCronStatus (~1,800 LOC)

**What it does:**
- **Inline Job Cards:** Replaces placeholder "Tool is processing…" text with a BEM card showing a progress bar, ETA, expandable step list, Cancel and Retry buttons.
- **Tasks Drawer:** Right-side panel listing all active/completed/failed jobs for the assistant.  Filterable by status.  Batch cancel/retry/dismiss operations.  Persisted to `localStorage` (max 200 entries).
- **Job Event Bus:** `window.wpMcpAiJobBus` — custom event target emitting `job:started`, `job:step`, `job:progress`, `job:completed`, `job:failed`, `job:cancelled`.
- **Tab Title Badge:** `(N)` prefix on `document.title` while N jobs are running.
- **Cron Status Integration:** Periodic REST poll (`/mcp-ai/v1/cron-status`) drives the drawer's health dot and count badges.

**SPA implementation approach:**
- `useJobBus` hook — bridges `window.wpMcpAiJobBus` to React state via `useSyncExternalStore` or effect-event pattern
- `JobCard` component — renders progress, steps, cancel/retry within message stream
- `TasksDrawer` component — right-side panel
- `useJobPersistence` hook — localStorage round-trip
- `useCronStatus` hook — polling + health dot state

#### GAP-03: Agent Panel & Workflow Tracker

**Legacy location:** `assets/js/chat.js` § initAgentPanel, updateAgentPanel, updateWorkflowTracker, createDelegationNotice, handleAgentToolResult (~350 LOC)

**What it does:**
- **Agent Panel:** Collapsible panel showing "agent team" cards with status dots (active/completed/error), agent name, role, task description.  Driven by `create_agent_team` and `manage_autonomous_session` tool results.
- **Workflow Tracker:** Progress bar + numbered step list with completion icons.  Driven by `execute_workflow`, `update_task_plan`, `get_task_plan` tool results.
- **Delegation Notices:** Inline message-stream banners for `delegate_to_agent`, `delegate_to_a2a_agent`, `aggregate_agent_results`.

**SPA implementation approach:**
- `AgentPanel` component — sidebar or collapsible panel
- `WorkflowTracker` component — renders within the agent panel or as a standalone card
- `DelegationNotice` component — inline message-stream element
- Integration: wire into the tool-result rendering path (annotation type detection)

#### GAP-04: Async Tool & Crawl4AI Polling

**Legacy location:** `assets/js/chat.js` § waitForAsyncToolResultSSE, waitForAsyncToolResultPolling, waitForAsyncToolResultWithEventBus, attemptTimeoutRecovery, waitForCrawl4aiTask, fetchCrawl4aiTask (~600 LOC)

**What it does:**
- Three async-wait strategies: SSE streaming, HTTP polling, event-bus-driven
- Timeout recovery: re-fetches the result once after timeout
- Crawl4AI-specific polling with task-status endpoint
- All strategies surface results through the Job Card UI (GAP-02)

**SPA implementation approach:**
- `useAsyncToolResult` hook — configurable strategy (SSE/polling/eventBus), abort-safe, timeout recovery
- `useCrawl4AiPolling` hook — Crawl4AI task URL pattern, configurable poll delay
- Depends on GAP-02 (Job Card) for rendering

### 2.2 🟡 MEDIUM Gaps — Impactful but self-contained

| ID | Feature | Legacy LOC | Notes |
|----|---------|-----------|-------|
| **GAP-05** | Conversation Export (JSON/Markdown) | ~150 | Pure data transform → blob download. `ExportButton` component + `useExport` hook. |
| **GAP-06** | Keyboard Shortcuts + Help Modal | ~150 | Global `keydown` listener, macOS/Windows modifier detection, help modal component. `Ctrl+S` save, `Ctrl+E` export, `Ctrl+N` new, `Ctrl+/` help, `Esc` dismiss. |
| **GAP-07** | Suggested Prompts | ~30 | Config-driven prompt chips rendered above the composer. Click fills input. |
| **GAP-08** | Tool Shortcuts | ~120 | Config-driven action buttons above composer. Click inserts a `/tool` payload. |
| **GAP-09** | CPT Action Buttons | ~80 | Custom action buttons emitting DOM `CustomEvent` for CPT integrations (research, document). |
| **GAP-10** | Embedded Chat Client (Cloudflare/local AI) | ~450 | Bypasses `mcp-ai/v1/chat-client` for direct provider calls. `EmbeddedChatAdapter` alternative fetch. |
| **GAP-11** | Cron Status Integration | ~170 | REST poll every 30s for cron job counts; drives health dot (GAP-02) and status display. |

### 2.3 🟢 SMALL Gaps — Low effort polish

| ID | Feature | Legacy LOC | Notes |
|----|---------|-----------|-------|
| **GAP-12** | Usage / Cost Badges | ~160 | Token counts + USD cost displayed as badges on each assistant message. Parsed from `usage` + `cost` in finish annotations. |
| **GAP-13** | Capability Flag Badges | ~60 | Colored pills (e.g. "voice", "vision", "tools") below assistant messages. |
| **GAP-14** | Dark Mode Toggle (in-UI button) | ~60 | Sun/moon toggle button in chat header. `localStorage` persistence. Currently theme is shortcode-config-only. |
| **GAP-15** | localStorage Quota Monitor | ~45 | Display "X MB / Y MB used" in footer. |
| **GAP-16** | Vector Store Preload | ~50 | REST call on mount to warm vector-store cache. |
| **GAP-17** | History Search | ~30 | Client-side filter of sidebar transcripts list. |
| **GAP-18** | History Title Editing | ~60 | Inline-edit session titles in sidebar. |
| **GAP-19** | History Pagination / Load More | ~40 | "Load more" button below transcripts list. |

### 2.4 ✅ Already Covered

| Feature | SPA Implementation |
|---------|-------------------|
| Transcripts Sidebar (list/load/delete/new) | `TranscriptsSidebar.tsx` + `useTranscriptSession.ts` |
| Threads Tab in Sidebar | `TranscriptsSidebar.tsx` Threads panel + `useThreadsSidebar.ts` |
| Memory Drawer (CRUD, scope, audit, prefs) | `MemoryDrawer.tsx` — **bonus:** legacy has no drawer UI |
| HITL Approval Bar | `HitlApprovalBar.tsx` — **bonus:** legacy has no HITL UI |
| File Attachments | `useAttachments.ts` |
| Markdown Rendering | `markdown.ts` (marked + DOMPurify) |
| Clipboard Copy | `useCopyToClipboard.ts` |
| Message Delete/Feedback/Regenerate/Edit | `MessageView.tsx` + `App.tsx` |
| Code Block Copy | `MessageView.tsx` |
| Chart/Video/Image/JSON/Truncated Rendering | `MessageView.tsx` multiple blocks |
| Save/Bookmark Messages | `useSavedMessages.ts` |

---

## 3. Implementation Phases

### Phase 1 — Foundation & Quick Wins (target: chat-spa v0.8.0)

**Goal:** Establish shared event-bus bridge, close the three highest-visibility small gaps.

1. **`useChatEventBus` hook** — bridge `window.wpMcpAiJobBus` → React state
2. **`useChatSessionStream` hook** — wrap cron-status SSE endpoint
3. **GAP-12** — Usage/Cost Badges
4. **GAP-13** — Capability Flag Badges
5. **GAP-14** — Dark Mode Toggle (in-UI button)
6. **GAP-06** — Keyboard Shortcuts + Help Modal

**Deliverable:** A chat SPA that "feels complete" for text-only daily use.

### Phase 2 — Voice & Audio (target: v0.9.0)

**GAP-01** — Full voice pipeline: TTS, STT, voice chat, translate.

### Phase 3 — Jobs & Async Tools (target: v0.10.0)

**GAP-02** — Job Cards, Tasks Drawer, Tab Badge  
**GAP-04** — Async Tool & Crawl4AI Polling  
**GAP-11** — Cron Status Integration

### Phase 4 — Agent Panel (target: v0.11.0)

**GAP-03** — Agent Panel, Workflow Tracker, Delegation Notices

### Phase 5 — Export & Composer Extras (target: v0.12.0)

**GAP-05** — Export  
**GAP-07** — Suggested Prompts  
**GAP-08** — Tool Shortcuts  
**GAP-09** — CPT Action Buttons

### Phase 6 — Embedded Chat (target: v0.13.0)

**GAP-10** — Embedded Chat Client adapter

### Phase 7 — Polish (target: v1.0.0)

**GAP-15** through **GAP-19** — Quota monitor, vector store preload, history search, title editing, pagination.

---

## 4. Architecture Notes

### Shared primitives

Several gaps depend on the same foundations:

```
SSE Event Stream (mcp-ai/v1/chat-client)
  ├── Tool result annotations  → GAP-02 Job Cards, GAP-03 Agents, GAP-04 Async
  ├── Usage/cost annotations   → GAP-12 Usage Badges
  ├── Memory events            → (already handled by MemoryDrawer)
  └── Status events            → GAP-11 Cron Status

window.wpMcpAiJobBus
  ├── job:started/step/progress/completed/failed/cancelled
  ├── → GAP-02 Job Cards, Tasks Drawer
  └── → GAP-04 Async Tool Results (event-bus polling mode)
```

Building `useChatEventBus` in Phase 1 creates the bridge that Phases 2–4 all rely on.

### SSE Adapter extensibility

The existing `sse-adapter.ts` already handles `tool_call_started`, `tool_call_completed`, `memory_event`, `done`, and `annotation` frame types.  New frame types (agent events, job events, async events) will be added as they are needed in later phases.

### Code organization

```
src/
├── api/                      # (existing)
├── components/
│   ├── chat/                 # (existing: MessageView, TranscriptsSidebar, ...)
│   ├── badges/               # NEW: UsageBadges, CapabilityFlagBadges
│   ├── jobs/                 # NEW: JobCard, TasksDrawer
│   ├── agents/               # NEW: AgentPanel, WorkflowTracker, DelegationNotice
│   ├── voice/                # NEW: SpeechButton, TranscribeButton, VoiceChatButton
│   └── composer/             # NEW: SuggestedPrompts, ToolShortcuts, ExportButton
├── hooks/
│   ├── useChatEventBus.ts     # NEW (Phase 1)
│   ├── useChatSessionStream.ts # NEW (Phase 1)
│   ├── useKeyboardShortcuts.ts # NEW (Phase 1)
│   ├── useDarkMode.ts          # NEW (Phase 1)
│   ├── useSpeechPlayback.ts    # NEW (Phase 2)
│   ├── useAudioRecording.ts    # NEW (Phase 2)
│   ├── useVoiceChat.ts         # NEW (Phase 2)
│   ├── useJobs.ts              # NEW (Phase 3)
│   ├── useAsyncToolResult.ts   # NEW (Phase 3)
│   └── (existing hooks)
└── utils/
    ├── export-conversation.ts  # NEW (Phase 5)
    └── (existing: sse-adapter.ts, markdown.ts)
```

---

## 5. Risk Register

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|-----------|
| Behavior parity bugs from legacy state-machine | Medium | High | Vitest unit tests for every new hook; compare output with legacy on same inputs |
| SSE adapter needs frame-type additions | Low | Medium | `translateFrame()` is a single switch — add cases as needed per phase |
| Global `wpMcpAiJobBus` singleton conflicts with React lifecycle | Low | Medium | Use `useSyncExternalStore` (React 18+) or effect + cleanup pattern |
| Two chat UIs in production during migration | Low | Low | SPA uses different shortcode (`[nvoos_chat_spa]`) — no collision |
| Attachment handling diverges between legacy and SPA | Low | Low | SPA already has `useAttachments`; voice media is a separate pipeline |

---

## 6. Success Criteria

- [ ] All 19 legacy features present in chat-spa
- [ ] Full Vitest test coverage for all new hooks and components
- [ ] Zero `console.error` in chat-spa during a full interaction session
- [ ] Legacy `chat.js` can be deprecated (not removed) with a filter flag
- [ ] Chat-SPA passes `npm run lint:a11y` with zero warnings
- [ ] Chat-SPA passes `npm run typecheck` with zero errors
