# Remote Drivers

## Purpose

Concrete implementations of `NvoosGraphify\Contracts\RemoteSource` — each driver ingests data from a specific external source type into graph nodes.

## Drivers

| Driver | Class | Capabilities |
|---|---|---|
| CSV File Upload | `Csv` | `fetch_nodes` |
| Generic REST API | `GenericRest` | `fetch_nodes`, `fetch_edges` |
| RSS / Sitemap | `RssSitemap` | `fetch_nodes` |
| SPARQL Endpoint | `Sparql` | `fetch_nodes` |
| Webhook Receiver | `Webhook` | `webhooks` |
| Wikidata | `Wikidata` | `fetch_nodes`, `reconcile` |
| WooCommerce | `WooCommerce` | `fetch_nodes` |

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |

## Conventions

- All drivers implement `NvoosGraphify\Contracts\RemoteSource`.
- Drivers are registered via `Registry::registerDriver()`.
- `CSV` driver uses `WP_Filesystem` for file access (no direct filesystem calls).

## Neighbors

- Parent: [`../`](../) — Remote directory
- Interface: [`../../Contracts/RemoteSource.php`](../../Contracts/RemoteSource.php)
- Registry: [`../Registry.php`](../Registry.php)
