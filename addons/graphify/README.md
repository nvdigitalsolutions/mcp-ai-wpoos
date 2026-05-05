# Graphify — Knowledge Graph Addon for NV oOS

> **Version**: 0.6.0 | **Requires**: NV oOS 1.0+, PHP 7.4+, WordPress 6.0+

Graphify transforms your WordPress content into a traversable knowledge graph with AI-powered enrichment, vector embeddings, and Retrieval-Augmented Generation (RAG) support.

---

## Features

### Core Graph Engine
- Automatic node and edge extraction from posts, pages, terms, and custom post types
- Structural relationships (post→term, post→author, post→linked-page)
- Semantic entity extraction via OpenAI (optional)
- Louvain-style community detection
- Cytoscape.js graph explorer in WordPress admin
- Schema.org JSON-LD injection
- Related content widget via graph proximity

### Remote Sources & Federation (v0.6.0)
- **Wikidata** reconciliation — match local entities to Wikidata QIDs with confidence scoring
- **OOS Federation** — federate nodes from other NV oOS sites via MCP protocol
- **Generic REST** — ingest from any REST API with dot-notation JSON path mapping
- **RSS/Atom/Sitemap** — crawl feed sources and create nodes automatically
- **SPARQL 1.1** — query any SPARQL endpoint (DBpedia, Wikidata Query Service, etc.)
- Circuit breaker pattern per host — auto-disables flaky sources
- SSRF protection — blocks RFC1918 and reserved IP ranges
- AES-256-GCM encrypted credential storage

### Vector Embeddings & RAG
- Float32 binary vector storage in MySQL (no external vector DB required)
- OpenAI embedding models: `text-embedding-3-small`, `text-embedding-3-large`, `text-embedding-ada-002`
- Cosine similarity search across all node embeddings
- Full RAG retrieval: keyword search + BFS graph traversal (1–3 hops) + vector similarity
- 5-minute transient caching for repeated queries

### AI Tools (MCP)
| Tool | Description | Capability |
|------|-------------|-----------|
| `graphify_get_node` | Retrieve a single node by ID | `read_posts` |
| `graphify_search_graph` | Full-text search across nodes | `read_posts` |
| `graphify_get_related` | BFS traversal for related nodes | `read_posts` |
| `graphify_build_graph` | Trigger a full/incremental build | `manage_options` |
| `graphify_retrieve_context` | RAG retrieval for LLM context | `read_posts` |
| `graphify_resolve_external` | Resolve a QID/URL to a local node | `read_posts` |
| `graphify_sync_remote_source` | Trigger manual enrichment sync | `manage_options` |
| `graphify_list_remote_sources` | List sources + available drivers | `read_posts` |

---

## Installation

1. Ensure the NV oOS base plugin is installed and activated.
2. Upload or copy the `addons/graphify/` directory to your WordPress plugins folder as `nvoos-graphify`.
3. Activate **NV oOS — Graphify** in **Plugins → Installed Plugins**.
4. Navigate to **Knowledge Graph** in the WordPress admin menu.

---

## Configuration

### General Tab
- **Enable Graphify** — master on/off switch
- **Semantic Extraction** — use OpenAI to extract named entities and concepts
- **Incremental Builds** — only process posts changed since the last build
- **Auto-Rebuild on Save** — trigger a build when a post is published or updated
- **Scheduled Rebuild** — daily/weekly/monthly cron build
- **OpenAI API Key** — required for semantic extraction and embeddings

### Remote Sources Tab
Configure external data sources and manage synchronisation:
- Add a source with **slug**, **driver type**, **label**, and driver-specific **configuration**
- Sensitive config values (tokens, keys, passwords, secrets) are encrypted at rest with AES-256-GCM
- Use **Test Connection** to verify connectivity before enabling
- Use **Sync Now** to trigger an immediate enrichment pass

### Embeddings Tab
- **Enable Embeddings** — generate vector embeddings during builds
- **Embeddings Model** — choose the OpenAI model
- **Reindex All** — regenerate embeddings for all existing nodes

---

## Remote Source Drivers

### Wikidata (`wikidata`)
Reconciles local entity nodes against Wikidata using the `wbsearchentities` API.

| Config Key | Description | Default |
|------------|-------------|---------|
| `language` | BCP 47 language code | `en` |
| `min_confidence` | Minimum match confidence (0–1) | `0.6` |

Confidence scoring:
- **1.0** — exact label match
- **0.85** — label starts with query
- **0.70** — label contains query
- **≥0.60** — levenshtein similarity fallback

### OOS Federation (`oos-federation`)
Federates nodes from a remote NV oOS / MCP site.

| Config Key | Description | Required |
|------------|-------------|----------|
| `endpoint` | REST API base URL | ✅ |
| `api_token` | Bearer token | ✅ |
| `post_types` | Comma-separated post types | — |
| `max_nodes` | Max nodes per sync | — |

### Generic REST (`generic-rest`)
Ingest nodes from any REST API.

| Config Key | Description |
|------------|-------------|
| `endpoint` | API URL |
| `api_token` | Bearer token (optional) |
| `path_results` | Dot-notation path to results array (e.g. `data.items`) |
| `path_id` | Dot-notation path to item ID |
| `path_label` | Dot-notation path to item label |
| `path_url` | Dot-notation path to item URL |
| `path_type` | Dot-notation path to item type |

### RSS/Atom/Sitemap (`rss-sitemap`)
Auto-detects and ingests from feed or sitemap URLs.

| Config Key | Description |
|------------|-------------|
| `feed_url` | Feed or sitemap URL |
| `node_type` | Node type to assign (default: `article`) |
| `max_items` | Maximum items per sync |

### SPARQL 1.1 (`sparql`)
Query any SPARQL 1.1 endpoint.

| Config Key | Description | Default |
|------------|-------------|---------|
| `endpoint` | SPARQL endpoint URL | — |
| `query` | SPARQL SELECT query | — |
| `var_id` | Variable for node ID | `id` |
| `var_label` | Variable for label | `label` |
| `var_url` | Variable for URL | `url` |
| `api_token` | Bearer token | — |

Default prefixes available in queries: `rdf`, `rdfs`, `owl`, `xsd`, `schema`, `wdt`, `wd`, `wikibase`, `bd`.

---

## REST API

Base path: `/wp-json/nvoos-graphify/v1`

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/graph` | Full graph data |
| `GET` | `/nodes` | Paginated node list |
| `GET` | `/nodes/{node_id}` | Single node with edges |
| `POST` | `/build` | Trigger graph build |
| `GET` | `/search` | Full-text node search |
| `GET` | `/export` | Export graph (json/graphml/csv/neo4j/obsidian) |
| `POST` | `/retrieve` | RAG context retrieval |
| `GET` | `/resolve` | Resolve external QID/URL to node |
| `GET` | `/sources` | List configured remote sources |
| `POST` | `/sources` | Create a remote source |
| `DELETE` | `/sources/{slug}` | Delete a remote source |
| `POST` | `/sources/{slug}/sync` | Trigger manual sync |
| `POST` | `/sources/{slug}/test` | Test source connection |

---

## Hooks Reference

### Actions
| Hook | When |
|------|------|
| `nvoos_graphify_register_remote_sources` | Register custom remote source drivers |
| `nvoos_graphify_before_enrich` | Before enrichment run starts |
| `nvoos_graphify_after_enrich` | After enrichment run completes |
| `nvoos_graphify_source_synced` | After a single source sync |

### Filters
| Hook | Description |
|------|-------------|
| `nvoos_graphify_allow_private_remotes` | Return `true` to bypass SSRF guard (dev/testing only) |
| `nvoos_graphify_enrich_budget` | Override per-run node budget |
| `nvoos_graphify_rag_candidates` | Filter RAG candidate nodes before ranking |

### Registering a Custom Driver
```php
add_action( 'nvoos_graphify_register_remote_sources', function( $registry ) {
    $registry->register_driver( new My_Custom_Remote_Driver() );
} );
```

Your driver must implement `NV_oOS_Graphify_Remote_Source_Interface`:
- `get_slug(): string`
- `get_label(): string`
- `get_config_schema(): array`
- `fetch_nodes( array $config ): array|WP_Error`
- `fetch_edges( array $config, array $nodes ): array`
- `test_connection( array $config ): bool|WP_Error`

---

## Agent Memory Bridge (Phase 4a)

When this addon is active, every memory written by the NV oOS agent memory
system is mirrored into the knowledge graph. This is fully additive — the
agent's transient memory store remains the source of truth in Phase 4a and
nothing about agent memory writes changes when the addon is deactivated.

### What the bridge creates

Subscribed action: `wp_mcp_ai_memory_stored` (fired by
`WP_MCP_AI_Tool_Store_Agent_Context` and therefore by every item produced by
`mine_agent_memory`).

For each event the bridge upserts:

| Node             | id format                         | Purpose                                            |
|------------------|-----------------------------------|----------------------------------------------------|
| `memory`         | `memory:<context_id>`             | The memory itself with verbatim content + metadata |
| `agent`          | `agent:<sanitised-agent-id>`      | The agent that observed the memory                 |
| `wing`           | `wing:<sanitised-wing>`           | A MemPalace wing scope                             |
| `room`           | `room:<wing>:<room>`              | A MemPalace room inside a wing                     |

And edges:

| Source → Target                  | Relation       |
|----------------------------------|----------------|
| `memory:* → agent:*`             | `OBSERVED_BY`  |
| `memory:* → wing:*`              | `MEMBER_OF`    |
| `memory:* → room:*`              | `MEMBER_OF`    |
| `room:* → wing:*`                | `MEMBER_OF`    |
| `memory:* → post:<source_post>`  | `DERIVED_FROM` (when the memory was ingested from a WP post) |

Each memory node also enqueues an embedding through the standard on-ingest
cron pipeline, so memory vectors share the `embeddings` table and provider
configuration with content vectors.

### Graph-mode retrieval

The agent-side `wake_up_context` tool gains a `mode` parameter
(`auto` / `graph` / `transient`). In graph mode it asks the bridge for a
ranked list of memory nodes built from:

- 1-hop BFS expansion from `agent`, `wing`, and `room` anchor nodes;
- keyword `LIKE` search across memory node labels;
- cosine similarity against the embeddings table when a query is supplied.

The ranked context-id list flows through the
`wp_mcp_ai_wake_up_graph_context_ids` filter so plugins can post-process it,
and the wake-up response includes a `retrieval_path` field (`graph` or
`transient`) for observability.

### Memory Palace admin preset

The "Graph Explorer" tab now exposes two extra inputs — **Agent ID** and
**Wing** — plus an **Apply** button that fades the rest of the graph and
highlights the matching memory nodes (and their anchor nodes), giving
operators a literal view of an agent's memory palace.

### Programmatic surface

```php
// Subscribe to memory writes from a custom integration:
add_action( 'wp_mcp_ai_memory_stored', function ( $payload ) {
    // $payload['context_id'], $payload['agent_id'], $payload['wing'],
    // $payload['room'], $payload['content'], $payload['source_post_id'], …
} );

// Run a graph-backed retrieval directly:
$ranked = NV_oOS_Graphify_Memory_Bridge::retrieve_graph( array(
    'agent_id' => 42,
    'wing'     => 'client-acme',
    'room'     => 'onboarding',
    'query'    => 'activation flow',
    'limit'    => 20,
) );
// → [ [ 'context_id' => 'ctx_…', 'score' => 1.23, 'via' => ['wing','vector'] ], … ]
```

### Out of scope for Phase 4a

- Replacing Graphify's embedding generator with the agent-side Phase 3
  provider system end-to-end (query embedding already uses it; ingest
  embedding still uses Graphify's pipeline). Symmetry to be addressed in a
  follow-up phase.
- Cross-site memory federation. Free side-effect of Graphify's existing
  federation, but turning it on by default requires a privacy review.

---

## Security

- All API credentials are encrypted with AES-256-GCM before storage
- The HTTP client blocks SSRF via RFC1918 IP range detection
- Circuit breakers auto-disable sources with repeated failures
- All REST endpoints check WordPress capabilities (`manage_options` for write operations)
- All database queries use `$wpdb->prepare()`

---

## Changelog

### 0.6.0
- Remote sources & federation system (5 drivers)
- Vector embeddings (float32 binary, MySQL storage)
- RAG retrieval tool (`graphify_retrieve_context`)
- `graphify_resolve_external` tool (Wikidata auto-ingest)
- `graphify_sync_remote_source` and `graphify_list_remote_sources` tools
- 7 new REST API endpoints for remote source management
- Tabbed settings UI (General / Remote Sources / Embeddings)
- Scheduled enrichment via WP-Cron (`nvoos_graphify_enrich` event)

### 0.5.0
- Initial release: graph build pipeline, Cytoscape.js explorer, export, 4 MCP tools

## Credits

Graphify ships pre-bundled, unmodified copies of the Cytoscape.js graph-rendering stack under `addons/graphify/assets/vendor/`. Each directory carries the upstream `LICENSE` file alongside the bundle.

| Library | License | Upstream |
|---------|---------|----------|
| Cytoscape.js | MIT — © 2016–2023 The Cytoscape Consortium | https://github.com/cytoscape/cytoscape.js |
| cytoscape-fcose | MIT — © iVis Lab, Bilkent University | https://github.com/iVis-at-Bilkent/cytoscape.js-fcose |
| cose-base | MIT — © iVis Lab, Bilkent University | https://github.com/iVis-at-Bilkent/cose-base |
| layout-base | MIT — © iVis Lab, Bilkent University | https://github.com/iVis-at-Bilkent/layout-base |

For the full repo-wide attribution index, see [`CREDITS.md`](../../CREDITS.md) at the repository root.

## License

Graphify itself is **proprietary** — © 2025-2026 NV Digital Solutions, all rights reserved. Bundled third-party libraries listed above retain their upstream MIT licenses. See [`CREDITS.md`](../../CREDITS.md) at the repository root for the full attribution index.
