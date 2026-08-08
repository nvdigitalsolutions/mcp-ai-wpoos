# Export Providers (Backup & Restore)

## Purpose

Export providers are self-contained classes that know how to export and import a single data domain — plugin settings, assistants, CPT posts, custom database tables, federation configs, JetEngine CCT data, and more. Each provider implements a simple contract so the central `WP_MCP_AI_Export_Manager` can orchestrate full-site backups and restores without knowing domain-specific details.

## How They Work

1. **Registration** — Providers are registered with the `WP_MCP_AI_Export_Manager` singleton, typically on `init` or `plugins_loaded`.
2. **UI Discovery** — The manager queries each provider's `get_id()`, `get_label()`, `get_description()`, `is_available()`, `get_count()`, and `contains_sensitive_data()` to render checkboxes in the Backup & Restore admin screen.
3. **Export** — When the user exports, the manager calls `export()` on each selected provider and wraps the results in a versioned JSON envelope (optionally encrypted with AES-256-CBC).
4. **Import** — When importing, the file is parsed → each provider's `validate()` runs as a dry-run guard → `import()` commits the data → the `wp_mcp_ai_after_import` action fires per provider.

## The Interface

All providers implement `WP_MCP_AI_Export_Provider` (at `interface-wp-mcp-ai-export-provider.php`):

```php
interface WP_MCP_AI_Export_Provider {
    public function get_id(): string;
    public function get_label(): string;
    public function get_description(): string;
    public function is_available(): bool;
    public function contains_sensitive_data(): bool;
    public function get_count(): int;
    public function export(): array;
    public function validate( array $data );      // returns true|WP_Error
    public function import( array $data );        // returns true|WP_Error
}
```

## The Abstract Base

`WP_MCP_AI_Export_Provider_Base` (at `class-wp-mcp-ai-export-provider-base.php`) provides shared helpers:

| Helper | Purpose |
|---|---|
| `get_option_safe( $option_name, $default )` | Reads a WordPress option with cache busting, ensuring fresh data during export. |
| `is_sensitive_key( $key )` | Detects API keys, tokens, credentials — delegates to `WP_MCP_AI_Admin_Settings_Base` when available. |
| `maybe_decrypt_value( $value )` | Decrypts an encrypted sensitive setting value during export. |
| `log_action( $action, $result )` | Records an audit entry (imported / validated / failed) in `wp_mcp_ai_export_import_log`. |
| `render_checkbox( $checked )` | Renders the standard UI checkbox row with count badge and sensitive-data warning icon. |

## Creating a Minimal Provider

1. Place your file in `includes/admin/export/` (or `addons/pro/includes/export/` for Pro-only providers).
2. Extend `WP_MCP_AI_Export_Provider_Base`.
3. Implement the nine methods from the interface.

### Example: Minimal Provider

```php
<?php
/**
 * My Feature Export Provider.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_MCP_AI_Export_Provider_My_Feature extends WP_MCP_AI_Export_Provider_Base {

    public function get_id(): string {
        return 'my_feature';
    }

    public function get_label(): string {
        return __( 'My Feature', 'mcp-ai-wpoos' );
    }

    public function get_description(): string {
        return __( 'Description of what this exports.', 'mcp-ai-wpoos' );
    }

    public function is_available(): bool {
        return true; // or check a dependency
    }

    public function contains_sensitive_data(): bool {
        return false;
    }

    public function get_count(): int {
        // Return an approximate count for the UI badge.
        return 0;
    }

    public function export(): array {
        // Return the data to be serialized in the JSON export file.
        $saved_data = $this->get_option_safe( 'my_feature_option', array() );
        return is_array( $saved_data ) ? $saved_data : array();
    }

    public function validate( array $data ) {
        if ( empty( $data ) ) {
            return new \WP_Error( 'my_feature_empty', __( 'No data to import.', 'mcp-ai-wpoos' ) );
        }
        return true;
    }

    public function import( array $data ) {
        $updated = update_option( 'my_feature_option', $data, false );
        if ( false === $updated ) {
            return new \WP_Error( 'my_feature_save_failed', __( 'Failed to save data.', 'mcp-ai-wpoos' ) );
        }
        $this->log_action( 'imported', true );
        return true;
    }
}
```

## Registering a Provider

Registration is done via the `wp_mcp_ai_register_export_providers` action. Hook early enough (e.g., `plugins_loaded` or `init`) and call `WP_MCP_AI_Export_Manager::instance()->register( new My_Provider() )`.

```php
add_action( 'wp_mcp_ai_register_export_providers', function () {
    $manager = WP_MCP_AI_Export_Manager::instance();
    $manager->register( new WP_MCP_AI_Export_Provider_My_Feature() );
} );
```

Pro-only providers (text domain `mcp-ai-wpoos-pro`) belong in `addons/pro/includes/export/` and should be registered in the Pro addon bootstrap. Use `is_available()` to return `false` when the dependency is missing (e.g., `class_exists( 'Jet_Engine' )`).

## Available Hooks

### Action: `wp_mcp_ai_after_import`

Fires after a successful import for a single provider. Use to clear caches, rebuild indexes, or trigger side effects.

```php
add_action( 'wp_mcp_ai_after_import', function ( $provider_id, $imported_data ) {
    if ( 'my_feature' === $provider_id ) {
        wp_cache_flush();
    }
}, 10, 2 );
```

### Filter: `wp_mcp_ai_export_visible_providers`

Filters which providers appear in the UI. Return a modified array of provider IDs to show/hide specific providers.

### Filter: `wp_mcp_ai_export_data`

Filters the entire export data array before JSON encoding. Use to redact or transform data at the envelope level.

### Filter: `wp_mcp_ai_import_data`

Filters the entire import data array after JSON parsing but before validation. Use to normalize legacy formats or transform migrated data.

## Security Considerations

### Sensitive Data Flag

If your provider exports API keys, tokens, or passwords, return `true` from `contains_sensitive_data()`. This:
- Displays a warning icon (⚠️) in the UI next to the provider checkbox.
- Sets `contains_sensitive_data: true` in the JSON envelope.
- Prompts the user to consider password-protecting the export file.

### Encryption

The export manager supports AES-256-CBC encryption via a user-provided passphrase. When enabled, the entire JSON payload is encrypted and wrapped in an `{"encrypted": true, "payload": "..."}` envelope. Decryption requires the same passphrase at import time.

### Pre-Import Backup

Before committing any import, the export manager automatically creates a snapshot of the current `wp_mcp_ai_settings` option (stored as `wp_mcp_ai_settings_backup_pre_import_{timestamp}`). This is a safety net — you can restore via `wp option get` / `wp option update` if needed.

### Audit Log

Every provider action is recorded in `wp_mcp_ai_export_import_log` (last 50 entries) via `log_action()`. Entries include the provider ID, action type, user, timestamp, and result.

### Data Integrity

- **validate() runs before import()** — if validation fails, the provider's data is skipped entirely (other providers still import).
- **Providers are independent** — a failure in one provider does not roll back others. Review the import results array after import to confirm all providers succeeded.
- **Silent skip for missing infrastructure** — providers that check for table/post-type/class existence during import silently skip data they cannot store rather than erroring out.

## Existing Providers

| Provider ID | Class | Data Domain |
|---|---|---|
| `core_settings` | `WP_MCP_AI_Export_Provider_Core_Settings` | Plugin configuration, API keys, provider settings |
| `assistants` | `WP_MCP_AI_Export_Provider_Assistants` | `mcp_ai_assistant` CPT posts and meta |
| `cpts` | `WP_MCP_AI_Export_Provider_CPTs` | Tasks, vault, audit, templates, peers |
| `addon_options` | `WP_MCP_AI_Export_Provider_Addon_Options` | Addon-specific WordPress options |
| `toolkit_options` | `WP_MCP_AI_Export_Provider_Toolkit_Options` | Toolkit settings options |
| `custom_tables` | `WP_MCP_AI_Export_Provider_Custom_Tables` | Custom DB tables (embeddings, threads, tenants, audit, metrics) |
| `federation` | `WP_MCP_AI_Export_Provider_Federation` | Federation peers & MCP connections |
| `license` | `WP_MCP_AI_Export_Provider_License` (Pro) | Pro license state |
| `remote_sites` | `WP_MCP_AI_Export_Provider_Remote_Sites` (Pro) | Remote site connections |
| `jetengine_ccts` | `WP_MCP_AI_Export_Provider_JetEngine_CCTs` (Pro) | JetEngine CCT tables |

## See Also

- [`interface-wp-mcp-ai-export-provider.php`](interface-wp-mcp-ai-export-provider.php) — the contract all providers implement.
- [`class-wp-mcp-ai-export-provider-base.php`](class-wp-mcp-ai-export-provider-base.php) — abstract base with shared helpers.
- [`class-wp-mcp-ai-export-manager.php`](class-wp-mcp-ai-export-manager.php) — central orchestrator (encryption, backup, import coordination).
- [`../../CLAUDE.md`](../../CLAUDE.md) — PHP compat, naming conventions, and tool authoring rules.
