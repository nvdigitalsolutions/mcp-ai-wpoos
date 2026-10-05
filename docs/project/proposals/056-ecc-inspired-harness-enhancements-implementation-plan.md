# Proposal 056 — Implementation Plan: ECC-Inspired Agent-Harness Enhancements

**Status:** Phase A in progress
**Date:** 2026-10-05
**Scope:** Phase A = core engine + base plugin. Phase B = Pro addon.
**Related:** [Proposal](056-ecc-inspired-harness-enhancements.md)

## Phase A — Core + Base (this PR)

### A1 — P1 Cascade router (core + legacy layers)

Files:

| File | Purpose |
|------|---------|
| `lib/core/src/Domain/Contract/ComplexityClassifierInterface.php` | Classify message lists into tiers with confidence |
| `lib/core/src/Domain/Contract/ResponseValidatorInterface.php` | Judge whether a tier-1 response is acceptable |
| `lib/core/src/Application/Provider/CascadeRouter.php` | Cascade orchestration wrapping `ProviderRouter` |
| `lib/core/tests/Unit/Application/Provider/CascadeRouterTest.php` | Unit coverage |
| `includes/class-wp-mcp-ai-cascade-executor.php` | **Legacy-layer cascade** — the live base+pro chat path does NOT go through lib/core; this gates `WP_MCP_AI_Language_Model_Router::create_chat_completion()` |
| `includes/class-wp-mcp-ai-language-model-router.php` | Cascade gate at the top of `create_chat_completion()` (one surgical edit covers every legacy caller: REST, CLI, tools, services) |
| `tests/test-cascade-executor.php` | Legacy executor + router-gate coverage |
| `addons/pro/includes/services/class-wp-mcp-ai-pro-jev-tier-routing.php` | Pro wiring: `wp_mcp_ai_cascade_classifier` filter backed by the existing Jev routing signal (proposal 045) + `map_signal_to_tier()` |
| `addons/pro/tests/test-pro-cascade-bridge.php` | Pro bridge coverage |
| `bin/check-chat-parity.php` | Parity row: legacy executor ↔ lib/core `CascadeRouter` |

Acceptance criteria:

- [x] Passthrough (no classifier) delegates to `ProviderRouter::chat()` unchanged.
- [x] Complex classification → primary provider, no tier-1 call.
- [x] Simple classification + acceptable validation → tier-1 result returned, stats record accepted.
- [x] Simple classification + unacceptable validation → escalation to primary, stats record escalated.
- [x] Tier-1 error → fail-open escalation to primary.
- [x] Tier-1 provider slug routing (`cascade_tier_1_provider`) and same-provider model override (`cascade_tier_1_model`) both work.
- [x] `getStats()` shape documented and asserted.
- [x] Legacy layer: same semantics via `WP_MCP_AI_Cascade_Executor` (inert unless `wp_mcp_ai_cascade_enabled` + configured + classified); streaming requests never cascade; bypass flag prevents re-entry; deterministic default validator; `wp_mcp_ai_cascade_decision` observability action.
- [x] Pro wiring: Jev routing signal → cascade tier mapping, fail-closed on neutral/unavailable signals.

Validation: `cd lib/core && composer test` (framework-agnostic PHPUnit, PHP 8.1+).

### A2 — P3 Hook profiles (base)

Files:

| File | Purpose |
|------|---------|
| `includes/hooks/README.md` | Folder purpose, public surface, `.context/` cross-refs (folder-readme convention) |
| `includes/hooks/class-wp-mcp-ai-hook-profiles.php` | Profile resolver + gates (`WP_MCP_AI_Hook_Profiles`) |
| `tests/test-hook-profiles.php` | Unit coverage |

Acceptance criteria:

- [ ] Rank ordering minimal < standard < strict; `allows()` gating correct in both directions.
- [ ] Resolution order: assistant meta → filter → option → constant → default `standard`.
- [ ] `WP_MCP_AI_HOOK_PROFILES_ENABLED` + filter master switch.
- [ ] `WP_MCP_AI_DISABLED_HOOKS` + `wp_mcp_ai_disabled_hooks` filter disable list.
- [ ] PHP 7.4 syntax (no enums / readonly / union types).
- [ ] No existing hook modified; zero behavior change when profiles unset.

Validation: Docker PHPUnit (WP 6.9 + WP 7.1), phpcs `phpcs.xml.dist`.

### A3 — P5 Session distiller (base)

Files:

| File | Purpose |
|------|---------|
| `includes/class-wp-mcp-ai-session-distiller.php` | Distiller + self-bootstrap (loader-pattern) |
| `tests/test-session-distiller.php` | Unit coverage |

Acceptance criteria:

- [ ] Subscribes to `wp_mcp_ai_chat_transcript_recorded` (priority 20, 7 args, legacy-shape tolerant per test-suite pattern 30).
- [ ] Opt-in gate default off; all gates filterable.
- [ ] Emits `wp_mcp_ai_memory_stored` with `context_type=session`, `memory_tier=episodic`, `wing=session`, `room=<assistant_id>`, `source=session_distiller`, deterministic `context_id`.
- [ ] Deterministic extractive summary contains user intent + outcome + tool-call count.
- [ ] Dedupe: one distillation per session key (transient).
- [ ] Bounded `wp_mcp_ai_session_summaries` option (default 100 entries, filterable).
- [ ] No provider call in the default path (zero-cost guarantee).

Validation: Docker PHPUnit (both WP versions), phpcs.

### A4 — P2 Eval harness tool (base)

Files:

| File | Purpose |
|------|---------|
| `includes/tools/class-wp-mcp-ai-tool-run-assistant-eval.php` | `run_assistant_eval` tool |
| `tests/test-run-assistant-eval-tool.php` | Unit coverage |
| `includes/class-wp-mcp-ai-tool-registry.php` | Registration map entry |
| `tests/tools/.coverage-manifest.txt` | Manifest entry (pattern 58) |
| `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php` | Preset membership (pattern 58) |

Acceptance criteria:

- [ ] `evaluate` mode: three dimensions scored separately; weights applied; pass/fail + verdict returned.
- [ ] Deterministic checks: expected-tool coverage, missing tools, empty response, repeated identical tool calls (trajectory error).
- [ ] `wp_mcp_ai_eval_judge` filter seam documented for semantic judging (Jev/LLM in Phase B).
- [ ] `list_cases` returns built-in cases + `wp_mcp_ai_eval_cases` filter contributions.
- [ ] Canonical envelope: success array via `format_success_response()`; failures are `WP_Error`.
- [ ] Two-gate sanitisation (Gate 1 entry, Gate 2 exit).
- [ ] Registered in tool registry + a preset + coverage manifest (CI gates).

Validation: Docker PHPUnit (both WP versions), phpcs, preset/manifest CI gates.

### A5 — P4 Security scan tool (base)

Files:

| File | Purpose |
|------|---------|
| `includes/tools/class-wp-mcp-ai-tool-scan-assistant-security.php` | `scan_assistant_security` tool |
| `tests/test-scan-assistant-security-tool.php` | Unit coverage |
| `includes/class-wp-mcp-ai-tool-registry.php` | Registration map entry |
| `tests/tools/.coverage-manifest.txt` | Manifest entry (pattern 58) |
| `includes/helpers/class-wp-mcp-ai-tool-presets-helper.php` | Preset membership (pattern 58) |

Acceptance criteria:

- [ ] Four check families: injection susceptibility, excessive agency, secret leakage, config hygiene.
- [ ] Every finding carries severity + evidence snippet + remediation.
- [ ] Posture score derived from weighted findings; advisory-only output.
- [ ] Missing/nonexistent assistant → `WP_Error` (not `success=false`).
- [ ] Canonical envelope + two-gate sanitisation.
- [ ] Registered in tool registry + a preset + coverage manifest (CI gates).

Validation: Docker PHPUnit (both WP versions), phpcs, preset/manifest CI gates.

## Phase B — Pro addon (next PR)

### B1 — Jev-backed cascade adapter (WP bridge)

- `includes/bridge/` adapter implementing `ComplexityClassifierInterface` +
  `ResponseValidatorInterface` over `WP_MCP_AI_Typesafe_Client`
  (proposal 039). Wire `CascadeRouter` into the WP provider pipeline with
  assistant meta keys (`_wp_mcp_ai_cascade_tier_1_model`,
  `_wp_mcp_ai_cascade_confidence_threshold`) + an admin metabox.
- Must satisfy `WPMCPAI.Decisions.ScopeDeclared` sniff (pattern 61):
  `@decision-domain` / `@decision-authority` docblocks +
  `WP_MCP_AI_Decision_Scope_Guard::gate()`.

### B2 — Semantic eval judging

- Wire `wp_mcp_ai_eval_judge` to `typesafe_decide` behind the scope guard;
  add an eval-case CPT + results history + Pro Schedule Manager integration
  for nightly regression runs.

### B3 — Hook profile admin UI

- Assistant metabox profile selector + Settings → Orchestration section for
  the default profile and disabled-hook list.

### B4 — Security scan surface

- Per-assistant posture score on the assistant edit screen; findings feed
  the site-level Security Posture as additive signals (open question 3).

### B5 — P6 Workflow suggestion miner

- `addons/pro/includes/harness/class-wp-mcp-ai-workflow-suggestion-miner.php`:
  mine `WP_MCP_AI_Harness_Trace_Store` + `wp_mcp_ai_session_summaries` for
  tool-chain n-grams; score frequency × recency × success-rate; cluster into
  candidate DAGs; expose via a Pro tool (`suggest_workflows_from_history`)
  and a Workflow Builder "Suggestions" panel with one-click import.
- Pro coverage manifest + preset updates (patterns 57/58).

## Validation gates (every phase)

1. `php -l` on every touched PHP file.
2. lib/core: `composer test` + `composer lint` (in `lib/core/`).
3. Base: Docker PHPUnit on WP 6.9 + WP 7.1 (per `mcp-ai-wpoos-test-suite`
   skill), phpcs with `phpcs.xml.dist` (errors only gate).
4. Registry gates: `test_tool_class_manifest_is_up_to_date`,
   `test_all_tools_accounted_for_in_presets` (pattern 58), ID-handoff
   contract suites if tool contracts are added.
5. Folder-readme check: `composer run docs:check-folder-readmes` (new
   `includes/hooks/` folder ships a README).
