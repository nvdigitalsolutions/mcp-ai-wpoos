# Interfaces

## Purpose

Holds every PHP `interface` declaration the plugin exports — pure contracts with zero implementation — so that tool classes, services, and infrastructure adapters can depend on stable abstractions.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php`, and on-demand `require_once` from each implementer in `infrastructure/`, `tools/`, `services/` |
| **Optional dependencies** | none |

## Public Surface

Every file in this folder is part of the public surface — that is the whole point of the folder. Implementers live in `tools/`, `services/`, and `infrastructure/`.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Interface` (+ `_Shortcuts_`, `_Fallback_Shortcut_`, `_Capability_Flags_`) | `interface-wp-mcp-ai-tool.php` | every class in `tools/` |
| `Interface_WP_MCP_AI_Options_Store` | `interface-wp-mcp-ai-options-store.php` | `infrastructure/wp/`, `services/` |
| `Interface_WP_MCP_AI_HTTP_Client` | `interface-wp-mcp-ai-http-client.php` | `infrastructure/http/`, provider clients |
| `Interface_WP_MCP_AI_Capability_Checker` | `interface-wp-mcp-ai-capability-checker.php` | `infrastructure/wp/`, tool capability gates |
| `Interface_WP_MCP_AI_Provider_Client` | `interface-wp-mcp-ai-provider-client.php` | `infrastructure/providers/`, language-model router |
| `WP_MCP_AI_Embedding_Provider_Interface` | `interface-wp-mcp-ai-embedding-provider.php` | `services/embedding/`, vector context service |
| `WP_MCP_AI_Generic_Tool_Response` | `interface-wp-mcp-ai-generic-tool-response.php` | provider response normalizers |
| `WP_MCP_AI_Agent_Role_Interface` | `interface-wp-mcp-ai-agent-role.php` | `agents/`, multi-agent orchestrators |
| `WP_MCP_AI_Tool_Queue_Interface` | `interface-wp-mcp-ai-tool-queue.php` | async tool executor, RabbitMQ bridge |
| `WP_MCP_AI_Tool_Async_Metadata_Interface` | `interface-wp-mcp-ai-tool-async-metadata.php` | async orchestrator (pending-response payloads) |
| `WP_MCP_AI_Tool_Bulk_Operation_Interface` | `interface-wp-mcp-ai-tool-bulk-operation.php` | batch iterator, migration runner |
| `WP_MCP_AI_Tool_LLM_Sanitizer_Interface` | `interface-wp-mcp-ai-tool-llm-sanitizer.php` | chat service (tool-result sanitization) |
| `Interface_WP_MCP_AI_Cron_Status_Job_Source` | `interface-wp-mcp-ai-cron-status-job-source.php` | `services/job-sources/`, Tasks Drawer |

## Inputs / Outputs / Neighbors

- **Reads from:** nothing — these files declare contracts only.
- **Writes to:** nothing.
- **Upstream callers:** every folder that needs a stable abstraction — primarily `tools/`, `services/`, `infrastructure/`, `agents/`, `rest/`.
- **Downstream collaborators:** `infrastructure/wp/`, `infrastructure/http/`, `infrastructure/providers/`, `services/embedding/`, `services/job-sources/` provide the canonical concrete implementations.
- **Events fired:** none.
- **Events listened to:** none.

## Conventions

- Files contain **only** `interface` declarations, optional `phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound` annotations when related interfaces are co-located, and PHPDoc.
- **No** method bodies, no constants beyond what an interface legally allows, no `use` of traits, no static state. If you find yourself adding logic, you want a trait (`traits/`) or an abstract class instead.
- **No** WordPress API calls anywhere in this folder — interfaces describe abstractions that `infrastructure/` adapts to WordPress. See [`docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../docs/project/architecture-decisions/ADR_001_module_boundaries.md).
- Two naming forms are in active use and both are accepted:
  - `Interface_WP_MCP_AI_*` (prefixed) — newer adapter contracts (HTTP client, options store, provider client).
  - `WP_MCP_AI_*_Interface` (suffixed) — original tool/agent contracts.
  Don't rename existing interfaces; pick the form that matches the neighbors when adding a new one.

## Tests

Interfaces themselves are not unit-tested. Their contracts are exercised by tests of concrete implementations:

```bash
vendor/bin/phpunit tests/test-wp-options-store.php
vendor/bin/phpunit tests/test-wp-http-client.php
vendor/bin/phpunit tests/test-provider-client-adapters.php
vendor/bin/phpunit tests/test-huggingface-tools-interface-compliance.php
```

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — input/output rules implementers must satisfy
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — canonical tool envelope referenced by `WP_MCP_AI_Tool_Interface`
- [`docs/project/architecture-decisions/ADR_001_module_boundaries.md`](../../docs/project/architecture-decisions/ADR_001_module_boundaries.md) — why these contracts exist

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders: [`infrastructure/`](../infrastructure/) (canonical implementers), [`traits/`](../traits/) (shared mixin helpers), [`domain/`](../domain/) (pure-PHP types interfaces may reference)
