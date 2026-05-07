# NV oOS Docs Hub — Changelog

## 0.3.2 — 2026-05-07

### Fixed
- **PHPCS lint compliance.** Resolved 96 errors / 38 warnings flagged on the v0.3.0/0.3.1 changes: associative-array spacing, missing function/class docblocks in the new test files, inline-comment punctuation, parameter-comment full stops, blank-line-after-class-comment, and a Yoda-condition violation in `Remote_Repo::fetch_tree()`'s SHA lookup. Auto-fixable issues fixed via `phpcbf`; remaining ones fixed by hand. Pre-existing `NV_oOS_*` class-name warnings (intentional addon-wide convention) are left untouched.

## 0.3.1 — 2026-05-07

### Fixed
- **Rebuild Documentation Index → 404.** The admin rebuild panel was building its REST URL from the literal string `nvoos-docs-hub/v1`, but the actual REST namespace is `nvoos-docs/v1`. Switched to `NV_oOS_Docs_Hub_REST::NAMESPACE` so all four rebuild routes (`/rebuild`, `/rebuild/status`, `/rebuild/cancel`, `/rebuild/resume`) resolve correctly.

### Changed
- **Remote Repositories section** now shows a highlighted call-out explaining the "Browse files in repo…" picker and the "Selected files / folders only" mode, so the per-file selection workflow is discoverable on first visit.

## 0.3.0 — 2026-05-07

### Changed
- **Default to remote-first.** Fresh installs now ship with `sources = ['remote']`. Local filesystem sources (`base`, `addons`, `root`, `context`) remain available but are off by default. Existing installs are not migrated — saved settings are preserved.
- Settings UI now groups local filesystem sources under a collapsed **"Advanced — local filesystem (legacy)"** section.

### Added
- **Lookup-and-select tree picker** for remote repositories. Each configured repo gets a "Browse files in repo…" button that calls a new admin REST endpoint and renders the `.md` / `.txt` file tree as a checkbox list.
- New per-repo **`selection_mode`** field with three values:
  - `all` — index every Markdown / `.txt` file (default; back-compat).
  - `prefix` — restrict to the existing `path` prefix.
  - `selected` — index only the explicit list configured via the picker.
- New per-repo **`selected_paths`** and **`excluded_paths`** lists. Trailing `/` denotes a directory (recursive). `excluded_paths` is always honoured, useful with `all` mode.
- New REST endpoint `GET /wp-json/nvoos-docs/v1/remote/tree` (admin-only). Cached for 10 minutes per `owner/repo/ref/path`.
- New `NV_oOS_Docs_Hub_Remote_Repo::fetch_tree_for_admin()` public helper.
- First-run admin notice on the settings page.
- One-time, dismissible notice for installs running all three legacy sources (`base` + `addons` + `root`) with zero remote repos configured.

### Documentation
- New `docs/remote-repos.md` describing the picker workflow.

### Out of scope (follow-ups)
- GitLab / Bitbucket / arbitrary Git hosts (current SSRF allowlist is GitHub-only).
- Background pre-fetch of the tree on save.
