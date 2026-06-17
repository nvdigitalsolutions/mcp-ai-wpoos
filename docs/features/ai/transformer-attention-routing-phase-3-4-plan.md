# Implementation Plan — Remaining Phases (3 & 4)

> **Status:** Research complete. Implementation not yet scheduled.  
> **Based on:** Anthropic Context Engineering (2026), Zylos Dynamic Context Assembly (2026), Generative Agents (Park et al., 2023), LangSmith/Langfuse observability patterns, Elasticsearch Weighted RRF.

---

## Phase 3: Agent Cross-Attention & Dynamic Context Assembly

### 3.1 — Substrate/Projection Context Engine

**Industry basis:** Zylos Research (Mar 2026) — "The prompt is a view, not storage." Anthropic (2026) — "Thoughtfully curating what information enters the model's limited attention budget at each step."

**Problem:** Currently every agent in the Planner→Executor→Critic cycle receives the full conversation context. The Planner dumps its entire plan into the Executor's prompt. The Executor's tool results pile up in the Critic's context. This wastes tokens and causes the "Lost in the Middle" problem (Liu et al., 2023) where relevant information buried mid-context is poorly attended to.

**Solution:** A `WP_MCP_AI_Context_Assembly_Engine` (singleton, `includes/data/class-wp-mcp-ai-context-assembly-engine.php`, ~600 lines) that treats each agent's context window as a **projection** assembled from persistent substrate on demand.

```
Persistent Substrate                    Ephemeral Projection (per agent)
────────────────────────────            ─────────────────────────────
System prompt (cached)         ┌─PINNED (stable, cacheable)
Tool definitions (cached)      │
Assistant preferences          │
                               ├─SESSION SUMMARY (static)
Prior plan steps (decision log)│  • Decisions made
Prior tool results (fact log)  │  • Facts established
Prior critique feedback        │  • Open questions
                               │
Conversation turns             ├─RETRIEVED (dynamic, per-agent)
Agent memory CCT entries       │  • Relevant to current step
Knowledge graph nodes          │  • Within token budget
                               │
                               └─RECENT RAW (last N turns)
                                  • Current observation
```

**Layout (cache-aware):**

```
┌──────────────────────────────────────┐ ← cache breakpoint 1
│ PINNED (stable prefix)               │   System prompt, tools
│ → hits KV cache on every call        │
├──────────────────────────────────────┤ ← cache breakpoint 2
│ SESSION SUMMARY (static per session)  │   Decisions, facts, questions
├──────────────────────────────────────┤
│ RETRIEVED (dynamic per inference)     │   Semantic search from substrate
│ → ordered by decreasing relevance    │
├──────────────────────────────────────┤
│ RECENT RAW (last 5 turns)            │   Current observation last
└──────────────────────────────────────┘
```

**API surface:**

| Method | Purpose |
|---|---|
| `assemble_for($agent_role, $task_class, $token_budget)` | Build projection for a specific agent role |
| `get_pinned_region()` | Returns system prompt + tool defs (cached prefix) |
| `get_session_summary()` | Returns decision log, fact log, open questions |
| `retrieve_relevant($query, $budget)` | Vector search across agent memory, knowledge graph, prior results |
| `get_recent_raw($n)` | Last N conversation turns at full fidelity |
| `project_to_budget($regions, $budget)` | Allocates token budget across regions proportionally |

**Token budget allocation (per-agent):**

| Agent | Pinned | Summary | Retrieved | Recent | Reserve |
|---|---|---|---|---|---|
| **Planner** | 30% | 10% | 30% | 10% | 20% |
| **Executor** | 20% | 10% | 40% | 20% | 10% |
| **Critic** | 20% | 20% | 30% | 20% | 10% |

The Planner gets more pinned context (system instructions matter most for planning). The Executor gets more retrieved context (needs tool-relevant data). The Critic gets more summary (needs to compare output against decisions).

**Strategy:** The `Context_Assembly_Engine` is the **single source of truth** for "what each agent sees." It replaces scattered context-construction code in the chat service and agent orchestrator. The existing `maybe_compress_messages()` and `WP_MCP_AI_Conversation_Compressor` become feeders into this engine rather than independent consumers.

---

### 3.2 — Agent Cross-Attention Context Passing

**Industry basis:** Generative Agents (Park et al., 2023) — tripartite memory scoring: `score = α·recency + β·importance + γ·relevance`. Multi-agent patterns (FutureAGI, 2026) — planner-executor, supervisor-worker, maker-checker.

**Problem:** Currently the Planner→Executor→Critic cycle passes context linearly: the Planner's full output is injected into the Executor's system prompt; the Executor's full tool results go to the Critic. There's no weighting, no filtering, no attention — every agent sees everything.

**Solution:** A `WP_MCP_AI_Agent_Cross_Attention` service (singleton, `includes/agents/class-wp-mcp-ai-agent-cross-attention.php`, ~400 lines) that applies **attention-weighted context passing** between agent roles.

**How it works:**

1. **Planner produces a plan** with N steps, each scored for relevance to the overall goal
2. **Executor receives only the relevant sub-task**, not the full plan. Other sub-tasks are summarized to 1-line references
3. **Executor's tool results are scored** for information density (tool output size × structured-ness)
4. **Critic receives Executor's output weighted by relevance** to the Planner's original intent — not the full Executor transcript

**Scoring formula (Generative Agents-inspired):**

```
cross_attention_score = α · recency + β · importance + γ · relevance

where:
  recency    = e^(-λ · age_in_turns)     [exponential decay, λ=0.1]
  importance = LLM-rated 0-1             [cached per plan step]
  relevance  = cosine_similarity(         [embedding match]
                 embed(current_query),
                 embed(step_description)
               )
```

**Implementation pattern:**

```php
class WP_MCP_AI_Agent_Cross_Attention {
    // Weight coefficients (tuneable via filters).
    const DEFAULT_ALPHA = 0.3;  // Recency weight
    const DEFAULT_BETA  = 0.3;  // Importance weight
    const DEFAULT_GAMMA = 0.4;  // Relevance weight
    
    /**
     * Score a context item for relevance to the current agent's task.
     *
     * @param array  $item         Context item (plan step, tool result, critique).
     * @param string $agent_role   'planner', 'executor', or 'critic'.
     * @param string $current_focus What the agent is currently working on.
     * @return float Cross-attention score (0–1).
     */
    public function score_context_item( $item, $agent_role, $current_focus );
    
    /**
     * Filter context items for an agent, keeping only those above
     * the attention threshold.
     *
     * @param array  $items        Candidate context items.
     * @param string $agent_role   Target agent role.
     * @param string $current_focus Current task focus.
     * @param int    $max_items    Maximum items to pass through.
     * @return array Filtered and scored items.
     */
    public function attend( $items, $agent_role, $current_focus, $max_items );
}
```

**Cross-agent flow:**

```
Planner
  │
  │ produces: [PlanStep₁(score=0.9), PlanStep₂(0.7), PlanStep₃(0.3)]
  │
  ▼
Cross-Attention filter: keep steps with score > 0.5
  │
  │ passes: [PlanStep₁(full), PlanStep₂(full), PlanStep₃("also: verify edge cases")]
  │
  ▼
Executor — only sees relevant sub-tasks, not the entire plan
  │
  │ produces: [ToolResult₁(size=5KB), ToolResult₂(size=200B), ToolResult₃(size=50KB)]
  │
  ▼
Cross-Attention filter: score by information density
  │
  │ passes: [ToolResult₁(summarized to 500B), ToolResult₂(full), ToolResult₃(truncated to 2KB)]
  │
  ▼
Critic — sees compressed Executor output, compared against Planner's intent
```

**Integration hook:** `wp_mcp_ai_agent_cross_attention_score` (filter) — Pro can swap in learned importance model.

---

### 3.3 — Positional Encoding for Multi-Turn Dialogue

**Industry basis:** Generative Agents (Park et al., 2023) — exponential recency decay. "Lost in the Middle" (Liu et al., 2023) — models attend best to beginning and end of context. RoPE (Rotary Position Embedding) — multiplicative positional encoding in modern Transformers.

**Problem:** Conversation messages are passed to the LLM as a flat array with no positional weighting. A system message from turn 1 and a user message from turn 50 are treated identically by the plugin's context assembly, even though their positions carry fundamentally different information value.

**Solution:** A `WP_MCP_AI_Positional_Context` utility (static, `includes/data/class-wp-mcp-ai-positional-context.php`, ~200 lines) that assigns **positional weights** to conversation messages before assembly.

**Weight assignment:**

| Message Position | Weight | Rationale |
|---|---|---|
| System prompt (role definition) | 1.0 (always included) | Stable prefix, cacheable |
| System prompt (dynamic context) | 0.8 | Important but changes per call |
| Most recent user message | 1.0 | Highest attention — this is what the agent must respond to |
| Second most recent user message | 0.95 | Nearly as important |
| Nth recent user message | `e^(-0.05 · n)` | Exponential decay; drops below 0.5 after ~14 turns |
| Tool result (high density) | +0.3 bonus | Tool outputs carry high information density |
| Tool result (low density / error) | -0.1 penalty | Error messages are less valuable for future context |
| Assistant message (tool_calls only) | 0.3 | Structural, not semantic — lower value |

**Implementation:**

```php
class WP_MCP_AI_Positional_Context {
    const DECAY_RATE = 0.05;       // λ in e^(-λ·position)
    const TOOL_RESULT_BOOST = 0.3; // Bonus for high-density tool results
    const MIN_WEIGHT = 0.1;        // Floor — never fully drop a message
    
    /**
     * Assign positional weights to a messages array.
     *
     * @param array $messages Chat messages (role/content pairs).
     * @return array Messages with '_position_weight' key added.
     */
    public static function weight( array $messages );
    
    /**
     * Sort + filter messages by positional weight, respecting budget.
     *
     * System messages are pinned to the beginning regardless of weight.
     * Non-system messages are ordered: most recent (weighted) → older (decayed).
     *
     * @param array $messages      Weighted messages.
     * @param int   $token_budget  Maximum tokens for non-system messages.
     * @return array Filtered messages.
     */
    public static function pack_by_weight( array $messages, $token_budget );
}
```

**RoPE-inspired multiplicative encoding (future Pro enhancement):**

The current exponential decay is additive (a simple weight multiplier). A RoPE-inspired approach would encode position multiplicatively into the attention score:

```
attention_score(i, j) = relevance(i, j) · e^(i·θ_j)

where θ_j is a learned or heuristically-set rotation angle per position.
```

This is more complex but provides sharper discrimination between nearby positions. Keep as a Pro-only enhancement referenced in the class docblock.

---

### 3.4 — Prompt Optimizer Enhancement (Self-Attention Assembly)

**Industry basis:** Zylos (2026) — "The assembly engine is the query planner — it optimizes what to include given the constraints of the context budget, the retrieval cost, and the caching topology." Anthropic (2026) — "Put the most critical information first or last, not in the middle."

**Problem:** The existing `WP_MCP_AI_Prompt_Optimizer` only handles cache-hit ordering (static → dynamic). It doesn't reorder or filter components by relevance to the current query, and it doesn't respect the "Lost in the Middle" finding that critical info should be at the beginning or end.

**Solution:** Enhance `WP_MCP_AI_Prompt_Optimizer` (~200 lines added) with a **self-attention pass** over the assembled prompt components before sending to the LLM.

**New methods:**

```php
class WP_MCP_AI_Prompt_Optimizer {
    // Existing: order_for_cache_hit(), generate_cache_key(), split_system_prompt()
    
    // NEW: Self-attention reordering.
    public static function order_by_relevance( array $components, $query_embedding );
    
    // NEW: Lost-in-the-Middle mitigation.
    public static function interleave_retrieved( array $retrieved_chunks );
    
    // NEW: Component scoring for budget trimming.
    public static function trim_to_budget( array $scored_components, $token_budget );
}
```

**Self-attention pass algorithm:**

```
1. Embed each prompt component (system prompt, memory docs, tool defs, conversation)
2. Compute attention scores: component_embedding · query_embedding
3. Sort components by descending attention score
4. Place top-1 component at BEGINNING (positional bias)
5. Place top-2 component at END (positional bias)
6. Fill middle in descending order
7. Interleave retrieved chunks (not batched) to avoid mid-context burial
```

**Layout after self-attention:**

```
┌──────────────────────────────────────────┐
│ Top-1: Most relevant component            │ ← attended first
├──────────────────────────────────────────┤
│ Top-3, Top-4, … (descending relevance)    │
├──────────────────────────────────────────┤
│ Top-2: Second most relevant               │ ← attended last (recency bias)
└──────────────────────────────────────────┘
```

---

## Phase 4: Ecosystem Maturation

### 4.1 — Admin Attention Dashboard

**Industry basis:** LangSmith/Langfuse tracing dashboards. Datadog LLM observability — latency heatmaps, token consumption metrics, trace-level drill-down.

**Problem:** There is no visibility into which tools the attention router selected vs. omitted per request. Admins can't tune top-K, head weights, or see the impact of attention routing on token usage.

**Solution:** An admin dashboard page (`includes/admin/class-wp-mcp-ai-attention-dashboard.php`, ~500 lines) accessible under **NV oOS → Attention Routing**.

**Dashboard sections:**

| Section | What it shows |
|---|---|
| **Overview cards** | Requests today, avg tools sent, token savings %, cache hit rate |
| **Attention heatmap** | 2D grid: queries (rows) × tools (columns), color = attention score. Interactive: hover to see per-head breakdown |
| **Per-head distribution** | Bar chart: semantic, capability, recency, dependency, risk scores averaged across requests |
| **Tool omission log** | Table: tools that were available but NOT selected, with reason (low semantic score / capability denied / dependency missing / risk filtered) |
| **Token savings graph** | Line chart: tokens-used-before-attention vs. tokens-used-after-attention over time |
| **Configuration** | Top-K slider (10–100), per-head weight sliders (0–1), enable/disable toggle, mandatory tools list editor |

**Data source:** The attention router already caches scores in `$last_scores`. Add a lightweight logger that persists the score breakdown per request to a custom table or options-based ring buffer.

**Chart library:** Reuse the existing `WP_MCP_AI_Chart_JS_Helper` already in the codebase for the admin analytics dashboard.

---

### 4.2 — A/B Testing Framework

**Industry basis:** Standard A/B testing patterns adapted for LLM observability. Langfuse — experiment tracking with metric comparison. Braintrust — trace-level evaluation across prompt variants.

**Problem:** There's no way to measure whether attention routing actually improves outcomes (lower token usage, same or better tool-call accuracy, same or better user satisfaction).

**Solution:** A `WP_MCP_AI_AB_Test_Runner` (static, `includes/data/class-wp-mcp-ai-ab-test-runner.php`, ~300 lines) that implements a simple A/B test framework.

**Design:**

```php
class WP_MCP_AI_AB_Test_Runner {
    const VARIANT_CONTROL   = 'full_tools';    // All tools sent to LLM
    const VARIANT_ATTENTION = 'attention_topk'; // Attention-filtered top-K
    
    /**
     * Determine which variant to use for this request.
     *
     * Uses consistent hashing on session key for sticky assignment.
     * Control/experiment split is configurable (default 50/50).
     *
     * @param string $session_key Stable session identifier.
     * @return string 'full_tools' or 'attention_topk'.
     */
    public static function assign_variant( $session_key );
    
    /**
     * Record an outcome metric for the current variant.
     *
     * @param string $variant  Variant identifier.
     * @param string $metric   Metric name (tokens_used, tool_accuracy, latency_ms).
     * @param float  $value    Metric value.
     */
    public static function record( $variant, $metric, $value );
    
    /**
     * Get summary statistics comparing variants.
     *
     * @param string $metric    Metric to compare.
     * @param int    $days      Lookback window.
     * @return array{control: array, experiment: array, p_value: float, winner: string}
     */
    public static function compare( $metric, $days = 7 );
}
```

**Metrics tracked:**

| Metric | Collection method |
|---|---|
| `tokens_input` | Read from LLM API response `usage.prompt_tokens` |
| `tokens_total` | Read from LLM API response `usage.total_tokens` |
| `tool_call_success_rate` | Ratio of successful tool executions to total tool calls |
| `tool_call_count` | Number of tool calls per request |
| `latency_first_token_ms` | Time from request start to first LLM token |
| `latency_total_ms` | Total request wall time |
| `user_feedback` | Thumbs up/down from chat UI (if enabled) |

**Statistical test:** Welch's t-test for continuous metrics, chi-squared for binary metrics. Configurable significance threshold (default p < 0.05).

---

### 4.3 — Graphify Migration to Unified Vector Service

**Problem:** `NV_oOS_Graphify_Embeddings` has its own embedding logic, cosine similarity, and vector unpacking — duplicating `WP_MCP_AI_Vector_Context_Service`. The attention router also has its own `static_cosine()` fallback.

**Solution:** Migrate Graphify to call `wp_mcp_ai_get_vector_context_service()` instead of its own embedding logic. This is a refactor, not new functionality.

**Steps:**

1. Add `embed_batch()` method to `WP_MCP_AI_Vector_Context_Service` (Graphify needs batch embedding for reindexing)
2. Update `NV_oOS_Graphify_Embeddings::generate_and_store()` to delegate to the vector service
3. Keep `NV_oOS_Graphify_Embeddings::store()` / `get()` / `search()` — these operate on Graphify's own table, which is fine
4. Remove the duplicate cosine_similarity and unpack_vector from Graphify (use `WP_MCP_AI_Tool_Embedding_Store::unpack_vector()` or a shared utility)
5. Deprecation notice: `NV_oOS_Graphify_Embeddings::generate_and_store()` calls the vector service internally but keeps the same public signature

**Estimated effort:** 4–6 hours. Primarily refactoring, no new features.

---

### 4.4 — Learned Weights (Pro)

**Industry basis:** Reinforcement Learning for LLM-based Multi-Agent Systems through Orchestration Traces (May 2026). Logistic regression over eval-harness outcomes. DSPy-style automatic prompt optimization.

**Problem:** The static head weights (semantic=0.50, capability=0.15, recency=0.10, dependency=0.15, risk=0.10) and RRF stage weights (attention=1.0, harness=1.0 for general tasks) are educated guesses. They should be learned from actual usage data.

**Solution:** A Pro-only `WP_MCP_AI_Learned_Router` that overrides `wp_mcp_ai_harness_tool_score` with a logistic regression model trained on eval-harness outcomes.

**Training data:** For each tool call in the audit trail:
- **Features:** semantic score, capability score, recency score, dependency score, risk score, task class, tool slug
- **Label:** 1 if tool call succeeded and contributed to task completion, 0 otherwise

**Model:** Logistic regression (scikit-learn compatible, exportable as JSON weights). Small enough to run inference in PHP without an ML runtime:

```php
// Inference: dot product of weights · features, passed through sigmoid.
$logit = $bias;
foreach ( $features as $i => $value ) {
    $logit += $weights[ $i ] * $value;
}
$probability = 1.0 / ( 1.0 + exp( -$logit ) );
```

**Training pipeline (offline, not in-plugin):**
1. Export audit trail data via WP-CLI: `wp mcp-ai export-training-data --days=30`
2. Train logistic regression in Python (scikit-learn) or R
3. Export model as JSON: `{"bias": 0.1, "weights": [0.5, -0.2, 0.3, ...], "feature_names": [...]}`
4. Upload via admin UI or place in `wp-content/uploads/mcp-ai/learned-router.json`
5. The `WP_MCP_AI_Learned_Router` class reads the JSON and applies weights via the `wp_mcp_ai_harness_tool_score` filter

**Fallback:** When no learned model is uploaded, the static weights are used (current behavior).

---

## Implementation Roadmap (Remaining)

### Phase 3: Agent Cross-Attention (~2 weeks)

| # | Component | File | Effort |
|---|---|---|---|
| 3.1 | `WP_MCP_AI_Context_Assembly_Engine` | `includes/data/class-wp-mcp-ai-context-assembly-engine.php` | L (600 lines) |
| 3.2 | `WP_MCP_AI_Agent_Cross_Attention` | `includes/agents/class-wp-mcp-ai-agent-cross-attention.php` | M (400 lines) |
| 3.3 | `WP_MCP_AI_Positional_Context` | `includes/data/class-wp-mcp-ai-positional-context.php` | S (200 lines) |
| 3.4 | Enhance `WP_MCP_AI_Prompt_Optimizer` | `includes/class-wp-mcp-ai-prompt-optimizer.php` | M (200 lines added) |
| 3.5 | Wire into chat service + agent orchestrator | `includes/services/class-wp-mcp-ai-chat-service.php`, `includes/services/class-wp-mcp-ai-agent-team-orchestrator.php` | M |
| 3.6 | Tests | `tests/test-context-assembly-engine.php`, `tests/test-agent-cross-attention.php` | M |

### Phase 4: Ecosystem Maturation (~3 weeks)

| # | Component | File | Effort |
|---|---|---|---|
| 4.1 | Admin Attention Dashboard | `includes/admin/class-wp-mcp-ai-attention-dashboard.php` | L (500 lines) |
| 4.2 | A/B Test Runner | `includes/data/class-wp-mcp-ai-ab-test-runner.php` | M (300 lines) |
| 4.3 | Graphify migration | `addons/graphify/includes/class-nvoos-graphify-embeddings.php` | S (refactor) |
| 4.4 | Learned Weights (Pro) | `addons/pro/includes/harness/class-wp-mcp-ai-learned-router.php` | M (400 lines) |
| 4.5 | WP-CLI export command | `includes/cli/class-wp-mcp-ai-cli-export-training-data.php` | S (200 lines) |
| 4.6 | Tests | Multiple test files | M |

---

## Industry References

| Source | Key Insight Applied |
|---|---|
| **Zylos Research — Dynamic Context Assembly** (Mar 2026) | Substrate/projection separation, cache-aware layout, token budget allocation per region. Directly informs 3.1. |
| **Anthropic — Effective Context Engineering** (2026) | "Curating what information enters the model's limited attention budget." Pinned + dynamic region layout. Informs 3.1 and 3.4. |
| **Generative Agents — Park et al.** (2023) | Tripartite memory scoring: `α·recency + β·importance + γ·relevance`. Exponential recency decay. Directly informs 3.2 and 3.3. |
| **"Lost in the Middle" — Liu et al.** (2023) | Positional attention bias — critical info must be at beginning or end, never buried mid-context. Informs 3.4 layout. |
| **LangSmith / Langfuse / Datadog LLM** (2025–2026) | Trace-level drill-down, latency heatmaps, token consumption dashboards. Informs 4.1. |
| **RL for Multi-Agent Orchestration Traces** (May 2026) | Credit assignment across agent roles. Logistic regression over eval outcomes. Informs 4.4. |

---

## Architectural Principles (Unix Theory applied to context)

| Principle | Application |
|---|---|
| **P0: Do one thing well.** | `Context_Assembly_Engine` assembles projections. `Agent_Cross_Attention` scores relevance. `Positional_Context` weights positions. Each is a single-responsibility component. |
| **P1: Expect output to become input.** | Every agent's output is scored and filtered before it becomes the next agent's input. Cross-attention is the filter. |
| **P2: Fail safe.** | All components degrade to the current "pass everything" behavior when dependencies are absent. No agent is blocked. |
| **P3: Compose, don't inherit.** | The assembly engine composes the pinned region, summary, retrieved context, and recent window. Each region is independently pluggable. |
| **P4: Budget explicitly.** | Every component operates within a declared token budget. No unbounded context growth. |
| **P5: Cache by design, not by accident.** | The pinned region is designed to hit the KV cache. Stable content precedes dynamic content. Cache hit rate is a tracked metric. |
| **P6: Observe everything.** | The A/B test runner and attention dashboard close the loop — you can measure whether attention routing actually helps. |
