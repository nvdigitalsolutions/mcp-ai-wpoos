# Proposal 045 — Decision-Model Orchestration Phase A: Semantic Tier Routing & Verify-Then-Escalate Cascade

**Status:** 🚧 Implemented (Phases 0–2) — PR pending on branch `add/jev-decision-orchestration-phase-a`
**Date:** 2026-09-28
**Scope:** Base plugin (`includes/`) + Pro addon (`addons/pro/`)
**Related:** Gap review [`../audits/2026-09/decision-model-orchestration-gap-review.md`](../audits/2026-09/decision-model-orchestration-gap-review.md); [`039-typesafe-jev-decision-provider.md`](./039-typesafe-jev-decision-provider.md) (implemented v1.1.83, PR #6728); [`040-typesafe-jev-enhancements.md`](./040-typesafe-jev-enhancements.md) (+ plan, largely shipped v1.1.84, PRs #6745–#6747); implementation plan: [`045-jev-decision-orchestration-phase-a-implementation-plan.md`](./045-jev-decision-orchestration-phase-a-implementation-plan.md)

## Summary

Proposals 039/040 made TypeSafe Jev a first-class **decision provider** — native
client, OpenRouter Decisions bridge, `typesafe_decide` / `typesafe_guardrail` /
`typesafe_rerank` / `typesafe_eval` / `typesafe_skill_select` tools, and Pro seams
(guest-chat guardrail, research source filter, advisory dispatcher routing). A
review of the router and orchestration layers against TypeSafe's official patterns
and the 2026 routing literature (see the linked gap review) found that **every Jev
integration is advisory and out-of-band** — the hot paths that decide *which
provider/model answers a request and how deeply it is verified* remain fully
deterministic.

This proposal implements **Phase A** of the review's sequencing: the two highest-value,
lowest-new-surface gaps.

1. **G1 — semantic tier routing signal.** Wire the existing
   `WP_MCP_AI_Pro_Jev_Classifier::classify_prompt()` output (task type, complexity
   score, frontier need) into `WP_MCP_AI_Language_Model_Router::route_with_tier()`
   and `WP_MCP_AI_Tool_Execution_Orchestrator::execute_with_depth()`, replacing the
   caller-supplied/zero `confidence` float with a Jev-derived signal. Code keeps
   every threshold; Jev only supplies the signal; opt-in
   (`enable_jev_tier_routing`, default off) and fail-open.
2. **G5 — verify-then-escalate cascade.** Ship a base verification-cascade service
   implementing TypeSafe's officially documented **SDE cascade** pattern (cheap
   model produces → per-field Noul battery verifies with "bad = true" framing and
   per-field `max` aggregation → escalate to the verification tier only when a flag
   fires), with two Pro consumers: per-claim verification in the research/report
   tools (extending the existing `check_citations()` seam) and — as stretch — a
   structured-extraction cascade for the CRM/medical toolkits (the deferred
   "extraction tools" item from plan 040).

Everything is additive, opt-in, fail-open, and obeys the existing conventions
(canonical envelope, two-gate sanitisation, decision cache, `manage_options` gates,
coverage manifest).

## Motivation / Gap Analysis

The review (audit `2026-09/decision-model-orchestration-gap-review.md`) established:

- `lib/core` `ProviderRouter` resolves providers purely deterministically (explicit
  → assistant config → site default) with reactive health failover only.
- Base `WP_MCP_AI_Language_Model_Router::route_with_tier()` selects draft vs
  verification models from hard-coded capacity thresholds and a **caller-supplied
  `confidence` float** that defaults to neutral (`0.65`) — no semantic input ever
  reaches it. `WP_MCP_AI_Tool_Execution_Orchestrator::execute_with_depth()` reads
  `$context['confidence']`, which is `0.0` unless a caller sets it, silently pushing
  orchestration toward the deep/verification tier.
- `WP_MCP_AI_Pro_Jev_Classifier::classify_prompt()` already computes exactly the
  missing signal — `task_type` (7-way choice), `complexity` (0–2 score),
  `needs_frontier` (noul) — but its only consumer is the *model-comparison*
  dispatcher, where it is explicitly advisory.
- Nothing verifies a draft-tier answer before it ships. The only output-side
  verifier is Pro's advisory `check_citations()` for research tools. TypeSafe's
  SDE-cascade cookbook shows the extract → verify → escalate loop's Pareto frontier
  sits **above and left of every single model** (mini + verifier beats the strong
  model alone on cost at equal or better quality) — and this is the one pattern plan
  040 explicitly deferred.

Industry evidence supporting these two moves (full sources in the review):

- **RouteLLM** (ICLR 2025): cost-quality routing saved 85% at 95% of GPT-4 quality;
  routers transfer across model swaps; the dominant failure mode is *silent quality
  regression*, mitigated by conservative rollout + eval gates (deferred to Phase C of
  the review — out of scope here).
- **TypeSafe confidence-gated routing / intent routing**: the decision model
  supplies the answer *and* confidence; **code owns every threshold**, scaled to the
  stakes of the decision.
- **Jev-as-router field data** (TokenTrim, RouterArena): an explicit difficulty
  rubric improved routed accuracy 67.1% → 69.5% while lowering cost; median decision
  ~311 ms, ~$0.032/1k routed queries — within the 50–100 ms-class routing budget
  against 500–2,000 ms inference.
- **SDE cascade**: per-field Noul questions framed so `true` = escalate, explicit
  `criteria.true/false`, aggregate with `max` ("any flag fires") — one confident red
  flag escalates instead of being averaged into silence.

## Design

Two workstreams, all inside existing seams — no new provider surface, no change to
chat-provider selection, no schema break to any shipped tool.

### Workstream 1 — Semantic tier routing signal (G1, Pro)

- New helper `WP_MCP_AI_Pro_Jev_Classifier::routing_signal_for( $messages, $options )`
  returning a code-owned routing signal: `{ task_type, complexity, needs_frontier,
  confidence }` where `confidence = (2 - complexity) / 2` (complexity 0 → 1.0,
  complexity 2 → 0.0). If the complexity answer's own confidence is `< 0.5`, or Jev
  is unavailable/errored, the helper returns the neutral `0.65` default — the
  decision model's uncertainty must not steer the router.
- Opt-in setting `enable_jev_tier_routing` (TypeSafe subtab, default off). When
  enabled, the signal is used in two places:
  1. `route_with_tier()` callers (via a small Pro helper wrapping the existing
     `wp_mcp_ai_tiered_model_selection` filter contract) pass the Jev `confidence`
     and `task_type` when the tier is `auto`.
  2. `execute_with_depth()` populates `$context['confidence']` from the same signal
     when the caller did not supply one.
- **Jev never overrides an explicit `options['provider']` / `options['model']` or a
  caller-supplied tier** — it only fills the `auto` signal. Fail-open: any error ⇒
  today's deterministic behavior. The shipped advisory decision cache absorbs
  repeated identical classifications.

### Workstream 2 — Verify-then-escalate cascade (G5, Base service + Pro consumers)

- **Base** service `WP_MCP_AI_Verification_Cascade` (`includes/services/`) with a
  shared **Noul battery builder**: given a structured record (fields with specs and
  source text), it programmatically decomposes one noul question per field-metric
  (hallucinated / off_target / incomplete / format_violation …), all framed
  "bad = true" with explicit true/false criteria, batched into **one** decision call
  (speculative fan-out). The service aggregates with `max` against a filterable
  threshold (`wp_mcp_ai_cascade_escalate_threshold`, default `0.7`) and invokes an
  escalation callback (bounded to one rung) only when a flag fires.
- **Pro consumer 1 (this proposal):** research/report per-claim cascade — extend the
  existing `check_citations()` advisory seam into a full per-claim battery when
  `enable_jev_citation_escalation` is on (default off); claims whose flags fire are
  re-generated on the verification-tier model via the existing
  `get_verification_model_for_provider()` machinery. One escalation rung maximum;
  verifier failure accepts the draft output (fail-open).
- **Pro consumer 2 (stretch, toolkit-owner sign-off):** structured-extraction
  cascade for CRM/medical toolkits — the plan-040 deferred item
  (`typesafe_extract_dates` + pre-parsed value extraction): draft-tier extraction,
  Noul battery verification against the source document, escalation to the
  verification tier on any flag.

## Testing

- Pro classifier: `routing_signal_for()` returns bounded values; complexity
  confidence `< 0.5` falls back to neutral; unavailable Jev ⇒ neutral; no network
  call when the setting is off.
- Router/orchestrator: `route_with_tier()` honours a supplied confidence and the
  filter contract; `execute_with_depth()` uses the Jev confidence when enabled and
  keeps today's `0.0` behavior when disabled; explicit provider/model/tier always
  win.
- Cascade service: battery builder emits one noul per field-metric with "bad = true"
  criteria; `max` aggregation escalates on a single firing flag; escalation callback
  invoked exactly once; verifier error accepts the draft; escalation bounded to one
  rung (second failure returns the draft, never loops).
- Research tools: opt-in citation escalation fires only above threshold and fails
  open; envelopes unchanged when the setting is off.
- Every seam fails open (mirrors the existing `test-pro-jev-*` conventions); two-gate
  sanitisation assertions on the battery state; coverage manifest + `tool-status.txt`
  entries for any new tool slugs.

## Risks & Mitigations

| Risk | Mitigation |
|---|---|
| Jev outage or misclassification degrades routing | Fail-open to today's deterministic defaults; neutral-confidence fallback below a complexity-confidence floor; kill-switch `wp_mcp_ai_jev_classifier_enabled` reused |
| Privacy: user text sent to a third-party decision API for routing | Opt-in per site; state = first user message only (never tool results, secrets, or full transcripts); existing adversarial-state caveat applies |
| Silent quality regression from cheaper tiers | Both workstreams ship **off by default**; Phase C of the review (eval gate + shadow mode) is the precondition for any site-wide default change |
| Escalation loops / cost amplification | Hard cap of one verification rung; escalation only when a flag fires; advisory decision cache absorbs repeat classifications |
| Confidence ≠ correctness | Code keeps every threshold; the Jev signal only *feeds* `route_with_tier`/`determine_tier`, it never picks a model directly |
| Jev is a closed hosted model | Transport abstraction retained (native + OpenRouter bridge); deterministic rules always run first |
| Non-English accuracy | Conservative thresholds; existing caveats retained on tool descriptions |

## Sources

- Gap review (in-repo): `docs/project/audits/2026-09/decision-model-orchestration-gap-review.md`
- [TypeSafe — Confidence-gated routing](https://docs.typesafe.ai/patterns/confidence-routing.md), [Intent routing](https://docs.typesafe.ai/patterns/intent-routing.md), [SDE cascade cookbook](https://docs.typesafe.ai/cookbooks/sde_cascade.md), [Parallel questions](https://docs.typesafe.ai/cookbooks/parallel_questions.md), [Confidence](https://docs.typesafe.ai/confidence.md)
- [Digital Applied — LLM Model Routing in 2026](https://www.digitalapplied.com/blog/llm-model-routing-2026-cost-quality-optimization-engineering-guide) (RouteLLM results, router taxonomy, overhead budgets, eval-gate mandate)
- [TokenTrim — Jev as an LLM router on RouterArena](https://github.com/TokenTrim/jev-routing-experiment)
- In-repo: proposals 039/040 (+ implementation plans), `.context/security-checklist.md` (v1.1.84 Jev gates)
