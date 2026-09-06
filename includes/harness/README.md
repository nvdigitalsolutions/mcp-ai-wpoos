# Harness — LLM Harness Layers A–J + Meta-Harness

## Purpose

Implements the nine opt-in LLM harness layers (Profile, Prompt cues, Reasoning/Self-consistency, Tool routing, Retrieval, Self-Refine, PII filter, Eval scheduling, Guardrails, Necessity Gate) that wrap provider calls with rate-limit, retry, telemetry, and reflection behaviour — plus the Meta-Harness auto-optimization subsystem (Trace Store, Trace Capture, Search Engine, Auto-Deploy, Population) that observes, analyzes, and self-optimizes AI agent execution.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | [`includes/harness/harness-init.php`](./harness-init.php) — pulled in from `includes/bootstrap/loader.php` |
| **Optional dependencies** | none — every layer is behaviour-preserving when the assistant's `Harness_Profile` keeps it disabled (the default) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Harness_Profile` | `class-wp-mcp-ai-harness-profile.php` | every layer, [`includes/assistants/metaboxes/class-wp-mcp-ai-metabox-harness-profile.php`](../assistants/metaboxes/), [`includes/services/`](../services/) |
| `WP_MCP_AI_PII_Filter` | `class-wp-mcp-ai-pii-filter.php` | Self-Refine reflections, reward-signal writes, agent-memory writes |
| `WP_MCP_AI_Prompt_Cue_Library` | `class-wp-mcp-ai-prompt-cue-library.php` | `Harness_Prompt_Injector`, the `list_prompt_cues` / `select_prompt_cue` / `apply_prompt_cue` tools |
| `WP_MCP_AI_Reasoning_Trace` | `class-wp-mcp-ai-reasoning-trace.php` | Self-consistency vote tool, eval scheduler |
| `WP_MCP_AI_Tool_Router_Harness` | `class-wp-mcp-ai-tool-router-harness.php` | chat service tool ranking, Pro learned-routing override, **two-stage RRF fusion with the attention router** (since 1.8.0) |
| `WP_MCP_AI_Retrieval_Harness` | `class-wp-mcp-ai-retrieval-harness.php` | `retrieve_with_provenance` tool, memory consumers |
| `WP_MCP_AI_Self_Refine_Loop` | `class-wp-mcp-ai-self-refine-loop.php` | chat service (when enabled), `record_reflection` tool |
| `WP_MCP_AI_Harness_Prompt_Injector` | `class-wp-mcp-ai-harness-prompt-injector.php` | self-registers a chat-client subscriber on load |
| `WP_MCP_AI_Harness_Eval_Scheduler` | `class-wp-mcp-ai-harness-eval-scheduler.php` | self-registers a WP-Cron handler on load |
| `WP_MCP_AI_Guardrails` | `class-wp-mcp-ai-guardrails.php` | self-registers as system-prompt + pre-screen subscriber on load |
| `WP_MCP_AI_Necessity_Gate` | `class-wp-mcp-ai-necessity-gate.php` | self-registers as tool-execution filter on load; 3-tier gating (safe-allowlist → necessity → irreversibility) |
| `WP_MCP_AI_Harness_Trace_Store` | `class-wp-mcp-ai-harness-trace-store.php` | Meta-Harness: persists execution telemetry with queryable indexes |
| `WP_MCP_AI_Harness_Trace_Capture` | `class-wp-mcp-ai-harness-trace-capture.php` | Meta-Harness: hooks into tool pipeline to record calls, duration, tokens, errors |
| `WP_MCP_AI_Harness_Search_Engine` | `class-wp-mcp-ai-harness-search-engine.php` | Meta-Harness: full-text search + faceted filtering across traces |
| `WP_MCP_AI_Harness_Auto_Deploy` | `class-wp-mcp-ai-harness-auto-deploy.php` | Meta-Harness: pushes approved optimizations to production with rollback |
| `WP_MCP_AI_Harness_Population` | `class-wp-mcp-ai-harness-population.php` | Meta-Harness: batch-processes historical traces through the proposer |
| `WP_MCP_AI_Artifact_Failure_Replay` (static) | `class-wp-mcp-ai-artifact-failure-replay.php` | Artifact Evolution B.2 (since 1.9.0): distills failed tool calls from trace runs into eval cases / an artifact-scoped replay suite |
| `WP_MCP_AI_Artifact_Verification_Gate` (static) | `class-wp-mcp-ai-artifact-verification-gate.php` | Artifact Evolution B.3 (since 1.9.0): post-mutation verification — runs incumbent vs candidate generators over a suite; `improve` / `no_regression` modes |
| `WP_MCP_AI_Artifact_Population` (static) | `class-wp-mcp-ai-artifact-population.php` | Artifact Evolution C (since 1.9.0): site-global archive of scored artifact variants with lineage; Imbue-style parent sampling (sigmoid fitness × novelty, dynamic percentile midpoint) |
| `WP_MCP_AI_Artifact_Mutator` (static) | `class-wp-mcp-ai-artifact-mutator.php` | Artifact Evolution D (since 1.9.0): failure-driven, learning-log-aware, and crossover prompt mutators + line-diff helper |
| `WP_MCP_AI_Artifact_Learning_Log` (static) | `class-wp-mcp-ai-artifact-learning-log.php` | Artifact Evolution D (since 1.9.0): differential record of past mutations (diff + score delta) with lineage-neighborhood retrieval |
| `WP_MCP_AI_Artifact_Admission_Gate` (static) | `class-wp-mcp-ai-artifact-admission-gate.php` | Artifact Evolution E (since 1.9.0): pre-commit VaG gate — structural validity, behavioral harmlessness, and marginal-gain critics; reject/skip/admit verdicts |
| `WP_MCP_AI_Artifact_Deploy` (static) | `class-wp-mcp-ai-artifact-deploy.php` | Artifact Evolution F (since 1.9.0): gated promotion of prompt/skill variants with holdout evidence, rollback, append-only audit trail, and drift detection with opt-in auto-rollback |
| `WP_MCP_AI_Artifact_Shadow` (static) | `class-wp-mcp-ai-artifact-shadow.php` | Artifact Evolution F (since 1.9.0): session-hash shadow A/B serving of registered variants with bounded serve stats |
| `WP_MCP_AI_Evolution_Governor` (static) | `class-wp-mcp-ai-evolution-governor.php` | Artifact Evolution G (since 1.9.0): unified hourly budget + per-path rate limits + site-wide mutation cap across every mutation path (`can_mutate` / `record_mutation` / `record_spend` / `get_report`) |
| `WP_MCP_AI_Artifact_Approval_Queue` (static) | `class-wp-mcp-ai-artifact-approval-queue.php` | Artifact Evolution G (since 1.9.0): human approval queue for `promote` and `rollback` items; approve executes via the Phase F deploy class; TTL + caps + decision actions |
| `WP_MCP_AI_Artifact_Lineage` (static) | `class-wp-mcp-ai-artifact-lineage.php` | Artifact Evolution G (since 1.9.0): ancestry/descendant graph payloads, root walk, ASCII tree, and canonical prompt content addressing (`hash_for`) |
| `WP_MCP_AI_Evolution_Settings_Bridge` (static) | `class-wp-mcp-ai-evolution-settings-bridge.php` | Artifact Evolution UI wiring (since 1.9.0): applies the self-evolution switches saved on Settings → Orchestration Layer to the runtime evolution filters at priority 5 (unsaved settings never override filter defaults) |

The seven harness tools (`list_prompt_cues`, `select_prompt_cue`, `apply_prompt_cue`, `self_consistency_vote`, `retrieve_with_provenance`, `record_reflection`, `scope_memory`) live in [`includes/tools/harness/`](../tools/harness/) and are registered from this folder's init via `wp_mcp_ai_register_tools`.

Layer I (Guardrails) does not ship its own tools — it hooks into the existing chat pipeline via the `wp_mcp_ai_resolved_system_prompt` filter (to inject guardrail instructions) and the `wp_mcp_ai_pre_chat_message` filter (to pre-screen messages before they reach the LLM).

Layer J (Necessity Gate) hooks into the tool execution pipeline via the `wp_mcp_ai_before_tool_execute` filter (priority 5) and gates every tool call against a necessity × irreversibility decision matrix. Safe read-only tools auto-pass. High-irreversibility tools require human approval when necessity is low. See `docs/features/necessity-gate.md` for the full decision matrix.

## Inputs / Outputs / Neighbors

- **Reads from:** assistant post meta `_wp_mcp_ai_harness_profile` (JSON), agent memory CCT (via `Retrieval_Harness`), measurement subsystem (reliability scores for tool routing), `wp_mcp_ai_harness_eval_generator` filter for eval generation.
- **Writes to:** agent-memory CCT (verbal reflections after PII scrubbing), telemetry spans via the OTEL exporter, WP-Cron schedule (`Eval_Scheduler::register()`).
- **Upstream callers:** [`includes/services/`](../services/) chat service (per-assistant gating on `Harness_Profile`), [`includes/assistants/metaboxes/`](../assistants/metaboxes/) (`Metabox_Harness_Profile`), [`includes/tools/harness/`](../tools/harness/).
- **Downstream collaborators:** [`includes/agents/`](../agents/) Critic role (default critic callable for `Self_Refine_Loop`), [`includes/measurement/`](../measurement/) (reads reliability data, writes spans), [`includes/tools/`](../tools/) registry (registers harness tools).
- **Events fired:** `wp_mcp_ai_harness_tool_score` (filter — Pro override point, now includes `$attention_score` param since 1.8.0), `wp_mcp_ai_harness_rrf_weight_harness` / `wp_mcp_ai_harness_rrf_weight_attention` (filter — tune RRF stage weights per task class since 1.8.0), `wp_mcp_ai_harness_eval_generator` (filter), `wp_mcp_ai_pii_filter_patterns` (filter — extra redaction patterns), `wp_mcp_ai_guardrail_violation` (action — Layer I, fired when a guardrail violation is detected), `wp_mcp_ai_necessity_gate_enabled` (filter — per-request override for Layer J), `wp_mcp_ai_necessity_gate_verdict` (filter — modify the gating verdict), `wp_mcp_ai_necessity_gate_blocked` (action — Layer J, fired when a tool call is blocked), `wp_mcp_ai_necessity_gate_warned` (action — Layer J, fired when a call gets a warning), `wp_mcp_ai_necessity_gate_classify` (filter — override the necessity classification), `wp_mcp_ai_register_tools` listener at priority 30.
- **Artifact evolution events (since 1.9.0):** `wp_mcp_ai_artifact_replay_case_rules` (filter — per-case replay rules), `wp_mcp_ai_artifact_verification_mode` (filter — `improve`\|`no_regression`), `wp_mcp_ai_artifact_verification_decision` (filter — final accept/reject override), `wp_mcp_ai_harness_verification_enabled` (filter — opt-in verification on the Continual Harness evolver, default false), `wp_mcp_ai_harness_verification_on_no_cases` (filter — `skip`\|`reject` when replay data is missing), `wp_mcp_ai_register_verifiers` (action at priority 10 — registers the `artifact_replay` verifier), `wp_mcp_ai_artifact_population_sharpness` / `wp_mcp_ai_artifact_population_percentile` / `wp_mcp_ai_artifact_population_novelty_weight` / `wp_mcp_ai_artifact_population_min_weight` / `wp_mcp_ai_artifact_population_max` (filters — selection tuning + cap), `wp_mcp_ai_artifact_population_weights` (filter — final weight map before sampling), `wp_mcp_ai_artifact_mutator_temperature` (filter — mutation temperature, default 0.7), `wp_mcp_ai_artifact_learning_log_max` (filter — learning-log retention cap), `wp_mcp_ai_artifact_admission_validators` / `wp_mcp_ai_artifact_admission_critics` (filters — extra structural validators / custom critics), `wp_mcp_ai_artifact_admission_max_chars` (filter — prompt length cap), `wp_mcp_ai_artifact_admission_mode` (filter — `strict`\|`net_gain` marginal-gain rule), `wp_mcp_ai_artifact_admission_on_no_evidence` (filter — `skip`\|`reject`\|`admit`), `wp_mcp_ai_artifact_admission_decision` (filter — final verdict override), `wp_mcp_ai_artifact_population_per_assistant_max` (filter — per-assistant pool cap, default 25), `wp_mcp_ai_artifact_deploy_require_holdout` (filter — fail-closed holdout requirement, default true), `wp_mcp_ai_artifact_deploy_auto_rollback` (filter — opt-in drift-triggered rollback, default false), `wp_mcp_ai_artifact_shadow_enabled` (filter — opt-in shadow serving, default false), `wp_mcp_ai_artifact_shadow_percentage` (filter — shadow arm size 0–100, default 10), `wp_mcp_ai_artifact_shadow_session_key` (filter — bucketing key, default user ID + session token), `wp_mcp_ai_evolution_governor_enabled` (filter — governor master switch, default true), `wp_mcp_ai_evolution_governor_rate_limit` (filter — per-path hourly mutation limit, default 60), `wp_mcp_ai_evolution_governor_site_max_mutations` (filter — site-wide hourly cap, default 0 = unlimited), `wp_mcp_ai_evolution_governor_paths` (filter — registered mutation paths), `wp_mcp_ai_artifact_approval_ttl` (filter — queue item TTL), `wp_mcp_ai_artifact_approval_queued` / `wp_mcp_ai_artifact_approval_decided` (actions — queue lifecycle). The `WP_MCP_AI_Evolution_Settings_Bridge` subscribes to `wp_mcp_ai_harness_evolution_enabled`, `wp_mcp_ai_harness_use_evolved_prompt`, `wp_mcp_ai_skill_registry_include_evolved`, `wp_mcp_ai_harness_evolution_budget_usd`, `wp_mcp_ai_evolution_governor_rate_limit`, `wp_mcp_ai_harness_evolution_warmup`, and `wp_mcp_ai_harness_verification_enabled` at priority 5, feeding them from saved Settings → Orchestration Layer values (unsaved settings never override the code defaults).
- **Events listened to:** chat-client message lifecycle (`Harness_Prompt_Injector`, `Guardrails`), `wp_mcp_ai_before_tool_execute` (`Necessity_Gate`, priority 5), WP-Cron `wp_mcp_ai_harness_eval_run` (`Eval_Scheduler`), `wp_mcp_ai_register_tools`.
- **Filters subscribed to (Guardrails):** `wp_mcp_ai_resolved_system_prompt` (priority 20 — injects guardrail instructions), `wp_mcp_ai_pre_chat_message` (priority 10 — pre-screens user messages).

## Conventions

- **Behaviour-preserving by default.** Every new layer must default to *off* in `Harness_Profile::defaults()`. A site administrator opts an assistant in; the harness must never enable itself.
- Hard caps live in `Harness_Profile` constants (`MAX_REASONING_SAMPLES`, `MAX_REFINE_ITERATIONS`, `DEFAULT_COST_CEILING_USD`) and are enforced by the loops — never let an assistant profile override the constant upward.
- **Always pass free-form text through `WP_MCP_AI_PII_Filter::scrub()` before persisting** to agent memory, reflections, or telemetry. False positives are acceptable; secret leakage is not.
- `Self_Refine_Loop` is provider-agnostic — generator and critic are plain callables. Do not introduce a provider dependency here; provider wiring belongs in the chat service.
- Retrieval is **defensive**: every underlying source (`recall_memory`, `semantic_context_search`, `retrieve_agent_memory`) may be absent. Wrap each in an error boundary and merge what survives — never error the harness because one source is missing.
- The cron handler is a no-op until at least one assistant has `evals_enabled` set; do not invert that gate.

## Tests

```bash
vendor/bin/phpunit tests/test-harness-services.php
vendor/bin/phpunit tests/test-harness-profile-metabox.php
vendor/bin/phpunit tests/test-harness-prompt-cue-library.php
vendor/bin/phpunit tests/test-harness-prompt-injector.php
vendor/bin/phpunit tests/test-harness-eval-scheduler.php
vendor/bin/phpunit tests/test-harness-eval-scheduler-inline-kick.php
```

## Also Load

- [`docs/features/llm-harness.md`](../../docs/features/llm-harness.md) — the full Layers A–I reference (authoritative)
- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — PII / secret handling (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — the seven harness tools live in `includes/tools/harness/`
- [`CLAUDE.md`](../../CLAUDE.md) — PHP-compat and canonical tool envelope
- [`includes/data/README.md`](../data/README.md) — upstream attention router that feeds semantic scores into Layer C (since 1.8.0)
- [`docs/features/meta-harness-auto-optimization.md`](../../docs/features/meta-harness-auto-optimization.md) — full Meta-Harness Phases 0-7 reference

## See Also

- Sibling: [`agents/`](../agents/) — Critic role is the default critic for `Self_Refine_Loop`
- Sibling: [`tools/harness/`](../tools/harness/) — the public tool surface of this subsystem
- Sibling: [`assistants/metaboxes/`](../assistants/metaboxes/) — `Metabox_Harness_Profile` is the admin UI for the per-assistant profile
- Sibling: [`measurement/`](../measurement/) — reliability inputs and OTEL span outputs
