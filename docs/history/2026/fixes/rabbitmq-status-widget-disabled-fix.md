# RabbitMQ Status Widget Always Shows Disabled — Fix Details

## Problem Description

On the Orchestration → RabbitMQ settings view
(`/admin.php?page=wp-mcp-ai-dashboard&tab=orchestration&view=rabbitmq`), the
**RabbitMQ Status** widget reported:

- PHP AMQP Extension: ✓ Loaded
- Integration Enabled: ○ Disabled

…even after the "Enable RabbitMQ Integration" checkbox was checked and the
settings saved. No errors or warnings were shown on the page.

## Root Cause

`WP_MCP_AI_Section_RabbitMQ` read the setting from a non-existent property:

```php
$enabled = ! empty( $this->settings['rabbitmq_enabled'] );
```

Neither `WP_MCP_AI_Section_RabbitMQ` nor its parent
`WP_MCP_AI_Settings_Section` ever declares or populates a `$settings`
property. Accessing an undefined property on a class without a `__get()`
magic method silently yields `null`, so `$enabled` was always `false` and the
widget always rendered "Disabled" — regardless of the saved checkbox value.
This pattern appeared in two places:

1. `render_status_widget()` — the status table's "Integration Enabled" row.
2. `is_visible()` — section visibility (masked in practice because
   `extension_loaded( 'amqp' )` was true on affected servers).

The checkbox field itself renders via `render_field()`, which correctly reads
`WP_MCP_AI_Settings_Registry::get_setting()`, which is why the checkbox state
and the status widget disagreed.

### Secondary Bug — AJAX Handlers Never Registered

`WP_MCP_AI_Section_RabbitMQ::register_ajax_handlers()` existed but was never
called from anywhere. The `wp_ajax_wp_mcp_ai_rabbitmq_health` and
`wp_ajax_wp_mcp_ai_rabbitmq_setup` endpoints were therefore not hooked, so the
"Test Connection" and "Setup Queues" buttons could never receive a response
(they would have failed with a generic "Request failed" error).

## Solution Implemented

### Key Changes

File: `includes/admin/sections/class-wp-mcp-ai-section-rabbitmq.php`

1. **New `is_integration_enabled()` helper** — reads the saved setting through
   `WP_MCP_AI_Settings_Registry::get_setting( 'rabbitmq_enabled', false )`
   (the same source used by the field renderer), with the
   `WP_MCP_AI_RABBITMQ_ENABLED` constant as an override fallback:

   ```php
   private function is_integration_enabled() {
       if ( defined( 'WP_MCP_AI_RABBITMQ_ENABLED' ) && WP_MCP_AI_RABBITMQ_ENABLED ) {
           return true;
       }
       return ! empty( WP_MCP_AI_Settings_Registry::get_setting( 'rabbitmq_enabled', false ) );
   }
   ```

2. **`render_status_widget()` and `is_visible()`** now use that helper instead
   of `$this->settings['rabbitmq_enabled']`.

3. **New constructor** that calls `register_ajax_handlers()` exactly once per
   request. A static guard (`$ajax_registered`) prevents duplicate `wp_ajax_*`
   registration because the section is instantiated in two places: the
   settings registry (`settings-dashboard-init.php`) and the Orchestration
   section's inline rabbitmq view (`render_rabbitmq_view()`).

   ```php
   public function __construct() {
       if ( ! self::$ajax_registered ) {
           self::$ajax_registered = true;
           $this->register_ajax_handlers();
       }
   }
   ```

### After the Fix

- The status widget shows "✓ Enabled" when the checkbox is saved as enabled
  (or when `WP_MCP_AI_RABBITMQ_ENABLED` is defined truthy).
- The "Connection Status" row, "Test Connection", and "Setup Queues" controls
  now render and their AJAX endpoints respond.

## Testing

### Automated

`vendor/bin/phpunit tests/test-runtime-control-ajax.php --filter rabbitmq`

- Before the fix: 4 / 6 passing (health/setup handlers unregistered).
- After the fix: 6 / 6 passing.

### Static Analysis

- `php -l includes/admin/sections/class-wp-mcp-ai-section-rabbitmq.php` — no syntax errors.
- `vendor/bin/phpcs includes/admin/sections/class-wp-mcp-ai-section-rabbitmq.php` — clean.

### Manual Verification

1. Go to NV oOS → Orchestration → RabbitMQ.
2. Check "Enable RabbitMQ Integration" and save.
3. The RabbitMQ Status widget should now show "✓ Enabled".
4. Click "Test Connection" — with valid Cloudways credentials the row should
   report "✓ Connected"; otherwise it shows the connection error message.

## Known Related Behavior (Not Changed)

Actual tool-queue interception by `WP_MCP_AI_Queue_Manager` remains gated
behind the `WP_MCP_AI_RABBITMQ_ENABLED` constant or the
`wp_mcp_ai_rabbitmq_enabled` filter — the admin checkbox controls the RabbitMQ
client and its status display, not that runtime queue hook. Bridging the
checkbox setting into that filter is a potential follow-up.

## Related Files

- `includes/admin/sections/class-wp-mcp-ai-section-rabbitmq.php` — fix.
- `includes/admin/sections/class-wp-mcp-ai-section-orchestration.php` — inline rabbitmq view host.
- `includes/class-wp-mcp-ai-rabbitmq-client.php` — health check / client config.
- `tests/test-runtime-control-ajax.php` — AJAX regression tests.
