# Pro SPA v2 — Migration Plan: Conversations as Primary

> **Branch:** `feat/spa-v2-conversations-primary`
> **Base:** `alpha-working`
> **Status:** In Progress

---

## 1. Problem Statement

The current pro-spa-v2 architecture is **thread-first**: the `ChatPage` requires a `threadId` from the URL to render the chat surface. This is backwards from the proven chat-spa pattern where **conversations (transcripts) are the primary chat unit** and **threads are a read-only browse view**.

### Industry Standards & Research

| Source | Key Finding |
|---|---|
| **AI Chat UI Best Practices 2026** (TheFrontKit) | Offer conversation branching for long sessions but keep primary flow linear |
| **Conversational AI UI Comparison 2025** (IntuitionLabs) | Every conversation is saved in sidebar; threads group related chats but don't own the transport |
| **Chat Interface Patterns** (agentic-design.ai) | Threading = branching & merging logic; conversations = the primary linear unit |
| **Chatbot UI Design Patterns 2026** (FuseLab) | Keep interface linear, invest in conversation history and search |
| **Vercel AI SDK** (`useChat`) | `id` parameter binds to session identity — conversations should own it |
| **OpenAI ChatGPT** | Conversations are the primary unit; each has a stable ID |
| **Claude (Anthropic)** | Projects group chat threads; conversations remain the primary unit |

**Conclusion:** Conversations (transcripts/sessions) own the chat transport. Threads are an optional organizational layer for grouping/browsing, never the gatekeeper.

---

## 2. Current Architecture Problems

```
pro-spa-v2 (BROKEN):
┌─ ChatPage ──────────────────────────────────────────┐
│  requires activeThreadId from URL param              │
│  if (!activeThreadId) → Welcome screen (blocks chat) │
│  handleNewThread creates a thread → navigates /chat/:id│
│  useTranscripts is instantiated but NOT used for chat │
│  thread messages loaded via useEffect                 │
└──────────────────────────────────────────────────────┘

┌─ ChatSidebar ───────────────────────────────────────┐
│  "New Thread" button creates thread                  │
│  Thread list has archive/delete buttons              │
│  Conversations tab can select/delete but doesn't     │
│  switch the chat (ChatPage doesn't wire it)           │
└──────────────────────────────────────────────────────┘

┌─ useThreads ────────────────────────────────────────┐
│  Full CRUD: create, archive, restore, summarize      │
│  Talks directly to ThreadsClient                     │
└──────────────────────────────────────────────────────┘
```

```
chat-spa (CORRECT):
┌─ useTranscriptSession ──────────────────────────────┐
│  • Generates sessionKey (or loads from localStorage) │
│  • sessionKey → useChat's `id` param                 │
│  • selectSession() → hydrate initialMessages, switch │
│  • startNewSession() → fresh key, empty messages     │
│  • onFinish → persistFinishedTurn() → transcripts    │
└──────────────────────────────────────────────────────┘

┌─ useThreadsSidebar (READ-ONLY) ─────────────────────┐
│  • list() threads for sidebar tab                    │
│  • getMessages() → load as read-only view            │
│  • deselectThread() on tab switch                    │
└──────────────────────────────────────────────────────┘
```

---

## 3. Migration Plan

### Phase 1: ChatPage — Remove Thread-First Guard

**File:** `src/features/chat/ChatPage.tsx`

- Remove the welcome screen (`!activeThreadId` guard)
- Remove `handleNewThread`, `isCreating`, `activeThreadId` as chat requirement
- Remove `threadInitialMessages`, `threadMessagesLoaded`, `threadTitle` state
- Remove the `useEffect` that loads thread messages
- Remove `ThreadsClient` direct instantiation
- Make `useTranscripts` the primary chat driver:
  - `transcripts.sessionKey` → `useChatSpoke`'s `id` (already wired)
  - `transcripts.selectSession()` → switches chat to that conversation
  - `transcripts.startNewSession()` → starts a fresh chat
  - `transcripts.initialMessages` → seeds `useChatSpoke`'s `initialMessages`
- Retain model/profile selectors, MemoryDrawer, HITL bar, AgentPanel
- Add thread read-only view: when a thread is selected from sidebar, load its messages as a read-only initial view but keep transport on chat-client

### Phase 2: useThreads — Make Read-Only

**File:** `src/hooks/useThreads.ts`

- Rename/refactor to match `useThreadsSidebar` pattern from chat-spa
- Remove: `createThread`, `archiveThread`, `restoreThread`, `summarizeThread`
- Keep: `list()`/`fetchThreads()`, `getMessages()`, `activeThreadId`, `setActiveThread`
- Add: `deselectThread()`, `threadInitialMessages` for read-only message loading

### Phase 3: ChatSidebar — Conversations First

**File:** `src/components/layout/ChatSidebar.tsx`

- Remove `useThreads` import (use simplified version)
- Remove "New Thread" button and `handleNewThread`
- Remove archive/delete buttons from thread rows
- Keep "Conversations" tab as default
- Wire `onSelectSession` to actually switch the chat (pass through to ChatPage)
- Thread selection loads messages into chat as read-only view
- Add proper `deselectThread` when switching to Conversations tab

### Phase 4: Layout — Unify Transcript Instantiation

**File:** `src/components/layout/Layout.tsx`

- Currently `LayoutContent` instantiates `useTranscripts` for sidebar
- `ChatPage` re-instantiates its own `useTranscripts` — wasteful
- Lift `useTranscripts` to `LayoutContent` level
- Pass full transcript result to both `ChatSidebar` AND `ChatPage`
- Remove duplicate instantiation in `ChatPage`

### Phase 5: Router — Simplify Chat Route

**File:** `src/router.tsx`

- Remove `/chat/:threadId` route
- Keep only `/chat` as the primary chat route
- Thread browsing stays in sidebar (no URL routing needed)

### Phase 6: StatusBar — Conversation Count

**File:** `src/components/layout/StatusBar.tsx`

- Replace thread count with conversation count
- Use `useTranscripts` instead of `useThreads` for connection status

### Phase 7: API — Keep Methods, Mark Usage

**File:** `src/api/threads.ts`

- Keep `create`, `archive`, `restore`, `summarize` methods (used elsewhere)
- Add JSDoc noting these are NOT used in the primary chat flow
- `createChatFetch` adapter stays unchanged

### Phase 8: Tests

**Files:** `src/__tests__/pro-spa.test.ts`, `src/__tests__/memory.test.tsx`, etc.

- Update tests to reflect conversation-first architecture
- Add tests for transcript session selection/switching
- Update thread tests to read-only assertions

---

## 4. Files Changed (Complete List)

| File | Change | Priority |
|---|---|---|
| `src/features/chat/ChatPage.tsx` | Remove thread-first guard, make conversations primary | P0 |
| `src/hooks/useThreads.ts` | Simplify to read-only (match `useThreadsSidebar`) | P0 |
| `src/components/layout/ChatSidebar.tsx` | Remove thread CRUD, wire conversation switching | P0 |
| `src/components/layout/Layout.tsx` | Lift `useTranscripts`, pass to ChatPage | P1 |
| `src/router.tsx` | Remove thread-ID route | P1 |
| `src/components/layout/StatusBar.tsx` | Show conversation count, not thread count | P2 |
| `src/api/threads.ts` | Mark mutators as non-primary-flow | P2 |
| `src/__tests__/pro-spa.test.ts` | Update tests for new architecture | P1 |

---

## 5. Rollback Plan

If the migration introduces regressions:
1. All old files are preserved in `alpha-working` branch
2. `api/threads.ts` mutators remain (just marked)
3. `useThreads` hook can be re-expanded if thread-first flow is needed elsewhere
4. Router restoration is a single-line revert

---

## 6. Validation Checklist

- [ ] Chat starts with a conversation session (no thread required)
- [ ] "New Chat" button in sidebar starts a fresh session
- [ ] Clicking a conversation in sidebar loads its messages and switches chat
- [ ] Deleting a conversation from sidebar removes it
- [ ] Threads tab shows read-only thread list
- [ ] Clicking a thread loads its messages as read-only view
- [ ] Switching from Threads to Conversations deselects thread
- [ ] Model/profile selectors work
- [ ] Memory drawer opens/closes
- [ ] HITL approval bar polls correctly
- [ ] Streaming responses work
- [ ] Messages persist to transcripts after each turn
- [ ] All 86 existing tests pass
- [ ] New tests for conversation switching pass
