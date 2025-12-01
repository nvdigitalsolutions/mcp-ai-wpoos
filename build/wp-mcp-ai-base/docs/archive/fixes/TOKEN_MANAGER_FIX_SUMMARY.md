# Token Manager Per Site Sub-Tab Error - Resolution

## Issue Summary

When accessing the "Per Site" sub-tab in the Token Manager section of the WP oOS settings dashboard, JavaScript console errors appeared:

```
POST https://bots.nvdigital.solutions/wp-admin/admin-ajax.php 400 (Bad Request)
```

The error was triggered by AJAX calls attempting to fetch provider and model distribution data for the charts displayed on the Per Site tab.

## Root Cause Analysis

### What We Found

1. **Charts on Per Site Tab**: The Per Site view (`render_per_site_view()`) displays two charts:
   - Provider Distribution Chart (`wp-mcp-ai-provider-distribution-chart`)
   - Model Distribution Chart (`wp-mcp-ai-model-distribution-chart`)

2. **JavaScript Initialization**: The `token-manager-charts.js` file initializes these charts and makes AJAX calls to:
   - `wp_mcp_ai_get_provider_distribution`
   - `wp_mcp_ai_get_model_distribution`

3. **Handler Implementation**: The AJAX handlers were properly implemented in `WP_MCP_AI_Admin_AJAX_Handlers` class:
   - `handle_get_provider_distribution()`
   - `handle_get_model_distribution()`

4. **Missing Registration**: However, these handlers were NOT registered with WordPress AJAX hooks in the Settings Dashboard constructor.

### Code Flow

```
User visits Per Site tab
    ↓
Chart canvases render
    ↓
JavaScript (token-manager-charts.js) initializes
    ↓
AJAX calls made to:
  - wp_mcp_ai_get_provider_distribution
  - wp_mcp_ai_get_model_distribution
    ↓
WordPress checks for registered action
    ↓
❌ ACTION NOT FOUND → 400 Bad Request
```

## Solution

### Changes Made

**File**: `includes/admin/class-wp-mcp-ai-settings-dashboard.php`

Added two missing AJAX action registrations in the constructor (lines 64-65):

```php
add_action( 'wp_ajax_wp_mcp_ai_get_provider_distribution', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
add_action( 'wp_ajax_wp_mcp_ai_get_model_distribution', array( $this->ajax_handlers, 'safe_ajax_handler' ) );
```

These lines were added after the existing chart-related AJAX actions and before `wp_ajax_wp_mcp_ai_update_chart_period`.

### Test Coverage

Created `tests/test-chart-ajax-endpoints.php` with the following test cases:

1. **test_provider_distribution_ajax_action_registered()** - Verifies the provider distribution endpoint responds successfully
2. **test_model_distribution_ajax_action_registered()** - Verifies the model distribution endpoint responds successfully
3. **test_provider_distribution_invalid_nonce_fails()** - Ensures security by checking nonce validation
4. **test_model_distribution_invalid_nonce_fails()** - Ensures security by checking nonce validation

## Technical Details

### AJAX Handler Flow

The fix completes the AJAX handler registration flow:

```
WordPress AJAX Hook Registration
(class-wp-mcp-ai-settings-dashboard.php)
    ↓
add_action('wp_ajax_wp_mcp_ai_get_provider_distribution', ...)
    ↓
Calls: safe_ajax_handler()
(class-wp-mcp-ai-admin-ajax-handlers.php)
    ↓
Maps action to: handle_get_provider_distribution()
    ↓
Calls: WP_MCP_AI_Chart_JS_Helper::get_provider_distribution_data()
    ↓
Returns formatted chart data
```

### Security

Both endpoints maintain proper security:
- Nonce verification: `check_ajax_referer('wp_mcp_ai_token_charts', 'nonce', false)`
- Capability check: `current_user_can('manage_options')`
- Safe AJAX wrapper handles exceptions gracefully

### Data Format

The endpoints return Chart.js compatible data:

```json
{
  "success": true,
  "data": {
    "labels": ["OpenAI", "Google", "Anthropic"],
    "values": [5000, 8000, 3000],
    "colors": ["rgba(54, 162, 235, 0.8)", "..."],
    "datasets": [{
      "data": [5000, 8000, 3000],
      "backgroundColor": ["rgba(54, 162, 235, 0.8)", "..."],
      "borderWidth": 1
    }]
  }
}
```

## Impact

### Before Fix
- ❌ Per Site tab shows chart canvases but no data
- ❌ JavaScript console shows 400 errors
- ❌ User experience degraded on Per Site view

### After Fix
- ✅ Charts properly populate with provider/model distribution data
- ✅ No JavaScript errors
- ✅ Full functionality restored to Per Site tab

## Regression Prevention

1. **Test Coverage**: New tests verify endpoints are registered
2. **Code Pattern**: Follow existing pattern for future chart endpoints
3. **Documentation**: This document serves as reference for similar issues

## Related Files

- `includes/admin/class-wp-mcp-ai-settings-dashboard.php` - AJAX action registration
- `includes/admin/class-wp-mcp-ai-admin-ajax-handlers.php` - Handler implementation
- `includes/admin/class-wp-mcp-ai-chart-js-helper.php` - Data retrieval
- `includes/admin/sections/class-wp-mcp-ai-section-token-manager.php` - UI rendering
- `assets/js/token-manager-charts.js` - Chart initialization and AJAX calls
- `tests/test-chart-ajax-endpoints.php` - Endpoint registration tests
- `tests/test-chart-today-option.php` - Data method tests

## Verification Steps

To verify the fix works:

1. Navigate to WP oOS Settings → Token Manager
2. Click on "Per Site" tab
3. Observe that Provider Distribution and Model Distribution charts load without errors
4. Check browser console - should show no 400 errors
5. Charts should display data if token usage exists

## Minimal Change Approach

This fix follows the principle of minimal changes:
- **Only 2 lines of code added** to register the missing AJAX actions
- **No modifications to existing code** - just filling in the gap
- **Follows existing patterns** - same style as other AJAX registrations
- **No breaking changes** - pure addition, no removals or modifications
