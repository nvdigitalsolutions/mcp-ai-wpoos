# Harness

## Purpose

The LLM Harness subsystem — the nine opt-in harness layers (Profile, Prompt cues, Reasoning/Self-consistency, Tool routing, Retrieval, Self-Refine, PII filter, Eval scheduling, Guardrails, Necessity Gate) plus the Meta-Harness (Trace Store, Trace Capture, Search Engine, Auto-Deploy, Population) and the Artifact Evolution classes (Admission Gate, Approval Queue, Deploy, Failure Replay, Learning Log, Lineage, Mutator, Population, Shadow, Verification Gate, Evolution Governor, Settings Bridge). Every layer is behaviour-preserving by default — each is gated by a per-assistant harness profile that ships in the "off" state.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Harness\HarnessService` (via `Plugin::register()`) |
| **Optional dependencies** | Base plugin `mcp-ai-wpoos` (monolith mode — its `includes/harness/harness-init.php` owns runtime wiring) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\Harness\HarnessService` | `HarnessService.php` | `Plugin::register()` — composition root; wires the eight self-registering subscribers in standalone mode |
| `NvoosContentGraphAiPlatform\Harness\HarnessProfile` | `HarnessProfile.php` | every layer, admin profile surfaces |
| `NvoosContentGraphAiPlatform\Harness\PiiFilter` | `PiiFilter.php` | Self-Refine reflections, trace/learning-log scrubbing, admission gate |
| `NvoosContentGraphAiPlatform\Harness\PromptCueLibrary` | `PromptCueLibrary.php` | `HarnessPromptInjector`, harness tools |
| `NvoosContentGraphAiPlatform\Harness\ReasoningTrace` | `ReasoningTrace.php` | self-consistency vote tool, eval scheduler |
| `NvoosContentGraphAiPlatform\Harness\ToolRouterHarness` | `ToolRouterHarness.php` | chat-service tool ranking, two-stage RRF fusion |
| `NvoosContentGraphAiPlatform\Harness\RetrievalHarness` | `RetrievalHarness.php` | `retrieve_with_provenance` tool, memory consumers |
| `NvoosContentGraphAiPlatform\Harness\SelfRefineLoop` | `SelfRefineLoop.php` | chat service (when enabled), `record_reflection` tool |
| `NvoosContentGraphAiPlatform\Harness\HarnessPromptInjector` | `HarnessPromptInjector.php` | self-registers a chat-client subscriber |
| `NvoosContentGraphAiPlatform\Harness\HarnessEvalScheduler` | `HarnessEvalScheduler.php` | self-registers the WP-Cron eval handler |
| `NvoosContentGraphAiPlatform\Harness\Guardrails` | `Guardrails.php` | self-registers system-prompt + pre-screen subscribers (Layer I) |
| `NvoosContentGraphAiPlatform\Harness\NecessityGate` | `NecessityGate.php` | self-registers the tool-execution filter (Layer J) |
| `NvoosContentGraphAiPlatform\Harness\OutputGuardrail` | `OutputGuardrail.php` | self-registers the response-render filter |
| `NvoosContentGraphAiPlatform\Harness\CitationVerifier` | `CitationVerifier.php` | self-registers the citation-checking filter |
| `NvoosContentGraphAiPlatform\Harness\HarnessTraceStore` / `HarnessTraceCapture` / `HarnessSearchEngine` / `HarnessAutoDeploy` / `HarnessPopulation` | `HarnessTraceStore.php`, `HarnessTraceCapture.php`, `HarnessSearchEngine.php`, `HarnessAutoDeploy.php`, `HarnessPopulation.php` | Meta-Harness observe/analyze/optimize loop |
| `NvoosContentGraphAiPlatform\Harness\Artifact*` + `EvolutionGovernor` + `EvolutionSettingsBridge` | `Artifact*.php`, `EvolutionGovernor.php`, `EvolutionSettingsBridge.php` | Artifact Evolution phases B–G |

## Inputs / Outputs / Neighbors

- **Reads from:** assistant post meta `_wp_mcp_ai_harness_profile` (JSON), `wp_mcp_ai_harness_profile_default` option, trace files under `wp-content/uploads/mcp-ai-harness-traces/`, the population/learning-log/approval-queue options (`wp_mcp_ai_artifact_population_global`, `wp_mcp_ai_artifact_learning_log`, `wp_mcp_ai_artifact_approval_queue`), evolution transients (`wp_mcp_ai_evolution_*`)
- **Writes to:** the same meta keys, options, and transients, WP-Cron schedule (`wp_mcp_ai_harness_eval_tick`), `wp_mcp_ai_discovered_cues` option
- **Upstream callers:** `Plugin::register()` (via `HarnessService`), chat service, harness tools, admin surfaces
- **Downstream collaborators:** base-plugin measurement subsystem (`Eval_Suite_Registry`, `Eval_Runner`, `Eval_Run_Store`, `Artifact_Replay_Verifier` — monolith only, probe-gated), base-plugin tool registry/safety profiles (monolith only, probe-gated)
- **Events listened to:** chat-client message lifecycle, `wp_mcp_ai_before_tool_execute`, `wp_mcp_ai_pre_response_render`, `wp_mcp_ai_resolved_system_prompt`, `wp_mcp_ai_pre_chat_message`, WP-Cron `wp_mcp_ai_harness_eval_tick`, the `wp_mcp_ai_harness_evolution_*` filter family
- **Base-plugin degradation:** every cross-subsystem dependency on the base plugin is gated by `defined( 'WP_MCP_AI_PATH' ) &&` probes. In standalone mode: `HarnessEvalScheduler::run_suite_for_assistant()` returns `WP_Error` (`wp_mcp_ai_harness_eval_unavailable`), `NecessityGate::evaluate()` passes calls through (no base tool pipeline to gate), `ArtifactFailureReplay` falls back to the literal `artifact_replay` verifier slug, and `ArtifactDeploy` regression/drift checks short-circuit to non-actionable reports

## Conventions

- Ported 1:1 from `mcp-ai-wpoos/includes/harness/` (extraction Wave C). All option keys, transient prefixes, meta keys, cron hooks, and `wp_mcp_ai_*` filter/action names are unchanged — data stability is sacred (extraction plan §3). Only class names move into the `NvoosContentGraphAiPlatform\Harness` namespace.
- Class renaming map: `WP_MCP_AI_<Name>` → camelCase (e.g. `WP_MCP_AI_Guardrails` → `Guardrails`, `WP_MCP_AI_Harness_Trace_Store` → `HarnessTraceStore`).
- Cross-references to classes that are NOT ported (measurement/evals, tool registry, `Agent_Harness_Evolver`, logger, request context, settings registry) keep their global name with a `\` prefix and a `defined( 'WP_MCP_AI_PATH' ) &&`-gated probe — the monorepo root classmap can autoload base classes even when the base plugin is inactive, and bare `class_exists()` absence tests would fatal on `WP_MCP_AI_PATH` references inside those files.
- Probes for ported sibling classes use `class_exists( __NAMESPACE__ . '\X' )` — `class_exists()` does not namespace-resolve unqualified string names.
- `WP_MCP_AI_Logger::log_event()` calls become the per-class `self::log_event()` shim (base logger when present, `error_log` fallback in standalone). The base `WP_MCP_AI_Inline_Async_Tick_Trait` is copied as `InlineAsyncTickTrait` (same namespace) so `HarnessEvalScheduler` does not hard-depend on base autoloading in standalone installs.
- Behaviour-preserving by default: every layer must default to *off* in `HarnessProfile::defaults()`.
- Text domain: `nvoos-content-graph-ai-platform`.

## Tests

```bash
# Monolith matrix (base plugin loaded)
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Harness

# Standalone matrix (base plugin skipped)
WP_MCP_AI_PLATFORM_STANDALONE=1 vendor/bin/phpunit -c plugins/nvoos-content-graph-ai-platform/phpunit.xml.dist --filter Harness
```

## See Also

- Plan: `docs/project/plans/content-graph-platform-extraction-plan.md`
- Tracker: `MIGRATION-GAPS.md`
- Base sources: `includes/harness/`, `includes/traits/trait-wp-mcp-ai-inline-async-tick.php`
