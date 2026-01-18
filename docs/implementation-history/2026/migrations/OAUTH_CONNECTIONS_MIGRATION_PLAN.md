# OAuth Connections Migration Plan

## Executive Summary

This document outlines the migration strategy for Gmail, Google Analytics, TikTok, and Meta (Facebook/Instagram) connections from settings-based OAuth configuration to the Remote Sites connection management system.

## Current Status

### Completed Migrations (4 integrations, 12 tools)
- ✅ **PayHere** (1 tool) - API key based
- ✅ **iSAMS** (3 tools) - API key/secret based
- ✅ **Flowhub** (7 tools + 1 client) - OAuth2 based
- ✅ **QuickBooks** (1 tool) - OAuth2 based

### Pending Migrations (4 integrations, 7 tools)
- 🔄 **Gmail** (1 tool) - OAuth2 with refresh tokens
- 🔄 **Google Analytics** (1 tool) - Service account JSON credentials
- 🔄 **TikTok** (2 tools) - OAuth2 access tokens
- 🔄 **Meta/Facebook/Instagram** (3 tools) - OAuth2 access tokens

## Tools Analysis

### Gmail Tools
**Tools Count**: 1
- `class-wp-mcp-ai-pro-tool-search-gmail.php` - Search Gmail messages

**Current Credentials**:
```php
'gmail_client_id'      => 'OAuth 2.0 Client ID'
'gmail_client_secret'  => 'OAuth 2.0 Client Secret'  
'gmail_refresh_token'  => 'Stored after OAuth flow'
'gmail_user_email'     => 'Connected email address'
```

**OAuth Flow**: 
- User initiates: `wp_mcp_ai_gmail_oauth_start`
- Callback: `wp_mcp_ai_gmail_oauth_callback`
- Uses refresh token for access token renewal

**Complexity**: **MEDIUM**
- OAuth refresh token management required
- Token renewal logic needed
- Per-user connection state

### Google Analytics Tools
**Tools Count**: 1
- `class-wp-mcp-ai-pro-tool-get-google-analytics-report.php` - GA4 reporting

**Current Credentials**:
```php
'google_analytics_property_id'         => 'GA4 Property ID'
'google_analytics_credentials'         => 'Legacy JSON field (deprecated)'
'google_analytics_credentials_json'    => 'Service account JSON'
'ita_tariff_api_key'                   => 'Unrelated tariff API'
```

**Authentication Type**: Service Account (not OAuth)
- Uses JWT for authentication
- Service account JSON with private key
- No refresh token needed

**Complexity**: **HIGH**
- JSON credential parsing required
- JWT token generation
- Service account key management
- Different from OAuth pattern

### TikTok Tools
**Tools Count**: 2
- `class-wp-mcp-ai-pro-tool-post-tiktok-video.php` - Publish videos
- `class-wp-mcp-ai-pro-tool-get-tiktok-insights.php` - Get analytics

**Current Credentials**:
```php
'tiktok_access_token'  => 'Long-lived access token'
'tiktok_client_key'    => 'Client Key from developer portal'
'tiktok_client_secret' => 'Client Secret'
```

**OAuth Flow**: Manual (no OAuth handler in codebase)
- User obtains token externally
- No automatic refresh mechanism
- Tools accept token as parameter OR use settings

**Complexity**: **LOW-MEDIUM**
- Simple token-based auth
- No refresh logic currently
- Direct parameter passing supported

### Meta (Facebook/Instagram) Tools
**Tools Count**: 3
- `class-wp-mcp-ai-pro-tool-post-facebook-instagram.php` - Publish posts
- `class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php` - Get insights
- `class-wp-mcp-ai-pro-tool-post-google-business-update.php` - Related

**Current Credentials**:
```php
'meta_access_token'           => 'Page/business access token'
'meta_app_id'                 => 'App ID'
'meta_app_secret'             => 'App Secret'
'meta_business_account_id'    => 'Business Account ID'
'meta_connected_user_name'    => 'Connected user display name'
'meta_connected_user_id'      => 'Connected user ID'
```

**OAuth Flow**:
- User initiates: `wp_mcp_ai_meta_oauth_start`
- Callback: `wp_mcp_ai_meta_oauth_callback`
- Stores access token after OAuth

**Complexity**: **MEDIUM-HIGH**
- OAuth flow handler exists
- Multiple credential fields
- Token expiration handling
- Page/account selection

## Migration Strategy

### Phase 1: Infrastructure (Week 1)

#### 1.1 Add New Connection Types to Remote Site Manager

**File**: `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`

Add new connection types:
```php
public static function get_connection_types() {
    return array(
        // ... existing types ...
        'gmail' => array(
            'label'       => __( 'Gmail', 'mcp-ai-wpoos-pro' ),
            'description' => __( 'Gmail API connection with OAuth2', 'mcp-ai-wpoos-pro' ),
            'auth_types'  => array( 'oauth2' ),
            'fields'      => array(
                'client_id'     => __( 'OAuth 2.0 Client ID', 'mcp-ai-wpoos-pro' ),
                'client_secret' => __( 'OAuth 2.0 Client Secret', 'mcp-ai-wpoos-pro' ),
                'refresh_token' => __( 'Refresh Token (auto-populated)', 'mcp-ai-wpoos-pro' ),
                'user_email'    => __( 'Connected Email', 'mcp-ai-wpoos-pro' ),
            ),
        ),
        'google_analytics' => array(
            'label'       => __( 'Google Analytics', 'mcp-ai-wpoos-pro' ),
            'description' => __( 'Google Analytics 4 with Service Account', 'mcp-ai-wpoos-pro' ),
            'auth_types'  => array( 'service_account' ),
            'fields'      => array(
                'property_id'          => __( 'GA4 Property ID', 'mcp-ai-wpoos-pro' ),
                'service_account_json' => __( 'Service Account JSON', 'mcp-ai-wpoos-pro' ),
            ),
        ),
        'tiktok' => array(
            'label'       => __( 'TikTok', 'mcp-ai-wpoos-pro' ),
            'description' => __( 'TikTok Open API connection', 'mcp-ai-wpoos-pro' ),
            'auth_types'  => array( 'oauth2', 'access_token' ),
            'fields'      => array(
                'access_token'  => __( 'Access Token', 'mcp-ai-wpoos-pro' ),
                'client_key'    => __( 'Client Key', 'mcp-ai-wpoos-pro' ),
                'client_secret' => __( 'Client Secret', 'mcp-ai-wpoos-pro' ),
                'open_id'       => __( 'Open ID (optional)', 'mcp-ai-wpoos-pro' ),
            ),
        ),
        'meta' => array(
            'label'       => __( 'Meta (Facebook/Instagram)', 'mcp-ai-wpoos-pro' ),
            'description' => __( 'Meta Graph API for Facebook and Instagram', 'mcp-ai-wpoos-pro' ),
            'auth_types'  => array( 'oauth2' ),
            'fields'      => array(
                'access_token'        => __( 'Access Token', 'mcp-ai-wpoos-pro' ),
                'app_id'              => __( 'App ID', 'mcp-ai-wpoos-pro' ),
                'app_secret'          => __( 'App Secret', 'mcp-ai-wpoos-pro' ),
                'business_account_id' => __( 'Business Account ID', 'mcp-ai-wpoos-pro' ),
                'page_id'             => __( 'Page ID (optional)', 'mcp-ai-wpoos-pro' ),
            ),
        ),
    );
}
```

**Time Estimate**: 4 hours

#### 1.2 Update Remote Sites Admin UI

**File**: `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`

Add UI components for:
- OAuth initiation buttons for Gmail and Meta
- Service account JSON upload for Google Analytics
- Access token paste for TikTok
- Connection testing buttons

**Time Estimate**: 6 hours

### Phase 2: Gmail Migration (Week 2)

#### 2.1 Update Gmail OAuth Handlers

**Files to modify**:
- OAuth handlers: Keep existing for backward compat
- Add connection support to handlers

**Changes**:
```php
// In wp_mcp_ai_gmail_oauth_callback
if ( isset( $_GET['connection_id'] ) ) {
    // Store tokens in connection
    $connection_id = sanitize_key( $_GET['connection_id'] );
    $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
    
    $updated_data = array_merge( $connection, array(
        'refresh_token' => WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $refresh_token ),
        'user_email'    => $user_email,
    ));
    
    WP_MCP_AI_Pro_Remote_Site_Manager::update_connection( $connection_id, $updated_data );
} else {
    // Fallback to settings
    $settings['gmail_refresh_token'] = $refresh_token;
}
```

**Time Estimate**: 4 hours

#### 2.2 Update Gmail Search Tool

**File**: `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-search-gmail.php`

Add `connection_id` parameter:
```php
public function get_parameters_schema() {
    return array(
        'type' => 'object',
        'properties' => array(
            'connection_id' => array(
                'type' => 'string',
                'description' => __( 'Optional Remote Sites connection ID...', 'domain' ),
            ),
            // ... existing parameters
        ),
    );
}
```

Add connection validation in `execute()`:
```php
$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
    $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
    
    // Validate connection
    if ( null === $connection || 'gmail' !== $connection['connection_type'] ) {
        return new WP_Error( 'invalid_connection', '...' );
    }
    
    $client_id = $connection['client_id'];
    $client_secret = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['client_secret'] );
    $refresh_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['refresh_token'] );
} else {
    // Fallback to settings
    $settings = WP_MCP_AI_Admin_Settings::get_settings();
    $client_id = $settings['gmail_client_id'];
    // ... etc
}
```

**Time Estimate**: 3 hours

#### 2.3 Testing

- Test OAuth flow with connection
- Test tool with connection_id
- Test settings fallback
- Test token refresh

**Time Estimate**: 2 hours

**Total Gmail**: 9 hours

### Phase 3: TikTok Migration (Week 2)

#### 3.1 Update TikTok Tools

**Files**:
- `class-wp-mcp-ai-pro-tool-post-tiktok-video.php`
- `class-wp-mcp-ai-pro-tool-get-tiktok-insights.php`

**Pattern**: Direct credential (similar to QuickBooks)

Add `connection_id` parameter and validation:
```php
if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
    $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
    
    // Validate
    if ( null === $connection || 'tiktok' !== $connection['connection_type'] ) {
        return new WP_Error( 'invalid_connection', '...' );
    }
    
    $access_token = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['access_token'] );
    $client_key = $connection['client_key'];
} else {
    // Fallback to settings
    $settings = WP_MCP_AI_Admin_Settings::get_settings();
    $access_token = $settings['tiktok_access_token'];
}
```

**Time Estimate**: 4 hours (2 hours per tool)

#### 3.2 Testing

- Test both tools with connection
- Test settings fallback

**Time Estimate**: 2 hours

**Total TikTok**: 6 hours

### Phase 4: Meta Migration (Week 3)

#### 4.1 Update Meta OAuth Handlers

**Similar to Gmail**: Modify OAuth handlers to support connections

**Time Estimate**: 4 hours

#### 4.2 Update Meta Tools

**Files**:
- `class-wp-mcp-ai-pro-tool-post-facebook-instagram.php`
- `class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php`

Add `connection_id` parameter and validation following the Gmail pattern.

**Time Estimate**: 6 hours (2 hours per tool)

#### 4.3 Testing

- Test OAuth flow
- Test all 3 tools
- Test settings fallback

**Time Estimate**: 3 hours

**Total Meta**: 13 hours

### Phase 5: Google Analytics Migration (Week 3-4)

**Note**: This is the most complex migration due to service account credentials.

#### 5.1 Add Service Account Support to Remote Site Manager

Need to handle:
- JSON file upload
- JSON parsing and validation
- Secure storage of private key
- JWT generation for token requests

**File**: `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`

Add methods:
```php
public static function parse_service_account_json( $json_string ) {
    $data = json_decode( $json_string, true );
    
    // Validate required fields
    if ( empty( $data['private_key'] ) || empty( $data['client_email'] ) ) {
        return new WP_Error( 'invalid_json', '...' );
    }
    
    return $data;
}

public static function generate_jwt( $connection_id ) {
    // Generate JWT for service account
    // Use Firebase JWT library or custom implementation
}
```

**Time Estimate**: 6 hours

#### 5.2 Update Google Analytics Tool

**File**: `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-google-analytics-report.php`

Current tool already has JWT logic. Extract and refactor:
```php
if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
    $connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
    
    // Validate
    if ( null === $connection || 'google_analytics' !== $connection['connection_type'] ) {
        return new WP_Error( 'invalid_connection', '...' );
    }
    
    $property_id = $connection['property_id'];
    $service_account = json_decode(
        WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $connection['service_account_json'] ),
        true
    );
} else {
    // Fallback to settings
    $settings = WP_MCP_AI_Admin_Settings::get_settings();
    $service_account = json_decode( $settings['google_analytics_credentials_json'], true );
}
```

**Time Estimate**: 4 hours

#### 5.3 Testing

- Test service account upload
- Test JWT generation
- Test API requests
- Test settings fallback

**Time Estimate**: 3 hours

**Total Google Analytics**: 13 hours

### Phase 6: Remove Old Settings (Week 4)

#### 6.1 Remove Fields from Settings

**File**: `includes/admin/sections/class-wp-mcp-ai-section-integrations.php`

Remove field definitions:
- Gmail: 2 fields
- Google Analytics: 4 fields (keep ita_tariff_api_key - unrelated)
- TikTok: 3 fields
- Meta: 6 fields

**Total**: 15 fields to remove

#### 6.2 Remove from Subtab Groups

Remove 4 groups from configuration.

#### 6.3 Remove Footer Rendering

Remove 4 switch cases for OAuth connection displays.

**Time Estimate**: 2 hours

**Total Removal**: 2 hours

### Phase 7: Documentation & Testing (Week 4)

#### 7.1 Update Documentation

- Migration guides for each integration
- Remote Sites usage documentation
- OAuth flow documentation
- Troubleshooting guide

**Time Estimate**: 4 hours

#### 7.2 Comprehensive Testing

- End-to-end testing all 7 tools
- OAuth flows (Gmail, Meta)
- Connection management UI
- Settings fallback verification
- Multi-connection scenarios

**Time Estimate**: 6 hours

**Total Documentation & Testing**: 10 hours

## Time Estimates Summary

| Phase | Integration | Complexity | Time (hours) |
|-------|-------------|------------|--------------|
| 1 | Infrastructure | - | 10 |
| 2 | Gmail | Medium | 9 |
| 3 | TikTok | Low-Medium | 6 |
| 4 | Meta | Medium-High | 13 |
| 5 | Google Analytics | High | 13 |
| 6 | Settings Removal | - | 2 |
| 7 | Docs & Testing | - | 10 |
| **TOTAL** | **4 integrations, 7 tools** | - | **63 hours** |

**Estimated Duration**: 4 weeks (assuming 16 hours/week)

## Implementation Order (Recommended)

1. **Week 1**: Infrastructure + TikTok (easier, validates infrastructure)
2. **Week 2**: Gmail (OAuth pattern validation)
3. **Week 3**: Meta (builds on Gmail OAuth pattern)
4. **Week 4**: Google Analytics (most complex) + Cleanup + Testing

## Risks & Mitigation

### Risk 1: OAuth Token Management Complexity
**Impact**: High  
**Mitigation**: 
- Reuse existing OAuth handlers
- Add connection support incrementally
- Maintain settings fallback during transition

### Risk 2: Service Account Private Key Security
**Impact**: High  
**Mitigation**:
- Use WordPress encryption functions
- Store encrypted in database
- Add security audit logging
- Consider key rotation mechanism

### Risk 3: Breaking Changes for Existing Users
**Impact**: High  
**Mitigation**:
- 100% backward compatibility maintained
- Settings still work as fallback
- Gradual migration encouraged
- Clear migration documentation

### Risk 4: OAuth Flow Interruption
**Impact**: Medium  
**Mitigation**:
- Test OAuth flows thoroughly
- Add error handling and recovery
- Provide manual token entry option
- Document OAuth setup process

### Risk 5: Multiple Accounts Per Service
**Impact**: Low  
**Mitigation**:
- Already handled by Remote Sites design
- Per-assistant connection assignment
- Connection validation in tools

## Success Criteria

### Must Have
- ✅ All 7 tools support `connection_id` parameter
- ✅ All connection types added to Remote Site Manager
- ✅ OAuth flows work with connections (Gmail, Meta)
- ✅ Service account credentials work (Google Analytics)
- ✅ 100% backward compatibility with settings
- ✅ Zero syntax errors
- ✅ Zero breaking changes

### Should Have
- ✅ Old settings removed from UI
- ✅ OAuth connection testing UI
- ✅ Migration documentation
- ✅ Connection health indicators
- ✅ Token refresh automation

### Nice to Have
- Settings-to-connection migration tool
- OAuth token expiration warnings
- Connection usage analytics
- Bulk connection import/export

## Benefits After Migration

### For Users
- **Multiple accounts**: Support multiple Gmail accounts, GA properties, TikTok accounts, Meta pages
- **Per-assistant control**: Assign specific connections to specific assistants
- **Better organization**: All OAuth connections in one place
- **Enhanced security**: Encrypted tokens at rest
- **Easier setup**: Connection templates and testing

### For System
- **Consistent pattern**: All external services use Remote Sites
- **Better maintainability**: Centralized credential management
- **Audit trail**: Connection usage tracking
- **Scalability**: Supports multi-tenant scenarios
- **Future-proof**: Easy to add new OAuth services

## Post-Migration Tasks

1. **Monitor adoption** - Track Remote Sites connection creation
2. **Deprecation notices** - Add warnings to settings-based usage
3. **Migration assistance** - Provide support for users migrating
4. **Settings cleanup** - In future major version, remove settings entirely
5. **OAuth improvements** - Add automatic token refresh, health checks
6. **Documentation updates** - Update all guides to reference Remote Sites

## Conclusion

This migration will complete the transformation of all external service integrations to use the Remote Sites connection management system. After completion:

**Total Migration Stats**:
- **8 integrations** migrated (4 existing + 4 OAuth)
- **19 tools** migrated (12 existing + 7 OAuth)
- **2 clients** updated (Flowhub + potentially Meta)
- **100% backward compatibility** maintained
- **~126 hours** total effort across both phases

The OAuth migrations are more complex due to token management and service account credentials, but the patterns established with PayHere, iSAMS, Flowhub, and QuickBooks provide a solid foundation for this next phase.
