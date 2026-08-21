# Conversation Import (`includes/conversation-import/`)

## Purpose

This folder houses the pipeline that imports external AI conversation exports
(OpenAI ChatGPT, Google Takeout Gemini) into the JetEngine `ai_chat_transcripts`
CCT — one CCT row per conversation — and nothing else.

## Tier

| | |
|---|---|
| **Distribution** | Base (loaded only when JetEngine is available — Full-version feature) |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` (JetEngine-available branches) |
| **Optional dependencies** | JetEngine (Custom Content Types module) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Conversation_Import_Conversation` | `class-wp-mcp-ai-conversation-import-conversation.php` | adapters, writer, manager, tests |
| `WP_MCP_AI_Conversation_Import_Adapter_Interface` | `interface-wp-mcp-ai-conversation-import-adapter.php` | detector, adapters, tests |
| `WP_MCP_AI_Conversation_Import_Adapter_Chatgpt` | `class-wp-mcp-ai-conversation-import-adapter-chatgpt.php` | detector (via adapter filter) |
| `WP_MCP_AI_Conversation_Import_Adapter_Gemini` | `class-wp-mcp-ai-conversation-import-adapter-gemini.php` | detector (via adapter filter) |
| `WP_MCP_AI_Conversation_Import_Adapter_Claude` | `class-wp-mcp-ai-conversation-import-adapter-claude.php` | detector (via adapter filter) |
| `WP_MCP_AI_Conversation_Import_Adapter_Sharegpt` | `class-wp-mcp-ai-conversation-import-adapter-sharegpt.php` | detector (via adapter filter) |
| `WP_MCP_AI_Conversation_Import_Adapter_Openai_Jsonl` | `class-wp-mcp-ai-conversation-import-adapter-openai-jsonl.php` | detector (via adapter filter) |
| `WP_MCP_AI_Conversation_Import_Media` | `class-wp-mcp-ai-conversation-import-media.php` | manager (sideload pass), tests |
| `WP_MCP_AI_Conversation_Import_Memory_Miner` | `class-wp-mcp-ai-conversation-import-memory-miner.php` | import-completion hook (self-bootstrapping) |
| `WP_MCP_AI_Conversation_Import_Archive` | `class-wp-mcp-ai-conversation-import-archive.php` | manager, tests |
| `WP_MCP_AI_Conversation_Import_Format_Detector` | `class-wp-mcp-ai-conversation-import-format-detector.php` | manager, tests |
| `WP_MCP_AI_Conversation_Import_CCT_Writer` | `class-wp-mcp-ai-conversation-import-cct-writer.php` | manager, tests |
| `WP_MCP_AI_Conversation_Import_Manager` | `class-wp-mcp-ai-conversation-import-manager.php` | import tools, WP-CLI, admin page, queue bridge, tests |
| `WP_MCP_AI_Conversation_Import_Deleter` | `class-wp-mcp-ai-conversation-import-deleter.php` | delete tool, privacy eraser, tests |
| `WP_MCP_AI_Conversation_Import_Privacy` | `class-wp-mcp-ai-conversation-import-privacy.php` | WordPress privacy tools (self-bootstrapping) |
| `WP_MCP_AI_Conversation_Import_Queue` | `class-wp-mcp-ai-conversation-import-queue.php` | `WP_MCP_AI_Async_Job_Queue` executor, admin page |
| `WP_MCP_AI_Conversation_Import_Admin` | `includes/admin/class-wp-mcp-ai-conversation-import-admin.php` | container (`admin.conversation_import`) |

## Inputs / Outputs / Neighbors

- **Reads from:** uploaded export files (ZIP/JSON) or media attachment paths;
  `ai_chat_transcripts` CCT rows (dedupe lookup); option
  `wp_mcp_ai_conversation_import_checkpoint` (resume state).
- **Writes to:** `ai_chat_transcripts` CCT rows via the JetEngine item handler
  (insert/update through the writer; delete through the deleter); the
  `wp_mcp_ai_job_queue` table via `WP_MCP_AI_Async_Job_Queue`; the checkpoint
  option during runs; a temp dir under uploads for ZIP extraction.
- **Upstream callers:** `includes/tools/class-wp-mcp-ai-tool-conversation-import-*.php`,
  `includes/class-wp-mcp-ai-cli-conversation-import-command.php`,
  `includes/admin/class-wp-mcp-ai-conversation-import-admin.php`,
  `includes/class-wp-mcp-ai-async-job-queue.php` (job type `conversation_import`).
- **Downstream collaborators:** `includes/class-wp-mcp-ai-jetengine-cct.php`
  (item handler + slug), `WP_MCP_AI_Async_Job_Queue` (jobs + progress),
  `WP_MCP_AI_Logger` (audit), `$wpdb` (dedupe/delete/privacy lookups),
  WordPress privacy exporters/erasers.
- **Events fired:** `wp_mcp_ai_conversation_import_completed` (report + user ID),
  `wp_mcp_ai_conversation_import_mined` (mining result + keys); filters
  `wp_mcp_ai_conversation_import_adapters`, `wp_mcp_ai_conversation_import_max_file_bytes`,
  `wp_mcp_ai_conversation_import_record`.
- **Events listened to:** `wp_mcp_ai_conversation_import_completed` (memory miner).

## Conventions

- Every adapter implements `WP_MCP_AI_Conversation_Import_Adapter_Interface` and
  registers itself through the `wp_mcp_ai_conversation_import_adapters` filter.
- Adapters never decode JSON themselves — the detector owns decoding and size guards.
- Canonical timestamps are UTC Unix seconds; roles are limited to
  `system` / `user` / `assistant` / `tool`.
- Imports are idempotent: dedupe key = platform + source ID + update time.

## Tests

PHPUnit suites for this folder:

```bash
vendor/bin/phpunit tests/test-conversation-import.php
vendor/bin/phpunit tests/test-conversation-import-phase2.php
vendor/bin/phpunit tests/test-conversation-import-phase3.php
vendor/bin/phpunit tests/test-conversation-import-phase4.php
```

JetEngine-dependent write paths are covered through the record-mapping test and
skipped/injected via a stub writer where JetEngine is unavailable; real CCT
integration coverage lives with the existing transcript-CCT tests.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — tool registration + envelope
- [`.context/pro-vs-base.md`](../../.context/pro-vs-base.md) — Base/Pro gating
- [`docs/project/plans/CONVERSATION-IMPORT-CCT-IMPLEMENTATION-PLAN.md`](../../docs/project/plans/CONVERSATION-IMPORT-CCT-IMPLEMENTATION-PLAN.md) — feature plan + decisions

## See Also

- Upstream parent: [`includes/`](../)
- Sibling folders worth knowing about: `includes/tools/` (tool surface), `includes/integrations/` (JetEngine integration seams)
