# OKF Integration Proposal — Google's Open Knowledge Format for NV oOS

**Status:** Proposal  
**Created:** 2026-07-20  
**Author:** AI Agent (via Zed)  
**Target:** NV oOS v2.1+ (Base tier)

---

## 1. Summary

Google Cloud released the **Open Knowledge Format (OKF v0.1)** on June 12, 2026 — an
Apache 2.0-licensed open specification that formalizes the "LLM Wiki" paradigm
popularized by Andrej Karpathy. An OKF bundle is a directory of markdown files
with YAML frontmatter, where each file represents a single **concept** (policy,
procedure, API, table, metric, playbook, etc.) and concepts link to each other
via standard markdown links, forming a navigable knowledge graph.

OKF is not a replacement for vector databases or RAG. It is the **complement**: OKF
handles curated, authoritative knowledge (~20%) with deterministic link-based
navigation, while RAG handles massive unstructured collections (~80%) with
semantic search. The recommended architecture is a **hybrid router** that selects
the right source per query.

NV oOS is already well-positioned for OKF adoption:
- Bundled skills already use markdown + YAML frontmatter (structurally identical to OKF)
- Paper Store provides flat-file knowledge storage
- Tool Embedding Store + Attention Router provide the RAG/vector side
- The plugin has ~1,025 tools that can optionally be described as OKF concepts

---

## 2. Why OKF Matters

| Problem with RAG-everything | How OKF Solves It |
|---|---|
| Chunking destroys document structure and relationships | Concepts are whole documents with explicit cross-links |
| Vector retrieval is probabilistic — you might get the wrong chunk | Link-based navigation is deterministic |
| Embedding sync with frequently-updated data is an operational burden | Files are plain text; diffs are native Git operations |
| "Why was this retrieved?" is opaque | Every link is human-readable and auditable |
| Every vendor has their own knowledge-graph schema | OKF is vendor-neutral, requiring only a `type` field |

---

## 3. Key OKF Specification Points (v0.1)

- **A bundle** is a directory tree of `.md` files
- **Every concept** requires YAML frontmatter with at least a `type` field
- **Optional fields**: `title`, `description`, `resource`, `tags`, `timestamp`
- **Cross-links** use standard markdown `[text](/path/to/concept.md)` syntax
- **`index.md`** at any level provides progressive disclosure (directory listing)
- **`log.md`** at any level records chronological update history
- **Conformance** requires only: frontmatter on every non-reserved `.md`, non-empty `type` field, reserved filenames follow conventions
- **No SDK, no runtime, no API key, no vendor lock-in**

---

## 4. NV oOS Integration Architecture

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

## 5. Implementation Phases

### Phase 1 — Skill Conformance (minimal, low-risk)
Add `type: Skill` and standardize frontmatter on all ~40 bundled skills.
This makes NV oOS skills OKF-conformant overnight.

### Phase 2 — OKF Parser
Minimal pure-PHP YAML frontmatter parser for the subset of YAML that
OKF frontmatter uses (scalars, lists, key-value pairs). Avoids adding
a `symfony/yaml` dependency.

### Phase 3 — OKF Bundle Reader
Class that navigates an OKF directory tree:
- `get_concept( $concept_id )` — reads frontmatter + body
- `traverse( $concept_id, $depth )` — follows cross-links up to N hops
- `browse( $path )` — reads `index.md` for directory listing
- `search_by_type( $type )` / `search_by_tag( $tag )`

### Phase 4 — OKF Bundle Writer
Class that creates and updates OKF concepts:
- `write_concept( $path, $frontmatter, $body )`
- `regenerate_index( $path )`
- `validate_bundle( $path )` — conformance check per spec §9

### Phase 5 — OKF MCP Tools (6 tools)
Follow the established Paper Store tool pattern:
- `okf_read_concept` — Read a single concept
- `okf_browse` — Browse a bundle directory via index.md
- `okf_traverse` — Follow cross-links from a concept
- `okf_search` — Search concepts by type/tag/text
- `okf_write_concept` — Create or update a concept
- `okf_delete_concept` — Archive a concept

### Phase 6 — OKF → Skill Bridge (Pro tier, future)
Treat every OKF concept as if it were a skill that agents can load into
context. This creates a seamless path from curated knowledge to agent
behavior.

### Phase 7 — Auto-Enrichment Agent (Pro tier, future)
Given a WordPress site, crawl pages/CPTs/taxonomies and auto-generate
OKF concepts with cross-links. Inspired by Google's reference enrichment
agent for BigQuery.

### Phase 8 — Hybrid Knowledge Router (Pro tier, future)
Query-classification layer that routes:
- "What is the policy for X?" → OKF
- "Find all past incidents involving Y" → RAG / Vector Store
- "How do I compute Z?" → OKF first, RAG fallback

---

## 6. File Structure

```
includes/
├── okf/                                    # NEW — OKF engine
│   ├── README.md
│   ├── okf-init.php                        # Bootstrap loader
│   ├── class-wp-mcp-ai-okf-parser.php      # YAML frontmatter parser
│   ├── class-wp-mcp-ai-okf-reader.php      # Bundle reader/traverser
│   └── class-wp-mcp-ai-okf-writer.php      # Bundle writer/validator
│
└── tools/
    └── okf/                                # NEW — OKF MCP tools
        ├── README.md
        ├── class-wp-mcp-ai-tool-okf-read-concept.php
        ├── class-wp-mcp-ai-tool-okf-browse.php
        ├── class-wp-mcp-ai-tool-okf-traverse.php
        ├── class-wp-mcp-ai-tool-okf-search.php
        ├── class-wp-mcp-ai-tool-okf-write-concept.php
        └── class-wp-mcp-ai-tool-okf-delete-concept.php

uploads/mcp-ai-wpoos/knowledge/             # Runtime OKF bundles
├── skill-knowledge/                        # Auto-generated from bundled skills
├── site-knowledge/                         # Site-specific curated knowledge
└── external-bundles/                       # Imported third-party OKF bundles
```

---

## 7. Decision Log

| Decision | Rationale |
|---|---|
| **Skills get `type: Skill` only (not renamed to OKF)** | Backward-compatible; skills remain usable by existing Skill Parser + OKF Reader |
| **Pure-PHP YAML parser, no new Composer dep** | OKF frontmatter is minimal (scalars, lists, kv-pairs). Avoids dependency bloat. |
| **`symfony/filesystem` for atomic writes (already a dep)** | Paper Store already uses it; consistent with existing I/O patterns |
| **Tools follow Paper Store naming pattern** | `okf_*` prefix, same capability levels as `paper_store_*` |
| **Base tier for reader/parser/writer/tools** | OKF is open standard; core I/O is filesystem only |
| **Pro tier for auto-enrichment agent + hybrid router** | LLM cost for enrichment; routing logic is advanced orchestration |

---

## 8. Risks & Mitigations

| Risk | Mitigation |
|---|---|
| YAML parsing edge cases in custom parser | Parser is limited to OKF's minimal YAML subset; tests cover edge cases |
| Large bundles slowing traversal | Lazy loading — files read on demand, not preloaded |
| Broken cross-links (concept moved/deleted) | Spec mandates consumers tolerate broken links; reader logs warnings |
| PHP 7.4 compatibility | No typed properties, no match expressions, no enums — all compatible |

---

## 9. Success Criteria

1. All bundled skills pass OKF v0.1 conformance check
2. OKF Reader can navigate the skill-knowledge bundle and resolve cross-links
3. OKF MCP tools are registered and accessible to assistants
4. Existing skill system continues to function unchanged
5. Zero new Composer dependencies in Base tier
6. All existing tests pass; new tests cover OKF parser/reader/writer
