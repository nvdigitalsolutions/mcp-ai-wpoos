# Okf

## Purpose

Wave E6 port surface (sub-cluster 4 — OKF). The **Open Knowledge Format
(OKF v0.1/v0.2)** engine from the base plugin's `includes/okf/` — a
vendor-neutral, agent- and human-friendly representation of curated
knowledge as a directory of markdown files with YAML frontmatter and
explicit cross-links (Google Cloud OKF spec, no SDK/runtime/API key).
Complementary to the vector store and the Paper Store: OKF handles
curated, authoritative knowledge with deterministic link-based
navigation. Ported into the AI addon per decision D4 (engine pieces
fold into `nvoos-content-graph-ai` under the `Engine\` namespace).

## Tier

| | |
|---|---|
| **Distribution** | AI addon (`nvoos-content-graph-ai`) — proprietary |
| **PHP target** | 8.1+ |
| **Loaded by** | `NvoosContentGraphAi\Plugin::registerEngine()` → `OkfBootstrap::register()` — standalone-only (`! defined('WP_MCP_AI_PATH')`); the base loader (`includes/bootstrap/loader.php` → `okf-init.php`) owns the same tool registration + generator hooks monolith |
| **Optional dependencies** | None (pure-PHP YAML parser for OKF's minimal frontmatter subset); the base `WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service` for atomic I/O when available (monolith-only) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `NvoosContentGraphAi\Engine\Okf\OkfParser` | `OkfParser.php` | Frontmatter extraction + YAML serialization |
| `NvoosContentGraphAi\Engine\Okf\OkfReader` | `OkfReader.php` | Bundle navigation, concept reading, traversal, search, trust tiers |
| `NvoosContentGraphAi\Engine\Okf\OkfWriter` | `OkfWriter.php` | Concept creation/soft-delete, index regeneration, bundle validation |
| `NvoosContentGraphAi\Engine\Okf\OkfBundleManager` | `OkfBundleManager.php` | Single source of truth for bundle paths + bundle lifecycle (list/create/rename/archive/delete, ZIP export/import, stats, log maintenance) |
| `NvoosContentGraphAi\Engine\Okf\OkfSkillKnowledgeGenerator` | `OkfSkillKnowledgeGenerator.php` | Auto-generates the `skill-knowledge` bundle from bundled skills |
| `NvoosContentGraphAi\Engine\Okf\OkfBootstrap` | `OkfBootstrap.php` | Wraps the base `okf-init.php` hook surface |

## Inputs / Outputs / Neighbors

- **Reads from:** file system at `wp-content/uploads/mcp-ai-wpoos/knowledge/`
  (OKF bundles); bundled skills at `includes/bundled-skills/` (base + Pro
  monolith; the addon's own root standalone — none shipped today).
- **Writes to:** same file system (atomic writes via the base Filesystem
  Service monolith, native `file_put_contents( …, LOCK_EX )` standalone)
  plus `.htaccess` / `index.php` guards and the `.trash/` archive
  directory.
- **Upstream callers:** any addon code via `OkfReader` / `OkfWriter` /
  `OkfBundleManager`; the future OKF tool wave (the base's ten
  `okf_*` MCP tools remain base-owned until then — documented
  deferral).
- **Downstream collaborators:** `OkfParser`; (monolith)
  `WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service`.
- **Events fired:** `wp_mcp_ai_okf_bundle_initialized`,
  `wp_mcp_ai_okf_concept_saved`, `wp_mcp_ai_okf_concept_deleted`.
- **Events listened to:** `wp_mcp_ai_bootstrapped` (priority 32,
  dormant standalone — no standalone emitter yet).

## Conventions

- **Per-mode discriminator is `defined( 'WP_MCP_AI_PATH' )`** — never
  bare `class_exists()` (the monorepo classmap resolves base classes
  standalone).
- One file = one class. All direct filesystem access stays in
  `OkfReader` / `OkfWriter`; every consumer resolves bundles through
  `OkfBundleManager` (single audited resolver, `realpath` containment).
- Bundle protection: `skill-knowledge` is auto-generated and protected
  (`okf_protected_bundle` on write/delete); curated content belongs in
  `site-knowledge`. Bundle roots carry `okf_version: "0.2"` (OKF v0.2
  §12) and `.htaccess`/`index.php` guards deny HTTP access.
- Reserved filenames (`index.md`, `log.md`) are never written
  directly; soft-deletes rename to `.deleted.<timestamp>`.
- Byte-identical constants/option keys/error codes/hook names with the
  base — including the `mcp-ai-wpoos/knowledge` default subdirectory
  (data survives mode transitions) and the ZIP import defenses.
  Deviations documented in the class docblocks (PSR-4 class names,
  inline requires → autoload + static `register()`, text domain,
  per-mode collaborator seams, the generator's per-mode fingerprint
  and skills-source roots).
- The base's ten `okf_*` MCP tools are deferred to the OKF tool wave —
  the engine is consumed directly until then.

## Tests

- `tests/Ecosystem/test-okf-core.php` — parser, reader, writer
  (frontmatter, navigation, traversal, search, trust tiers, validation
  report, soft-delete, index regeneration, log.md).
- `tests/Ecosystem/test-okf-bundle-manager.php` — bundle manager,
  generator, bootstrap (resolution/containment, lifecycle, ZIP
  round-trip + defenses, skill generation, priority-32 hook surface).

```bash
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-okf-core.php
vendor/bin/phpunit -c plugins/nvoos-content-graph-ai/phpunit-ecosystem.xml.dist plugins/nvoos-content-graph-ai/tests/Ecosystem/test-okf-bundle-manager.php
```

## Also Load

- [`../README.md`](../README.md) — the Engine wave (OOS + markup + paper-store + OKF)
- [`../../README.md`](../../README.md) — composition root + subsystem index
- [`../../../../.context/conventions.md`](../../../../.context/conventions.md) — naming + style
- [`../../../../.context/security-checklist.md`](../../../../.context/security-checklist.md) — path traversal + ZipSlip

## See Also

- [`docs/project/plans/ecosystem-port-cluster-loop.md`](../../../../docs/project/plans/ecosystem-port-cluster-loop.md) — cluster ordering + pipeline
- [`docs/project/ecosystem-port-tracker.md`](../../../../docs/project/ecosystem-port-tracker.md) — E6 row status
- [`includes/okf/`](../../../../includes/okf/) + [`includes/tools/okf/`](../../../../includes/tools/okf/) — the base subsystem (the port's origin) and its tool surface
- [`docs/features/okf-integration.md`](../../../../docs/features/okf-integration.md) — OKF conformance and skill integration
