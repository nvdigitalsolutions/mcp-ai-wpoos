# Proposal: Docs Hub Broken Link Detection & Repair Enhancement

**Status:** Implementation  
**Author:** AI Agent (2026-07-05)  
**Target:** NV oOS Docs Hub addon (addons/docs-hub/)  
**Scope:** Broken link smart suggestions, auto-fix capability, CLI tooling, admin dashboard

---

## 1. Summary

The Docs Hub addon currently **detects** broken internal `.md` file links during rebuild but provides **zero repair capability**. Broken links are stored as raw `{ source, target }` pairs in the manifest and displayed as a single counter in the admin settings page. This proposal adds a three-tier repair system: smart suggestion engine (content-hash + fuzzy matching), REST/CLI fix tooling, and slug-history redirect management.

### Industry Standards Referenced

| Source | Key Insight |
|---|---|
| [Portent — Fuzzy Logic Redirection](https://portent.com/blog/seo/fuzzy-logic-redirection.htm) | Fuzzy matching broken URLs to valid ones and storing redirects |
| [hyperlink (Rust)](https://github.com/untitaker/hyperlink) | Maps broken links back to source files via content-hash fuzzy matching |
| [WPMU DEV Broken Link Checker](https://wpmudev.com/broken-link-checker/) | Scheduled scans, categorized results, dashboard with actions |
| [Upward Engine — Internal Linking Best Practices 2026](https://upwardengine.com/blog/internal-linking-best-practices-seo/) | Quarterly audits, redirect management, orphaned page detection |
| [Indexly Broken Link Checker](https://indexly.ai/broken-link-checker) | Actionable suggestions with alternative URLs and redirect recommendations |

---

## 2. Current State

### 2.1 Detection Only (exists)

`NV_oOS_Docs_Hub_Indexer::detect_broken_links()` (line 439):
- Scans all `.md` files for Markdown links via regex `/[^]]+\([^)]+\)/`
- Skips absolute URLs (`https?://`) and anchor fragments (`#...`)
- Resolves relative `.md` paths via `realpath()` + `file_exists()`
- Stores failures as `{ source: "relative/path.md", target: "missing-file.md" }`

### 2.2 No Repair Capability (gap)

| Capability | Status |
|---|---|
| Detect broken internal `.md` links | ✅ |
| Detect broken external URLs | ❌ |
| Detect broken anchor fragments (`#missing-section`) | ❌ |
| Suggest corrected link targets | ❌ |
| Auto-fix links (rewrite source `.md` files) | ❌ |
| Track renamed/moved pages (slug history) | ❌ |
| Redirect old slugs → new slugs | ❌ |
| Broken link dashboard with trends | ❌ |
| CLI fix command | ❌ |
| REST endpoint for fix operations | ❌ |

### 2.3 Admin Display (minimal)

The settings page (`class-nvoos-docs-hub-settings.php` line 754) shows only:
```
Broken Links: 42
```

No table, no categorization, no actions.

---

## 3. Proposed Architecture

### 3.1 Smart Suggestion Engine

**New method**: `NV_oOS_Docs_Hub_Indexer::suggest_fix($broken_target, $source_path)`

Three suggestion strategies ranked by confidence:

| Priority | Strategy | Confidence | How |
|---|---|---|---|
| 1 | Content-hash match | 0.85–1.00 | Compare `md5(file_content)` of broken target against all known files from previous manifest |
| 2 | Fuzzy filename match | 0.60–0.89 | Levenshtein distance on filename stem; threshold < 3 |
| 3 | Directory-neighbor match | 0.40–0.59 | Files in same directory with similar names |

**Content-hash store**: During scan phase, compute and store `content_hash` for every `.md` file. Persist in `slug_map` entries and manifest. On next rebuild, compare broken targets against the hash store.

**New manifest shape** for broken links:
```json
{
  "broken_links": [
    {
      "source": "docs/getting-started.md",
      "target": "docs/old-setup.md",
      "suggestions": [
        { "target": "docs/installation.md", "confidence": 0.92, "method": "hash_match" },
        { "target": "docs/set-up.md",    "confidence": 0.67, "method": "fuzzy_name" }
      ]
    }
  ]
}
```

### 3.2 CLI Fix Command

**New command**: `wp nvoos-docs fix-links`

```
wp nvoos-docs fix-links [--dry-run] [--auto] [--confidence=0.8]
```

- `--dry-run`: Show what would be fixed without writing changes
- `--auto`: Auto-apply all suggestions above confidence threshold
- `--confidence=0.8`: Minimum confidence for auto-fix (default 0.8)

Interactive mode shows each broken link with suggestions and asks `[y/N/a/s]`.

### 3.3 REST Fix Endpoint

**New route**: `POST /nvoos-docs/v1/fix-links`

```json
// Request
{
  "fixes": [
    { "source": "docs/getting-started.md", "old_target": "docs/old-setup.md", "new_target": "docs/installation.md" }
  ],
  "dry_run": false
}

// Response
{
  "success": true,
  "fixed": 1,
  "skipped": 0,
  "errors": [],
  "results": [
    { "source": "docs/getting-started.md", "status": "fixed", "old": "docs/old-setup.md", "new": "docs/installation.md" }
  ]
}
```

**Permission**: `manage_options` (admin-only write endpoint).

### 3.4 Admin Dashboard Enhancement

Extend the settings page broken links section from a single counter to a table:

| Source Page | Broken Target | Best Suggestion | Confidence | Actions |
|---|---|---|---|---|
| docs/getting-started.md | docs/old-setup.md | docs/installation.md | 92% | [Apply Fix] [Ignore] |
| addons/pro/docs/README.md | ../base/docs/api.md | ../base/docs/rest-api.md | 78% | [Apply Fix] [Ignore] |

Add "Fix All High-Confidence" bulk action and "Export CSV" button.

### 3.5 Slug History & Redirects

Track content hashes across rebuilds. When a file's content hash appears under a new slug, record the rename in `slug_history`. The SPA's `NotFound` component can check this map and redirect automatically.

---

## 4. Implementation Plan

### Phase 1: Suggestion Engine (Core)

**Files to create/modify**:

| File | Action | Description |
|---|---|---|
| `includes/class-nvoos-docs-hub-indexer.php` | Modify | Add `suggest_fix()`, `compute_content_hash()`, hash-based matching in `detect_broken_links()` |
| `includes/class-nvoos-docs-hub-cache.php` | Modify | Store/retrieve `content_hashes` map alongside manifest |

**New methods**:
- `NV_oOS_Docs_Hub_Indexer::compute_content_hash($file_path)` → `string`
- `NV_oOS_Docs_Hub_Indexer::suggest_fix($broken_target, $source_path)` → `array`
- `NV_oOS_Docs_Hub_Indexer::build_content_hash_map($entries)` → `array`
- `NV_oOS_Docs_Hub_Indexer::fuzzy_match_slug($target_stem, $all_slugs)` → `array`

### Phase 2: CLI Fix Tool

| File | Action | Description |
|---|---|---|
| `includes/class-nvoos-docs-hub-cli.php` | Modify | Add `fix_links` subcommand |
| `includes/class-nvoos-docs-hub-link-fixer.php` | **Create** | `NV_oOS_Docs_Hub_Link_Fixer` — safe `.md` file rewriting |

### Phase 3: REST + Admin

| File | Action | Description |
|---|---|---|
| `includes/rest/class-nvoos-docs-hub-rest.php` | Modify | Add `POST /fix-links` route |
| `includes/admin/class-nvoos-docs-hub-settings.php` | Modify | Broken links table + JS for inline fix |
| `assets/js/docs-hub-admin.js` | **Create** | Admin JS for fix-link interactions |

### Phase 4: Slug History

| File | Action | Description |
|---|---|---|
| `includes/class-nvoos-docs-hub-indexer.php` | Modify | Compute and persist `slug_history` |
| `src/routes/NotFound.tsx` | Modify | Check `slug_history` for redirect suggestions |

---

## 5. Security & Safety Considerations

| Risk | Mitigation |
|---|---|
| File write corruption | Write to temp file, verify content, then `rename()` atomically |
| Path traversal in fix targets | Validate all paths against known scan directory; reject `../` escapes |
| Mass file modification | Require `manage_options`; add `--dry-run` default; never auto-fix without explicit opt-in |
| Remote repo file writes | Skip fix operations for `source: "remote"` entries (read-only GitHub content) |
| Concurrent rebuilds | Check `NV_oOS_Docs_Hub_Rebuild_State::is_running()` before allowing writes |

---

## 6. Testing Strategy

### Unit Tests
- `test_suggest_fix_hash_match()` — renamed file detected via content hash
- `test_suggest_fix_fuzzy_name()` — typo correction via Levenshtein
- `test_suggest_fix_no_match()` — deleted file returns empty suggestions
- `test_fix_link_rewrites_md_file()` — verify correct Markdown link rewriting
- `test_fix_link_rejects_remote_source()` — remote entries denied
- `test_fix_link_path_traversal_blocked()` — `../` paths rejected

### Integration Tests
- `test_fix_links_cli_dry_run()` — CLI `--dry-run` outputs suggestions only
- `test_fix_links_cli_auto()` — CLI `--auto` applies fixes
- `test_fix_links_rest_endpoint()` — REST endpoint auth + fix flow
- `test_slug_history_redirect()` — NotFound component shows redirect

---

## 7. References

- [Portent — Using fuzzy logic to redirect broken links](https://portent.com/blog/seo/fuzzy-logic-redirection.htm)
- [hyperlink — Very fast link checker for CI](https://github.com/untitaker/hyperlink) (content-hash fuzzy matching)
- [WPMU DEV Broken Link Checker](https://wpmudev.com/broken-link-checker/) (scheduled scans + dashboard)
- [Internal Linking Best Practices for SEO 2026](https://upwardengine.com/blog/internal-linking-best-practices-seo/)
- Codebase: `addons/docs-hub/includes/class-nvoos-docs-hub-indexer.php` (detect_broken_links)
- Codebase: `addons/docs-hub/includes/class-nvoos-docs-hub-cli.php` (existing CLI commands)
- Codebase: `addons/docs-hub/includes/rest/class-nvoos-docs-hub-rest.php` (REST route registration)
