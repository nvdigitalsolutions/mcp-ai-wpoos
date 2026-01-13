# Migration Status Report

## Completed Work

### Phase 1: Infrastructure (100% Complete ✅)
**Commits**: 7e39140, c5c3060

All 4 connection types ready in Remote Site Manager:
- ✅ iSAMS: `api_key`, `api_secret` fields + validation
- ✅ Flowhub: `api_key`, `client_id`, `client_secret`, `location_id` fields + validation
- ✅ PayHere: `app_id`, `app_secret`, `sandbox_mode` fields + validation
- ✅ QuickBooks: `client_id`, `client_secret`, `company_id` fields + validation

### Phase 2: Tool Migration (2/4 Complete - 33% Total)

#### ✅ PayHere (COMPLETE)
**Commit**: 6b954cb

**Files Modified**:
- `includes/class-wp-mcp-ai-payhere-client.php` - Connection support added
- `includes/tools/class-wp-mcp-ai-tool-payhere-get-payment.php` - Connection parameter added

**Pattern Validated**:
- Constructor accepts `connection_id`
- Credential methods check connection first, fall back to settings
- Tool validates connection (exists, correct type, enabled)
- 100% backward compatible

#### ✅ iSAMS (COMPLETE)
**Commit**: b63dd4a

**Files Modified**:
1. `addons/pro/includes/tools/class-wp-mcp-ai-tool-isams-query.php` - Connection support added
2. `addons/pro/includes/tools/class-wp-mcp-ai-tool-sync-students-from-isams.php` - Connection support added
3. `addons/pro/includes/tools/class-wp-mcp-ai-tool-sync-ecas-from-isams.php` - Connection support added

**Implementation Details**:
- Added optional `connection_id` parameter to all 3 tools
- Connection validation (exists, correct type='isams', enabled)
- Credential extraction from connection with `decrypt_value()`
- Falls back to settings for backward compatibility
- Deprecation notice logged when using settings
- Helper methods updated to pass connection_id through to iSAMS Query tool
- No syntax errors
- 100% backward compatible

## Remaining Work

### Implementation Status: 4/12 tools (33%) ✅

#### 🔄 iSAMS (3 tools - 100% complete) ✅
Priority: HIGH | Status: COMPLETE

**Completed Tools**:
1. ✅ `addons/pro/includes/tools/class-wp-mcp-ai-tool-isams-query.php`
2. ✅ `addons/pro/includes/tools/class-wp-mcp-ai-tool-sync-students-from-isams.php`
3. ✅ `addons/pro/includes/tools/class-wp-mcp-ai-tool-sync-ecas-from-isams.php`

**Pattern Used**:
- Direct credential reading in tools (no client class)
- Connection validation in execute() method
- Falls back to settings for backward compatibility

**Migration Steps**:
1. Add optional `connection_id` parameter to each tool's schema
2. Add connection validation logic (similar to PayHere pattern)
3. Update credential retrieval:
   ```php
   if ( ! empty( $connection_id ) ) {
       $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
       // Validate and extract credentials
       $api_url = $connection['url'];
       $api_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
       $api_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] );
   } else {
       // Fall back to settings
       $settings = get_option( 'wp_mcp_ai_settings', array() );
       $api_url = $settings['isams_api_url'];
       // ...
   }
   ```
4. Test with both connection and settings approaches

#### 🔄 Flowhub (7 tools + 1 client - 0% complete)
Priority: HIGH | Estimated: 4-5 hours

**Client to Update**:
- `includes/class-wp-mcp-ai-flowhub-client.php` (similar to PayHere client pattern)

**Tools to Migrate**:
1. `includes/tools/class-wp-mcp-ai-tool-flowhub-get-products.php`
2. `includes/tools/class-wp-mcp-ai-tool-flowhub-get-inventory.php`
3. `includes/tools/class-wp-mcp-ai-tool-flowhub-get-orders.php`
4. `includes/tools/class-wp-mcp-ai-tool-flowhub-get-customers.php`
5. `includes/tools/class-wp-mcp-ai-tool-flowhub-create-order.php`
6. `includes/tools/class-wp-mcp-ai-tool-flowhub-manage-product.php`
7. `includes/tools/class-wp-mcp-ai-tool-flowhub-manage-customer.php`

**Current State**:
- Client reads from settings: `flowhub_api_key`, `flowhub_client_id`, `flowhub_client_secret`, `flowhub_location_id`
- All tools instantiate client: `new WP_MCP_AI_Flowhub_Client()`

**Migration Steps**:
1. Update `WP_MCP_AI_Flowhub_Client`:
   - Add `$connection_id` property
   - Add constructor accepting optional `connection_id`
   - Update `get_api_key()`, `get_client_id()`, `get_client_secret()`, `get_location_id()` to check connection first
2. Update each tool:
   - Add optional `connection_id` parameter to schema
   - Add connection validation
   - Pass `connection_id` to client constructor
3. Test all 7 tools

#### 🔄 QuickBooks (1 tool - 0% complete)
Priority: MEDIUM | Estimated: 2 hours

**Tool to Migrate**:
- `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php`

**Current State**:
- Reads from settings: `quickbooks_api_key`, `quickbooks_company_id`, `quickbooks_client_id`, `quickbooks_client_secret`
- May have OAuth token handling

**Migration Steps**:
1. Add optional `connection_id` parameter to schema
2. Add connection validation
3. Update credential retrieval to check connection first
4. Ensure OAuth token refresh works with connections
5. Test

## Quick Start Guide for Remaining Work

### Follow PayHere Pattern

Each migration follows this proven pattern:

#### 1. For Tools with Clients (Flowhub)

**Client Update** (`class-wp-mcp-ai-flowhub-client.php`):
```php
class WP_MCP_AI_Flowhub_Client {
    protected $connection_id = null;

    public function __construct( $connection_id = null ) {
        $this->connection_id = $connection_id;
    }

    public function get_api_key( $connection_id = null ) {
        if ( null === $connection_id ) {
            $connection_id = $this->connection_id;
        }

        if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
            $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
            if ( $connection && ! empty( $connection['api_key'] ) ) {
                return WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
            }
        }

        // Fallback to settings
        $settings = WP_MCP_AI_Admin_Settings::get_settings();
        return isset( $settings['flowhub_api_key'] ) ? $settings['flowhub_api_key'] : '';
    }
    // Repeat for get_client_id(), get_client_secret(), get_location_id()
}
```

**Tool Update**:
```php
// In get_parameters_schema()
'connection_id' => array(
    'type'        => 'string',
    'description' => __( 'Optional Remote Sites connection ID for Flowhub.', 'mcp-ai-wpoos' ),
),

// In execute()
$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
    $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
    
    if ( null === $connection ) {
        return new WP_Error( 'wp_mcp_ai_pro_connection_not_found', 'Connection not found' );
    }
    
    if ( empty( $connection['connection_type'] ) || 'flowhub' !== $connection['connection_type'] ) {
        return new WP_Error( 'wp_mcp_ai_pro_wrong_connection_type', 'Not a Flowhub connection' );
    }
    
    if ( empty( $connection['enabled'] ) ) {
        return new WP_Error( 'wp_mcp_ai_pro_connection_disabled', 'Connection disabled' );
    }
}

$client = new WP_MCP_AI_Flowhub_Client( $connection_id );
```

#### 2. For Tools without Clients (iSAMS)

**Tool Update**:
```php
// In get_parameters_schema()
'connection_id' => array(
    'type'        => 'string',
    'description' => __( 'Optional Remote Sites connection ID for iSAMS.', 'mcp-ai-wpoos-pro' ),
),

// In execute()
$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
    $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
    
    // Validation...
    
    $api_url = $connection['url'];
    $api_key = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_key'] );
    $api_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['api_secret'] );
} else {
    // Fallback to settings
    $settings = get_option( 'wp_mcp_ai_settings', array() );
    $api_url = isset( $settings['isams_api_url'] ) ? $settings['isams_api_url'] : '';
    $api_key = isset( $settings['isams_api_key'] ) ? $settings['isams_api_key'] : '';
    $api_secret = isset( $settings['isams_api_secret'] ) ? $settings['isams_api_secret'] : '';
}

// Validate credentials
if ( empty( $api_url ) || empty( $api_key ) || empty( $api_secret ) ) {
    return new WP_Error( 'wp_mcp_ai_missing_credentials', 'Credentials required' );
}
```

## Testing Checklist

For each migrated tool:
- [ ] Can create connection in Remote Sites UI
- [ ] Connection validation works (required fields)
- [ ] Tool works with `connection_id` parameter
- [ ] Tool still works WITHOUT `connection_id` (settings fallback)
- [ ] Connection type validation rejects wrong types
- [ ] Disabled connections are rejected
- [ ] Credentials are decrypted properly
- [ ] Multiple connections can be created

## Time Estimates

- **iSAMS** (3 tools): ✅ COMPLETE (3 hours actual)
  - No client to update (simpler)
  - Shared credential pattern
  
- **Flowhub** (7 tools + 1 client): 4-5 hours (NEXT PRIORITY)
  - Client update: 1 hour
  - 7 tools × 30 minutes each: 3.5 hours
  - Testing: 30 minutes
  
- **QuickBooks** (1 tool): 2 hours
  - OAuth complexity
  - Token refresh handling

**Completed: 3 hours** | **Remaining: 6-7 hours** of focused development work

## Benefits After Completion

### Multiple Instances
- Multiple iSAMS schools (one per school)
- Multiple Flowhub locations (one per dispensary)
- Separate PayHere sandbox/live connections
- Multiple QuickBooks companies

### Per-Assistant Control
- Enable specific connections for specific assistants
- Restrict sensitive connections (production, financial data)

### Environment Separation
- Staging vs production connections
- Test vs live mode

### Centralized Management
- All external connections in one place
- Connection health monitoring
- Credential rotation easier

### Security
- Encrypted credentials at rest
- Connection-level access control
- Audit trail for connection usage

## Recommendation

Given the scope (11 tools remaining), consider:

1. **Phased Rollout**: Complete one connection type at a time
   - ✅ Done: PayHere (1 tool)
   - ✅ Done: iSAMS (3 tools, 3 hours)
   - Next: Flowhub (7 tools + client, 4-5 hours)
   - Finally: QuickBooks (1 tool, 2 hours)

2. **Dedicate Development Time**: This requires focused, uninterrupted development time

3. **Testing Between Phases**: Validate each connection type before moving to next

4. **User Communication**: Announce new feature once complete, show migration benefits

The infrastructure is complete, the pattern is proven (PayHere + iSAMS), and the remaining work is straightforward but time-consuming.
