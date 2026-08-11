# Backup & Restore

**Version:** 1.1.46+
**Category:** Pro feature (with base export providers)
**Proposal:** [020-comprehensive-backup-restore](../project/proposals/020-comprehensive-backup-restore-proposal.md)

## Overview

The Backup & Restore system provides modular, provider-based export/import of NV oOS configuration. It uses a JSON-based format with chunked file I/O and progress reporting.

## Architecture

```
WP_MCP_AI_Export_Manager
  ├── WP_MCP_AI_Export_Provider_Interface (contract)
  │     ├── export(): array → return data payload
  │     └── import( array $data ): bool|WP_Error
  └── 11 export providers (8 base + 3 Pro)
```

## Base Export Providers (8)

| Provider | Exports | File |
|---|---|---|
| Core Settings | `wp_mcp_ai_settings`, `wp_mcp_ai_credentials`, provider configs | `class-wp-mcp-ai-export-provider-core-settings.php` (258 lines) |
| Assistants | All `mcp_ai_assistant` CPT posts with meta | `class-wp-mcp-ai-export-provider-assistants.php` (285 lines) |
| CPTs | Custom post type definitions and content | `class-wp-mcp-ai-export-provider-cpts.php` (319 lines) |
| Custom Tables | Plugin-created database tables | `class-wp-mcp-ai-export-provider-custom-tables.php` (394 lines) |
| Federation | Federation peers and mesh configuration | `class-wp-mcp-ai-export-provider-federation.php` (253 lines) |
| Addon Options | Per-addon WordPress options | `class-wp-mcp-ai-export-provider-addon-options.php` (337 lines) |
| Toolkit Options | Per-toolkit configuration options | `class-wp-mcp-ai-export-provider-toolkit-options.php` (282 lines) |

## Pro Export Providers (3)

| Provider | Exports | File |
|---|---|---|
| JetEngine CCTs | Custom content type definitions and data | `class-wp-mcp-ai-export-provider-jetengine-ccts.php` (329 lines) |
| License Keys | Pro license activations | `class-wp-mcp-ai-export-provider-license.php` (190 lines) |
| Remote Sites | Remote site connections and credentials | `class-wp-mcp-ai-export-provider-remote-sites.php` (324 lines) |

## Admin UI

Access via **Settings → NV oOS → Advanced → Backup & Restore**.

- Provider checkboxes for selective export/import
- Full export downloads a JSON file with all selected providers
- Import accepts a previously-exported JSON file
- Chunked processing with progress feedback for large exports
- Validation before import to prevent data corruption

## CLI Usage

```bash
# Export all base providers
wp mcp-ai export --providers=all --output=/tmp/nvoos-backup.json

# Export specific providers
wp mcp-ai export --providers=assistants,settings --output=/tmp/nvoos-backup.json

# Import from backup
wp mcp-ai import --input=/tmp/nvoos-backup.json

# Import specific providers only
wp mcp-ai import --input=/tmp/nvoos-backup.json --providers=settings,assistants
```

## Export Format

```json
{
  "version": "1.0",
  "exported_at": "2026-08-11T12:00:00Z",
  "plugin_version": "1.1.51",
  "providers": {
    "core-settings": { ... },
    "assistants": [ ... ],
    "cpts": { ... }
  }
}
```

## Import Safety

- All imports validate JSON structure before applying
- Chunked file I/O prevents memory exhaustion on large exports
- Progress reporting via admin-ajax for UI feedback
- Rollback is manual — take a backup before importing

## Related

- [Proposal 020: Comprehensive Backup & Restore](../project/proposals/020-comprehensive-backup-restore-proposal.md)
- [Implementation Plan](../project/proposals/020-comprehensive-backup-restore-implementation-plan.md)
- [Export README](../../includes/admin/export/README.md)
