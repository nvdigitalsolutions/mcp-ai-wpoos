# `nvoos/database-kernel`

The scoped-access kernel for Proposal 167. Plain PHP: no WordPress symbols, no
framework, no I/O. It decides *what* a scoped database read compiles to and
*whether* it is allowed; it never opens a connection.

## Why it lives here

Not in `lib/core`. A **base+pro install** is the WordPress.org base package with
this add-on unpacked into `addons/pro/`, and that base package strips `lib/`
entirely (`bin/build-plugin-zip.sh`, `--exclude '/lib'`). A kernel in `lib/core`
would therefore be missing for exactly the customers who bought Pro. Decision
**D15** moved it here; `lib/core` remains the home of the OOS chat engine, which
this package does not use.

Ships in: the Pro add-on zip, and therefore in the complete build too.

## Public surface

| Namespace | What it is |
|---|---|
| `Nvoos\Database\Domain\Contract\DatabaseQueryInterface` | The port a host adapter implements |
| `Nvoos\Database\Domain\Service\Database\*` | Grammar, admission, scope policy, shaping, masking, cursors, error mapping, refusal codes |
| `Nvoos\Database\Domain\ValueObject\Database\*` | Descriptors and query objects |

Entry points, in dependency order:

1. `ScopePolicy` — tiers, relation/column authorisation, mandatory row filter.
2. `StatementGrammar` — emits SQL containing only framework syntax and `:pN`
   placeholders; identifiers are resolved from the catalogue, never interpolated.
3. `AdmissionClassifier` — layer-2 courtesy: single statement, `SELECT`/`WITH`
   only, no `SET`, no file/OS functions.
4. `ResultShaper` / `ColumnMasker` — caps, numeric fidelity, `[binary]`,
   `sensitive` masking.
5. `CursorCodec` — signed keyset cursors bound to scope, relation, ordering and
   direction.
6. `ErrorMapper` — SQLSTATE/driver-code to `RefusalCode`.

## Neighbours

| Direction | Component |
|---|---|
| Consumer (WordPress) | `includes/database/` — PDO executor, probe, registries (Proposal 167 P0/P1) |
| Consumer (Laravel) | `lib/laravel-adapter/src/Adapter/PdoDatabaseConnection.php`, `EloquentDatabaseConnection.php` |
| Autoloader | `autoload.php` (WordPress, four-line PSR-4) · Composer path repository (Laravel) |
| Enforced by | `bin/check-domain-purity.php` → `addons/pro/lib/database/src` is **strict** |

## Conventions

- `declare(strict_types=1)` in every file; `final` (and `readonly` where the shape
  is immutable) classes; one object structure per file.
- **No** `get_option`, `apply_filters`, `WP_Error`, `$wpdb`, `file_get_contents`,
  `curl_*` — the purity gate fails the build on any of them.
- Errors are `DatabaseRefusal` carrying a `RefusalCode`. A host maps the code to
  its own wire shape; driver messages are never read, only SQLSTATE/driver numbers.
- PHP floor is **8.2** (class-level `readonly`). That is this package's floor, not
  the plugin's: a host must gate availability on `PHP_VERSION_ID >= 80200` before
  autoloading anything here.

## Tests

No WordPress, no Composer:

```bash
php lib/core/vendor/bin/phpunit -c addons/pro/lib/database/phpunit.xml.dist
```

`tests/bootstrap.php` registers two PSR-4 prefixes and nothing else, so the suite
passing *is* the host-independence proof. 151 tests / 211 assertions cover the
grammar, admission corpus, scope policy, cursors, shaping, masking, error mapping
and the fail-closed value objects.

## Also load

- `docs/project/proposals/167-database-toolkit-scoped-access-proposal.md` — design, threat model, decisions
- `docs/project/proposals/167-database-toolkit-scoped-access-research.md` — sources and open questions
- `docs/project/proposals/167-database-toolkit-scoped-access-implementation-plan.md` — phases and gates
