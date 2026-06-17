# Vector Storage

## Purpose

Houses 1 vector storage preparation tool: `prepare_file_for_vector_store` — converts uploaded files (PDF, DOCX, XLSX, CSV, PPTX, plain text) into chunked, cleaned text suitable for ingestion into OpenAI Vector Stores or other embedding backends.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry |
| **Optional dependencies** | None (PHP-native processing; COM/PhpSpreadsheet not required for basic formats) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Prepare_File_For_Vector_Store` | `class-wp-mcp-ai-tool-prepare-file-for-vector-store.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** Uploaded files (PDF, DOCX, XLSX, CSV, PPTX, TXT/MD/JSON/HTML); WordPress media attachments
- **Writes to:** Prepared text output (optionally stored as attachment); chunk preview metadata
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** WordPress media library (attachment creation); `WP_MCP_AI_Logger`
- **Events fired:** None explicit
- **Events listened to:** None

## Conventions

- Implements `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Processes files per format: PDF (text extraction), DOCX (ZIP+XML), spreadsheets (CSV natively; XLSX via structured text formatting), PPTX (presentation text), plain text (cleaning/normalization).
- Output includes chunked preview, byte counts, and format-specific metadata.
- Capability flags: `pro`, `local-only`, `read-only` (no state changes outside of optional attachment creation).

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/vector-storage/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
