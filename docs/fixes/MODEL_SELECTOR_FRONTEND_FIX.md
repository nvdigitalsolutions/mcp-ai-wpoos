# Model Selector Frontend Fix

**Date:** December 28, 2025  
**Issue:** Filtered AI models not showing in Elementor widget dropdown  
**Status:** ✅ Fixed

## Problem Description

The model dropdown in Elementor widgets was showing empty (only displaying "— Select Model —" placeholder) when users selected a provider. This occurred because:

1. The `admin-model-selector.js` script was only enqueued in admin/backend contexts
2. The AJAX handler `wp_mcp_ai_get_models_for_provider` only had `wp_ajax_` hook (logged-in admin)
3. The Elementor widget didn't declare the model selector script as a dependency
4. Frontend users couldn't access the model fetching endpoint

## Root Cause

The model selector functionality was designed for backend use only (admin editing screens). When the feature was extended to frontend widgets, the necessary frontend initialization was not implemented.

### Original Architecture

```
Backend (Admin):
├─ Script: admin-model-selector.js (✅ enqueued)
├─ Handler: wp_ajax_wp_mcp_ai_get_models_for_provider (✅ registered)
└─ Capability: edit_posts (admin only)

Frontend (Elementor Widget):
├─ Script: admin-model-selector.js (❌ not enqueued)
├─ Handler: wp_ajax_nopriv_* (❌ not registered)
└─ Capability: none (❌ no access)
```

## Solution Implemented

### 1. Frontend Script Registration

**File:** `includes/class-wp-mcp-ai-shortcode.php`

Registered the model selector script for frontend use:

```php
// Register model selector script for frontend use (for provider/model selectors in widgets).
$model_selector_relative = 'assets/js/admin-model-selector.js';
$model_selector_path     = WP_MCP_AI_URL . $model_selector_relative;
$model_selector_version  = $this->get_asset_version( $model_selector_relative );

wp_register_script(
    'wp-mcp-ai-model-selector',
    $model_selector_path,
    array( 'jquery' ),
    $model_selector_version,
    true
);

// Localize model selector for frontend AJAX requests.
wp_localize_script(
    'wp-mcp-ai-model-selector',
    'wpMcpAiModelSelector',
    array(
        'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
        'nonce'           => wp_create_nonce( 'wp-mcp-ai-model-selector' ),
        'selectModelText' => __( '— Select Model —', 'wp-mcp-ai' ),
        'errorMessage'    => __( 'Failed to load models. Please try again.', 'wp-mcp-ai' ),
    )
);
```

### 2. Frontend AJAX Handler Registration

**File:** `includes/admin/class-wp-mcp-ai-settings-dashboard.php`

Added nopriv handler for logged-in frontend users:

```php
add_action( 'wp_ajax_wp_mcp_ai_get_models_for_provider', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
// Allow logged-in frontend users to fetch models for provider/model selectors in widgets/shortcodes.
add_action( 'wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
```

### 3. Permission Adjustment

**File:** `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php`

Relaxed capability from `edit_posts` to `read`:

```php
// Allow access for logged-in users who can read (for frontend widgets/shortcodes).
// This allows the model selector to work in Elementor widgets and frontend shortcodes.
if ( ! is_user_logged_in() || ! current_user_can( 'read' ) ) {
    wp_send_json_error(
        array(
            'message' => __( 'You must be logged in to access this feature.', 'wp-mcp-ai' ),
        )
    );
    return;
}
```

### 4. Elementor Widget Dependency

**File:** `includes/elementor/class-wp-mcp-ai-elementor-widget.php`

Added model selector as script dependency:

```php
public function get_script_depends() {
    return array( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'wp-mcp-ai-model-selector' );
}
```

## New Architecture

```
Backend (Admin):
├─ Script: admin-model-selector.js (✅ enqueued)
├─ Handler: wp_ajax_wp_mcp_ai_get_models_for_provider (✅ registered)
└─ Capability: read (admin access)

Frontend (Elementor Widget):
├─ Script: admin-model-selector.js (✅ enqueued via dependency)
├─ Handler: wp_ajax_nopriv_wp_mcp_ai_get_models_for_provider (✅ registered)
└─ Capability: read (logged-in users)
```

## How It Works

### User Flow

1. **Page Load**: User visits page with Elementor widget containing provider/model selector
2. **Script Loading**: 
   - `wp-mcp-ai-model-selector` script loads
   - Localized with AJAX URL and nonce
3. **User Interaction**: User selects provider (e.g., "OpenAI")
4. **AJAX Request**:
   ```javascript
   $.ajax({
       url: wpMcpAiModelSelector.ajaxUrl,
       type: 'POST',
       data: {
           action: 'wp_mcp_ai_get_models_for_provider',
           nonce: wpMcpAiModelSelector.nonce,
           provider: 'openai'
       }
   })
   ```
5. **Server Processing**:
   - Verify nonce
   - Check user is logged in
   - Fetch models for provider
   - Return JSON response
6. **UI Update**: Dropdown populates with filtered models

### Security Flow

```
Request → Nonce Verification → Login Check → Capability Check → Model Fetch → Response
                ↓                    ↓               ↓              ↓
              Valid?            Logged in?      Has 'read'?    Available?
                ↓                    ↓               ↓              ↓
              Yes                  Yes             Yes            Yes
                ↓                    ↓               ↓              ↓
              Continue            Continue        Continue       Success
```

## Security Considerations

### Unchanged Security Measures
- ✅ Nonce verification (`check_ajax_referer`)
- ✅ Input sanitization (`sanitize_key`)
- ✅ Output escaping (JSON response)
- ✅ No sensitive data exposed (only model lists)

### Changed Security Measures
- **Before**: Required `edit_posts` capability (admin only)
- **After**: Required `read` capability (logged-in users)
- **Rationale**: Frontend users need to select models when configuring assistants

### Why This Is Safe

1. **Read-Only Operation**: Only fetches model lists, doesn't modify anything
2. **Public Information**: Model names are not sensitive data
3. **User Authentication**: Still requires login (no anonymous access)
4. **Nonce Protection**: Prevents CSRF attacks
5. **No Privilege Escalation**: Users can't access admin-only features

## Testing

### Manual Testing Steps

1. **Create Test Page**:
   - Create new page in WordPress
   - Add Elementor widget with provider/model selector
   - Publish page

2. **Test as Logged-In User**:
   - Log in as subscriber/contributor/editor
   - Visit page with widget
   - Select provider from dropdown
   - Verify models populate in model dropdown
   - Try different providers (OpenAI, Gemini, etc.)

3. **Test as Guest**:
   - Log out
   - Visit same page
   - Verify error message if selector is used
   - (Or verify selector is hidden for guests)

4. **Test Error Cases**:
   - Invalid provider name → error message
   - No API key configured → appropriate message
   - Network error → graceful fallback

### Automated Testing

```bash
# Run AJAX handler registration tests
vendor/bin/phpunit tests/test-ajax-handlers-registered.php

# Expected: All tests pass including new nopriv handler
```

### Browser Console Testing

```javascript
// In browser console on page with widget
jQuery.ajax({
    url: wpMcpAiModelSelector.ajaxUrl,
    type: 'POST',
    data: {
        action: 'wp_mcp_ai_get_models_for_provider',
        nonce: wpMcpAiModelSelector.nonce,
        provider: 'openai'
    },
    success: function(response) {
        console.log('Success:', response);
        // Expected: { success: true, data: { models: {...} } }
    },
    error: function(xhr, status, error) {
        console.error('Error:', error);
    }
});
```

## Files Changed

1. `includes/class-wp-mcp-ai-shortcode.php` - Register script for frontend
2. `includes/admin/class-wp-mcp-ai-settings-dashboard.php` - Add nopriv handler
3. `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` - Relax permission
4. `includes/elementor/class-wp-mcp-ai-elementor-widget.php` - Add dependency
5. `tests/test-ajax-handlers-registered.php` - Update test expectations

## Rollback Plan

If issues arise, revert with:

```bash
git revert b06b620  # Revert test update
git revert 893fdcc  # Revert main changes
```

## Future Enhancements

1. **Guest Access**: Consider allowing guest access with rate limiting
2. **Caching**: Cache model lists to reduce API calls
3. **Error Messages**: More specific error messages for troubleshooting
4. **UI Feedback**: Loading indicators during model fetch
5. **Model Filtering**: Filter models by capability (vision, audio, etc.)

## Related Documentation

- `docs/tool-reference.md` - Tool capabilities and permissions
- `docs/rest-api.md` - REST API authentication
- `assets/js/admin-model-selector.js` - Model selector JavaScript
- `includes/services/class-wp-mcp-ai-model-service.php` - Model service

## References

- Issue: "filtered ai models based on providers is not showing in the dropdown lost of the elementor widget"
- HTML Element: `<select id="wp-mcp-ai-prof-selector-1-model" ...>`
- Branch: `copilot/fix-ai-model-dropdown-issue`
- Commits: `893fdcc`, `b06b620`
