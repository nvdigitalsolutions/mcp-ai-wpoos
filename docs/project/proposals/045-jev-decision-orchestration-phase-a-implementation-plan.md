# Implementation Plan 045 — Decision-Model Orchestration Phase A: Semantic Tier Routing & Verify-Then-Escalate Cascade

**Status:** 🚧 Phases 0–2 implemented (Phase 2 stretch + Phase 3 docs housekeeping pending)
**Date:** 2026-09-28
**Proposal:** [`045-jev-decision-orchestration-phase-a.md`](./045-jev-decision-orchestration-phase-a.md)
**Branch:** `add/jev-decision-orchestration-phase-a` (PR against `alpha-working` per repo convention)

## Design decisions (locked)

1. **No new provider surface.** Everything lands inside the existing
   `Interface_WP_MCP_AI_Decision_Client` seam, the existing
   `WP_MCP_AI_Pro_Jev_Classifier` service, the `typesafe` settings subtab, and the
   existing tool/registry conventions. Chat-provider selection surfaces stay
   untouched (same exclusion list as plan 039 decision 2 / plan 040 decision 1).
2. **Jev supplies the signal; code owns every gate.** The decision model never picks
   a provider/model/tier directly. It feeds `route_with_tier()` /
   `determine_tier()` / the escalation threshold; all thresholds, allowlists, and
   fail-open paths remain PHP constants/filters.
3. **Both workstreams ship off by default.** `enable_jev_tier_routing` and
   `enable_jev_citation_escalation` default `false`. Enabling them changes behavior
   only for the `auto` tier path / the flagged-claim re-generation — explicit
   provider/model/tier selections always win.
4. **Routing signal contract.** New helper
   `WP_MCP_AI_Pro_Jev_Classifier::routing_signal_for( $messages, $options )` returns
   `array { task_type: string, complexity: float (0–2), needs_frontier: float (0–1),
   confidence: float (0–1) }` or the neutral fallback
   `array { task_type: 'general', complexity: 1.0, needs_frontier: 0.5,
   confidence: 0.65 }`. `confidence = (2 - complexity) / 2`, clamped to 0–1. If the
   complexity answer's own `confidence` is `< 0.5`, Jev is unavailable, or any call
   errors, return the neutral fallback (never steer the router on an uncertain
   signal). State sent to Jev = **first user message only** (reuses
   `extract_user_text()`), never tool results, secrets, or transcripts.
5. **Cascade battery contract.** Base service `WP_MCP_AI_Verification_Cascade`
   exposes:
   - `build_battery( array $record, array $field_specs, string $source_text ): array`
     — one noul question per `field::metric` pair (metrics:
     `hallucinated`, `off_target`, `incomplete`, `format_violation`,
     `unreasonable`), each framed **bad = true** with explicit
     `criteria.true` / `criteria.false` (structured EntryType fields per plan 040
     Phase 0 — already shipped). Empty fields get only the `absence_wrong` head.
   - `evaluate( array $battery, array $options ): array` — **one** batched decision
     call through the existing client (speculative fan-out), returning per-question
     probabilities + `fired` set via `max` aggregation against
     `wp_mcp_ai_cascade_escalate_threshold` (default `0.7`).
   - `run( ..., callable $escalate ): mixed` — invokes `$escalate` exactly once,
     bounded to a single rung; verifier errors and second-rung failures return the
     draft result unchanged (fail-open, never loop).
6. **Sanitisation.** Battery state passes the recursive two-gate walk already
   shipped for structured EntryType fields (strings via `sanitize_text_field`,
   arrays walked, keys `sanitize_key`, plain JSON only). Record field values follow
   the same walk before embedding in `state`.
7. **Capabilities.** No new tools land in this plan's core scope, so no new
   capability gates are introduced. If the extraction stretch lands, its tools
   follow the plan-040 decision 7 conventions (`manage_options`, canonical envelope,
   guidance interface, `ai_ml` preset, coverage manifest, `tool-status.txt`).
8. **Every new seam fails open** — identical pattern to
   `WP_MCP_AI_Pro_Jev_Classifier`: any Jev error, missing credential, or disabled
   kill-switch (`wp_mcp_ai_jev_classifier_enabled`) ⇒ unmodified pass-through to
   today's deterministic behavior, silent except for opt-in debug logging.

## Phase 0 — Base: verification cascade service (G5 foundation)

### 0.1 Changed files

| File | Change |
|---|---|
| `includes/services/class-wp-mcp-ai-verification-cascade.php` (new) | `WP_MCP_AI_Verification_Cascade` per design decision 5: `build_battery()` (noul-per-field decomposition, bad=true framing, explicit criteria), `evaluate()` (one batched call via `WP_MCP_AI_Typesafe_Client` / OpenRouter bridge through the existing transport resolver, `max` aggregation, filterable threshold `wp_mcp_ai_cascade_escalate_threshold`), `run()` (single-rung bounded escalation callback). No WordPress UI surface — service-only. |
| `includes/class-wp-mcp-ai-typesafe-client.php` | No contract change; verify only that `decide()` accepts the batched noul question map produced by the builder (structured criteria already supported post-040). If any gap appears (e.g., >N questions per call), the builder chunks per the existing state-size guard (mirror `filter_sources_by_relevance()` chunking). |
| `includes/class-wp-mcp-ai-plugin.php` (or the services loader) | Register the new service class for autoload where the other `includes/services/` classes are loaded. |

### 0.2 Tests

| File | Coverage |
|---|---|
| `tests/test-verification-cascade.php` (new) | Builder emits one noul per `field::metric`; empty field gets `absence_wrong` only; every question carries `criteria.true/false` with bad=true framing; `evaluate()` batches into one client call (`pre_http_request` counter) and `max`-aggregates — a single 0.95 flag fires while a 0.6 mean does not; threshold filter respected; `run()` invokes the escalation callback exactly once when a flag fires and zero times when clean; verifier `WP_Error` accepts the draft; escalation callback returning a draft on second failure terminates without looping; sanitisation walk strips HTML from structured state; chunking for oversized batteries. |

## Phase 1 — Pro: semantic tier routing signal (G1)

### 1.1 Changed files

| File | Change |
|---|---|
| `addons/pro/includes/services/class-wp-mcp-ai-pro-jev-classifier.php` | Add `routing_signal_for( $messages, $options = array() )` per design decision 4: reuses `classify_prompt()` internally, maps `complexity` to `confidence` in code, applies the complexity-confidence floor (0.5) → neutral fallback, honours the existing `is_available()` / kill-switch. |
| `addons/pro/includes/services/class-wp-mcp-ai-pro-jev-tier-routing.php` (new) | `WP_MCP_AI_Pro_Jev_Tier_Routing`: thin opt-in wiring helper. `maybe_apply( $task_type, $confidence, $tier, $options )` returns the input unchanged unless `enable_jev_tier_routing` is on **and** `$tier === 'auto'` **and** no explicit `provider`/`model` override exists; when active, computes the Jev signal and returns the enriched args. Hooks the existing `wp_mcp_ai_tiered_model_selection` filter (post-selection enrichment of the result metadata: `decision_model` key carrying task_type/confidence/needs_frontier, purely informational). |
| `includes/class-wp-mcp-ai-language-model-router.php` | No signature change. `route_with_tier()` gains nothing mandatory; the Jev signal arrives through callers passing `confidence`/`task_type` (see 1.2) and the informational metadata via the existing `wp_mcp_ai_tiered_model_selection` filter. |
| `addons/pro/includes/services/class-wp-mcp-ai-tool-execution-orchestrator-pro.php` (new, or filter registration in the Pro bootstrap) | Populates `$context['confidence']` in `execute_with_depth()` via a filter/hook when `enable_jev_tier_routing` is on and the caller left confidence unset — Jev signal feeds `WP_MCP_AI_Orchestration_Depth_Scheduler::determine_tier()`. Off ⇒ today's `0.0` default behavior. |
| `addons/pro/includes/admin/` (TypeSafe subtab extension) | New checkbox `enable_jev_tier_routing` (default off) with description noting: first-message-only state, ~150–320 ms per classification, advisory cache reuse, fail-open. |

### 1.2 Tests

| File | Coverage |
|---|---|
| `addons/pro/tests/test-pro-jev-tier-routing.php` (new) | `routing_signal_for()`: complexity 0 → confidence 1.0; complexity 2 → confidence 0.0; complexity-confidence < 0.5 → neutral 0.65; unavailable/error → neutral; state contains only the first user message (no tool results); no HTTP call when setting off; explicit provider/model/tier untouched; `wp_mcp_ai_tiered_model_selection` metadata carries `decision_model` only when enabled. |
| `tests/test-...` (orchestrator) | `execute_with_depth()` uses Jev confidence when enabled and caller left it unset; unchanged (`0.0`) when disabled; caller-supplied confidence always wins. |

## Phase 2 — Pro: cascade consumers (G5)

### 2.1 Consumer 1 — research/report per-claim cascade

| File | Change |
|---|---|
| `addons/pro/includes/services/class-wp-mcp-ai-pro-jev-classifier.php` | Extend `check_citations()` behind `enable_jev_citation_escalation` (default off): replace the single choice-per-citation with the battery from `WP_MCP_AI_Verification_Cascade` (claim + source passage as state; hallucinated/off_target per claim). Claims whose flags fire are queued for re-generation. |
| `addons/pro/includes/tools/orchestration/class-wp-mcp-ai-pro-tool-generate-research-report.php` + `eca-management/class-wp-mcp-ai-tool-research-eca.php` | Opt-in escalation step: re-generate flagged claims on the verification-tier model via the existing `get_verification_model_for_provider()` machinery (single rung, `run()` contract); failures keep the original claim (fail-open); envelope gains `escalated_claims` metadata when active. |

### 2.2 Consumer 2 (stretch) — structured-extraction cascade (CRM/medical)

| File | Change |
|---|---|
| `addons/pro/includes/services/class-wp-mcp-ai-pro-jev-extraction.php` (new) | SDE pattern per the TypeSafe cookbook: draft-tier extraction (existing CRM/medical tooling) → `build_battery()` against the source document → escalate on any flag. Gated by toolkit-owner sign-off (plan-040 stretch item) and its own opt-in setting. Deferred in this PR unless owners approve. |

### 2.3 Tests

| File | Coverage |
|---|---|
| `addons/pro/tests/test-pro-jev-cascade-research.php` (new) | Citation battery builds per-claim heads; escalation fires only above threshold; re-generation invoked once per fired claim; verifier error keeps original claims; envelopes unchanged when setting off. |
| `addons/pro/tests/test-pro-jev-extraction.php` (stretch) | Draft → verify → escalate round-trip on synthetic CRM records; fabricated fields flagged (`hallucinated` high), honest empty fields not (`absence_wrong` low); single-rung bound. |

## Phase 3 — Docs & housekeeping

- `docs/features/ai-providers/typesafe.md`: new sections — routing signal
  (`enable_jev_tier_routing`, signal contract, privacy note), verification cascade
  (battery metrics, threshold filter, single-rung bound), research per-claim
  escalation.
- `docs/project/proposals/README.md` / `RELATED_PROPOSALS_INDEX.md`: register 045
  (and the audit doc) per convention.
- `CHANGELOG.md` entry at merge time (v1.1.88+ window) per the standard format.
- `.context/tool-registry.md` and `.context/security-checklist.md` windows note the
  new opt-in seams (no new tool slugs unless the stretch lands; stretch tools follow
  the full registration checklist).

## Explicitly NOT changed

- Chat-provider selection surfaces (dropdowns, onboarding wizard, default-provider
  validation, router/dispatcher maps) — unchanged from plan 039 decision 2.
- `lib/core` `ProviderRouter` / `ChatOrchestrator` strategy objects (review G2) —
  Phase D of the review; blocked on Phase C governance (eval gate + shadow mode).
- Tool-call risk gates, loop stagnation checks, skill/compaction loop wiring
  (review G3/G4/G8) — later phases.
- Shadow mode + threshold store (review G6/G7) — Phase C; **precondition** before
  any site-wide default change to tier routing.
- NV Cloud passthrough, Content Graph port cluster — unchanged deferred items from
  plans 040/039.
- No schema removal or renaming: every existing `typesafe_decide` /
  `typesafe_guardrail` / `typesafe_rerank` call keeps working.
