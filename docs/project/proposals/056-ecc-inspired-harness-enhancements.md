# Proposal 056 — ECC-Inspired Agent-Harness Enhancements

**Status:** ✅ Proposal approved — implementation underway (Phase A in this PR)
**Date:** 2026-10-05
**Scope:** Core engine (`lib/core/`, PHP 8.1+) + Base plugin (`includes/`, PHP 7.4). Pro follow-ups (`addons/pro/`) in Phase B.
**Related:** [Research summary](056-ecc-inspired-harness-enhancements-implementation-plan.md) · Proposal 039 (TypeSafe Jev decision provider) · Proposal 045 (Jev decision orchestration) · Meta-Harness Auto-Optimization (v1.1.40) · MemPalace Capture Framework
**Inspiration:** [affaan-m/ECC](https://github.com/affaan-m/ECC) (MIT) — "plan → test → implement → review → verify → remember → improve" agent-harness operating system.

## Summary

NV oOS is already a mature agent harness: BMAD agent definitions (`.bmad/`),
load-on-demand context engineering (`.context/`), a framework-agnostic core
(`lib/core/` with `ChatOrchestrator`, `ProviderRouter`, `CompactionProvider`),
the MemPalace memory framework (wings / rooms / tiers, bi-temporal recall),
the Meta-Harness trace system, and 12 security classes. Studying ECC against
industry research (FrugalGPT cascades, LLM-as-judge evaluation standards,
OWASP LLM Top 10 2026, tiered-memory literature) surfaced six concrete gaps —
not re-implementations of ECC, but the runtime concepts ECC layers on top of
its own harness, expressed as NV oOS idioms.

This proposal adopts five of them into the base plugin + core engine and
specifies a sixth for the Pro addon:

| # | Feature | Layer | Ships in |
|---|---------|-------|----------|
| P1 | Cascade model routing (cheap-model-first, judge-verified escalation) | `lib/core` | Phase A |
| P2 | Assistant evaluation harness (trajectory judges + rubric scoring) | Base tool | Phase A |
| P3 | Hook profiles (minimal / standard / strict, per-assistant + runtime toggles) | Base | Phase A |
| P4 | Per-assistant security scan (OWASP 2026-informed config audit) | Base tool | Phase A |
| P5 | Session distillation (session-end summary → MemPalace recall at session start) | Base | Phase A |
| P6 | Workflow suggestions from execution history (instincts → workflows) | Pro addon | Phase B |

Each feature is additive, fails closed, and reuses existing seams — no
breaking changes to the 60+ public hooks, the canonical tool envelope, or the
provider contracts.

## Motivation / Gap Analysis

### P1 — Cascade model routing

`ProviderRouter` (lib/core) routes assistant → provider with health-based
failover, and `WP_MCP_AI_Language_Model_Router` (base) applies provider
priority lists. Neither implements **complexity-based cascading** — the
FrugalGPT / RouteLLM / AutoMix pattern where a cheap model answers the easy
majority and a frontier model handles the hard minority. Industry results put
cascade pipelines at up to 98% cost reduction with most requests answered in
under half a second. NV oOS already owns every component needed: 13 provider
clients, the TypeSafe Jev decision client (proposal 039) as a classifier/stop
judge, and `WP_MCP_AI_Cost_Tracker` to prove the savings.

### P2 — Assistant evaluation harness

BMAD defines QA roles and workflows, and the Meta-Harness proposes
optimizations from traces, but there is **no rubric-based evaluation of an
assistant's trajectory** (tool selection / tool use / response quality as
separate concerns). 2026 LLM-as-judge standards: separate judges per concern
(never one mega-judge), decompose rubrics into discrete checks, keep
deterministic gates for hard rules, validate against human baselines.
Proposal 039 shipped Jev precisely for rubric-style judgments.

### P3 — Hook profiles

The plugin fires 60+ documented lifecycle hooks. ECC's insight: hooks are
*executable configuration*, so they need profile-based gating and runtime
toggles (`ECC_HOOK_PROFILE`, `ECC_DISABLED_HOOKS`) instead of being
all-or-nothing. NV oOS has no way to run a lightweight assistant on a
minimal hook profile or disable a noisy subscriber per assistant.

### P4 — Per-assistant security scan

The OWASP LLM Top 10 2026 ranks **Prompt Injection #1** and **Excessive
Agency #3** (driven by tool/MCP usage). The site-level Security Posture (21
signals) and the destructive-ops gate address runtime behavior, but nothing
audits an *assistant's configuration itself*: injection-prone system
prompts, destructive tool grants, secrets pasted into prompts, oversized
tool assignments.

### P5 — Session distillation

Chat transcripts persist (24h localStorage + CCT) and MemPalace stores
memories, but **nothing automatically distills a finished session into
durable memory** and recalls it at the next session start. ECC's session
summaries + memory vault, expressed through the existing
`wp_mcp_ai_chat_transcript_recorded` → `wp_mcp_ai_memory_stored` →
`recall_memory` pipeline.

### P6 — Workflow suggestions from history (Pro)

ECC's "instincts" (patterns learned from sessions with confidence scores,
clustered into reusable skills). NV oOS's analogue: mine the Meta-Harness
trace store for repeated tool chains and offer them as one-click Pro
Workflow Builder DAGs. Depends on P5-style structured history; deferred to
Phase B so Phase A lands reviewable first.

## Design

### P1 — CascadeRouter (lib/core, framework-agnostic)

```
CascadeRouter (new, Application/Provider)
├── wraps ProviderRouter (passthrough when disabled or unconfigured)
├── ComplexityClassifierInterface  (new contract) — classify(messages, options)
│     → { tier: 'simple'|'complex', confidence: 0..1, reason: string }
├── ResponseValidatorInterface     (new contract) — validate(response, messages, options)
│     → { acceptable: bool, confidence: 0..1, reason: string }
└── stats: requests, routed_simple, accepted, escalated, est_savings
```

Flow for `chat()`:

1. Disabled / no classifier → `ProviderRouter::chat()` passthrough (zero
   behavior change).
2. Classify. Complex → primary provider immediately.
3. Simple → tier-1 model (`cascade_tier_1_model` on the same provider, or
   `cascade_tier_1_provider` for a different client). Tier-1 error →
   escalate to primary (fail-open).
4. Validate the tier-1 response. Unacceptable → escalate to primary and
   record; acceptable → return tier-1 result and record savings.

Configuration lives in `assistantConfig` / `options` keys
(`cascade_tier_1_model`, `cascade_tier_1_provider`,
`cascade_confidence_threshold`) so the WordPress bridge can expose it as
assistant meta later. The classifier and validator are constructor-injected
contracts — the WP adapter will supply a Jev-backed implementation
(proposal 039 client) in a follow-up; until then the router is inert.

### P1-legacy — Cascade on the live base+pro path

The live chat path (`WP_MCP_AI_REST` →
`WP_MCP_AI_Language_Model_Router::create_chat_completion()`) does **not** go
through lib/core, so the framework-agnostic router alone would be inert in
the plugin. `WP_MCP_AI_Cascade_Executor` (base) brings the same cascade to
that path: a gate at the top of `create_chat_completion()` consults the
executor, which is inert unless `wp_mcp_ai_cascade_enabled` is on, a
classifier filter supplies a verdict, and a tier-1 target is configured.
Streaming requests never cascade; tier-1 calls carry a bypass flag so the
gate cannot re-enter itself; a deterministic default validator
(error shapes, empty content) works without Pro. The Pro addon wires the
existing Jev routing signal (proposal 045) into
`wp_mcp_ai_cascade_classifier` via `WP_MCP_AI_Pro_Jev_Tier_Routing::cascade_classifier()`
with code-owned tier thresholds. `wp_mcp_ai_cascade_decision` fires with
accepted/escalated outcomes for audit and cost-tracker subscribers.

### P2 — `run_assistant_eval` base tool

Two modes:

- `evaluate` — score a supplied execution trace (user message, tool calls,
  final response) against a rubric. Three **separate** dimensions, each with
  its own checks: `tool_selection`, `tool_use`, `response_quality`. Each
  check has an optional weight (default 1). Deterministic core (selected
  tools vs `expected_tools`, argument presence, empty-response detection)
  plus a filter seam `wp_mcp_ai_eval_judge` for semantic/Jev judging
  (Phase B wiring). Returns per-check, per-dimension and overall scores,
  pass/fail, and a human-readable verdict.
- `list_cases` — list stored eval cases (Phase B: case library CPT; Phase A
  returns the built-in case registry + filter `wp_mcp_ai_eval_cases`).

Canonical envelope + two-gate sanitisation. Capability: `manage_options`.

### P3 — Hook profiles (base)

`WP_MCP_AI_Hook_Profiles` (`includes/hooks/`):

- Profiles ranked `minimal (0) < standard (1) < strict (2)`.
- `allows( $hook, $assistant_id, $required_profile )` — profile rank ≥
  required rank passes. Subscribers adopt it voluntarily (opt-in
  discipline): `if ( ! WP_MCP_AI_Hook_Profiles::allows( ... ) ) return;`
- Resolution order: assistant meta `_wp_mcp_ai_hook_profile` →
  `wp_mcp_ai_hook_profile` filter → site option
  `wp_mcp_ai_default_hook_profile` → `WP_MCP_AI_HOOK_PROFILE` constant →
  `standard`.
- Master switch: `WP_MCP_AI_HOOK_PROFILES_ENABLED` constant +
  `wp_mcp_ai_hook_profiles_enabled` filter (default on).
- Per-hook disable list: `WP_MCP_AI_DISABLED_HOOKS` constant (comma list,
  ECC's env-var pattern) + `wp_mcp_ai_disabled_hooks` filter, consulted by
  `is_disabled( $hook, $assistant_id )`.

No existing hook changes its timing or arguments; this is a pure advisory
gate. PHP 7.4-safe (no enums).

### P4 — `scan_assistant_security` base tool

Deterministic audit of an assistant's configuration via
`WP_MCP_AI_Assistant_CPT::get_assistant_configuration()`:

1. **Prompt-injection susceptibility** — config-level bait patterns in the
   system prompt (unconditional obedience clauses, "ignore all previous
   instructions" placeholders, instructions to execute user-supplied
   content, missing guardrail references).
2. **Excessive agency** — destructive-tool grants (capability-flag
   `destructive`), tool-count ceiling, admin-class tools on the assistant.
3. **Secret leakage** — API-key / token patterns inside the system prompt
   (`sk-…`, `api_key =`, `Bearer …`).
4. **Configuration hygiene** — missing provider, empty system prompt,
   temperature outliers.

Returns a structured report with per-finding severity, a posture score, and
remediation strings. All checks deterministic and unit-tested. Capability:
`manage_options`.

### P5 — Session distiller (base)

`WP_MCP_AI_Session_Distiller` subscribes to
`wp_mcp_ai_chat_transcript_recorded` (priority 20, 7 args) and, when enabled,
distills the finished session into MemPalace:

- Gates (all filterable): `wp_mcp_ai_session_distill_enabled` (default
  **false** — opt-in, avoids surprise provider calls), minimum message
  count (default 4), one distillation per session key (transient), guest
  sessions excluded.
- Distillation: deterministic extractive summary by default (user intents,
  assistant outcome, tool-call count, topic hints) — zero provider cost.
  Filter seam `wp_mcp_ai_session_distill_summarizer` lets Pro/advanced
  setups substitute an LLM summarizer.
- Emits the canonical `wp_mcp_ai_memory_stored` event
  (`context_type=session`, `memory_tier=episodic`, `wing=session`,
  `room=<assistant_id>`, `source=session_distiller`,
  `context_id=session_<hash>`), so the existing CCT bridge, Graphify bridge
  and `recall_memory` hydration all pick it up with zero new storage code.
- Also records a bounded `wp_mcp_ai_session_summaries` option entry as a
  JetEngine-free fallback and for the Phase B miner.

### P6 — Workflow suggestions (Pro, Phase B)

Miner over the Meta-Harness trace store + session summaries: extract
recurring tool-chain n-grams, score by frequency × recency × success rate,
cluster into candidate DAGs, and surface them in the Pro Workflow Builder
with one-click import. Full design in the implementation plan (§Phase B).

## Non-goals

- Re-implementing ECC's coding-agent surface (BMAD + `.context/` + 62 skills
  already cover that role).
- Changing any existing public hook signature or timing.
- Auto-enabling P1/P5 provider calls — both default to passthrough/off.
- Pro UI for P2/P3/P4 in this proposal (Phase B).

## Risk assessment

| Risk | Mitigation |
|------|------------|
| Cascade routes to a weaker model and degrades answers | Judge verification + confidence threshold + fail-open escalation; disabled by default |
| Eval tool misused as a cost loop | Evaluate mode never calls a provider; judge seam is explicit and filter-gated |
| Hook profiles break existing subscribers | Pure opt-in gate; no default behavior change; master switch off restores legacy behavior |
| Session distiller spams the CCT | Per-session dedupe transient + opt-in flag + bounded fallback option |
| Security scan false positives | Findings carry severity + evidence snippets; report is advisory, never blocking |

## Open questions

1. Default tier-1 model per provider — defer to the WP adapter follow-up;
   propose `gpt-4o-mini` / `gemini-flash` equivalents per provider family.
2. Eval case library storage — CPT (Phase B) vs Paper Store records?
3. Should `scan_assistant_security` findings feed the site-level Security
   Posture score? (Phase B; propose as additive signals.)
