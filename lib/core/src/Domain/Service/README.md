# Domain Services

## Purpose

Houses pure business-logic service implementations — classes that contain domain algorithms with **zero framework dependencies**. These services depend only on PHP 8.1+ and domain contracts. They are the "inner hexagon" of the Hexagonal Architecture.

## Tier

| | |
|---|---|
| **Distribution** | `lib/core` (framework-agnostic) |
| **PHP target** | 8.1+ |
| **Loaded by** | PSR-4 autoloader (`Nvoos\Core\Domain\Service\*`) |
| **Optional dependencies** | None — no WordPress, no HTTP, no database |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `Nvoos\Core\Domain\Service\Budget\DataBudgetTracker` | `Budget/DataBudgetTracker.php` | ChatOrchestrator, tool execution loop |
| `Nvoos\Core\Domain\Service\Optimization\ErlangC` | `Optimization/ErlangC.php` | Queue analysis tools |
| `Nvoos\Core\Domain\Service\Text\SemanticCompressor` | `Text/SemanticCompressor.php` | Context compression (TBD — adapter bridges to legacy) |

## Subdirectories

| Folder | Purpose |
|---|---|
| `Budget/` | Byte/token budget tracking and accounting |
| `Text/` | Text processing (compression, chunking, summarization) |
| `Tool/` | Tool lifecycle descriptors, chain prediction |
| `Optimization/` | Math/algorithms (PSO, Erlang C, code optimization) |
| `Validation/` | Input validation, schema validation |
| `Memory/` | Memory hygiene, garbage collection helpers |

## Conventions

- **One class per file**, PSR-4 namespaced under `Nvoos\Core\Domain\Service\*`.
- **All classes use `declare(strict_types=1)`** and PHP 8.1+ features (readonly, enums, named arguments).
- **Zero WordPress references.** No `get_option`, `apply_filters`, `WP_Error`, `ABSPATH` guards, or `wp_parse_args`. If a service needs platform-specific behavior, it goes through a domain contract.
- **Interface-first:** every service implements a domain contract from `Domain/Contract/`. Callers depend on the interface, never the concrete class.
- **Stateless where possible:** Tier 0 services should be stateless or per-request scoped. Stateful services (e.g., memory managers) are instantiated per-request.
- **Tests live in `tests/lib/core/Domain/Service/`** and use PHPUnit without WordPress bootstrap.

## Also Load

- [`../Contract/README.md`](../Contract/README.md) — domain contracts implemented here
- [`../../Application/README.md`](../../Application/README.md) — orchestrators that consume these services
- [`../../../docs/project/proposals/ai-orchestration-services-lib-core-migration-plan.md`](../../../../docs/project/proposals/ai-orchestration-services-lib-core-migration-plan.md) — migration plan
