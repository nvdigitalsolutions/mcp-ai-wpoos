# NV oOS Settings Storage Context

> **GSD Context File** — Load this when working on settings, credentials, import/export, or admin save flow.
> Last reviewed: August 26, 2026 (v1.1.64).
>
> **New in v1.1.64 (PR #5952):** the rolling log buffers `wp_mcp_ai_recent_errors` / `wp_mcp_ai_recent_activity` are storage-sensitive options that had grown into the megabytes (full assistant configs incl. `system_prompt` + unbounded arguments per entry). The persistence path now enforces a per-entry byte budget (fingerprinted `assistant_config`/`system_prompt`, string truncation, largest-value dropping, 12 diagnostic keys preserved); Extended Logging budget is 8 KB/entry. Data Management (Settings → Advanced) exposes Compact (rewrites stored entries through the budget) and Delete (empties both buffers) via the `wp_mcp_ai_maintain_log_buffers` AJAX action, with per-buffer entry counts + sizes reported. Keep new log-buffer writes inside this budget machinery — never store raw prompt/argument blobs.

---

## Two-Option Architecture (v1.1.40+)

NV oOS stores settings across two WordPress options:

| Option | Autoload | Content |
|--------|----------|---------|
| `wp_mcp_ai_settings` | Yes | Non-sensitive: providers, models, features, tools, limits, etc. |
| `wp_mcp_ai_credentials` | No | Sensitive: API keys, tokens, secrets |

**Constants:**
```php
WP_MCP_AI_Admin_Settings_Base::OPTION_NAME             // 'wp_mcp_ai_settings'
WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME // 'wp_mcp_ai_credentials'
```

---

## Reading Settings — Always Use `get_settings()`

```php
// ✅ CORRECT — transparent merge of settings + credentials
$all = WP_MCP_AI_Admin_Settings_Base::get_settings();

// ❌ WRONG — misses credentials
$partial = get_option( 'wp_mcp_ai_settings', array() );
```

The merge in `get_settings()` gives credentials precedence for keys present in both options.

---

## Writing Settings

### Non-sensitive keys → `wp_mcp_ai_settings` (autoload)
```php
update_option( WP_MCP_AI_Admin_Settings_Base::OPTION_NAME, $settings, true );
```

### Sensitive keys → `wp_mcp_ai_credentials` (non-autoload)
```php
update_option( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, $credentials, false );
```

### Checking if a key is sensitive
```php
WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key( $key )
WP_MCP_AI_Admin_Settings_Base::get_sensitive_fields()
```

---

## Save Flow (Dashboard)

The `WP_MCP_AI_Settings_Dashboard` class orchestrates a 7-step save:

1. `wp_suspend_cache_addition( true )` — prevent stale Redis writes
2. Clear caches for **both** option names + transient
3. Read fresh from DB (`get_option()` directly — cache was just cleared)
4. Create merged backup (both options) for rollback
5. Filter sensitive keys out of sanitized POST data (unless explicitly provided)
6. Save non-sensitive → `wp_mcp_ai_settings`, sensitive → `wp_mcp_ai_credentials`
7. `wp_suspend_cache_addition( false )` — resume normal caching

---

## Migration

A one-time activation migration moves existing credentials from the old single option to the split:

```php
wp_mcp_ai_migrate_credentials_to_split()
```

- Guard: `wp_mcp_ai_credentials_migrated` option flag
- Runs on `admin_init` (activation hook for fresh installs, deferred for existing)
- Additive: creates credentials option without deleting from settings
- Verify step confirms before removing keys from `wp_mcp_ai_settings`

**Agents should never replicate this logic.** It is idempotent and runs once.

---

## Import/Export

- **Export** calls `get_settings()` → merged JSON includes all keys
- **Import** writes to both options, clears both caches
- **Pre-import backup** is a merged snapshot of both options

---

## Common Pitfalls

| Pitfall | Fix |
|---------|-----|
| Calling `get_option( 'wp_mcp_ai_settings' )` directly | Use `get_settings()` instead |
| Writing API key to `wp_mcp_ai_settings` | Write to `wp_mcp_ai_credentials` |
| Forgetting to clear credentials cache | Clear both: `wp_cache_delete( OPTION_NAME, 'options' )` AND `wp_cache_delete( CREDENTIALS_OPTION_NAME, 'options' )` |
| Assuming single-option storage in hooks | Both options fire `updated_option` — listen for both if subscribing |

---

## Related

- [Feature Doc: Settings Credential Split](../docs/features/settings-credential-split.md)
- [CLAUDE.md](../CLAUDE.md) — full agent conventions
- [includes/bootstrap/activation.php](../includes/bootstrap/activation.php) — migration source
- [includes/admin/class-wp-mcp-ai-admin-settings-base.php](../includes/admin/class-wp-mcp-ai-admin-settings-base.php) — constants + merge logic
