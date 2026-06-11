# LLM Harnessing Subsystem

> **What it is.** A coherent, opt-in set of seven layers that "harness" any LLM the plugin already supports — OpenAI, Gemini, Anthropic, Ollama — so the same model produces better, more verifiable, more measurable answers without fine-tuning. Built on the existing agentic loop, `Reasoning_Controller`, Agent Memory CCT, MemPalace recall, transcript mining, and the ~830 tool registry. Behaviour-preserving by default.
>
> **Where it lives.** `includes/harness/` (base) and `includes/tools/harness/` (base tools). Pro-only extensions (best-of-N>3, autonomous overnight refine, learned router, LLM-as-judge, HumanEval sandbox, fine-tune candidate export) live under `addons/pro/includes/harness/` and are added in subsequent stories — the base layers are designed so Pro can plug in without touching them.

---

## The seven layers

| Layer | Service / class | What it does |
|-------|-----------------|--------------|
| **A — Prompt / Cue** | `WP_MCP_AI_Prompt_Cue_Library` | Registry of named, versioned cue templates ("Chain-of-Thought", "Failure-Modes-First", "Plan-Then-Solve", "Cite-or-Abstain", "Tool-or-Abstain", "Clarify First", "State Uncertainty") that *augment* — never replace — the assistant's existing system prompt. |
| **B — Reasoning / Rehearsal** | `WP_MCP_AI_Reasoning_Trace` | Canonical trace schema (`assumptions → constraints → plan → intermediate_results → verification → answer`) plus a self-consistency vote primitive for cheap test-time-compute scaling. |
| **C — Tool Routing** | `WP_MCP_AI_Tool_Router_Harness` | Scores candidate tools by task-class-aware capability flags + per-assistant preferences. **Since 1.8.0:** fuses with the attention router's semantic scores via Weighted Reciprocal Rank Fusion (RRF, k=60). Pluggable via `wp_mcp_ai_harness_tool_score` (now receives `$attention_score`). |
| **D — Retrieval** | `WP_MCP_AI_Retrieval_Harness` | Single retrieval entry point that fans out to `recall_memory`, `semantic_context_search`, and `retrieve_agent_memory`; deduplicates by content hash, attaches provenance and freshness, and verifies citations. |
| **E — Feedback / Self-Refine** | `WP_MCP_AI_Self_Refine_Loop` | Synchronous, bounded `generate → critique → revise` loop with hard caps on iterations and cost. Reflexion-style verbal reflections persisted via `record_reflection` after PII scrubbing. |
| **F — Memory Scoping** | `WP_MCP_AI_Tool_Scope_Memory` + `WP_MCP_AI_Pii_Filter` | Task-class buckets so reflections from one task don't pollute another, plus a conservative regex sweep for emails, phones, SSNs, credit-card-shaped digits, and common API key prefixes before any reflection write. |
| **G — Evaluation** | `includes/measurement/eval/*` (already present) | The repo already ships a sophisticated eval framework (verifiers with independence enforcement, reward registry, abstention tracking, OTel exporter, regression detector). The harness profile carries `evals_enabled` / `verifiers` keys so future work can wire profile-driven invocation without duplicating that infrastructure. |
| **H — Curriculum / Fine-tune Export** | Pro | Failure clustering and JSONL export for human review. Strictly Pro because of cost and human-review obligations. |

---

## Orchestration layer vs. harness — what's the difference?

The plugin has **two** complementary AI infrastructure layers. They are sometimes confused but solve different problems and operate at different points in the request lifecycle.

| Aspect | **Orchestration layer** (`docs/ORCHESTRATION_REFERENCE.md`) | **LLM harness** (this doc) |
|--------|------------------------------------------------------------|----------------------------|
| **Question it answers** | "*How* do I run this request safely, cheaply, and at scale?" | "*What* should the model do to produce a better answer?" |
| **Scope** | Cross-cutting infrastructure: model routing, tool execution, multi-agent coordination, budgets, health monitoring, async jobs. | Per-request *epistemic* quality: cues, reasoning traces, retrieval with provenance, self-refine, memory scoping, evals. |
| **Mental model** | The kitchen — burners, oven temperatures, dishwasher schedules, fire suppression. | The recipe — what ingredients to use, in what order, and how to taste-test before plating. |
| **Default behaviour** | **Always on.** The orchestration layer governs every chat request; you can tune presets but you cannot turn it "off". | **Off by default.** Every layer is gated by the per-assistant `harness_profile`. Behaviour-preserving until opted in. |
| **Configuration unit** | Site-wide settings (presets, PSO targets, budget caps) and per-tool capability flags. | Per-assistant post meta (`_wp_mcp_ai_harness_profile`) authored from the Assistant edit screen. |
| **What it touches** | The agentic loop, the tool registry, the reasoning controller, the multi-agent coordinator, the budget enforcer, OTel/health metrics. | The system prompt (cue prepending), the tool selection score, the retrieval payload, the draft-critique-revise loop, reflection writes, eval runs. |
| **Primary services** | `WP_MCP_AI_Tool_Execution_Orchestrator`, `WP_MCP_AI_Tool_Load_Balancer`, `WP_MCP_AI_Reasoning_Controller`, `WP_MCP_AI_Agent_Team_Orchestrator`, `WP_MCP_AI_PSO_Optimizer_Service`, `WP_MCP_AI_Budget_Enforcement_Service`. | `WP_MCP_AI_Prompt_Cue_Library`, `WP_MCP_AI_Reasoning_Trace`, `WP_MCP_AI_Tool_Router_Harness`, `WP_MCP_AI_Retrieval_Harness`, `WP_MCP_AI_Self_Refine_Loop`, `WP_MCP_AI_Pii_Filter`. |
| **Failure mode it prevents** | Runaway costs, stuck async jobs, capacity exhaustion, multi-agent deadlock, budget overrun. | Hallucinations, missing citations, premature answers, leaked PII in long-term memory, over-reliance on a single sample. |
| **Admin UI surface** | NV oOS → Orchestration Dashboard (multi-page: Overview, Workflows, PSO, Health, Multi-Agent). | Assistant edit screen → "LLM Harness" metabox (per assistant). |
| **When to extend** | You need a new execution strategy, a new model provider, a new resource preset, or a new health metric. | You need a new reasoning cue, a new verifier, a new task-class memory scope, or you want to wire profile-driven evals. |

### How they interact

The harness sits **on top of** the orchestration layer, not beside it:

```
┌───────────────────────── Chat request ─────────────────────────┐
│                                                                │
│  ┌─ Harness (per-assistant, opt-in) ─────────────────────────┐ │
│  │  • Layer A — prepend cues to the system prompt            │ │
│  │  • Layer B — request N reasoning samples                  │ │
│  │  • Layer C — score candidate tools                        │ │
│  │  • Layer D — wrap retrieval with provenance               │ │
│  │  • Layer E — wrap the draft in a critique-revise loop     │ │
│  │  • Layer F — scope memory writes by task class            │ │
│  └────────────────────────┬──────────────────────────────────┘ │
│                           │  (delegates execution to)           │
│                           ▼                                     │
│  ┌─ Orchestration layer (always on) ─────────────────────────┐ │
│  │  • Language model router (which provider/model?)          │ │
│  │  • Tool execution orchestrator (sync/async, caching)      │ │
│  │  • Reasoning controller (task complexity → mode)          │ │
│  │  • Multi-agent coordinator (delegate? parallelise?)       │ │
│  │  • PSO optimizer (adaptive parameter tuning)              │ │
│  │  • Budget enforcement (token/cost/timeout caps)           │ │
│  │  • Health monitoring (metrics, degradation, OTel)         │ │
│  └────────────────────────┬──────────────────────────────────┘ │
│                           │  (returns chunks/tool calls to)     │
│                           ▼                                     │
│                    Streaming chat response                      │
└────────────────────────────────────────────────────────────────┘
```

### Concrete examples

- **"My assistant keeps fabricating citations."** → Harness layer D (`require_citations = true`) + Layer A `cite_or_abstain` cue. The orchestration layer cannot solve this; it doesn't read the model's content.
- **"My async tool jobs are stuck in `queued`."** → Orchestration layer (`Async_Health_Monitor`, dead-letter handling). The harness has no opinion on async lifecycle.
- **"This particular assistant should never call write tools first."** → Harness layer C (scored router + read-only-first preference for the `qa` task class). The orchestration layer's tool execution governs *how* it runs, not *which* tool the model picks.
- **"Costs are ballooning across the whole site."** → Orchestration layer (`Budget_Enforcement_Service`, model routing presets). Profile cost ceilings are a per-assistant *additional* cap, not the primary lever.
- **"I need an evidence-grounded answer with self-checking on this assistant only."** → Harness layers A + B + D + E. The orchestration layer ensures the resulting (potentially larger) request still respects site-wide budgets and timeouts.

### Reading order

If you're new to either subsystem, start with whichever matches your problem:

- Cost / scale / async / multi-agent / health → [`docs/ORCHESTRATION_REFERENCE.md`](ORCHESTRATION_REFERENCE.md).
- Answer quality / hallucination control / citations / reasoning depth → keep reading this document.

---

## Quickstart

### 1. Turn the harness on for one assistant

The default profile is **off**. Save a profile via post meta to opt in:

```php
WP_MCP_AI_Harness_Profile::save( $assistant_id, array(
    'enabled'   => true,
    'cues'      => array( 'plan_then_solve', 'state_uncertainty' ),
    'reasoning' => array(
        'enabled'   => true,
        'n_samples' => 3,   // hard-capped at 8
    ),
    'retrieval' => array(
        'enabled'           => true,
        'k'                 => 5,
        'require_citations' => true,
    ),
    'refine'    => array(
        'enabled'   => true,
        'max_iters' => 2,   // hard-capped at 4
    ),
    'memory'    => array(
        'scoped'     => true,
        'task_class' => 'qa',
        'pii_filter' => true,
    ),
    'cost_ceiling_usd' => 0.50,
) );
```

The profile is sanitized and clamped on write; values exceeding the hard caps are silently reduced to the maximum.

### 2. Apply a cue from a tool call

```json
{
  "tool": "apply_prompt_cue",
  "arguments": {
    "system_prompt": "You are a research assistant. Be concise.",
    "cue_slugs": ["cite_or_abstain", "state_uncertainty"]
  }
}
```

The original prompt is preserved verbatim — cues are prepended, never overwritten.

### 3. Retrieve with provenance + verify citations in one call

```json
{
  "tool": "retrieve_with_provenance",
  "arguments": {
    "query": "What does our refund policy say about gift cards?",
    "k": 5,
    "verify_answer": "Gift cards are non-refundable per the 2024 update."
  }
}
```

Returns the top-k passages (each with `source`, `content_hash`, `timestamp`, `score`, `freshness`) plus a `verification` block reporting whether every claim-like sentence in the candidate answer is supported by at least one retrieved passage.

### 4. Self-refine with a verifier

```php
use WP_MCP_AI_Self_Refine_Loop as Loop;

$result = Loop::run(
    'Compute the discount for a $250 order with code SAVE15.',
    function ( $task, $prev = null, $crit = null ) {
        // Call your model. Return string.
    },
    function ( $task, $candidate ) {
        // Use the existing Agent_Role_Critic or a custom verifier.
        return array( 'verdict' => 'revise', 'feedback' => '...' );
    },
    array(
        'max_iters'     => 2,
        'cost_per_iter' => 0.02,
        'cost_ceiling'  => 0.20,
    )
);
```

Stop reasons (`accepted`, `rejected`, `max_iters`, `cost_ceiling`) are surfaced so the caller can attribute outcomes accurately.

---

## The cross-cutting profile

```jsonc
{
  "enabled": false,
  "cues": [],
  "reasoning": { "enabled": false, "n_samples": 1, "max_iters": 1 },
  "tools": { "router": "fixed" },                     // or "scored"
  "retrieval": { "enabled": false, "k": 5, "require_citations": false },
  "refine": { "enabled": false, "max_iters": 1 },
  "memory": { "scoped": false, "task_class": "general", "pii_filter": true },
  "evals_enabled": [],
  "verifiers": [],
  "cost_ceiling_usd": 1.0
}
```

Storage: post meta `_wp_mcp_ai_harness_profile`, JSON-encoded. Optional global fallback in `wp_mcp_ai_harness_profile_default` option. Filterable via `wp_mcp_ai_harness_profile`.

Hard caps enforced regardless of input:

| Field | Cap |
|-------|-----|
| `reasoning.n_samples` | `WP_MCP_AI_Harness_Profile::MAX_REASONING_SAMPLES` (8) |
| `reasoning.max_iters` | `WP_MCP_AI_Harness_Profile::MAX_REFINE_ITERATIONS` (4) |
| `refine.max_iters` | `WP_MCP_AI_Harness_Profile::MAX_REFINE_ITERATIONS` (4) |
| `retrieval.k` | 50 |
| `cost_ceiling_usd` | 1000.0 |

---

## Tools shipped with the harness

| Slug | Layer | Capability flags |
|------|-------|------------------|
| `list_prompt_cues` | A | read-only, local-only, idempotent, cacheable |
| `select_prompt_cue` | A | read-only, local-only, idempotent |
| `apply_prompt_cue` | A | read-only, local-only, idempotent |
| `self_consistency_vote` | B | read-only, local-only, idempotent |
| `retrieve_with_provenance` | D | read-only, cacheable |
| `record_reflection` | E | write, state-changing, requires-capability (`edit_posts`) |
| `scope_memory` | F | read-only, local-only, idempotent |

All seven tools are auto-registered via `wp_mcp_ai_register_tools` at priority 30 from `includes/harness/harness-init.php`.

---

## Hooks

See [`docs/hooks-reference.md` → LLM Harness Hooks](hooks-reference.md#llm-harness-hooks) for the full list:

- `wp_mcp_ai_register_prompt_cues` (action)
- `wp_mcp_ai_select_prompt_cue` (filter)
- `wp_mcp_ai_harness_profile` (filter)
- `wp_mcp_ai_harness_tool_score` (filter)
- `wp_mcp_ai_retrieval_passages` (filter)
- `wp_mcp_ai_retrieval_claim_supported` (filter)
- `wp_mcp_ai_pii_filter_patterns` (filter)
- `wp_mcp_ai_resolved_system_prompt` (filter — chat-client integration)
- `wp_mcp_ai_harness_inject_cue_slugs` (filter — late-stage cue substitution)
- `wp_mcp_ai_harness_eval_generator` (filter — Layer G generator binding)
- `wp_mcp_ai_harness_eval_completed` (action — fired after a scheduled run)

---

## Chat-client integration (Layer A)

The chat surface materialises a system prompt in three places:

1. **Server-side chat path** in `class-wp-mcp-ai-rest.php` (after the professional-prompt merge).
2. **Embedded-config endpoint** in `class-wp-mcp-ai-rest-chat-controller.php` that the WebLLM client polls to refresh its prompt at runtime.
3. **Shortcode bootstrap** in `class-wp-mcp-ai-shortcode.php` that pre-localises the prompt for the page render.

All three apply the `wp_mcp_ai_resolved_system_prompt` filter. The harness `WP_MCP_AI_Harness_Prompt_Injector` is the single subscriber — it loads the assistant's harness profile and prepends every cue listed in `harness_profile.cues` (in order) using `WP_MCP_AI_Prompt_Cue_Library::apply()`. Cues *augment* the existing assistant prompt; they never overwrite it. When the profile is disabled (the default) the filter is a no-op so existing chat behaviour is preserved exactly.

Sites that want to swap cues by task class — for example, "use `chain_of_thought` for math but `cite_or_abstain` for QA" — can hook `wp_mcp_ai_harness_inject_cue_slugs` to substitute the slug list on the way through without mutating the stored profile.

---

## Cost & latency guardrails

Every harness layer respects existing TPM budget enforcement and the `wp_mcp_ai_cost_calculated` hook. Best-of-N and self-refine are additionally hard-capped by per-request USD ceilings declared in the profile. The `WP_MCP_AI_Self_Refine_Loop` reports its `estimated_cost_usd` in every result so callers can attribute spend accurately.

---

## Security

- **PII / secret scrubbing.** All reflection writes pass through `WP_MCP_AI_Pii_Filter::scrub()` which redacts emails, phones, SSNs, credit-card-shaped digits, and common API key prefixes (OpenAI, Anthropic, Google, Stripe, GitHub, generic Bearer). Sites can extend the pattern list via `wp_mcp_ai_pii_filter_patterns`.
- **Capability gates.** `record_reflection` requires `edit_posts`. The harness profile save path requires `edit_post` on the assistant.
- **No new external endpoints.** The base harness layers do not introduce new outbound HTTP calls; they reuse existing client wrappers (OpenAI, Gemini, Anthropic, Ollama) via the agentic loop.
- **Defensive retrieval.** `WP_MCP_AI_Retrieval_Harness` wraps each underlying tool in a try/error boundary so a misbehaving downstream cannot break the whole call.

---

## Goodhart / reward-hacking mitigations

The plan calls out reward hacking as a first-class risk. The base subsystem ships:

1. **No single composite metric.** Tools return raw passage scores, freshness, recall confidence, and verification coverage as separate fields.
2. **PII filter is conservative.** False positives are preferable to leakage; a site can opt out of the filter per-profile (`memory.pii_filter = false`) but the default is on.
3. **Hard caps on test-time compute.** `MAX_REASONING_SAMPLES`, `MAX_REFINE_ITERATIONS`, and `cost_ceiling_usd` are enforced server-side regardless of caller input.
4. **No auto-fine-tune.** Curriculum / fine-tune export is Pro-only and gated behind `manage_options` + human review (out of scope for the base subsystem).

---

## Testing

```bash
# Run the harness test suites:
vendor/bin/phpunit tests/test-harness-prompt-cue-library.php
vendor/bin/phpunit tests/test-harness-services.php
vendor/bin/phpunit tests/test-harness-prompt-injector.php
vendor/bin/phpunit tests/test-harness-profile-metabox.php
```

Coverage areas:

- Prompt cue library: registration, default seeding, task-class filtering, `apply()` ordering, filter override.
- Harness profile: defaults, sanitization, clamping, JSON-string acceptance, capability gating.
- PII filter: emails, phones, SSNs, API keys, custom patterns, clean passthrough.
- Reasoning trace: schema, list-growth cap, blank-entry stripping, self-consistency vote (whitespace/case normalisation, ties, empty input).
- Self-refine loop: early accept, revise-then-accept, iteration clamping, cost-ceiling abort, `WP_Error` propagation.
- Tool router: read-only beats state-changing for `qa`, assistant preferences boost score, `wp_mcp_ai_harness_tool_score` filter override.
- Retrieval: citation verification (positive, negative, empty), well-formed payload when no underlying tools.
- Prompt injector: off-by-default, prepends cues when profile enabled, skips unknown cue slugs, `wp_mcp_ai_harness_inject_cue_slugs` substitution, registration via init.
- Profile metabox: nonce-gated save, capability-gated save, checkbox-disabled-when-omitted semantics for every layer, evals_enabled / verifiers preserved on UI save, layer values clamped to hard caps, invalid router value falls back to `fixed`.

---

## What's *not* in this slice

The plan is staged. The following remain as follow-up stories:

- Layer A admin UI: shipped as `WP_MCP_AI_Metabox_Harness_Profile` on the Assistant edit screen. Surfaces the `enabled` toggle, the cue checkbox grid (sourced from the Cue Library), the per-request cost ceiling, and per-layer fieldsets for Layers B (best-of-N reasoning), C (tool router mode + preferred-tool-families matrix), D (retrieval + require-citations), E (self-refine + max iterations), F (memory scoping + task class + PII filter), and G (eval-suite selection + last-run timestamps).
- Layer C "preferred tool families" matrix: shipped as the `tools.preset_weights` map on the harness profile (preset_slug → float weight, clamped to `[-5, 5]`). Preset slugs are the keys returned by `WP_MCP_AI_Tool_Presets_Helper::get_presets()` (e.g. `agentic_workflow`, `ai_ml`, `content_writing`, `ecommerce`, `seo_marketing`, `crawling_scraping`, …). At scoring time, `WP_MCP_AI_Tool_Router_Harness::score_tool()` builds a process-cached reverse index `tool_slug → preset_slug[]` from the preset library and adds the sum of matching preset weights to the base capability-flag score. A tool that is a member of multiple weighted presets accumulates each weight (so admins can layer broad family preferences with narrower overrides). Negative weights dampen the family — useful for opting an assistant out of an entire preset without removing the underlying tools. The matrix renders inside the Tool Router fieldset under a collapsible **Preferred tool families** disclosure; weights are only meaningful when the router mode is set to **Scored**. Pro can still swap the whole scoring pipeline via the existing `wp_mcp_ai_harness_tool_score` filter, which now also receives the resolved preset weights as its 5th argument.
- Layer G profile-driven invocation: shipped as `WP_MCP_AI_Harness_Eval_Scheduler`. The scheduler walks every assistant with `harness_profile.enabled` + non-empty `evals_enabled` on a daily cron (`wp_mcp_ai_harness_eval_tick`), looks up each suite from `WP_MCP_AI_Eval_Suite_Registry`, runs it via `WP_MCP_AI_Eval_Runner` using a generator obtained from the `wp_mcp_ai_harness_eval_generator` filter, and records summaries to both the suite-scoped `WP_MCP_AI_Eval_Run_Store` (trend history, regression-detector input) and a per-assistant post meta `_wp_mcp_ai_harness_last_evals` (admin-UI "last result" column). When no generator is wired up, the run is skipped — never errored — preserving the behaviour-preserving guarantee. Sites can also call `run_suite_for_assistant( $assistant_id, $suite_slug )` directly for one-off runs from a dashboard button or WP-CLI command.
- Layer H curriculum export (Pro): shipped as `WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum` (Pro). The tool walks the assistant's `harness_profile.evals_enabled` (or an explicit `suite_slugs` argument), fetches each registered `WP_MCP_AI_Eval_Suite`, and emits one OpenAI-chat-format JSONL row per eval case (`{"messages":[system, user input, assistant expected]}`). Non-string inputs/expecteds are JSON-encoded. Supports `dry_run` (counts only), `max_cases` cap (hard ceiling 5000), per-case character cap (filter `wp_mcp_ai_pro_curriculum_per_case_char_cap`, default 16 000), and writes to `wp-content/uploads/mcp-ai/harness-curriculum/` with `.htaccess`/`index.php` guards. Registered via the `wp_mcp_ai_pro_tools` filter from `addons/pro/includes/harness-init.php`.
- The "Harness Lift" report card (Pro leaderboard widget).

These follow-ups can be implemented without changing the public surface of the base layers shipped here.
