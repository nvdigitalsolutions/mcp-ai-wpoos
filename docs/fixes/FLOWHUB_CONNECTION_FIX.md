# Flowhub Connection Validation Fix

## Issue
Users were unable to save new Flowhub connections even when all required fields were filled. The validation error appeared:
> "API key (key header) and client ID (clientId header) are required for Flowhub connections."

## Root Cause
Multiple connection types (iSAMS, Flowhub, QuickBooks, EZuite ERP) used the same HTML form field names:
- `api_key` - used by iSAMS, Flowhub, and EZuite ERP
- `client_id` - used by Flowhub and QuickBooks  
- `api_secret` - used by iSAMS and EZuite ERP
- `client_secret` - used by QuickBooks

When the form was submitted, **all** fields were sent to the server, even hidden ones. The browser followed standard HTML behavior where the **last field with the same name wins**, causing empty values from hidden fields to override filled values from visible fields.

### Example Flow
1. User selects "Flowhub" as connection type
2. User fills in `flowhub_api_key` field with "my_api_key_123"
3. Form shows Flowhub fields; QuickBooks and EZuite fields are hidden with CSS (`display: none`)
4. On submit, browser sends:
   - `api_key=my_api_key_123` (from Flowhub field)
   - `api_key=` (empty, from hidden EZuite field - **this wins because it's last**)
5. Backend receives empty `api_key` → validation fails

## Solution
Implemented **unique field names** for each connection type to prevent naming conflicts:

### Field Name Mapping
| Connection Type | Original Name | New Unique Name |
|----------------|---------------|-----------------|
| iSAMS | `api_key` | `isams_api_key` |
| iSAMS | `api_secret` | `isams_api_secret` |
| Flowhub | `api_key` | `flowhub_api_key` |
| Flowhub | `client_id` | `flowhub_client_id` |
| QuickBooks | `client_id` | `quickbooks_client_id` |
| QuickBooks | `client_secret` | `quickbooks_client_secret` |
| EZuite ERP | `api_key` | `ezuite_erp_api_key` |
| EZuite ERP | `api_secret` | `ezuite_erp_api_secret` |

### Backend Logic
Added a switch statement in `WP_MCP_AI_Pro_Remote_Sites_Admin::handle_actions()` (lines 134-159) to map connection-type-specific field names to generic field names based on the selected `connection_type`:

```php
switch ( $connection_type ) {
    case 'isams':
        $api_key     = isset( $_POST['isams_api_key'] ) ? wp_unslash( $_POST['isams_api_key'] ) : '';
        $api_secret  = isset( $_POST['isams_api_secret'] ) ? wp_unslash( $_POST['isams_api_secret'] ) : '';
        break;
    case 'flowhub':
        $api_key     = isset( $_POST['flowhub_api_key'] ) ? wp_unslash( $_POST['flowhub_api_key'] ) : '';
        $client_id   = isset( $_POST['flowhub_client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['flowhub_client_id'] ) ) : '';
        break;
    // ... other cases
}
```

## Files Modified
1. `addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php`
   - Updated form field names and IDs (lines 653, 689, 724, 758, 759, 771)
   - Added switch statement for field mapping (lines 134-159)

2. `addons/pro/tests/test-remote-sites-admin.php`
   - Fixed incorrect Flowhub validation test
   - Added test for successful Flowhub connection creation
   - Added comprehensive test for unique field names preventing conflicts

## Testing
Run the test suite to verify the fix:
```bash
vendor/bin/phpunit addons/pro/tests/test-remote-sites-admin.php::test_unique_field_names_prevent_conflicts
```

## Future Considerations
- This pattern should be followed for any new connection types that share field names with existing types
- Consider refactoring to use a more structured approach (e.g., namespaced field arrays) for better maintainability
- The existing security pattern for API key/secret handling (no sanitization, encryption before storage) is maintained

## Related Files
- Backend validation: `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` (lines 719-726)
- Connection storage: `addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php` (lines 84-264)
- Flowhub client: `includes/class-wp-mcp-ai-flowhub-client.php`
