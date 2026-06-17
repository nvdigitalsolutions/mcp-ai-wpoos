# Model Manager AJAX Handler Fix - Verification Guide

## Issue Summary
The Model Manager "Discover Models" and "Research Model" features were failing with:
- Error message: "Failed to research model. Please try again."
- Browser console error: `Failed to load resource: the server responded with a status of 400 ()` from `admin-ajax.php`

## Root Cause
AJAX handlers were only being loaded when the Model Manager view was rendered, but AJAX requests arrive before page rendering, causing WordPress to return 400 errors for unregistered AJAX actions.

## Fix Applied
Moved AJAX handler file loading from conditional render to early plugin initialization:

1. **mcp-ai-wpoos.php (line 702)**: Added `require_once` for Model Manager AJAX handler
2. **class-wp-mcp-ai-section-token-manager.php (line 1177)**: Removed conditional loading
3. **test-ajax-handlers-registered.php**: Added tests for the three Model Manager AJAX actions

## How to Verify the Fix

### Prerequisites
1. WordPress admin access with `manage_options` capability
2. At least one AI provider configured (OpenAI, Gemini, or Anthropic)
3. Browser developer tools open to view console

### Test Case 1: Discover Models
1. Navigate to **Settings → NV oOS → Token Manager tab**
2. Click **Model Manager** sub-tab
3. Click the **"Discover Models"** button
4. **Expected Result**: 
   - Spinner shows while discovering
   - No 400 errors in browser console
   - Results show either:
     - "Newly Discovered Models" section with models not in config
     - "Already Configured" section with existing models
     - "No new models discovered" message if all models are configured

### Test Case 2: Research Model
1. Still in Model Manager view
2. Enter a model ID (e.g., `gpt-4.5-turbo`)
3. Select a provider from dropdown (e.g., `OpenAI`)
4. Click the **"Research Model"** button
5. **Expected Result**:
   - Spinner shows while researching
   - No 400 errors in browser console
   - Results show:
     - Model name and specifications
     - Context window, cost per 1K, status
     - "Add to Configuration" button

### Test Case 3: Add Model Configuration
1. After successfully researching a model
2. Click the **"Add to Configuration"** button
3. **Expected Result**:
   - Button changes to "Added!" and becomes secondary style
   - Success notice appears
   - Page reloads after 2 seconds
   - New model appears in configured models list

### Browser Console Checks
During all tests, verify in browser console:
- ✅ No 400 errors from `admin-ajax.php`
- ✅ AJAX requests return 200 status
- ✅ Response data contains `success: true` (visible in Network tab)

### Known Limitations
These features require:
- **Active AI Provider**: At least one provider (OpenAI, Gemini, or Anthropic) must be configured with valid API key
- **Internet Connection**: Tools make external API calls
- **API Credits**: Uses AI tokens to perform research
- **Admin Privileges**: Only users with `manage_options` capability can access

## Technical Verification

### Check AJAX Actions Are Registered
Run in WordPress admin or via WP-CLI:
```php
// Check if actions are registered
$actions = array(
    'wp_ajax_wp_mcp_ai_discover_models',
    'wp_ajax_wp_mcp_ai_research_model',
    'wp_ajax_wp_mcp_ai_add_model_config',
);

foreach ( $actions as $action ) {
    echo $action . ': ' . ( has_action( $action ) ? 'Registered' : 'NOT REGISTERED' ) . "\n";
}
```

Expected output:
```
wp_ajax_wp_mcp_ai_discover_models: Registered
wp_ajax_wp_mcp_ai_research_model: Registered
wp_ajax_wp_mcp_ai_add_model_config: Registered
```

### Check Tools Are Available
Run in WordPress admin or via WP-CLI:
```php
$registry = WP_MCP_AI_Tool_Registry::get_instance();
$tools = array( 'discover_new_models', 'research_model', 'add_model_config' );

foreach ( $tools as $tool_slug ) {
    $tool = $registry->get_tool( $tool_slug );
    echo $tool_slug . ': ' . ( $tool ? 'Available' : 'NOT AVAILABLE' ) . "\n";
}
```

Expected output:
```
discover_new_models: Available
research_model: Available
add_model_config: Available
```

## Error Scenarios to Test

### Scenario 1: No Provider Configured
1. Remove all provider API keys from settings
2. Try to discover models
3. **Expected**: Error message "No AI provider configured"

### Scenario 2: Invalid Model ID
1. Enter a non-existent model ID (e.g., `fake-model-9000`)
2. Select a provider
3. Click Research Model
4. **Expected**: Error from research tool indicating model not found

### Scenario 3: Missing Permissions
1. Log in as non-admin user (e.g., Editor role)
2. Try to access Model Manager
3. **Expected**: View should not be accessible, or AJAX returns permission denied

## Files Modified
- `mcp-ai-wpoos.php` - Added AJAX handler loading
- `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php` - Removed conditional loading
- `tests/test-ajax-handlers-registered.php` - Added test cases

## Rollback Instructions
If issues occur, revert with:
```bash
git revert 240b1a4
git revert d639a01
```

Or manually remove line 702 from `mcp-ai-wpoos.php` and restore conditional loading in token manager section.

## Success Criteria
- [x] AJAX handlers are registered on plugin load
- [x] No 400 errors when clicking Model Manager buttons
- [x] Discover Models returns results
- [x] Research Model returns specifications
- [x] Add to Configuration saves model to config
- [x] Tests added for AJAX handler registration

## Additional Notes
- The fix ensures AJAX handlers are loaded during `admin_init` hook
- Security is maintained via nonce verification and capability checks
- Tools are already registered in Tool Registry (no changes needed there)
- This is a minimal fix with no breaking changes
