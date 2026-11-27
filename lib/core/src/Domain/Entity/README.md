# Domain Entities

## Purpose

Immutable value objects and command DTOs that carry data across the oOS core engine. All classes are `final readonly` (PHP 8.1+) — no setters, no mutation. Used by every contract, tool, and service.

## Tier

| | |
|---|---|
| **Distribution** | `nvoos/core` Composer package |
| **PHP target** | 8.1+ |
| **Dependencies** | none (pure PHP, no framework imports) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `ContentItem` | `ContentItem.php` | `ContentStoreInterface`, all content tools |
| `ContentQuery` | `ContentQuery.php` | `ContentStoreInterface::query()` |
| `ContentCollection` | `ContentCollection.php` | Paginated query results |
| `Product` | `Product.php` | Commerce aggregate: `ProductCatalogInterface`, `CommerceMigrator` |
| `ProductVariant` | `ProductVariant.php` | The sellable unit (SKU, price, stock, option values) |
| `ProductQuery` | `ProductQuery.php` | `ProductCatalogInterface::query()` |
| `ProductCollection` | `ProductCollection.php` | Paginated product results |
| `CommerceMigrationReport` | `CommerceMigrationReport.php` | `CommerceMigrator::migrate()` |
| `CreateContentCommand` | `CreateContentCommand.php` | `ContentStoreInterface::create()` |
| `UpdateContentCommand` | `UpdateContentCommand.php` | `ContentStoreInterface::update()` |
| `AuthContext` | `AuthContext.php` | `AuthProviderInterface::authenticate()` |
| `Credential` | `Credential.php` | `AuthProviderInterface::issueCredential()` |
| `UserInfo` | `UserInfo.php` | `AuthProviderInterface::getUserInfo()` |
| `StoredFile` | `StoredFile.php` | `FileStoreInterface` |
| `JobStatus` | `JobStatus.php` | `QueueClientInterface` |
| `HttpResponse` | `HttpResponse.php` | `HttpClientInterface` |

## Conventions

- All classes implement `\JsonSerializable` for safe REST/SSE encoding.
- Constructor promotion with `public readonly` properties.
- Zero business logic — data carriers only. Validation lives in the adapter layer.
- **Commerce exception:** `Product` refuses a blank name and two variants sharing
  an identity, and `Money`/`OptionSelection`/`ProductOption` validate on
  construction. Those four are the model's invariants — a variant with no
  identity makes every later lookup arbitrary, and a float amount is lossy — so
  they are enforced where the object is created rather than trusted to every
  adapter. `Product::optionErrors()` reports coherence problems instead of
  throwing, because an importer must be able to describe partial source data.

## Also Load

- [`../Contract/README.md`](../Contract/README.md)
- [`docs/proposals/cross-platform-extraction-architecture.md`](../../../../../docs/proposals/cross-platform-extraction-architecture.md)
