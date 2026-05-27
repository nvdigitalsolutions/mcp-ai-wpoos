# paper-store/

## Purpose

Pro extension of the NVoOS Paper Store — adds a Markdown+YAML driver, a WordPress
admin UI for browsing collections and records, periodic Git versioning, and
import/export MCP tools.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | `paper-store-pro-init.php` hooked on `wp_mcp_ai_bootstrapped` at priority 35 (after base `paper-store-init.php` at priority 30) |
| **Optional dependencies** | `symfony/process` (git sync), `symfony/yaml` (Markdown driver — fallback regex parser built in) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Paper_Markdown_Yaml_Driver` | `class-wp-mcp-ai-paper-markdown-yaml-driver.php` | Paper Store Manager (registered as driver for `.md` files) |
| `WP_MCP_AI_Paper_Git_Sync` | `class-wp-mcp-ai-paper-git-sync.php` | Cron, admin UI (opt-in via `wp_mcp_ai_paper_git_sync_enabled` filter) |
| `WP_MCP_AI_Paper_Admin_UI` | `class-wp-mcp-ai-paper-admin-ui.php` | WordPress admin menu |
| `WP_MCP_AI_Tool_Paper_Store_Import` | `../tools/paper-store/class-wp-mcp-ai-tool-paper-store-import.php` | MCP tool registry |
| `WP_MCP_AI_Tool_Paper_Store_Export` | `../tools/paper-store/class-wp-mcp-ai-tool-paper-store-export.php` | MCP tool registry |

### Pro MCP Tools (2)

| Tool slug | File (in `addons/pro/includes/tools/paper-store/`) | Capability |
|---|---|---|
| `paper_store_import` | `class-wp-mcp-ai-tool-paper-store-import.php` | `edit_posts` |
| `paper_store_export` | `class-wp-mcp-ai-tool-paper-store-export.php` | `read` |

## Inputs / Outputs / Neighbors

- **Reads from:** Paper Store file system (`.md` files via Markdown+YAML driver), WordPress options (`wp_mcp_ai_settings` for git-sync opt-in).
- **Writes to:** Same file system (`.md` records), git repository (auto-commits via cron).
- **Upstream callers:** Paper Store Manager (driver registration), `wp_mcp_ai_bootstrapped` action (init hook), WordPress admin (`admin_menu`).
- **Downstream collaborators:** Base `WP_MCP_AI_Paper_Store_Manager` and `WP_MCP_AI_Paper_Driver_Interface` (from `includes/paper-store/`), `WP_MCP_AI_Tool_Registry`, `WP_MCP_AI_Logger`.
- **Filters fired:** `wp_mcp_ai_paper_git_sync_enabled`, `wp_mcp_ai_paper_git_remote_url`, `wp_mcp_ai_paper_git_branch`.
- **Events listened to:** `wp_mcp_ai_bootstrapped`.

## Conventions

- All classes here use typed properties, `readonly`, and enums — features that require the Pro 8.1 target (see Tier table above).
- The Markdown+YAML driver implements `WP_MCP_AI_Paper_Driver_Interface` from the base Paper Store; never bypass the driver interface.
- Git sync is **opt-in** via the `wp_mcp_ai_paper_git_sync_enabled` filter (defaults to `false`). It respects `FS_METHOD` and requires `direct` to operate.
- Admin UI is a singleton (`WP_MCP_AI_Paper_Admin_UI::get_instance()` / `::init()`). All admin pages use `wp_mcp_ai_` capability checks.
- Tool classes live in `addons/pro/includes/tools/paper-store/`, not here — this folder is only for engine/service classes.

## Tests

No dedicated PHPUnit tests exist for this Pro folder yet. The Pro tools (`paper_store_import`, `paper_store_export`) are exercised indirectly through the base Paper Store tool tests in:

```bash
vendor/bin/phpunit tests/paper-store/
```

Git-sync and admin-UI tests are TODO — tracked internally.

## Also Load

- Base Paper Store README: [`includes/paper-store/README.md`](../../../../includes/paper-store/README.md)
- [`.context/conventions.md`](../../../../.context/conventions.md)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md)
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md)

## See Also

- Parent folder: [`addons/pro/includes/`](../) (Pro)
- Base counterpart: [`includes/paper-store/`](../../../../includes/paper-store/) (Base)
- Pro tools: [`addons/pro/includes/tools/paper-store/`](../tools/paper-store/)
