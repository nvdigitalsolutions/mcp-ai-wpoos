# Proposal: Meta-Harness — Automated Harness Profile Optimization for NV oOS

**Based on:** [Meta-Harness: End-to-End Optimization of Model Harnesses](https://arxiv.org/abs/2603.28052) (Lee et al., Stanford & MIT, 2026)  
**Date:** 2026-07-12  
**Status:** Research-backed proposal  
**Target release:** v1.9.0 → v2.0.0  
**Author:** AI-assisted analysis of NV oOS harness subsystem vs. state-of-the-art research

---

## Table of Contents

1. [Executive Summary](#executive-summary)
2. [Research Landscape](#research-landscape)
3. [Current State Analysis](#current-state-analysis)
4. [Proposed Architecture](#proposed-architecture)
5. [Phase-by-Phase Implementation Plan](#phase-by-phase-implementation-plan)
6. [Consolidated File Change Map](#consolidated-file-change-map)
7. [Risk Assessment](#risk-assessment)
8. [Dependencies Between Phases](#dependencies-between-phases)
9. [Total Estimated Effort](#total-estimated-effort)
10. [References](#references)

---

## Executive Summary

The Meta-Harness paper (Lee et al., March 2026) demonstrates that **automated search over harness code** — using a coding-agent proposer with full access to prior candidates' source code, scores, and execution traces — produces harnesses that significantly outperform hand-engineered baselines. Key results: +7.7 accuracy points on text classification over the prior state-of-the-art (ACE), 4× fewer context tokens, 10× faster convergence than existing text optimizers, and harnesses that generalize across unseen datasets and models.

NV oOS already ships a sophisticated **7-layer LLM Harnessing Subsystem** (`includes/harness/`) with a per-assistant profile model, evaluation scheduler, and 60+ lifecycle hooks. The system is remarkably well-positioned to adopt the Meta-Harness pattern — the infrastructure exists; what's missing is the **search loop** that automates what is currently a manual configuration process.

This proposal defines an 8-phase plan to extend the base harness subsystem with automated profile search and execution-trace capture, graduating to a Pro-level proposer agent that optimizes harness configurations through evolutionary search. Total estimated effort: **22 days across 3 iterations**, delivering a fully automated harness optimization pipeline by v2.0.0.

**Core thesis:** NV oOS should not just ship a configurable harness — it should ship a harness that _optimizes itself_. This matches the industry trajectory identified by Weng (2026): "Harness engineering will evolve in the direction of meta-methodology — the harness system itself becomes an optimization target, with fewer heuristic rules and more general mechanisms."

---

## Research Landscape

### The Harness Engineering Revolution (2025–2026)

The term "harness" has rapidly crystallized as the layer between a raw LLM and the real world. It encompasses prompt construction, tool selection, context management, memory, retrieval, evaluation, and workflow orchestration. Three major threads define the current state of the art:

#### Thread 1: Context Engineering (ACE → MCE → Meta-Harness)

| System | What it optimizes | Search space | Key insight |
|--------|-------------------|-------------|-------------|
| **ACE** (Zhang et al., 2025) | Context playbook (bulleted items) | Fixed heuristics: generate → reflect → curate | Structured, itemized context avoids collapse |
| **MCE** (Ye et al., 2026) | Meta-skills + context functions | Free-form skills with bi-level optimization | Separate mechanism from artifact; evolve both |
| **Meta-Harness** (Lee et al., 2026) | Complete harness programs | Full code-space via coding-agent proposer | Full execution traces > summaries for search |

The progression is clear: **instruction prompts → structured context → workflow → harness code → optimizer code**. As Lilian Weng notes in her July 2026 survey: "As the model becomes more intelligent and powerful, we move toward more complex targets and generic methods."

#### Thread 2: Evolutionary Program Search

| System | Search mechanism | Key innovation |
|--------|-----------------|----------------|
| **AlphaEvolve** (Novikov et al., 2025) | LLM-guided mutation with tournament selection | `# EVOLVE-BLOCK` markers for bounded edits |
| **ADAS** (Hu et al., 2025) | Meta-agent programs new agents | Archive-based population; self-refine on drafts |
| **DGM** (Zhang et al., 2025) | Agent modifies its own harness code | File-system-based code editing; parent proportional selection |
| **Self-Harness** (Zhang et al., 2026) | Weakness mining → bounded edits → validation | Held-in/held-out splits; regression-free acceptance |

#### Thread 3: Production Harness Patterns

From **OpenAI's Codex harness engineering post** (Feb 2026) and **Birgitta Böckeler's Martin Fowler article** (Apr 2026):

| Production pattern | NV oOS equivalent |
|-------------------|-------------------|
| **Layered architecture enforced by linters** | Capability flags on tools; necessity gate; guardrails |
| **Feedforward guides + feedback sensors** | Prompt cues (feedforward) + self-refine loop (feedback) |
| **Garbage collection / drift detection** | Eval scheduler with regression detector |
| **Steering loop** (human iterates on harness) | Manual harness profile configuration (current state) |
| **→ Automated steering loop** (proposer iterates on harness) | **This proposal** |
| **Computational vs inferential controls** | Regex PII filter (computational) + LLM critic (inferential) |

### The Meta-Harness Paper: Key Findings

The paper's ablation study (Table 3) is the single most important data point for implementation decisions:

| Proposer interface | Median accuracy | Best accuracy | Runs > zero-shot |
|-------------------|:--------------:|:------------:|:----------------:|
| Scores only | 34.6 | 41.3 | 26 |
| Scores + summaries | 34.9 | 38.7 | 23 |
| **Full traces (Meta-Harness)** | **50.0** | **56.7** | **39** |

**Conclusion: summaries can hurt more than they help.** The proposer must have raw access to execution traces. This directly shapes the trace store design (Phase 1).

Secondary findings from the paper's Appendix D (Practical Implementation Tips):

1. **"Write a good skill"** — the skill text is the strongest lever on search quality. Expect 3–5 short evolution runs to debug the skill before a full run.
2. **"Start with a baseline that the search set is hard for"** — 50–100 examples for classification; the eval must be fast and discriminative.
3. **"Log everything in a format that is easy to navigate"** — JSON, hierarchical directories, consistent naming, `grep`-friendly.
4. **"Make logs queryable through a small CLI"** — list Pareto frontier, show top-k, diff between runs.
5. **"Lightweight validation before expensive benchmarks"** — import check + smoke test before full evaluation.
6. **"Automate evaluation outside the proposer"** — separate harness scores candidates.

---

## Current State Analysis

### What NV oOS Already Has (Readiness Score: 7/10)

NV oOS is among the most harness-ready WordPress AI plugins in existence. The following existing components map directly to Meta-Harness requirements:

| Meta-Harness Component | NV oOS Equivalent | Maturity | Gap |
|-----------------------|-------------------|----------|-----|
| **Harness program** | 7-layer LLM Harnessing Subsystem (`includes/harness/`) | ✅ Production | Profile-level only; no code-space search |
| **Per-candidate source code** | `_wp_mcp_ai_harness_profile` post meta (JSON) | ✅ Production | Only the final config, not search candidates |
| **Evaluation scores** | `WP_MCP_AI_Eval_Runner` + `WP_MCP_AI_Eval_Run_Store` | ✅ Production | Wired for manual runs, not search loop |
| **Execution traces** | `WP_MCP_AI_Reasoning_Trace` + transcript CPT | ⚠️ Partial | Scattered across systems; not artifact-per-candidate |
| **Proposer agent** | Admin metabox + manual config | ❌ Missing | No automated proposal mechanism |
| **Filesystem-like store** | Post meta / options / CPTs | ❌ Missing | Not organized as inspectable artifact tree |
| **Pareto frontier** | Manual comparison in admin UI | ⚠️ Partial | No frontier computation or visualization |
| **Search population** | Multiple assistant profiles (manual) | ⚠️ Partial | No evolutionary population management |

### The Seven Existing Harness Layers (v1.8.0)

```
Layer A — Prompt/Cue         ✅ WP_MCP_AI_Prompt_Cue_Library (7 fixed cues)
Layer B — Reasoning/Rehearsal ✅ WP_MCP_AI_Reasoning_Trace + self-consistency vote
Layer C — Tool Routing        ✅ WP_MCP_AI_Tool_Router_Harness (RRF, k=60)
Layer D — Retrieval           ✅ WP_MCP_AI_Retrieval_Harness (3-source fan-out)
Layer E — Self-Refine         ✅ WP_MCP_AI_Self_Refine_Loop (generate→critique→revise)
Layer F — Memory Scoping      ✅ WP_MCP_AI_Tool_Scope_Memory + PII Filter
Layer G — Evaluation          ✅ WP_MCP_AI_Harness_Eval_Scheduler (daily cron)
Layer H — Curriculum Export   ✅ Pro only (WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum)
```

### Key Hooks Available for Instrumentation

The existing 60+ lifecycle hooks provide perfect injection points for trace capture without modifying existing harness code:

- `wp_mcp_ai_before_tool_execution` / `wp_mcp_ai_after_tool_execution` — tool-level trace capture
- `wp_mcp_ai_before_chat_request` / `wp_mcp_ai_after_chat_response` — request-level boundaries
- `wp_mcp_ai_cost_calculated` — token/cost tracking per step
- `wp_mcp_ai_harness_tool_score` — pluggable scoring (Pro can swap in learned model)
- `wp_mcp_ai_harness_eval_generator` — plugs eval generation into any backend
- `wp_mcp_ai_harness_eval_completed` — fires after every scheduled eval run
- `wp_mcp_ai_resolved_system_prompt` — captures final assembled prompt per request
- `wp_mcp_ai_retrieval_passages` — captures retrieval output per request

### Existing Orchestration Layer (Not to Be Confused with the Harness)

The **orchestration layer** (always-on, site-wide) handles execution infrastructure — model routing, tool execution, budgets, multi-agent coordination. The **harness layer** (opt-in, per-assistant) handles epistemic quality — cues, reasoning, retrieval, self-refine. The proposed search infrastructure sits at the harness level and delegates execution to the orchestration layer, matching the Meta-Harness architecture exactly.

---

## Proposed Architecture

### System Overview

The enhancement adds three new subsystems, organized as an **evolutionary search loop** that uses the existing harness infrastructure as its evaluation backend:

```
┌──────────────────── Meta-Harness Search Loop (New) ────────────────────┐
│                                                                        │
│  ┌── Harness Trace Store (Phase 1) ──────────────────────────────┐    │
│  │  Per-run artifact directories:                                 │    │
│  │    profile.json   reasoning_trace.json   retrieval.json        │    │
│  │    tool_calls.jsonl   self_refine.json   score.json            │    │
│  │    cost.json   model_response.txt                              │    │
│  └────────────────────────────────────────────────────────────────┘    │
│                              │  (feeds traces to)                      │
│                              ▼                                         │
│  ┌── Harness Search Engine (Phase 2) ────────────────────────────┐    │
│  │  Population ℋ = {profiles}   Pareto frontier   Iteration loop │    │
│  │  Candidate validation   Search set management                 │    │
│  │  Existing eval scheduler (Layer G) as evaluation backend       │    │
│  └──────────────────────────┬─────────────────────────────────────┘   │
│                             │  (evaluates via)                        │
│                             ▼                                          │
│  ┌── Harness Proposer (Phase 3, Pro) ───────────────────────────┐    │
│  │  Coding-agent proposer (Claude Code / Opus / configurable)    │    │
│  │  Inspects traces → forms hypotheses → proposes new profiles   │    │
│  │  Operates on profile JSON (Phase 3a) → harness PHP (Phase 3b) │    │
│  └────────────────────────────────────────────────────────────────┘   │
│                                                                        │
│  ┌── Existing 7-Layer Harness (unchanged) ───────────────────────┐    │
│  │  Layer A (Cues)   Layer B (Reasoning)   Layer C (Routing)     │    │
│  │  Layer D (Retrieval)   Layer E (Refine)   Layer F (Memory)    │    │
│  │  Layer G (Evals)   Layer H (Curriculum, Pro)                  │    │
│  └────────────────────────────────────────────────────────────────┘   │
│                                                                        │
│  ┌── Existing Orchestration Layer (always on, unchanged) ────────┐    │
│  │  Model routing   Tool execution   Budget enforcement          │    │
│  │  DSpark optimizations   Health monitoring   OTel export       │    │
│  └────────────────────────────────────────────────────────────────┘   │
└────────────────────────────────────────────────────────────────────────┘
```

### Design Decisions

The following decisions are made based on the research and industry standards surveyed:

| Decision | Rationale | Source |
|----------|-----------|--------|
| **Profile-level search before code-level search** | Safer, incremental; Meta-Harness appendix shows profile space alone is powerful enough. The paper's proposer often started from a strong prior — this is the NV oOS equivalent. | Meta-Harness §3 "the proposer is free to inspect any prior harness" |
| **Flat-file trace store (not CPT)** | Matches Meta-Harness' filesystem pattern. Coding agents are trained on `grep`/`cat` workflows. JSON under `wp-content/uploads/mcp-ai-harness-traces/`. | Meta-Harness Appx D: "Log everything in a format that is easy to navigate... machine-readable formats such as JSON" |
| **Proposer-pluggable (filter-based)** | Matches existing architecture. `wp_mcp_ai_harness_proposer` filter lets Pro swap in Claude Code/Opus while base ships a "best-of-N" random proposer. | Convention per `wp_mcp_ai_harness_tool_score`, `wp_mcp_ai_harness_eval_generator` |
| **Search set = eval suites** | Reuses Layer G infrastructure. Each `WP_MCP_AI_Eval_Suite` becomes a search set. Fast, discriminative, already supports regression detection. | Meta-Harness Appx D: "Construct the search set by filtering for examples that the baseline gets wrong" |
| **Pareto frontier, not single metric** | Avoids Goodhart collapse. Accuracy + context cost + latency as separate dimensions. Matches existing multi-signal approach (separate freshness/recall/confidence). | Meta-Harness §4.1 "we evaluate candidates under Pareto dominance"; Weng §Future Challenges §5 |
| **No auto-deploy without regression gate** | Safety requirement. Self-Harness' held-in/held-out validation pattern. Candidates accepted only if no regression on held-out eval data. | Self-Harness (Zhang et al., 2026); Martin Fowler: "Keep quality left" |

---

## Phase-by-Phase Implementation Plan

### Phase 0: Pre-Flight — Research Proposer Interface Design (1 day)

Before writing code, validate the proposer feedback loop with a paper prototype.

**Task 0.1 — Map search space dimensions** (0.25 days)

Document every tunable parameter across the 7 harness layers that a proposer could modify:

```php
// Profile-level search space dimensions:
//   Layer A: cue_slugs[]  (7 choices, combinatoric)
//   Layer B: reasoning.n_samples (1–8), reasoning.max_iters (1–4)
//   Layer C: tools.router (fixed|scored), tools.preset_weights (per-preset float -5..+5)
//   Layer D: retrieval.k (1–50), retrieval.require_citations (bool)
//   Layer E: refine.enabled (bool), refine.max_iters (1–4)
//   Layer F: memory.scoped (bool), memory.task_class (string), memory.pii_filter (bool)
//   Layer G: evals_enabled[] (suite slugs)
//   cost_ceiling_usd (float)
//
// Combinatorial space: ~10^12 possible profiles per assistant.
// The proposer narrows this via trace-guided search.
```

**Task 0.2 — Design trace store schema** (0.25 days)

Finalize the JSON schemas for each trace artifact file. Each artifact must be:

- Self-describing (includes schema version, timestamps, assistant/profile IDs)
- Machine-readable (valid JSON, consistent key names)
- Grep-friendly (one JSON object per line for tool_calls.jsonl)
- Bounded (max file sizes; no unbounded arrays)

**Task 0.3 — Write proposer skill text draft** (0.5 days)

The Meta-Harness paper emphasizes that the skill text is "the primary interface for steering the search" and "the strongest lever on whether the loop works." Draft the skill text that the proposer agent will receive, defining:

- Its role ("You are a harness optimization agent for the NV oOS WordPress plugin")
- Directory layout it can access (trace store, profile store, eval suite definitions)
- What it can modify (harness profile JSON; later: cue templates, retrieval strategies)
- What it cannot touch (orchestration layer, tool implementations, security gates)
- Output format (valid harness profile JSON)
- Objectives (maximize eval score; minimize context tokens as secondary objective)

**Deliverable:** Research notes in `docs/project/proposals/006-research-notes.md`

---

### Phase 1: Harness Trace Store (3 days)

**The single most impactful change**, per Meta-Harness' ablation (Table 3). Currently, execution data is scattered across post meta, CPTs, options, and transients. This phase creates a unified, filesystem-like artifact store.

#### Task 1.1 — Create `class-wp-mcp-ai-harness-trace-store.php` (1.5 days)

```php
/**
 * Harness Trace Store — unified execution artifact storage.
 *
 * Location: includes/harness/class-wp-mcp-ai-harness-trace-store.php
 *
 * Directory structure per run:
 *   wp-content/uploads/mcp-ai-harness-traces/
 *     {assistant_id}/
 *       {run_id}/
 *         meta.json              — run metadata (timestamp, assistant_id, profile_hash, model)
 *         profile.json           — resolved harness profile at run time
 *         score.json             — final aggregate scores per eval suite
 *         cost.json              — token counts, estimated cost
 *         reasoning_trace.json   — Layer B trace (assumptions→plan→answer)
 *         retrieval.json         — passages, citations, freshness scores
 *         tool_calls.jsonl       — one JSON object per line: {slug, args, result, duration_ms}
 *         self_refine.json       — iterations, verdicts, feedback per iteration
 *         model_response.txt     — final text response
 *
 * Hard caps:
 *   - Max 500 files per run directory
 *   - Max 10 MB per file
 *   - Max 50 runs retained per assistant (oldest deleted via FIFO)
 *   - .htaccess + index.php guards on the base directory
 */

class WP_MCP_AI_Harness_Trace_Store {
    const BASE_DIR = 'mcp-ai-harness-traces';
    const MAX_RUNS_PER_ASSISTANT = 50;
    const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
    const MAX_FILES_PER_RUN = 500;

    // Core API:
    public static function start_run( $assistant_id, array $meta = array() ): string; // returns run_id
    public static function write_artifact( $run_id, $filename, $data ): bool;
    public static function append_jsonl( $run_id, $filename, array $record ): bool;
    public static function finish_run( $run_id, array $score = array() ): bool;
    public static function get_run_dir( $run_id ): string;
    public static function list_runs( $assistant_id, $limit = 20 ): array;
    public static function get_run_manifest( $run_id ): array; // lists all files + sizes
    public static function prune_old_runs( $assistant_id ): int; // returns count pruned
}
```

**Key implementation details:**

- Store path: `wp_upload_dir()['basedir'] . '/mcp-ai-harness-traces/'`
- Run ID format: `assistant_{id}_run_{timestamp}_{random_hex}`
- JSONL files use `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE` for readability
- Pruning triggered on `finish_run()` — remove oldest when > MAX_RUNS_PER_ASSISTANT
- Filesystem guards: `.htaccess` (deny from all), `index.php` (silence is golden)

#### Task 1.2 — Wire trace capture hooks (1.0 day)

Wire into existing lifecycle hooks. No changes to existing harness classes — pure subscriber pattern.

```php
// New file: includes/harness/class-wp-mcp-ai-harness-trace-capture.php
// Hooks: wp_mcp_ai_before_chat_request   → start_run()
//        wp_mcp_ai_before_tool_execution → start tool timer
//        wp_mcp_ai_after_tool_execution  → append_jsonl('tool_calls.jsonl', …)
//        wp_mcp_ai_after_chat_response   → write reasoning trace, retrieval, cost
//        wp_mcp_ai_cost_calculated       → accumulate cost
//        wp_mcp_ai_harness_eval_completed → finish_run()
// Filter: wp_mcp_ai_harness_trace_capture_enabled — per-request gate (default: false)
```

Activation: trace capture is **off by default** and enabled per-assistant via a new `harness_profile.trace_capture.enabled` flag. This preserves the behaviour-preserving guarantee.

#### Task 1.3 — Add admin UI for trace store (0.5 days)

New metabox section on the Assistant edit screen under the LLM Harness metabox:

- Toggle: "Enable execution trace capture"
- Retention: "Keep last N runs" (slider, 10–100, default 50)
- "View latest run" button linking to a trace viewer page
- "Download run as ZIP" button
- Per-run summary table: run ID, timestamp, eval score, cost, file count, size

**Deliverable:** Three new PHP files + metabox section. No changes to existing classes.

**Testing:**
```bash
vendor/bin/phpunit tests/test-harness-trace-store.php
```

---

### Phase 2: Harness Search Engine (4 days)

The search loop infrastructure. Evaluates candidate profiles, manages population, computes Pareto frontier.

#### Task 2.1 — Create `class-wp-mcp-ai-harness-search-engine.php` (2.0 days)

```php
/**
 * Harness Search Engine — population-based profile optimization.
 *
 * Location: includes/harness/class-wp-mcp-ai-harness-search-engine.php
 *
 * Implements Algorithm 1 from Meta-Harness (Lee et al., 2026):
 *   1. Initialize population ℋ from seed profiles
 *   2. Evaluate all seeds via eval scheduler (Layer G)
 *   3. For each iteration t=1..N:
 *      a. Proposer inspects trace store → proposes k candidates
 *      b. Validate each candidate (schema check + smoke test)
 *      c. Evaluate valid candidates via eval scheduler
 *      d. Add to population, update Pareto frontier
 *   4. Return Pareto frontier
 */

class WP_MCP_AI_Harness_Search_Engine {
    // Config:
    const DEFAULT_ITERATIONS = 20;
    const DEFAULT_CANDIDATES_PER_ITERATION = 2;
    const MAX_POPULATION = 200;

    // Core API:
    public static function run_search(
        int $assistant_id,
        array $seed_profiles,     // Initial population (at least 1)
        array $search_set_suites, // Eval suite slugs to use as search set
        array $opts = array()     // iterations, k, proposer, etc.
    ): array; // Returns { population, pareto_frontier, stats }

    // Internal:
    public static function evaluate_candidate( $assistant_id, array $profile, array $suites ): array;
    public static function compute_pareto_frontier( array $population ): array;
    public static function validate_candidate( array $profile ): array|WP_Error;
}
```

**Key design points:**

- **Search set = eval suites.** Reuses `WP_MCP_AI_Eval_Suite_Registry` and `WP_MCP_AI_Eval_Runner`. Each suite defines `input` and `expected` for each case, plus a `scorer` callable. No new evaluation infrastructure needed.
- **The proposer is pluggable.** Via filter `wp_mcp_ai_harness_proposer`:
  ```php
  // Base: Best-of-N random proposer (ships with base plugin)
  // Pro: Coding-agent proposer (Claude Code / Opus with trace access)
  $proposer = apply_filters( 'wp_mcp_ai_harness_proposer', null, $population, $trace_store, $assistant_id );
  ```
- **Candidate validation.** Two-stage: (1) schema validation — does the JSON parse and pass `WP_MCP_AI_Harness_Profile::sanitize()`? (2) smoke test — apply the profile to a tiny eval set (1–2 cases), verify it produces a response without crashing. Meta-Harness Appx D pattern.
- **Pareto frontier computation.** Multi-objective: maximize accuracy, minimize context tokens, minimize latency. Uses standard non-dominated sorting. Stored as transient `wp_mcp_ai_harness_pareto_{assistant_id}` for admin UI.
- **Background execution.** Search runs asynchronously via the existing durable-run infrastructure. Admin triggers from metabox button; progress reported via SSE to the admin UI.

#### Task 2.2 — Create search engine CLI commands (1.0 day)

```bash
# WP-CLI commands for the search engine (new file: includes/cli/class-wp-mcp-ai-cli-harness-search-command.php)

wp mcp-ai harness search start <assistant_id> [--iterations=20] [--k=2]
wp mcp-ai harness search status <search_run_id>
wp mcp-ai harness search results <search_run_id> [--format=table|json]
wp mcp-ai harness search cancel <search_run_id>

wp mcp-ai harness population list <assistant_id> [--limit=20] [--format=table]
wp mcp-ai harness population show <profile_hash> [--format=json]
wp mcp-ai harness population diff <hash_a> <hash_b>         # Show what changed between two profiles

wp mcp-ai harness trace list <assistant_id> [--limit=20]
wp mcp-ai harness trace show <run_id> [--artifact=profile|trace|tools|score]
wp mcp-ai harness trace export <run_id> [--output=/path/to/dir]
```

These CLIs serve dual purpose: (1) human operators debugging harness behaviour, and (2) the Pro proposer agent navigating the trace store — matching Meta-Harness Appx D: "A short CLI that lists the Pareto frontier, shows top-k harnesses, and diffs code and results between pairs of runs can make the experience store much easier to use."

#### Task 2.3 — Admin UI for search engine (1.0 day)

New admin page: **NV oOS → Harness Optimizer**

- **Search tab:** Start a new search run, view progress (SSE), see Pareto frontier chart
- **Population tab:** Browse all candidate profiles, filter by eval suite × score range, diff any two profiles
- **Trace tab:** View per-run trace artifacts, download as ZIP
- **Profile diff viewer:** Side-by-side JSON diff with color-coded changes, showing which parameters changed between two profile candidates

**Deliverable:** Two new PHP files + one new CLI file + one admin page.

**Testing:**
```bash
vendor/bin/phpunit tests/test-harness-search-engine.php
vendor/bin/phpunit tests/test-harness-search-cli.php
```

---

### Phase 3: Pro — Coding-Agent Proposer (Pro addon, 4 days)

The proposer agent that inspects traces and proposes improved harness profiles. This is a Pro feature because it requires a capable model (Claude Opus / GPT-5 class) and incurs significant API costs.

#### Task 3.1 — Create `class-wp-mcp-ai-pro-harness-proposer.php` (2.0 days)

```php
/**
 * Pro Harness Proposer — coding-agent-based profile optimization.
 *
 * Location: addons/pro/includes/harness/class-wp-mcp-ai-pro-harness-proposer.php
 *
 * Implements the proposer from Meta-Harness Algorithm 1, adapted to NV oOS.
 * The proposer is invoked as a filter callback on `wp_mcp_ai_harness_proposer`.
 */

class WP_MCP_AI_Pro_Harness_Proposer {
    // The proposer model — configurable via admin settings.
    // Default: claude-opus-4-6 (matches Meta-Harness paper)
    const DEFAULT_PROPOSER_MODEL = 'claude-opus-4-6';

    /**
     * Propose k new harness profiles based on prior traces.
     *
     * @param array $population    Current population of evaluated profiles.
     * @param int   $assistant_id  Assistant being optimized.
     * @param array $search_suites Eval suites used as search set.
     * @param int   $k             Number of candidates to propose.
     * @return array{ candidates: array<array>, reasoning: string }
     */
    public function propose( array $population, int $assistant_id, array $search_suites, int $k = 2 ): array;
}
```

**Proposer workflow (matching Meta-Harness):**

1. **Inspect population.** The proposer reads the Pareto frontier and recent failures via the trace store CLI. It can `grep` for specific failure patterns across tool_calls.jsonl files.
2. **Form hypotheses.** Based on trace inspection, the proposer forms causal hypotheses (e.g., "assistant fails on QA tasks because retrieval.k=3 misses relevant passages → try k=7 for QA task class").
3. **Propose edits.** Generates k distinct candidate profiles. Each is a diff from the best-so-far profile. The proposer is instructed to make minimal, targeted changes (Self-Harness pattern: "prefer recurrent error patterns that are addressable and can be resolved by narrow changes").
4. **Self-critique.** Each proposed candidate goes through one self-refine pass (the proposer checks: "Is this edit likely to fix the observed failure without breaking passing cases?").

**Skill text design (critical per Meta-Harness Appx D):**

The proposer receives a structured skill text defining:

```markdown
# NV oOS Harness Optimization Agent

## Your role
You are a harness optimization agent. Your job is to inspect execution traces
from prior harness configurations and propose improved profiles.

## What you can read
- /traces/{run_id}/profile.json — the harness profile used for this run
- /traces/{run_id}/score.json — evaluation scores per suite
- /traces/{run_id}/reasoning_trace.json — model reasoning trace
- /traces/{run_id}/tool_calls.jsonl — each tool call, args, result, timing
- /traces/{run_id}/self_refine.json — self-refine iterations and feedback
- /population/pareto_frontier.json — current Pareto-optimal profiles

## What you can propose
- Edits to harness profile JSON (cue slugs, reasoning n_samples, router weights,
  retrieval k, refine max_iters, memory task_class, cost ceilings)
- Task-class-specific overrides (different settings for qa vs code vs research)
- New cue template text (only when existing cues are insufficient)

## What you must NOT touch
- Tool implementations (tool execution code)
- Orchestration layer (model routing, budget enforcement)
- Security gates (guardrails, PII filter patterns, capability checks)
- The proposer's own code

## Output format
Each proposal must be a valid harness profile JSON object with a "rationale"
field explaining the causal reasoning behind each change.

## Objectives
Primary: maximize eval suite accuracy
Secondary: minimize context token usage
Tertiary: minimize API cost
```

#### Task 3.2 — Implement proposer agent loop (1.5 days)

The proposer is invoked as a sub-agent via the existing agent infrastructure. It receives:

1. A working directory with symlinks to relevant trace store directories
2. A skill file (the text above)
3. Read-only access to the trace store, population store, and eval suite definitions
4. Write access only to a `proposals/` subdirectory

After proposing, the search engine validates and evaluates candidates.

**Safety constraints:**

- Proposer API costs tracked via `wp_mcp_ai_cost_calculated`
- Hard cap: proposer runs max 20 iterations × 2 candidates = 40 profile evaluations
- Each evaluation runs the full eval suite (max 100 cases by default)
- Total cost ceiling configurable per search run (default: $50)
- Proposer model is configurable independent of the assistant's model

#### Task 3.3 — Pro admin settings (0.5 days)

Under **NV oOS → Settings → Pro → Harness Optimizer**:

- **Proposer model** — dropdown (all configured providers capable of agentic coding)
- **Max iterations per search** — slider (5–50, default 20)
- **Max cost per search** — USD input (default $50)
- **Search frequency** — "Allow scheduled auto-optimization" toggle + cron schedule
- **Auto-deploy threshold** — "Automatically apply profile if improvement exceeds N%" (with minimum held-out eval pass rate)
- **Notification** — "Email admin when a new best profile is discovered"

**Deliverable:** Two Pro PHP files + Pro admin settings. Wired via `addons/pro/includes/harness-init.php`.

---

### Phase 4: Advanced Search Dimensions — Cues & Retrieval Routes (2 days)

Expands the search space beyond profile JSON to include discovered cue templates and retrieval strategies. This is where the system moves from "profile search" toward true "harness code search."

#### Task 4.1 — Discovered cue registration (0.75 days)

Extend `WP_MCP_AI_Prompt_Cue_Library` with a discovered-cue registry:

```php
// New method on Prompt Cue Library (includes/harness/class-wp-mcp-ai-prompt-cue-library.php):
WP_MCP_AI_Prompt_Cue_Library::register_discovered_cue( array(
    'slug'             => 'verify_against_db_first',
    'text'             => 'Before answering any question, verify your assumptions against the database...',
    'discovered_for'   => 'qa',
    'search_run_id'    => 42,
    'score_delta'      => 0.12,     // improvement over baseline
    'status'           => 'candidate', // candidate → accepted → active → deprecated
) );
```

Discovered cues are stored in a new option `wp_mcp_ai_discovered_cues` and surfaced in the harness profile metabox with a "Discovered" badge and provenance info (which search run found them).

#### Task 4.2 — Retrieval strategy routing (Pro, 1.25 days)

Meta-Harness' math retrieval harness (Appendix B.2) discovered a 4-route BM25 program with domain-specific retrieval policies. The NV oOS equivalent:

```php
/**
 * Retrieval Strategy — per-task-class retrieval routing.
 *
 * Location: addons/pro/includes/harness/class-wp-mcp-ai-pro-retrieval-strategy.php
 *
 * A retrieval strategy defines, per task class:
 *   1. Which source tools to try, in what order
 *   2. Per-source k, dedup threshold, rerank function
 *   3. Freshness vs relevance blend ratio
 *   4. Whether to include a "hard reference" index (like geometry fixes)
 *
 * Strategies are discovered by the proposer and stored as JSON in
 * wp-content/uploads/mcp-ai-harness-strategies/{slug}.json
 */

class WP_MCP_AI_Pro_Retrieval_Strategy {
    public static function apply( string $strategy_slug, string $query, string $task_class, array $scope ): array;
    public static function register( string $slug, array $definition ): bool;
    public static function list_discovered(): array;
}
```

This extends Layer D without modifying `WP_MCP_AI_Retrieval_Harness`. The harness profile gains a new key:

```jsonc
{
  "retrieval": {
    "enabled": true,
    "k": 5,
    "strategy": "auto",          // NEW: "fixed" (current) | "learned" | "auto"
    "strategy_slug": "qa_v3",    // NEW: which discovered strategy to use
    "require_citations": true
  }
}
```

When `strategy: "auto"`, the retrieval harness delegates to the strategy router, which selects the best discovered strategy for the current task class.

**Deliverable:** Two files modified (Prompt Cue Library + new Pro strategy class). Existing retrieval harness unchanged (strategy is additive).

---

### Phase 5: Cross-Assistant Transfer & Auto-Deploy (2 days)

#### Task 5.1 — Harness population sharing (1.0 day)

Enable discovered harnesses from one assistant to transfer to others. This matches Meta-Harness' finding that harnesses generalize across unseen models and datasets.

```php
/**
 * Harness Population — shared across assistants.
 *
 * Location: includes/harness/class-wp-mcp-ai-harness-population.php
 *
 * The population is the global archive of evaluated harness profiles
 * across all assistants on the site. Assistants can:
 *   - Contribute profiles (from search runs)
 *   - Inherit profiles (apply a discovered profile from the population)
 *   - Track lineage (which assistant discovered which profile, and its descendants)
 */

class WP_MCP_AI_Harness_Population {
    // Storage: CPT 'mcp_ai_harness_profile' or JetEngine CCT
    const POST_TYPE = 'mcp_ai_harness_profile';

    public static function archive( array $profile, array $eval_results, int $source_assistant_id ): int; // returns profile_id
    public static function get_population( array $filters = array() ): array;
    public static function get_pareto_frontier( string $task_class = 'general' ): array;
    public static function transfer( int $profile_id, int $target_assistant_id ): bool;
    public static function get_lineage( int $profile_id ): array; // parent/child/sibling graph
}
```

Admin UI: **NV oOS → Harness Optimizer → Population** shows:

- "Transfer to this assistant" button on any population profile
- "Suggested for this assistant" section (Pareto-optimal profiles that improved similar assistants)
- Lineage graph (Mermaid diagram showing which profiles were derived from which)

#### Task 5.2 — Auto-deploy with regression gates (1.0 day)

Self-Harness pattern: accept candidates only if they pass both held-in and held-out eval data.

```php
/**
 * Harness Auto-Deploy — safe profile application with regression gates.
 *
 * Location: includes/harness/class-wp-mcp-ai-harness-auto-deploy.php
 */
class WP_MCP_AI_Harness_Auto_Deploy {
    // Configurable thresholds:
    const MIN_HELD_IN_IMPROVEMENT = 0.02;   // 2% minimum improvement on search set
    const MIN_HELD_OUT_PASS_RATE = 0.95;     // 95% of previously-passing cases must still pass
    const MAX_HELD_OUT_REGRESSION = 0.05;    // No more than 5% regression on held-out

    /**
     * Evaluate whether a candidate profile is safe to auto-deploy.
     *
     * @return array{ approved: bool, reason: string, metrics: array }
     */
    public static function evaluate_auto_deploy(
        int $assistant_id,
        array $candidate_profile,
        array $held_in_scores,
        array $held_out_scores,
        array $baseline_scores
    ): array;

    /**
     * Apply a profile with rollback capability.
     * Stores the previous profile so it can be reverted.
     */
    public static function apply_with_rollback( int $assistant_id, array $new_profile ): bool;
    public static function rollback( int $assistant_id ): bool; // reverts to last known-good
}
```

**Safety guarantee:** A profile is never auto-deployed if it regresses on any held-out eval suite. The admin receives an email notification when a candidate passes all gates, with a one-click "Apply" link that still requires manual confirmation.

**Deliverable:** Two new PHP files + admin UI enhancements.

---

### Phase 6: DSpark Signal Integration (1 day)

Wire DSpark speculative execution metrics into the trace store and search feedback loop.

#### Task 6.1 — DSpark trace enrichment (0.5 days)

The DSpark system already tracks chain acceptance rates, tier distributions, and cost savings. Add a DSpark-specific artifact to the trace store:

```jsonc
// traces/{run_id}/dspark.json
{
  "speculative_blocks_attempted": 12,
  "speculative_blocks_accepted": 9,
  "acceptance_rate": 0.75,
  "tier_distribution": { "draft": 8, "verification": 3 },
  "cost_savings_usd": 0.042,
  "depth_tier_used": "standard",
  "chain_rejection_reasons": {
    "tool_result_mismatch": 2,
    "state_conflict": 1
  }
}
```

Wired via the existing DSpark hooks (`wp_mcp_ai_orchestration_depth_tier`, `wp_mcp_ai_tiered_model_selection`) — no changes to DSpark classes.

#### Task 6.2 — DSpark-aware proposer scoring (0.5 days)

Extend the proposer's evaluation of candidates to include:

- **Speculative acceptance rate:** Higher is better (fewer wasted API calls)
- **Draft-tier success rate:** Measures whether the routing is appropriately choosing draft vs verification models
- **Chain rejection patterns:** If a profile consistently causes chain rejections on a specific tool, the proposer can identify it

This gives the proposer richer diagnostic information — it can see not just "the harness got the wrong answer" but "the harness's tool selection caused 3 speculative chain rejections per request."

**Deliverable:** DSpark trace capture (existing hooks) + proposer scoring extension. No new classes.

---

### Phase 7: Testing & Documentation (3 days)

#### Task 7.1 — PHPUnit test suites (1.5 days)

```bash
# New test files:
tests/test-harness-trace-store.php        # Write, read, prune, JSONL append, file caps
tests/test-harness-search-engine.php      # Population management, Pareto frontier, validation
tests/test-harness-search-cli.php         # WP-CLI command coverage
tests/test-harness-population.php         # Archive, transfer, lineage
tests/test-harness-auto-deploy.php        # Regression gates, rollback
tests/test-harness-pro-retrieval-strategy.php  # Strategy registration, per-task-class routing
tests/test-harness-pro-proposer.php       # Proposer output schema, skill text parsing
```

Coverage targets:

- Trace store: write/read round-trip, prune behaviour, JSONL integrity, file size caps, concurrent access
- Search engine: zero-iteration (seed-only), multi-iteration convergence, proposer error recovery, Pareto correctness
- Auto-deploy: held-in improvement gate, held-out regression gate, rollback integrity, notification triggers
- Proposer (Pro): valid profile output, rationale presence, distinct candidates (diversity check), cost ceiling enforcement

#### Task 7.2 — Integration tests with eval suites (0.75 days)

Use the existing eval suite infrastructure to test the full search loop end-to-end:

- Define a minimal eval suite (5 cases) with known-answer cases
- Run a 3-iteration search with the Best-of-N proposer (base)
- Verify: population grows, scores are recorded, Pareto frontier is computed
- Verify: traces are written per-run, can be read back
- Verify: no regression on held-out cases when best profile is applied

#### Task 7.3 — Documentation (0.75 days)

| Document | Content |
|----------|---------|
| `docs/features/harness-auto-optimization.md` | User-facing: how to enable, run searches, interpret results, auto-deploy |
| `docs/developer/harness-search-internals.md` | Developer-facing: architecture, hooks, extending with custom proposers |
| `docs/project/proposals/006-research-notes.md` | Phase 0 research notes (search space, schema, skill text) |
| Update `CLAUDE.md` § "LLM Harnessing Subsystem" | Add trace store, search engine, population entries |
| Update `docs/features/llm-harness.md` | Add "Layer I — Auto-Optimization" section |

**Deliverable:** 5 documentation files + 7 test files.

---

### Phase 8: Iteration & Polish (2 days)

After Phase 1–7 ships, run 2–3 real search runs against the plugin's own eval suites and polish.

#### Task 8.1 — Dogfooding run (1.0 day)

Run a full Meta-Harness search using the Pro proposer against:

- The existing `qa_accuracy` eval suite (or create a small one if not yet defined)
- A set of 3 assistant profiles (different task classes: qa, code, research)
- 10 iterations × 2 candidates = 20 evaluations per assistant

Expected outcomes to validate:
- Proposer identifies meaningful improvements (non-zero score delta)
- Proposer's reasoning (rationale field) matches observed trace failures
- No regressions on held-out eval cases
- Trace store handles the volume without performance degradation

#### Task 8.2 — Iterate on skill text (0.75 days)

Per Meta-Harness Appx D: "iterating on the skill text had a larger effect on search quality than changing iteration count or population size." Based on the dogfooding run:

- Review proposer reasoning quality — is it making causal hypotheses or random edits?
- Tune skill text wording — add constraints, clarify objectives, add examples
- Test with different proposer models if available

#### Task 8.3 — Polish admin UI (0.25 days)

- Pareto frontier chart (Chart.js line chart in admin)
- "Optimization history" timeline showing score progression across iterations
- Loading states and error states for async search runs

---

## Consolidated File Change Map

| Phase | File | Change | Risk | Location |
|-------|------|--------|------|----------|
| 1 | `class-wp-mcp-ai-harness-trace-store.php` | **NEW** — trace artifact storage | Low — additive | `includes/harness/` |
| 1 | `class-wp-mcp-ai-harness-trace-capture.php` | **NEW** — hook subscriber | Low — additive | `includes/harness/` |
| 1 | `harness-init.php` | **MODIFIED** — load new files | Low — require_once only | `includes/harness/` |
| 1 | Metabox section | **NEW** — trace capture toggle + run viewer | Low — UI only | `includes/admin/` |
| 2 | `class-wp-mcp-ai-harness-search-engine.php` | **NEW** — search loop | Medium — new subsystem | `includes/harness/` |
| 2 | `class-wp-mcp-ai-cli-harness-search-command.php` | **NEW** — WP-CLI commands | Low — additive | `includes/cli/` |
| 2 | Admin search page | **NEW** — optimizer UI | Low — UI only | `includes/admin/` |
| 3 | `class-wp-mcp-ai-pro-harness-proposer.php` | **NEW** — coding-agent proposer | Medium — API cost | `addons/pro/includes/harness/` |
| 3 | Pro admin settings | **NEW** — proposer config | Low — UI only | `addons/pro/includes/admin/` |
| 3 | `harness-init.php` (Pro) | **MODIFIED** — load proposer, wire filter | Low | `addons/pro/includes/` |
| 4 | `class-wp-mcp-ai-prompt-cue-library.php` | **MODIFIED** — discovered cue registry | Low — additive method | `includes/harness/` |
| 4 | `class-wp-mcp-ai-pro-retrieval-strategy.php` | **NEW** — per-task-class routing | Medium — new subsystem | `addons/pro/includes/harness/` |
| 5 | `class-wp-mcp-ai-harness-population.php` | **NEW** — cross-assistant sharing | Low — additive | `includes/harness/` |
| 5 | `class-wp-mcp-ai-harness-auto-deploy.php` | **NEW** — regression-gated deploy | Medium — state-changing | `includes/harness/` |
| 6 | DSpark trace capture | **MODIFIED** — add dspark.json artifact | Low — hook subscriber | `includes/harness/` |
| 7 | 7 test files | **NEW** — test coverage | Low — additive | `tests/` |
| 7 | 5 doc files | **NEW/MODIFIED** — documentation | Low | `docs/` |

**Total: 11 new files, 5 modified files, 7 test files, 5 doc files.**

---

## Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|-----------|
| **Proposer API cost overrun** | High | Hard cap on iterations × candidates; cost ceiling per search run; proposer model configurable independently; kill switch in admin |
| **Proposer produces invalid profiles** | Medium | Two-stage validation (schema check + smoke test) before expensive evaluation; invalid candidates never reach eval |
| **Search overfits to eval suites** | High | Held-out eval suites mandatory for auto-deploy; regression gates prevent degradation; manual review required for deployment |
| **Trace store disk usage** | Medium | Per-assistant run cap (50 runs); per-file size cap (10 MB); automatic pruning; admin-configurable retention |
| **Proposer proposes insecure configurations** | Low | Profile sanitizer clamps all values to hard caps; guardrails/necessity gates are not in the searchable space; PII filter is always on for memory writes |
| **Performance impact of trace capture** | Low | Trace capture off by default; JSONL append is O(1); filesystem writes are async (shutdown hook); no DB writes in hot path |
| **Race condition: two search runs on same assistant** | Medium | Search engine acquires a per-assistant lock (transient, 30 min TTL); second run is rejected with a clear message |
| **Proposer exploits benchmark artifacts** | Medium | Held-out eval suites are separate from search suites; proposer never sees held-out results; manual audit of proposer reasoning |
| **Backward compatibility** | Low | All new features are additive and off-by-default; existing harness profiles unchanged; existing hooks continue to fire as before |

---

## Dependencies Between Phases

```
                    Phase 0 (Research)
                         │
                         ▼
                    Phase 1 (Trace Store)
                         │
              ┌──────────┼──────────┐
              ▼          ▼          ▼
        Phase 2      Phase 4     Phase 6
     (Search Engine) (Cues+Routes) (DSpark)
              │          │          │
              └──────────┼──────────┘
                         ▼
                    Phase 3 (Proposer)
                         │
                         ▼
                    Phase 5 (Transfer)
                         │
                         ▼
                    Phase 7 (Testing)
                         │
                         ▼
                    Phase 8 (Polish)
```

- **Phase 0** is fully parallel — research only, no code
- **Phase 1** is the critical path — everything depends on trace capture
- **Phases 2, 4, and 6** can run partially in parallel (different files, no conflicts)
- **Phase 3** depends on Phases 2 (uses search engine) and 1 (reads traces)
- **Phase 5** depends on Phases 2 and 3 (population + proposer must exist)
- **Phase 7** depends on all code phases
- **Phase 8** depends on Phase 7 (must have tests to dogfood safely)

---

## Total Estimated Effort

| Phase | Tasks | Days | Base/Pro | Iteration |
|-------|-------|------|----------|-----------|
| 0 — Research | 3 | 1.0 | Base | Iteration 1 |
| 1 — Trace Store | 3 | 3.0 | Base | Iteration 1 |
| 2 — Search Engine | 3 | 4.0 | Base | Iteration 1 |
| **Iteration 1 subtotal** | **9** | **8.0** | | |
| 3 — Pro Proposer | 3 | 4.0 | Pro | Iteration 2 |
| 4 — Cues & Retrieval | 2 | 2.0 | Base+Pro | Iteration 2 |
| **Iteration 2 subtotal** | **5** | **6.0** | | |
| 5 — Transfer & Auto-Deploy | 2 | 2.0 | Base | Iteration 3 |
| 6 — DSpark Integration | 2 | 1.0 | Base | Iteration 3 |
| 7 — Testing & Docs | 3 | 3.0 | Base+Pro | Iteration 3 |
| 8 — Iteration & Polish | 3 | 2.0 | Base+Pro | Iteration 3 |
| **Iteration 3 subtotal** | **10** | **8.0** | | |
| **Total** | **24** | **22.0** | | |

**Base plugin estimate:** ~14 days (Phases 0, 1, 2, 4 base, 5, 6, 7 base, 8)  
**Pro addon estimate:** ~8 days (Phases 3, 4 pro, 7 pro, 8 pro)  
**Combined:** ~22 days across 3 iterations (approximately 5–6 weeks with review cycles)

---

## References

### Primary Research

1. Lee, Y., Nair, R., Zhang, Q., Lee, K., Khattab, O., & Finn, C. (2026). **Meta-Harness: End-to-End Optimization of Model Harnesses.** arXiv:2603.28052. https://arxiv.org/abs/2603.28052
2. Weng, L. (2026). **Harness Engineering for Self-Improvement.** Lil'Log. https://lilianweng.github.io/posts/2026-07-04-harness/
3. Böckeler, B. (2026). **Harness engineering for coding agent users.** Martin Fowler Blog. https://martinfowler.com/articles/harness-engineering.html
4. OpenAI. (2026). **Harness engineering: leveraging Codex in an agent-first world.** https://openai.com/index/harness-engineering/

### Context Engineering Lineage

5. Zhang, Q., et al. (2025). **Agentic Context Engineering: Evolving Contexts for Self-Improving Language Models.** ICLR 2026.
6. Ye, H., et al. (2026). **Meta Context Engineering via Agentic Skill Evolution.** arXiv:2601.21557.
7. Zhang, J., et al. (2026). **Self-Harness: Harnesses That Improve Themselves.** arXiv:2606.09498.

### Evolutionary Search

8. Novikov, A., et al. (2025). **AlphaEvolve: A coding agent for scientific and algorithmic discovery.** arXiv:2506.13131.
9. Hu, S., Lu, C., & Clune, J. (2025). **Automated Design of Agentic Systems.** ICLR 2025.
10. Zhang, J., et al. (2025). **Darwin Gödel Machine: Open-Ended Evolution of Self-Improving Agents.** arXiv:2505.22954.
11. Agrawal, A., et al. (2025). **GEPA: Reflective Prompt Evolution Can Outperform Reinforcement Learning.** arXiv:2507.19457.

### Recursive Self-Improvement

12. Zelikman, E., et al. (2023). **Self-Taught Optimizer (STOP): Recursively Self-Improving Code Generation.** COLM 2024.
13. Hebbar, et al. (2026). **SIA: Self Improving AI with Harness & Weight Updates.** arXiv:2605.27276.

### NV oOS Internal Documentation

14. `docs/features/llm-harness.md` — LLM Harnessing Subsystem (7-layer architecture)
15. `CLAUDE.md` — Claude Code context (orchestration, DSpark, hooks)
16. `includes/harness/harness-init.php` — Harness bootstrap
17. `.context/` — Subsystem context files

---

*This proposal was prepared by AI-assisted analysis synthesizing the Meta-Harness paper, Lilian Weng's survey of 35+ papers, production harness engineering patterns from OpenAI and Thoughtworks, and a deep audit of NV oOS's existing 7-layer harness subsystem and 60+ lifecycle hooks.*
