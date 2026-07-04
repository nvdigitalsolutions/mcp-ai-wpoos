# Remote

## Purpose

Manages external data-source integration — a driver registry, HTTP client, enrichment orchestrator, state store, and crypto utilities for remote-source credentials. Core ships seven drivers (CSV, Webhook, Wikidata, Generic REST, RSS/Sitemap, SPARQL, WooCommerce); additional enterprise drivers ship in `nvoos-graphify-pro`.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | `NvoosGraphify\Plugin::register()` via `Remote\Registry` |
| **Optional dependencies** | None (HTTP client uses WordPress HTTP API) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphify\Remote\Registry` | `Registry.php` | `Plugin`, REST controller, Admin |
| `NvoosGraphify\Remote\HttpClient` | `HttpClient.php` | `Enricher`, remote drivers |
| `NvoosGraphify\Remote\Enricher` | `Enricher.php` | `Plugin` (cron enrichment) |
| `NvoosGraphify\Remote\Crypto` | `Crypto.php` | `StateStore` (credential encryption) |
| `NvoosGraphify\Remote\StateStore` | `StateStore.php` | Admin, REST (source config persistence) |

## Inputs / Outputs / Neighbors

- **Reads from:** Custom DB table (`nvoos_graphify_remote_sources`), WordPress options
- **Writes to:** Custom DB tables (remote sources, nodes, edges), WordPress options
- **Upstream callers:** `NvoosGraphify\Plugin` (composition root), `src/Rest/Controller`, `src/Admin/RemoteAdmin`
- **Downstream collaborators:** `src/Graph/Db` (graph writes), `src/Contracts/RemoteSource` (driver interface)
- **Events fired:** `nvoos_graphify/register_remote_sources`
- **Events listened to:** `nvoos_graphify/register_remote_sources`, `nvoos_graphify/cron_enrich`

## Conventions

- Drivers implement `NvoosGraphify\Contracts\RemoteSource` and are registered via `Registry::registerDriver()`.
- **Core ships seven drivers:** Wikidata, GenericRest, RssSitemap, Sparql, WooCommerce, Csv, and Webhook. Additional enterprise drivers (Jira, Slack, M365, etc.) are provided by the `nvoos-graphify-pro` addon.
- `HttpClient` delegates to `wp_remote_get`/`wp_remote_post` with URL validation. Advanced SSRF-safe validation is available in the pro addon.
- `Crypto` provides pass-through encrypt/decrypt. Credential encryption with a site-specific key is available in the pro addon. Core stores remote source configs as plain JSON.

## Tests

```bash
vendor/bin/phpunit --filter '/Remote|Registry|HttpClient|Enricher|Driver/'
```

## See Also

- Parent: [`../`](../) — src root
- Interface: [`../Contracts/RemoteSource.php`](../Contracts/RemoteSource.php)
- Collaborators: [`../Graph/Db.php`](../Graph/Db.php), [`../Rest/Controller.php`](../Rest/Controller.php)
