# Vector Storage Pro Tools

> Pro extensions for OpenAI-compatible vector stores (file_search / Assistants v2): file
> preparation, chunking and metadata enrichment for RAG workflows.

| | |
|---|---|
| **Activation** | Auto-loaded with the Pro add-on |
| **Tools** | 1+ (Pro extension over the base vector-store tools) |
| **Tool location** | `addons/pro/includes/tools/vector-storage/` |

---

## What it provides

The base plugin already ships file-management tools for OpenAI's `file_search` and
Assistants vector stores. This Pro module layers in tools that:

- Pre-process binary and structured files before upload to a vector store.
- Convert HTML to clean Markdown for higher-quality retrieval.
- Apply metadata extraction (titles, headings, tags) to improve filtering at query time.
- Aggregate multiple research sources into a single knowledge artifact.

Tools currently in this module:

| Tool slug | What it does |
|---|---|
| `prepare_file_for_vector_store` | Normalize a file (mime, encoding, chunk hints) so it ingests cleanly into an OpenAI vector store |

Companion tools that live in `addons/pro/includes/tools/` (top level) and are commonly
used together with this module:

- `convert_html_to_markdown`
- `extract_structured_data`
- `aggregate_research_data`
- `generate_research_report`
- `verify_information`

See [`docs/VECTOR_STORAGE_PRO_TOOLS.md`](../VECTOR_STORAGE_PRO_TOOLS.md) for the deeper
write-up.

---

## When to use it

- Building a knowledge base assistant that needs to ingest mixed-format content (PDF,
  HTML, CSV, transcripts).
- Improving retrieval quality by attaching consistent metadata to every chunk.
- Combining the Crawl4AI tool (in the base plugin) with these prep tools to feed a
  high-quality vector store from web content.

---

## Activation

No specific toggle is required — these tools register automatically when the Pro add-on
is loaded and the base vector-store / file-management settings are configured.

---

## Related docs

- [Pro Toolkits index](README.md)
- [`addons/pro/docs/VECTOR_STORAGE_PRO_TOOLS.md`](../VECTOR_STORAGE_PRO_TOOLS.md) — full design notes
- Base plugin: `docs/tool-reference.md` — file_search / vector-store base tools
