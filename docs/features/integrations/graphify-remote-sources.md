# Graphify Remote Sources & Federation

> **Feature introduced in**: Graphify 0.6.0  
> **Requires**: NV oOS 1.0+, PHP 7.4+

This document describes the remote sources and federation system for the Graphify knowledge-graph addon.

---

## Overview

Remote sources let Graphify pull external entity data into your local knowledge graph. During each graph build (or on a cron schedule), the **Remote Enricher** iterates over all enabled sources, fetches candidate nodes and edges via their driver, and upserts the results into the local graph tables.

```
WordPress Build Pipeline
    ├── Step 1: Content detection
    ├── Step 2: Structural extraction
    ├── Step 3: Semantic extraction (optional)
    ├── Step 3.5: Remote enrichment ← NEW
    ├── Step 4: Degree recalculation
    ├── Step 5: Community detection
    └── Step 6: Finalize + fire hooks
```

---

## Architecture

### Class Hierarchy

```
NV_oOS_Graphify_Remote_Source_Interface  (contract for all drivers)
    ├── NV_oOS_Graphify_Remote_Wikidata
    ├── NV_oOS_Graphify_Remote_Oos_Federation
    ├── NV_oOS_Graphify_Remote_Generic_Rest
    ├── NV_oOS_Graphify_Remote_Rss_Sitemap
    └── NV_oOS_Graphify_Remote_Sparql

NV_oOS_Graphify_Remote_Registry (singleton, holds driver instances)
NV_oOS_Graphify_Remote_Enricher (orchestration, budget, locking)
NV_oOS_Graphify_HTTP_Client     (SSRF guard, ETag caching, circuit breaker)
NV_oOS_Graphify_Crypto          (AES-256-GCM encrypt/decrypt)
NV_oOS_Graphify_Embeddings      (float32 binary vectors, cosine similarity)
```

### Data Flow

1. Admin configures a source (slug, driver, config) via **Settings → Remote Sources** or the REST API.
2. Sensitive config values (keys matching `token|password|secret|key`) are encrypted with AES-256-GCM and stored in `nvoos_graph_remote_sources.config_json`.
3. During enrichment, `NV_oOS_Graphify_Remote_Registry::get_active_sources()` decrypts configs before passing them to drivers.
4. Each driver returns an array of `node` and `edge` structs, which are batch-upserted into the graph tables with `source_slug`, `external_id`, and `confidence` metadata.
5. Node embeddings (if enabled) are generated and stored in `nvoos_graph_node_embeddings`.

---

## Database Schema

### `nvoos_graph_remote_sources`

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Auto-increment PK |
| `slug` | varchar(100) UNIQUE | Source identifier (URL-safe) |
| `driver` | varchar(100) | Driver slug (e.g. `wikidata`) |
| `label` | varchar(255) | Human-readable name |
| `config_json` | longtext | JSON config (sensitive fields AES-256-GCM encrypted) |
| `enabled` | tinyint | 1 = active |
| `rate_limit` | int | Requests/hour (0 = unlimited) |
| `last_sync_at` | datetime | Last successful sync timestamp |
| `last_error` | text | Last error message (null = no errors) |
| `circuit_state` | varchar(20) | `closed` / `open` / `half-open` |

### Node columns added in v0.6.0

| Column | Description |
|--------|-------------|
| `external_id` | QID, URL, or ID from the source |
| `source_slug` | Which remote source this came from |
| `confidence` | Match confidence score (0.0–1.0) |
| `expires_at` | Optional TTL for auto-expiry |

### `nvoos_graph_node_embeddings`

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Auto-increment PK |
| `node_id` | varchar(100) | FK to `nvoos_graph_nodes.node_id` |
| `model` | varchar(100) | Embedding model name |
| `dim` | int | Vector dimension count |
| `vector` | longblob | Float32 binary (4 bytes × dim) |
| `updated_at` | datetime | Last update timestamp |

---

## HTTP Client

`NV_oOS_Graphify_HTTP_Client` wraps WordPress's `wp_remote_*` functions with:

### SSRF Protection

All outbound URLs are resolved to their IP before the request. The following ranges are blocked:

- `127.0.0.0/8` — loopback
- `10.0.0.0/8` — RFC1918
- `172.16.0.0/12` — RFC1918
- `192.168.0.0/16` — RFC1918
- `169.254.0.0/16` — link-local
- `::1/128` — IPv6 loopback
- `fc00::/7` — IPv6 unique local

Override for development:

```php
add_filter( 'nvoos_graphify_allow_private_remotes', '__return_true' );
```

### Circuit Breaker

Each host gets a circuit breaker backed by a WordPress transient (`nvoos_graphify_circuit_{md5(host)}`):

| State | Behaviour |
|-------|-----------|
| `closed` | Normal operation |
| `open` | All requests blocked immediately |
| `half-open` | One probe request allowed; reopens on failure |

### ETag Caching

`GET` responses with `ETag` headers are cached per URL. Subsequent requests send `If-None-Match`; a `304 Not Modified` returns the cached body and skips re-processing.

### Exponential Backoff

Transient network errors are retried up to 3 times with 1 s → 2 s → 4 s delays.

---

## Drivers

### Wikidata (`wikidata`)

Uses the `wbsearchentities` MediaWiki API to reconcile entity names against Wikidata.

**Confidence scoring algorithm:**

```
exact label match     → 1.0
label starts-with     → 0.85
label contains query  → 0.70
levenshtein fallback  → normalised score (discarded if < 0.60)
```

Nodes below the configured `min_confidence` threshold are discarded.

**Configuration:**

```json
{
  "language": "en",
  "min_confidence": 0.6
}
```

### OOS Federation (`oos-federation`)

Pulls nodes from a remote NV oOS installation via its MCP REST API. Useful for syncing a staging graph into production, or federating a multi-site network.

**Configuration:**

```json
{
  "endpoint": "https://remote-site.example.com/wp-json/mcp-ai/v1",
  "api_token": "cred_xxxxx.SECRET",
  "post_types": "post,page",
  "max_nodes": 200
}
```

### Generic REST (`generic-rest`)

Maps arbitrary REST API responses to graph nodes using dot-notation paths.

**Configuration:**

```json
{
  "endpoint": "https://api.example.com/items",
  "api_token": "Bearer my-token",
  "path_results": "data.items",
  "path_id": "id",
  "path_label": "name",
  "path_url": "permalink",
  "path_type": "category"
}
```

### RSS/Atom/Sitemap (`rss-sitemap`)

Auto-detects feed format (RSS 2.0, Atom 1.0, XML sitemap) and ingests articles/URLs as nodes.

**Configuration:**

```json
{
  "feed_url": "https://blog.example.com/feed/",
  "node_type": "article",
  "max_items": 100
}
```

### SPARQL 1.1 (`sparql`)

Executes a SELECT query against any SPARQL 1.1 endpoint.

**Configuration:**

```json
{
  "endpoint": "https://query.wikidata.org/sparql",
  "query": "SELECT ?id ?label ?url WHERE { ?id rdfs:label ?label . FILTER(lang(?label)='en') } LIMIT 100",
  "var_id": "id",
  "var_label": "label",
  "var_url": "url"
}
```

Built-in prefix declarations: `rdf`, `rdfs`, `owl`, `xsd`, `schema`, `wdt`, `wd`, `wikibase`, `bd`.

---

## Vector Embeddings

When **Embeddings** are enabled, Graphify generates a float32 vector for each node's label + properties and stores it in MySQL as a binary blob (4 bytes × dim).

### Retrieval Augmented Generation (RAG)

The `graphify_retrieve_context` tool combines three retrieval strategies:

1. **Keyword search** — full-text LIKE search against node labels (top-k)
2. **BFS traversal** — follows edges up to `hops` hops from seed nodes
3. **Vector similarity** — cosine similarity against stored embeddings (when `use_vectors=true`)

The combined candidate list is deduplicated, ranked, and serialised into a markdown `context_text` string suitable for injection into an LLM system prompt.

### Embedding Generation

Embeddings are generated by:
1. `wp_mcp_ai_get_embedding( $text, $model )` — NV oOS core function (preferred)
2. Direct OpenAI API call — fallback if core function unavailable

### Reindexing

Trigger a full reindex from the Embeddings tab or via:

```
wp eval "NV_oOS_Graphify_Embeddings::get_instance()->reindex_all();"
```

Progress is tracked in the `nvoos_graphify_reindex_offset` option.

---

## REST API

All endpoints are under `/wp-json/nvoos-graphify/v1`.

### `POST /retrieve`

RAG context retrieval for LLM injection.

**Request:**
```json
{
  "question": "What is the relationship between gravity and spacetime?",
  "k": 10,
  "hops": 2,
  "use_vectors": true,
  "include_edges": true
}
```

**Response:**
```json
{
  "success": true,
  "context_text": "**Gravity** (entity)\n...",
  "nodes_found": 8,
  "edges_found": 12
}
```

### `GET /resolve?ref=Q937`

Resolve a Wikidata QID or external URL to a local node. When `auto_ingest=true` (default) and the node doesn't exist locally, it is automatically fetched from Wikidata and inserted.

### `GET /sources`

List all configured remote sources with their status (enabled, circuit state, last sync).

### `POST /sources`

Create a new remote source.

**Request:**
```json
{
  "slug": "my-wikidata",
  "driver": "wikidata",
  "label": "Wikidata Reconciliation",
  "enabled": true,
  "config": { "language": "en", "min_confidence": 0.7 }
}
```

### `DELETE /sources/{slug}`

Delete a source and its configuration.

### `POST /sources/{slug}/sync`

Trigger an immediate sync. Pass `"async": false` for synchronous execution.

### `POST /sources/{slug}/test`

Test connectivity to a source. Returns driver-specific connection info or a `WP_Error`.

---

## Adding a Custom Driver

1. Create a class implementing `NV_oOS_Graphify_Remote_Source_Interface`.
2. Register it on the `nvoos_graphify_register_remote_sources` action:

```php
add_action( 'nvoos_graphify_register_remote_sources', function ( $registry ) {
    $registry->register_driver( new My_Custom_Driver() );
} );
```

3. Your driver must implement:

| Method | Return | Description |
|--------|--------|-------------|
| `get_slug()` | `string` | URL-safe identifier (e.g. `my-source`) |
| `get_label()` | `string` | Human-readable name |
| `get_config_schema()` | `array` | JSON Schema for admin UI validation |
| `fetch_nodes( $config )` | `array\|WP_Error` | Return array of node structs |
| `fetch_edges( $config, $nodes )` | `array` | Return edge structs linking new nodes |
| `test_connection( $config )` | `bool\|WP_Error` | Verify connectivity |

**Node struct:**
```php
array(
    'node_id'     => 'my-source:item-123',
    'label'       => 'My Item',
    'type'        => 'entity',
    'url'         => 'https://...',
    'external_id' => 'item-123',
    'source_slug' => 'my-source',
    'confidence'  => 0.95,
    'properties'  => array( 'description' => '...' ),
)
```

---

## Security Considerations

- Credentials are stored encrypted (AES-256-GCM, WordPress salt-derived key). Rotating AUTH_KEY/SECURE_AUTH_KEY will require re-saving all source configs.
- The SSRF guard is enforced in `NV_oOS_Graphify_HTTP_Client` and cannot be bypassed without adding the `nvoos_graphify_allow_private_remotes` filter (development use only).
- All REST write endpoints require `manage_options` capability.
- Source slugs are validated with `sanitize_key()` — only lowercase alphanumeric and hyphens/underscores.

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Source shows "circuit open" | Repeated connection failures | Use **Test Connection** to probe; circuit resets after 60 s |
| Nodes not appearing after sync | Budget exhausted | Increase **Enrichment Budget** in settings |
| Embeddings not generating | No OpenAI API key | Add key under **Settings → General → OpenAI API Key** |
| SSRF error on private endpoint | SSRF guard triggered | For dev use only: add `add_filter('nvoos_graphify_allow_private_remotes','__return_true')` to `wp-config.php` |
| Wikidata match confidence too low | Entity name ambiguous | Increase `min_confidence` or use more specific entity labels |

---

## See Also

- [Graphify README](../addons/graphify/README.md) — Installation and full feature overview
- [Tool Reference](tool-reference.md) — All 800+ NV oOS MCP tools
- [REST API Reference](rest-api.md) — Full NV oOS REST API documentation
- [Developer Hooks Reference](DEVELOPER_HOOKS_REFERENCE.md) — All plugin hooks
