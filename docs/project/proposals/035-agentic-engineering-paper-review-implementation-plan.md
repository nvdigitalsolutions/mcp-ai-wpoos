# Implementation Plan: Agentic Engineering Gap-Closure (Waves 1–3)

Companion implementation plan to [`035-agentic-engineering-paper-review-enhancement-plan.md`](./035-agentic-engineering-paper-review-enhancement-plan.md). This document specifies the exact file-level changes, hooks, and acceptance criteria for all 8 work items (W1–W8) plus the documentation wave, in the same task-granular style as [`007-artifact-evolution-implementation-plan.md`](./007-artifact-evolution-implementation-plan.md).

## Scope of This Plan

- All new runtime behaviour is **opt-in and off by default** — no existing assistant's prompts, routing, publishing, or metrics change without an admin opting in. The single exception is new metric *definitions* (idempotent registrations, no new emission by default) and the new publish hook (fired, but enforcement gated off in Base).
- No new composer/npm dependencies. All verifiers deterministic or pluggable-callable (the existing LLM-judge pattern).
- No data migration. New post meta / options are written only when the new UI surfaces are used.
- Backward-compatible API changes only: optional array keys, new classes, new filterable hooks.

## Working Conventions Applied

Per `.context/conventions.md`, `CLAUDE.md`, and the measurement-subsystem conventions (`docs/reference/measurement/conventions.md`):

- Base files target **PHP 7.4** — no enums, named args, union types, `match`, or constructor promotion. WPCS tabs, 120-char lines, full PHPDoc, `@since 1.9.0` for new API (bump only if a newer line ships first).
- i18n: every user-facing string via `__()` / `esc_html__()` with the `mcp-ai-wpoos` domain (Pro strings use the Pro domain per addon convention).
- Every new filter/action prefixed `wp_mcp_ai_` and docblocked with `@since`, parameter shapes, and default values.
- Verifiers: immutable subject (never mutate `$subject`), no exceptions for routine failures (`WP_Error` only when the verifier cannot run), independence declared via `get_independence_profile()`.
- Goodhart discipline: every new metric declares a `counter_metric` pairing; every new rubric preset passes through the counterfactual runner in tests.
- Security: sanitize at entry (`sanitize_text_field`, `sanitize_textarea_field`, `absint`, `sanitize_key`, `wp_unslash`), escape at exit, capability checks (`manage_options` for settings surfaces, `edit_posts` for assistant editing), nonces on all admin POSTs.
- New PHP-bearing folders ship a `README.md` per the folder convention (enforced by `composer run docs:check-folder-readmes`).

---

## Wave 1 — Trajectory verifier, eval gate, few-shot examples

### Task 1.1 — Trajectory verifier kind (Base)

**New file:** `includes/measurement/verifiers/class-wp-mcp-ai-trajectory-verifier.php`

1. `WP_MCP_AI_Trajectory_Verifier extends WP_MCP_AI_Verifier_Base`, kind `'trajectory'`, slug default `'trajectory_verifier'`. Deterministic — independence profile is empty (`disallowed_*` all empty arrays); no LLM calls.
2. Constructor `__construct( $slug = 'trajectory_verifier', array $rules = array() )`. Rule schema (all keys optional, sanitized via `sanitize_key` / `absint` on construct):
   - `expected_sequence` — ordered array of tool slugs; `match_mode` ∈ `exact|ordered|subset` (default `ordered`).
   - `required_tools` — tools that must appear at least once.
   - `forbidden_tools` — tools that must not appear (e.g. writing tools for a `qa` task class).
   - `max_tool_retries` — per-tool attempt ceiling (default `2`; exceeded → evidence entry, score penalty).
   - `max_loop_length` — consecutive repeats of the same tool beyond this count count as a stuck loop (default `3`).
   - `require_verification` — optional tool slug that must appear after generation output (e.g. `retrieve_with_provenance`, `verify_answer`); missing → case fails with reason `verification_skipped` (the paper's "fluent output that skipped its verification steps" failure mode).
   - `on_missing_trajectory` — `'fail'|'skip'`, default `'fail'` with reason `trajectory_missing` (an output with no evidence of its path must not pass a trajectory case; suite authors who want leniency may set `'skip'`).
3. Subject contract: `$subject['trajectory']` is an array of event records `{ tool, outcome, attempt }` (strings/ints) **or** a JSON string (decoded defensively; malformed → `WP_Error('wp_mcp_ai_trajectory_invalid')`). Also accepts the raw artifact shape written by `WP_MCP_AI_Harness_Trace_Store::write_artifact()` (events keyed under `'events'`) so harness trace runs can be verified directly.
4. `verify( array $subject, array $context = array() )` returns the canonical shape: `passed`, `score` (weighted fraction of satisfied rules, weights: sequence 0.4, required/forbidden 0.2 each, retries/loop 0.1 each, verification 0.1), `confidence` (1.0 — deterministic), `reasons` (translated strings, no raw event content beyond tool slugs), `evidence` (per-rule breakdown with matched/missing slugs only — never full argument payloads, PII-safe by construction).

**File:** `includes/measurement/interface-wp-mcp-ai-verifier.php`

5. Add `trajectory` to the documented verifier-kinds list in the interface docblock (`deterministic sequence checks over recorded tool-call paths; no LLM call`).

**File:** `includes/measurement/eval/class-wp-mcp-ai-eval-runner.php`

6. Generator contract extension: accept optional `'trajectory'` key in the generator's return array; pass it through into the subject array handed to verifiers (alongside `value`/`input`). Absent key → subject simply has no `trajectory` member (existing behaviour for all current suites is unchanged). Update the `run()` docblock.

**File:** `includes/measurement/class-wp-mcp-ai-measurement-bootstrap.php`

7. Register `new WP_MCP_AI_Trajectory_Verifier( 'trajectory_verifier' )` in `wp_mcp_ai_register_reference_verifiers()` and add `'trajectory_verifier'` to the default `$enabled` list — so the existing `wp_mcp_ai_enable_reference_verifiers` filter can disable it, and third parties (priority 10) still pre-empt it.

**File:** `includes/measurement/README.md`

8. Note the new verifier kind + subject contract.

**Tests:** `tests/measurement/test-trajectory-verifier.php`

- Golden sequence passes (`expected_sequence` + `match_mode => exact`).
- Out-of-order sequence fails with `ordered` mode and passes with `subset` mode.
- `forbidden_tools` fails; `required_tools` missing fails.
- Stuck-loop detection (4 consecutive repeats, `max_loop_length => 3`).
- `require_verification` missing → `verification_skipped` reason.
- JSON-string trajectory and trace-store artifact shape both normalize.
- Malformed trajectory returns `WP_Error` (not a pass).
- Missing trajectory → `trajectory_missing` fail (and `'skip'` behaves as skip).
- Subject array is not mutated after verify (deep-copy assertion).

**Acceptance criteria**
- All existing eval suites run unchanged (no `trajectory` key → identical results).
- A suite can gate on the agent's path, not just its output.
- Reference-verifier filter can disable the new verifier.

---

### Task 1.2 — Eval gate service + publish hook + WP-CLI (Base)

**New file:** `includes/measurement/class-wp-mcp-ai-eval-gate.php`

1. `WP_MCP_AI_Eval_Gate` — static API, `register()` idempotent pattern (mirrors `WP_MCP_AI_Guardrails::register()`).
   - Constants: `DEFAULT_PASS_RATE = 0.8`, `DEFAULT_ALLOWED_REGRESSION = 0.05`.
   - `public static function check( $assistant_id, array $options = array() ): array` returning `{ passed, status ('passed'|'blocked'|'warned'), reasons: array<string>, suites: array<slug, summary>, pinned: array<slug> }`.
   - Pinned suites come from the assistant's harness profile post meta (`_wp_mcp_ai_harness_profile` → `evals_enabled` array). **No suites pinned → `warned`** with reason `no_suites_pinned` (never blocked — the gate nudges toward eval coverage; it does not hard-fail unpinned assistants by default).
   - Runs pinned suites via `WP_MCP_AI_Eval_Runner` + `WP_MCP_AI_Eval_Suite_Registry` with a generator callable supplied by the caller (Base ships a default generator for rule/schema/trajectory-only suites that need no LLM; suites whose cases require an LLM judge without a callable abstain and are reported as `warned`, not `blocked` — fail-open for judge unavailability, matching the verifier's own abstention semantics).
   - Thresholds via `apply_filters( 'wp_mcp_ai_eval_gate_thresholds', array( 'pass_rate' => self::DEFAULT_PASS_RATE, 'allowed_regression' => self::DEFAULT_ALLOWED_REGRESSION ), $assistant_id )`.
   - Regression check via `WP_MCP_AI_Eval_Regression_Detector` against `WP_MCP_AI_Eval_Run_Store` history when prior runs exist.
   - **Fail-closed discipline:** a suite that exists but cannot be loaded (renamed slug) → `warned` with reason `suite_unavailable`, never a hard block. Hard blocking requires all pinned suites to load and fail. Filter `wp_mcp_ai_eval_gate_fail_closed` (default `false`) for sites that want the inverse.
   - Every check writes a CoSAI audit-trail event (`eval_gate_check`) with assistant id, status, reasons (no prompt content).
2. `register()` subscribes `transition_post_status` on the assistant CPT: when transitioning **into** `publish` from a non-publish status, fire `do_action( 'wp_mcp_ai_assistant_pre_publish', $assistant_id, $gate_result )`. **Base fires the hook only — enforcement is Pro** (Task 1.4), so default behaviour stays unchanged.
3. Optionally record stock metric `eval.gate.outcome` (gauge 0/1/2 for passed/blocked/warned; counter-paired with `chat.turn.error.count`) — see Task 3.2 for metric conventions.

**File:** `includes/cli/class-wp-mcp-ai-cli-measurement-command.php`

4. New subcommand `gate-check`:
   - `wp mcp-ai measurement gate-check --assistant-id=<id> [--enforce] [--format=table|json]`
   - Prints status, reasons, per-suite pass rates; exit code 0 on `passed`, 1 on `blocked`, 2 on `warned` when `--enforce`, else informational 0 (documented in `## OPTIONS`).

**File:** `includes/measurement-init.php` (or the measurement loader used by the subsystem — follow the existing load pattern)

5. Require the new class; call `WP_MCP_AI_Eval_Gate::register()`.

**Tests:** `tests/measurement/test-eval-gate.php`

- No suites pinned → `warned` / `no_suites_pinned`.
- Pinned suite passing → `passed`; pinned suite failing below threshold → `blocked` with reasons.
- Regression detector consulted when run-store history exists (threshold respected).
- Renamed/unloadable suite → `warned` / `suite_unavailable` (and `fail_closed` filter flips to `blocked`).
- `wp_mcp_ai_assistant_pre_publish` fires on draft→publish, not on publish→publish updates.
- Threshold filter changes pass/block boundary.

**Acceptance criteria**
- Base installs see zero behaviour change until a harness profile pins suites or Pro enforces.
- `wp mcp-ai measurement gate-check` is regression-aware and CI-usable.

---

### Task 1.3 — Few-shot examples store, injector, metabox (Base)

**New folder:** `includes/examples/` with `README.md` (purpose, public surface, neighbours: `includes/harness/`, `includes/assistants/metaboxes/`, `.context/pro-vs-base.md`, `docs/features/context-window-management.md`).

**New file:** `includes/examples/class-wp-mcp-ai-examples-store.php`

1. `WP_MCP_AI_Examples_Store` — post-meta-backed CRUD on the assistant CPT.
   - Meta key `_wp_mcp_ai_examples` (JSON array). Post meta (not option) so assistant deletion cleans up.
   - Constants: `MAX_PAIRS = 8`, `MAX_INPUT_CHARS = 2000`, `MAX_OUTPUT_CHARS = 4000`, `MAX_TOOLS_PER_PAIR = 6`.
   - `get( $assistant_id )` → normalized array of pairs `{ label, input, output, expected_tools: array<string> }` (missing/legacy entries skipped, sanitized on read).
   - `save( $assistant_id, array $pairs )` — sanitizes (`sanitize_text_field` label, `sanitize_textarea_field` input/output), truncates to caps, validates `expected_tools` against the tool registry slugs when the registry is available (unknown slugs dropped with a reason logged via `WP_MCP_AI_Logger`), returns `array{ saved, dropped, reasons }`.
   - `count()`, `delete( $assistant_id )` for metabox round-trips.

**New file:** `includes/examples/class-wp-mcp-ai-examples-injector.php`

2. `WP_MCP_AI_Examples_Injector` — static `register()` (idempotent) subscribing `wp_mcp_ai_resolved_system_prompt` at **priority 8** (before the Prompt Injector's cues at 10 and Guardrails at 20; static content first per prompt-caching guidance).
   - Gate: harness profile key `examples.enabled` (Task step 3 below). Disabled or no pairs → return prompt unchanged.
   - Assembly: `\n\n## Examples\n` + `### Example N: {label}\nInput: {input}\nExpected output: {output}` (+ `Expected tools: a, b` line when `expected_tools` present). Pairs are emitted in stored order, oldest-first.
   - Budget: compute the examples block's estimated tokens (`ceil( strlen / 4 )` fallback, or the tiktoken helper when loaded); if the block plus the base prompt exceeds the assistant's model context budget (`WP_MCP_AI_Token_Budget_Manager` + the pre-flight thresholds from `docs/features/context-window-management.md`), drop pairs oldest-first until it fits; if even one pair doesn't fit, inject nothing and log a `WP_MCP_AI_Logger` event (`examples_block_skipped_budget`).
   - Never mutates or re-orders the pre-existing prompt; returns `$prompt . $block`.

**File:** `includes/harness/class-wp-mcp-ai-harness-profile.php`

3. Add profile key `'examples' => array( 'enabled' => false, 'max_pairs' => 3 )` to `defaults()`; extend `sanitize()`: `enabled` boolean, `max_pairs` clamped to `0..8` (reuse `WP_MCP_AI_Examples_Store::MAX_PAIRS`); unknown keys dropped per existing sanitize discipline.

**File:** `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-examples.php`

4. `WP_MCP_AI_Metabox_Examples` following the `WP_MCP_AI_Metabox_Base` pattern (nonce `wp_mcp_ai_examples_nonce`, save on `save_post_mcp_ai_assistant`, bail on autosave/quick-edit, capability check). Renders: enabled checkbox (reads/writes the harness profile examples key), pair rows (label, input, output textareas, expected-tools comma list), add/remove row via existing admin JS conventions.

**File:** `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`

5. Register the metabox alongside the existing metabox registrations (same `add_meta_boxes` block, after Harness Profile).

**Tests:** `tests/examples/test-examples-store.php`, `tests/examples/test-examples-injector.php`

- Store: save/get round-trip; cap enforcement (9th pair dropped); oversized strings truncated; unknown tool slugs dropped; delete.
- Injector: disabled profile → unchanged prompt; enabled + pairs → block appended with correct formatting and static-first ordering (priority assertion via hook order test: injector registered before cue injector); budget overflow drops oldest pairs first; empty-after-drop → no injection; tiktoken path used when available.
- Profile sanitize: `max_pairs` clamped, booleans coerced, unknown keys stripped.

**Acceptance criteria**
- Default behaviour identical for every existing assistant (profile key off).
- Examples load statically before cues/history; token budget is never exceeded by the examples block.

---

### Task 1.4 — Pro gate wiring: publish enforcement, workflow deploy, schedule promotion (Pro)

**New file:** `addons/pro/includes/measurement/class-wp-mcp-ai-pro-eval-gate-enforcer.php`

1. `WP_MCP_AI_Pro_Eval_Gate_Enforcer::register()` (Pro bootstrap).
   - Subscribes `wp_mcp_ai_assistant_pre_publish` (from Task 1.2). When `apply_filters( 'wp_mcp_ai_eval_gate_enforce_publish', true )`:
     - `blocked` → prevent publish: `wp_die()`-free handling — set `$post->post_status` back to draft is unreliable in `transition_post_status`; instead hook `save_post_mcp_ai_assistant` at priority 20, re-check, and on block set status to `draft` + add an admin notice (`WP_MCP_AI_Pro_Eval_Gate_Enforcer::admin_notice`) + audit-trail event with reason and override capability (`manage_options` may override via a checkbox `_wp_mcp_ai_gate_override` present in POST, recorded to the audit trail).
     - `warned` (no suites pinned) → dismissible notice, publish proceeds.
2. Workflow Builder deploy gate: locate the deploy/save handler in `addons/pro/includes/workflow-builder/` (the `deploy` / `save` path that persists workflow state); before persisting, call `WP_MCP_AI_Eval_Gate::check()` for the workflow's referenced assistant(s); `blocked` → abort save, return a structured error rendered in the builder UI; filter `wp_mcp_ai_workflow_eval_gate_enforce` (default `false` — workflow deploys stay permissive until the site opts in; the proposal's stance is deliberate: gate on *production* promotion, not on every builder save).
3. Schedule Manager promotion gate: in the schedule create/enable handler (`addons/pro/includes/schedule-manager/`), when the schedule's target is an assistant run, consult the gate; `blocked` → warn in UI + require `wp_mcp_ai_schedule_allow_gate_override` capability for the override checkbox (audit-logged).
4. All block/override events write CoSAI audit-trail entries (`eval_gate_enforced`, `eval_gate_override`) — no prompt content, only assistant/suite/status.

**Tests:** `tests/pro/measurement/test-pro-eval-gate-enforcer.php`

- Publish blocked → status reverted to draft + notice queued; override present + capability → publish proceeds + audit event.
- Filter default-off keeps legacy publishing unchanged.
- Workflow save aborts on block when filter enabled; schedule enable warns and records override.

**Acceptance criteria**
- Gate enforcement is Pro-only and opt-in per surface; every enforcement path is auditable.

---

## Wave 2 — Pro verification depth + routing tiers

### Task 2.1 — Groundedness verifier (Base)

**New file:** `includes/measurement/verifiers/class-wp-mcp-ai-groundedness-verifier.php`

1. Kind `'llm_judge'` (judge-based — the base class ships no SDK), slug default `'groundedness_verifier'`.
2. Callable resolution: constructor callable, else `apply_filters( 'wp_mcp_ai_groundedness_judge_callable', null, $slug, $subject, $context )` — same shape as `WP_MCP_AI_LLM_Judge_Verifier`. **No callable → abstain** (`passed => false`, `abstained => true` reason) so suites never false-pass on a missing judge.
3. Independence profile: inherits the LLM-judge pattern (`disallowed_providers`/`disallowed_models`/`disallowed_tools` from constructor, filter-extendable) — the registry already enforces generator-vs-judge separation.
4. Subject contract: `value` (the response) + optional `context_docs` (array of source strings the response should be grounded in). The judge callable receives both and returns the canonical verifier shape. Score semantics documented: 1.0 = every claim traceable to `context_docs`; 0.0 = unsupported claims dominate; abstain when no docs supplied and judge requires them.
5. Register in `wp_mcp_ai_register_reference_verifiers()` (`'groundedness_verifier'` in the default enabled list, same disable filter).

**Tests:** `tests/measurement/test-groundedness-verifier.php`

- Abstains with no callable (never passes).
- Fixture judge returning pass/fail propagates score/evidence.
- Independence profile surfaces in registry checks.
- Subject not mutated.

---

### Task 2.2 — Hallucination rubric preset (Pro)

**File:** `addons/pro/includes/measurement/class-wp-mcp-ai-pro-rubric-presets.php`

1. `const SLUG_HALLUCINATION = 'pro_hallucination_rubric';`
2. `public static function hallucination( array $overrides = array() )` — returns a `WP_MCP_AI_Pro_Rubric_Verifier` with criteria: `claim_coverage` (supported claims / total claims, weight 0.5), `unsupported_claim_count` (penalty beyond threshold, weight 0.3), `abstention_credit` (explicit uncertainty statements rewarded, weight 0.2). Judge-backed (composes with Task 2.1's callable filter); criteria filterable via `wp_mcp_ai_pro_pro_hallucination_rubric_criteria` following the existing preset filter naming.
3. Register in `register_preset_rubrics()` alongside the existing three presets.

**File:** `addons/pro/tests/measurement/test-pro-rubric-presets.php`

4. Add cases: passes on fully-grounded fixture; fails on fabricated-claim fixture; partial credit on mixed; bootstrap registers all four presets; reasons never leak subject content (extend the existing no-leak test).

---

### Task 2.3 — Trajectory-compliance rubric preset (Pro)

**File:** `addons/pro/includes/measurement/class-wp-mcp-ai-pro-rubric-presets.php`

1. `const SLUG_TRAJECTORY_COMPLIANCE = 'pro_trajectory_compliance_rubric';`
2. `public static function trajectory_compliance( array $overrides = array() )` — wraps Task 1.1's deterministic criteria (`expected_sequence`, `forbidden_tools`, `require_verification`, loop/retry bounds — configured via `$overrides['trajectory']`) as the dominant weighted criteria (0.7) plus an optional LLM-judge criterion `path_efficiency` (0.3: "was the path efficient and sound?", judge callable optional — criterion abstains when absent, deterministic part still scores). Paper alignment: "trajectory evaluation checks the full sequence of tool calls and intermediate reasoning."
3. Register in `register_preset_rubrics()`.

**File:** `addons/pro/tests/measurement/test-pro-rubric-presets.php`

4. Cases: golden trajectory passes; forbidden-tool trajectory fails; inefficient-but-correct path gets partial credit via judge fixture; no-judge path scores on deterministic criteria only.

---

### Task 2.4 — Task-type → tier routing presets (Base) + admin UI & learned proposals (Pro)

**Context:** `WP_MCP_AI_Language_Model_Router::route_with_tier( $task_type, $confidence, $tier, $options )` already exists with draft/verification tiers and the `wp_mcp_ai_tiered_model_selection` filter. This task adds a declarative, admin-legible preset layer on top — no router internals change.

**New file:** `includes/class-wp-mcp-ai-task-tier-presets.php`

1. `WP_MCP_AI_Task_Tier_Presets`:
   - Option `wp_mcp_ai_task_tier_presets` (array, autoloaded off) mapping task-type keys (the router's vocabulary: `chat`, `tool`, `research`, …) → tier (`draft|verification|auto`). Sanitize on save (`sanitize_key` both dimensions, tier enum-checked).
   - `defaults()` — `array( 'research' => 'verification', 'tool' => 'draft' )` shipped as **documentation-only defaults** (not auto-saved): sites opt in by saving the option; the plugin ships a "Install suggested presets" button instead of silently changing routing.
   - `resolve( $task_type, $default_tier = 'auto' )` — filter `wp_mcp_ai_task_tier_presets` applied first; returns the mapped tier or `$default_tier`.
2. Integration point: a thin adapter in `route_with_tier()`'s caller chain — rather than editing the router, hook `wp_mcp_ai_tiered_model_selection` at priority 5 in the preset class to substitute the tier before the router's default resolution; document this in the router file's filter docblock (`@since` note referencing the preset layer). No behaviour change when no presets saved.

**File:** `addons/pro/includes/admin/` — new admin surface `WP_MCP_AI_Pro_Routing_Tiers_Page` (or a tab on the existing Orchestration Dashboard)

3. Read-only table: task type → current tier + resolved models per provider (via the model catalog); per-row tier dropdown (`draft|verification|auto`) + "Install suggested presets" button; saves via admin-post + nonce + `manage_options`.

**File:** `addons/pro/includes/harness/class-wp-mcp-ai-pro-harness-proposer.php`

4. Extend the proposer with a `routing_tier` proposal kind: from harness trace data, per task-type error-rate and cost pairs suggest tier changes (e.g. `tool` task-type error rate < 2% on draft tier → suggest widening draft usage); proposals flow through the existing confidence-scored, human-approved queue. Gated off by default (`meta_harness_auto_propose` already governs the queue).

**Tests:** `tests/test-task-tier-presets.php`

- Defaults not persisted on activation; `resolve()` returns default tier when option empty.
- Saved preset maps and enum-sanitizes; filter overrides option.
- Adapter changes tier only when a preset exists (router default path untouched).
- Pro: proposal generation from fixture traces; UI save/validation.

**Acceptance criteria**
- Routing behaviour byte-identical for sites that never touch the option.
- Admin-legible tier map; proposals remain human-approved.

---

## Wave 3 — Modes, economics, docs

### Task 3.1 — Prototype vs production assistant modes (Base)

**File:** `includes/assistants/class-wp-mcp-ai-assistant-cpt.php`

1. `const META_MODE = '_wp_mcp_ai_assistant_mode';` values `prototype` (default) | `production`; sanitize on save (`sanitize_key`, enum).

**New file:** `includes/assistants/class-wp-mcp-ai-assistant-mode.php`

2. `WP_MCP_AI_Assistant_Mode::register()`:
   - `apply_filters( 'wp_mcp_ai_assistant_production_requirements', array( 'eval_gate' => true, 'guardrails' => true, 'audit_logging' => true ), $assistant_id )` — requirement map consulted wherever a production assistant is touched.
   - `transition_post_status` into `publish` for a `production` assistant: when `eval_gate` requirement on, require `WP_MCP_AI_Eval_Gate::check()` → `passed` (base falls back to the W2 hook; no double-enforcement — document the single path).
   - On chat-request assembly: production assistants get `guardrails.enabled` forced true and `strictness => 'high'` in the effective harness profile (resolved copy only — never rewrites stored meta), via filter `wp_mcp_ai_effective_harness_profile` (new filter added in `WP_MCP_AI_Harness_Profile::get()`; default returns the stored profile unchanged).
   - Chat UI badge: `wp_mcp_ai_assistant_mode_badge` filter consumed by the shortcode/chat-client config (returns `''` unless production, then a translated "Production" badge string).
3. Prototype mode changes nothing except a translated "Prototype — not for live use" watermark via the same badge filter (opt-out filter for sites that dislike watermarks).

**File:** `includes/assistants/metaboxes/class-wp-mcp-ai-metabox-assistant-mode.php`

4. Mode radio metabox (Base metabox pattern from Task 1.3 step 4), with explanatory text linking to `docs/features/agentic-engineering-enhancements.md` (docs task below).

**Tests:** `tests/assistants/test-assistant-mode.php`

- Default `prototype`; enum sanitize; meta persists.
- Production publish without passing gate reverts (base path reuses W2 hook — integration test asserting single enforcement path).
- Effective profile forces guardrails for production, stored profile untouched.
- Badge filter outputs for both modes; opt-out respected.

---

### Task 3.2 — Skill economics metrics + dashboard panel (Base)

**File:** `includes/measurement/class-wp-mcp-ai-stock-metrics.php`

1. Two new definitions, following the existing shape (`id`, `label`, `type`, `unit`, `direction`, `privacy_tier => PRIVACY_INTERNAL`, `counter_metric`, `goodhart_note`, `otel_attribute`):
   - `skill.load.count` — counter, `DIRECTION_LOWER_IS_BETTER` (fewer loads = better context economy; `goodhart_note`: pairing with `chat.turn.error.count` prevents gaming by never loading skills), OTel `mcp_ai.skill.load.count`.
   - `skill.load.estimated_tokens` — histogram, `DIRECTION_LOWER_IS_BETTER`, OTel `mcp_ai.skill.load.estimated_tokens`.

**File:** `includes/tools/class-wp-mcp-ai-tool-load-skill.php`

2. In `execute()` after a successful load: guarded emission (`class_exists( 'WP_MCP_AI_Metric_Collector' )`) — `record( 'skill.load.count', 1, array( 'skill' => sanitized slug ) )` and `record( 'skill.load.estimated_tokens', estimated tokens, … )`. Estimation: tiktoken helper when loaded, else `ceil( strlen( $content ) / 4 )`. No emission on failure paths (only successful loads count as context spend).

**File:** `includes/admin/measurement/class-wp-mcp-ai-admin-measurement-dashboard.php`

3. New "Context economics" panel: sums `skill.load.estimated_tokens` over the selected window vs a hypothetical always-loaded baseline (sum of installed skills' sizes from the skill registry — metadata-only math, no provider calls), renders the savings delta + sparkline reusing the existing chart handle. Panel hidden when no skill metrics exist (no data → "No skill loads recorded yet" placeholder).

**Tests:** `tests/measurement/test-stock-metrics.php` (extend) + `tests/test-tool-load-skill.php` (extend)

- Definitions registered with counter pairing (registry audit passes).
- Successful load records both metrics; failed load records neither.
- Estimator: char fallback correct; tiktoken path preferred when available.
- Panel math: savings delta for a fixture window.

---

### Task 3.3 — Documentation & positioning

**File:** `docs/features/llm-harness.md`

1. New section "Why this architecture — industry alignment": cite the paper's harness equation (agent = model + harness), the Terminal Bench 2.0 top-30→top-5 harness-only result, and the LangChain +13.7 harness-tweak result; map Layers A–J + orchestration layer to the paper's six harness components; link this proposal pair.

**New file:** `docs/features/agentic-engineering-enhancements.md`

2. Feature doc for the shipped surface: trajectory verifier + suite authoring (with JSON examples), eval gate semantics (warn vs block, fail-closed filter), few-shot examples (profile keys, budget behaviour), assistant modes, routing tier presets, skill economics panel. Cross-link from `docs/reference/measurement/README.md` and `docs/QUICK_REFERENCE.md`.

**File:** `AGENTS.md`

3. Add one line under §1 noting the 035 proposal/plan pair as the owned scope for the gap-closure items (W1–W8), so other coding agents don't duplicate it.

**File:** `docs/project/proposals/README.md` (and `RELATED_PROPOSALS_INDEX.md` if it indexes numbered proposals)

4. Register the 035 pair.

---

## Cross-cutting: rollback, migrations, risks

**Rollback**

- Every new class is opt-in or inert without its profile/option/meta being set. Deleting the meta/option (`_wp_mcp_ai_examples`, `_wp_mcp_ai_assistant_mode`, `wp_mcp_ai_task_tier_presets`, profile `examples`/`evals_enabled` keys) fully restores prior behaviour — no schema changes anywhere.
- Gate enforcement filters default to permissive; flipping them back off removes the enforcement while audit history remains.

**Risks & mitigations**

| Risk | Mitigation |
|---|---|
| Gate blocks legitimate publishing (eval flakiness) | Fail-open on missing judges/suites; override capability + audit; enforcement opt-in per surface (Task 1.4) |
| Examples block inflates context cost | Hard caps (8 pairs), token-budget pre-flight, oldest-first drop, injector off by default |
| Trajectory verifier false-passes on partial traces | Deterministic criteria with explicit `on_missing_trajectory => fail`; suite authors opt into leniency |
| Routing tiers degrade quality on mis-mapped task types | Defaults documentation-only; suggestions human-approved via the proposer queue; per-surface opt-in |
| Metric noise (skill metrics) | Privacy tier `internal`; no prompt content; Goodhart pairing enforced by the registry audit |
| Prompt-injection via examples content | Store sanitizes on save AND read; injector output never bypasses the existing resolved-prompt guardrails (priority 20) |

**Definition of done (per task)**

1. PHPCS clean (`composer run lint`), PHP 7.4 compat (`composer run lint:compat`).
2. New/changed tests pass in `vendor/bin/phpunit` scoped to the affected files, then the full measurement/assistant groups.
3. Folder READMEs present for new folders; `composer run docs:check-folder-readmes` passes.
4. Docs cross-links resolved (`docs/DOCUMENTATION_INDEX.md` not required to change; QUICK_REFERENCE updated at release).

## Suggested PR breakdown (following the measurement-subsystem sequencing precedent)

| PR | Contents | Validation gate |
|---|---|---|
| 1 | Task 1.1 — trajectory verifier + runner passthrough | `tests/measurement/` green, existing eval suites unchanged |
| 2 | Task 1.2 — eval gate + CLI + publish hook | gate-check demo, PHPUnit |
| 3 | Task 1.3 — examples store/injector/metabox/profile keys | `tests/examples/`, manual metabox smoke |
| 4 | Task 1.4 — Pro enforcement wiring | Pro test group, manual publish override flow |
| 5 | Task 2.1 + 2.2 + 2.3 — groundedness + two rubric presets | Pro rubric suite + counterfactual run on new presets |
| 6 | Task 2.4 — routing tier presets + UI + proposer extension | `tests/test-task-tier-presets.php`, PSO regression group |
| 7 | Task 3.1 + 3.2 — assistant modes + skill economics | assistant-mode tests, stock-metric audit, dashboard smoke |
| 8 | Task 3.3 — docs | `composer run ci:all` (incl. folder-readme + doc checks) |

Each PR lands independently; no PR depends on an unreleased later PR.
