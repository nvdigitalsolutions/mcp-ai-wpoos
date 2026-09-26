# Remote Sources Guide

The Knowledge Graph can ingest nodes and edges from external systems
through **remote source drivers** (Settings → Remote Sources). Seven
drivers ship with the plugin. This guide documents what each driver
needs, how it behaves, and how it was verified.

---

## Shared infrastructure

Every outbound request goes through `NvoosContentGraph\Remote\HttpClient`,
which provides:

- **SSRF guard** — private/loopback/reserved addresses are blocked, and
  redirects are followed manually so every hop is re-checked. Local
  development can opt out via the `nvoos_content_graph/allow_private_urls`
  filter.
- **Circuit breaker** — repeated failures per host open the circuit and
  skip the source until it cools down.
- **Caching + retries** — GET responses are cached in transients; 5xx
  responses are retried with exponential backoff.
- **Credentials encryption** — driver config (tokens, secrets) is stored
  AES-256-GCM encrypted via `Remote\Crypto`.

Drivers implement `NvoosContentGraph\Contracts\RemoteSource` and are
registered on the `nvoos_content_graph/register_remote_sources` action.
Syncs are orchestrated by `Remote\Enricher::syncSource()` (manual Sync
button) and the scheduled enrichment cron, both honoring the per-site
`remote_enrich_budget` (default 50 items per sync).

---

## Drivers

### Generic REST API (`generic_rest`)

Pulls items (and optionally edges) from any JSON REST API.

| Field | Meaning |
|---|---|
| `base_url` | Endpoint URL (GET). Required. |
| `api_token` | Bearer token → `Authorization: Bearer …`. |
| `auth_query_param` | When set (e.g. `api_key`), the token is sent as this query parameter instead of a header. |
| `path_results` | Dot-notation path to the item array (e.g. `data.items`). Empty = the response itself is the array. |
| `path_id` / `path_label` / `path_url` / `path_type` | Field names per item (defaults `id`, `name`, `url`, none). |
| `max_items` | Items ingested per sync (0 = unlimited). Default 500. |
| `page_param` | Page-number query parameter (e.g. `page`). Empty = single request. |
| `page_size_param` / `page_size` | Per-page size parameter and value when paginating. |
| `edge_path` | Dot-notation path to the edges array (empty = no edges). |
| `edge_source_field` / `edge_target_field` / `edge_relation_field` | Edge mapping (defaults `source`, `target`, `relation`). |

The **Test** button now fetches the first page and verifies: HTTP status,
valid JSON, the results path resolving to an array, and that items carry
the configured label field. Paginated endpoints are walked page-by-page
until an empty page, the item cap, or 100 pages.

### Wikidata (`wikidata`)

Reconciliation-only driver using `wbsearchentities`. Configures the
search `language` (BCP 47, default `en`) and `min_confidence`
(default 0.6). Matching is label-similarity based; exact matches score
highest. Follows the [Wikidata data-access policy](https://www.wikidata.org/wiki/Wikidata:Data_access)
(user-agent + Accept-Encoding are set by the HTTP client).

### RSS / Atom / Sitemap (`rss_sitemap`)

Ingests feed items or sitemap URLs as nodes. `feed_type` overrides the
root-element auto-detection (`rss`, `atom`, `sitemap`). `node_type`
(default `article`) and `max_items` (default 100) apply. The Test button
verifies HTTP status and XML parseability.

### SPARQL Endpoint (`sparql`)

Runs one `SELECT` query against a SPARQL 1.1 endpoint with
`Accept: application/sparql-results+json`. The query must return
`?id ?label` (optionally `?type ?url`) for nodes and `?source ?target
?relation` for edges. The Test button probes the query capped at
`LIMIT 1` — queries that already carry a `LIMIT` are left untouched.

### WooCommerce (`woocommerce`)

Local-database driver (no HTTP): products plus their category/tag edges.
Requires WooCommerce active.

### CSV File Upload (`csv`)

Ingests rows from a Media Library attachment (`attachment_id`) or a file
path **inside the uploads directory** (`file_path` — anything outside is
refused). `field_map` is JSON mapping node fields to column names
(`label` required; `type` is a static value). `delimiter` and
`has_header_row` configure parsing.

### Webhook Receiver (`webhook`)

Pure-receiver driver. Producers POST JSON to
`/wp-json/nvoos-content-graph/v1/webhooks/{slug}` with an
`X-NVOOS-Signature` header = HMAC-SHA256 hex of the raw body, signed
with the configured `webhook_secret` (a `sha256=` prefix is accepted,
GitHub/Stripe style). Verified payloads are ingested through the
`records_path` + `field_map` mapping. Signature comparison is
timing-safe (`hash_equals`).

---

## Verification status

Each driver's behavior is pinned by PHPUnit tests under
`tests/Unit/Remote/Drivers/` (HTTP mocked via `pre_http_request`; the
webhook and CSV drivers are tested in-process):

| Driver | Verified behaviors |
|---|---|
| `generic_rest` | schema covers runtime config; path mapping; whole-array fallback; `max_items`; pagination walk; edge mapping; Bearer vs query-param auth; connection probe (counts, missing path, non-JSON); non-2xx yields empty |
| `wikidata` | single-encoding of search terms; exact/partial/empty matching; `min_confidence` gate; configured language |
| `rss_sitemap` | RSS/Atom/sitemap parsing; `max_items`; `feed_type` override; XML validation |
| `sparql` | binding mapping (nodes + edges); single-encoding of the query; `LIMIT 1` probe (no double-LIMIT); non-JSON failure |
| `woocommerce` | gated on WooCommerce being active (integration-tested via the main suite) |
| `csv` | header-row parsing; field map; `max_items`; uploads-dir path guard |
| `webhook` | HMAC verification (plain + prefixed, wrong signature, missing secret); records-path ingestion; single-object wrap |

Known limitations: the Generic REST driver is GET-only (no POST bodies);
the CSV driver requires the direct filesystem method (FTP-only
hosts must use a Media Library attachment, which resolves through
`get_attached_file`).
