# Services

## Purpose

Hosts the plugin's business-logic layer — ~75 service classes that orchestrate repositories and infrastructure adapters to fulfil chat, tool execution, async jobs, memory, model discovery, telemetry, file processing, and multi-agent orchestration requests.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php`; constructed by `includes/class-wp-mcp-ai-container.php` and the `services/job-sources/job-sources-init.php` bootstrap |
| **Optional dependencies** | optional per-service (Gemini, OpenAI, Mubert, Action Scheduler, JetEngine); each service `class_exists()`-guards optional collaborators |

## Public Surface

The chat path and the tool execution path are the two services every other folder talks to most. Other services are entry points for specialised features.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Chat_Service` | `class-wp-mcp-ai-chat-service.php` | `rest/` chat controller, REST SSE handler, CLI |
| `WP_MCP_AI_Tool_Service` | `class-wp-mcp-ai-tool-service.php` | REST `/tools`, chat service, CLI |
| `WP_MCP_AI_Tool_Execution_Orchestrator` | `class-wp-mcp-ai-tool-execution-orchestrator.php` | tool service, async path |
| `WP_MCP_AI_Tool_Async_Executor` | `class-wp-mcp-ai-tool-async-executor.php` | WP-Cron, REST async polling |
| `WP_MCP_AI_Assistant_Service` | `class-wp-mcp-ai-assistant-service.php` | REST assistants, admin UI |
| `WP_MCP_AI_File_Service` (+ `_Factory`, Gemini/OpenAI variants) | `class-wp-mcp-ai-file-service*.php` | upload handlers, tools |
| `WP_MCP_AI_Memory_*` (capture, manager, tier manager) | `class-wp-mcp-ai-memory-*.php` | chat service, mine-memory tool |
| `WP_MCP_AI_Agent_Identity_Resolver` | `class-wp-mcp-ai-agent-identity-resolver.php` | store/recall memory tools, chat-memory REST controller (virtual-key → canonical agent ID bridging) |
| `WP_MCP_AI_Cost_Tracking_Service`, `WP_MCP_AI_Error_Tracking_Service`, `WP_MCP_AI_Performance_*`, `WP_MCP_AI_Token_*` | telemetry classes | chat service, admin dashboard, Pro Dashboard |
| `WP_MCP_AI_Cron_Status_Service` (+ `services/job-sources/`) | `class-wp-mcp-ai-cron-status-service.php` | REST Tasks Drawer, admin UI |
| `WP_MCP_AI_Vector_Context_Service` (+ `services/embedding/`) | `class-wp-mcp-ai-vector-context-service.php` | chat memory, semantic search tools |
| `WP_MCP_AI_Agent_Team_Orchestrator`, `WP_MCP_AI_Agent_Communication_Service`, `WP_MCP_AI_Agent_Context_Manager` | multi-agent classes | `agents/`, `teams/`, REST |
| `WP_MCP_AI_Gemini_Video_Generation_Service`, `WP_MCP_AI_Gemini_Music_Service`, `WP_MCP_AI_Mubert_Music_Service` | media services | media tools |
| `services/embedding/` | three `WP_MCP_AI_Embedding_Provider_*` implementations | vector context service |
| `services/job-sources/` | three `WP_MCP_AI_Job_Source_*` adapters | cron status service |
| `WP_MCP_AI_Speculative_Tool_Executor` | `class-wp-mcp-ai-speculative-tool-executor.php` | tool execution orchestrator |
| `WP_MCP_AI_Orchestration_Depth_Scheduler` | `class-wp-mcp-ai-orchestration-depth-scheduler.php` | tool execution orchestrator |
| `WP_MCP_AI_Hybrid_Plan_Generator` | `class-wp-mcp-ai-hybrid-plan-generator.php` | tool execution orchestrator |
| `WP_MCP_AI_Orchestration_Preset_Service` | `class-wp-mcp-ai-orchestration-preset-service.php` | admin settings UI |
| `WP_MCP_AI_DSpark_Hooks` | `class-wp-mcp-ai-dspark-hooks.php` | admin dashboard widgets |

Other classes in this folder (orchestration helpers, DSpark execution services, load balancers, monitors, validators) are also instantiated via the DI container; consult their PHPDoc before depending on them.

## Inputs / Outputs / Neighbors

- **Reads from:** repositories (`repositories/`), options-store adapter (`infrastructure/wp/`), provider clients (`infrastructure/providers/`), HTTP client (`infrastructure/http/`), domain constants (`domain/`), tool registry, transients/object cache.
- **Writes to:** repositories, options, transients, the transcripts table (via `WP_MCP_AI_Transcript_Repository`), WP-Cron schedules, Action Scheduler queue (when available), outbound HTTP (via provider clients), file uploads dir (via file services).
- **Upstream callers:** `rest/` controllers, `cli/` commands, `admin/` AJAX handlers, `tools/` (for shared orchestration helpers), `agents/`, `slash-commands/`.
- **Downstream collaborators:** every adapter in `infrastructure/`, every repository in `repositories/`, traits in `traits/` (notably `WP_MCP_AI_Inline_Async_Tick_Trait`), and the tool registry.
- **Events fired:** `wp_mcp_ai_inline_kick_completed` (via the inline-async tick trait), `wp_mcp_ai_cron_status_*` actions, plus subsystem-specific filters (chat, memory, tokens, orchestration depth, tiered routing, hybrid planning) documented per service.
- **Events listened to:** `wp_mcp_ai_cron_status_job_sources` (filter — job-source registration), WP-Cron hooks for async/cleanup jobs, `plugins_loaded` for late init.

## Conventions

- One service = one cohesive responsibility (chat, tools, file ingestion, memory, telemetry, etc.). When a service grows past ~1500 lines, extract collaborators rather than continuing to bolt on methods.
- Services consume `interfaces/` contracts (HTTP client, options store, capability checker, provider client) via constructor injection wherever practical. Backward-compatible direct WordPress calls are tolerated but should not be added to new services — extend an interface and inject instead.
- Subfolders are scoped role-specific implementations of an interface:
  - `embedding/` — `WP_MCP_AI_Embedding_Provider_Interface` implementations.
  - `job-sources/` — `Interface_WP_MCP_AI_Cron_Status_Job_Source` implementations + their init wiring.
- Services that participate in inline-async work must `use` the `WP_MCP_AI_Inline_Async_Tick_Trait` from `traits/` so the cron-disabled fallback and observability hook are wired uniformly.
- Long-running services should respect the data-budget tracker and the timeout-detection service rather than rolling their own limits.

## Tests

```bash
vendor/bin/phpunit --filter 'Service' tests/
vendor/bin/phpunit tests/test-service-tool.php
vendor/bin/phpunit tests/test-service-assistant.php
vendor/bin/phpunit tests/test-chat-service-pending-errors.php
vendor/bin/phpunit tests/test-chat-service-transcript-recording.php
vendor/bin/phpunit tests/test-cron-status-service.php
```

There are ~35 `tests/test-*service*.php` files plus integration coverage under `tests/rest/` and `tests/memory/`. Add a matching test file when introducing a new service.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — sanitisation/auth rules for service inputs
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — canonical tool envelope returned through the tool service
- [`.context/rest-api.md`](../../.context/rest-api.md) — service entry points exposed through REST
- [`.context/testing.md`](../../.context/testing.md) — service test patterns
- [`docs/ADR_001_module_boundaries.md`](../../docs/ADR_001_module_boundaries.md) — service ↔ repository ↔ infrastructure layering

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders: [`repositories/`](../repositories/), [`infrastructure/`](../infrastructure/), [`interfaces/`](../interfaces/), [`traits/`](../traits/), [`tools/`](../tools/) (services orchestrate tool execution), [`rest/`](../rest/) (services are the REST controllers' main collaborators)
