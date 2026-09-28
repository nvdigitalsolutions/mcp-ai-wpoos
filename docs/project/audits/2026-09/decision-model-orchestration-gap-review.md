# Decision-Model Orchestration Gap Review — Base + Pro Router & Orchestration Layers

**Status:** 📝 Review — for discussion
**Date:** 2026-09-28
**Scope:** `lib/core` (`ProviderRouter`, `ChatOrchestrator`), base `includes/` (`Language_Model_Router`, `Orchestration_Depth_Scheduler`, chat services), Pro addon (Jev classifier, dispatcher, guardrails)
**Method:** Codebase review of the routing/orchestration layers + external research (TypeSafe official docs/cookbooks, RouteLLM/RouterArena/LLMRouterBench, 2026 routing surveys, Jev community integrations incl. the awesome-jev list)
**Related:** Proposals [039](../../proposals/039-typesafe-jev-decision-provider.md), [040](../../proposals/040-typesafe-jev-enhancements.md) and its [implementation plan](../../proposals/040-typesafe-jev-enhancements-implementation-plan.md)

---

## 1. Summary

Since v1.1.83 the plugin has a first-class **decision provider** (TypeSafe Jev) with a
native client, an OpenRouter Decisions bridge, four decision tools
(`typesafe_decide`, `typesafe_guardrail`, Pro `typesafe_rerank` / `typesafe_eval` /
`typesafe_skill_select`), and several Pro seams (guest-chat guardrail, research source
filter, advisory `jev_routing` on the model-comparison dispatcher).

However, **every Jev integration is advisory, opt-in, and out-of-band** — the actual
hot paths that decide *which provider/model answers a chat request, and how deep the
orchestration goes*, are still 100% deterministic:

- `lib/core` `ProviderRouter` — explicit option → assistant config → site default,
  plus reactive health-tracker failover. No semantic signal, no tiers.
- Base `WP_MCP_AI_Language_Model_Router::route_with_tier()` + `WP_MCP_AI_Orchestration_Depth_Scheduler`
  — draft/verification tiers from hard-coded capacity thresholds and a caller-supplied
  `confidence` float (defaults to a neutral `0.65`). No semantic input.
- `lib/core` `ChatOrchestrator` — agentic loop gated by iteration count, policy events,
  and retry counts. No decision-model gates on tool calls, loop progress, or completion.

The Pro `WP_MCP_AI_Pro_Jev_Classifier::classify_prompt()` already computes exactly the
signal these layers are missing (`task_type` choice, `complexity` score 0–2,
`needs_frontier` noul) — but it is used only as an advisory pre-step on the
*model-comparison* dispatcher, explicitly documented as "callers decide what to do with
the decision… never routes anything itself."

Industry research (Section 3) shows this is the opposite of the state of the art:
the highest-value, officially-documented decision-model patterns are precisely
**pre-request routing**, **confidence-gated escalation**, and **verify-then-escalate
cascades** — the layers this plugin left deterministic.

**Recommendation:** wire the existing Jev classifier into the tier-routing and
verification paths (G1, G5 below) as opt-in, fail-open, code-thresholded enhancements,
and add the calibration/shadow governance layer (G6, G7) before any wider routing.

---

## 2. Current-State Map (verified in code)

| Layer | File | Decision logic today | Decision-model touchpoints |
|---|---|---|---|
| Core provider router | `lib/core/src/Application/Provider/ProviderRouter.php` | `resolveForChat()`: `options.provider` → `assistantConfig.provider` → `settings.getDefaultProvider()`; slug aliases; reactive `attemptFailover()` via `ProviderHealthTracker` | None |
| Legacy provider router (active non-OOS path) | `includes/class-wp-mcp-ai-language-model-router.php` | Explicit provider → static `provider_priority_list` fallback; `route_with_tier()` resolves draft/verification tiers; `wp_mcp_ai_route_to_provider` / `wp_mcp_ai_tiered_model_selection` filters | `set_depth_scheduler()` (deterministic) |
| Depth scheduler | `includes/services/class-wp-mcp-ai-orchestration-depth-scheduler.php` | Capacity utilisation (0–100) × `confidence` float against hard-coded thresholds (70/40/15, 0.8/0.6/0.4); neutral confidence default 0.65 | `wp_mcp_ai_orchestration_depth_tier` filter |
| Agentic loop | `lib/core/src/Application/Chat/ChatOrchestrator.php` | `handleChat()`: rate limit → semantic compression → `BeforeChatRequest` / `applyPreStepPolicy` / `applyRequestPolicy` events → bounded retry (`MAX_REQUEST_RETRIES = 3`) → tool loop (`BeforeToolExecution` / `AfterToolExecution` events) → up to `max_agentic_iterations` (15) | Events only; no decision listeners shipped |
| Decision client (base) | `includes/class-wp-mcp-ai-typesafe-client.php` (+ OpenRouter decisions bridge) | Native `POST /v1/systemone`; typed choice/score/noul; 429/`retry-after`; advisory cache (`enable_typesafe_cache`) | `typesafe_decide`, `typesafe_guardrail` tools |
| Pro classifier | `addons/pro/includes/services/class-wp-mcp-ai-pro-jev-classifier.php` | `classify_prompt()` → `task_type` (7-way choice), `complexity` (0–2 score), `needs_frontier` (noul); `filter_sources_by_relevance()`; fail-open, kill-switch `wp_mcp_ai_jev_classifier_enabled` | Consumed only by `WP_MCP_AI_Pro_Parallel_Model_Dispatcher` (`jev_routing`, advisory) |
| Pro guardrails/evals | `addons/pro/includes/tools/jev/*`, guest-chat guardrail | pass/review/block hazard screening (input), `typesafe_rerank`, `typesafe_eval`, `typesafe_skill_select` | Tools are `manage_options`-gated; not wired into the chat loop |

Deferred by plan 040: extraction tools (the SDE-cascade pattern), the CG port cluster,
NV Cloud passthrough.

---

## 3. Industry Research Synthesis

### 3.1 LLM routing standards

- **Router taxonomy** (2026 arXiv survey on dynamic routing/cascades, via
  [Digital Applied](https://www.digitalapplied.com/blog/llm-model-routing-2026-cost-quality-optimization-engineering-guide)):
  decisions are classified by **when** (pre-request rules / at-inference cascade /
  post-response retry), **what** feeds them (query features, model metadata, past
  performance), and **how** (rules, classifiers, RL, cascades). Production practice is
  **layered**: cheap rule pass for the obvious → classifier for the ambiguous middle →
  cascade for the tail.
- **RouteLLM** (ICLR 2025, LMSYS): matrix-factorization/BERT routers hit **85% cost
  savings at 95% of GPT-4 quality** on MT Bench, sending only 14% of queries to the
  strong model. Two properties transfer to this codebase: (a) routers trained on one
  model pair **transfer** when models are swapped, so no per-model-pair retraining; (b)
  the failure mode everyone skips is **silent quality regression** — the mandated
  mitigation is a **pre-merge/continuous eval gate** (50–500 representative cases)
  before widening the cheap-model share.
- **Router overhead budget**: rules <1 ms, embeddings ~5 ms, semantic/ML classifiers
  50–100 ms, against 500–2,000 ms inference. An *LLM* used as the router adds a full
  inference round-trip and should be reserved for genuinely hard decisions — a
  decision model such as Jev (~150–320 ms, ~$0.03/1k routed queries in the
  [TokenTrim RouterArena experiment](https://github.com/TokenTrim/jev-routing-experiment))
  sits in the sweet spot between rules and LLM-judges.
- **Jev-as-router field evidence** (TokenTrim, RouterArena/LLMRouterBench): an explicit
  **difficulty rubric** beats bare capability labels (69.5% vs 67.1% routed accuracy);
  honest ablation showed retrieved neighbour-evidence dominated Jev's difficulty signal
  on LLMRouterBench; and **routing only pays off when no single model dominates the
  pool** — an important argument for keeping explicit per-assistant model config the
  default here.

### 3.2 TypeSafe official patterns (docs.typesafe.ai)

| Pattern | What it is | Relevance to this codebase |
|---|---|---|
| **Confidence-gated routing** | The answer says *what*; confidence says *whether to act*. Per-action thresholds scaled to the stakes (low-stakes act at ≥0.6; high-stakes act only >0.85, else confirm) | The missing consumer for `classify_prompt()`'s confidence; maps directly onto tier selection |
| **Intent routing** | One call classifies intent + complexity → route to deterministic handler / specialist LLM / human | Maps onto assistant/provider/tier selection |
| **SDE cascade** | Cheap model extracts → per-field Noul battery verifies ("bad = true", explicit true/false criteria, per-field `max` aggregation) → escalate to strong model only when any flag fires; the cascade's Pareto frontier sits **above and left of every single model** | The strongest match for the existing draft/verification tier machinery (deferred in plan 040 as "extraction tools") |
| **Speculative fan-out** | Batch many questions in one call; batching 13 questions is ~12× cheaper, ~10× faster | Already partially implemented via `typesafe_decide` multi-question state |
| **Composite scoring** | Atomic scores combined with code-owned weights | Already shipped (`weights` arg on `typesafe_decide`) |
| **LLM guardrails** | One request screens input for hazard categories → pass/review/block thresholds | Shipped as `typesafe_guardrail` + guest-chat seam; the **output/tool-call side is not wired** |
| **Skill suggestion** | Rank + re-check over a catalog in two requests | Shipped as `typesafe_skill_select` (admin-gated); not loop-wired |
| **Calibration guidance** | Confidence = concentration, **not** correctness; set three bands (act / review / human) from **your own labeled data**; treat ~0.5 as "no answer" | No per-site threshold store or eval-gate harness exists yet |

### 3.3 Community agent-loop patterns (awesome-jev)

The strongest community integrations enhance *agent orchestration*, not just routing:

- **Tool-call gates** — one Noul per candidate tool call (risk: irreversible?,
  authorized?); code maps probabilities to allow/ask/deny and **fails closed** only via
  deterministic rules (toolgate, pi-jev-auto-mode, jev-engineering).
- **Loop stagnation** — Jev judges trajectory progress; code returns
  CONTINUE / WARN / REPLAN / HALT (ProgressGate). This codebase hard-stops at 15
  iterations with no semantic check.
- **Done-verification** — before trusting a "task complete" claim, verify against the
  transcript only when files changed with no passing check (jev-belay).
- **Compaction decisions** — Jev scores which tool results are stale and can be dropped
  instead of summarized (fast-jev-compaction).
- **Per-turn model routing** — Jev picks model + reasoning effort from session state;
  deterministic code filters candidates and owns every threshold first (Jevonian,
  jev-codex-router).

### 3.4 Cross-cutting principles

1. **Deterministic rules first, then decision model, then LLM.** "A regex or database
   lookup beats any model" (TypeSafe docs) — the decision model should only see the
   cases rules cannot decide.
2. **The decision model supplies the signal; code owns the gate.** Thresholds,
   allowlists, and fail-open paths stay in PHP; this is exactly how the existing Jev
   seams already behave.
3. **Fail open.** A decision-model outage must never block chat (existing plugin
   convention; every shipped seam already does this).
4. **Adversarial state + privacy.** The decision model only sees what the caller
   passes; sending user text to a third-party decision API must stay opt-in and
   minimal (first user message, no secrets/tool results).
5. **Calibration over vibes.** Bands, not exact thresholds; calibrate on labeled site
   traffic before widening any automatic routing.

---

## 4. Gap / Opportunity Register

Severity: **P0** = highest value with lowest new surface · **P1** = high value ·
**P2** = governance/hardening · **P3** = optional.

### G1 — Tier routing has no semantic complexity signal · P0

- **Layer:** `WP_MCP_AI_Language_Model_Router::route_with_tier()` +
  `WP_MCP_AI_Orchestration_Depth_Scheduler`.
- **Current:** draft/verification tier from capacity × a caller-supplied `confidence`
  float (neutral default 0.65). The only semantic input is a `task_type` string.
- **Evidence:** confidence-gated routing + intent-routing patterns; Jev difficulty
  rubric beat capability labels in RouterArena; RouteLLM cost-quality curve.
- **Opportunity:** opt-in `enable_jev_tier_routing` — run
  `WP_MCP_AI_Pro_Jev_Classifier::classify_prompt()` on the first user message and feed
  `complexity`/`needs_frontier` as the confidence signal into
  `route_with_tier()` / `determine_tier()`. Code keeps the existing hard thresholds;
  decision cache (already shipped) absorbs repeat costs; fail-open = today's behavior.
- **Seams:** `wp_mcp_ai_tiered_model_selection` filter; `set_depth_scheduler()`.
- **Guard:** never overrides an explicit `options['provider']`/`model`; assistant-level
  opt-in first, site-wide later.

### G2 — `lib/core` ProviderRouter has no tier/cost-aware strategy · P3

- **Layer:** `Nvoos\Core\Application\Provider\ProviderRouter`.
- **Current:** single provider per request; reactive failover only.
- **Evidence:** cheapest-capable-model routing (RouteLLM/LiteLLM/Vercel/Azure
  strategies); the RouterArena caveat that routing pays only when no single model
  dominates the pool.
- **Opportunity:** add an optional **router strategy** (strategy object on the router:
  `StaticStrategy` today, `TieredStrategy` opt-in) behind the same `resolveForChat()`
  contract. Because `lib/core` is framework-agnostic, the decision client would arrive
  as a `DecisionClientInterface` adapter (mirroring `Interface_WP_MCP_AI_Decision_Client`),
  which also benefits `nvoos-content-graph-ai` (it runs `ChatOrchestrator` only).
- **Guard:** default strategy unchanged; no behavior change without opt-in.

### G3 — Agentic loop lacks a tool-call risk gate · P1

- **Layer:** `ChatOrchestrator` (`BeforeToolExecution` event) and/or legacy
  `wp_mcp_ai_before_tool_execution` (destructive-ops gate).
- **Current:** the destructive-ops gate is confirmation/allowlist-based; the guest-chat
  guardrail screens **input** only.
- **Evidence:** toolgate / pi-jev-auto-mode / jev-engineering — one Noul per candidate
  tool call (irreversible? within scope? authorized?), code maps to allow/ask/deny.
- **Opportunity:** optional per-assistant Jev tool-call gate for high-stakes slugs
  (state-changing, outbound, credential-adjacent), **advisory on top of** the existing
  deterministic gate — Jev can raise the bar (ask/review) but never be the sole
  authorizer. Batched fan-out keeps it one ~150–300 ms call per tool round.
- **Guard:** fails open; only for assistants that opt in; never gate on Jev alone.

### G4 — Loop termination is iteration-count only · P3

- **Layer:** `ChatOrchestrator` max-iterations loop.
- **Evidence:** ProgressGate (CONTINUE / WARN / REPLAN / HALT from trajectory
  judgment).
- **Opportunity:** optional semantic stagnation check every N iterations (repeating
  tool calls, no observable progress) → earlier halt with a diagnosable reason; emits
  through the existing event/policy seams.
- **Guard:** advisory only — code keeps the hard iteration cap.

### G5 — No verify-then-escalate cascade for cheap-model outputs · P0

- **Layer:** draft/verification tiers + research/report tool paths.
- **Current:** tier selection exists but nothing verifies a draft-tier answer before it
  ships; `check_citations()` (Pro, advisory) is the only output-side verifier, and only
  for research tools.
- **Evidence:** the SDE cascade is the officially-documented pattern whose Pareto
  frontier beats **every single model** (cheap extract → per-field Noul battery with
  "bad = true" framing and per-field `max` aggregation → escalate only when a flag
  fires). This is the deferred "extraction tools" item from plan 040.
- **Opportunity:** implement the cascade for the highest-value bounded outputs first —
  structured extraction (CRM/medical-record toolkits, the plan-040 stretch) and
  research reports (extend the existing `citation_checks` into a fuller per-claim
  battery). Generalize as a reusable `verify_then_escalate` service with the Noul
  battery builder as a shared helper.
- **Guard:** escalation is bounded (one verification rung); verifier failures accept
  the draft answer (fail-open); `typesafe_eval` measures the separation before
  thresholds ship.

### G6 — No on-traffic calibration or threshold store · P2

- **Layer:** hard-coded thresholds in the depth scheduler, guardrail, and (future)
  routing gates.
- **Evidence:** TypeSafe confidence guidance (three bands from **your own labeled
  data**); jevcal / Jev DSPy Lab calibrate per-question thresholds with held-out
  verification; the Digital Applied eval-gate mandate (50–500 cases pre-merge).
- **Opportunity:** a per-site **decision threshold store** (option-backed, filterable)
  + an eval-gate report built on the existing `typesafe_eval` service — accuracy by
  confidence bucket, and a CI-style gate for any routing change. This is the layer the
  industry says makes routing safe rather than a gamble.
- **Guard:** Pro-only, opt-in, no telemetry exfiltration.

### G7 — No shadow mode for routing decisions · P2

- **Layer:** all routing paths.
- **Evidence:** shadow-mode rollout is the standard safe path (tripwire,
  jev-harness, Jevonian decision recording; the plugin already has an OOS shadow
  runner pattern in `includes/oos/`).
- **Opportunity:** opt-in `jev_routing_shadow` — record every routing decision
  (chosen route, confidence, would-have-changed-outcome) without applying it; the
  recorded stream feeds G6's calibration harness. Reuses the existing opt-in logging
  gates.
- **Guard:** decisions recorded, never applied; logging redaction rules apply.

### G8 — Skill/compaction/rerank decisions are tools, not loop wiring · P3

- **Layer:** `ChatOrchestrator` tool loop; bundled skill registry.
- **Current:** `typesafe_skill_select`, `typesafe_rerank`, and compaction patterns
  exist as `manage_options`-gated tools.
- **Evidence:** skill-suggestion cookbook (rank + re-check); fast-jev-compaction;
  langchain-skill-router.
- **Opportunity:** optional loop policies that (a) suggest a skill for the next turn,
  (b) drop stale tool results instead of summarizing. Highest latency-per-turn cost of
  all gaps — justify per assistant.
- **Guard:** opt-in; failures fall back to the current loop.

---

## 5. Recommended Sequencing

| Phase | Items | Rationale |
|---|---|---|
| **Phase A** | G1 (tier signal) + G5 (verify-then-escalate service) | Reuses the shipped classifier and tier machinery; the two officially-documented patterns with the largest cost/quality payoff; all opt-in and fail-open |
| **Phase B** | G3 (tool-call gate) | Safety-driven; follows the shipped guest-guardrail precedent |
| **Phase C** | G6 (threshold store + eval gate) + G7 (shadow mode) | The governance layer that must exist **before** any routing is widened or made site-wide |
| **Phase D** | G2 (core router strategy) | Only after A–C prove the signals; also unlocks CG-AI |
| **Phase E** | G4 (stagnation) + G8 (loop wiring) | Long tail; per-assistant opt-in only |

A Phase A implementation would follow the proposal-040 pattern: a numbered proposal +
implementation plan, base decision-client reuse, Pro routing wiring behind
`enable_jev_tier_routing`, two-gate sanitisation on every new surface, new PHPUnit
suites per seam (every seam fails open), and coverage-manifest entries.

## 6. Risks & Constraints

| Risk | Mitigation |
|---|---|
| Decision-model outage blocks chat | Every new seam fails open to today's deterministic behavior (existing convention) |
| Privacy: user text sent to a third-party decision API | Opt-in, per-assistant; minimal state (first user message only); never secrets/tool results; existing adversarial-state caveat applies |
| Confidence ≠ correctness | Bands not exact values; conservative thresholds; never the sole authorizer for state-changing operations |
| Silent quality regression from wider routing | Eval gate (G6) + shadow mode (G7) before any site-wide default change |
| Latency (~150–320 ms per decision) | Only where the decision changes the outcome; advisory decision cache (shipped) absorbs repeats; speculative fan-out keeps it one call |
| Jev is a closed, hosted model | Keep the transport abstraction (native + OpenRouter bridge); deterministic rules always run first; `wp_mcp_ai_jev_classifier_enabled` kill-switch |
| Non-English accuracy (TypeSafe documents English-first) | Keep the existing caveats; conservative thresholds for non-English traffic |
| Routing pays only when no single model dominates the pool | Explicit per-assistant provider/model config remains the default; auto-routing stays opt-in |

## 7. Sources

- [TypeSafe docs — Patterns](https://docs.typesafe.ai/patterns.md): speculative fan-out, confidence-gated routing, composite scoring, intent routing
- [TypeSafe — Confidence-gated routing](https://docs.typesafe.ai/patterns/confidence-routing.md)
- [TypeSafe — Intent routing](https://docs.typesafe.ai/patterns/intent-routing.md)
- [TypeSafe — SDE cascade cookbook](https://docs.typesafe.ai/cookbooks/sde_cascade.md)
- [TypeSafe — LLM guardrails cookbook](https://docs.typesafe.ai/cookbooks/llm_guardrails.md)
- [TypeSafe — Confidence](https://docs.typesafe.ai/confidence.md)
- [OpenRouter — What is Jev](https://openrouter.ai/blog/insights/what-is-jev/)
- [Digital Applied — LLM Model Routing in 2026](https://www.digitalapplied.com/blog/llm-model-routing-2026-cost-quality-optimization-engineering-guide) (RouteLLM ICLR 2025 results, router taxonomy, eval-gate mandate, overhead budgets)
- [TokenTrim — Jev as an LLM router on RouterArena](https://github.com/TokenTrim/jev-routing-experiment) (difficulty rubric, honest ablation, cost/latency measurements)
- [Awesome Jev](https://github.com/AnotiaWang/awesome-jev) (toolgate, ProgressGate, jev-belay, fast-jev-compaction, Jevonian, jev-engineering, jev-harness, jevcal)
- In-repo: `docs/project/proposals/039-typesafe-jev-decision-provider.md`, `040-typesafe-jev-enhancements.md` (+ implementation plan), `.context/security-checklist.md` (v1.1.84 Jev gates), `CLAUDE.md` (Jev provider + enhancement-wave sections)
