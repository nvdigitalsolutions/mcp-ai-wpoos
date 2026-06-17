# filesystem/

## Purpose

Symfony-Filesystem-backed wrapper providing atomic file writes, safe directory creation, and exception-to-`WP_Error` translation for NV oOS's non-WordPress-uploads I/O.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ (see [`CLAUDE.md`](../../CLAUDE.md)) |
| **Loaded by** | Symfony autoloader (`WP_MCP_AI\Filesystem` namespace); resolved through the service container or `WP_MCP_AI_Filesystem_Service::get_instance()`. Also loaded directly by tests via `require_once`. |
| **Optional dependencies** | `symfony/filesystem` (vendored, always available). For WordPress-owned uploads / plugin / theme directories, prefer `WP_Filesystem` so credentials and FTP fallbacks are respected. |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI\Filesystem\WP_MCP_AI_Filesystem_Service` | `class-wp-mcp-ai-filesystem-service.php` | cache (filesystem adapter), measurement exports, log rotation, fixture builders |
| `::get_instance()` | same file | singleton accessor |
| `write_file()`, `append_to_file()`, `mkdir()`, `exists()`, `remove()`, `copy()`, `rename()` | same file | atomic, exception-safe filesystem operations |

All public methods return `true` on success or `WP_Error` on `IOExceptionInterface` — the underlying `Symfony\Component\Filesystem\Filesystem` is intentionally not exposed.

## Inputs / Outputs / Neighbors

- **Reads from:** Caller-supplied paths (absolute, pre-resolved).
- **Writes to:** The filesystem (atomic `dumpFile()` for `write_file()`, recursive `mkdir()` for directories). Cache uses `<uploads>/wp-mcp-ai-cache/` via this service.
- **Upstream callers:** [`includes/cache/`](../cache/) (filesystem adapter path), measurement/exports, fixture generators, the dead-letter queue when persisting payloads, Pro report builders.
- **Downstream collaborators:** `Symfony\Component\Filesystem\Filesystem`, `Symfony\Component\Filesystem\Exception\IOExceptionInterface`.
- **Events fired:** None.
- **Events listened to:** None.

## Conventions

- **Resolve paths before calling.** This primitive does not implement path-traversal validation — callers must ensure inputs cannot escape their intended root (use `realpath()` + prefix check, or constrain to `wp_upload_dir()['basedir']`). See [`.context/security-checklist.md`](../../.context/security-checklist.md) for the canonical anti-traversal pattern.
- **Atomic writes only.** `write_file()` uses `dumpFile()` which writes to a temp file and renames — never replace it with `file_put_contents()`.
- **Translate exceptions to `WP_Error`.** No method may leak a Symfony exception across the public surface; that is enforced by the existing try/catch wrappers and must be preserved when adding methods.
- **Do not use for WordPress-managed assets.** Plugin updates, theme writes, uploaded-attachment processing, and anything that must respect FS_METHOD/FTP credentials go through `WP_Filesystem` (loaded via `request_filesystem_credentials`) — not this service.

## Tests

```bash
vendor/bin/phpunit tests/test-filesystem-service.php
```

Filesystem behaviour is also exercised by the cache filesystem-adapter path (`tests/test-cache-service.php` when Redis/APCu are unavailable) and by various export tools.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — path-traversal prevention, MIME validation for uploads (always)
- [`.context/testing.md`](../../.context/testing.md) — fixtures and temp-dir conventions

## See Also

- Sibling primitives: [`includes/http/`](../http/), [`includes/cache/`](../cache/)
- WordPress-credential-aware counterpart: `WP_Filesystem` (core) and any plugin code that uses `request_filesystem_credentials()`
