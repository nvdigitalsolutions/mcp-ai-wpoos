# REST

## Purpose

Exposes the knowledge graph via WordPress REST API under the `nvoos-content-graph/v1` namespace — graph queries, node retrieval, search, build triggers, export, remote-source CRUD, and webhook ingestion.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `NvoosContentGraph\Plugin::register()` on `rest_api_init` |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraph\Rest\Controller` | `Controller.php` | `Plugin::register()` (REST route registration) |

## Inputs / Outputs / Neighbors

- **Reads from:** REST request params, custom DB tables (via `src/Graph/Db`), `nvoos_content_graph_settings` option
- **Writes to:** Custom DB tables (remote sources), triggers graph builds, `WP_REST_Response` / `WP_Error`
- **Upstream callers:** WordPress REST API, frontend JS (Cytoscape viewer), AI addon chat
- **Downstream collaborators:** `src/Graph/Db` (queries, mutations), `src/Graph/Builder` (build triggers), `src/Graph/Exporter` (export), `src/Remote/Registry` (source management)
- **Events fired:** None (REST handlers return responses directly)
- **Events listened to:** `rest_api_init`

### REST Endpoints

| Method | Path | Description | Auth |
|---|---|---|---|
| `GET` | `/graph` | Get full graph data | `read` + guest token |
| `GET` | `/nodes` | List/paginate nodes | `read` + guest token |
| `GET` | `/nodes/{id}` | Get single node with edges | `read` + guest token |
| `POST` | `/build` | Trigger a graph build | `manage_options` |
| `GET` | `/search` | Search nodes by label | `read` + guest token |
| `GET` | `/export` | Export graph (all formats) | `manage_options` |
| `POST` | `/retrieve` | Retrieve RAG context for a post | `read` + guest token |
| `GET` | `/resolve` | Resolve external entity | `read` + guest token |
| `GET` | `/sources` | List remote sources | `manage_options` |
| `POST` | `/sources` | Create remote source | `manage_options` |
| `DELETE` | `/sources/{id}` | Delete remote source | `manage_options` |
| `POST` | `/sources/{id}/sync` | Trigger remote source sync | `manage_options` |
| `POST` | `/sources/{id}/test` | Test remote source connection | `manage_options` |
| `POST` | `/webhooks/{slug}` | Receive webhook payload | Public (validated) |

## Conventions

- Read endpoints require the `read` capability (all logged-in users) or a valid guest token. Write endpoints require `manage_options`.
- The `/export` endpoint requires `manage_options` — bulk data export is an administrative operation.
- Webhook endpoint validates source configuration and HMAC signature before accepting payloads.
- Graph data responses use the canonical node/edge shape from `src/Graph/Db`.
- The graph contains only public content: public post types, public taxonomies, and user display names. No private or non-public data is exposed through read endpoints.

## Tests

```bash
vendor/bin/phpunit --filter '/REST|RestController/'
```

## See Also

- Parent: [`../`](../) — src root
- Collaborators: [`../Graph/`](../Graph/), [`../Remote/`](../Remote/)
- AI addon REST: [`../../../nvoos-content-graph-ai/src/Rest/ChatController.php`](../../../nvoos-content-graph-ai/src/Rest/ChatController.php)
