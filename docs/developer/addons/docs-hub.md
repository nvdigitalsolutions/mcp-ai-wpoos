# Docs Hub Addon (`addons/docs-hub/`)

**Version:** 0.3.8  
**Location:** `addons/docs-hub/`  
**Requires:** WordPress 6.0+, PHP 7.4+, NV oOS base plugin

The Docs Hub addon turns any WordPress site into a **documentation portal** — indexing Markdown files from local plugin directories or remote public GitHub repositories and rendering them as a searchable, navigable React SPA.

---

## Contents

1. [Quick Start](#quick-start)
2. [Features by Version](#features-by-version)
3. [Remote Repository Configuration](#remote-repository-configuration)
4. [Chunked Rebuild & CLI](#chunked-rebuild--cli)
5. [Sitemap Integration](#sitemap-integration)
6. [Shortcode Reference](#shortcode-reference)
7. [REST API](#rest-api)

---

## Quick Start

1. Install and activate `addons/docs-hub/` (ZIP from Releases).
2. Go to **WP-Admin → NV oOS Docs Hub → Settings**.
3. Add a remote repository (e.g. `nvdigitalsolutions/mcp-ai-wpoos`) or leave the default local-docs source.
4. Click **Rebuild Index**. The SPA will be ready at `[nvoos_docs]` on any page.

---

## Features by Version

| Version | What shipped |
|---------|-------------|
| **v0.1.0** | Initial React SPA, REST API, local + remote GitHub indexing |
| **v0.1.1–0.1.3** | Content field fix, slug encoding, mobile sidebar toggle, GitHub subtree path fetch |
| **v0.2.x** | Chunked rebuild pipeline + CLI `rebuild` subcommand, admin progress UI |
| **v0.3.0** | Remote-first defaults, tree-picker UX, drag-to-reorder repos |
| **v0.3.1–0.3.5** | 404 fix, PHPCS lint, hash-router anchor fix, scrollIntoView, RemoteAnchor |
| **v0.3.6** | Defensive `remote_repos` coercion, REST/SSRF hardening (HTTPS-only fetcher) |
| **v0.3.7** | a11y root attrs (`lang`, `role`), skip-link, `prefers-reduced-motion` |
| **v0.3.8** | Syntax highlighting (rehype-highlight), `PageFooter` (last_modified + edit-on-GitHub link), `NV_oOS_Docs_Hub_Sitemap_Provider`, admin `repo-picker.js` extracted from inline `<script>` |

---

## Remote Repository Configuration

1. Go to **NV oOS Docs Hub → Settings → Remote Repositories**.
2. Click **Add Repository** and enter a GitHub repository in `owner/repo` format (e.g. `nvdigitalsolutions/mcp-ai-wpoos`).
3. Optionally specify a subdirectory (e.g. `docs`) and a branch (default: `main`).
4. Click **Save** then **Rebuild Index**.

The fetch uses a SSRF-safe HTTPS-only helper — only `github.com` and `raw.githubusercontent.com` hosts are reachable. Decompression-bomb protection is applied.

---

## Chunked Rebuild & CLI

Large repositories are rebuilt in chunks to avoid PHP timeout errors. The rebuild pipeline (`NV_oOS_Docs_Hub_Rebuild_Pipeline`) uses the inline-async-tick pattern for low-latency on hosts with `DISABLE_WP_CRON`.

**WP-CLI:**
```bash
# Full rebuild
wp nvoos-docs rebuild

# Clear cache
wp nvoos-docs clear

# Status check
wp nvoos-docs status
```

---

## Sitemap Integration

Version 0.3.8 adds `NV_oOS_Docs_Hub_Sitemap_Provider`, which registers all indexed documentation pages with the WordPress core sitemap (`wp-sitemap.xml`). No additional configuration required.

---

## Shortcode Reference

```php
[nvoos_docs]
[nvoos_docs repo="nvdigitalsolutions/mcp-ai-wpoos" branch="main" path="docs"]
```

---

## REST API

**Namespace:** `/wp-json/nvoos-docs/v1/`

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/manifest` | Full file tree (public) |
| `GET` | `/content` | File content by path (public) |
| `POST` | `/rebuild` | Trigger chunked rebuild (`edit_posts`) |
| `GET` | `/status` | Rebuild job status (`edit_posts`) |
