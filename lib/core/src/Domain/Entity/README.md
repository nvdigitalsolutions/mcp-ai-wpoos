# Domain Entities

## Purpose

Immutable value objects and command DTOs that carry data across the oOS core engine. All classes are `final readonly` (PHP 8.1+) — no setters, no mutation. Used by every contract, tool, and service.

## Tier

| | |
|---|---|
| **Distribution** | `oos/core` Composer package |
| **PHP target** | 8.1+ |
| **Dependencies** | none (pure PHP, no framework imports) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `ContentItem` | `ContentItem.php` | `ContentStoreInterface`, all content tools |
| `ContentQuery` | `ContentQuery.php` | `ContentStoreInterface::query()` |
| `ContentCollection` | `ContentCollection.php` | Paginated query results |
| `CreateContentCommand` | `CreateContentCommand.php` | `ContentStoreInterface::create()` |
| `UpdateContentCommand` | `UpdateContentCommand.php` | `ContentStoreInterface::update()` |
| `AuthContext` | `AuthContext.php` | `AuthProviderInterface::authenticate()` |
| `Credential` | `Credential.php` | `AuthProviderInterface::issueCredential()` |
| `UserInfo` | `UserInfo.php` | `AuthProviderInterface::getUserInfo()` |
| `StoredFile` | `StoredFile.php` | `FileStoreInterface` |
| `JobStatus` | `JobStatus.php` | `QueueClientInterface` |

## Conventions

- All classes implement `\JsonSerializable` for safe REST/SSE encoding.
- Constructor promotion with `public readonly` properties.
- Zero business logic — data carriers only. Validation lives in the adapter layer.

## Also Load

- [`../Contract/README.md`](../Contract/README.md)
- [`docs/proposals/cross-platform-extraction-architecture.md`](../../../../../docs/proposals/cross-platform-extraction-architecture.md)
