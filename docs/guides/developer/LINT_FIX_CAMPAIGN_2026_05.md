# Lint Fix Campaign — May 2026

## Status Summary

| Area | Errors | Status |
|------|--------|--------|
| 14 non-pro addons | 0 | ✅ Clean |
| Pro non-tools | 0 | ✅ Clean |
| Pro tools | 1,143 | ⚠️ Partial |
| **Total (from 7,318)** | **1,143** | **84% reduction** |

## What Was Done

### phpcs.xml.dist Changes
- All addons excluded from `WordPress.Files.FileName` and `PEAR.NamingConventions.ValidClassName` (addons use `NVOOS_*` naming)
- Removed blanket excludes for `*/addons/pro/*` and `*/addons/saas-controller/*`
- Excluded `cloud-worker/wp-config-local.php` (dev config, not addon code)
- Excluded 8 files triggering PHPCSUtils `AbstractArrayDeclarationSniff` parse error:
  - `crm/class-wp-mcp-ai-tool-crm-email-search-accounting.php`
  - `crm/class-wp-mcp-ai-tool-crm-email-search-correspondence.php`
  - `crm/class-wp-mcp-ai-tool-crm-email-search-leads.php`
  - `crm/class-wp-mcp-ai-tool-crm-capture-interaction.php`
  - `crm/class-wp-mcp-ai-tool-get-companies.php`
  - `crm/class-wp-mcp-ai-tool-research-company.php`
  - `financial-planning/class-wp-mcp-ai-tool-cash-flow-analyzer.php`
  - `financial-planning/class-wp-mcp-ai-tool-net-worth-calculator.php`

### Clean Addons (0 errors)
`algorave`, `canvas`, `canvas-toolkit`, `chat-spa`, `cloud-worker`, `cornerstone3d`, `docs-hub`, `document-editor`, `embedded`, `fantasy-football`, `graphify`, `media-studio`, `toolkit-shell`, `saas-controller`

### Pro Addon — Clean Areas
`admin/`, `mcp-servers/`, `services/`, `rest/`, `cli/`, `para/`, `qms/`, `tests/`, `vault/`, `build/`, `includes/` root files, `src/`

## Remaining Errors: Pro Tools (1,143 errors in 321 files)

### Error Breakdown

| Category | Count | Effort | How to Fix |
|----------|-------|--------|------------|
| Missing function doc comments | ~600 | Hard | Per-method descriptions needed |
| Missing @param tags | ~145 | Medium | Add `@param array $arguments`, `@param array $context` |
| Short ternaries (`?:`) | ~140 | Medium | Expand to `$x ? $x : $y` |
| Yoda conditions | ~39 | Easy | Reverse: `'value' === $var` |
| Doc comment capitalization | ~20 | Easy | Capitalize first letter |
| Inline comment periods | ~16 | Easy | Add `.` at end |
| date() restricted | ~12 | Easy | Add `// phpcs:ignore` |
| Missing short descriptions | ~10 | Easy | Add description to docblock |
| PreparedSQL | ~9 | Medium | Add `// phpcs:ignore` or use `$wpdb->prepare()` |
| Heredoc | ~7 | Medium | Replace with concatenated strings |
| Other misc | ~125 | Mixed | Various |

### Key Directories Still Needing Work

```
video-production/    — ~78 errors (13 files, ~6 each)
site-creator-toolkit/ — ~30 errors
social-media/        — ~25 errors
multilingual/        — ~20 errors
calendar-booking/    — ~15 errors
law-firm/            — ~10 errors
architectural-design/ — ~10 errors
Root tool files      — ~200 errors (class-wp-mcp-ai-tool-*.php)
```

### MCP Server Directories (all 27 already clean)
`ai-tool-builder`, `analytics`, `architect-agent`, `architectural-design`, `calendar-booking`,
`chat-channels`, `cre-debt`, `crm`, `dj-management`, `document-generation`, `eca`, `ecommerce`,
`extended-cognition`, `financial-planner`, `healthcare`, `healthcare-imaging`, `healthcare-wellness`,
`image-production`, `law-firm`, `media`, `multilingual`, `project-management`,
`regulatory-registration`, `site-creator`, `social-media`, `video-production`

## How to Continue

### 1. Run the automated fixers (safe, indentation-aware)

```bash
# Add docblocks to standard tool methods (execute, get_slug, etc.)
php bin/fix-lint-missing-docblocks.php

# Fix inline comments, Yoda conditions, comment closers
php bin/fix-lint-common.php
```

### 2. Run phpcbf for auto-fixable formatting

```bash
# Run one directory at a time to avoid memory exhaustion
vendor/bin/phpcbf -d memory_limit=256M addons/pro/includes/tools/<dirname>/
```

**Important**: 8 files cause a PHPCS parse error (PHPCSUtils bug with PHP 8.3).
These are already excluded in `phpcs.xml.dist`. Do not modify them — they will crash phpcs/phpcbf.

### 3. Fix remaining errors file by file

```bash
# Check current status
vendor/bin/phpcs -d memory_limit=512M --error-severity=1 --warning-severity=8 --report=source addons/pro/includes/tools/

# Fix one directory at a time
vendor/bin/phpcs -d memory_limit=512M --error-severity=1 --warning-severity=8 addons/pro/includes/tools/<dirname>/

# Fix one file
vendor/bin/phpcs -d memory_limit=512M --error-severity=1 --warning-severity=8 addons/pro/includes/tools/<filename>.php
```

### 4. Working order (easiest → hardest)

1. **Inline comment periods** — `bin/fix-lint-common.php` handles most
2. **Yoda conditions** — `bin/fix-lint-common.php`, then manual for edge cases
3. **Short ternaries** — Expand `$x ?:` → `$x ? $x : $default` (manual)
4. **Docblock short descriptions** — `bin/fix-lint-missing-docblocks.php` handles standard methods
5. **Custom method docblocks** — Manual, needs understanding of each method's purpose

## Fixer Scripts (in bin/)

### `fix-lint-missing-docblocks.php`
Adds PHPDoc blocks for standard tool interface methods that are missing documentation:
`execute()`, `get_slug()`, `get_name()`, `get_description()`, `get_required_capability()`,
`get_parameters_schema()`, `get_capability_flags()`, `get_definition()`, `get_unavailable_reason()`, `is_available()`.

Respects existing indentation (tabs). Safe to run repeatedly — skips files that already have docblocks.

### `fix-lint-common.php`
Fixes inline comment periods, Yoda conditions in control structures, single-line block comment
closers, and `get_capability_flags()` docblocks. Conservative — only transforms safe patterns.

## Known Issues

1. **PHPCSUtils parse error (8 files excluded)** — PHP 8.3 + PHPCSUtils 1.2.x triggers a parse error
   in `AbstractArrayDeclarationSniff.php(566)` on specific array syntax. Workaround: those files
   are excluded in `phpcs.xml.dist`. When PHPCSUtils is updated, remove the excludes.

2. **Memory exhaustion** — The full pro addon contains ~1,395 PHP files. Always use
   `-d memory_limit=512M` and scan one directory at a time.

3. **Deduplication** — Some tool files have `get_required_capability()` defined in both the
   class and a trait. The PHPCS "Cannot redeclare" error is a false positive from the sniffer.

## Quick Reference Commands

```bash
# Check entire pro addon (errors only, no warnings)
vendor/bin/phpcs -d memory_limit=512M --error-severity=1 --warning-severity=8 --report=summary addons/pro/

# Check just non-pro addons
vendor/bin/phpcs -d memory_limit=512M --error-severity=1 --warning-severity=8 --report=summary addons/algorave/ addons/canvas/ addons/canvas-toolkit/ addons/chat-spa/ addons/cloud-worker/ addons/cornerstone3d/ addons/docs-hub/ addons/document-editor/ addons/embedded/ addons/fantasy-football/ addons/graphify/ addons/media-studio/ addons/toolkit-shell/ addons/saas-controller/

# Full lint (errors-only) as CI would run
composer run lint:errors-only
```

## Git History

Branch: `lint/all-addons-phpcs-fixes`
Base: `alpha-working` (commit `d8b1332db`)
