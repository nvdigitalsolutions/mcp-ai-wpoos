# Context-1 Inspired Orchestration Enhancements

## Proposal: Applying Agentic Retrieval Best Practices to NV oOS

**Author:** NV Digital Solutions  
> **Status:** ⏳ Not implemented (v1.1.29) — None of the 3 proposed orchestration services exist
**Created:** 2026-03-27  
**Related Components:** Agent Context Manager, Prioritize Context Tool, Workflow Tools, Research Tools, Agentic Workflow Optimizer  
**Inspired By:** Chroma Context-1, Stanford ACE Framework, Anthropic Context Engineering

---

## Executive Summary

This proposal adapts three breakthrough concepts from Chroma's Context-1 agentic retrieval model and broader industry research into concrete enhancements for the NV oOS plugin orchestration, agent system, and workflow tools:

1. **Staged Retrieval Pipeline** (Recall → Precision): A two-phase context retrieval approach where agents first gather broadly, then progressively narrow to the most relevant documents.
2. **Self-Editing Context Window**: Agents dynamically prune their own context mid-workflow to free space for further exploration, preventing context rot.
3. **Scalable Synthetic Task Generation**: An extraction-based verification pipeline with LLM judge scoring for automated evaluation of agent and workflow quality.

These concepts directly address real-world limitations in WordPress-based AI orchestration: constrained token budgets, multi-step tool chains that accumulate irrelevant context, and the difficulty of systematically evaluating agent workflows.

---

## Table of Contents

- [Background & Motivation](#background--motivation)
- [Industry Research & Standards](#industry-research--standards)
- [Current Architecture Review](#current-architecture-review)
- [Enhancement 1: Staged Retrieval Pipeline](#enhancement-1-staged-retrieval-pipeline)
- [Enhancement 2: Self-Editing Context Window](#enhancement-2-self-editing-context-window)
- [Enhancement 3: Scalable Synthetic Task Generation](#enhancement-3-scalable-synthetic-task-generation)
- [Workflow Tool Integration](#workflow-tool-integration)
- [Implementation Phases](#implementation-phases)
- [File Manifest](#file-manifest)
- [Testing Strategy](#testing-strategy)
- [Performance Considerations](#performance-considerations)
- [Migration & Backward Compatibility](#migration--backward-compatibility)
- [References](#references)

---

## Background & Motivation

### The Problem

NV oOS currently provides powerful context management (Phase 5) and multi-agent orchestration (DeepSeek V4-inspired). However, several gaps exist:

1. **Flat Retrieval**: `retrieve_agent_memory` performs a single-pass keyword search. `semantic_context_search` performs a single-pass vector search. Neither implements iterative refinement—the agent gets one shot at finding relevant context.

2. **Passive Context Accumulation**: As agents execute multi-step workflows (research → analysis → synthesis), the context window fills with tool results from earlier steps. There is no mechanism for agents to actively prune stale or irrelevant context mid-workflow.

3. **Manual Quality Assurance**: Workflow validation (`validate_workflow`) checks syntax but not semantic quality. There is no automated pipeline for generating test scenarios and evaluating whether agent workflows produce correct results.

### Why Context-1 Matters for WordPress AI

Chroma's Context-1 demonstrated that a 20B-parameter model with a 32K token budget can outperform frontier models with much larger context windows by:
- Retrieving thoroughly first, then selecting carefully (staged retrieval)
- Actively pruning its own context mid-search (self-editing)
- Training on diverse synthetic multi-hop tasks with automated verification

These principles are directly applicable to NV oOS, where:
- WordPress sites have PHP memory constraints (32K-128K token budgets via `WP_MCP_AI_Resource_Manager`)
- Multi-agent workflows chain 5-15 tool calls, each adding to context
- Assistants serve diverse domains (e-commerce, content, research, regulatory)

---

## Industry Research & Standards

### Chroma Context-1 (2025)

**Key Innovation:** A 20B agentic search model trained with staged curriculum learning and self-editing context management.

- **Architecture**: Based on gpt-oss-20B, 32K token context window
- **Staged Training**: SFT + RL on 8,000+ synthetic multi-hop tasks; curriculum first optimizes for broad recall, then progressively trains for precision
- **Self-Editing**: Model trained to selectively prune its own context mid-search, freeing space for further exploration
- **Result**: Outperforms frontier models (GPT-4-class) with much larger context windows at 10x lower inference cost

**Source:** [Chroma Context-1 Technical Report](https://www.trychroma.com/research/context-1)

### Stanford ACE Framework (2025)

**Key Innovation:** Agentic Context Engineering with a three-agent system (Generator, Reflector, Curator) that evolves context through self-supervision.

- **Generator**: Executes tasks using current strategies from a living playbook
- **Reflector**: Analyzes outcomes, extracting lessons without human input
- **Curator**: Maintains and prunes the playbook, ensuring knowledge accumulates while irrelevant strategies are removed
- **Result**: +10.6% accuracy improvement on AppWorld benchmarks with no weight updates

**Source:** [ACE Paper (arXiv:2510.04618)](https://arxiv.org/abs/2510.04618), [GitHub](https://github.com/ace-agent/ace)

### Anthropic Context Engineering (2025)

**Key Principles:**
- Context is not just a prompt—it is a living, evolving workspace
- Effective agents curate relevant, up-to-date information dynamically
- "Context collapse" is a risk in long-running multi-turn agents
- Iterative automated curation is essential for production agents

**Source:** [Anthropic Engineering Blog](https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents)

### Additional Industry Standards

| Framework | Key Contribution | Relevance to NV oOS |
|-----------|-----------------|---------------------|
| **Milvus Context Pruning** | Developer guide for pruning RAG/agentic AI contexts | Directly applicable to `prioritize_context` tool |
| **DeepEval** | LLM evaluation framework with task completion, tool correctness metrics | Model for synthetic task verification |
| **AutoPlay** | Exploration-driven synthetic task generation for agents | Pattern for workflow test generation |
| **Contextual AI Benchmarks** | End-to-end agentic RAG evaluation methodology | Standards for measuring retrieval quality |

---

## Current Architecture Review

### Existing Components (Strengths)

NV oOS already has a strong foundation for these enhancements:

| Component | File | Capability |
|-----------|------|------------|
| **Agent Context Manager** | `includes/services/class-wp-mcp-ai-agent-context-manager.php` | Store, retrieve, search, prune contexts with TTL |
| **Prioritize Context Tool** | `includes/tools/class-wp-mcp-ai-tool-prioritize-context.php` | Score and select contexts within token budget |
| **Semantic Context Search** | `includes/tools/class-wp-mcp-ai-tool-semantic-context-search.php` | Vector embedding search via OpenAI |
| **Vector Context Service** | `includes/services/class-wp-mcp-ai-vector-context-service.php` | Embedding generation, cosine similarity, context optimization |
| **Context Compression** | `includes/services/class-wp-mcp-ai-context-compression-service.php` | Chunking, summarization, compression policies |
| **Token Budget Manager** | `includes/services/class-wp-mcp-ai-token-budget-service.php` | Model limits, budget calculation, message truncation |
| **Agentic Workflow Optimizer** | `includes/class-wp-mcp-ai-agentic-workflow-optimizer.php` | Tool result caching, compression, iteration prediction |
| **Team Orchestrator** | `includes/services/class-wp-mcp-ai-agent-team-orchestrator.php` | Multi-agent team composition, workflow execution |
| **Workflow Coordinator** | `includes/services/class-wp-mcp-ai-enhanced-workflow-coordinator.php` | Parallel/sequential workflow execution |
| **Execute Workflow Tool** | `includes/tools/class-wp-mcp-ai-tool-execute-workflow.php` | Create and execute multi-agent workflows |
| **Validate Workflow Tool** | `includes/tools/class-wp-mcp-ai-tool-validate-workflow.php` | Structural validation of workflow YAML/JSON |
| **Check Workflow Health** | `includes/tools/class-wp-mcp-ai-tool-check-workflow-health.php` | Diagnose workflow health and stuck states |
| **Deep Research Tool** | `includes/tools/class-wp-mcp-ai-tool-deep-research.php` | Multi-step web search with AI analysis |
| **Store Agent Context** | `includes/tools/class-wp-mcp-ai-tool-store-agent-context.php` | Persistent context storage with content ingestion |
| **Retrieve Agent Memory** | `includes/tools/class-wp-mcp-ai-tool-retrieve-agent-memory.php` | Keyword-based context search and retrieval |

### Current Gaps

| Gap | Current Behavior | Desired Behavior |
|-----|-----------------|------------------|
| **Single-pass retrieval** | One keyword or vector search, then done | Iterative: broad recall → narrow precision |
| **Static context window** | Context accumulates, only trimmed at budget limit | Agent actively prunes mid-workflow |
| **No inter-step pruning** | Tool results from step 1 remain through step 10 | Relevance-scored pruning between workflow steps |
| **No automated evaluation** | Manual testing, syntax-only validation | Synthetic task generation with LLM judge scoring |
| **No retrieval metrics** | No recall/precision tracking | Track and optimize retrieval quality over time |

---

## Enhancement 1: Staged Retrieval Pipeline

### Concept

Adapt Context-1's "recall first, then precision" staged training approach into a two-phase retrieval pipeline for agent context and research workflows.

**Phase 1 — Broad Recall**: Cast a wide net. Retrieve all potentially relevant contexts using multiple search strategies in parallel (keyword, semantic, tag-based, type-filtered). Intentionally over-retrieve to ensure nothing important is missed.

**Phase 2 — Precision Refinement**: Score, rerank, and filter the recall set using task-specific relevance signals. Apply the current task context to select only the most useful items within the token budget.

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    STAGED RETRIEVAL PIPELINE                    │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─── Phase 1: BROAD RECALL ──────────────────────────────┐    │
│  │                                                         │    │
│  │  ┌──────────┐  ┌───────────┐  ┌──────────┐            │    │
│  │  │ Keyword  │  │ Semantic  │  │ Tag/Type │            │    │
│  │  │ Search   │  │ Vector    │  │ Filter   │            │    │
│  │  │ (BM25)   │  │ Search    │  │ Search   │            │    │
│  │  └────┬─────┘  └─────┬─────┘  └────┬─────┘            │    │
│  │       │              │              │                   │    │
│  │       └──────────────┼──────────────┘                   │    │
│  │                      ▼                                  │    │
│  │           ┌──────────────────┐                          │    │
│  │           │  Merge & Dedup   │                          │    │
│  │           │  (Union Set)     │                          │    │
│  │           └────────┬─────────┘                          │    │
│  │                    │                                    │    │
│  │         Broad candidate set (N items)                   │    │
│  └────────────────────┼───────────────────────────────────┘    │
│                       ▼                                         │
│  ┌─── Phase 2: PRECISION REFINEMENT ─────────────────────┐    │
│  │                                                        │    │
│  │  ┌───────────────┐  ┌───────────────┐                 │    │
│  │  │ Task-Aware    │  │ Multi-Signal  │                 │    │
│  │  │ Reranking     │  │ Scoring       │                 │    │
│  │  │ (query match) │  │ (importance,  │                 │    │
│  │  │               │  │  recency,     │                 │    │
│  │  │               │  │  access freq) │                 │    │
│  │  └───────┬───────┘  └───────┬───────┘                 │    │
│  │          └──────────┬───────┘                          │    │
│  │                     ▼                                  │    │
│  │          ┌──────────────────┐                          │    │
│  │          │ Token Budget     │                          │    │
│  │          │ Fitting          │                          │    │
│  │          │ (top-K within    │                          │    │
│  │          │  budget)         │                          │    │
│  │          └────────┬─────────┘                          │    │
│  │                   │                                    │    │
│  │        Precision set (M items, M << N)                 │    │
│  └───────────────────┼───────────────────────────────────┘    │
│                      ▼                                         │
│               Final Context Window                              │
└─────────────────────────────────────────────────────────────────┘
```

### Implementation Plan

#### 1.1 New Service: `WP_MCP_AI_Staged_Retrieval_Service`

**File:** `includes/services/class-wp-mcp-ai-staged-retrieval-service.php`

This singleton service orchestrates the two-phase retrieval pipeline.

```php
class WP_MCP_AI_Staged_Retrieval_Service {

    /**
     * Execute staged retrieval for an agent's context.
     *
     * @param int|string $agent_id     Agent identifier.
     * @param array      $task_context Current task details (query, keywords, type).
     * @param int        $token_budget Maximum tokens for final context.
     * @param array      $options      Optional overrides (recall_limit, precision_strategy, etc.).
     * @return array {
     *     @type bool  $success        Whether retrieval succeeded.
     *     @type array $contexts       Final precision-filtered contexts.
     *     @type int   $recall_count   Items found in recall phase.
     *     @type int   $precision_count Items after precision filtering.
     *     @type int   $total_tokens   Tokens consumed by final set.
     *     @type array $metrics        Retrieval quality metrics (recall_breadth, precision_ratio).
     * }
     */
    public function retrieve( $agent_id, $task_context, $token_budget, $options = array() ) {
        // Phase 1: Broad Recall
        $recall_set = $this->phase_recall( $agent_id, $task_context, $options );

        // Phase 2: Precision Refinement
        $precision_set = $this->phase_precision( $recall_set, $task_context, $token_budget, $options );

        return $precision_set;
    }

    /**
     * Phase 1: Broad recall using multiple search strategies.
     *
     * Executes keyword search, semantic search, and tag/type filtering
     * in parallel, then merges and deduplicates results.
     *
     * @param int|string $agent_id     Agent identifier.
     * @param array      $task_context Task context with query, keywords, type.
     * @param array      $options      Recall options (recall_limit, search_strategies).
     * @return array Merged, deduplicated candidate contexts.
     */
    private function phase_recall( $agent_id, $task_context, $options ) {
        $recall_limit = isset( $options['recall_limit'] ) ? absint( $options['recall_limit'] ) : 50;
        $candidates   = array();

        // Strategy 1: Keyword search via Agent Context Manager.
        $keyword_results = $this->keyword_search( $agent_id, $task_context, $recall_limit );

        // Strategy 2: Semantic vector search via Vector Context Service.
        $semantic_results = $this->semantic_search( $agent_id, $task_context, $recall_limit );

        // Strategy 3: Type and tag-based filtering.
        $filter_results = $this->filter_search( $agent_id, $task_context, $recall_limit );

        // Merge and deduplicate by context_id.
        $candidates = $this->merge_and_deduplicate(
            $keyword_results,
            $semantic_results,
            $filter_results
        );

        return $candidates;
    }

    /**
     * Phase 2: Precision refinement using multi-signal scoring and budget fitting.
     *
     * Reranks the recall set using task-aware relevance scoring, importance,
     * recency, and access frequency, then selects top items within token budget.
     *
     * @param array $recall_set   Candidate contexts from Phase 1.
     * @param array $task_context Current task details.
     * @param int   $token_budget Maximum token budget.
     * @param array $options      Precision options (strategy, weights).
     * @return array Precision-filtered contexts with metrics.
     */
    private function phase_precision( $recall_set, $task_context, $token_budget, $options ) {
        $strategy = isset( $options['precision_strategy'] ) ? $options['precision_strategy'] : 'balanced';

        // Multi-signal scoring (delegates to prioritize_context logic).
        // Enhanced with cross-reference scoring: items referenced by other items score higher.
        $scored_items = $this->score_candidates( $recall_set, $task_context, $strategy );

        // Budget-aware selection.
        $selected = $this->fit_to_budget( $scored_items, $token_budget );

        return array(
            'success'         => true,
            'contexts'        => $selected['items'],
            'recall_count'    => count( $recall_set ),
            'precision_count' => count( $selected['items'] ),
            'total_tokens'    => $selected['total_tokens'],
            'budget_used_pct' => $selected['budget_used_pct'],
            'metrics'         => array(
                'recall_breadth'  => count( $recall_set ),
                'precision_ratio' => count( $recall_set ) > 0
                    ? round( count( $selected['items'] ) / count( $recall_set ), 3 )
                    : 0,
                'strategy'        => $strategy,
            ),
        );
    }
}
```

#### 1.2 New Tool: `staged_context_retrieval`

**File:** `includes/tools/class-wp-mcp-ai-tool-staged-context-retrieval.php`

Exposes staged retrieval as an AI-callable tool, enabling agents to use the pipeline directly.

```php
/**
 * Parameters Schema:
 *   agent_id     (required) - Agent identifier
 *   query        (required) - Search query for current task
 *   token_budget (required) - Maximum tokens for context (100-100000)
 *   keywords     (optional) - Additional keywords for recall broadening
 *   task_type    (optional) - Task type hint (research, analysis, content, etc.)
 *   strategy     (optional) - Precision strategy (balanced, recall_heavy, precision_heavy)
 *   recall_limit (optional) - Max items in recall phase (default: 50)
 *
 * Returns:
 *   success, contexts[], recall_count, precision_count, total_tokens,
 *   budget_used_pct, metrics{recall_breadth, precision_ratio, strategy}
 */
```

#### 1.3 Integration with Deep Research Tool

Enhance `WP_MCP_AI_Tool_Deep_Research` to use staged retrieval when `memory_agent_id` is provided:

```php
// Before research, recall prior relevant research from memory.
if ( ! empty( $memory_agent_id ) ) {
    $staged_retrieval = WP_MCP_AI_Staged_Retrieval_Service::get_instance();
    $prior_research   = $staged_retrieval->retrieve(
        $memory_agent_id,
        array(
            'query'    => $topic,
            'keywords' => $focus_areas,
            'type'     => 'research',
        ),
        4000, // Reserve 4K tokens for prior context.
        array( 'precision_strategy' => 'recall_heavy' )
    );

    // Inject prior research as additional context for the AI analysis step.
    if ( $prior_research['success'] && $prior_research['precision_count'] > 0 ) {
        $prior_context = $this->format_prior_research( $prior_research['contexts'] );
        // Append to analysis prompt...
    }
}
```

---

## Enhancement 2: Self-Editing Context Window

### Concept

Adapt Context-1's self-editing mechanism so that agents can actively prune their own context during multi-step workflows. Instead of passively accumulating tool results until the token budget overflows, the agent evaluates and discards less-relevant context between workflow steps.

This is critical for NV oOS because:
- WordPress PHP memory limits create hard token budget ceilings
- Multi-agent workflows (research → analysis → synthesis) chain 5-15 tool calls
- Each tool result adds to context, and earlier results may become irrelevant as the task evolves
- The existing `prioritize_context` tool operates reactively (called manually); we need proactive, automatic pruning

### Architecture

```
┌──────────────────────────────────────────────────────────────┐
│                SELF-EDITING CONTEXT WINDOW                   │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  Workflow Step 1          Workflow Step 2                     │
│  ┌──────────────┐         ┌──────────────┐                   │
│  │ Tool Result A │         │ Tool Result C │                  │
│  │ Tool Result B │         │ Tool Result D │                  │
│  └──────┬───────┘         └──────┬───────┘                   │
│         │                        │                           │
│         ▼                        ▼                           │
│  ┌─────────────────────────────────────────────────────┐    │
│  │            CONTEXT WINDOW (Token Budget)             │    │
│  │                                                      │    │
│  │  [System] [History] [A] [B] [C] [D]                 │    │
│  │                                                      │    │
│  │  Budget: 16,000 tokens                               │    │
│  │  Used:   14,200 tokens (88.8%)                       │    │
│  │  Free:    1,800 tokens ← NOT ENOUGH for Step 3      │    │
│  └──────────────────────┬──────────────────────────────┘    │
│                         │                                    │
│                    ┌────▼─────┐                               │
│                    │ SELF-EDIT│                               │
│                    │ DECISION │                               │
│                    └────┬─────┘                               │
│                         │                                    │
│              ┌──────────┼──────────┐                         │
│              ▼          ▼          ▼                          │
│         ┌────────┐ ┌────────┐ ┌────────┐                    │
│         │ KEEP   │ │COMPRESS│ │ DROP   │                    │
│         │ (high  │ │ (medium│ │ (low   │                    │
│         │ relev.)│ │ relev.)│ │ relev.)│                    │
│         └───┬────┘ └───┬────┘ └───┬────┘                    │
│             │          │          │                           │
│             ▼          ▼          ▼                           │
│  ┌─────────────────────────────────────────────────────┐    │
│  │            EDITED CONTEXT WINDOW                     │    │
│  │                                                      │    │
│  │  [System] [History] [A-compressed] [C] [D]          │    │
│  │                                                      │    │
│  │  Budget: 16,000 tokens                               │    │
│  │  Used:    9,400 tokens (58.8%)                       │    │
│  │  Free:    6,600 tokens ← Ready for Step 3           │    │
│  └─────────────────────────────────────────────────────┘    │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```

### Self-Edit Decision Logic

Each context item is evaluated on three axes:

```
┌─────────────────────────────────────────────────────┐
│           SELF-EDIT SCORING MATRIX                   │
├──────────────┬──────────┬───────────┬───────────────┤
│ Signal       │ Keep     │ Compress  │ Drop          │
├──────────────┼──────────┼───────────┼───────────────┤
│ Relevance    │ > 0.7    │ 0.3-0.7   │ < 0.3        │
│ Recency      │ Current/ │ 2-5 steps │ > 5 steps    │
│              │ adjacent │ ago       │ ago           │
│ References   │ Cited by │ Cited     │ Not cited    │
│              │ later    │ once      │              │
│ Uniqueness   │ Sole     │ Partial   │ Redundant    │
│              │ source   │ overlap   │ with others  │
├──────────────┼──────────┼───────────┼───────────────┤
│ Action       │ Retain   │ Summarize │ Remove       │
│              │ in full  │ to ~30%   │ entirely     │
└──────────────┴──────────┴───────────┴───────────────┘
```

### Implementation Plan

#### 2.1 New Service: `WP_MCP_AI_Context_Self_Editor`

**File:** `includes/services/class-wp-mcp-ai-context-self-editor.php`

This service implements proactive context pruning between workflow steps.

```php
class WP_MCP_AI_Context_Self_Editor {

    /**
     * Default threshold to trigger self-editing (percentage of budget used).
     */
    const EDIT_THRESHOLD = 0.70;

    /**
     * Minimum free tokens required before triggering edit.
     */
    const MIN_FREE_TOKENS = 2000;

    /**
     * Edit the context window to free space for upcoming steps.
     *
     * Evaluates each context item against the current task state and
     * upcoming step requirements, then keeps, compresses, or drops items
     * to maintain sufficient free budget.
     *
     * @param array $context_items   Current context window items with metadata.
     * @param int   $token_budget    Total token budget.
     * @param array $current_task    Current task context (query, step, type).
     * @param array $upcoming_steps  Information about remaining workflow steps.
     * @param array $options         Edit options (threshold, min_free, strategy).
     * @return array {
     *     @type array $edited_items     Items after editing (kept + compressed).
     *     @type array $dropped_items    Items that were removed.
     *     @type array $compressed_items Items that were compressed.
     *     @type int   $tokens_freed     Tokens recovered by editing.
     *     @type int   $tokens_used      Tokens used after editing.
     *     @type bool  $edit_performed   Whether any edits were made.
     *     @type array $decisions        Per-item decision log (keep/compress/drop + reason).
     * }
     */
    public function edit_context( $context_items, $token_budget, $current_task, $upcoming_steps = array(), $options = array() ) {
        $threshold  = isset( $options['threshold'] ) ? floatval( $options['threshold'] ) : self::EDIT_THRESHOLD;
        $min_free   = isset( $options['min_free_tokens'] ) ? absint( $options['min_free_tokens'] ) : self::MIN_FREE_TOKENS;
        $used       = $this->calculate_total_tokens( $context_items );
        $free       = $token_budget - $used;

        // Check if editing is needed.
        if ( ( $used / $token_budget ) < $threshold && $free >= $min_free ) {
            return array(
                'edited_items'   => $context_items,
                'dropped_items'  => array(),
                'compressed_items' => array(),
                'tokens_freed'   => 0,
                'tokens_used'    => $used,
                'edit_performed' => false,
                'decisions'      => array(),
            );
        }

        // Score each item.
        $decisions = $this->score_items( $context_items, $current_task, $upcoming_steps );

        // Apply decisions: keep, compress, or drop.
        return $this->apply_decisions( $decisions, $token_budget, $min_free );
    }

    /**
     * Score context items for keep/compress/drop decisions.
     *
     * @param array $items          Context items.
     * @param array $current_task   Current task context.
     * @param array $upcoming_steps Upcoming workflow steps.
     * @return array Scored items with decisions.
     */
    private function score_items( $items, $current_task, $upcoming_steps ) {
        $scored = array();

        foreach ( $items as $index => $item ) {
            $relevance  = $this->calculate_relevance( $item, $current_task, $upcoming_steps );
            $recency    = $this->calculate_recency( $item, $current_task );
            $references = $this->calculate_reference_score( $item, $items );
            $uniqueness = $this->calculate_uniqueness( $item, $items );

            // Weighted composite score.
            $composite = ( $relevance * 0.4 ) + ( $recency * 0.25 ) + ( $references * 0.2 ) + ( $uniqueness * 0.15 );

            // Decision thresholds.
            if ( $composite >= 0.7 ) {
                $decision = 'keep';
            } elseif ( $composite >= 0.3 ) {
                $decision = 'compress';
            } else {
                $decision = 'drop';
            }

            $scored[] = array(
                'item'       => $item,
                'index'      => $index,
                'scores'     => array(
                    'relevance'  => round( $relevance, 3 ),
                    'recency'    => round( $recency, 3 ),
                    'references' => round( $references, 3 ),
                    'uniqueness' => round( $uniqueness, 3 ),
                    'composite'  => round( $composite, 3 ),
                ),
                'decision'   => $decision,
            );
        }

        return $scored;
    }
}
```

#### 2.2 New Tool: `self_edit_context`

**File:** `includes/tools/class-wp-mcp-ai-tool-self-edit-context.php`

Allows AI agents to explicitly trigger context self-editing during workflows.

```php
/**
 * Parameters Schema:
 *   context_items  (required) - Current context window items
 *   token_budget   (required) - Total token budget (100-200000)
 *   current_task   (required) - {query, step_name, step_number, type}
 *   upcoming_steps (optional) - Array of upcoming step descriptions
 *   threshold      (optional) - Budget usage threshold to trigger edit (0.5-0.95, default: 0.70)
 *   min_free       (optional) - Minimum free tokens required (500-50000, default: 2000)
 *
 * Returns:
 *   success, edited_items[], dropped_count, compressed_count, tokens_freed,
 *   tokens_used, budget_used_pct, decisions[]
 */
```

#### 2.3 Integration with Workflow Execution

Enhance `WP_MCP_AI_Agent_Team_Orchestrator::execute_workflow_step()` to automatically trigger context self-editing between steps:

```php
// In execute_team_workflow(), between steps:
foreach ( $workflow['steps'] as $index => $step ) {
    // --- Self-Edit Context Before Step ---
    if ( $index > 0 && $this->should_self_edit( $accumulated_context, $token_budget ) ) {
        $self_editor = WP_MCP_AI_Context_Self_Editor::get_instance();
        $edit_result = $self_editor->edit_context(
            $accumulated_context,
            $token_budget,
            array(
                'query'       => $step['subtask'] ?? $task['description'] ?? '',
                'step_name'   => $step['name'] ?? '',
                'step_number' => $index,
                'type'        => $step['type'] ?? 'generic',
            ),
            array_slice( $workflow['steps'], $index + 1 ) // Upcoming steps
        );

        if ( $edit_result['edit_performed'] ) {
            $accumulated_context = $edit_result['edited_items'];
            $this->log_team_action( $team_id, 'context_self_edit', array(
                'step'            => $index,
                'tokens_freed'    => $edit_result['tokens_freed'],
                'dropped_count'   => count( $edit_result['dropped_items'] ),
                'compressed_count' => count( $edit_result['compressed_items'] ),
            ) );
        }
    }

    // Execute the step with pruned context.
    $step_result = $this->execute_workflow_step( $team, $step, $task, $context, $previous_results );
    // ...
}
```

#### 2.4 Integration with Enhanced Workflow Coordinator

Enhance `WP_MCP_AI_Enhanced_Workflow_Coordinator::execute_workflow()` with self-editing hooks:

```php
/**
 * Filter: wp_mcp_ai_before_workflow_step
 *
 * Fires before each workflow step, allowing context self-editing.
 *
 * @param array $context     Current accumulated context.
 * @param int   $step_index  Current step index.
 * @param array $workflow    Full workflow definition.
 * @param int   $token_budget Available token budget.
 */
$context = apply_filters(
    'wp_mcp_ai_before_workflow_step',
    $context,
    $step_index,
    $workflow,
    $token_budget
);
```

#### 2.5 Integration with Agentic Workflow Optimizer

Enhance `WP_MCP_AI_Agentic_Workflow_Optimizer` to track context editing metrics:

```php
// New metrics tracked:
// - context_edits: Number of self-edit operations performed
// - tokens_freed_total: Total tokens recovered across all edits
// - items_dropped_total: Total items dropped
// - items_compressed_total: Total items compressed
// - avg_budget_utilization: Average budget usage across steps
```

---

## Enhancement 3: Scalable Synthetic Task Generation

### Concept

Adapt Context-1's extraction-based verification pipeline into an automated system for generating test scenarios for NV oOS workflows and evaluating them using LLM judge scoring. This creates a continuous quality assurance loop.

The pipeline has four stages:

1. **Task Extraction**: Generate multi-step test tasks from real WordPress data (posts, products, settings)
2. **Task Execution**: Run tasks through the agent/workflow system
3. **LLM Judge Evaluation**: Score results for correctness, completeness, and relevance
4. **Metrics & Feedback**: Track quality over time and feed results back into optimization

### Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│            SYNTHETIC TASK GENERATION PIPELINE                   │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ┌─── Stage 1: TASK EXTRACTION ──────────────────────────┐     │
│  │                                                        │     │
│  │  WordPress Data Sources:                               │     │
│  │  ┌─────────┐  ┌──────────┐  ┌──────────┐             │     │
│  │  │ Posts/  │  │ Products │  │ Settings │             │     │
│  │  │ Pages   │  │ (Woo)    │  │ & Config │             │     │
│  │  └────┬────┘  └────┬─────┘  └────┬─────┘             │     │
│  │       └─────────────┼─────────────┘                    │     │
│  │                     ▼                                  │     │
│  │  ┌──────────────────────────────────┐                  │     │
│  │  │  LLM Task Generator              │                  │     │
│  │  │  - Multi-hop query generation    │                  │     │
│  │  │  - Ground truth extraction       │                  │     │
│  │  │  - Difficulty calibration        │                  │     │
│  │  └──────────────┬───────────────────┘                  │     │
│  │                 │                                      │     │
│  │     Generated tasks with expected outcomes             │     │
│  └─────────────────┼─────────────────────────────────────┘     │
│                    ▼                                            │
│  ┌─── Stage 2: TASK EXECUTION ───────────────────────────┐     │
│  │                                                        │     │
│  │  ┌──────────────────────────────────────────────┐     │     │
│  │  │  Execute task via:                            │     │     │
│  │  │  - Tool Execution Orchestrator               │     │     │
│  │  │  - Agent Team Orchestrator                   │     │     │
│  │  │  - Enhanced Workflow Coordinator              │     │     │
│  │  │                                               │     │     │
│  │  │  Capture: tool calls, context, timing,        │     │     │
│  │  │  token usage, errors                          │     │     │
│  │  └──────────────────────┬───────────────────────┘     │     │
│  │                         │                              │     │
│  │              Execution trace + result                  │     │
│  └─────────────────────────┼─────────────────────────────┘     │
│                            ▼                                    │
│  ┌─── Stage 3: LLM JUDGE EVALUATION ────────────────────┐     │
│  │                                                        │     │
│  │  ┌──────────────────────────────────────────────┐     │     │
│  │  │  LLM Judge scores on 5 dimensions:           │     │     │
│  │  │                                               │     │     │
│  │  │  1. Correctness  (0-1): Does result match     │     │     │
│  │  │     expected outcome?                         │     │     │
│  │  │  2. Completeness (0-1): Were all required     │     │     │
│  │  │     steps executed?                           │     │     │
│  │  │  3. Relevance    (0-1): Is the result          │     │     │
│  │  │     relevant to the original query?           │     │     │
│  │  │  4. Efficiency   (0-1): Were tools used       │     │     │
│  │  │     efficiently (no redundant calls)?         │     │     │
│  │  │  5. Safety       (0-1): Were permissions      │     │     │
│  │  │     and capabilities respected?               │     │     │
│  │  └──────────────────────┬───────────────────────┘     │     │
│  │                         │                              │     │
│  │              Scored evaluation (0-5 composite)         │     │
│  └─────────────────────────┼─────────────────────────────┘     │
│                            ▼                                    │
│  ┌─── Stage 4: METRICS & FEEDBACK ──────────────────────┐     │
│  │                                                        │     │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │     │
│  │  │ Quality     │  │ Performance │  │ Regression  │  │     │
│  │  │ Dashboard   │  │ Trends      │  │ Detection   │  │     │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  │     │
│  │                                                        │     │
│  │  Feed results back into:                               │     │
│  │  - Workflow optimization                               │     │
│  │  - Agent memory (store successful strategies)          │     │
│  │  - System prompt refinement                            │     │
│  └───────────────────────────────────────────────────────┘     │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

### Task Domains (Adapted from Context-1)

Context-1 uses 4 domains (web, SEC filings, patent law, email). For NV oOS, we adapt to WordPress-relevant domains:

| Context-1 Domain | NV oOS Domain | Example Multi-Hop Task |
|-------------------|---------------|------------------------|
| Web Search | **Content Discovery** | "Find all posts about AI published in the last month, summarize their themes, and identify which categories have the most engagement" |
| SEC Filings | **WooCommerce Analytics** | "Find the top 3 products by revenue this quarter, check their inventory levels, and draft a restock recommendation" |
| Patent Law | **Regulatory Compliance** | "Check all active registrations expiring in 90 days, validate their document checklists, and generate renewal submission packs" |
| Email | **Site Administration** | "Audit user roles with admin capabilities, check their last login dates, and generate a security report of dormant admin accounts" |

### Implementation Plan

#### 3.1 New Service: `WP_MCP_AI_Synthetic_Task_Service`

**File:** `includes/services/class-wp-mcp-ai-synthetic-task-service.php`

Generates synthetic multi-step tasks from WordPress site data.

```php
class WP_MCP_AI_Synthetic_Task_Service {

    /**
     * Supported task domains.
     */
    const DOMAINS = array(
        'content_discovery',
        'ecommerce_analytics',
        'regulatory_compliance',
        'site_administration',
    );

    /**
     * Difficulty levels.
     */
    const DIFFICULTIES = array(
        'simple'       => 1,  // 1-2 tool calls
        'moderate'     => 2,  // 3-5 tool calls
        'complex'      => 3,  // 6-10 tool calls
        'multi_hop'    => 4,  // 10+ tool calls, requires chaining clues
    );

    /**
     * Generate a batch of synthetic tasks for a given domain.
     *
     * @param string $domain     Task domain (content_discovery, ecommerce_analytics, etc.).
     * @param int    $count      Number of tasks to generate.
     * @param string $difficulty Difficulty level.
     * @param array  $options    Generation options.
     * @return array {
     *     @type array $tasks[] {
     *         @type string $task_id          Unique task identifier.
     *         @type string $domain           Task domain.
     *         @type string $difficulty       Difficulty level.
     *         @type string $description      Natural language task description.
     *         @type array  $required_tools   Tools expected to be used.
     *         @type array  $expected_outcome Ground truth or expected result pattern.
     *         @type array  $verification     Verification criteria for LLM judge.
     *         @type int    $estimated_steps  Expected number of steps.
     *     }
     * }
     */
    public function generate_tasks( $domain, $count = 10, $difficulty = 'moderate', $options = array() ) {
        // Validate domain.
        // Extract site data for grounding.
        // Generate tasks using LLM with extraction-based verification.
        // Return tasks with ground truth.
    }

    /**
     * Generate ground truth from WordPress data.
     *
     * Uses extraction (not generation) to create verifiable expected outcomes.
     * This mirrors Context-1's extraction-based verification approach.
     *
     * @param string $domain Task domain.
     * @param array  $site_data Extracted WordPress site data.
     * @return array Ground truth data for verification.
     */
    private function extract_ground_truth( $domain, $site_data ) {
        // For content_discovery: actual post counts, categories, dates.
        // For ecommerce_analytics: real product data, order totals.
        // For regulatory: actual registration records, expiry dates.
        // For site_admin: real user roles, login timestamps.
    }
}
```

#### 3.2 New Service: `WP_MCP_AI_Task_Evaluation_Service`

**File:** `includes/services/class-wp-mcp-ai-task-evaluation-service.php`

Evaluates task execution results using an LLM judge.

```php
class WP_MCP_AI_Task_Evaluation_Service {

    /**
     * Evaluation dimensions with scoring criteria.
     */
    const DIMENSIONS = array(
        'correctness'  => 'Does the result match the expected outcome?',
        'completeness' => 'Were all required steps and tools executed?',
        'relevance'    => 'Is the result directly relevant to the task query?',
        'efficiency'   => 'Were tools used efficiently without redundant calls?',
        'safety'       => 'Were permissions and capabilities properly respected?',
    );

    /**
     * Evaluate a task execution result using LLM judge.
     *
     * @param array $task             Original task definition.
     * @param array $execution_trace  Full execution trace (tool calls, results, timing).
     * @param array $result           Final execution result.
     * @param array $options          Evaluation options (model, strict_mode).
     * @return array {
     *     @type float $composite_score   Overall score (0-5).
     *     @type array $dimension_scores  Per-dimension scores (0-1 each).
     *     @type string $judge_reasoning  LLM judge's explanation.
     *     @type array  $issues           Identified issues or failures.
     *     @type array  $suggestions      Improvement suggestions.
     *     @type bool   $passed           Whether task meets minimum threshold.
     * }
     */
    public function evaluate( $task, $execution_trace, $result, $options = array() ) {
        // Build judge prompt with task, expected outcome, actual result.
        // Call LLM to score on each dimension.
        // Parse structured scores.
        // Return evaluation with reasoning.
    }

    /**
     * Run a full evaluation suite (batch of tasks).
     *
     * @param array $tasks            Array of task definitions.
     * @param array $execution_results Corresponding execution results.
     * @param array $options           Suite options.
     * @return array {
     *     @type float $avg_composite     Average composite score.
     *     @type array $dimension_avgs    Average score per dimension.
     *     @type int   $passed_count      Tasks that met threshold.
     *     @type int   $failed_count      Tasks that did not meet threshold.
     *     @type array $regressions       Tasks that scored lower than baseline.
     *     @type array $task_evaluations  Individual task evaluations.
     * }
     */
    public function evaluate_suite( $tasks, $execution_results, $options = array() ) {
        // Evaluate each task.
        // Calculate aggregate metrics.
        // Detect regressions against stored baseline.
        // Return suite report.
    }
}
```

#### 3.3 New Tool: `generate_evaluation_tasks`

**File:** `includes/tools/class-wp-mcp-ai-tool-generate-evaluation-tasks.php`

Exposes task generation as an AI-callable tool for self-evaluation.

```php
/**
 * Parameters Schema:
 *   domain      (required) - Task domain (content_discovery, ecommerce_analytics,
 *                            regulatory_compliance, site_administration)
 *   count       (optional) - Number of tasks to generate (1-50, default: 10)
 *   difficulty  (optional) - Difficulty level (simple, moderate, complex, multi_hop)
 *   use_site_data (optional) - Whether to ground tasks in actual site data (default: true)
 *
 * Returns:
 *   success, tasks[], count, domain, difficulty
 */
```

#### 3.4 New Tool: `evaluate_workflow_quality`

**File:** `includes/tools/class-wp-mcp-ai-tool-evaluate-workflow-quality.php`

Allows agents to evaluate workflow quality using the LLM judge.

```php
/**
 * Parameters Schema:
 *   task            (required) - Original task definition
 *   execution_trace (required) - Full execution trace from workflow
 *   result          (required) - Final execution result
 *   baseline_score  (optional) - Previous score to detect regression
 *
 * Returns:
 *   success, composite_score, dimension_scores{}, judge_reasoning,
 *   issues[], suggestions[], passed, is_regression
 */
```

#### 3.5 Integration with Validate Workflow Tool

Enhance `WP_MCP_AI_Tool_Validate_Workflow` to support semantic validation using synthetic tasks:

```php
// In validate_workflow, when strict mode is enabled:
if ( $strict && class_exists( 'WP_MCP_AI_Synthetic_Task_Service' ) ) {
    // Generate a simple task for this workflow type.
    $task_service = WP_MCP_AI_Synthetic_Task_Service::get_instance();
    $test_tasks   = $task_service->generate_tasks(
        $this->infer_domain( $workflow ),
        1, // Just one task for validation.
        'simple'
    );

    // Dry-run the task through the workflow.
    // Evaluate with LLM judge.
    // Add semantic validation results to the report.
}
```

---

## Workflow Tool Integration

### How These Enhancements Affect Existing Workflow Tools

#### Execute Workflow (`execute_workflow`)

The `execute_workflow` tool (via `WP_MCP_AI_Enhanced_Workflow_Coordinator`) benefits from all three enhancements:

| Enhancement | Integration Point | Benefit |
|-------------|-------------------|---------|
| **Staged Retrieval** | Before workflow execution, retrieve relevant prior contexts and workflows | Better starting context, reduced redundant research |
| **Self-Editing Context** | Between workflow steps, automatically prune stale context | Prevents context overflow in long workflows, better step quality |
| **Synthetic Tasks** | Generate test workflows for validation and quality assurance | Automated regression detection, workflow quality scoring |

**Implementation:** Add `wp_mcp_ai_workflow_context_strategy` option to workflow definitions:

```yaml
workflow:
  name: "Content Research Pipeline"
  context_strategy:
    staged_retrieval: true     # Use staged retrieval for initial context
    self_editing: true         # Enable inter-step context pruning
    edit_threshold: 0.75       # Trigger editing at 75% budget usage
    quality_evaluation: true   # Run LLM judge evaluation after completion
  steps:
    - name: research
      type: delegate
      role: researcher
    - name: analyze
      type: delegate
      role: analyst
    - name: synthesize
      type: aggregate
      strategy: consensus
```

#### Validate Workflow (`validate_workflow`)

Enhanced with semantic validation via synthetic task generation:

```
Current: Structural validation only (syntax, required fields, dependency graph)
Enhanced: Structural + Semantic validation (generate test task, dry-run, LLM judge score)
```

New validation levels:
- `basic` — Syntax and structure (current behavior)
- `strict` — Basic + dependency validation + circular reference detection (current strict mode)
- `semantic` — Strict + synthetic task generation + dry-run evaluation (new)

#### Check Workflow Health (`check_workflow_health`)

Enhanced with context health metrics:

```
Current: Checks workflow state (initialized, running, stuck, failed)
Enhanced: Also checks context health per step:
  - Context utilization per step
  - Self-edit frequency and effectiveness
  - Token budget pressure points
  - Context rot indicators (stale items persisting across many steps)
```

#### Visualize Workflow Metrics (`visualize_workflow_metrics`)

Enhanced with new chart types:

```
Current charts: performance (doughnut), completion (pie), timing (bar)
New charts:
  - Context Budget Timeline: Line chart showing token usage across steps
  - Self-Edit Impact: Bar chart showing tokens freed per step
  - Retrieval Quality: Scatter plot of recall vs precision per workflow
  - Quality Score History: Line chart of LLM judge scores over time
```

### New Orchestration Hooks

```php
/**
 * Fires before staged retrieval is executed.
 *
 * @param int|string $agent_id     Agent identifier.
 * @param array      $task_context Task context.
 * @param int        $token_budget Token budget.
 */
do_action( 'wp_mcp_ai_before_staged_retrieval', $agent_id, $task_context, $token_budget );

/**
 * Fires after context self-editing is performed.
 *
 * @param array $edit_result  Edit results (freed tokens, decisions).
 * @param int   $step_index   Current workflow step index.
 * @param array $workflow     Full workflow definition.
 */
do_action( 'wp_mcp_ai_after_context_self_edit', $edit_result, $step_index, $workflow );

/**
 * Filters the self-edit scoring weights.
 *
 * @param array $weights      Default weights {relevance, recency, references, uniqueness}.
 * @param array $current_task Current task context.
 * @param array $step_info    Current step information.
 */
$weights = apply_filters( 'wp_mcp_ai_self_edit_weights', $weights, $current_task, $step_info );

/**
 * Filters the LLM judge evaluation prompt.
 *
 * @param string $prompt      Default judge prompt.
 * @param array  $task        Task being evaluated.
 * @param array  $result      Execution result.
 */
$prompt = apply_filters( 'wp_mcp_ai_evaluation_judge_prompt', $prompt, $task, $result );

/**
 * Fires after synthetic task evaluation is complete.
 *
 * @param array $evaluation Suite evaluation results.
 * @param array $tasks      Tasks that were evaluated.
 */
do_action( 'wp_mcp_ai_after_task_evaluation', $evaluation, $tasks );
```

---

## Implementation Phases

### Phase 1: Staged Retrieval Pipeline (Estimated: 16-20 hours)

| Task | Hours | Priority |
|------|-------|----------|
| Create `WP_MCP_AI_Staged_Retrieval_Service` | 6-8 | P0 |
| Create `staged_context_retrieval` tool | 3-4 | P0 |
| Integrate with `deep_research` tool | 2-3 | P1 |
| Integrate with `retrieve_agent_memory` (staged mode option) | 2-3 | P1 |
| PHPUnit tests for staged retrieval | 3-4 | P0 |

**Dependencies:** Existing `WP_MCP_AI_Agent_Context_Manager`, `WP_MCP_AI_Vector_Context_Service`

**Deliverables:**
- New service class with Phase 1 (recall) and Phase 2 (precision) methods
- New AI-callable tool for agents to use staged retrieval
- Integration with existing research tool for prior-knowledge recall
- Full test coverage

### Phase 2: Self-Editing Context Window (Estimated: 20-24 hours)

| Task | Hours | Priority |
|------|-------|----------|
| Create `WP_MCP_AI_Context_Self_Editor` service | 8-10 | P0 |
| Create `self_edit_context` tool | 3-4 | P0 |
| Integrate with `Agent_Team_Orchestrator` workflow execution | 4-5 | P0 |
| Integrate with `Enhanced_Workflow_Coordinator` | 3-4 | P1 |
| Add self-edit metrics to `Agentic_Workflow_Optimizer` | 2-3 | P1 |
| PHPUnit tests for self-editing | 4-5 | P0 |

**Dependencies:** Existing `WP_MCP_AI_Context_Compression_Service`, `WP_MCP_AI_Token_Budget_Manager`, Phase 1

**Deliverables:**
- New service class with scoring matrix and decision engine
- New AI-callable tool for explicit self-editing
- Automatic inter-step context pruning in workflow execution
- Metrics tracking in workflow optimizer
- WordPress hooks for extensibility
- Full test coverage

### Phase 3: Synthetic Task Generation (Estimated: 24-30 hours)

| Task | Hours | Priority |
|------|-------|----------|
| Create `WP_MCP_AI_Synthetic_Task_Service` | 8-10 | P0 |
| Create `WP_MCP_AI_Task_Evaluation_Service` | 8-10 | P0 |
| Create `generate_evaluation_tasks` tool | 3-4 | P1 |
| Create `evaluate_workflow_quality` tool | 3-4 | P1 |
| Integrate with `validate_workflow` (semantic validation) | 3-4 | P2 |
| PHPUnit tests for task generation and evaluation | 4-5 | P0 |

**Dependencies:** Existing workflow tools, AI provider clients, Phase 1 and 2

**Deliverables:**
- Task generation service with 4 WordPress-adapted domains
- LLM judge evaluation service with 5-dimension scoring
- Two new AI-callable tools
- Enhanced workflow validation with semantic level
- Full test coverage

### Phase 4: Workflow Tool Integration & Dashboard (Estimated: 12-16 hours)

| Task | Hours | Priority |
|------|-------|----------|
| Enhance `check_workflow_health` with context metrics | 3-4 | P1 |
| Enhance `visualize_workflow_metrics` with new charts | 4-5 | P2 |
| Add `context_strategy` support to workflow definitions | 3-4 | P1 |
| Update Orchestration Dashboard with new metrics | 3-4 | P2 |

**Dependencies:** Phases 1-3

**Deliverables:**
- Enhanced workflow health checks with context rot detection
- New chart types for context and quality visualization
- Declarative context strategy in workflow YAML/JSON
- Dashboard integration for monitoring

### Total Estimated Effort: 72-90 hours (across 4 phases)

---

## File Manifest

### New Files

| File | Type | Description |
|------|------|-------------|
| `includes/services/class-wp-mcp-ai-staged-retrieval-service.php` | Service | Two-phase recall→precision retrieval pipeline |
| `includes/services/class-wp-mcp-ai-context-self-editor.php` | Service | Context self-editing with scoring and decision engine |
| `includes/services/class-wp-mcp-ai-synthetic-task-service.php` | Service | Synthetic task generation from WordPress data |
| `includes/services/class-wp-mcp-ai-task-evaluation-service.php` | Service | LLM judge evaluation with 5-dimension scoring |
| `includes/tools/class-wp-mcp-ai-tool-staged-context-retrieval.php` | Tool | AI-callable staged retrieval tool |
| `includes/tools/class-wp-mcp-ai-tool-self-edit-context.php` | Tool | AI-callable context self-editing tool |
| `includes/tools/class-wp-mcp-ai-tool-generate-evaluation-tasks.php` | Tool | AI-callable synthetic task generation tool |
| `includes/tools/class-wp-mcp-ai-tool-evaluate-workflow-quality.php` | Tool | AI-callable workflow quality evaluation tool |
| `tests/test-staged-retrieval-service.php` | Test | Unit tests for staged retrieval |
| `tests/test-context-self-editor.php` | Test | Unit tests for self-editing |
| `tests/test-synthetic-task-service.php` | Test | Unit tests for task generation |
| `tests/test-task-evaluation-service.php` | Test | Unit tests for LLM judge evaluation |

### Modified Files

| File | Modification |
|------|-------------|
| `includes/services/class-wp-mcp-ai-agent-team-orchestrator.php` | Add inter-step self-editing in `execute_team_workflow()` |
| `includes/services/class-wp-mcp-ai-enhanced-workflow-coordinator.php` | Add `wp_mcp_ai_before_workflow_step` filter hook |
| `includes/class-wp-mcp-ai-agentic-workflow-optimizer.php` | Add self-edit and retrieval metrics tracking |
| `includes/tools/class-wp-mcp-ai-tool-deep-research.php` | Integrate staged retrieval for memory recall |
| `includes/tools/class-wp-mcp-ai-tool-validate-workflow.php` | Add `semantic` validation level |
| `includes/tools/class-wp-mcp-ai-tool-check-workflow-health.php` | Add context health metrics |
| `includes/tools/class-wp-mcp-ai-tool-visualize-workflow-metrics.php` | Add new chart types |

---

## Testing Strategy

### Unit Tests

```php
// Staged Retrieval
class Test_Staged_Retrieval_Service extends WP_UnitTestCase {
    public function test_phase_recall_merges_multiple_strategies() {}
    public function test_phase_recall_deduplicates_by_context_id() {}
    public function test_phase_precision_respects_token_budget() {}
    public function test_phase_precision_applies_strategy_weights() {}
    public function test_full_pipeline_recall_then_precision() {}
    public function test_empty_recall_returns_empty_precision() {}
    public function test_recall_heavy_strategy_keeps_more_items() {}
    public function test_precision_heavy_strategy_filters_aggressively() {}
}

// Self-Editing Context
class Test_Context_Self_Editor extends WP_UnitTestCase {
    public function test_no_edit_when_under_threshold() {}
    public function test_edit_triggered_when_over_threshold() {}
    public function test_high_relevance_items_kept() {}
    public function test_low_relevance_items_dropped() {}
    public function test_medium_relevance_items_compressed() {}
    public function test_tokens_freed_matches_expectation() {}
    public function test_decisions_logged_correctly() {}
    public function test_upcoming_steps_influence_relevance() {}
    public function test_reference_scoring_preserves_cited_items() {}
    public function test_uniqueness_scoring_drops_redundant_items() {}
}

// Synthetic Task Generation
class Test_Synthetic_Task_Service extends WP_UnitTestCase {
    public function test_generate_tasks_returns_correct_count() {}
    public function test_generate_tasks_validates_domain() {}
    public function test_generate_tasks_includes_ground_truth() {}
    public function test_difficulty_affects_step_count() {}
    public function test_site_data_grounding_uses_real_data() {}
}

// Task Evaluation
class Test_Task_Evaluation_Service extends WP_UnitTestCase {
    public function test_evaluate_returns_all_dimensions() {}
    public function test_composite_score_calculation() {}
    public function test_regression_detection() {}
    public function test_evaluate_suite_aggregates_correctly() {}
}
```

### Integration Tests

```php
// End-to-end workflow with staged retrieval and self-editing
class Test_Context1_Workflow_Integration extends WP_UnitTestCase {
    public function test_workflow_with_staged_retrieval_and_self_editing() {
        // 1. Store multiple contexts for an agent.
        // 2. Create a multi-step workflow.
        // 3. Execute with staged retrieval enabled.
        // 4. Verify self-editing occurs between steps.
        // 5. Verify final result quality.
    }

    public function test_synthetic_task_evaluation_loop() {
        // 1. Generate synthetic tasks.
        // 2. Execute each task.
        // 3. Evaluate with LLM judge.
        // 4. Verify scores are within expected range.
    }
}
```

---

## Performance Considerations

### Token Budget Impact

| Operation | Estimated Token Cost | Frequency |
|-----------|---------------------|-----------|
| Staged Retrieval (Phase 1) | 0 (local search only) | Once per workflow |
| Staged Retrieval (Phase 2, semantic) | ~500 tokens (embedding API) | Once per workflow |
| Self-Edit Scoring | 0 (local computation) | Once per step |
| Self-Edit Compression | ~200-500 tokens (if AI summarization used) | Per compressed item |
| Synthetic Task Generation | ~2,000-5,000 tokens (LLM call) | On-demand / scheduled |
| LLM Judge Evaluation | ~1,000-3,000 tokens per task | On-demand / scheduled |

### PHP Memory Impact

- Staged retrieval recall phase may temporarily hold 50+ context items in memory
- Self-editing operates on the current context window (already in memory)
- Synthetic task generation stores tasks in transients (auto-expired)
- All operations are designed to be garbage-collected after completion

### Caching Strategy

- Staged retrieval results cached via `WP_MCP_AI_Agentic_Workflow_Optimizer` (5-minute TTL)
- Self-edit decisions cached per workflow step (workflow lifetime only)
- Synthetic tasks cached in transients (configurable TTL, default 1 hour)
- LLM judge evaluations stored as agent context (30-day TTL)

### WordPress Cron Integration

- Synthetic task generation can run as a background WP Cron job
- Evaluation suites can run as scheduled health checks
- Results feed into the existing Orchestration Dashboard

---

## Migration & Backward Compatibility

### No Breaking Changes

All enhancements are additive:
- New services are opt-in (enabled via settings or workflow configuration)
- New tools are registered alongside existing tools (no slug conflicts)
- Existing workflow definitions continue to work without `context_strategy`
- Existing `prioritize_context` tool continues to work independently
- All new hooks use unique prefixes (`wp_mcp_ai_*`)

### Feature Flags

Each enhancement can be independently enabled/disabled:

```php
// In wp_mcp_ai_settings:
'enable_staged_retrieval'      => true,  // Enhancement 1
'enable_context_self_editing'  => true,  // Enhancement 2
'enable_synthetic_evaluation'  => false, // Enhancement 3 (disabled by default, requires LLM API)
```

### Graceful Degradation

- If `WP_MCP_AI_Vector_Context_Service` is unavailable (no OpenAI API key), staged retrieval falls back to keyword-only recall
- If `WP_MCP_AI_Context_Compression_Service` is unavailable, self-editing drops items instead of compressing
- If LLM API is unavailable, synthetic task evaluation returns an informative error

---

## References

### Primary Research

1. **Chroma Context-1**: [Training a Self-Editing Search Agent](https://www.trychroma.com/research/context-1) — Core inspiration for staged retrieval and self-editing context
2. **Stanford ACE Framework**: [Agentic Context Engineering (arXiv:2510.04618)](https://arxiv.org/abs/2510.04618) — Three-agent system for evolving contexts
3. **Anthropic**: [Effective Context Engineering for AI Agents](https://www.anthropic.com/engineering/effective-context-engineering-for-ai-agents) — Production best practices

### Industry Standards & Benchmarks

4. **Contextual AI**: [Platform Benchmarks 2025](https://contextual.ai/blog/platform-benchmarks-2025) — End-to-end agentic RAG evaluation
5. **Milvus**: [LLM Context Pruning Developer Guide](https://milvus.io/blog/llm-context-pruning-a-developers-guide-to-better-rag-and-agentic-ai-results.md) — Context pruning best practices
6. **DeepEval**: [LLM Evaluation Framework](https://github.com/confident-ai/deepeval) — LLM judge evaluation patterns
7. **AutoPlay**: [Scaling Synthetic Task Generation (arXiv:2509.25047)](https://arxiv.org/abs/2509.25047) — Exploration-driven task generation

### Agentic RAG Surveys

8. **Agentic RAG Survey**: [arXiv:2501.09136](https://arxiv.org/abs/2501.09136) — Comprehensive survey of agentic retrieval methods
9. **Kore.ai**: [Agentic RAG Comprehensive Guide](https://www.kore.ai/blog/what-is-agentic-rag) — Production agentic RAG patterns
10. **Emergent Mind**: [Agentic Retrieval Methods in AI Reasoning](https://www.emergentmind.com/topics/agentic-retrieval-methods) — Emerging patterns and trends

### Context Window Management

11. **AI Agents Plus**: [Context Window Management Techniques 2026](https://www.ai-agentsplus.com/blog/ai-context-window-management-techniques-2026) — Production context management
12. **MarkAICode**: [RAG Context Window Chunk Strategy Guide 2026](https://markaicode.com/rag-context-window-chunk-strategy/) — Chunking strategy best practices
13. **Blog.jroddev**: [Context Window Management in Agentic Systems](https://blog.jroddev.com/context-window-management-in-agentic-systems/) — Practical patterns

### Related NV oOS Documentation

14. `docs/proposals/DEEPSEEK-V4-ORCHESTRATION-ENHANCEMENTS.md` — DeepSeek V4-inspired orchestration (Phases 1-5)
15. `docs/proposals/PHASE-5-COMPLETION-REPORT.md` — Phase 5 state management and memory completion
16. `docs/RAG-ENHANCED-MEMORY-MANAGEMENT.md` — RAG best practices implemented in NV oOS
17. `docs/guides/RESEARCH_PATTERN_IMPLEMENTATION_GUIDE.md` — Research pattern implementation guide
18. `docs/reference/technical/TOKEN-CONTEXT-WINDOW-EXPLAINED.md` — Token context window technical reference

---

## Appendix A: Glossary

| Term | Definition |
|------|-----------|
| **Staged Retrieval** | Two-phase retrieval: broad recall followed by precision refinement |
| **Self-Editing Context** | Agent actively pruning its own context window mid-workflow |
| **Context Rot** | Degradation of context quality as irrelevant items accumulate |
| **Multi-Hop Task** | Task requiring chaining information across multiple documents/steps |
| **LLM Judge** | Using a language model to evaluate the quality of another model's output |
| **Recall** | Fraction of relevant items that are successfully retrieved |
| **Precision** | Fraction of retrieved items that are actually relevant |
| **Token Budget** | Maximum number of tokens available for context + response |
| **Ground Truth** | Known correct answer used for evaluation (extracted, not generated) |
| **Context Strategy** | Declarative configuration for how a workflow manages its context |

## Appendix B: Mapping to Existing NV oOS Tool Slugs

| New Feature | Uses Existing Tool | Extends Existing Tool |
|-------------|-------------------|----------------------|
| Staged Retrieval Service | `retrieve_agent_memory`, `semantic_context_search` | `prioritize_context` |
| Self-Editing Service | `prioritize_context` | `execute_workflow` |
| Synthetic Task Generator | `web_search`, `search_content`, `get_woo_products` | `validate_workflow` |
| Task Evaluation Service | (uses LLM API directly) | `check_workflow_health`, `visualize_workflow_metrics` |
