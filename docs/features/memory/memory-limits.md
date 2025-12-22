# Memory document streaming limits

WP oOS now reads assistant memory attachments incrementally to reduce peak memory usage. The controller stops reading from each file once enough bytes have been collected to satisfy the chunk budget that is forwarded to the language model.

## Default limits

| Limit | Value | Notes |
| ----- | ----- | ----- |
| Per document characters | `4,000` | Matches the existing `MEMORY_MAX_DOCUMENT_CHARS` constant. |
| Per document bytes | `262,144` | Provides room for markup-heavy formats while collecting the first 4K characters. Filterable via `wp_mcp_ai_memory_max_document_bytes`. |
| Aggregate characters | `12,000` | `MEMORY_MAX_TOTAL_CHARS`, unchanged. |
| Aggregate bytes | `1,048,576` | Default ~1MB budget that comfortably covers three 4K character documents. Filterable via `wp_mcp_ai_memory_max_total_bytes`. |

If the byte budget is exhausted before all attachments are processed, remaining files are skipped for that request. Documents that exceed their byte budget are truncated to the collected portion; chunk metadata is still produced so the model can reference the available text.

## Streaming behaviour

* Text files are read with `SplFileObject` in configurable chunks (`wp_mcp_ai_memory_read_chunk_bytes`) so only the first portion required for chunking is loaded into memory.
* DOCX files are parsed with `XMLReader` over the `zip://` stream wrapper, emitting paragraph breaks and tabs as they are encountered.
* PDF files continue to rely on `wp_read_pdf()` when available. The extracted text is trimmed to the remaining character budget.

Administrators can adjust the byte guards with the new filters if their infrastructure can tolerate larger reads, or tighten them for resource constrained environments. Because reads now stop once the budgets are satisfied, increasing the chunk limits may require higher PHP memory limits on smaller hosts.
