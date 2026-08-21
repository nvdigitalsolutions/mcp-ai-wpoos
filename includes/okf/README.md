# okf/

## Purpose

Provides the **Open Knowledge Format (OKF v0.1)** engine — a vendor-neutral,
agent- and human-friendly representation of curated knowledge as a directory
of markdown files with YAML frontmatter and explicit cross-links. Implements
the Google Cloud OKF specification without requiring any SDK, runtime, or
API key.

OKF is complementary to the existing vector-store (Tool Embedding Store) and
flat-file store (Paper Store): OKF handles curated, authoritative knowledge
with deterministic link-based navigation.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/bootstrap/loader.php` → `okf-init.php`; hooks `wp_mcp_ai_bootstrapped` at priority 32 |
| **Optional dependencies** | None (pure-PHP YAML parser for OKF's minimal frontmatter subset) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_OKF_Parser` | `class-wp-mcp-ai-okf-parser.php` | Frontmatter extraction |
| `WP_MCP_AI_OKF_Reader` | `class-wp-mcp-ai-okf-reader.php` | Bundle navigation, concept reading, traversal |
| `WP_MCP_AI_OKF_Writer` | `class-wp-mcp-ai-okf-writer.php` | Concept creation, bundle validation |
| `WP_MCP_AI_OKF_Skill_Knowledge_Generator` | `class-wp-mcp-ai-okf-skill-knowledge-generator.php` | Auto-generates the `skill-knowledge` bundle from `includes/bundled-skills/` |

## Inputs / Outputs / Neighbors

- **Reads from:** File system at `wp-content/uploads/mcp-ai-wpoos/knowledge/` (OKF bundles), bundled skills at `includes/bundled-skills/`.
- **Writes to:** Same file system (atomic writes via `WP_MCP_AI_Filesystem_Service`). On bootstrap (priority 32) the skill-knowledge bundle is (re)generated from bundled skills whenever it is missing or the plugin version changed; the admin "Install/Force Reinstall Bundled Skills" action refreshes it too.
- **Upstream callers:** MCP tools (primary), any plugin code via `WP_MCP_AI_OKF_Reader`.
- **Downstream collaborators:** `WP_MCP_AI_Filesystem_Service` (atomic I/O), `WP_MCP_AI_Logger` (error logging), `WP_MCP_AI_Tool_Registry` (tool registration).
- **Events fired:** `wp_mcp_ai_okf_bundle_initialized`, `wp_mcp_ai_okf_concept_saved`, `wp_mcp_ai_okf_concept_deleted`.

## Conventions

- One file = one class. OKF engine classes live here; MCP tools live in `includes/tools/okf/`.
- Pure-PHP YAML parser handles only the subset needed for OKF frontmatter (scalars, lists, key-value pairs). No external YAML library dependency.

## Tests

```bash
vendor/bin/phpunit tests/manual/test-okf-v02-parser.php
vendor/bin/phpunit tests/manual/test-okf-v02-reader.php
vendor/bin/phpunit tests/manual/test-okf-v02-validate-attestation.php
```

OKF tests live under `tests/manual/` (they exercise real bundle files on disk). Parser/reader behavior and attestation validation are covered; the writer is exercised through the MCP tool suite.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (always)
- [`.context/settings-storage.md`](../../.context/settings-storage.md) — file-system + option boundaries
- [`docs/features/okf-integration.md`](../../docs/features/okf-integration.md) — OKF v0.1 conformance and skill integration
