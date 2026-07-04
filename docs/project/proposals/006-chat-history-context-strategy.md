# Proposal 006: Chat History Context Strategy — BME Architecture

**Status:** Draft
**Author:** AI Agent (2026-07-04)
**Target:** mcp-ai-wpoos v2.x
**Scope:** Legacy chat client (`chat.js` + server-side REST `enforce_chat_request_limits`)

---

## 1. Summary

The legacy chat client currently uses a **pure sliding window** strategy: keep the last N messages (default 8) and drop everything older. This causes the assistant to lose context when the user returns to the page after inactivity — it sees only the most recent messages, not the conversation's intent, decisions, or constraints established earlier.

This proposal introduces a **Beginning-Middle-End (BME)** hybrid architecture:
- **Beginning**: System prompt + optional conversation summary (semantic compression of oldest turns)
- **Middle**: Summarized "middle" turns (LLM-compressed history)
- **End**: Full-fidelity recent messages (last N turns, verbatim)

The BME pattern mirrors industry standards (LangChain's `ConversationSummaryBufferMemory`, OpenAI's context management guidance, Microsoft Agent Framework reducers) and resolves the "repeating the first message" regression seen after page reloads.

---

## 2. Current State

### 2.1 Architecture Diagram

```
Client (chat.js)                                  Server (class-wp-mcp-ai-rest.php)
┌───────────────────────┐          POST /chat-client           ┌──────────────────────────┐
│ state.conversation[]  │ ──────────────────────────────────► │ enforce_chat_request_    │
│ (full in-memory list) │   payload: { messages, ... }        │   limits()               │
│                       │                                     │                          │
│ localStorage backup   │                                     │ 1. Count tokens          │
│ (24h TTL)             │                                     │ 2. Count messages        │
└───────────────────────┘                                     │ 3. slice(-max_history)   │
                                                              │ 4. System msgs preserved  │
                                                              └──────────────────────────┘
```

### 2.2 Current Limitations

| Issue | Impact | Root Cause |
|---|---|---|
| **Context loss on page return** | Assistant repeats early messages / forgets decisions | localStorage 24h expiry, no semantic compression |
| **Pure sliding window** | Old but critical context silently dropped | `array_slice($other_messages, -$N)` in `enforce_chat_request_limits` |
| **No summarization** | Every dropped turn loses semantic content forever | No summary pipeline exists |
| **Single scalar limit** | Only `max_history_messages` (default 8) controls everything | No token-aware trimming, no per-model limits |
| **Tool results bloating** | Long tool outputs (crawls, file reads) eat message budget | No tool-result summarization before append |
| **Embedded provider divergence** | Client-side `slice(-maxHistoryMessages)` for embedded, server-side `enforce_` for cloud — two code paths | Duplicated trimming logic |

### 2.3 Key Files

| File | Role |
|---|---|
| `assets/js/chat.js` | Client state: `state.conversation[]`, `sendChat()`, `restoreConversationFromStorage()`, `generateEmbeddedCompletion()` |
| `assets/js/chat-storage-service.js` | localStorage persistence (24h TTL, `STORAGE_EXPIRY_MS`) |
| `includes/class-wp-mcp-ai-rest.php` | Server: `enforce_chat_request_limits()` (lines 7269–7420), `handle_chat_request()` |
| `includes/admin/class-wp-mcp-ai-admin-settings.php` | Settings: `max_history_messages` field (line 5776+) |

---

## 3. Industry Standards Research

### 3.1 The Three Canonical Strategies

| Strategy | How It Works | Pros | Cons |
|---|---|---|---|
| **Sliding Window** (current) | Keep last N messages, drop oldest | Simple, zero latency, predictable cost | Loses all semantic context from dropped turns |
| **Summarization** | LLM compresses old history into a summary message when threshold exceeded | Preserves semantic content, moderate complexity | Extra API call (~200–500ms), summary quality varies |
| **External Memory (RAG)** | Store past turns as embeddings, retrieve relevant ones per-query | Cross-session persistence, high fidelity retrieval | Requires vector DB, embedding model, retrieval pipeline |

### 3.2 The Hybrid Gold Standard: ConversationSummaryBufferMemory

LangChain's `ConversationSummaryBufferMemory` is widely considered the pragmatic default for production agents:

> "A hybrid approach: keep the last N interactions verbatim for recent accuracy, and summarize everything older than that threshold."
> — LangChain documentation

```
┌─────────────────────────────────────────────────────────────┐
│ [System Prompt]                                              │  ← Always preserved
├─────────────────────────────────────────────────────────────┤
│ [Summary: "User asked about X, decided to use Y because Z"]  │  ← Compressed history
├─────────────────────────────────────────────────────────────┤
│ User: "What about the pricing?"                              │  ← Full fidelity
│ Assistant: "The pricing model is..."                         │  ← recent turns
│ User: "Can I get a discount?"                                │
│ Assistant: "Let me check..."                                 │
└─────────────────────────────────────────────────────────────┘
```

### 3.3 Key Production Insights (from General Compute, Mem0, OpenAI, Microsoft)

1. **Drop complete turns, not half-turns.** Removing a user message while keeping the assistant response creates "orphan" messages that confuse the model.
2. **Always preserve the system prompt.** It defines persona, constraints, and instructions.
3. **Summarize earlier and more frequently.** Waiting until near context limit means summarizing a huge block in one go — quality degrades.
4. **Token-aware trimming > message-count trimming.** A single message with a 10K-token tool result costs more than 50 short messages.
5. **70–80% context capacity is the trigger point.** Summarize when approaching 80% of model context window, not when exceeding it.
6. **Tool result summarization.** Large tool outputs (crawls, file reads) should be summarized before appending to the message list.

### 3.4 The "Beginning-Middle-End" Pattern

This is the conceptual model the user referenced and maps directly to the hybrid architecture:

- **Beginning** = System prompt + domain knowledge + conversation summary
- **Middle** = Compressed/condensed older conversation turns
- **End** = Full-fidelity recent messages (the "working memory")

---

## 4. Proposed Solution: BME Architecture

### 4.1 Conceptual Model

```
┌──────────────────────────────────────────────────────────────────┐
│ BEGINNING (always preserved, never trimmed)                       │
│  ├─ System prompt (persona, constraints, tool definitions)        │
│  ├─ Professional role context                                     │
│  └─ Conversation summary (auto-generated when middle overflows)   │
├──────────────────────────────────────────────────────────────────┤
│ MIDDLE (compressed older history, replaced by summary)            │
│  ├─ Summarized turns 1..(N - END_SIZE)                            │
│  └─ Key decisions, facts, constraints preserved                   │
├──────────────────────────────────────────────────────────────────┤
│ END (full-fidelity recent messages, verbatim)                     │
│  ├─ Last (END_SIZE) user + assistant turns                        │
│  ├─ Active tool calls + results                                   │
│  └─ Current context for immediate replies                         │
└──────────────────────────────────────────────────────────────────┘
```

### 4.2 New Settings

| Setting | Type | Default | Description |
|---|---|---|---|
| `context_strategy` | enum | `bme` | Which strategy: `sliding_window`, `bme`, `bme_rag` |
| `end_window_size` | int | 10 | Number of full-fidelity message turns to keep (END zone) |
| `summary_trigger_count` | int | 30 | When total non-system messages exceed this, trigger summarization |
| `summary_trigger_tokens` | int | 0 | Alternative: trigger summarization at token threshold (0 = disabled, uses count) |
| `summary_model` | string | `default` | Model to use for summarization (`default` = assistant model, or specific model slug) |
| `max_summary_tokens` | int | 500 | Max tokens for the generated summary |
| `tool_result_summarize_threshold` | int | 2000 | Tool results > N chars get summarized before context storage |
| `max_history_messages` | int | 8 | *(kept for backward compat — becomes `end_window_size` in BME mode)* |

### 4.3 Server-Side Flow (`enforce_chat_request_limits` rewrite)

```
enforce_chat_request_limits(messages, attachments, context):
    1. Separate system messages from conversation messages
    2. Separate "beginning" meta-messages (summary, memory) from normal turns
    3. Apply END_WINDOW_SIZE: keep last N turns verbatim
    4. If MIDDLE zone exceeds SUMMARY_TRIGGER_COUNT:
       a. Extract middle messages
       b. Call summary model to compress
       c. Replace middle with single summary message
    5. Apply token budget as final safety net
    6. Recombine: beginning + summary + end
```

### 4.4 Client-Side Changes (`chat.js`)

1. **Conversation state enhancement**: Add `summary` field to `state.conversation` metadata
2. **`sendChat()` update**: Pass `context_strategy` parameter, let server handle BME (client stays thin)
3. **localStorage enhancement**: Store summary alongside messages for faster page-return restores
4. **`restoreConversationFromStorage()`**: When rehydrating, ensure beginning context is rebuilt, not just raw message list
5. **Tool result trimming**: Truncate/summarize large tool results client-side before storing in `state.conversation[]`

### 4.5 Summary Generation (New Server-Side Module)

```php
class WP_MCP_AI_Conversation_Summarizer {
    /**
     * Summarize a list of messages into a compact context block.
     *
     * @param array  $messages      Messages to summarize.
     * @param string $summary_model Model slug for the summary LLM call.
     * @param int    $max_tokens    Max tokens for the output summary.
     * @return string|WP_Error     Summary text or error.
     */
    public function summarize(array $messages, string $summary_model = '', int $max_tokens = 500);

    /**
     * Check if summarization should trigger based on current message state.
     */
    public function should_summarize(array $messages, int $trigger_count, int $trigger_tokens): bool;
}
```

---

## 5. Implementation Plan (Phased)

### Phase 1: Foundation (v2.0)

**Goal**: Ship BME server-side without breaking existing clients.

- [ ] Create `WP_MCP_AI_Conversation_Summarizer` class
- [ ] Add `context_strategy` setting (default: `sliding_window` — preserves current behavior)
- [ ] Add `end_window_size`, `summary_trigger_count`, `summary_model`, `max_summary_tokens` settings
- [ ] Refactor `enforce_chat_request_limits()` to support BME strategy via strategy pattern
- [ ] Add `trim_messages_bme()` method to REST class
- [ ] Add PHPUnit tests for summarizer and BME trimming
- [ ] Add `wp_mcp_ai_context_strategy` filter for programmatic overrides

### Phase 2: Client Integration (v2.1)

**Goal**: Client sends `context_strategy` parameter; localStorage handles summaries.

- [ ] Add `context_strategy`, `end_window_size` to `globalConfig` in chat.js
- [ ] Add `summary` field to localStorage data model
- [ ] Update `sendChat()` to pass strategy parameters
- [ ] Tool result summarization in `stripMessageDisplayMetadata()`
- [ ] Client-side token estimation for embedded provider path
- [ ] JS integration tests for storage round-trips with summaries

### Phase 3: Advanced Memory (v2.2+)

**Goal**: External memory (RAG) for cross-session persistence.

- [ ] `bme_rag` strategy: combine BME with vector-store retrieval
- [ ] Auto-store important decisions/facts as retrievable memories
- [ ] Per-user long-term memory via existing JetEngine CCT infrastructure
- [ ] Memory importance scoring and decay
- [ ] Chat-memory bridge integration (already partially exists: `requestWakeUpContext()`)

---

## 6. Backward Compatibility

| Concern | Mitigation |
|---|---|
| Existing `max_history_messages` setting | Preserved; in sliding-window mode, unchanged. In BME mode, mapped to `end_window_size`. |
| Legacy Webhook integrations (WhatsApp, Discord, Slack, etc.) | They bypass `enforce_chat_request_limits` or use their own history — unaffected. |
| Embedded/WebLLM client path | Strategy applied client-side for embedded; server-side for cloud. |
| localStorage format | New `summary` field is optional; old format loads without error. |
| REST API schema | `context_strategy` is optional; omitting defaults to `sliding_window`. |

---

## 7. Success Metrics

| Metric | Current | Target |
|---|---|---|
| Context loss on page return (24h+) | 100% (all context gone) | 0% (summary preserves key context) |
| Assistant repeats early messages after reload | Frequent | Eliminated |
| Token waste from full-history sends | Variable (depends on conversation length) | Capped at BME budget |
| Summary quality (subjective) | N/A | Preserves ≥80% of key decisions |
| Extra latency from summarization | N/A | <500ms per trigger event |

---

## 8. Risks and Mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Summary model hallucinates or misrepresents context | Medium | Use same provider as assistant; configurable summary model; store summary alongside raw messages for audit |
| Extra API cost for summarization calls | Medium | Only trigger when threshold exceeded; use cheaper/faster model for summaries; cache summary |
| BME complexity introduces bugs in agentic workflows | Medium | Strategy pattern isolates BME code; feature flag; graduated rollout |
| Embedded providers (WebLLM) can't summarize server-side | Low | Client-side LLM can generate summaries too; or use server-side fallback |

---

## 9. Alternatives Considered

| Alternative | Why Not Chosen |
|---|---|
| **Pure sliding window (status quo)** | Fails the core UX problem: loses context on page return |
| **Full RAG/memory only (no sliding window)** | Too complex for MVP; requires vector DB infrastructure |
| **Just increase `max_history_messages`** | Doesn't fix root cause; higher token costs, still eventually drops old context |
| **Persist full history to server** | Privacy concerns; server storage cost; doesn't solve context window limit |

---

## 10. References

- [General Compute: Multi-Turn Conversations in LLM APIs](https://www.generalcompute.com/blog/multi-turn-conversations-llm-apis-best-practices-agents)
- [Microsoft Agent Framework: Managing Chat History](https://devblogs.microsoft.com/agent-framework/managing-chat-history-for-large-language-models-llms/)
- [LangChain: ConversationSummaryBufferMemory](https://langchain-doc.readthedocs.io/en/latest/modules/memory/types/summary_buffer.html)
- [Mem0: LLM Chat History Summarization Guide 2025](https://mem0.ai/blog/llm-chat-history-summarization-guide-2025)
- [OpenAI Community: Best Practices for Context Management in Long AI Chats](https://community.openai.com/t/best-practices-for-cost-efficient-high-quality-context-management-in-long-ai-chats/1373996)
- [Redis: Context Window Overflow in 2026](https://redis.io/blog/context-window-overflow/)
- Internal: `docs/project/proposals/005-wp-cli-infrastructure-hardening.md` (proposal format template)
