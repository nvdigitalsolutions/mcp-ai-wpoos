# Conversation Import — User Guide

Import your conversation history from external AI services (OpenAI ChatGPT,
Google Gemini, Anthropic Claude, ShareGPT datasets, OpenAI fine-tuning JSONL)
into the NV oOS **AI Chat Transcripts** store. Imported conversations become
queryable, mineable into agent memory, and manageable through the same
retention and privacy controls as native chat transcripts.

> **Requires:** JetEngine (Custom Content Types module). The feature is part
> of the Full version; the Base version does not include it.

## Supported formats

| Format | Export mechanism | Payload |
|---|---|---|
| ChatGPT | Settings → Data Controls → Export data (email link) | `conversations.json` or the whole ZIP |
| Gemini | takeout.google.com → My Activity → **Gemini Apps** (not "Gemini", which exports Gems) | Activity JSON (or ZIP) |
| Claude | claude.ai → Settings → Privacy → Export data | `conversations.jsonl` |
| ShareGPT | Community tooling (oobabooga, exporters) | `*.json` / `*.jsonl` datasets |
| OpenAI fine-tuning | Platform / community tooling | `*.jsonl` datasets |

Files up to 128 MB are accepted by default (filter
`wp_mcp_ai_conversation_import_max_file_bytes`). JSONL datasets stream
line-by-line with per-line error reporting.

## Three ways to import

### 1. Admin page (recommended)

**Tools → NV oOS → Conversation Import.**

1. Upload a `.zip`, `.json`, or `.jsonl` export.
2. Review the detected format, estimated conversation count, and file size.
3. Choose options:
   - **Dry run** — count and validate without writing anything.
   - **Existing conversations** — *Skip* (default, idempotent re-imports) or
     *Refresh* (overwrite existing rows).
   - **Limit** — cap the number of conversations (0 = all).
   - **Sideload images** — copy ChatGPT export images into the media library
     and rewrite `[Image: …]` placeholders to attachment URLs.
4. Click **Import conversations**. Large imports run as background jobs; the
   page shows live progress and offers a downloadable JSON report when the
   run completes. Uploaded source files are deleted automatically after a
   successful run.

### 2. WP-CLI

```bash
# Inspect without importing.
wp mcp-ai conversation-import detect ./conversations.json

# Import (idempotent by default).
wp mcp-ai conversation-import import ./chatgpt-export.zip

# Import with options.
wp mcp-ai conversation-import import ./export.jsonl \
    --format=sharegpt --policy=refresh --limit=500 --sideload-media

# Resume an interrupted run.
wp mcp-ai conversation-import import 1234 --resume-token=import-20260820-abc123

# Check a running import.
wp mcp-ai conversation-import status import-20260820-abc123

# Delete imported conversations (dry-run first).
wp mcp-ai conversation-import delete chatgpt --dry-run
wp mcp-ai conversation-import delete gemini --limit=100
```

### 3. MCP tools (via an assistant)

| Tool | Purpose |
|---|---|
| `conversation_import_detect` | Inspect an export file and report format + estimated count |
| `conversation_import_run` | Import with dry-run, policy, limit, batch, sideload, resume options |
| `conversation_import_status` | Check a running import by token |
| `conversation_import_delete` | Delete imported rows by platform (dry-run supported) |

All four tools require the `manage_options` capability.

## How imported data is stored

- **One CCT row per conversation** in `ai_chat_transcripts`, keyed by
  `session_key = import-{platform}-{hash}`.
- Roles are normalised to `system` / `user` / `assistant` / `tool`.
- Timestamps are UTC Unix seconds; provenance (platform, source ID, title,
  model, import time, importer version) lives in the row metadata.
- Re-importing the same archive **skips** existing rows by default — safe to
  run repeatedly.

## Privacy, retention & deletion

- Imported rows carry the importing user ID, so WordPress personal-data
  export and erasure requests include them (a dedicated
  "NV oOS Imported AI Conversations" exporter/eraser provides source-specific
  fields).
- Retention sweeps (Settings → Orchestration) prune imported rows by age and
  per-user cap like any other transcript. CLI imports (`user_id = 0`) are
  treated as guest rows and use the shorter guest retention window.
- Delete imported data anytime via the `conversation_import_delete` tool or
  the CLI `delete` subcommand.

## Memory mining (optional, off by default)

Enable **Conversation Import → Mine memory** (setting
`conversation_import_mine_memory`) to feed freshly imported conversations
through the existing `mine_agent_memory` flow automatically. Records are
scoped to the virtual `import-miner` agent, chunked, and deduplicated by
content hash — the same verbatim discipline as manual mining. Imported
content is personal data; only enable this deliberately.
