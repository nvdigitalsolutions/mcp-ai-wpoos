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
| 6 | Necessity gate | **done — 2026-11-15** (see §24; was recorded as blocked on the approval queue, which was wrong) |
| 7 | Harness search engine | **blocked by design** — needs the eval suite and the evolution governor, both WordPress-only |
| 8 | Output guardrail — **scope extension** | **done — 2026-11-15** (see §13) |
| 9 | Guardrail prompt + block policy — **scope extension** | **done — 2026-11-15** (see §14) |
| 10 | Wire the output guard into the chat path | **done — 2026-11-15** (see §16) |
| 11 | Guardrail prompt injection | **done — 2026-11-15** (see §17) |
| 12 | Trace capture | **done — 2026-11-15** (see §18) |
| 13 | Scored tool routing | **done — 2026-11-15** (see §19) |
| 14 | PII / secret pattern parity | **done — 2026-11-15** (see §21) |
| 15 | `lib/core` PSR-12 reformat + style gate | **done — 2026-11-15** (see §22) |
| 16 | Server-side system prompt ownership | **done — 2026-11-15** (see §23) |
| 17 | Tool preset catalogue (generated) | **done — 2026-11-15** (see §25) |

Open work that is *not* a cluster is tracked in §20.

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

---

## 12. Scope extension — output-side safety (clusters 8 and 9)

**Why the scope grew.** The original seven clusters covered the *harness* classes. Reviewing what was left showed a bigger asymmetry: PR #7778 ported the **input** side of the safety layer (`InputGuard`, `PiiFilter`, `PromptInjectionDetector`), and the **output** side is still WordPress-only. That matters more than the tool router did, because unlike the router the output guard is *live* — `harness-init.php` registers it and `class-wp-mcp-ai-rest.php` applies `wp_mcp_ai_pre_response_render` at priority 10 on every REST chat response. So the console screens what goes in and not what comes out.

**Two classes, two clusters.**

| # | Class | Scope |
|---|---|---|
| 8 | `OutputGuardrail` (340 LOC) | Response validation: sensitive-info leakage, unsafe content, injection echoes; block/warn/log modes; strictness tiers |
| 9 | `Guardrails` remainder (658 LOC, `analyze_message` already ported) | The system-prompt guardrail builder and the block/warn/log decision rule |

**Standards this is aligned to** (checked 2026-11-15):

- **OWASP LLM05:2025 — Improper Output Handling** and **LLM02:2025 — Sensitive Information Disclosure**: validate model output before it reaches a sink. The guardrail sits at a pre-render filter, which is the correct sink boundary for chat.
- **LLM07:2025 — System Prompt Leakage**: the injection-echo check is the output-side counterpart of the input-side jailbreak detector.
- **NIST AI RMF — Measure/Manage**: findings must be auditable. That is what the findings log is for.
- **Guardrail engineering guidance** (multiple 2026 sources): "log *that* a check fired and *which* detector — never the matched text"; and decide fail-open vs fail-closed per check rather than globally.
- **Streaming reality**: an output check runs after the provider has generated and billed the response, and text already streamed cannot be recalled. The guardrail is a pre-render gate, not a retraction mechanism, and the docs say so.

**Four problems found while reading, and how each is handled.**

1. **`output_guard` never survives the profile sanitiser.** Both the WordPress and the core `HarnessProfile::sanitize()` handle `guardrails` but not `output_guard`, so `HarnessProfile::get()` always reports `output_guard.enabled = false`. That is why the WordPress guardrail reads raw `_wp_mcp_ai_harness_profile` post meta directly — it bypasses the sanitiser *and* the site-default fallback. A Laravel port that reads the profile through `HarnessProfileProvider` could therefore never turn the feature on. **Fix:** add `output_guard` to the sanitiser in both runtimes, with the same enum validation `guardrails` gets, and have the guardrail consume the sanitised profile. No behaviour changes for the default (disabled) profile.

2. **The findings log stores leaked content.** `log_finding()` writes `content_preview => substr( $content, 0, 200 )` into the `wp_mcp_ai_output_guard_findings` option. When the finding *is* sensitive information, the log then persists that sensitive information — the exact anti-pattern the guidance names. **Fix:** keep the hash, the finding types and the content length; drop the preview. Nothing in the repo reads it (§ verified by grep), so this is safe.

3. **Silent pattern failure.** Both classes use `@preg_match` and ignore the return value, so a pattern that fails to compile silently stops detecting — a fail-open on the one check that matters most. The patterns are static, so rather than invent fail-closed semantics the port **asserts that every pattern compiles** in a unit test. That removes the failure mode instead of hiding it.

4. **`OutputGuardrail` needs its own pattern tiers.** `PiiFilter` already detects email, phone, card, SSN and common API keys, but not AWS keys, JWTs, private keys, connection strings, IPv4, long hex, or credential assignments — and its purpose is redaction, not detection, with different precision trade-offs. The output guardrail keeps its own tiered table. (Separately noted, not fixed here: `PiiFilter` does not redact AWS/JWT/private-key material on the **input** path either.)

**Method.** Same loop protocol as §4: core service, WordPress delegation behind `class_exists` with the local implementation intact, throwaway equivalence harness run twice and deleted, core unit tests, an app test only if a binding ships, then the five gates.

---

## 13. Cluster 8 result — output guardrail

**What shipped.** `Domain\Service\Harness\OutputGuardrail` — `evaluate()`, `hasSensitiveInfo()`, `hasUnsafeContent()`, `hasInjectionEcho()`, `sensitivePatterns()`, `normaliseConfig()`, plus the result/mode/strictness constants and `MIN_ECHO_MARKERS = 2`. WordPress delegates `validate_response()` and keeps its local implementation for `lib/`-stripped builds.

The result is **structured**: findings, a `blocked` flag and the content to deliver. Core does not build translated error text and does not log — both are runtime concerns.

**The four problems from §12, resolved.**

1. **`output_guard` now survives the sanitiser** in both runtimes, with the same enum validation `guardrails` gets. Because `WP_MCP_AI_Harness_Profile::sanitize()` delegates to core, the WordPress fix is the core fix plus the local fallback.
2. **The findings log no longer stores content.** Keys are now `timestamp, assistant_id, findings, content_hash, content_length, surface`. No preview, and no `context` — the REST sink passes a `WP_REST_Request`, so the old `'context' => $context` serialised the user's prompt into an option as well.
3. **Every pattern compiles** — asserted by a unit test over all three tiers, so the `@preg_match` fail-open has nowhere to hide.
4. **Separate tiered pattern table**, deliberately not merged with `PiiFilter`: detection over-reports, redaction must not.

**Three deliberate behaviour changes, all narrow.**

| Change | Old | New | Why |
|---|---|---|---|
| Profile source | raw `_wp_mcp_ai_harness_profile` post meta | `HarnessProfile::get()` | The raw read bypassed the sanitiser *and* the site-default fallback. It was a workaround for problem 1, and it is what made the Laravel side impossible. Side effect: a site-wide default can now enable output guarding, and the `wp_mcp_ai_harness_profile` filter can adjust it — both as documented for every other layer. |
| Malformed `mode` | fell through the `switch` to `log` (pass through) | `warn` | Matches the profile sanitiser, and pass-through is the fail-open the guidance warns about. Unreachable through `get()`, which normalises first. |
| Malformed `strictness` | behaved like `low` | `medium` | Same reason: the sanitiser's default, not an artefact of reading raw meta. |

**Proven equivalent.** 262 scenarios: 23 contents × 8 valid profiles, plus 23 × 3 malformed profiles, plus passthrough and log-shape probes.

- **184 valid-profile scenarios: 0 differences.**
- **69 malformed-profile scenarios: 30 differences**, confined to `bad_enums` (15) and `junk_values` (15) — exactly the two documented changes above. `output_guard_not_array` produced 0 differences.
- **9 probes: 0 differences**, and the log payload is proven to contain no `content_preview` and no `context`.

**Validation.** `lib/core` **1,575 tests / 5,541 assertions** (31 new); app suite unchanged at **687 / 4,054** because the cluster adds no binding; adapter suite 180 / 676 with the same 2 pre-existing project-tool failures; phpcs clean on the laravel and WordPress standards.

**Still open.** The guard is live on WordPress but the console does not call it yet — there is no Laravel counterpart to `wp_mcp_ai_pre_response_render` on the chat path. Porting the evaluator is the prerequisite; wiring it into the Laravel response pipeline is a separate change, and it needs the same pre-render sink boundary to be meaningful.

---

## 14. Cluster 9 result — guardrail prompt and block policy

**What shipped.** Two small services rather than one large façade:

- `Domain\Service\Harness\GuardrailPrompt` — `apply()` and `build()`. The "stay on target" instruction block, prepended when the profile *and* the guardrails layer are both enabled.
- `Domain\Service\Harness\GuardrailPolicy` — `decide()` and `messageKey()`. The block/warn/log rule, separated from detection because "what is this message?" and "what should happen to it?" are different questions.

`Guardrails::analyze_message()` already delegated to `PromptInjectionDetector`; `filter_system_prompt()`, `screen_message()` and `build_block_message()` now delegate too. That completes the class — every method is either ported or platform-bound.

**Why the two sides treat `warn` differently.** The output guardrail's `warn` mode *prefixes and delivers*; the input guardrail's `warn` mode *blocks* the security findings. That asymmetry is deliberate and documented in `GuardrailPolicy`: an unblocked hostile input reaches the model and spends tokens, while a flagged output has already been paid for, so annotating it keeps the turn usable.

**`messageKey()` is wired, not speculative.** The WordPress `build_block_message()` now asks core for the key and maps it to its own translated string, with a local fallback for `lib/`-stripped builds. That keeps the result→key mapping defined once instead of once per runtime — and avoids shipping a helper with no caller, the mistake cluster 3 corrected.

**One quirk pinned rather than fixed.** If `allowed_topics` is a non-empty array whose entries all trim to nothing, the original still emits the list header, leaving a dangling `- ` in the prompt. The profile sanitiser drops such entries before the builder sees them, so it is unreachable — but a test pins it so a future tidy-up does not silently change the prompt.

**Proven equivalent.** 324 scenarios:

- 36 system-prompt cases (9 profile shapes × 4 prompts, including empty, whitespace-only and a 420-word prompt) — **28 injected, 0 differences**.
- 208 `screen_message` cases (4 modes × 4 strictnesses × 13 messages) — **72 blocked, 0 differences**, including the violation log entry and the `wp_mcp_ai_guardrail_violation` action.
- 26 gated cases (layer off, profile off) and 2 short-circuit cases — **0 differences**.
- 52 `analyze_message` cases across four strictness levels — **0 differences**.

The mode breakdown confirms the policy rather than assuming it: `log` blocked **0** of 48 cases, `block` blocked 24, `warn` blocked 24, and the blocked messages were exactly the jailbreak and injection findings.

**Validation.** `lib/core` **1,597 tests / 5,584 assertions** (22 new); app suite unchanged at **687 / 4,054** because the cluster adds no binding; adapter suite 180 / 676 with the same 2 pre-existing project-tool failures; phpcs clean on the laravel and WordPress standards.

**Where this leaves the harness port.** Every harness class is now either ported, platform-bound (trace capture hooks, logging, translated copy), gated on an unported dependency (cluster 6, the necessity gate — it needs the approval queue), or blocked by design (cluster 7, the search engine). The outstanding *enabling* work is not more porting: it is wiring the four ported services into the Laravel chat path, which no runtime-agnostic service can do for itself.

---

---

## 15. Cluster 10 — wiring the output guard into the chat path

**The problem.** `OutputGuardrail` is portable and tested, but nothing calls it on the Laravel runtime. WordPress applies `wp_mcp_ai_pre_response_render` on every REST chat response; the console has no equivalent, so the console screens what goes in and not what comes out.

**Where the sink is.** The Laravel console does not assemble the response itself — `ChatStreamController` delegates to `ChatOrchestrator::handleChatStreaming()`, which lives in `lib/core`. So the seam belongs in the orchestrator, not in an adapter, and both runtimes can use it. This is the same shape `InputGuard` already uses: an optional collaborator set by the provider, doing nothing when unconfigured.

**The streaming constraint, stated plainly.** `handleChatStreaming()` emits token deltas as they arrive (`$onStreamChunk` → `message` events carrying `choices[0].delta.content`). Text already sent cannot be recalled, so a whole-response guard applied at the end would annotate a leak the user has already read. The three options were:

| Option | Verdict |
|---|---|
| Guard the final payload only | Useless on a stream — the deltas already leaked |
| Guard per delta | Wrong for the finding that matters: an email or key split across chunks evades a per-chunk scan, and per-chunk scanning of short tokens over-reports |
| Buffer while the guard is enabled | Correct, and the only one that is honest |

So: **enabling the output guard turns off live token streaming.** When a guard is wired and enabled, the delta callback is suppressed while the model runs, and after validation the response is sent as **one** content chunk followed by the usual payload frame.

It has to be one chunk rather than none: the console's SSE adapter builds assistant text from `choices[0].delta.content` frames only — the trailing payload frame is metadata it ignores. Suppressing the chunks outright would have produced a correct-but-empty bubble. The guarded text is therefore emitted as a single delta, which is the smallest change that both preserves the client contract and never sends unvalidated text.

The default path — no guard configured — is byte-identical to today. That trade-off is the price of a guard that can actually retract, and it is recorded in the config comments so an operator knows what the switch buys and costs.

**A leak-proof return shape.** `OutputGuard::inspect()` returns `content` set to the empty string when the response is blocked, so a caller cannot accidentally render the very text that was blocked. The orchestrator substitutes a fixed withheld notice.

**What ships.**

- `Application\Security\OutputGuard` — a small configured collaborator over `Domain\Service\Harness\OutputGuardrail`, mirroring `InputGuard`.
- `ChatOrchestrator::setOutputGuard()` plus application in both `handleChat()` and `handleChatStreaming()`, with delta suppression while the guard is enabled.
- Config keys `oos.security.output_guard`, `_mode`, `_strictness`, and provider wiring built from them.
- Core unit tests for the collaborator, orchestrator integration tests through the existing scripted-provider harness, and an app-level wiring test.

**Out of scope.** The WordPress side already applies its own filter and keeps doing so; this change does not touch it. Trace capture, the scored router and guardrail prompt injection remain separate items.

---

## 16. Cluster 10 result — output-guard wiring

**What shipped.**

- `Application\Security\OutputGuard` — a configured collaborator over `OutputGuardrail`, mirroring `InputGuard`. `inspect()` returns `content => ''` when a response is blocked, so a caller cannot render what was just blocked.
- `ChatOrchestrator::setOutputGuard()`, `setHarnessProfileProvider()` and `outputGuard()`, plus application in both `handleChat()` and `handleChatStreaming()`.
- Config keys `oos.security.output_guard`, `_mode`, `_strictness`, and provider wiring.
- Core tests (10 for the collaborator, 9 orchestrator integration tests including the streaming path) and an app-level wiring test.

**The bug the wiring exposed.** The first cut suppressed deltas entirely while the guard was enabled. That would have shipped a correct guard and a broken UI: the console's SSE adapter (`spa-v2/src/sse-adapter.ts`) builds assistant text from `choices[0].delta.content` frames only, and the trailing payload frame is metadata it ignores. So a buffered turn now emits its validated text as **one** delta before the payload — the smallest change that keeps the client contract and never sends unvalidated text.

**Precedence, and why it is asymmetric.** A per-assistant profile that enables `output_guard` wins; otherwise the guard wired from config stands. A profile can therefore switch the guard **on** but never **off** — a stale or partial profile must not be able to silently disable a safety control.

**Validation.** `lib/core` **1,616 tests / 5,640 assertions** (19 new); app suite **690 passed / 4,063 assertions** (3 new); adapter suite 180 / 676 with the same 2 pre-existing project-tool failures; `phpcs-laravel.xml.dist` clean across the 264 in-ruleset files.

Two notes on the gates. `lib/core` is **not** in `phpcs-laravel.xml.dist`'s `<file>` list — passing a core file explicitly lints it against PSR-12 and reports the pre-existing WPCS spacing in `ChatOrchestrator.php`, so only the ruleset's own trees are treated as the gate (the new `OutputGuard.php` was brought to PSR-12 anyway). And one full app run failed in `ProjectManagementToolParityTest` at `parent::setUp()` — infrastructure, not an assertion; two subsequent full runs were green.

**What this leaves.** The chat path now enforces, in order: input guard → provider → output guard → buffered or streamed delivery. Trace capture, the scored router and guardrail prompt injection are still unwired, and each is a separate change.

---

## 17. Cluster 11 result — guardrail prompt injection

**What shipped.** `ChatOrchestrator` now prepends the guardrail block to the conversation's system turn when the assistant's profile enables the layer, on both `handleChat()` and `handleChatStreaming()`. It resolves the harness profile **once per turn** and shares it with the output-guard resolution from cluster 10, so a turn reads its profile once rather than twice.

**Why it lives in the orchestrator.** WordPress filters one resolved system prompt (`wp_mcp_ai_resolved_system_prompt`). The Laravel chat path has no such seam — in fact it had no system-prompt handling at all, which is a separate gap. Putting the injection in the orchestrator gives both runtimes one place to change and works whether the client sends a system turn or not.

**Three behaviours worth naming.**

- **First system turn, or a new one.** A conversation may carry one `system` message, several, or none. The block goes on the first; when there is none, a system message is inserted. From the model's side those are the same thing.
- **Idempotent.** If the system content already contains `[Guardrails — Stay on Target]`, nothing is added. Doubling the block would double its weight in the prompt, and a client may well have injected it already.
- **Opt-in at two levels.** Nothing happens unless the profile is enabled *and* `guardrails.enabled`. That gate lives in `GuardrailPrompt::apply()`, so the orchestrator does not restate it.

**Validation.** `lib/core` **1,623 tests / 5,654 assertions** (7 new); app suite unchanged at **690 / 4,063** because no binding ships; adapter suite 180 / 676 with the same 2 pre-existing project-tool failures; `phpcs-laravel.xml.dist` clean across the 264 in-ruleset files.

The tests assert what the provider actually receives, not what the orchestrator thinks it sent: no profile leaves one message, an enabled layer yields `system` first, an existing system turn is prefixed rather than replaced, a disabled layer injects nothing, a pre-injected block is not duplicated, `high` strictness reaches the block, and the streaming path injects too.

---

## 18. Cluster 12 result — trace capture

**What shipped.** `Application\Chat\TraceCapture` — one run, recorded — plus `ChatOrchestrator::setTraceStore()` / `traceStore()`, a `oos.security.trace_capture` master kill switch, and provider wiring.

A run is a chat turn. It writes three record types to the store's `run.jsonl`: `start` (model, provider, assistant, timestamp), one `iteration` per provider call (finish reason, tool-call count), and `end` (iterations, cost, cancelled, duration, record count). `finish()` then prunes to the profile's window.

**The bug the wiring exposed.** The first cut recorded iterations from the agentic loop's `$iteration` counter. That counter only advances on a *continuation*: a turn that answers immediately breaks out of the loop before `++$iteration` runs, so a one-call turn recorded **zero** iterations — a trace that misreports the exact thing it exists to measure. The counter now belongs to the capture (`providerCall()` auto-numbers, and `end` carries `provider_calls` alongside the orchestrator's own loop count). Four call sites: initial call and loop continuation, on both chat paths.

**Two deliberate limits.**

- **No message text.** Records carry identifiers, counts and timings only — `content` and `prompt` never appear. A trace that copies the conversation into long-lived storage is a liability, and `PiiFilter` elsewhere exists precisely to keep that text out.
- **Capture is profile-gated, the kill switch is global.** Nothing is written unless `trace_capture.enabled`; `oos.security.trace_capture` is the master switch on top, mirroring WordPress's `wp_mcp_ai_harness_trace_capture_enabled` filter. The retention window comes from the profile and is clamped to 10–200 by `TracePolicy` before the store sees it.

**Validation.** `lib/core` **1,640 tests / 5,714 assertions** (17 new); app suite **693 passed / 4,067 assertions** (3 new); adapter suite 180 / 676 with the same 2 pre-existing project-tool failures; `phpcs-laravel.xml.dist` clean.

**Cost note.** `finish()` prunes on every completed turn, as the WordPress store does. That is one grouped read plus one delete per turn — acceptable against a table indexed on `(assistant_id, run_id)`, and the alternative (a periodic sweep) needs a scheduler this runtime does not have yet.

---

## 19. Cluster 13 result — scored tool routing

**What shipped.** `buildAllowedTools()` now takes the resolved profile and, when `tools.router` is `scored`, orders the tool definitions through `ToolRouterHarness::rank()` — by capability flags against the profile's task class, plus per-slug preferences. `fixed` (the default) keeps the assistant's configured order, exactly as before.

The trace records the decision: a `routing` record carries the router mode, the tool count and the slugs **in the order the model saw them**. That is the first question asked when a turn goes wrong, and with a scored router it is no longer the configured order.

**Failure is a fallback, not an outage.** `rank()` already returns an empty array rather than throwing; the orchestrator treats an empty ranking, or any Throwable, as "use the fixed order". Anything the router did not score keeps its configured position at the end instead of disappearing. A ranking problem must never become a capability outage.

**What actually scores on this runtime.** Capability flags and per-slug `tools.preferences` work today. `tools.preset_weights` do **not**: the preset catalogue is the WordPress helper's ~3,100-line translated array, and the Laravel runtime has no equivalent — its `presets` endpoints are schedule presets, not tool presets. The router is fed an empty catalogue, so every preset weight is inert by construction. Porting a catalogue belongs with the console's tool-preset UI, not with the router; until then the flag and preference signals carry the ranking.

**Scope discipline.** `scored` stays opt-in and is not the default. Turning it on changes which tools a model attends to first, and that is a behaviour change that needs an eval to justify — the plan's cluster 5 gate said as much. This PR makes the switch real; it does not flip it.

**Validation.** `lib/core` **1,644 tests / 5,721 assertions** (4 new); app suite unchanged at **693 / 4,067** because no binding ships; adapter suite 180 / 676 with the same 2 pre-existing project-tool failures; `phpcs-laravel.xml.dist` clean.

The tests assert the definitions the provider received: the default router keeps `writer, reader`; `scored` with `task_class: rag` produces `reader, writer`; scoring reorders without filtering; and the trace records `scored` with `['reader', 'writer']`.

---

## 20. Open items

The clusters are done. What is left is either dependency-blocked or deliberately deferred, and each one is written here so it is picked up intentionally rather than rediscovered. Nothing in this list is a bug in what shipped.

### Blocked on another subsystem

| Item | Blocked on | What "done" looks like |
|---|---|---|
| **Cluster 6 — necessity gate** (652 LOC) | *(Recorded as: "the approval queue, which is WordPress-only. A gate that cannot ask for approval can only auto-approve." That was wrong — see §24.)* | **Closed — cluster 17, see §24** (ported; the approval queue is one of five verdict branches and was never the blocker) |
| **Cluster 7 — harness search engine** (1,324 LOC) | An eval suite/registry **and** the evolution governor. | A parameter search with something to search and a way to judge the result. Until then any port would be a loop over a scoring function nobody can evaluate. |

### Deferred by decision

| Item | Why it is deferred | What "done" looks like |
|---|---|---|
| **Tool preset catalogue on Laravel** | `tools.preset_weights` are inert (§19): the catalogue is WordPress's ~3,100-line translated array and Laravel's `presets` endpoints are *schedule* presets. Capability flags and per-slug `tools.preferences` carry the ranking meanwhile. | A tool-preset catalogue in core (data, not translations) plus an index build at boot; parity test asserting the same tool lands in the same families on both runtimes. Belongs with the console's tool-preset UI. |
| **`tools.router: scored` as default** | The switch is live but off (§19). Changing which tools a model attends to first is a behaviour change that needs measurement. | An eval comparing `fixed` vs `scored` on real assistant tool sets, with a stated tie-break rule for when flags alone are enough. |
| **Retrieval harness in the chat path** | §7: wiring `retrieve()` changes model input and needs a prompt-budget review. | A budgeted retrieval step with a before/after token and quality comparison. |
| **`record_reflection()`** | §10: its only write target is `store_agent_context`, and WordPress has no `ToolInvokerInterface` adapter to bridge it. | A WordPress `ToolInvokerInterface` adapter, then the memory-track work that owns `store_agent_context`. |

### Gaps found in passing, not in scope

| Item | Where it surfaced | What it needs |
|---|---|---|
| **`PiiFilter` misses AWS keys, JWTs, private keys and connection strings** | §12: the output guardrail detected these, `PiiFilter` did not redact them on the **input** path. | **Closed — cluster 14, see §21.** |
| **The chat path had no system-prompt handling at all** | §17: guardrail injection had to be added to the orchestrator because nothing assembled a system prompt. | **Closed — cluster 16, see §23** (server-side, matching WordPress) |
| **`lib/core` has no style gate** | §16: `phpcs-laravel.xml.dist` did not list `lib/core` in its `<file>` set, and `ChatOrchestrator.php` carried 535 PSR-12 spacing errors that no gate reported. | **Closed — cluster 15, see §22** (79,948 violations, not 535; reformatted in #7805, gate on in this cluster) |
| **One flaky app test** | §16: `ProjectManagementToolParityTest` failed once at `parent::setUp()` and passed on two subsequent full runs. | Catch it with `--repeat` if it recurs; not reproducible now. |

---

## 21. Cluster 14 result — PII / secret pattern parity

**What shipped.** `PiiFilter::patterns()` and the WordPress `default_patterns()` gained four families: AWS access key IDs (`AKIA` long-term and `ASIA` STS), JWTs, PEM private-key blocks (the whole block, not the header), and connection strings. The two tables are byte-identical and in the same order — verified by reflection over the WordPress table, not by reading them side by side.

**Why the gap mattered more than "missing patterns".** The output guard detected all four shapes; the input path redacted none of them. Probing the old table against real shapes showed the leak was worse than absent: `postgres://user:pass@db.internal:5432/app` came out as `postgres://user:[REDACTED_EMAIL]:5432/app`, because the **email** pattern was matching the `user:pass@host` authority on its own. That accidental partial catch is why the family looked covered. `redis://default:hunter2@cache:6379` matched nothing at all and reached storage intact — a redis URL has no email-shaped authority to borrow.

**Order is load-bearing.** Three orderings changed, each because the pre-existing table would otherwise claim the match first:

- Credential shapes before the credit-card pattern. An AWS key ID is four identifying characters plus sixteen that are frequently all digits, so the card pattern produced `AKIA[REDACTED_CC]` on numeric keys and missed lettered keys entirely.
- Connection strings before the email pattern, for the reason above.
- Private-key blocks before the card pattern, because base64 key material carries long digit runs.

**Two deliberate divergences from the detection table.** Redaction and detection have different false-positive budgets, so the tables are intentionally not shared — the class docblock now says so:

- Detection flags the `-----BEGIN … PRIVATE KEY-----` header, which is evidence enough to raise a finding. Redaction matches the **entire block**: replacing only the header would report a redaction while leaving the key material in the text, which is worse than no guard because it looks handled.
- Detection's `[^\s]+` tail is fine when a false positive costs a warning prefix. Redaction stops before trailing sentence punctuation, so `see postgres://u:p@h/db.` keeps its full stop instead of eating it.

**One pre-existing defect fixed in passing.** The card pattern allowed a trailing separator (`(?:\d[ -]?){13,19}`), which swallowed the space after the run: `order 1234567890123456 confirmed` became `order [REDACTED_CC]confirmed`. It now ends on a digit. Same redaction count, no glue.

**Limitation, documented rather than papered over.** A *truncated* PEM — a BEGIN with no matching END — is not redacted, because the block pattern cannot close. `OutputGuardrail` still detects the header on the output side, so the shape is caught on the half of the pipeline that can see it.

**Test-file cleanup was part of making the change landable.** `tests/test-harness-services.php` carried 40 WPCS errors against a per-file CI budget of 10 (`.github/workflows/php-linting.yml`), so any PR touching it needed three other clean files to pass at all. Thirty-nine were mechanical: 33 missing method docblocks, 2 missing variable docblocks, 2 missing `@var` tags, and one blank line after a trait import. The remaining 4 were `OneObjectStructurePerFile`, resolved with a sniff-scoped exclude-pattern rather than a file split, matching the precedent the ruleset already sets for two other test files that group several classes. The file is now at zero, and the change is pure addition (226 lines, 0 deletions).

**Validation.** `lib/core` **1,667 tests / 5,793 assertions** (23 new); app suite **693 / 4,067**, unchanged; `phpcs-laravel.xml.dist` clean on both changed core files; `phpcs.xml.dist` clean on both changed WordPress files. The adapter suite was not run — nothing under `lib/laravel-adapter/` changed.

The tests pin what a caller receives rather than what the patterns intend: each family is reduced to a single token; an AWS key is never mistaken for a card number; a complete PEM loses its body *and* its END marker while the surrounding prose survives; a truncated PEM is explicitly asserted **not** to be redacted; seven ordinary-prose cases that merely name credentials are asserted untouched; and every default pattern is compiled, so a typo cannot silently disable a family the way `scrub()`'s skip-invalid-pattern behaviour otherwise would.

---

## 22. Cluster 15 result — the lib/core style gate

**What shipped.** `phpcs-laravel.xml.dist` now lists `lib/core/src` and `lib/core/tests`, so the largest tree in the Laravel runtime has a style gate for the first time. The ruleset run is **799 files, 0 errors, 0 warnings**. The reformat that made this possible landed separately as #7805 (533 files, +58,454 / −52,254).

**The measurement changed the plan.** The item was recorded as "`ChatOrchestrator.php` carries 535 unreported PSR-12 spacing errors". That was one file. The tree had **79,948** violations across 533 files — 35,368 even with tab indentation exempted — and was internally *mixed*: under `WordPress-Core` it fails 25,536 times, so there was no "declare its existing style" shortcut either.

**Why the tree became spaces.** Roughly 78,000 of the 79,948 were indentation itself: PSR-12 mandates four spaces and `lib/core` used tabs. Exempting the indentation sniffs does not produce a tab-clean tree, because phpcbf's brace fixers insert space-indented lines — the first attempt left methods whose opening brace sat at four spaces over a body indented one space. That is provably token-identical and completely unreadable, and repairing it would mean a re-indent pass over 495 files. Converting the tree is the smaller risk. The adapter keeps tabs; its ruleset says so and that is unchanged.

**The reformat was verified mechanically, not by review.** Every file was compared against `HEAD` as a PHP token stream with whitespace removed: 454 identical, 80 differing only by braces added around single-statement control bodies, **0 differing in any other token**. The brace additions are the one place the change is not whitespace, so they are stated rather than assumed.

**The remaining 60 were four kinds of judgement call.**

| Violation | Count | Resolution |
|---|---|---|
| `ClosingBrace.StatementAfter` | 44 in 25 files | Split the line at the brace. Whitespace only. |
| `MultipleClasses` | 9 in 5 test files | Sniff-scoped `exclude-pattern`, matching the precedent the WPCS ruleset sets for its own stubs. Splitting would spread one fixture across files. |
| `CamelCapsMethodName` | 6 in `ValidateToolSchemaTool` | Renamed the six private `validate_*` methods and their six call sites. They are private, so no surface changed. |
| `ValidClassName` | 1 | A `WP_Error` stand-in in a test fixture has to carry WordPress's name. Excluded. |

**A bug worth recording.** The first brace-splitting script matched a member keyword and re-emitted only the matched prefix, silently deleting the method name off 44 lines: `} public function getName(): string` became `} public function` followed by `{`. phpcs did not catch it — the lines it was pointed at were fixed, and the parse errors sat in files the sniff never had to parse to complain. PHPUnit caught it. **A regex "fix" that satisfies a sniff can still destroy the line it was applied to, so lint every touched file with `php -l` before trusting a mechanical pass.** The corrected script splits the line at the brace and keeps the whole remainder.

**A limitation, documented rather than papered over.** `<exclude name="Generic.WhiteSpace.DisallowTabIndent">` is ruleset-wide; PHPCS cannot scope a sniff exclusion by path. That means the two indentation sniffs are off for `lib/core` and the app skeleton as well as the adapter, so nothing stops a future contributor re-tabbing `lib/core`. Attempting a path-scoped `<rule ref>` instead does not work — the `PSR12` inclusion still reports, which turned the adapter red with 6,139 errors. Enforcing indentation per tree needs a second ruleset and a second CI step; that is written here so it is picked up deliberately. Nothing depends on it today, because the tree is already spaces.

**Validation.** Ruleset run: **799 files, 0 errors**. `lib/core` **1,667 tests / 5,793 assertions** — unchanged from before the reformat and the gate. Token comparison of this cluster's changes: 533 of 534 files identical, and the one that differs does so by exactly 12 lines — six `validate_*` declarations and their six call sites.

---

## 23. Cluster 16 result — server-side system prompt ownership

**What shipped.** `ChatOrchestrator` now applies the assistant's `system_prompt` server-side on both the non-streaming and streaming paths, composed with the guardrail block in the same order WordPress uses: guardrail, then instructions, then whatever the conversation itself carried.

**The gap was narrower than it looked, and worse.** The console already knew the prompt existed — `AssistantTestController` emits a `no_system_prompt` warning when it is empty — but `assistantConfig['system_prompt']` was never read by the orchestrator. Three call sites were already passing it (`AssistantTestController`, `ChatStreamController`, `AgentNodeRunner`); core ignored it. So the console diagnosed a prompt it never used.

**WordPress is the reference, and it is server-side.** The REST handler resolves the assistant's stored instructions and prepends the professional prompt; the provider client emits `['role' => 'system', 'content' => ['text' => …]]` as the first message, then merges memory messages after it. Nothing about the prompt depends on client cooperation, which is the property worth having.

**Composition rules.** `applySystemPrompt()` replaces the guardrail-only `applyGuardrailPrompt()`:

1. The guardrail block is added unless the conversation already carries its header.
2. The assistant's instructions are added unless the conversation already contains them.
3. The conversation's own system text is appended last, never dropped.
4. Anything already present is left alone — a client that injected a block must not get it twice, because doubling it doubles its weight in the prompt.

Each rule is pinned by a test. The four guardrail behaviours that existed (insert, prefix-not-replace, disabled injects nothing, idempotent) are unchanged and still pass untouched.

**A behaviour change worth stating.** The guardrails layer being off no longer takes the instructions with it. Instructions are the assistant's own configuration; the rails are an operator overlay. They were previously coupled only because instructions were never applied at all.

**Trust boundary, noted rather than quietly widened.** `ChatStreamController` (which serves both `/chat-client` and `/chat/stream`) takes the whole `assistant` config from the request body and does not resolve it against the database. Honouring `system_prompt` there means a client can set it. That endpoint already trusts its client for `provider`, `model` and `tools` — `tools` being the more powerful of the four — so this does not create a new class of trust, but it is newly *effective* and is recorded here. The paths where the server knows the assistant (`AssistantTestController`, `AgentNodeRunner`) resolve from the database and are unaffected. Hardening the transport endpoint belongs with the wider question of who owns assistant configuration, not with this cluster.

**A copy inaccuracy left alone.** The guardrail block says "directly related to your assigned purpose and instructions **above**", but both runtimes place the block *before* the instructions, so the instructions are below it. This is identical in WordPress (`$guardrail_prompt . "\n\n" . $system_prompt`) — a pre-existing wording fault in shared prompt text, not a porting slip. Changing prompt text alters model behaviour on both runtimes and needs an eval, so it is recorded rather than edited.

**Validation.** `lib/core` **1,671 tests / 5,802 assertions** (4 new, 9 new assertions); the 46 existing orchestrator tests pass unmodified. App suite **693 / 4,067**, unchanged — no binding ships. `phpcs-laravel.xml.dist` clean, which also exercises the gate added in §22 on a real change for the first time.

---

## 24. Cluster 17 result — the necessity gate

**A correction first.** §20 recorded cluster 6 as blocked on *"the approval queue, which is WordPress-only"*, with the argument that "a gate that cannot ask for approval can only auto-approve". Both halves were wrong:

- The approval queue appears in **one of five** verdict branches, and the WordPress code already wraps it in `class_exists` (`require_approval()`). Without a queue the gate still returns `WP_Error('necessity_gate_approval_required')` — it blocks *and says so*, which is the opposite of auto-approving.
- The real dependency was `WP_MCP_AI_Action_Safety_Profile` (479 lines), and it is essentially pure PHP: constants, `compute_risk_score()`, `determine_verdict()`, and `derive_irreversibility_from_flags()` over plain flag strings. Its only WordPress-isms are `__()` on label tables and a single `update_post_meta`.

The cluster was **unported, not blocked**. Cluster 7 remains genuinely blocked — it is a parameter-search loop whose fitness function is the eval suite, and `Eval_Suite` / `Eval_Runner` / `Eval_Case` / `Eval_Suite_Registry` / `Evolution_Governor` are all WordPress-only.

**What shipped.**

| Layer | Class | Holds |
|---|---|---|
| Core rules | `Domain\Service\Safety\ActionSafetyProfile` | The two scales and the necessity × irreversibility matrix. Returns a stable `reason_code`, never copy. |
| Core decision | `Domain\Service\Safety\NecessityGate` | Tier 1 safe-tool allowlist, tier 2 heuristics, tier 3 matrix. Structured result, no error object. |
| Core enforcement | `Application\Tool\NecessityGuard` | A monotonic `ToolGuardInterface` that turns block/approval verdicts into denials. |
| WordPress | `WP_MCP_AI_Action_Safety_Profile`, `WP_MCP_AI_Necessity_Gate` | Delegate the arithmetic, the matrix and the classification to core; keep `evaluate()` (hooks, `WP_Error`, logger, queue) and the translated sentences. |

The reason text stays in WordPress: the matrix returns `reason_code`, and `reason_text()` maps it to `__()`. That is what lets `lib/core` carry the safety rules without a translation domain.

**Parity was proven, not assumed.** A throwaway harness ran the WordPress implementation and the core port over the same inputs and compared outputs:

- **20/20 matrix cells** agree on verdict, risk score and `requires_approval`.
- All risk scores agree, including the unknown-necessity default.
- **13/14 flag derivations** agree. The exception is deliberate: core lowercases flags, matching `ReadOnlyToolGuard`'s existing convention, so a tool declaring `READ-ONLY` scores `0.0` rather than WordPress's accidental `0.5`. Every built-in tool declares lowercase, so the practical effect is nil — but it is a real behaviour change on the WordPress side, made to remove a divergence rather than add one.

**Tier 2 is not reachable through the guard, and that is stated rather than hidden.** `ToolGuardInterface` can only allow or deny, so:

- **`skip`** (unnecessary but harmless) is allowed through. Denying it would hand the model an error to recover from, which is worse than running a benign call.
- **`approval_required`** denies with a reason naming the missing approval service. The registry's own `ask` path fails closed for the same reason, so this is consistent rather than a policy choice.

Both are recorded here and in the guard's docblock.

**Wiring.** `oos.necessity_gate.enabled` (env `NVOOS_NECESSITY_GATE`), **off by default** — it changes what the model is allowed to do, so turning it on is a deployment decision, matching `oos.read_only`. Registered as a monotonic guard so an earlier listener cannot re-allow what it denies.

**Validation.** `lib/core` **1,746 tests / 5,967 assertions** (+75 tests, +165 assertions: the full 20-cell matrix, 13 flag derivations, the classification table, and the guard mapping). App suite **693 / 4,067**, unchanged — no app-skeleton binding ships. Adapter suite **180 / 676 with the same 2 pre-existing project-tool failures**. `phpcs-laravel.xml.dist` **804 files, 0 errors**; `phpcs.xml.dist` clean on both changed WordPress files.

---
