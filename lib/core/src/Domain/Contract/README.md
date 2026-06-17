# Domain Contracts

## Purpose

Holds every interface the oOS core engine exports — pure contracts with zero implementation — so that tools, providers, and platform adapters can depend on stable abstractions regardless of the host framework (WordPress, Laravel, CraftCMS).

## Tier

| | |
|---|---|
| **Distribution** | `nvoos/core` Composer package |
| **PHP target** | 8.1+ (readonly classes, enums, fibers, named arguments) |
| **Dependencies** | none (zero external contract dependencies) |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `ErrorFactoryInterface` | `ErrorFactoryInterface.php` | Every tool, provider, and service — canonical error envelope |
| `ContentStoreInterface` | `ContentStoreInterface.php` | Read/write tools (`GetPostTool`, `CreatePostTool`, etc.) |
| `AuthProviderInterface` | `AuthProviderInterface.php` | `GetUserInfoTool`, agentic loop auth context |
| `SettingsStoreInterface` | `SettingsStoreInterface.php` | All provider clients (API keys, base URLs) |
| `FileStoreInterface` | `FileStoreInterface.php` | `SearchAttachmentsTool`, image/audio generation tools |
| `CacheStoreInterface` | `CacheStoreInterface.php` | Domain-owned cache contract with transient-style API |
| `QueueClientInterface` | `QueueClientInterface.php` | Async tool execution, cron scheduling |
| `EventDispatcherInterface` | `EventDispatcherInterface.php` | Domain-owned event dispatcher with filter semantics |
| `HttpClientInterface` | `HttpClientInterface.php` | Domain-owned HTTP client contract |
| `ToolInterface` + 5 sub-interfaces | `ToolInterface.php` | Every tool in `../../Tool/` |

## Inputs / Outputs / Neighbors

- **Reads from:** nothing — contracts only.
- **Writes to:** nothing.
- **Upstream callers:** `Application/`, `Infrastructure/`, `Tool/`
- **Downstream collaborators:** `lib/wordpress-adapter/src/Adapter/` (WordPress implementations)

## Conventions

- Files contain **only** `interface` declarations and PHPDoc.
- **No** WordPress references anywhere — these describe abstractions that adapters implement.
- `null` means "not found"; never `false` or framework-specific error types.
- Return types use `mixed` where the concrete type varies by adapter.

## Tests

Interfaces are tested through their adapter implementations. See `lib/wordpress-adapter/tests/`.

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md)
- [`docs/proposals/cross-platform-extraction-architecture.md`](../../../../../docs/proposals/cross-platform-extraction-architecture.md)
