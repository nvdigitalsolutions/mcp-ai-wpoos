# 090-k — Harness port to Laravel: research, plan, and execution loop

**Status:** plan written 2026-11-15. Clusters are executed one at a time, each as its own mergeable PR, lowest dependency first.
**Scope:** the seven harness classes still WordPress-only — retrieval, citation verification, reasoning traces, self-refine, tool routing, the necessity gate, and the search engine.
**Non-goal:** the artifact/evolution cluster (10 classes) and the Pro harness. Those are a research subsystem, not admin-surface parity; they stay WordPress-only until there is a product reason and a measurement tree to run them.

---

## 1. Why this order, and what "port" means here

### 1.1 The porting rule

Every cluster follows the pattern already established by the posture scorer, the AI-safety filter, and the harness profile:

1. The **logic moves to `lib/core`** — framework-neutral, no `get_option`, no post meta, no `WP_Error`.
2. The **WordPress class delegates** to it behind a `class_exists` guard, keeping its public API and its translation/formatting. `lib/` is stripped from wp.org base builds, so the local implementation stays as the self-contained fallback.
3. The **runtime supplies only storage and I/O** through a narrow contract.
4. The port is **proven equivalent** before tests are written where the WP behaviour is subtle: run both implementations side by side over a shared corpus (the technique used for `HarnessProfile`, which caught nothing but proved the sanitiser was identical across nine cases).
5. **No behaviour change in WordPress** unless it is a separately reviewed fix. Port faithful, then fix deliberately.

### 1.2 Why analysis and presentation are split

The WordPress verifier embeds `__( '…' )` and `wp_trim_words()` inside its scoring loop. Moving that code wholesale into `lib/core` would either (a) drag WordPress translation into a framework-neutral package, or (b) drop localisation for non-English sites.

So the core classes return **structured results** and each runtime renders them. WordPress keeps its current strings byte-for-byte; Laravel renders its own. This is the same separation the SPA already relies on (`posture-utils` parses, the page renders).

---

## 2. Industry grounding

| Standard / practice | What it contributes to this plan |
|---|---|
| **OWASP Top 10 for LLM Applications — LLM09:2025 Misinformation** | Hallucinated citations and fabricated sources are an *application-layer security risk*, not just a quality issue. Verification must be visible to the user ("which claim is unsupported"), not a silent score. |
| **OWASP LLM06:2025 Excessive Agency** | Tool routing and the necessity gate are least-privilege controls. Scopes and presets must be explicit and deny-by-default. |
| **OWASP LLM01:2025 Prompt Injection** | Retrieved passages are untrusted input. Provenance travels with the passage so downstream filtering can tell document text from instructions. |
| **NIST AI RMF + AI 600-1 Generative AI Profile** | Measurements must be reproducible and attributable: a trace needs a run id, a model, a cost, and a policy outcome to be auditable. That is the reasoning-trace cluster. |
| **Grounding-as-a-pipeline** (evidence gate → native citations → claim-level verification → evidence in the UI) | Fixes the cluster order: retrieval produces evidence, then verification judges it, then traces record it. Verifying before retrieving is meaningless. |
| **Post-hoc attribution vs cite-as-you-generate** | The existing WordPress design is post-hoc. Keeping that is correct for parity; a native-citation mode is a separate, larger change. |
| **Span grounding / verbatim-quote checks** | Informs how the verifier should treat a claim: the current Jaccard + entity-boost heuristic is a *lower bound*, so it over-flags rather than under-flags — the safe direction for misinformation. |
| **N-gram overlap as a conservative support predicate** | Already the `retrieval_harness::verify_citations` rule (shared 5-gram). Documented as a floor, not a claim of entailment. |
| **Self-consistency / best-of-N (Wang et al.)** | `reasoning.n_samples` is only meaningful with a vote or agreement metric; that is why self-refine and reasoning traces land together. |
| **Observability: stable attribute set + retention policy** | `trace_capture.retention_runs` (10–200) already exists in the profile; the trace store must enforce it rather than trusting callers. |

---

## 3. Cluster plan

Sizes are the WordPress source line counts; effort is relative to the harness-profile PR (~350 lines + 26 tests).

### Cluster 1 — Retrieval harness (453 LOC) · **first**

| | |
|---|---|
| **Depends on** | Nothing. The three primitives it drives — `recall_memory`, `semantic_context_search`, `retrieve_agent_memory` — already exist in `lib/core/src/Tool/`. |
| **What it does** | Tries the primitives in order, normalises the heterogeneous payloads into a passage shape, dedupes by content hash, blends `0.7 × score + 0.3 × freshness`, sorts, caps at k (hard cap 50), and returns passages + citations + recall confidence. Also `verify_citations(answer, passages)` — the conservative 5-gram support check. |
| **Core** | `Domain\Service\Harness\RetrievalHarness` + `Domain\Contract\ToolInvokerInterface` (the one seam that differs per runtime). |
| **Runtime binding** | Laravel: `Adapter\RegistryToolInvoker` over core's `ToolRegistry`. WordPress: delegation from `WP_MCP_AI_Retrieval_Harness` with an invoker over `WP_MCP_AI_Tool_Registry`. |
| **Deliberately not in scope** | Injecting passages into the prompt. That changes model input and needs a prompt-budget review — its own cluster. |
| **Gates** | Provenance present on every passage; dedupe keeps the highest score; freshness decay bounds (1 day → 1.0, 365 days → 0.0, unknown → 0.5); k clamped 1..50; a tool that throws or errors is skipped, not fatal. |

### Cluster 2 — Citation verifier (297 LOC)

| | |
|---|---|
| **Depends on** | Cluster 1 (passages) for any real use. |
| **What it does** | Extracts claim-like sentences (numbers/years/copulas, ≥20 chars, ≤20 claims), scores each against passages with Jaccard + a 0.2 entity boost, flags anything under `min_similarity`, and annotates. |
| **Core** | `Domain\Service\Harness\CitationVerifier` returning structured claims + counts; runtimes render. |
| **Known quirk to preserve** | The union in `compute_similarity` is `array_merge` of two unique sets, so the denominator is inflated by the intersection. That makes the score a strict lower bound — over-flagging, which is the safe direction for LLM09. Port faithful; a "fix" changes what WordPress warns about. |
| **Gates** | Threshold respected; ≤20 claims; first matching passage wins (not best); no annotation when nothing is unverified. |

### Cluster 3 — Reasoning trace + trace-store contract (237 LOC + store)

| | |
|---|---|
| **Depends on** | Nothing structurally, but it is the substrate for 4 and 7. |
| **Why the contract comes with it** | WordPress stores traces in post meta; Laravel has no post meta. A `TraceStoreInterface` (append + prune to `retention_runs`) is the minimum that makes traces reproducible on both runtimes, and it is what NIST-style auditability requires. |
| **Gates** | Retention enforced by the store, not the caller; trace carries run id, model, cost, and policy outcome; no PII (route text through `PiiFilter` first). |

### Cluster 4 — Self-refine loop (242 LOC)

| | |
|---|---|
| **Depends on** | Cluster 3 (trace capture) and `HarnessProfile` + `PiiFilter` (both now in core). |
| **Gates** | Hard iteration cap (`MAX_REFINE_ITERATIONS = 4`) even if the profile asks for more; cost ceiling respected; loop terminates on no-change; every iteration traced. |

### Cluster 5 — Tool router harness (413 LOC)

| | |
|---|---|
| **Depends on** | Tool capability flags and the presets helper (both WordPress-only). |
| **Why it matters** | `tools.router: scored` and `tools.preset_weights` are already sanitised by `HarnessProfile` but nothing scores with them — the clearest dangling wire in the harness. |
| **Gates** | Weight matrix clamped ±5 at read time; unknown presets inert; router falls back to `fixed` on any error; the chosen tool set is traceable. |

### Cluster 6 — Necessity gate (652 LOC)

| | |
|---|---|
| **Depends on** | Approval queue + action-safety profile (both unported). |
| **Gates** | Irreversible actions require approval by default; `auto_skip` never skips an irreversible action; every skip and every approval is auditable. |

### Cluster 7 — Harness search engine (1324 LOC)

| | |
|---|---|
| **Depends on** | Eval suite/runner/registry **and** the evolution governor — i.e. the artifact/evolution cluster this plan excludes. |
| **Status** | **Blocked by design.** It is a parameter-search loop over evals; porting it before the eval scaffolding would produce a search with nothing to search. Revisit only if model-foundry work lands on Laravel. |

---

## 4. The loop protocol

Each iteration of the loop is one cluster and must satisfy all of these before the next starts:

1. **Port with a proof.** Where the WP behaviour is non-obvious, run both implementations over a shared corpus first (throwaway harness, deleted after use) and record the result in the validation note.
2. **Tests.** Core unit tests for the logic; an app-level test for the runtime binding. No cluster ships with only one of the two.
3. **No behaviour change on WordPress** — or, if one is intended, it is called out in the PR body as a fix with its own rationale.
4. **Gates green:**
   - `lib/core` PHPUnit (full suite)
   - full Laravel app suite with the PM fixtures env
   - adapter suite (host PHP — the container's `vendor/nvoos/core` symlink is Windows-absolute)
   - `phpcs --standard=phpcs-laravel.xml.dist` over the CI-covered trees
   - `phpcs --standard=phpcs.xml.dist` on every changed WordPress file
5. **Docs.** A validation record in `090-b` plus the baseline row in the `mcp-ai-wpoos-laravel-runtime` skill.
6. **One PR per cluster**, based on `alpha-working`, titled for the cluster. Independent of its neighbours wherever the code allows, so merge order does not matter.

### Baseline to beat (2026-11-15)

| Suite | Baseline |
|---|---|
| `lib/core` | 1,432 tests / 5,213 assertions |
| Laravel app | 674 passed / 4,023 assertions |
| Adapter | 180 tests / 676 assertions (2 known PM failures) |

---

## 5. Risks and how they are handled

| Risk | Handling |
|---|---|
| **Silent behavioural drift** between the WordPress fallback and the core implementation | Delegation is `class_exists`-guarded and one-way: core is authoritative when present. The equivalence corpus is the check, not eyeballing. |
| **i18n regression** | Core returns data, runtime renders strings. WordPress keeps its exact current output. |
| **Performance** — retrieval runs before every turn | It is opt-in per assistant (`retrieval.enabled`) and k is capped; a failing or slow primitive is skipped, never fatal. The cluster lands unwired into the chat path precisely so the cost is not paid until it is asked for. |
| **Untrusted passages** (LLM01) | Provenance travels with each passage; the router/verifier never treats passage text as instructions. |
| **Scope creep into the artifact cluster** | Cluster 7 is explicitly blocked; 6 is gated on the approval queue. |
| **Stacked-PR merge races** (already observed twice: #7775 and #7778 merged before their follow-up commits) | One cluster per branch, based on `alpha-working`, no cross-cluster dependency at the file level. If a dependency is unavoidable, the child PR rebases rather than stacks. |

---

## 6. Tracker

| # | Cluster | Status |
|---|---|---|
| 1 | Retrieval harness | **done — 2026-11-15** (see §7) |
| 2 | Citation verifier | **done — 2026-11-15** (see §8) |
| 3 | Reasoning trace | **done — 2026-11-15** (see §9) |
| 4 | Self-refine loop + trace store | **done — 2026-11-15** (see §10) |
| 5 | Tool router harness | **done — 2026-11-15** (see §11) |
| 6 | Necessity gate | pending (needs approval queue) |
| 7 | Harness search engine | **blocked by design** |

---

## 7. Cluster 1 result — retrieval harness

**What shipped.** `Domain\Service\Harness\RetrievalHarness` (the pipeline: fan-out, normalisation, dedupe, 70/30 freshness blend, k clamp, citation support) plus `Domain\Contract\ToolInvokerInterface` (the one runtime seam). Laravel binds `Adapter\RegistryToolInvoker` over the core tool registry and resolves the harness from the container. WordPress's `WP_MCP_AI_Retrieval_Harness` delegates, passing its two `apply_filters` hooks as callbacks so behaviour is unchanged and the base-build fallback stays intact.

**Deliberately not wired into chat yet.** Nothing calls `retrieve()` from the orchestrator: doing so changes model input and needs a prompt-budget review. The cluster lands the capability, not the cost.

**Two upstream behaviours pinned rather than "fixed":**

- *Cross-source duplicates are not collapsed.* The fallback content hash mixes in the source slug, so the same text from two primitives stays two passages; only payloads carrying an explicit `content_hash` deduplicate. A test states this so a future change is deliberate.
- *Freshness can outweigh a small score gap.* The blend is `0.7 × score + 0.3 × freshness` over a full 0–1 range, so a fresh 0.85 outranks a year-old 0.90. A test asserts the arithmetic (`0.895` vs `0.63`) instead of the intuition.

**Validation.**

- `lib/core`: **1,424 tests / 5,216 assertions** green (was 1,406 / 5,170); `RetrievalHarnessTest` is 18 cases covering provenance, source order, a skipped failing source, hash dedupe, both freshness cases, k flooring/capping, percent-score normalisation, the three envelope shapes, unusable payloads, the aggregates, the passage-filter callback, and the citation-support predicate
- Full app suite (PM fixtures env): **672 passed / 4,019 assertions** (was 668 / 4,009) — `RetrievalHarnessWiringTest` resolves the harness and invoker from the container and asserts every source is tried and every passage carries provenance
- Adapter suite (host PHP): 180 tests / 676 assertions, the same 2 pre-existing project-tool failures
- `phpcs --standard=phpcs-laravel.xml.dist` over the CI-covered trees: clean; `phpcs --standard=phpcs.xml.dist` on the WordPress harness file: clean
- `WP_MCP_AI_Retrieval_Harness` parses and its delegation is one-way: core is authoritative when present, local implementation otherwise

---

## 8. Cluster 2 result — citation verifier

**What shipped.** `Domain\Service\Harness\CitationVerifier` returns structured results (`claims[]` with `verified`/`similarity`, plus `verified`/`unverified`/`total`). WordPress delegates and keeps its `require_citations` gate, its translated warning text and its `do_action` contract, composing the appendix from the returned claims — so the rendered output is byte-identical.

**Proven equivalent.** The real WordPress class ran twice over a six-case corpus (core hidden vs loaded) and the rendered output plus `do_action` counts were diffed. Five cases are byte-identical. The sixth is an improvement: a passage-shaped source (`['text' => …]`) makes the local path raise `strtolower(): Argument #1 must be of type string, array given`, while core reads `text` — which matters because cluster 1 returns passages.

**Two behaviours pinned by tests**, both caught by a first-draft test being wrong: identical text scores **0.5 not 1.0** (the union concatenates two unique sets, so the ratio is a strict lower bound on overlap), and a sentence needs a number, a year or a copula to be judged at all.

**Validation.** `lib/core` 1,445 tests / 5,234 assertions (13 new); app suite unchanged at 674 / 4,023 because the cluster adds no binding; phpcs clean on both standards.

---

## 9. Cluster 3 result — reasoning trace

**Scope correction.** The plan said "reasoning trace + trace-store contract". The trace schema and the vote primitive are what the WordPress class actually owns; the *store* is a separate class (`Harness_Trace_Capture`) that also owns the retention policy, and its only consumer is cluster 4's self-refine loop. Defining a `TraceStoreInterface` now would produce a contract with no consumer and a Laravel adapter with no table, so the store moved to cluster 4, where it has a caller.

**What shipped.** `Domain\Service\Harness\ReasoningTrace` — `newTrace()`, `sanitize()` (unknown keys dropped, list fields capped at 50, junk degrading to a fresh trace) and `selfConsistencyVote()` (whitespace/case-normalised majority with the agreement ratio, ties to the first candidate). WordPress delegates the three; `persist()`/`fetch()` stay on post meta because storage is a platform concern.

**Proven equivalent.** Both implementations ran over five trace corpora and six vote sets; the only differences were the file path in an `Array to string conversion` warning that both emit, and a one-second clock tick on the case that falls back to "now".

**Validation.** `lib/core` **1,484 tests / 5,320 assertions** (21 new); app suite **678 passed / 4,033 assertions** (unchanged); phpcs clean on both standards.

**No app-level test**, for the same reason as cluster 2: no binding ships here.

---

## 10. Cluster 4 result — self-refine loop + trace store

**What shipped.**

- `Domain\Service\Harness\SelfRefineLoop` — the bounded generate → critique → revise loop, with `SelfRefineException` carrying a reason code and the caller's original error object.
- `Domain\Contract\TraceStoreInterface` — `append()` + `prune()` per assistant, with the retention window enforced by the store rather than trusted from the caller.
- `Domain\Service\Harness\TracePolicy` — `isEnabled()`, `retentionRuns()` (default 50, mirroring `MAX_RUNS_PER_ASSISTANT`) and `clampRuns()` pinned to 10–200.
- `Domain\Service\Harness\NullTraceStore` — the no-storage default, so a runtime without trace config still runs the harness.
- `Nvoos\Laravel\Adapter\DatabaseTraceStore` + `nvoos_harness_trace_records` migration, bound in `NvoosServiceProvider`.

**Why the loop and the store ship together.** Cluster 3 deferred the store because it had no consumer. The loop is that consumer: it emits one record per critique through the `on_iteration` seam, which the WordPress delegation wires to `WP_MCP_AI_Harness_Trace_Capture::record_refine_iteration()`. The seam also keeps core free of any reference to the WordPress class.

**Error translation.** Core must not import `WP_Error`, so the loop throws `SelfRefineException` with `reason ∈ {empty_task, cost_exceeded, generator_failed, critic_failed}` and, when a callable returned one, the original error object. The WordPress delegation returns that object unchanged, so callers still receive the very `WP_Error` their generator produced — not a copy. The two translatable messages are re-localised in the WordPress layer, because core messages are English defaults and the delegation must not regress i18n.

**Two caps, deliberately.** `HarnessProfile` clamps `refine.max_iters`, but a caller can pass `$opts` straight to the loop, so the loop clamps again: `MAX_REFINE_ITERATIONS = 4` holds no matter what the profile says. The cost ceiling is charged *before* each call, so an unaffordable draft is never generated — and the pass that the ceiling cancels is still counted in `estimated_cost_usd`, which is the WordPress behaviour this ports.

**Proven equivalent.** Both implementations ran over 23 scenarios — accept, revise-to-cap, `max_iters` 999 and `-4`, empty feedback, reject, ceiling-before-draft, ceiling-mid-loop, zero-ceiling, empty task, generator error (draft and revision), critic error (first and later pass), uppercase verdict, unknown verdict, missing verdict, bare-string critique, junk critique, and non-string answers (int, null). Output was **byte-identical**, including error codes and the per-pass trace records.

**Deferred on purpose.** `record_reflection()` stays in WordPress. Its write target is the `store_agent_context` tool and WordPress has no `ToolInvokerInterface` adapter, so porting it needs an invoker bridge plus that tool — memory-track work, not harness-loop work. It is the only remaining method on the class.

**Validation.** `lib/core` **1,519 tests / 5,386 assertions** (35 new); app suite **687 passed / 4,054 assertions** (9 new — this cluster adds a binding, so it carries an app-level test); adapter suite unchanged at 180 / 676 with the same 2 pre-existing project-tool failures; phpcs clean on the laravel and WordPress standards.

---

## 11. Cluster 5 result — tool router harness

**What shipped.** `Domain\Service\Harness\ToolRouterHarness` — `scoreTool()`, `rank()`, `fuseWithRrf()`, `resolveAssistantInputs()`, `buildPresetIndex()`, `clampWeight()`, and the `RRF_K = 60` constant. WordPress delegates all four public methods and keeps its implementations for `lib/`-stripped builds.

**Three dependencies are injected, not imported.** The preset catalogue is a translated WordPress array, so `rank()` takes it as `$options['presets']` (or a prebuilt `$options['preset_index']`, which the WordPress façade passes from its existing static cache). The two hook seams arrive as `$options['score_filter']` and `$options['stage_weight_filter']`; the WordPress layer wraps `wp_mcp_ai_harness_tool_score`, `wp_mcp_ai_harness_rrf_weight_harness` and `wp_mcp_ai_harness_rrf_weight_attention` in closures, so every documented filter still fires with its documented argument list. Tools are duck-typed on `getSlug()`/`get_slug()` and `getCapabilityFlags()`/`get_capability_flags()`, because WordPress and core tools expose different casings.

**The clamp is a deliberate tightening.** `HarnessProfile` clamps `tools.preset_weights` to ±5, but it has **no sanitiser for `tools.preferences` at all**, and either map can be passed straight to `rank()`. The router now clamps every assistant-supplied weight at read time. With in-range weights the behaviour is unchanged — which is what the equivalence run shows.

**Three fidelity details that only a diff could find.**

1. `fuse_with_rrf()` in WordPress never sanitises the task class: `'  QA  '` misses the stage-weight table and falls back to `general`, while `rank()` passes the *sanitised* class to `score_tool()`. Core now keeps the two paths apart — scoring normalises, fusion does not — or a caller's padded task class would silently change the fusion weights.
2. `resolve_assistant_inputs()` is asymmetric in the original: `preset_weights` keys are returned **verbatim** (including a junk `'  '` key), `preferences` keys are **sanitised** and empty ones dropped. Preserved exactly.
3. The preset index is genuinely load-bearing: the real catalogue has **70 presets**, **413 tools in two or more families**, and the top tool (`list_openai_files`) sits in **10**. Accumulation therefore fires in practice, not just in the unit test.

**Failure is degraded, not thrown.** A tool that throws while being scored is skipped; a wholesale failure returns an empty ranking, which the caller reads as "use the fixed order". A router that throws takes the chat turn with it.

**Proven equivalent.** 2,184 scenarios — 8 tool shapes × 10 task classes × 4 preference sets × 6 preset sets, plus 6 tool subsets × 10 classes × 3 attention sets, 7 direct fusion cases × 10 classes, 4 profile shapes and 5 filter probes. **2,184 scenarios, 241 differences, all 241 the `over_clamp` cases** — i.e. zero differences anywhere a weight is inside ±5.

**Validation.** `lib/core` **1,544 tests / 5,434 assertions** (25 new); app suite unchanged at **687 / 4,054** because the cluster adds no binding; adapter suite 180 / 676 with the same 2 pre-existing project-tool failures; phpcs clean on the laravel and WordPress standards.

**Still unwired, and that is a separate decision.** Nothing in the chat path calls the router yet — `tools.router: scored` is read only by `Harness_Profile::is_layer_enabled()`. Porting the scorer does not wire it; wiring it would be new behaviour on both runtimes, so it belongs in its own change with its own eval.
