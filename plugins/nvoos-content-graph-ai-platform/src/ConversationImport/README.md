# ConversationImport

## Purpose

Wave E4 port surface (sub-cluster 6). The conversation import
subsystem from the base plugin's `includes/conversation-import/` — 16
files (adapter interface, canonical conversation value object, format
detector, five platform adapters, archive preparation, media
sideloading, CCT writer, import manager, deleter, GDPR privacy
integration, memory-mining bridge, async queue bridge) — ported
byte-identically so the platform addon carries external conversation
imports (ChatGPT, Gemini, Claude, ShareGPT, OpenAI JSONL) in
standalone installs without the base plugin.

## Tier

| | |
|---|---|
| **Distribution** | Platform addon (`nvoos-content-graph-ai-platform`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAiPlatform\Plugin::registerConversationImport()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base loader requires all 16 files unconditionally and owns the two self-bootstrapping hooks in monolith installs |
| **Optional dependencies** | JetEngine (transcript CCT storage) and the base plugin's settings registry / memory-mining tool / async job queue — all monolith-only (`defined( 'WP_MCP_AI_PATH' )`-gated); standalone installs degrade with the documented `wp_mcp_ai_import_*` envelopes |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAiPlatform\ConversationImport\AdapterInterface` | `AdapterInterface.php` | `FormatDetector::is_adapter_instance()` |
| `NvoosContentGraphAiPlatform\ConversationImport\Conversation` | `Conversation.php` | Adapters, `CctWriter`, `Manager`, `Media` |
| `NvoosContentGraphAiPlatform\ConversationImport\FormatDetector` | `FormatDetector.php` | `Manager::run()` / `Manager::inspect()` |
| `NvoosContentGraphAiPlatform\ConversationImport\AdapterChatgpt` | `AdapterChatgpt.php` | `FormatDetector::default_adapters()` |
| `NvoosContentGraphAiPlatform\ConversationImport\AdapterGemini` | `AdapterGemini.php` | `FormatDetector::default_adapters()` |
| `NvoosContentGraphAiPlatform\ConversationImport\AdapterClaude` | `AdapterClaude.php` | `FormatDetector::default_adapters()` |
| `NvoosContentGraphAiPlatform\ConversationImport\AdapterSharegpt` | `AdapterSharegpt.php` | `FormatDetector::default_adapters()` |
| `NvoosContentGraphAiPlatform\ConversationImport\AdapterOpenaiJsonl` | `AdapterOpenaiJsonl.php` | `FormatDetector::default_adapters()` |
| `NvoosContentGraphAiPlatform\ConversationImport\Archive` | `Archive.php` | `Manager::run()` |
| `NvoosContentGraphAiPlatform\ConversationImport\Media` | `Media.php` | `Manager::run()` (sideload_media) |
| `NvoosContentGraphAiPlatform\ConversationImport\CctWriter` | `CctWriter.php` | `Manager` (injectable for tests) |
| `NvoosContentGraphAiPlatform\ConversationImport\Manager` | `Manager.php` | `QueueBridge::execute()`, admin tooling |
| `NvoosContentGraphAiPlatform\ConversationImport\Deleter` | `Deleter.php` | `Privacy::erase()`, admin tooling |
| `NvoosContentGraphAiPlatform\ConversationImport\Privacy` | `Privacy.php` | `Plugin::registerConversationImport()` (standalone-only bootstrap) |
| `NvoosContentGraphAiPlatform\ConversationImport\MemoryMiner` | `MemoryMiner.php` | `Plugin::registerConversationImport()` (standalone-only bootstrap) |
| `NvoosContentGraphAiPlatform\ConversationImport\QueueBridge` | `QueueBridge.php` | Queue workers, UI polling |

## Inputs / Outputs / Neighbors

- **Reads from:** the `wp_mcp_ai_conversation_import_checkpoint`
  option (resume state), uploads-scoped source paths, and (monolith)
  the `jet_cct_{SLUG}` transcript table via
  `WP_MCP_AI_JetEngine_CCT`.
- **Writes to:** CCT transcript rows (`import-{platform}` session
  keys, `import-{platform}` assistant IDs), the checkpoint option,
  sideloaded media attachments, and (monolith) the base audit log.
- **Upstream callers:** `Plugin::registerConversationImport()`; async
  queue workers; WordPress privacy exporter/eraser registries.
- **Downstream collaborators:** WordPress options/attachment/filesystem
  APIs; the base `WP_MCP_AI_JetEngine_CCT`, `WP_MCP_AI_Logger`,
  `WP_MCP_AI_Settings_Registry`, `WP_MCP_AI_Tool_Mine_Agent_Memory`,
  `WP_MCP_AI_Async_Job_Queue` classes (monolith-only,
  `defined( 'WP_MCP_AI_PATH' ) &&`-gated); this package's
  `Queues\AsyncJobQueue` (standalone).
- **Events fired:** `wp_mcp_ai_conversation_import_completed` (run
  report + user ID), `wp_mcp_ai_conversation_import_mined` (mining
  result + session keys).
- **Events listened to:** `wp_privacy_personal_data_exporters`,
  `wp_privacy_personal_data_erasers`, `admin_init` (privacy policy
  content), `wp_mcp_ai_default_settings`,
  `wp_mcp_ai_conversation_import_completed` (memory mining).

## Conventions

- **Per-mode discriminator is `defined( 'WP_MCP_AI_PATH' )`** — never
  bare `class_exists()` (the monorepo classmap resolves base classes
  standalone, where their storage surfaces do not exist).
- **Base-only collaborators degrade** — JetEngine CCT, settings
  registry, mining tool, logger, and async queue all gate on
  `defined( 'WP_MCP_AI_PATH' )`; standalone installs report the
  documented `wp_mcp_ai_import_jetengine_missing` /
  `wp_mcp_ai_import_mine_unavailable` / `wp_mcp_ai_import_queue_missing`
  envelopes.
- **Importer version resolves per mode** — base `WP_MCP_AI_VERSION`
  monolith, `NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION` standalone.
- Byte-identical constants/options/hook names/shapes with the base;
  deviations documented in the class docblocks (text domain, PSR-4
  class names, file-level self-bootstrap → explicit `bootstrap()`,
  `\WP_Error`/`\Throwable`/`\Exception`/`\ZipArchive` qualification).
- Standalone-only bootstrap registration — the base loader owns the
  same privacy + memory-mining hooks in monolith installs; the other
  14 classes are passive PSR-4 libraries.
- Manager batch processing keeps the reference `&$report` /
  `&$file_report` signatures and the 25/1/200 batch-size clamp.

## Tests

- `tests/test-conversation-import-core.php` — Conversation value
  object, adapters (ChatGPT, Gemini, Claude, ShareGPT, OpenAI JSONL),
  format detector, archive preparation, media sideloading.
- `tests/test-conversation-import-manager.php` — Manager run lifecycle
  (dry-run, policies, batches, limits, resume, checkpoint, inspect)
  with the stub writer.
- `tests/test-conversation-import-privacy-deleter-queue.php` —
  Deleter, Privacy, QueueBridge, MemoryMiner (per-mode seams).

```bash
vendor/bin/phpunit plugins/nvoos-content-graph-ai-platform/tests/test-conversation-import-core.php
vendor/bin/phpunit plugins/nvoos-content-graph-ai-platform/tests/test-conversation-import-manager.php
vendor/bin/phpunit plugins/nvoos-content-graph-ai-platform/tests/test-conversation-import-privacy-deleter-queue.php
```

## Also Load

- [`../Queues/README.md`](../Queues/README.md) — the standalone async
  job queue the `QueueBridge` resolves to
- [`../ContentAssistant/README.md`](../ContentAssistant/README.md) —
  the E4-4 sub-cluster (shared per-mode seam + README conventions)
- [`../README.md`](../README.md) — composition root + subsystem index
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — security (capability + nonce gates)

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E4 row status
- [`includes/conversation-import/`](../../../../includes/conversation-import/) — the base subsystem (the port's origin)
