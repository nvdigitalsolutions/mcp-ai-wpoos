# Transformer-Inspired Attention Routing

> **What it is.** A two-stage tool selection pipeline that applies Transformer architecture concepts — QKV attention, multi-head scoring, pre-computed KV cache, sliding-window attention — to the plugin's ~830-tool registry. Instead of sending every tool definition to the LLM, the attention router pre-filters to the top-40 most semantically relevant tools, then the harness Layer C re-ranks them via Reciprocal Rank Fusion (RRF) with structural capability-flag scores.
>
> **Where it lives.** `includes/data/` (attention router, embedding store, conversation compressor) and `includes/harness/` (RRF fusion in `Tool_Router_Harness`). Loaded by `includes/data/data-init.php` via `includes/bootstrap/loader.php`.
>
> **Opt-in/out.** On by default for assistants with >30 tools. Disable globally via `wp_mcp_ai_attention_routing_enabled` filter or per-request via `force_full` option. Conversation compression is off by default (enable via `wp_mcp_ai_enable_conversation_compression` option).

---

## Transformer Analogy

| Transformer Concept | Plugin Implementation |
|---|---|
| **Query (Q)** | Embedding of the user's query + system prompt |
| **Key (K)** | Pre-computed embedding of each tool's name + description + parameter names |
| **Value (V)** | The tool's capability (its function definition sent to the LLM) |
| **Attention(Q, K)** | Cosine similarity between query embedding and tool embeddings → top-K selection |
| **Multi-Head Attention** | 5 independent scoring heads: semantic, capability, recency, dependency, risk |
| **KV Cache** | `wp_mcp_ai_tool_embeddings` table stores pre-computed float32 vectors, refreshed on tool definition change |
| **Sliding Window** | Most recent N messages kept in full; older messages compressed into 3-aspect summaries (decisions, facts, questions) |
| **Positional Encoding** | System messages preserved at fixed positions; recent messages weighted higher; tool results carry boosted weight |

---

## Two-Stage Pipeline

```
                    ~830 registered tools
                          │
                          ▼
          ┌──────────────────────────────┐
          │ Stage 1: Attention Router      │
          │ (data/class-wp-mcp-ai-tool-  │
          │  attention-router.php)        │
          │                              │
          │ Q = embed(query + sys prompt) │
          │ K = pre-computed from DB      │
          │ Attention = cosine(Q, K)      │
          │                              │
          │ 5-head scoring:               │
          │  • Semantic    (0.50 weight)  │
          │  • Capability  (0.15 weight)  │
          │  • Recency     (0.10 weight)  │
          │  • Dependency  (0.15 weight)  │
          │  • Risk        (0.10 weight)  │
          │                              │
          │ → top-40 candidates           │
          └──────────────┬───────────────┘
                         │
                    ~40 tools
                         │
                         ▼
          ┌──────────────────────────────┐
          │ Stage 2: Harness Layer C       │
          │ (harness/class-wp-mcp-ai-    │
          │  tool-router-harness.php)     │
          │                              │
          │ Per-tool structural scoring:  │
          │  • Capability flags × task    │
          │    class matrix               │
          │  • Assistant preferences      │
          │  • Preset-family weights      │
          │                              │
          │ Fuse with attention scores    │
          │ via Weighted RRF (k=60):      │
          │  w1/(k+rank1) + w2/(k+rank2)  │
          │                              │
          │ → final ranked tool list      │
          └──────────────┬───────────────┘
                         │
                         ▼
                  LLM payload
```

---

## Components

### `WP_MCP_AI_Tool_Attention_Router`
**File:** `includes/data/class-wp-mcp-ai-tool-attention-router.php`  
**Pattern:** Singleton, 789 lines

Main entry point: `select_tools($query_text, $tool_slugs, $options)` — returns top-K tool slugs ordered by descending attention score.

**Public API:**
| Method | Purpose |
|---|---|
| `select_tools()` | Main entry point. Scores all tools, returns top-K slugs. |
| `get_attention_breakdown()` | Detailed per-head scores for diagnostics. |
| `get_cached_scores()` | Retrieve `slug → score` map from last `select_tools()` call (for harness RRF fusion). |
| `compute_tool_embedding()` | Static: pre-compute and store embedding for one tool (called by WP-Cron). |

**Configurable filters:**
| Filter | Default | Purpose |
|---|---|---|
| `wp_mcp_ai_attention_routing_enabled` | `true` | Master on/off |
| `wp_mcp_ai_attention_top_k` | `40` | Number of tools to select |

### `WP_MCP_AI_Tool_Embedding_Store`
**File:** `includes/data/class-wp-mcp-ai-tool-embedding-store.php`  
**Pattern:** Static methods, 395 lines  
**Table:** `{prefix}wp_mcp_ai_tool_embeddings`

Store + retrieve float32-packed embedding vectors keyed by `(tool_slug, provider_id, model)`. Uses `text_hash` (MD5 of source text) for invalidation — when a tool's definition changes, the embedding is recomputed.

### `WP_MCP_AI_Conversation_Compressor`
**File:** `includes/data/class-wp-mcp-ai-conversation-compressor.php`  
**Pattern:** Singleton, 562 lines

Sliding-window attention for multi-turn dialogue:
- Keeps last N messages (default 10) at full fidelity
- Compresses older messages into 3-aspect summaries (decisions, facts, questions)
- LLM-based summarization with extractive keyword fallback
- Off by default; enable via `wp_mcp_ai_enable_conversation_compression` option

### `WP_MCP_AI_Tool_Router_Harness` (enhanced)
**File:** `includes/harness/class-wp-mcp-ai-tool-router-harness.php`  
**Added in 1.8.0:** RRF fusion, attention score integration

New public method: `fuse_with_rrf($harness_scores, $attention_scores, $task_class)` — combines heterogeneous ranking signals via Weighted Reciprocal Rank Fusion with k=60 (industry standard from Elasticsearch, OpenSearch, MongoDB).

New filters: `wp_mcp_ai_harness_rrf_weight_harness` / `wp_mcp_ai_harness_rrf_weight_attention` — tune stage weights per task class.

---

## Integration Hooks

| Hook | Priority | What happens |
|---|---|---|
| `wp_mcp_ai_tool_registered` | 10 | Schedules async embedding pre-computation via WP-Cron |
| `wp_mcp_ai_attention_tool_slugs` | 20 | Filters tool slugs in `build_tools_payload()` before LLM payload conversion |
| `wp_mcp_ai_chat_options` | 1 | Captures last user message for attention query text |
| `wp_mcp_ai_chat_options` | 15 | Conversation compressor applies sliding-window |
| `wp_mcp_ai_harness_tool_score` | 5 | Bridge: feeds cached attention scores into harness scoring pipeline |
| `wp_mcp_ai_tool_embedding_compute` | — | WP-Cron action: computes one tool's embedding |
| `wp_mcp_ai_after_activation` | — | Installs `wp_mcp_ai_tool_embeddings` table |

---

## Graceful Degradation (Unix Theory P6: fail safe)

Every component degrades to current behavior when dependencies are unavailable:

| Scenario | Behavior |
|---|---|
| No vector service / embedding API | Attention router returns all tools (no filtering) |
| No embedding API key configured | Semantic head scores neutral (0.5 for all tools) |
| No audit trail available | Recency head scores neutral (0.5 for all tools) |
| LLM summarization fails | Extractive keyword-based fallback (no API call) |
| Conversation compression disabled | Messages pass through unchanged |
| ≤30 tools registered | Attention routing bypassed (not beneficial) |
| `force_full` option set | All tools sent to LLM |

---

## Remaining Phases (Future Work)

> **Detailed implementation plan:** [`transformer-attention-routing-phase-3-4-plan.md`](transformer-attention-routing-phase-3-4-plan.md)

### Phase 3: Agent Cross-Attention (Pro — not yet scheduled)
- **Cross-agent attention context:** Planner → Executor → Critic weighted context passing. Each agent attends to its predecessor's outputs with relevance weighting, analogous to encoder-decoder cross-attention in Transformers.
- **Positional encoding for multi-turn dialogue:** Exponential decay weights for conversation messages (system=0, most recent=1.0, second=0.95, …). Tool results carry boosted weight. RoPE-inspired multiplicative encoding.

### Phase 4: Ecosystem Maturation (Pro — not yet scheduled)
- **Admin UI:** Attention heatmaps showing which tools were selected/omitted per request. Per-assistant head-weight configuration. Top-K slider.
- **A/B testing framework:** Compare attention-routed vs. full-tool-list request outcomes (latency, token usage, tool-call accuracy).
- **Graphify migration:** Move `NV_oOS_Graphify_Embeddings` to use the unified `WP_MCP_AI_Vector_Context_Service`.
- **Learned weights:** Replace static head weights with logistic regression over eval-harness outcomes (Pro override of `wp_mcp_ai_harness_tool_score`).

---

## Industry References

| Source | Key Insight |
|---|---|
| **Anthropic Tool Search Tool** (Nov 2025) | Same `defer_loading` pattern. 85% token reduction, accuracy improved from 49% to 74% on Opus 4. 134K tokens of tool definitions before optimization. |
| **Spring AI Smart Tool Selection** (Dec 2025) | 34-64% token savings with dynamic tool discovery. Warns: "tool selection accuracy degrades when models face 30+ similarly-named tools." |
| **Pinecone / Elastic / SBERT** | Two-stage retrieval: bi-encoder (recall) → cross-encoder (precision). Industry standard for search pipelines. |
| **Elasticsearch Weighted RRF** | `Σ w_i × 1/(k + rank_i)` with k=60. Immune to score-scale differences between heterogeneous retrievers. |
| **Vaswani et al. "Attention Is All You Need"** (2017) | The original Transformer paper. Multi-head attention, QKV projections, positional encoding — the architectural inspiration. |

---

## Tests

```bash
# Existing harness tests (Layer C backwards-compat verified)
vendor/bin/phpunit tests/test-harness-services.php

# Attention router tests (to be added)
vendor/bin/phpunit tests/test-attention-router.php
vendor/bin/phpunit tests/test-tool-embedding-store.php
vendor/bin/phpunit tests/test-conversation-compressor.php
```

---

## Also Load

- [`docs/features/llm-harness.md`](llm-harness.md) — Full harness Layers A–H reference
- [`includes/data/README.md`](../../includes/data/README.md) — Data layer public surface
- [`includes/harness/README.md`](../../includes/harness/README.md) — Harness subsystem overview
- [`CLAUDE.md`](../../CLAUDE.md) — PHP-compat, tool envelope, two-gate sanitisation
- [`.context/conventions.md`](../../.context/conventions.md) — Naming and style
