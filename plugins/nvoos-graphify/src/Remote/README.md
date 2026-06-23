# Remote

## Purpose

Manages external data-source integration — a driver registry, HTTP client stub, enrichment orchestrator stub, state store, and crypto utilities for remote-source credentials. Full implementations ship in the `nvoos-graphify-remote` addon.

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
- **No drivers ship in the core plugin.** Driver implementations (Wikidata, GenericRest, RssSitemap, Sparql, WooCommerce, Csv, Webhook) are provided by the `nvoos-graphify-remote` addon.
- `HttpClient` is a stub that delegates to `wp_remote_get`/`wp_remote_post`. SSRF-safe URL validation is deferred to the remote addon.
- `Crypto` is a stub (encrypt/decrypt are pass-through). Credential encryption is deferred to the remote addon. Core stores remote source configs as plain JSON.

## Tests

```bash
vendor/bin/phpunit --filter '/Remote|Registry|HttpClient|Enricher|Driver/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — SSRF, encryption

## See Also

- Parent: [`../`](../) — src root
- Interface: [`../Contracts/RemoteSource.php`](../Contracts/RemoteSource.php)
- Collaborators: [`../Graph/Db.php`](../Graph/Db.php), [`../Rest/Controller.php`](../Rest/Controller.php)
