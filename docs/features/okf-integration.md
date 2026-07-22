# OKF Integration — Open Knowledge Format v0.1

**Status:** Implemented (Phases 1–5)
**Merged:** July 20, 2026 (PR #5719)
**Tier:** Base

---

## 1. What Is OKF?

Google Cloud's **Open Knowledge Format (OKF v0.1)** is an Apache 2.0-licensed, vendor-neutral
specification for AI agent knowledge bases. An OKF bundle is a directory of markdown files with
YAML frontmatter — each file is a **concept** (policy, procedure, API reference, metric, playbook,
etc.) — and concepts link to each other via standard markdown links, forming a navigable knowledge
graph.

OKF is **not a replacement** for vector databases or RAG. It is the complement:

| Problem with RAG-everything | How OKF Solves It |
|---|---|
| Chunking destroys document structure | Concepts are whole documents with explicit cross-links |
| Vector retrieval is probabilistic | Link-based navigation is deterministic |
| "Why was this retrieved?" is opaque | Every link is human-readable and auditable |
| Every vendor has their own knowledge-graph schema | OKF is vendor-neutral; only a `type` field is required |

**Recommended architecture:** Use OKF for curated, authoritative knowledge (~20%) and the vector
store (Tool Embedding Store + Attention Router) for unstructured data (~80%).

---

## 2. Architecture

```
┌─────────────────────────────────────────────────────┐
│                   Knowledge Layer                    │
│  ┌──────────────┐  ┌──────────────┐  ┌────────────┐ │
│  │  OKF Bundles  │  │ Vector Store │  │Paper Store │ │
│  │  (markdown)   │  │(embeddings)  │  │  (JSON)    │ │
│  └──────┬───────┘  └──────┬───────┘  └─────┬──────┘ │
└─────────┼─────────────────┼────────────────┼────────┘
          │                 │                │
     ┌────▼─────────────────▼────────────────▼────┐
     │          Hybrid Knowledge Router            │
     │   (routes query → OKF / RAG / Paper Store)  │
     └────────────────────┬───────────────────────┘
                          │
     ┌────────────────────▼───────────────────────┐
     │         Consumption (Chat, REST, Tools)     │
     └────────────────────────────────────────────┘
```

---

## 3. File Structure

```
includes/
├── okf/                                    # OKF engine (Base tier)
│   ├── README.md                           # Folder context
│   ├── okf-init.php                        # Bootstrap (priority 32)
│   ├── class-wp-mcp-ai-okf-parser.php      # YAML frontmatter parser
│   ├── class-wp-mcp-ai-okf-reader.php      # Bundle reader/traverser
│   └── class-wp-mcp-ai-okf-writer.php      # Bundle writer/validator
│
└── tools/
    └── okf/                                # OKF MCP tools (Base tier)
        ├── README.md
        ├── class-wp-mcp-ai-tool-okf-read-concept.php
        ├── class-wp-mcp-ai-tool-okf-browse.php
        ├── class-wp-mcp-ai-tool-okf-traverse.php
        ├── class-wp-mcp-ai-tool-okf-search.php
        ├── class-wp-mcp-ai-tool-okf-write-concept.php
        └── class-wp-mcp-ai-tool-okf-delete-concept.php

wp-content/uploads/mcp-ai-wpoos/knowledge/   # Runtime OKF bundles
├── skill-knowledge/                         # Auto-generated from bundled skills
├── site-knowledge/                          # Site-specific curated knowledge
└── external-bundles/                        # Imported third-party OKF bundles
```

---

## 4. OKF Engine Components

### 4.1 Parser (`WP_MCP_AI_OKF_Parser`)

A pure-PHP YAML frontmatter parser that handles only the subset of YAML needed for OKF
frontmatter: scalars, lists, and key-value pairs. No external YAML library dependency.
This keeps the Base tier dependency-free.

### 4.2 Reader (`WP_MCP_AI_OKF_Reader`)

Navigates an OKF directory tree:
- `get_concept( $concept_id )` — reads frontmatter + body from a concept file
- `traverse( $concept_id, $depth )` — follows cross-links up to N hops
- `browse( $path )` — reads `index.md` for directory listing (progressive disclosure)
- `search_by_type( $type )` / `search_by_tag( $tag )` — type/tag-based discovery

### 4.3 Writer (`WP_MCP_AI_OKF_Writer`)

Creates and updates OKF concepts:
- `write_concept( $path, $frontmatter, $body )` — atomic writes via `WP_MCP_AI_Filesystem_Service`
- `regenerate_index( $path )` — rebuilds `index.md` after concept changes
- `validate_bundle( $path )` — conformance check per OKF spec §9

### 4.4 Bootstrap

Loaded via `includes/bootstrap/loader.php` at priority 32 (after Paper Store at 30).
Single-line hook: `add_action( 'wp_mcp_ai_bootstrapped', 'wp_mcp_ai_okf_init', 32 );`

### 4.5 Events

| Hook | Fires When |
|------|-----------|
| `wp_mcp_ai_okf_bundle_initialized` | A new bundle directory is created |
| `wp_mcp_ai_okf_concept_saved` | A concept is created or updated |
| `wp_mcp_ai_okf_concept_deleted` | A concept is archived/deleted |

---

## 5. MCP Tools (6)

All 6 tools follow the established tool pattern: two-gate sanitization, canonical return envelope,
and proper capability checks.

| Tool Slug | Function | Capability |
|-----------|----------|------------|
| `okf_read_concept` | Read a single concept by path or concept ID | `read` |
| `okf_browse` | Browse a bundle directory via `index.md` | `read` |
| `okf_traverse` | Follow cross-links from a concept (configurable depth) | `read` |
| `okf_search` | Search concepts by type, tag, or full-text | `read` |
| `okf_write_concept` | Create or update a concept | `edit_posts` |
| `okf_delete_concept` | Archive a concept (moves to `.archive/`) | `delete_posts` |

### Example: Reading a Concept

```
User: "What is our refund policy?"
Assistant calls: okf_read_concept( path = "site-knowledge/policies/refunds.md" )
Returns: frontmatter { type: Policy, tags: [billing, support] } + markdown body
```

### Example: Traversing Cross-Links

```
User: "Show me everything related to our shipping policy"
Assistant calls: okf_traverse( concept_id = "policies/shipping", depth = 2 )
Returns: concept + all linked concepts up to 2 hops away
```

---

## 6. Skill Conformance

All **41 bundled skills** (`includes/bundled-skills/`) now include `type: Skill` in their YAML
frontmatter — the single required field for OKF v0.1 conformance. This means:

- Bundled skills are immediately navigable as OKF concepts
- The skill catalog (`# Available Skills` in progressive disclosure mode) doubles as an OKF index
- External OKF bundles can be consumed alongside existing skills without format translation

Example frontmatter after OKF conformance:
```yaml
---
name: code-reviewer
description: Code review skill for WordPress plugins and themes
type: Skill
---
```

---

## 7. Design Decisions

| Decision | Rationale |
|---|---|
| **Pure-PHP YAML parser, no new Composer dep** | OKF frontmatter uses minimal YAML (scalars, lists, kv-pairs). Avoids dependency bloat in Base tier. |
| **Tools follow Paper Store naming pattern** | `okf_*` prefix, same capability levels as `paper_store_*` — consistent discovery surface. |
| **Base tier for engine + tools** | OKF is an open standard; core I/O is filesystem-only. No paid APIs or services required. |
| **Skill conformance via `type: Skill` only** | Backward-compatible. Skills remain usable by existing Skill Parser + OKF Reader simultaneously. |
| **Atomic writes via existing Filesystem Service** | Paper Store already uses `WP_MCP_AI_Filesystem_Service`; consistent with existing I/O patterns. |
| **Priority 32 bootstrap (after Paper Store at 30)** | Both are filesystem-based knowledge stores; Paper Store initializes first for dependency ordering. |

---

## 8. Future Phases (Roadmap)

| Phase | Description | Tier |
|-------|-------------|------|
| ✅ **Phase 1** | Skill conformance — `type: Skill` on all bundled skills | Base |
| ✅ **Phase 2** | OKF Parser — pure-PHP YAML frontmatter parser | Base |
| ✅ **Phase 3** | OKF Reader — bundle navigation, traversal, search | Base |
| ✅ **Phase 4** | OKF Writer — concept creation, bundle validation | Base |
| ✅ **Phase 5** | OKF MCP Tools — 6 tools for AI agent consumption | Base |
| 🔮 **Phase 6** | OKF → Skill Bridge — treat OKF concepts as loadable agent skills | Pro |
| 🔮 **Phase 7** | Auto-Enrichment Agent — crawl site content → auto-generate OKF concepts | Pro |
| 🔮 **Phase 8** | Hybrid Knowledge Router — query-classification layer routing to OKF/Vector/Paper | Pro |

---

## 9. References

- [OKF v0.1 Specification](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md)
- [Google Cloud Blog: How OKF can improve data sharing](https://cloud.google.com/blog/products/data-analytics/how-the-open-knowledge-format-can-improve-data-sharing/)
- Full proposal: [`docs/project/proposals/OKF_INTEGRATION_PROPOSAL.md`](../project/proposals/OKF_INTEGRATION_PROPOSAL.md)
- OKF engine: [`includes/okf/README.md`](../../includes/okf/README.md)
- OKF tools: [`includes/tools/okf/README.md`](../../includes/tools/okf/README.md)
- Agent Skills doc: [`docs/features/agent-skills.md`](agent-skills.md)
