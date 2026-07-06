# Tenant Repository Guide

> How to make a Pro toolkit tenant-aware using the Tenant Repository pattern.

## Quick Start

### For CPT-Based Toolkits (Recommended)

Use the enhanced Data Store Factory:

```php
// OLD — no tenant isolation
$store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_store( 'crm', 'contacts' );

// NEW — with tenant isolation
$store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'crm', 'contacts' );
```

The `get_tenant_store()` method automatically:
1. Creates the store via the existing factory
2. Resolves tenant context from the request
3. Sets tenant context on the store
4. Returns the store — all queries are now tenant-scoped

### For Custom Table Toolkits

Extend `WP_MCP_AI_Tenant_Repository`:

```php
class My_Toolkit_DB extends WP_MCP_AI_Tenant_Repository {

    public function get_items(): array {
        global $wpdb;
        $this->require_tenant();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}my_table WHERE " . $this->tenant_where(),
            ),
            ARRAY_A
        );
        // phpcs:enable
    }

    public function insert_item( array $data ): int {
        global $wpdb;
        $this->require_tenant();

        $data['tenant_type'] = $this->get_tenant_type();
        $data['tenant_id']   = $this->get_tenant_id();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( "{$wpdb->prefix}my_table", $data );
        return $wpdb->insert_id;
        // phpcs:enable
    }
}
```

### In Tool execute() Methods

```php
class My_Tool extends WP_MCP_AI_Tool_Base {

    public function execute( $arguments, $context ) {
        // Resolve tenant context (fail-closed).
        $tenant = WP_MCP_AI_Tenant_Context::instance()->resolve();
        if ( is_wp_error( $tenant ) ) {
            return $tenant;
        }

        // Use the scoped store.
        $store = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store(
            'my-toolkit',
            'items'
        );

        return $store->query_items( $arguments );
    }
}
```

## Tenant Context Resolution

The context manager tries these sources in order:

1. **REST header** `X-WP-MCP-AI-Tenant: school:42`
2. **User meta** `_wp_mcp_ai_tenant` on the current user
3. **Assistant meta** `_wp_mcp_ai_bound_tenant` on the assistant CPT
4. **Multisite** blog ID (fallback)

### Setting User Tenant Meta

```php
update_user_meta( $user_id, '_wp_mcp_ai_tenant', array(
    'type' => 'school',
    'id'   => 42,
) );
```

Or via WP-CLI:

```bash
wp mcp tenant assign 42 school 1 --primary
```

## Tenant-Scoped Options

Use `WP_MCP_AI_Tenant_Options` for per-tenant settings:

```php
// Create from context
$opts = WP_MCP_AI_Tenant_Options::from_context();
if ( $opts ) {
    $value = $opts->get( 'my_setting', 'default' );
    $opts->update( 'my_setting', 'new_value' );
}

// Or explicitly
$opts = new WP_MCP_AI_Tenant_Options( 'school', 42 );
$opts->update( 'enable_feature', true );
```

Option keys are automatically prefixed: `wp_mcp_ai_school_42_enable_feature`.

## Migration

### Adding Tenant Columns to Existing Tables

```php
$table = $wpdb->prefix . 'my_custom_table';

if ( ! WP_MCP_AI_Tenant_Migration::has_tenant_columns( $table ) ) {
    WP_MCP_AI_Tenant_Migration::add_tenant_columns( $table );
    WP_MCP_AI_Tenant_Migration::add_tenant_index( $table );
}

// Backfill existing rows with a default tenant
WP_MCP_AI_Tenant_Migration::backfill_table( $table, 'school', 1 );
```

### Migrating CPT Posts

```php
$count = WP_MCP_AI_Tenant_Migration::migrate_cpt_posts(
    'mcp_ai_eca',  // post type
    'school',       // tenant type
    1               // tenant ID
);
// Adds _tenant_type and _tenant_id post meta to all posts
```

## Backward Compatibility

The system is designed for gradual adoption:

- **By default**, `tenant_id = 0` acts as a bypass (all queries return all data)
- **Feature flags** enable isolation per-toolkit
- **WP_MCP_AI_TENANT_ISOLATION** constant enables globally

Existing code continues to work without changes until the feature flag is enabled.

## Testing Tenant Isolation

```php
class Test_My_Toolkit_Isolation extends WP_UnitTestCase {

    public function test_cross_tenant_isolation() {
        // Set context to Tenant A.
        WP_MCP_AI_Tenant_Context::instance()->set( 'school', 1 );

        // Create data as Tenant A.
        $store_a = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'my-toolkit', 'items' );
        $id_a    = $store_a->create_item( array( 'title' => 'Item A' ) );

        // Switch to Tenant B.
        WP_MCP_AI_Tenant_Context::instance()->set( 'school', 2 );

        // Tenant B should NOT see Tenant A's data.
        $store_b  = WP_MCP_AI_Toolkit_Data_Store_Factory::get_tenant_store( 'my-toolkit', 'items' );
        $all      = $store_b->query_items( array() );

        $this->assertEmpty( $all );

        // Cleanup.
        WP_MCP_AI_Tenant_Context::reset();
    }
}
```
