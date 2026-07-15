# Settings Credential Split

**Status:** Stable — v1.1.40
**Source:** `includes/admin/class-wp-mcp-ai-admin-settings-base.php`, `includes/admin/class-wp-mcp-ai-settings-dashboard.php`, `includes/bootstrap/activation.php`

---

## Overview

In v1.1.40, the NV oOS settings storage was restructured into a **two-option architecture** that isolates sensitive API keys from general configuration. This defense-in-depth measure prevents credential loss during settings operations and reduces the autoload footprint on every WordPress request.

## Architecture: Before vs After

| Aspect | Before (v1.1.39) | After (v1.1.40) |
|--------|-----------------|-----------------|
| Storage | Single option: `wp_mcp_ai_settings` | Two options: `wp_mcp_ai_settings` + `wp_mcp_ai_credentials` |
| Autoload | Everything autoloaded | Credentials non-autoloaded |
| Key safety | Keys mixed with config | Keys isolated, save-protected |
| Migration | N/A | One-time activation migration |

## Constants

```php
// WP_MCP_AI_Admin_Settings_Base
const OPTION_NAME             = 'wp_mcp_ai_settings';     // Autoload — non-sensitive config
const CREDENTIALS_OPTION_NAME = 'wp_mcp_ai_credentials';  // Non-autoload — API keys, tokens
```

## Read Path: `get_settings()` Merge

**Always use `WP_MCP_AI_Admin_Settings_Base::get_settings()`** to read settings. It transparently merges credentials:

```php
$defaults    = self::get_default_settings();
$saved       = get_option( self::OPTION_NAME, array() );
$credentials = get_option( self::CREDENTIALS_OPTION_NAME, array() );

// Credentials take precedence for keys present in both
if ( count( $credentials ) > 0 ) {
    $saved = array_merge( $saved, $credentials );
}

$settings = wp_parse_args( $saved, $defaults );
```

**Agents and tools should never call `get_option()` directly** for a full settings snapshot — always go through `get_settings()`.

## Save Path: Dashboard 7-Step Process

The `WP_MCP_AI_Settings_Dashboard` save flow:

1. **Suspend cache additions** — `wp_suspend_cache_addition( true )` prevents stale Redis/Memcached writes during the read-merge-write cycle
2. **Clear all caches** — `reset_settings_cache()`, `wp_cache_delete()` for both option names, delete transient
3. **Read fresh from DB** — both `wp_mcp_ai_settings` and `wp_mcp_ai_credentials`
4. **Create backup** — merged backup of both options for rollback
5. **Sensitive key protection** — credential keys from `get_sensitive_fields()` are never overwritten by empty/masked values from non-provider tabs
6. **Merge and save** — non-sensitive keys → `wp_mcp_ai_settings`; sensitive keys → `wp_mcp_ai_credentials`
7. **Resume cache** — `wp_suspend_cache_addition( false )`

## Sensitive Fields

```php
WP_MCP_AI_Admin_Settings_Base::get_sensitive_fields()
// Returns: array of keys that should live in wp_mcp_ai_credentials
// e.g., openai_api_key, gemini_api_key, brave_search_api_key, ...

WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( $key )
// Returns: bool — true if the key matches a known sensitive pattern
```

## Migration: One-Time Activation Hook

On plugin activation (or admin_init for existing installs), a one-time migration runs:

```php
wp_mcp_ai_migrate_credentials_to_split()
```

**Behavior:**
1. Reads `wp_mcp_ai_settings`
2. For each key matching `is_sensitive_setting_key()`: copies value to `wp_mcp_ai_credentials`, removes from `wp_mcp_ai_settings`
3. Saves both options (credentials non-autoload)
4. Sets flag `wp_mcp_ai_credentials_migrated` to prevent re-run

**Key property:** The migration is additive — it creates the new option without data loss. A verify step confirms the migration before removing keys from the old option.

## Import/Export

- **Export** uses `get_settings()` which merges credentials → exported JSON contains all keys
- **Import** writes to both options, clears both caches, validates subtab boundaries
- **Pre-import backup** includes merged credentials for complete rollback

## Agent Impact

### Do
- Use `WP_MCP_AI_Admin_Settings_Base::get_settings()` for all settings reads
- Use `WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME` when writing credentials directly
- Clear both caches when writing settings: `wp_cache_delete( OPTION_NAME, 'options' )` and `wp_cache_delete( CREDENTIALS_OPTION_NAME, 'options' )`

### Don't
- Don't call `get_option( 'wp_mcp_ai_settings' )` directly — you'll miss credentials
- Don't write API keys to `wp_mcp_ai_settings` — use `wp_mcp_ai_credentials`
- Don't replicate the migration logic — it runs once and is idempotent

## Related

- [Agent Context: Settings Storage](../../.context/settings-storage.md)
- [`includes/bootstrap/activation.php`](../../includes/bootstrap/activation.php) — migration source
- [`includes/admin/class-wp-mcp-ai-admin-settings-base.php`](../../includes/admin/class-wp-mcp-ai-admin-settings-base.php) — constants + `get_settings()`
- [`includes/admin/class-wp-mcp-ai-settings-dashboard.php`](../../includes/admin/class-wp-mcp-ai-settings-dashboard.php) — save flow
