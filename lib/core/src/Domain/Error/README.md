# Domain Errors

## Purpose

Typed exception classes for the oOS core engine. Each carries structured context (user ID, resource type, capability, field errors) so callers can produce precise error responses without parsing string messages.

## Tier

| | |
|---|---|
| **Distribution** | `nvoos/core` Composer package |
| **PHP target** | 8.1+ |

## Public Surface

| Symbol | HTTP | Used when |
|---|---|---|
| `DomainError` | — | Standalone error (no framework). Serializable, framework-agnostic. |
| `AccessDeniedException` | 403 | User lacks capability for an operation |
| `NotFoundException` | 404 | Resource (post, user, file) does not exist |
| `ValidationException` | 422 | Input data fails schema or business rules |
| `AuthenticationException` | 401 | Token invalid, expired, or missing |

## Conventions

- All extend `\RuntimeException` and carry an HTTP status code.
- Additional `public readonly` properties carry structured context.
- Adapters translate these exceptions into framework-native errors (WP_Error, Laravel HTTP exceptions).

## Also Load

- [`../Contract/ErrorFactoryInterface.php`](../Contract/ErrorFactoryInterface.php)
