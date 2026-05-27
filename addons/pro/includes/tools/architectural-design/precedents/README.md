# Precedents

## Purpose

Houses 2 architectural precedent tools: CRUD management of built case studies (stored as `mcp_ai_arch_precedent` CPT with OpenAI embeddings) and cosine-similarity semantic search against the precedent library — part of the Architectural Design Toolkit.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry; `WP_MCP_AI_Architectural_Precedents_Engine` for embedding generation |
| **Optional dependencies** | `enable_architectural_design_toolkit` setting; OpenAI API for embeddings |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Manage_Architectural_Precedents` | `class-wp-mcp-ai-tool-manage-architectural-precedents.php` | tool registry |
| `WP_MCP_AI_Tool_Search_Architectural_Precedents` | `class-wp-mcp-ai-tool-search-architectural-precedents.php` | tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `mcp_ai_arch_precedent` CPT and its post meta (country, building type, embeddings, etc.)
- **Writes to:** `mcp_ai_arch_precedent` CPT (CRUD via manage tool); `_arch_prec_embedding` post meta (regenerated on create/update)
- **Upstream callers:** Pro tool registry, orchestrator
- **Downstream collaborators:** `WP_MCP_AI_Architectural_Precedents_Engine` (embedding regeneration, cosine similarity); `WP_MCP_AI_Architectural_Precedent_CPT` (post type definition)
- **Events fired:** None
- **Events listened to:** None

## Conventions

- Both tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Manage tool is `state-changing` (CRUD); search tool is `read-only`.
- Embeddings are regenerated automatically on every create/update via `WP_MCP_AI_Architectural_Precedents_Engine::regenerate_embedding_for_post()`.
- Precedent metadata includes country, building type, climate zone, sustainability rating, architect, year, area, and key features.

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architectural-design/precedents/
```

## Also Load

- [`.context/conventions.md`](../../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
- [`../README.md`](../README.md) — parent Architectural Design toolkit
