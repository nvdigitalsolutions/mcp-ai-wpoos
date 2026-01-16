# Connection Type Migration Implementation Guide

## Overview

This guide documents the migration of 4 high-priority connections (iSAMS, Flowhub, PayHere, QuickBooks) from settings-based configuration to the Remote Sites connection management system.

## Phase 1: Infrastructure (COMPLETE ✅)

### Changes Made

#### Remote Site Manager
**File**: `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`

Added 8 new connection fields:
- `api_secret` - For iSAMS API secret, PayHere app secret
- `client_id` - For Flowhub/QuickBooks OAuth client ID
- `client_secret` - For Flowhub/QuickBooks OAuth client secret
- `app_id` - For PayHere app ID  
- `app_secret` - For PayHere app secret
- `location_id` - For Flowhub location ID
- `company_id` - For QuickBooks company ID
- `sandbox_mode` - For PayHere sandbox/live mode

#### Security
- All sensitive fields (`api_secret`, `client_secret`, `app_secret`) are encrypted at rest
- Field preservation during updates (don't overwrite if not provided)
- Proper sanitization of all inputs

#### Validation
Added connection type-specific validation:

```php
// iSAMS
if ( 'isams' === $connection_type ) {
    if ( empty( $connection['api_key'] ) || empty( $connection['api_secret'] ) ) {
        return new WP_Error(...);
    }
}

// Flowhub
if ( 'flowhub' === $connection_type ) {
    if ( empty( $connection['api_key'] ) || empty( $connection['client_id'] ) || 
         empty( $connection['client_secret'] ) || empty( $connection['location_id'] ) ) {
        return new WP_Error(...);
    }
}

// PayHere
if ( 'payhere' === $connection_type ) {
    if ( empty( $connection['app_id'] ) || empty( $connection['app_secret'] ) ) {
        return new WP_Error(...);
    }
}

// QuickBooks
if ( 'quickbooks' === $connection_type ) {
    if ( empty( $connection['client_id'] ) || empty( $connection['client_secret'] ) || 
         empty( $connection['company_id'] ) ) {
        return new WP_Error(...);
    }
}
```

#### Admin Interface
**File**: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`

Updated form handling to accept new fields in POST data.

## Phase 2: Tool Migration (TODO)

### Migration Pattern

Each tool needs to be updated to support both the old settings-based approach and the new connection-based approach for backward compatibility.

#### Example Migration Pattern

**Before** (settings-based):
```php
public function execute( array $arguments = array(), array $context = array() ) {
    // Get settings
    $settings = get_option( 'wp_mcp_ai_settings', array() );
    $api_url  = isset( $settings['isams_api_url'] ) ? $settings['isams_api_url'] : '';
    $api_key  = isset( $settings['isams_api_key'] ) ? $settings['isams_api_key'] : '';
    $api_secret = isset( $settings['isams_api_secret'] ) ? $settings['isams_api_secret'] : '';
    
    // Validate credentials
    if ( empty( $api_url ) || empty( $api_key ) || empty( $api_secret ) ) {
        return new WP_Error(...);
    }
    
    // Make API call
    $result = $this->make_api_call( $api_url, $api_key, $api_secret, $arguments );
}
```

**After** (connection-based with backward compatibility):
```php
public function execute( array $arguments = array(), array $context = array() ) {
    // Check for connection_id (new approach)
    if ( ! empty( $arguments['connection_id'] ) ) {
        $connection_id = sanitize_key( $arguments['connection_id'] );
        $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
        
        if ( null === $connection ) {
            return new WP_Error(
                'wp_mcp_ai_pro_connection_not_found',
                __( 'Connection not found. Use list_connections to see available connections.', 'wp-mcp-ai-pro' )
            );
        }
        
        // Validate connection type
        if ( empty( $connection['connection_type'] ) || 'isams' !== $connection['connection_type'] ) {
            return new WP_Error(
                'wp_mcp_ai_pro_wrong_connection_type',
                __( 'This connection is not an iSAMS connection.', 'wp-mcp-ai-pro' )
            );
        }
        
        // Get credentials from connection
        $api_url = $connection['url'];
        $api_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
        $api_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] );
        
    } else {
        // Fallback to settings (old approach - for backward compatibility)
        $settings = get_option( 'wp_mcp_ai_settings', array() );
        $api_url  = isset( $settings['isams_api_url'] ) ? $settings['isams_api_url'] : '';
        $api_key  = isset( $settings['isams_api_key'] ) ? $settings['isams_api_key'] : '';
        $api_secret = isset( $settings['isams_api_secret'] ) ? $settings['isams_api_secret'] : '';
        
        // Show deprecation notice
        if ( ! empty( $api_url ) && ! empty( $api_key ) ) {
            error_log( 'WP MCP AI: Settings-based iSAMS configuration is deprecated. Please migrate to Remote Sites connections.' );
        }
    }
    
    // Validate credentials
    if ( empty( $api_url ) || empty( $api_key ) || empty( $api_secret ) ) {
        return new WP_Error(...);
    }
    
    // Make API call
    $result = $this->make_api_call( $api_url, $api_key, $api_secret, $arguments );
}
```

#### Schema Updates

Add `connection_id` as an optional parameter:

```php
public function get_parameters_schema() {
    return array(
        'type'       => 'object',
        'properties' => array(
            'connection_id' => array(
                'type'        => 'string',
                'description' => __( 'Connection ID from Remote Sites. Call list_connections action first to get available connections.', 'wp-mcp-ai-pro' ),
            ),
            // ... existing parameters
        ),
        'required' => array( /* existing required params */ ),
    );
}
```

### Tools to Migrate

#### iSAMS (3 tools)
1. **WP_MCP_AI_Tool_ISAMS_Query** 
   - File: `addons/pro/includes/tools/class-wp-mcp-ai-tool-isams-query.php`
   - Credentials: `api_url`, `api_key`, `api_secret`
   
2. **WP_MCP_AI_Tool_Sync_Students_From_ISAMS**
   - File: `addons/pro/includes/tools/class-wp-mcp-ai-tool-sync-students-from-isams.php`
   - Credentials: Same as above
   
3. **WP_MCP_AI_Tool_Sync_ECAs_From_ISAMS**
   - File: `addons/pro/includes/tools/class-wp-mcp-ai-tool-sync-ecas-from-isams.php`
   - Credentials: Same as above

#### Flowhub (7 tools + 1 client)
**Client to update**: `includes/class-wp-mcp-ai-flowhub-client.php`
- Add method `get_from_connection( $connection_id )` to load credentials from connection

**Tools**:
1. `includes/tools/class-wp-mcp-ai-tool-flowhub-get-products.php`
2. `includes/tools/class-wp-mcp-ai-tool-flowhub-get-inventory.php`
3. `includes/tools/class-wp-mcp-ai-tool-flowhub-get-orders.php`
4. `includes/tools/class-wp-mcp-ai-tool-flowhub-get-customers.php`
5. `includes/tools/class-wp-mcp-ai-tool-flowhub-create-order.php`
6. `includes/tools/class-wp-mcp-ai-tool-flowhub-manage-product.php`
7. `includes/tools/class-wp-mcp-ai-tool-flowhub-manage-customer.php`

Credentials: `api_key`, `client_id`, `client_secret`, `location_id`

#### PayHere (1 tool + 1 client)
**Client to update**: `includes/class-wp-mcp-ai-payhere-client.php`
- Add method `get_from_connection( $connection_id )` to load credentials from connection

**Tool**:
1. `includes/tools/class-wp-mcp-ai-tool-payhere-get-payment.php`

Credentials: `app_id`, `app_secret`, `sandbox_mode`

#### QuickBooks (1 tool)
1. **WP_MCP_AI_Pro_Tool_Get_QuickBooks_Report**
   - File: `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php`
   - Credentials: `client_id`, `client_secret`, `company_id`
   - Note: May need OAuth token refresh handling

### Actions to Add

Each tool should add a `list_connections` action (like EZuite ERP tool):

```php
// In execute() method
if ( 'list_connections' === $action ) {
    return $this->list_connections( $context );
}

protected function list_connections( $context = array() ) {
    $connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
    $assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
    
    // Filter for this connection type
    $filtered = array();
    foreach ( $connections as $connection ) {
        if ( empty( $connection['enabled'] ) ) {
            continue;
        }
        if ( empty( $connection['connection_type'] ) || 'isams' !== $connection['connection_type'] ) {
            continue;
        }
        // Check assistant enablement if needed
        $filtered[] = array(
            'id'      => $connection['id'],
            'name'    => $connection['name'],
            'url'     => $connection['url'],
            'enabled' => true,
        );
    }
    
    return array(
        'summary'     => sprintf( __( 'Found %d connection(s)', 'wp-mcp-ai-pro' ), count( $filtered ) ),
        'connections' => $filtered,
        'count'       => count( $filtered ),
    );
}
```

## Phase 3: UI Enhancements (OPTIONAL)

### Connection Type Selector
Update the Remote Sites admin form to show/hide fields based on connection type selection.

**File**: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`

Add JavaScript to toggle field visibility:
```javascript
jQuery('#connection_type').on('change', function() {
    var type = jQuery(this).val();
    
    // Hide all optional fields
    jQuery('.optional-field').hide();
    
    // Show relevant fields based on type
    switch(type) {
        case 'isams':
            jQuery('.field-api-key, .field-api-secret').show();
            break;
        case 'flowhub':
            jQuery('.field-api-key, .field-client-id, .field-client-secret, .field-location-id').show();
            break;
        case 'payhere':
            jQuery('.field-app-id, .field-app-secret, .field-sandbox-mode').show();
            break;
        case 'quickbooks':
            jQuery('.field-client-id, .field-client-secret, .field-company-id').show();
            break;
    }
});
```

### Form Fields to Add
- Connection Type dropdown (with new types)
- API Secret field (password)
- Client ID field (text)
- Client Secret field (password)
- App ID field (text)
- App Secret field (password)
- Location ID field (text)
- Company ID field (text)
- Sandbox Mode checkbox

## Testing Checklist

### For Each Connection Type:
- [ ] Can create new connection with all required fields
- [ ] Required field validation works
- [ ] Credentials are encrypted in database
- [ ] Can edit connection without re-entering credentials
- [ ] Can test connection successfully
- [ ] Tools can use connection_id to access credentials
- [ ] Tools still work with old settings-based approach
- [ ] Per-assistant enablement works
- [ ] Multiple connections can be created (e.g., multiple schools for iSAMS)

### Security:
- [ ] Credentials are never exposed in logs
- [ ] Credentials are encrypted at rest
- [ ] Capability checks work correctly
- [ ] Connection validation prevents wrong type usage

## Rollout Strategy

1. **Phase 1** (Complete): Infrastructure ready
2. **Phase 2**: Migrate one connection type at a time
   - Start with **PayHere** (simplest - 1 tool + 1 client)
   - Then **iSAMS** (3 related tools)
   - Then **QuickBooks** (1 tool but OAuth)
   - Finally **Flowhub** (most complex - 7 tools + 1 client)
3. **Phase 3**: Add UI enhancements
4. **Phase 4**: Deprecate settings-based approach
5. **Phase 5**: Remove old settings after 2-3 versions

## Benefits After Migration

1. **Multiple Instances**: Users can connect to multiple iSAMS schools, Flowhub locations, PayHere accounts, etc.
2. **Per-Assistant Control**: Enable specific connections per assistant
3. **Better Organization**: All external connections in one place
4. **Connection Testing**: Built-in connection test before use
5. **Audit Trail**: Track which connections are used when
6. **Security**: Centralized credential management with encryption
7. **Staging/Production**: Separate connections for different environments

## Documentation to Update

After migration:
- [ ] Update tool documentation to show connection_id usage
- [ ] Add migration guide for existing users
- [ ] Update screenshots showing new connection UI
- [ ] Add examples of list_connections usage
- [ ] Document backward compatibility period

## Estimated Effort

- **Phase 1** (Infrastructure): ✅ Complete (2 hours)
- **Phase 2** (Tool Migration): 🕒 8-12 hours
  - PayHere: 2 hours (1 tool + 1 client)
  - iSAMS: 3 hours (3 tools)
  - QuickBooks: 2 hours (1 tool + OAuth)
  - Flowhub: 4-5 hours (7 tools + 1 client)
- **Phase 3** (UI): 🕒 2-3 hours
- **Testing**: 🕒 3-4 hours
- **Documentation**: 🕒 2 hours

**Total**: 17-24 hours of development work

## Next Steps

1. Complete Phase 2 tool migration following the pattern documented above
2. Test each connection type thoroughly
3. Update UI to show connection-type-specific fields
4. Create user migration documentation
5. Set deprecation timeline for settings-based approach
