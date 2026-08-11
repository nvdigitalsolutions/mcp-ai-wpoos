# Plugin Updater

**Version:** 1.1.52+
**Category:** Base feature
**Class:** `WP_MCP_AI_Plugin_Updater` (~850 lines)

## Overview

The NV oOS Plugin Updater fetches release metadata from the GitHub Releases API, compares versions against the installed plugin, downloads ZIP artifacts, and installs updates via WordPress's `Plugin_Upgrader`. It replaces reliance on WordPress.org update channels for GitHub-distributed builds.

## Features

### GitHub Release Fetching
- Queries `https://api.github.com/repos/nvdigitalsolutions/mcp-ai-wpoos/releases`
- Compares semantic versions against installed plugin
- Downloads the appropriate ZIP artifact (`-base.zip`, `-complete.zip`, or `-pro.zip`)

### Base-to-Complete Upgrade Path
- Settings → Advanced → **Upgrade to Complete Package**
- One-click upgrade from base-only to the complete package (base + Pro)
- Uses `Plugin_Upgrader` for core, direct copy with rollback for Pro

### Pro Addon Update Support
- Detects and updates the Pro addon alongside the base plugin when bundled
- Hides redundant Pro updater UI when Pro is already included in the complete package
- Rollback support: copy old Pro files back on failure

### Cache Busting
- Manual "Check for Updates" clears cached release data before fetching
- Default TTL: 12 hours
- `ajax_check_update()` and `ajax_check_pro_update()` delete stale cache entries

### Security
- Nonce-scoped update actions
- Capability checks: `update_plugins` required
- ZIP validation before extraction
- Temporary directory cleanup on completion

## Admin UI

Access via **Settings → NV oOS → Advanced → Updates**.

- Current version display with latest available version
- "Check for Updates" button (manual cache bust)
- "Update Now" button when a newer version is available
- "Upgrade to Complete Package" for base-only installs
- Pro addon status indicator

### Post-Install Integrity Check (v1.1.52)

After every update installation, the updater runs `verify_installation_integrity()` to confirm that critical plugin files exist on disk before reporting success. This prevents silent update corruption on distributed filesystems (e.g., Cloudways) where `unzip` can drop files without reporting errors.

The integrity check uses a safelist (`VERIFY_FILES`) of 15 critical paths:

```
mcp-ai-wpoos.php
includes/class-wp-mcp-ai-plugin.php
includes/class-wp-mcp-ai-container.php
includes/class-wp-mcp-ai-rest.php
includes/rest/class-wp-mcp-ai-rest-controller-base.php
includes/rest/class-wp-mcp-ai-rest-chat-controller.php
includes/rest/class-wp-mcp-ai-rest-mcp-controller.php
includes/rest/class-wp-mcp-ai-rest-tools-controller.php
includes/rest/class-wp-mcp-ai-rest-token-manager.php
includes/rest/class-wp-mcp-ai-rest-cost-manager.php
includes/rest/class-wp-mcp-ai-rest-analytics-manager.php
includes/rest/class-wp-mcp-ai-rest-authenticator.php
includes/rest/class-wp-mcp-ai-rest-validator.php
includes/rest/class-wp-mcp-ai-sse-handler.php
includes/bridge/class-wp-mcp-ai-bridge.php
```

These are the files loaded via `require_once` without `file_exists()` guards in the main plugin bootstrap. If any are missing after an update, the plugin will fatal on the next request. The integrity check catches this before the update is reported as successful, returning a `WP_Error` listing the missing files.

## Hooks

```php
// Filter: modify the GitHub API URL
apply_filters( 'wp_mcp_ai_github_api_url', $url );

// Filter: modify the release asset filename pattern
apply_filters( 'wp_mcp_ai_release_asset_pattern', $pattern, $package_type );

// Action: before update installation
do_action( 'wp_mcp_ai_before_plugin_update', $new_version );

// Action: after successful update
do_action( 'wp_mcp_ai_after_plugin_update', $new_version, $old_version );
```

## Constants

```php
// Override the GitHub repository for updates
define( 'WP_MCP_AI_UPDATE_REPO', 'nvdigitalsolutions/mcp-ai-wpoos' );

// Disable automatic update checks
define( 'WP_MCP_AI_DISABLE_AUTO_UPDATE', true );

// Set cache TTL in seconds (default: 43200 = 12 hours)
define( 'WP_MCP_AI_UPDATE_CACHE_TTL', 3600 );
```

## Troubleshooting

| Symptom | Likely Cause | Solution |
|---|---|---|
| "Could not fetch release data" | GitHub API rate limit | Wait or use a GitHub token |
| "Update failed: could not extract ZIP" | Disk space / permissions | Check `/tmp` space and file permissions |
| Pro updater section missing | Pro bundled in complete package | Expected — Pro updates with base |
| Stale version displayed | Cache not cleared | Click "Check for Updates" |

## Related

- [PR #5800: GitHub-based plugin updater](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5800)
- [PR #5801: Base-to-complete upgrade path](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5801)
- [PR #5810: Update checker cache bust](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/pull/5810)
