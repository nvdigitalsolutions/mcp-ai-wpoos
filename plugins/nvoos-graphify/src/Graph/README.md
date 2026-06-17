# Graph

## Purpose

The graph engine — database layer, content detection, structural extraction, semantic analysis, community detection, graph building, export, and reporting.

## Tier

| | |
|---|---|
| **Distribution** | Core plugin |
| **PHP target** | 8.1+ |
| **License** | GPL-3.0-or-later |
| **Loaded by** | Autoloader (PSR-4); wired by `NvoosGraphify\Plugin` |
| **Optional dependencies** | None |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosGraphify\Graph\Db` | `Db.php` | Builder, Tools, REST, Frontend, Admin — **the sole DB access layer** |
| `NvoosGraphify\Graph\Builder` | `Builder.php` | `Plugin` (cron, save_post, initial build) |
| `NvoosGraphify\Graph\Detector` | `Detector.php` | `Builder` |
| `NvoosGraphify\Graph\StructuralExtractor` | `StructuralExtractor.php` | `Builder` |
| `NvoosGraphify\Graph\SemanticExtractor` | `SemanticExtractor.php` | `Builder` (Phase 2+) |
| `NvoosGraphify\Graph\Analyzer` | `Analyzer.php` | `Builder` (community detection) |
| `NvoosGraphify\Graph\Exporter` | `Exporter.php` | REST controller |
| `NvoosGraphify\Graph\Report` | `Report.php` | Admin, REST |

## Inputs / Outputs / Neighbors

- **Reads from:** WordPress posts/terms/users/media (via Detector), custom DB tables (`nvoos_graphify_nodes`, `_edges`, `_meta`, `_embeddings`)
- **Writes to:** Custom DB tables (nodes, edges, meta, embeddings), transients (report cache)
- **Upstream callers:** `NvoosGraphify\Plugin` (build triggers), `src/Rest/Controller` (queries, export), `src/Tools/` (graph tools), `src/Frontend/` (viewer data), `src/Admin/` (stats, export)
- **Downstream collaborators:** `src/Schema` (table names, option keys), `src/Settings` (config)
- **Events fired:** `nvoos_graphify/before_build`, `nvoos_graphify/after_build`
- **Events listened to:** None (called directly)

## Conventions

- `Db` is a static facade over `$wpdb` — all raw SQL lives here, no DB queries in other folders.
- Custom tables use `nvoos_graphify_` prefix and are created via `dbDelta` on activation.
- `Builder::build()` is idempotent — safe to call repeatedly from cron.
- Extraction pipeline is: Detector → StructuralExtractor → Db (batch upserts) → Analyzer (communities).

## Tests

```bash
vendor/bin/phpunit --filter '/Graph|Builder|Db|Detector|Analyzer/'
```

## Also Load

- [`../../../.context/conventions.md`](../../../.context/conventions.md) — naming + style
- [`../../../.context/security-checklist.md`](../../../.context/security-checklist.md) — SQL preparation

## See Also

- Parent: [`../`](../) — src root
- Collaborators: [`../Schema.php`](../Schema.php) (table/column constants), [`../Settings.php`](../Settings.php)
- Consumed by: [`../Tools/`](../Tools/), [`../Rest/`](../Rest/), [`../Frontend/`](../Frontend/), [`../Admin/`](../Admin/)
