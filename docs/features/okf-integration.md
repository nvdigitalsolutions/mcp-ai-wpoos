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
│   ├── class-wp-mcp-ai-okf-writer.php      # Bundle writer/validator
│   ├── class-wp-mcp-ai-okf-bundle-manager.php # Bundle lifecycle manager (1.1.62)
│   └── class-wp-mcp-ai-okf-skill-knowledge-generator.php # skill-knowledge generator
│
└── tools/
    └── okf/                                # OKF MCP tools (Base tier)
        ├── README.md
        ├── class-wp-mcp-ai-tool-okf-read-concept.php
        ├── class-wp-mcp-ai-tool-okf-browse.php
        ├── class-wp-mcp-ai-tool-okf-traverse.php
        ├── class-wp-mcp-ai-tool-okf-search.php
        ├── class-wp-mcp-ai-tool-okf-list-bundles.php
        ├── class-wp-mcp-ai-tool-okf-write-concept.php
        ├── class-wp-mcp-ai-tool-okf-delete-concept.php
        ├── class-wp-mcp-ai-tool-okf-validate-attestation.php
        ├── class-wp-mcp-ai-tool-okf-validate-bundle.php
        └── class-wp-mcp-ai-tool-okf-import-bundle.php

wp-content/uploads/mcp-ai-wpoos/knowledge/   # Runtime OKF bundles
├── skill-knowledge/                         # Auto-generated (protected from tool writes)
├── site-knowledge/                          # Site-specific curated knowledge
├── external-bundles/                        # Imported third-party OKF bundles
├── .trash/                                  # Archived bundles (recoverable)
└── <user bundles>/                          # Created via okf_write_concept first write
```

---

## 4. OKF Engine Components

### 4.1 Parser (`WP_MCP_AI_OKF_Parser`)

A pure-PHP YAML frontmatter parser that handles only the subset of YAML needed for OKF
frontmatter: scalars, lists, and key-value pairs. No external YAML library dependency.
This keeps the Base tier dependency-free.

### 4.2 Reader (`WP_MCP_AI_OKF_Reader`)

Navigates an OKF directory tree:
- `get_concept( $concept_id )` — reads frontmatter + body from a concept file (path resolution
  is symlink-aware: a `/`-boundary lexical check plus `realpath` containment)
- `traverse( $concept_id, $depth )` — follows cross-links up to N hops
- `browse( $path )` — reads `index.md` for directory listing (progressive disclosure)
- `search_by_type( $type )` / `search_by_tag( $tag )` — type/tag-based discovery
- `find_broken_links( $concept_id )` (1.1.62) — advisory report of unresolved bundle-internal
  cross-links (absolute, relative, and external-scheme aware; §6.1 tolerance)

### 4.3 Writer (`WP_MCP_AI_OKF_Writer`)

Creates and updates OKF concepts:
- `write_concept( $path, $frontmatter, $body )` — atomic writes via `WP_MCP_AI_Filesystem_Service`
- `regenerate_index( $path )` — rebuilds `index.md` after concept changes (bundle-root indexes carry `okf_version: "0.2"`)
- `validate_bundle( $path )` — conformance check per OKF spec §9, plus advisory `broken_links` reporting (§6.1 — never affects conformance)
- `append_log( $path, $entry, $action )` — maintains `log.md` (OKF v0.2 §9)
- `ensure_bundle_root()` — creates the bundle directory and fires `wp_mcp_ai_okf_bundle_initialized`

### 4.4 Bundle Manager (`WP_MCP_AI_OKF_Bundle_Manager`, since 1.1.62)

Single source of truth for bundle paths and the bundle lifecycle. All seven tools resolve
bundles through it (no more per-tool path logic):

- `resolve_bundle_root( $bundle, $create )` — strict slug validation for new bundles,
  `realpath` containment against the knowledge root (symlink-safe), legacy names of existing
  bundles stay readable.
- `get_knowledge_root()` — filterable via `wp_mcp_ai_okf_knowledge_root`; creates the root
  with `.htaccess` (`Deny from all`) + `index.php` guards on first use.
- Bundle lifecycle: `list_bundles()`, `create_bundle()` (stamps `okf_version` + `log.md`),
  `rename_bundle()`, `archive_bundle()` (→ `.trash/`), `delete_bundle()`,
  `export_bundle_zip()` / `import_bundle_zip()` (ZipSlip-safe, symlink rejection, size caps),
  `bundle_stats()`, `append_log()`.
- **Protection:** `skill-knowledge` is auto-generated and cannot be written, deleted,
  renamed, or archived through the tools (`okf_protected_bundle`). It is rebuilt by the
  generator on bootstrap and after admin bundled-skill reinstall.

### 4.5 Bootstrap

Loaded via `includes/bootstrap/loader.php` at priority 32 (after Paper Store at 30).
Single-line hook: `add_action( 'wp_mcp_ai_bootstrapped', 'wp_mcp_ai_okf_init', 32 );`

### 4.6 Events

| Hook | Fires When |
|------|-----------|
| `wp_mcp_ai_okf_bundle_initialized` | A new bundle directory is created |
| `wp_mcp_ai_okf_concept_saved` | A concept is created or updated |
| `wp_mcp_ai_okf_concept_deleted` | A concept is archived/deleted |

### 4.7 Admin UI (1.1.62)

`WP_MCP_AI_OKF_Bundle_Manager_Admin_Page` registers a **OKF Bundles** screen under
*Assistants* (`edit.php?post_type=mcp_ai_assistant&page=wp-mcp-ai-okf-bundle-manager`,
`manage_options`), mirroring the Pro Skill Manager UX:

- **Bundles** — create (slug-validated; root `index.md` + `log.md` auto-created), list with
  health stats (concepts, stale/deprecated, trust-tier histogram, conformance), rename,
  archive (→ `.trash/`), delete, export ZIP.
- **Browser** — concept tree with trust-tier/staleness badges and a "new concept" form.
- **Editor** — CodeMirror over a concept's raw markdown; save (validated frontmatter +
  reserved-name + protected-bundle guards) and soft-delete. `index.md` / `log.md` are
  read-only.
- **Import / Export** — ZIP upload (ZipSlip-safe) and per-bundle authenticated ZIP download
  (streamed, deleted after send).
- **Validate** — on-demand `validate_bundle()` report (advisory issues only).

All state changes flow through the Bundle Manager and are nonce + `manage_options` gated.

---

## 5. MCP Tools (10)

All 10 tools follow the established tool pattern: two-gate sanitization, canonical return envelope,
and proper capability checks.

| Tool Slug | Function | Capability |
|-----------|----------|------------|
| `okf_read_concept` | Read a single concept by path or concept ID | `read` |
| `okf_browse` | Browse a bundle directory via `index.md` | `read` |
| `okf_traverse` | Follow cross-links from a concept (configurable depth) | `read` |
| `okf_search` | Search concepts by type, tag, or full-text | `read` |
| `okf_list_bundles` | List bundles with health stats (types, trust tiers, stale/deprecated counts) | `read` |
| `okf_validate_attestation` | Validate an Attested Computation concept's contract | `read` |
| `okf_validate_bundle` | Bundle-level conformance check (advisory issues, never blocks) | `read` |
| `okf_write_concept` | Create or update a concept (**creates the bundle on first write**; refuses `skill-knowledge`; supports `resource`/`sources`/`usage_window`/`verified`) | `edit_posts` |
| `okf_delete_concept` | Archive a concept (renames to `.deleted.<timestamp>`; refuses `skill-knowledge`) | `delete_posts` |
| `okf_import_bundle` | Import a bundle from a server-side ZIP (ZipSlip-safe) | `manage_options` |

> **Bundle creation & log maintenance (1.1.62):** writing into a missing bundle now creates
> it (strict name validation: lowercase letters, numbers, hyphens, underscores) and generates
> its root `index.md` (stamped with `okf_version: "0.2"`). Concept writes and deletions append
> `log.md` entries (OKF v0.2 §9). The `skill-knowledge` bundle is auto-generated and protected
> from tool writes (`okf_protected_bundle`) — curated knowledge belongs in `site-knowledge`.
>
> **Tool Presets:** OKF tools are available in two presets:
> - **Essentials Internal** — the read tools plus `okf_list_bundles` and `okf_validate_bundle`
> - **Files & Documents** — all 10 tools (read + write/delete/validate/import) alongside Paper Store tools

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
| ✅ **Phase 6** | OKF → Skill Bridge — treat OKF concepts as loadable agent skills | Pro |
| ✅ **Phase 7** | Auto-Enrichment Agent — crawl site content → auto-generate OKF concepts | Pro |
| ✅ **Phase 8** | Hybrid Knowledge Router — query-classification layer routing to OKF/Vector/Paper | Pro |

### 8.1 OKF → Skill Bridge (Phase 6, 1.1.62)

`load_skill` accepts `bundle:concept_id` names via the `wp_mcp_ai_load_skill_external`
filter; the Pro `WP_MCP_AI_OKF_Skill_Bridge` resolves them into skill-shaped instructions
with a provenance/trust banner. Gates: per-assistant grants (metabox →
`_wp_mcp_ai_okf_concepts`, fail-closed), draft rejection, optional
`wp_mcp_ai_okf_skill_bridge_min_trust` tier gate. Concepts load explicitly by name;
progressive-disclosure index integration is future work.

### 8.2 Auto-Enrichment Agent (Phase 7, 1.1.62)

The Pro `WP_MCP_AI_OKF_Enrichment_Agent` crawls published WordPress content —
any public post type, plus optionally public taxonomy terms — and generates
OKF concepts into a bundle (default `site-content`, created on first run).
Generated concepts carry the Phase C provenance schema (`resource`, `sources`,
`generated: { by: process:okf-enrichment }`), post-type-namespaced concept IDs
(`post/<slug>`, `page/<slug>`, `terms/<taxonomy>/<slug>`), and cross-links to
other crawled posts extracted from internal `<a>` links.

- **Deterministic and idempotent** — re-running overwrites the same concept
  files; no bundled LLM calls. The `wp_mcp_ai_okf_enrichment_description`
  filter upgrades the deterministic excerpt to an AI summary.
- **Protected bundles are refused** — enrichment can never write to
  `skill-knowledge` (or any `okf_protected_bundle`).
- **Surfaces:** MCP tool `okf_enrich_site_content` (`manage_options`); the
  Base Bundle Manager admin page (Import/Export tab) shows a gated
  enrichment form when Pro is active (AJAX `wp_mcp_ai_okf_bundle_enrich`).

### 8.3 Hybrid Knowledge Router (Phase 8, 1.1.62)

The Pro `WP_MCP_AI_Hybrid_Knowledge_Router` classifies a knowledge query and
produces an ordered routing plan across the three stores: OKF bundles (curated
markdown), the vector store (semantic embeddings), and Paper Store (structured
records). Classification is keyword/pattern-based by default (policies/
procedures → OKF; incidents/logs/history → Paper; similarity queries → Vector),
with unmatched queries falling back to OKF → Vector → Paper.

- **Filters:** `wp_mcp_ai_hybrid_router_signals` extends the pattern table;
  `wp_mcp_ai_hybrid_router_decision` replaces the entire plan (LLM-backed or
  custom classifiers).
- **OKF lookup:** `search_okf()` performs deterministic token-overlap ranking
  with scores and trust/stale metadata; the `route_knowledge_query` tool
  (`read`) runs it only when OKF is the primary route.

---

## 9. References

- [OKF v0.1 Specification](https://github.com/GoogleCloudPlatform/knowledge-catalog/blob/main/okf/SPEC.md)
- [Google Cloud Blog: How OKF can improve data sharing](https://cloud.google.com/blog/products/data-analytics/how-the-open-knowledge-format-can-improve-data-sharing/)
- Full proposal: [`docs/project/proposals/OKF_INTEGRATION_PROPOSAL.md`](../project/proposals/OKF_INTEGRATION_PROPOSAL.md)
- OKF engine: [`includes/okf/README.md`](../../includes/okf/README.md)
- OKF tools: [`includes/tools/okf/README.md`](../../includes/tools/okf/README.md)
- Agent Skills doc: [`docs/features/agent-skills.md`](agent-skills.md)
