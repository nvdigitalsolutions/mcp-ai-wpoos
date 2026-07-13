# Phase 0 Research Notes — Meta-Harness Search Space & Trace Schema

**Proposal:** 006-meta-harness-auto-optimization.md  
**Date:** 2026-07-12  
**Status:** Completed — ready for Phase 1 implementation

---

## 1. Search Space Dimensions

Every tunable parameter across the 7 harness layers that a proposer could modify:

### Layer A — Prompt/Cue
| Parameter | Type | Range | Default |
|-----------|------|-------|---------|
| `cues[]` | string[] (cue slugs) | Any subset of registered cues | `[]` |
| Cue ordering | implicit (array order) | N/A | Registration order |

### Layer B — Reasoning
| Parameter | Type | Range | Default |
|-----------|------|-------|---------|
| `reasoning.enabled` | bool | — | `false` |
| `reasoning.n_samples` | int | 1–8 | `1` |
| `reasoning.max_iters` | int | 1–4 | `1` |

### Layer C — Tool Routing
| Parameter | Type | Range | Default |
|-----------|------|-------|---------|
| `tools.router` | string enum | `fixed`, `scored` | `fixed` |
| `tools.preset_weights.{slug}` | float | -5.0 .. +5.0 | (none) |

Available preset slugs (`WP_MCP_AI_Tool_Presets_Helper::get_presets()`):
`agentic_workflow`, `ai_ml`, `content_writing`, `ecommerce`, `seo_marketing`,
`crawling_scraping`, `communications`, `developer_tools`, `healthcare`,
`media_images`, `social_media`, `calendar_orchestration`, `document_generation`,
`dietpi`, `fantasy_football`, `embedded_webllm`

### Layer D — Retrieval
| Parameter | Type | Range | Default |
|-----------|------|-------|---------|
| `retrieval.enabled` | bool | — | `false` |
| `retrieval.k` | int | 1–50 | `5` |
| `retrieval.require_citations` | bool | — | `false` |
| `retrieval.strategy` | string enum | `fixed`, `learned`, `auto` | (not yet in schema) |
| `retrieval.strategy_slug` | string | discovered strategy slug | (not yet in schema) |

### Layer E — Self-Refine
| Parameter | Type | Range | Default |
|-----------|------|-------|---------|
| `refine.enabled` | bool | — | `false` |
| `refine.max_iters` | int | 1–4 | `1` |

### Layer F — Memory Scoping
| Parameter | Type | Range | Default |
|-----------|------|-------|---------|
| `memory.scoped` | bool | — | `false` |
| `memory.task_class` | string | `general`, `qa`, `code`, `research`, `rag`, `math`, `agentic` | `general` |
| `memory.pii_filter` | bool | — | `true` |

### Layer G — Evaluation
| Parameter | Type | Range | Default |
|-----------|------|-------|---------|
| `evals_enabled[]` | string[] (suite slugs) | Any subset of registered suites | `[]` |
| `verifiers[]` | string[] (verifier slugs) | Any subset | `[]` |

### Cross-cutting
| Parameter | Type | Range | Default |
|-----------|------|-------|---------|
| `cost_ceiling_usd` | float | 0.0 .. 1000.0 | `1.0` |

**Combinatorial space estimate:** ~10^12 possible profiles per assistant. The proposer narrows this via trace-guided causal search.

---

## 2. Trace Artifact Schema

### meta.json
```json
{
  "schema_version": "1.0",
  "run_id": "assistant_42_run_1750000000_a1b2c3",
  "assistant_id": 42,
  "profile_hash": "md5_of_profile_json",
  "model": "gpt-4o",
  "task_class": "qa",
  "started_at": 1750000000,
  "finished_at": 1750000015,
  "duration_ms": 15000,
  "provider": "openai",
  "search_run_id": null
}
```

### profile.json
The resolved, sanitized harness profile at run time (output of `WP_MCP_AI_Harness_Profile::get()`).

### score.json
```json
{
  "suites": {
    "qa_accuracy": {
      "score": 0.85,
      "total_cases": 20,
      "passed": 17,
      "failed": 3,
      "skipped": 0,
      "duration_ms": 4200
    }
  },
  "aggregate": {
    "mean_score": 0.85,
    "weighted_score": 0.85
  }
}
```

### cost.json
```json
{
  "total_tokens": 4520,
  "prompt_tokens": 3200,
  "completion_tokens": 1320,
  "estimated_cost_usd": 0.023,
  "provider": "openai",
  "model": "gpt-4o",
  "cost_ceiling_usd": 1.0,
  "within_ceiling": true
}
```

### reasoning_trace.json
Output of `WP_MCP_AI_Reasoning_Trace::new_trace()` — contains:
`assumptions[]`, `constraints[]`, `plan[]`, `intermediate_results[]`, `verification[]`, `answer`

### retrieval.json
```json
{
  "passages": [
    {
      "text": "...",
      "source": "recall_memory",
      "score": 0.85,
      "freshness": 0.92,
      "ranked_score": 0.871,
      "citation": {
        "source": "recall_memory",
        "source_id": "mem_123",
        "timestamp": 1749000000,
        "content_hash": "abc123"
      }
    }
  ],
  "freshness": 0.85,
  "recall_confidence": 0.78,
  "sources_tried": ["recall_memory", "semantic_context_search", "retrieve_agent_memory"],
  "require_citations": true,
  "citation_verification": {
    "covered": true,
    "coverage_ratio": 0.92,
    "unsupported": []
  }
}
```

### tool_calls.jsonl (one JSON object per line)
```json
{"seq":1,"slug":"database_query","args":{"query":"SELECT..."},"result_success":true,"result_type":"array","result_summary":"3 rows","duration_ms":45,"cost_usd":0.0,"timestamp":1750000001}
{"seq":2,"slug":"format_response","args":{"data":"..."},"result_success":true,"result_type":"string","result_summary":"OK","duration_ms":2,"cost_usd":0.0,"timestamp":1750000001}
```

### self_refine.json
```json
{
  "enabled": true,
  "max_iters": 2,
  "iterations": [
    {
      "iteration": 1,
      "verdict": "revise",
      "feedback": "The answer is missing a citation for the refund policy claim.",
      "candidate_length": 450
    },
    {
      "iteration": 2,
      "verdict": "accept",
      "feedback": "",
      "candidate_length": 520
    }
  ],
  "stopped_reason": "accepted",
  "estimated_cost_usd": 0.04
}
```

### model_response.txt
Plain text of the final assistant response.

---

## 3. Proposer Skill Text (Draft)

```markdown
# NV oOS Harness Optimization Agent

## Your role
You are a harness optimization agent for the NV oOS WordPress plugin. Your job
is to inspect execution traces from prior harness configurations and propose
improved harness profiles that increase evaluation scores while minimizing
context token usage.

## What you can read
- /traces/{run_id}/meta.json — run metadata (assistant, model, timing)
- /traces/{run_id}/profile.json — the harness profile used for this run
- /traces/{run_id}/score.json — evaluation scores per suite
- /traces/{run_id}/cost.json — token counts and estimated cost
- /traces/{run_id}/reasoning_trace.json — model reasoning trace
- /traces/{run_id}/retrieval.json — retrieved passages and citation verification
- /traces/{run_id}/tool_calls.jsonl — each tool call, args, result, timing
- /traces/{run_id}/self_refine.json — self-refine iterations and feedback
- /traces/{run_id}/model_response.txt — final model response
- /population/pareto_frontier.json — current Pareto-optimal profiles
- /suites/{suite_slug}/definition.json — eval suite case definitions

## What you can propose
- Edits to harness profile JSON:
  - cue_slugs[] — which prompt cues to activate
  - reasoning.n_samples (1-8), reasoning.max_iters (1-4)
  - tools.router: "fixed" or "scored"
  - tools.preset_weights: per-preset float weights (-5.0 to +5.0)
  - retrieval.k (1-50), retrieval.require_citations (bool)
  - refine.enabled (bool), refine.max_iters (1-4)
  - memory.scoped (bool), memory.task_class (string)
  - memory.pii_filter (bool)
  - evals_enabled[] — which eval suites to run
  - cost_ceiling_usd (float, 0.0-1000.0)

## What you must NOT touch
- Tool implementations (tool execution code)
- Orchestration layer (model routing, budget enforcement)
- Security gates (guardrails, PII filter patterns, capability checks)
- The proposer's own code or the search engine

## Output format
Each proposal must be a valid JSON harness profile object wrapped in:
```json
{
  "rationale": "string explaining causal reasoning behind each change",
  "profile": { /* harness profile as per schema */ }
}
```

## Objectives (in priority order)
1. Maximize eval suite accuracy scores
2. Minimize context token usage (secondary)
3. Minimize API cost (tertiary)

## Strategy
1. First, inspect the worst-performing runs in the population. Use grep to
   find error patterns in tool_calls.jsonl and reasoning_trace.json.
2. Form causal hypotheses: "This profile failed because X → try changing Y"
3. Propose 2-3 distinct candidates. Each should change a different aspect of
   the harness. Prefer narrow, targeted edits over broad rewrites.
4. Self-critique each candidate before output: "Will this fix the observed
   failure without breaking passing cases?"
5. Prefer additive changes (adding cues, increasing k) over removing existing
   safeguards.
```

---

## 4. Validation & Smoke Test Design

### Schema validation
Pass candidate through `WP_MCP_AI_Harness_Profile::sanitize()`. If the output differs from input in unexpected ways (other than clamping), reject.

### Smoke test
Apply profile to 1-2 eval cases from the search set. Verify:
1. Profile loads without PHP error
2. Chat request completes without timeout
3. Response is a non-empty string
4. No PHP warnings/notices logged

Reject candidates that fail smoke test before expensive full evaluation.

---

## 5. File Size Caps & Retention Limits

| Limit | Value | Rationale |
|-------|-------|-----------|
| Max runs per assistant | 50 | Balances proposer history needs vs disk usage |
| Max files per run | 500 | Prevents runaway artifact generation |
| Max file size per artifact | 10 MB | Prevents single large trace from blocking I/O |
| Max tool_calls.jsonl lines | 10,000 | Prevents infinite-loop trace bloat |
| Pruning strategy | FIFO (oldest first) | Simple, predictable, no complex scoring needed |
| Base directory guards | .htaccess + index.php | Standard WP security pattern |
