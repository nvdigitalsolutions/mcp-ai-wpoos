# Contracts

## Purpose

Defines the two extension interfaces that govern the entire plugin — `Tool` for executable operations and `RemoteSource` for external data-source drivers.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | Autoloader (PSR-4) — consumed by `ToolRegistry`, `Remote\Registry`, and all implementations |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphify\Contracts\Tool` | `Tool.php` | `ToolRegistry`, all 14 built-in tools, addon tools |
| `NvoosGraphify\Contracts\RemoteSource` | `RemoteSource.php` | `Remote\Registry`, all 7 remote drivers |

## Inputs / Outputs / Neighbors

- **Reads from:** Nothing directly (interfaces only)
- **Writes to:** Nothing directly (interfaces only)
- **Upstream callers:** `NvoosGraphify\ToolRegistry` (tool contract), `NvoosGraphify\Remote\Registry` (driver contract)
- **Downstream collaborators:** `src/Tools/` (implements Tool), `src/Remote/Drivers/` (implements RemoteSource), `nvoos-graphify-ai/src/Tools/` (implements Tool)

## Conventions

- Interfaces are the ONLY public contract — callers type-hint against them, never against concrete classes.
- Every tool MUST implement `Tool`; every remote driver MUST implement `RemoteSource`.
- Interface methods include PHPDoc with `@since 1.0.0` tags.

## Tests

```bash
# No dedicated tests — contracts are exercised through implementor tests
vendor/bin/phpunit --filter '/Tool|RemoteSource/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style

## See Also

- Implementors: [`../Tools/`](../Tools/), [`../Remote/`](../Remote/)
- Addon implementors: [`../../nvoos-graphify-ai/src/Tools/`](../../nvoos-graphify-ai/src/Tools/)
