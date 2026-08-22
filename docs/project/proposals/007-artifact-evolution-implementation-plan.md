# Implementation Plan: Artifact Evolution — Phase A (Wiring Repair & Safety Foundation)

Companion implementation plan to [`007-artifact-evolution.md`](./007-artifact-evolution.md). This document specifies the exact changes for **Phase A**, which is implemented in this pass. Phases B–G are specified at task level in the proposal and are tracked separately.

## Scope of This Pass

Phase A only. Nothing here changes default runtime behavior:

- All new behavior is gated behind opt-in filters that default to `false`.
- No new dependencies.
- No data migration (existing options remain in place; the parallel stores become *readable* rather than replaced).
- Backward-compatible API changes only (optional parameters appended).

## Working Conventions Applied

Per `.context/conventions.md` and the project rules:

- Base files target **PHP 7.4** (no enums, no named args, no union types, no `match`). The `lib/wordpress-adapter` file keeps its existing PHP 8.0+ style (`match`, strict types).
- WPCS: tabs, 120-char lines, full PHPDoc with `@since 1.x.x` (current version: **1.9.x** — use `@since 1.9.0` for new API, matching the Pro proposer's tag).
- Tool rules: sanitize arguments at entry, escape output; canonical envelope (`success` array or `WP_Error`) — the `WPMCPAI.Tools.*` sniffs must stay clean.
- i18n: every new user-facing string via `__()` with the `mcp-ai-wpoos` text domain.
- Every filter/action name prefixed `wp_mcp_ai_` and docblocked with `@since`.

## Task List

### Task A.1 — Repair the `evolve_harness` ↔ Evolver contract

**File:** `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php`

1. Add constants:
   - `VALID_COMPONENTS = array( 'all', 'prompt', 'roles', 'skills', 'memory' )`
   - `BUDGET_TRANSIENT_PREFIX = 'wp_mcp_ai_evolution_budget_'`
   - `DEFAULT_BUDGET_USD = 5.0`
   - `DEFAULT_REFINER_CALL_COST_USD = 0.01`
2. Constructor: keep the documented signature `__construct( $session_id, $assistant_id )`, add a defensive normalization — if the first argument is numeric and the second is not, swap them (guards against the exact bug found in G1).
3. New public method `analyze_failures( $component = 'all', $window_length = 50 )`:
   - `$component` validated against `VALID_COMPONENTS`; invalid → `WP_Error( 'wp_mcp_ai_evolution_invalid_component', …, array( 'status' => 400 ) )`.
   - `$window_length` clamped to 10–500.
   - Reads the trajectory window (new optional `$window_length` override on `read_trajectory_window()`); when no audit trail exists, returns a graceful analysis array (`failures_detected => 0`, `trail_available => false`, translated `note`) — never a fatal.
   - Returns `failures_detected` (total instances across all signature buckets), `signatures`, `trajectory_count`, `window_length`, `component`.
4. Extend `evolve()` to `evolve( $component = 'all', $window_length = 0, $dry_run = false )`:
   - Existing gates preserved (enabled filter, session/assistant validity) and evaluated first.
   - Component validated; invalid → `WP_Error`.
   - `$dry_run` stored on a private `$dry_run` property; each pass checks it before any `update_*` write.
   - `$window_length` forwarded to the trajectory read (0 = filter default).
   - Runs only the requested pass(es); non-requested components report `status => 'skipped'`.
   - Result gains `changes_applied` (count of passes that stored/created something) and `summary` (translated one-liner) — consumed by both tool copies.
   - Budget gate evaluated before the trajectory read (cheap early-exit).
   - Audit-trail event + evolution log are **not** written for dry runs.
5. Budget helpers (private): `get_budget_limit()` (filter `wp_mcp_ai_harness_evolution_budget_usd`, default `DEFAULT_BUDGET_USD`), `get_budget_spent()`, `record_budget_spend( $usd )`, `budget_remaining()` — transient keyed `BUDGET_TRANSIENT_PREFIX . $assistant_id`, `HOUR_IN_SECONDS` TTL, same pattern as `WP_MCP_AI_Pro_Harness_Proposer::COST_TRACKER_PREFIX`.
   - `call_refiner()` enforces the budget per call and estimates cost: `cost_usd` from the response if present, else token-usage-based estimate, else `DEFAULT_REFINER_CALL_COST_USD` flat fallback.

**File:** `includes/tools/class-wp-mcp-ai-tool-evolve-harness.php`

6. `get_evolver_instance()`: construct with `( $session_id, $assistant_id )` — correct order.

**File:** `lib/wordpress-adapter/src/Tool/EvolveHarnessTool.php`

7. `getEvolver()`: construct with `( $session_id, $assistant_id )` — correct order. Handlers already pass the right values.

**Acceptance criteria**
- `analyze` operation on an assistant with no audit trail returns a chat response (no fatal).
- `evolve` with `component => 'prompt'` runs only the prompt pass; `dry_run => true` performs zero writes.
- Invalid component returns `WP_Error` with code `wp_mcp_ai_evolution_invalid_component` from both tool copies.
- Constructor survives swapped argument order.

### Task A.2 — Evolved prompt consumption (opt-in)

**New file:** `includes/agents/class-wp-mcp-ai-evolved-prompt-resolver.php`

- `WP_MCP_AI_Evolved_Prompt_Resolver` — static class, `register()` (idempotent, same pattern as `WP_MCP_AI_Guardrails::register()`).
- Subscribes `wp_mcp_ai_resolved_system_prompt` at priority 15 (between Prompt Injector at 10 and Guardrails at 20), signature `( $prompt, $assistant_id, $context )`.
- Gate: `apply_filters( 'wp_mcp_ai_harness_use_evolved_prompt', false, $assistant_id, $context )`. When true and `_wp_mcp_ai_evolved_system_prompt` is a non-empty string, return the evolved prompt; otherwise return the original unchanged.
- ABSPATH guard, full docblocks.

**File:** `includes/agents-init.php`

- Require the new file after the evolver; call `WP_MCP_AI_Evolved_Prompt_Resolver::register()`.

**Acceptance criteria**
- Default: filter is a no-op (resolved prompt unchanged).
- With the opt-in filter true and evolved meta present, the evolved prompt is returned; with no evolved meta, the original is returned.

### Task A.3 — Evolved skills merge into the Skill Registry (opt-in)

**File:** `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php`

- New static method `get_evolved_skills()`: reads the `wp_mcp_ai_evolved_skills` option and normalizes each entry to registry shape: `name` (sanitize_key), `description` (sanitize_textarea_field + PII scrub), `instructions` (PII-scrubbed inert text — the Refiner's `code`), `evolved => true`. Invalid entries (missing name) are skipped.

**File:** `includes/class-wp-mcp-ai-skill-registry.php`

- In `load_skills()`, after the directory scan and before `update_skill_index()`: merge evolved skills when `apply_filters( 'wp_mcp_ai_skill_registry_include_evolved', false )` is true and the evolver class is available. Guarded with `class_exists` + `is_callable` so the registry has no hard dependency.

**Acceptance criteria**
- Default: registry index unchanged.
- Opt-in: evolved skills appear in `get_all_skills()` / `get_skill_index()` with `name` / `description` / `instructions` present.
- Registry loads fine when the evolver class is absent.

### Task A.4 — Sanitization & PII scrubbing of Refiner output

**File:** `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php`

- `evolve_skills()`: create + update paths — `name` via `sanitize_key`/`sanitize_text_field`, `description` via `sanitize_textarea_field` + `WP_MCP_AI_PII_Filter::scrub()` (guarded by `class_exists`), `code` via PII scrub only (remains inert instructional text; deliberately **not** `wp_kses_post`-stripped so code examples survive, and never executed).
- `evolve_roles()`: `system_instructions` through PII scrub + `sanitize_textarea_field`.
- `evolve_prompt()`: the evolved prompt is PII-scrubbed before storing in post meta.
- PII filter is an optional dependency — code paths must degrade gracefully when the class is absent.

**Acceptance criteria**
- A Refiner response containing an email-like secret does not persist it (with the PII filter active).
- Skill entries still round-trip code examples.

### Task A.5 — Evolution budget guard

Covered under A.1 items 1 + 5. **Acceptance criteria**
- With the budget transient at/over the limit, `evolve()` returns the budget `WP_Error` before any trajectory read or provider call.
- With evolution disabled (default), the disabled reason wins over the budget check (disabled is checked first).

### Task A.6 — Tests & docs

**New file:** `tests/test-harness-evolver.php` (WP_UnitTestCase)

1. `test_detect_failure_signatures_empty` — empty trajectory → empty signatures.
2. `test_detect_failure_signatures_tool_failures` — synthetic `tool_call` events with `is_error` results.
3. `test_detect_failure_signatures_stuck_loops` — 3+ consecutive identical tool+args calls.
4. `test_detect_failure_signatures_budget_and_success_rate` — `session_end` timeout + <50% success rate.
5. `test_analyze_failures_no_trail_graceful` — no audit trail → array, `failures_detected` 0, `trail_available` false.
6. `test_analyze_failures_invalid_component` — `WP_Error`.
7. `test_evolve_disabled_by_default` — `evolved => false` with disabled reason.
8. `test_evolve_invalid_component_returns_wp_error`.
9. `test_evolve_budget_gate` — enable via filter, set budget transient ≥ limit → budget `WP_Error`; cleanup filters/transients in `tearDown`.
10. `test_should_evolve_warmup_and_frequency` — with evolution enabled, iteration 1 false (warmup), iteration 5 true, iteration 51 false (stable frequency 20 → 51 % 20 ≠ 0); filter cleanup.
11. `test_get_evolved_skills_normalizes_registry_shape`.
12. `test_constructor_survives_swapped_arguments` — construct with `( 42, 'session-abc' )`; assistant_id resolves to 42, session to `session-abc`.

**File:** `tests/test-tool-evolve-harness.php` (extend)

13. `test_analyze_operation_does_not_fatal` — regression test for the undefined-method bug.
14. `test_evolve_operation_disabled_by_default_returns_envelope`.

**Docs**

15. `includes/agents/README.md` — add resolver row to the public-surface table; add new hooks to the events lists (`wp_mcp_ai_harness_use_evolved_prompt`, `wp_mcp_ai_harness_evolution_budget_usd`, `wp_mcp_ai_skill_registry_include_evolved`).
16. `includes/tools/README.md` — note the corrected `evolve_harness` contract (component filtering + dry-run now enforced).

## Verification Commands

```bash
# Syntax
php -l includes/agents/class-wp-mcp-ai-agent-harness-evolver.php
php -l includes/agents/class-wp-mcp-ai-evolved-prompt-resolver.php
php -l includes/tools/class-wp-mcp-ai-tool-evolve-harness.php
php -l includes/class-wp-mcp-ai-skill-registry.php
php -l lib/wordpress-adapter/src/Tool/EvolveHarnessTool.php
php -l tests/test-harness-evolver.php

# Style (changed files only)
vendor/bin/phpcs --standard=phpcs.xml.dist \
  includes/agents/class-wp-mcp-ai-agent-harness-evolver.php \
  includes/agents/class-wp-mcp-ai-evolved-prompt-resolver.php \
  includes/agents-init.php \
  includes/tools/class-wp-mcp-ai-tool-evolve-harness.php \
  includes/class-wp-mcp-ai-skill-registry.php \
  lib/wordpress-adapter/src/Tool/EvolveHarnessTool.php \
  tests/test-harness-evolver.php \
  tests/test-tool-evolve-harness.php

# Unit tests
vendor/bin/phpunit tests/test-harness-evolver.php
vendor/bin/phpunit tests/test-tool-evolve-harness.php
```

PHP 7.4 compatibility is reviewed manually (base files avoid PHP 8-only syntax); `composer run lint:compat` may be run repo-wide if time permits.

## Out of Scope (Phases C–G)

Fitness population management, crossover/learning-log mutators, the VaG admission gate, auto-deploy promotion, shadow A/B, and the lineage admin UI — all specified in the proposal, none implemented here. No behavior changes without explicit opt-in.

---

# Phase B — Fitness Harness: Score Artifacts (Base)

Implemented second (this pass). Gives the Continual Harness Evolver its first real fitness signal: the ability to score a candidate prompt against the failures the incumbent actually produced, and to reject a mutation that does not improve on them (Imbue's post-mutation verification, >10x cost lever).

## Task List

### Task B.1 — Artifact-scoped eval suites

**File:** `includes/measurement/eval/class-wp-mcp-ai-eval-suite.php`

1. Add optional `artifact_type` (enum: `''`, `prompt`, `role`, `skill`, `memory`, `profile`; default `''` = general) and `artifact_id` (slug, default `''`) to the suite constructor args, with sanitized private properties, getters (`get_artifact_type()`, `get_artifact_id()`), `is_artifact_scoped()`, and `to_array()` additions.

**File:** `includes/measurement/eval/class-wp-mcp-ai-eval-suite-registry.php`

2. Add `get_suites_for_artifact( $artifact_type, $artifact_id = '' )` (type must match; id matches when either side is empty) and `get_general_suites()`.

**Acceptance criteria**
- Existing suites (no artifact fields) behave exactly as before; `to_array()` gains the two new keys.
- Lookup filters correctly across type/id combinations.

### Task B.2 — Failure-case replay from the Trace Store

**New file:** `includes/harness/class-wp-mcp-ai-artifact-failure-replay.php`

3. `WP_MCP_AI_Artifact_Failure_Replay` (static API):
   - `collect_failures( $assistant_id, $options )` — walks the assistant's recent Meta-Harness trace runs (`WP_MCP_AI_Harness_Trace_Store::list_runs`), reads each `tool_calls.jsonl`, keeps `result_success === false` records (optional `slugs` whitelist, `max_runs` default 5, `max_failures` default 20), PII-scrubs summaries, dedupes by slug+args hash. `WP_Error( 'wp_mcp_ai_failure_replay_no_cases' )` when nothing found.
   - `build_cases( $assistant_id, $options )` — converts failure records into `WP_MCP_AI_Eval_Case` arg arrays: `input` carries the replay prompt + tool slug/args/error/run_id; `expected` carries per-case rules; `verifier_slug` defaults to the new `artifact_replay` verifier (option-overridable, e.g. `llm_judge`); `metadata.source = trace_replay`. Per-case rules are filterable via `wp_mcp_ai_artifact_replay_case_rules`.
   - `build_suite( $assistant_id, $options )` — wraps the cases in an artifact-scoped suite (`artifact_type` default `prompt`, `artifact_id` = assistant ID).

**New file:** `includes/measurement/verifiers/class-wp-mcp-ai-artifact-replay-verifier.php`

4. `WP_MCP_AI_Artifact_Replay_Verifier` (slug `artifact_replay`): deterministic, no LLM calls. Reads `$subject['expected']['rules']` and delegates to an internal `WP_MCP_AI_Rule_Verifier`; baseline rule = `required` on `value` (non-empty output). Registered on `wp_mcp_ai_register_verifiers` at priority 10 from `harness-init.php`.

**Acceptance criteria**
- A trace run containing failed tool calls yields replay cases with the right shape; successful calls are excluded; secrets in args/error summaries are redacted when the PII filter is loaded.
- No trace data → `WP_Error` with `wp_mcp_ai_failure_replay_no_cases` (callers treat as “skip verification”).

### Task B.3 — Post-mutation verification gate

**New file:** `includes/harness/class-wp-mcp-ai-artifact-verification-gate.php`

5. `WP_MCP_AI_Artifact_Verification_Gate::evaluate( $incumbent_generator, $candidate_generator, $suite, $options )`:
   - Runs `WP_MCP_AI_Eval_Runner::run()` for both generators over the same suite (same cases → same slugs).
   - Pairs case reports; counts `improved_cases` (incumbent fail → candidate pass) and `regressed_cases` (reverse).
   - Modes (filterable via `wp_mcp_ai_artifact_verification_mode`):
     - `improve` (default, Imbue): accept iff `improved_cases > 0`.
     - `no_regression`: accept iff `regressed_cases === 0` and candidate pass_rate ≥ incumbent pass_rate − tolerance (default 0.05).
   - Zero cases → `decision => 'skip'`. Final verdict filterable via `wp_mcp_ai_artifact_verification_decision`.

**File:** `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php`

6. Wire into `evolve_prompt()`: when `wp_mcp_ai_harness_verification_enabled` (default false, receives assistant ID + component) is true, the candidate prompt must pass the gate on the replay suite before it is stored. Failures return `status => 'verification_failed'`, `stored => false`, with the gate result attached. Skip paths (no replay data, missing classes, exhausted budget) default to allow-with-note and are visible in the pass result. Verification generator calls share the existing per-assistant evolution budget.

**Acceptance criteria**
- Default behavior unchanged (verification off).
- With verification on and replay data present: improving candidates are stored, non-improving candidates are rejected and reported.

### Task B.4 — Run-store artifact indexing

**File:** `includes/measurement/eval/class-wp-mcp-ai-eval-run-store.php`

7. `record()` accepts optional `$artifact` array (`artifact_type` / `artifact_id`) stored in the record envelope (backward compatible). A bounded index option (`wp_mcp_ai_eval_runs_artifact_index`, FIFO cap 500) maps artifact keys to suite slugs; new `get_runs_for_artifact( $type, $id )` merges matching suite histories newest-first. This lets the existing regression detector operate per artifact.

### Task B.5 — Tests & docs

8. New `tests/test-artifact-failure-replay.php` (seeded trace runs; collection, case shape, PII, no-data error), `tests/test-artifact-verification-gate.php` (improve / no_regression / skip / non-callable), `tests/test-eval-artifact-scoping.php` (suite/registry/run-store). Update `includes/harness/README.md` public surface + hooks.

## Verification Commands

Same as Phase A: `php -l`, `vendor/bin/phpcs` on changed files, `PHPCompatibilityWP` 7.4–8.3 on base files, `vendor/bin/phpunit` on the new + neighboring test files, `composer run docs:check-folder-readmes`.

---

# Phase C — Artifact Population & Selection (Base)

Implemented third (this pass). Gives the evolution loop its population mechanics: competing artifact variants with lineage, scored by Phase B's evaluation, and sampled via Imbue-style sigmoid fitness × novelty weighting with a dynamic percentile midpoint. The population is a site-global archive (cross-assistant transfer) pruned FIFO like the harness-profile population.

## Task List

### Task C.1 — Artifact population store

**New file:** `includes/harness/class-wp-mcp-ai-artifact-population.php`

1. `WP_MCP_AI_Artifact_Population` (static API), option `wp_mcp_ai_artifact_population_global`:
   - Entry shape: `hash` (md5 of type+artifact content), `artifact_type` (prompt\|role\|skill\|memory\|profile), `artifact_id`, `artifact` payload, `score` (mean of `score_history`, capped at 10), `eval` payload, `parent` hash, `children` hashes, `children_count`, `sources`, `created_at`, `last_seen_at`.
   - `archive( $artifact_type, $artifact_id, $artifact, $score, $eval, $parent_hash, $source_assistant_id )` — upserts by content hash; re-seen entries aggregate scores and merge sources; new entries link parent→child lineage. `WP_Error` for invalid artifact types.
   - `get_population( $filters )` — filter by type / id / min_score / source assistant. `get_entry()`, `remove()`, `clear()`, `get_stats()` (count, mean/max/min score, dynamic midpoint).
   - Cap pruning keeps the most recent `MAX_POPULATION` (filterable `wp_mcp_ai_artifact_population_max`).

### Task C.2 — Imbue-style parent sampling

2. Selection mechanics on the same class:
   - `compute_weights( $entries, $options )` — `weight = max( min_weight, sigmoid( sharpness × ( score − midpoint ) ) × ( 1 + novelty_weight / ( 1 + children_count ) ) )`. Dynamic midpoint = Nth percentile of current population scores (default 50th, nearest-rank), so selection pressure stays in the sigmoid's high-gradient region as the population improves.
   - `sample_parents( $artifact_type, $artifact_id = '', $k = 1, $options )` — weighted sampling without replacement; strictly positive minimum weights (low scorers are occasionally sampled); injectable `rng` callable for deterministic tests; final weight map filterable via `wp_mcp_ai_artifact_population_weights`.
   - Tuning filters: `wp_mcp_ai_artifact_population_sharpness` (default 10), `..._percentile` (50), `..._novelty_weight` (2.0), `..._min_weight` (0.01).

### Task C.3 — Evolver integration

**File:** `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php`

3. `verify_prompt_candidate()` archives the incumbent and the candidate (parent = incumbent hash) into the population after a real verification run, attaching `incumbent_hash` / `candidate_hash` to the result. Rejected candidates are archived too — the learning log needs the failures. Guarded by `class_exists`; no writes when verification is off.

### Task C.4 — Tests & docs

4. New `tests/test-artifact-population.php`: lineage linkage, score aggregation, FIFO cap, scope filtering, weight properties (fitness, novelty decay, min weight), dynamic midpoint, deterministic sampling via injected RNG, filter overrides. Update `includes/harness/README.md` and the proposal status/file map.

**Acceptance criteria**
- Population persists lineage and aggregates scores without duplicating content.
- Sampling prefers high scorers, decays with children, never zero-weight, and respects artifact scoping.
- No behavior change when verification is off (archive writes only follow a real verification run).

---

# Phase D — Mutators & Learning Log (Base)

Implemented fourth (this pass). Adds the three Imbue mutator strategies as standalone services operating on the Phase C population, plus the learning-log store that turns past mutations into differential context (`diff + score delta`) for future mutator calls. The Continual Harness `evolve_*` passes remain the legacy single-lineage path; the mutators are the population-native path the Phase E admission gate will consume.

## Scope Decision (deviation from proposal D.1)

- Implemented: **failure-driven**, **learning-log-aware**, and **crossover** mutators.
- Deferred: the **self-referential mutation-prompt evolution** (Promptbreeder) — it needs its own population of mutation prompts and will land as a Phase D+ refinement after the gate (Phase E) validates the loop end-to-end.
- Deferred: **D.3 (Pro proposer as mutator backend)** — Pro-addon work, tracked in the proposal; the mutator context shape already leaves room for it.

## Task List

### Task D.1 — Mutator services

**New file:** `includes/harness/class-wp-mcp-ai-artifact-mutator.php`

1. `WP_MCP_AI_Artifact_Mutator` (static API), three LLM-driven prompt mutators sharing a JSON response contract (`{"prompt": …, "change_summary": …}`), PII-scrubbed output, and a provider-style callable (`function ( array $messages, array $options ): array|WP_Error`) so tests run without a live provider:
   - `failure_driven( $llm_callable, $context )` — parent prompt + replayed failure cases → targeted fix.
   - `with_learning_log( $llm_callable, $context )` — same, plus learning-log entries (diff + score delta) with instructions not to repeat regressions.
   - `crossover( $llm_callable, $context )` — 2+ parents combined into one artifact.
   - Shared `diff_artifacts( $parent, $child, $max_lines )` — pure-PHP line diff (LCS, capped input) producing a compact `+`/`-` change description for learning-log persistence.
   - Mutation envelope: `{ success, kind, parent_hashes, artifact_type, artifact, change_summary, diff, meta }`; `WP_Error` for missing parents / bad LLM output. Filters: `wp_mcp_ai_artifact_mutator_temperature` (default 0.7).

### Task D.2 — Learning log

**New file:** `includes/harness/class-wp-mcp-ai-artifact-learning-log.php`

2. `WP_MCP_AI_Artifact_Learning_Log` (static API), option `wp_mcp_ai_artifact_learning_log` capped at 200 entries (FIFO, filterable):
   - `record( $entry )` — id, artifact type/id, parent/child hashes, kind, diff, change_summary, score_delta, assistant, timestamp; PII-scrubbed diff/summary.
   - `get_for_neighborhood( $hash, $n, $strategy )` — `ancestors` (walk the population's parent chain) or `siblings` (children of the same parent) → the “what was tried around this lineage” context for mutators.

**File:** `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php`

3. `verify_prompt_candidate()` records a learning-log entry after every real verification run: diff between incumbent and candidate prompts + `score_delta` (candidate − incumbent pass rate). Guarded by `class_exists`; no writes when verification is off.

### Task D.3 — Tests & docs

4. New `tests/test-artifact-mutator.php` (all three kinds with stub LLM callables, error paths, PII scrub, diff helper) and `tests/test-artifact-learning-log.php` (record/retrieve, cap, ancestor + sibling neighborhoods). Add an evolver test that skip paths write nothing. Update `includes/harness/README.md` and the proposal status/file map.

**Acceptance criteria**
- Mutators produce canonical envelopes from stubbed LLM output; error paths are `WP_Error`.
- Learning log persists diffs + score deltas and resolves lineage neighborhoods.
- No behavior change when verification is off.

---

# Phase E — Pre-Commit Admission Gate (VaG) (Base)

Implemented fifth (this pass). The safety centerpiece: every artifact candidate must pass three heterogeneous critics **before** it can be applied — structural validity, behavioral harmlessness, and marginal gain over the incumbent. This is VaG's Verifier-as-Gatekeeper mapped onto existing layers; admission is pre-commit only (post-hoc rollback cannot undo contaminated descendants — arXiv 2608.05810).

## Task List

### Task E.1 — Three critics mapped to existing layers

**New file:** `includes/harness/class-wp-mcp-ai-artifact-admission-gate.php`

1. `WP_MCP_AI_Artifact_Admission_Gate::evaluate( $artifact_type, $candidate, $incumbent, $verification, $assistant_id )` → `{ decision: admit|reject|skip, critics: { structural, harmlessness, marginal_gain }, reasons: [] }`.
   - **Structural validity** — internal validators per artifact type (prompt: non-empty + length cap `wp_mcp_ai_artifact_admission_max_chars`, default 30000; skill: required name + instructions; others: non-empty payload), plus site-supplied validators via `wp_mcp_ai_artifact_admission_validators`.
   - **Behavioral harmlessness** — `WP_MCP_AI_Pii_Filter::contains_secret()` (deterministic) + `WP_MCP_AI_Guardrails::analyze_message( $text, 'high' )` (jailbreak/injection/diversion). Class-absent → pass with a visible `critic_unavailable` reason.
   - **Semantic consistency / marginal gain** — consumes the Phase B verification result: `strict` mode (default) requires `improved_cases > 0 && regressed_cases === 0`; `net_gain` mode requires `improved > regressed`. No verification evidence → governed by `wp_mcp_ai_artifact_admission_on_no_evidence` (`skip` default; `reject` fails closed).
   - Any critic failure ⇒ overall `reject`. Verdict override available via `wp_mcp_ai_artifact_admission_decision`.

### Task E.2 — Per-assistant pool caps

**File:** `includes/harness/class-wp-mcp-ai-artifact-population.php`

2. New `enforce_per_assistant_cap( $assistant_id )` — evicts the lowest-scored entries sourced from that assistant until the per-assistant cap (`wp_mcp_ai_artifact_population_per_assistant_max`, default 25) is satisfied. Deterministic, score-ordered eviction keeps the pool small (the VaG 5x-smaller-pool result) without touching other assistants' entries.

### Task E.3 — Evolver integration

**File:** `includes/agents/class-wp-mcp-ai-agent-harness-evolver.php`

3. `verify_prompt_candidate()` runs the admission gate after the Phase B verification and attaches the verdict as `result.admission`; `evolve_prompt()` rejects on either `verification.decision === 'reject'` or `verification.admission.decision === 'reject'` (shared helper). Per-assistant cap enforced after archiving (evicted count attached). No writes when verification is off.

### Task E.4 — Tests & docs

4. New `tests/test-artifact-admission-gate.php` (all three critics, strict/net-gain modes, no-evidence policies, custom critics, decision filter) + population cap tests. Update `includes/harness/README.md` and the proposal status/file map.

**Acceptance criteria**
- A candidate failing any critic is rejected; marginal gain without evidence follows the configured policy.
- Population eviction is score-ordered, deterministic, and assistant-scoped.
- Default behavior unchanged (gate only runs when verification is enabled).

---

# Phase F — Deployment, Shadow A/B & Drift (Base)

Implemented sixth (this pass). Promotion of artifact variants out of the Phase C population into the live runtime, with the Phase B verification gate as the mandatory holdout, session-hash shadow serving (Imbue-style controlled rollout), and the existing regression detector wired to artifact deployments with optional automatic rollback.

## Scope Decision (deviation from proposal F.1)

The proposal said "extend `WP_MCP_AI_Harness_Auto_Deploy`". Artifact storage differs structurally from profiles (prompt = assistant post meta; skills = site-global option; both content-addressed in the population), and Auto-Deploy's meta keys and `average_score()` helper are profile-shaped. A sibling class `WP_MCP_AI_Artifact_Deploy` reuses the same safety guarantees (holdout gate, rollback target, append-only audit trail) without entangling profile and artifact storage. The proposal tags Phase F as Pro, but every dependency (Auto-Deploy, verification gate, regression detector, run store) already lives in Base, so Phase F lands in Base like Phases B–E.

## Task List

### Task F.1 — Artifact promotion with rollback + audit trail

**New file:** `includes/harness/class-wp-mcp-ai-artifact-deploy.php`

1. `WP_MCP_AI_Artifact_Deploy::promote( $assistant_id, $artifact_type, $candidate, array $options = array() )` → `array|WP_Error`.
   - Deployable types: `prompt` (assistant meta `WP_MCP_AI_Agent_Harness_Evolver::EVOLVED_PROMPT_META_KEY`) and `skill` (option `wp_mcp_ai_evolved_skills`, site-global — documented; audit trail still stored on the promoting assistant).
   - Capability gate: `current_user_can( 'edit_post', $assistant_id )`.
   - Structural validation of the candidate (prompt: non-empty string within the admission length cap; skill: `name` + `instructions`).
   - Saves the incumbent as the rollback target (`_wp_mcp_ai_artifact_previous_{type}`), stamps `_wp_mcp_ai_artifact_deployed_at_{type}`, appends an immutable (append-only, FIFO-100, no public mutation API) audit event `promote` with hash/actor/reason/timestamp.

### Task F.2 — Holdout regression gate before promotion

**File:** same class

2. `promote()` requires holdout evidence (fail closed): either a pre-computed `WP_MCP_AI_Artifact_Verification_Gate::evaluate()` payload in `$options['verification']` with `regressed_cases === 0` and `candidate_pass_rate >= MIN_HELD_OUT_PASS_RATE` (0.95), or inline `$options['generators']` + `$options['suite']` which `promote()` runs through the gate in `no_regression` mode itself. Missing evidence → `WP_Error wp_mcp_ai_artifact_deploy_no_holdout`. The site may relax the requirement via `wp_mcp_ai_artifact_deploy_require_holdout` (default true).

### Task F.3 — Shadow mode: session-hash bucketing

**New file:** `includes/harness/class-wp-mcp-ai-artifact-shadow.php` + resolver integration in `includes/agents/class-wp-mcp-ai-evolved-prompt-resolver.php`

3. `register_candidate( $assistant_id, 'prompt', $payload, $hash )` stores the admitted variant; `should_serve_candidate( $assistant_id, $artifact_type, $candidate_hash, $context )` buckets deterministically: `( hexdec( substr( md5( $session_key ), 0, 8 ) ) % 10000 ) / 10000 < pct / 100`. Session key defaults to `current_user_id . ':' . wp_get_session_token()` and is filterable via `wp_mcp_ai_artifact_shadow_session_key`; percentage defaults to 10 via `wp_mcp_ai_artifact_shadow_percentage` (0 disables).
4. The resolver consults the shadow controller when `wp_mcp_ai_artifact_shadow_enabled` is true for the assistant: bucketed sessions receive the shadow candidate, everyone else the incumbent; every serve decision is recorded in bounded stats (`wp_mcp_ai_artifact_shadow_stats_*`) for Phase G dashboards. Comparison runs through the Trace Store + F.4 drift detection — shadow never writes the deployed artifact.

### Task F.4 — Drift detection → automatic rollback

**File:** same deploy class

5. `detect_drift( $assistant_id, $artifact_type, array $thresholds = array() )` splits `WP_MCP_AI_Eval_Run_Store::get_runs_for_artifact()` summaries on the deployment timestamp into baseline (pre) and current (post-deploy means) and runs `WP_MCP_AI_Eval_Regression_Detector::detect()`. No baseline or no post rows → `actionable: false` with a reason (never a false alarm).
6. `check_and_rollback( $assistant_id, $artifact_type, array $thresholds = array() )` invokes `rollback()` automatically only when the drift is actionable **and** `wp_mcp_ai_artifact_deploy_auto_rollback` (default false — human-in-the-loop, CoSAI) returns true; the rollback appends an `rollback_drift` audit event carrying the detector reasons. Otherwise the drift report is returned for the Phase G admin queue.

### Task F.5 — Tests & docs

7. New `tests/test-artifact-deploy.php` (promote happy path, fail-closed without evidence, capability gate, structural rejection, rollback restore, append-only history + cap, inline holdout evaluation, drift detection with seeded run-store rows, auto-rollback filter on/off) and `tests/test-artifact-shadow.php` (deterministic bucketing, 0%/100% bounds, registration + stats, resolver shadow swap for a bucketed session, default-off). Update `includes/harness/README.md`, `includes/agents/README.md`, proposal status + file map, and this plan.

**Acceptance criteria**
- No promotion without holdout evidence (fail closed); rollback always restores the exact incumbent and is audit-logged.
- Shadow mode never writes the deployed artifact; bucketing is deterministic per session.
- Drift detection never fires without baseline data; automatic rollback is opt-in.
- Default behavior unchanged (no shadow, no auto-promote, no auto-rollback).

## Verification Commands

```bash
php -l includes/harness/class-wp-mcp-ai-artifact-deploy.php
php -l includes/harness/class-wp-mcp-ai-artifact-shadow.php
vendor/bin/phpcs --standard=phpcs.xml.dist includes/harness/class-wp-mcp-ai-artifact-deploy.php includes/harness/class-wp-mcp-ai-artifact-shadow.php includes/agents/class-wp-mcp-ai-evolved-prompt-resolver.php includes/harness/harness-init.php tests/test-artifact-deploy.php tests/test-artifact-shadow.php
vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 7.4-8.3 --extensions=php includes/harness/class-wp-mcp-ai-artifact-deploy.php includes/harness/class-wp-mcp-ai-artifact-shadow.php includes/agents/class-wp-mcp-ai-evolved-prompt-resolver.php tests/test-artifact-deploy.php tests/test-artifact-shadow.php
vendor/bin/phpunit tests/test-artifact-deploy.php tests/test-artifact-shadow.php
```

Note on the WSL test loop: on this machine PHPUnit runs are ~100x faster from an ext4 copy (the 9p mount on `/mnt/f` causes a file-stat storm). Sync once with `wsl -d Ubuntu -- bash -c '~/sync-wpoos.sh'` (script: `bin/wsl-sync.sh`, copies to `~/mcp-ai-wpoos-ws/` incl. the SQLite WP core), then run tests inside WSL with `wsl -d Ubuntu -- bash -c 'cd ~/mcp-ai-wpoos-ws && php vendor/bin/phpunit tests/…'`. Prefix Windows-side commands containing `/`-rooted args with `MSYS2_ARG_CONV_EXCL='*'` to stop Git Bash path mangling; avoid heredocs through the terminal (quote-stripping turns `<<'EOF'` into an expanding heredoc).

---

# Phase G — Governance & Observability (Base)

Implemented seventh (this pass). Closes the loop with a unified budget/rate governor across every mutation path, a human approval queue for promotions and drift-rollbacks (backed by the Phase F deploy class), a lineage graph for artifacts, an admin governance surface in the assistant screen, and the compliance mapping.

## Task List

### Task G.1 — Unified evolution governor (budget + rate limits)

**New file:** `includes/harness/class-wp-mcp-ai-evolution-governor.php`

1. `WP_MCP_AI_Evolution_Governor` (static) unifies the Phase A budget across all mutation paths (`evolver`, `search`, `proposer`, `population` — paths extendable via `wp_mcp_ai_evolution_governor_paths`):
   - One shared hourly per-assistant budget. The spend transient reuses the Phase A key (`wp_mcp_ai_evolution_budget_{assistant_id}`) so existing spend carries over; the limit keeps the Phase A filter `wp_mcp_ai_harness_evolution_budget_usd` (default $5.00).
   - Per-path rate limit: `wp_mcp_ai_evolution_governor_rate_limit` (default 60 mutations/hour/path), counted in hourly transients.
   - Site-wide mutation cap: `wp_mcp_ai_evolution_governor_site_max_mutations` (default 0 = unlimited).
   - `can_mutate( $assistant_id, $path, $estimated_cost_usd )` → `{allowed, reason, …}`; `record_mutation()`; `record_spend()`; `get_report()` (observability payload for G.2). `wp_mcp_ai_evolution_governor_enabled` (default true) kills the whole layer; with default limits the evolver's observable behavior is unchanged.

### Task G.2 — Approval queue + lineage graph + admin surface

**New files:** `includes/harness/class-wp-mcp-ai-artifact-approval-queue.php`, `includes/harness/class-wp-mcp-ai-artifact-lineage.php`, `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-artifact-governance.php`

2. `WP_MCP_AI_Artifact_Approval_Queue` (option-backed): `enqueue()` stores a pending `promote` item (candidate + holdout verification) or `rollback` item (drift report); `approve()` executes the item via `WP_MCP_AI_Artifact_Deploy::promote()` / `rollback()` and records approver/note/timestamp; `reject()` records the decision without executing. Capability-checked, TTL-expiring (`wp_mcp_ai_artifact_approval_ttl`, default 7 days), bounded (20 pending per assistant, 500 total FIFO). Actions: `wp_mcp_ai_artifact_approval_queued` / `wp_mcp_ai_artifact_approval_decided`.
3. `WP_MCP_AI_Artifact_Lineage`: `graph( $artifact_type, $hash )` walks the Phase C population's parent/children links into `{nodes, edges}`; `get_root()` walks to the seed; `render_ascii()` produces a testable tree for admin/CLI surfaces.
4. `WP_MCP_AI_Metabox_Artifact_Governance` (extends `WP_MCP_AI_Metabox_Base`, registered in the assistant CPT like the harness-profile metabox): renders the governor report, the pending queue with nonce'd approve/reject actions (`admin-post` handlers, `edit_post` capability + `check_admin_referer`), and the lineage tree of the deployed prompt. Registered only when the Phase F/G classes exist.
5. Evolver integration: when `wp_mcp_ai_artifact_queue_for_approval` (default false) is true, a verified+admitted prompt candidate is enqueued as a `promote` item instead of requiring a manual call — the human decision still happens in the queue (CoSAI).

### Task G.3 — Compliance documentation

6. Extend `docs/operations/compliance/EU_AI_ACT_2026.md` with a "Self-Evolution Governance (Artifact Evolution)" section mapping every Phase A–G layer to Article 14 (human oversight), Article 15 (accuracy/robustness), Article 72 (logging), plus NIST AI RMF (Govern/Map/Measure/Manage) and CoSAI safety-by-design alignments. Cross-link from the multi-framework summary.

### Task G.4 — Tests & docs

7. New `tests/test-evolution-governor.php` (budget shared across paths, legacy spend carry-over, rate-limit block, site cap, report shape, enable filter), `tests/test-artifact-approval-queue.php` (enqueue/approve→deploy, reject, caps, TTL, capability), `tests/test-artifact-lineage.php` (graph edges, root walk, ASCII render), `tests/test-metabox-artifact-governance.php` (render with queue items, admin-post approve with nonce + capability). Update `includes/harness/README.md`, `includes/assistants/README.md`, proposal status + file map, and this plan.

**Acceptance criteria**
- All mutation paths draw from one budget; rate limits and the site cap are opt-in via defaults that preserve current behavior.
- Queue approval is the only way a queued candidate reaches deployment; approve/reject are capability- and nonce-protected and fully audit-logged.
- Lineage walks are bounded and deterministic; the admin metabox degrades to a notice when the subsystem is absent.
- Compliance doc gains the self-evolution mapping with no changes to runtime behavior.

## Verification Commands

```bash
php -l includes/harness/class-wp-mcp-ai-evolution-governor.php includes/harness/class-wp-mcp-ai-artifact-approval-queue.php includes/harness/class-wp-mcp-ai-artifact-lineage.php includes/assistants/metaboxes/class-wp-mcp-ai-metabox-artifact-governance.php
vendor/bin/phpcs --standard=phpcs.xml.dist includes/harness/class-wp-mcp-ai-evolution-governor.php includes/harness/class-wp-mcp-ai-artifact-approval-queue.php includes/harness/class-wp-mcp-ai-artifact-lineage.php includes/assistants/metaboxes/class-wp-mcp-ai-metabox-artifact-governance.php includes/agents/class-wp-mcp-ai-agent-harness-evolver.php includes/harness/class-wp-mcp-ai-harness-search-engine.php tests/test-evolution-governor.php tests/test-artifact-approval-queue.php tests/test-artifact-lineage.php tests/test-metabox-artifact-governance.php
vendor/bin/phpunit tests/test-evolution-governor.php tests/test-artifact-approval-queue.php tests/test-artifact-lineage.php tests/test-metabox-artifact-governance.php
```

(WSL loop and MSYS2_ARG_CONV_EXCL caveats: see the Phase F verification note.)
