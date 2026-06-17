# {Folder Name}

<!--
  NV oOS Folder README Template — v1.0 (June 2026)

  This template is the canonical shape for every `README.md` that lives in an
  `includes/**` subdirectory (Base) or `addons/pro/includes/**` subdirectory (Pro).

  Rationale: This file applies Unix theory's "rule of transparency" + "rule of
  representation" at the directory level. Each folder must be able to introduce
  itself: what it does (one thing), what it exports, who calls it, and where to
  go next. See `docs/developer/folder-readme-convention.md` for the full
  rationale and `docs/project/proposals/UNIX_THEORY_COMPLIANCE_ENHANCEMENT_PROPOSAL.md`
  §P7 for the proposal context.

  LAYERING RULE (mirrors `AGENTS.md` §2): folder READMEs MUST NOT restate
  naming conventions, security rules, or PHP-compat rules. They link to the
  canonical sources only:
    - `.context/conventions.md`        — naming + style
    - `.context/security-checklist.md` — security
    - `CLAUDE.md`                      — PHP compat, tool patterns
    - `AGENTS.md`                      — agent inventory + coordination

  Keep this file under ~150 lines. If you need more, split the deeper material
  into `docs/` and link to it from here.

  Required H2 sections (enforced by `bin/check-folder-readmes.php`):
    1. Purpose
    2. Tier
    3. Public Surface
    4. Inputs / Outputs / Neighbors
    5. Conventions
    6. Tests
    7. Also Load

  Delete this HTML comment block before committing the filled-in README.
-->

## Purpose

One sentence. State the single responsibility of this folder ("Unix rule:
do one thing well"). If you need two sentences, the folder is probably doing
two things and should be split.

> Example: "This folder houses every PHPCS-compliant tool that mutates the
> WordPress posts table — and nothing else."

## Tier

| | |
|---|---|
| **Distribution** | Base / Pro / Both |
| **PHP target** | 7.4+ / 8.1+ |
| **Loaded by** | (the bootstrap or registry file that wires this folder in) |
| **Optional dependencies** | (e.g. JetEngine, WooCommerce — or "none") |

## Public Surface

What other folders are allowed to depend on. List the classes, interfaces,
traits, or functions that form this folder's *external* contract. Anything not
listed here is considered internal and may change without notice.

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Example_Service` | `class-wp-mcp-ai-example-service.php` | `includes/rest/`, `includes/cli/` |
| `wp_mcp_ai_example_helper()` | `helpers.php` | tools, tests |

## Inputs / Outputs / Neighbors

The composability section. Make the seams explicit.

- **Reads from:** (config options, post meta, transients, REST input…)
- **Writes to:** (DB tables, options, files, external APIs…)
- **Upstream callers:** (which folders invoke code in here)
- **Downstream collaborators:** (which folders this code calls into)
- **Events fired:** (action/filter hooks emitted from this folder)
- **Events listened to:** (action/filter hooks consumed by this folder)

## Conventions

Only conventions **specific to this folder** that go beyond the canonical
project-wide rules. Examples of folder-specific deltas:

- "All classes here must implement `WP_MCP_AI_Tool_Interface`."
- "Files here must be PHP 8.1+ — this folder is Pro-only."
- "Direct WordPress API calls are forbidden — use the `infrastructure/`
  adapter (see [`ADR-001`](../../docs/project/architecture-decisions/ADR_001_module_boundaries.md))."

If you find yourself restating naming/security/PHP-compat rules here, **stop
and link to `.context/conventions.md` instead**.

## Tests

Where the PHPUnit tests for this folder live, and how to run just this slice.

```bash
vendor/bin/phpunit tests/path/to/this-folder/
```

If coverage is intentionally partial, say so and why.

## Also Load

When an AI agent (or human) is working in this folder, they should also load
the following canonical context files for the GSD 30% budget:

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security (always)
- [`.context/{subsystem}.md`](../../.context/) — e.g. `tool-registry.md`, `rest-api.md`, `chat-ui.md`, `testing.md`, `pro-vs-base.md`
- (Optional) any `docs/` reference relevant to this folder

## See Also

- Upstream parent: [`includes/`](../) (Base) or [`addons/pro/includes/`](../) (Pro)
- Sibling folders worth knowing about: (list 2–4 if relevant)
- Related ADRs / proposals in `docs/`: (list if relevant)

---

<!--
  House-keeping:
    - Update this README when the folder's responsibility, public surface, or
      neighbors change. Drift between code and this file is a code-review smell.
    - The `bin/check-folder-readmes.php` script will flag missing READMEs and
      missing required sections. Run `composer run docs:check-folder-readmes`
      before opening a PR if you've added or removed files in `includes/`.
-->
