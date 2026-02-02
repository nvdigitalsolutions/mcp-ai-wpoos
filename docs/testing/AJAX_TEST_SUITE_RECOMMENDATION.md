# AJAX Test Suite Recommendation for Federation Mesh Subtab

**Date:** 2026-02-01  
**Issue:** Federation mesh checkboxes cannot be unchecked  
**Question:** Does it make sense to add an AJAX test suite for this subtab?

## TL;DR

**NO - An AJAX test suite is NOT appropriate** because the settings save mechanism uses WordPress `admin_post` (standard POST), not AJAX.

## Analysis

### How Settings Are Saved

The federation mesh subtab settings are saved via:

1. **Standard HTML Form POST** (not AJAX)
   ```html
   <form method="POST" action="<?php echo admin_url('admin-post.php'); ?>">
       <input type="hidden" name="action" value="wp_mcp_ai_save_settings" />
       <input type="hidden" name="subtab_advanced" value="federation_mesh" />
       <!-- ... other fields ... -->
   </form>
   ```

2. **WordPress admin_post Action**
   ```php
   add_action('admin_post_wp_mcp_ai_save_settings', array($this, 'handle_save_settings'));
   ```

3. **Page Redirect After Save**
   - After processing, redirects to settings page with `?updated=true`
   - No AJAX involved in the process

### JavaScript Role

The JavaScript in `settings-dashboard.js` only:
- Updates the subtab hidden field before submission
- Logs checkbox states for debugging
- Adds loading state to submit button
- **Does NOT use AJAX** for the save operation

### Existing AJAX Operations

The Settings Dashboard DOES use AJAX for:
- ✅ Connection testing (Ollama, Cloudways, etc.)
- ✅ Data fetching (chart data, model lists)
- ✅ Quick operations (toggle tools, reset usage)
- ❌ **NOT for main settings save**

### Existing Test Coverage

**AJAX Tests:**
- 16 test files using `WP_Ajax_UnitTestCase`
- Cover all AJAX operations in the dashboard
- Follow established pattern for testing AJAX endpoints

**Unit Tests:**
- `test-federation-directory-checkbox.php` - Tests checkbox persistence
- `test-provider-subtab-save-bug.php` - Similar subtab issues
- Direct testing of sanitization logic

## Recommendation

### Don't Add AJAX Tests

**Why:**
1. Settings save is not AJAX-based
2. AJAX tests would not catch the actual bug
3. Would create confusion about the save mechanism
4. Existing unit tests are more appropriate

### What to Do Instead

**1. Enhance Existing Unit Tests**

Add comprehensive tests to `test-federation-directory-checkbox.php`:

```php
public function test_all_three_federation_checkboxes_can_be_unchecked() {
    // Set all three to true initially
    $initial = array(
        'enable_mesh' => true,
        'enable_federation' => true,
        'enable_federation_directory' => true,
    );
    update_option(WP_MCP_AI_Admin_Settings::OPTION_NAME, $initial);
    
    // Uncheck all three
    $_POST['subtab_advanced'] = 'federation_mesh';
    $_POST['wp_mcp_ai_settings'] = array(
        // All three checkboxes unchecked (not in POST)
    );
    
    $section = new WP_MCP_AI_Section_Advanced();
    $sanitized = $section->sanitize($_POST['wp_mcp_ai_settings']);
    
    // All should be false
    $this->assertFalse($sanitized['enable_mesh']);
    $this->assertFalse($sanitized['enable_federation']);
    $this->assertFalse($sanitized['enable_federation_directory']);
}
```

**2. Add Integration Tests**

Create `test-admin-post-settings-save.php`:

```php
class Test_Admin_Post_Settings_Save extends WP_UnitTestCase {
    public function test_full_admin_post_flow() {
        // Simulate complete admin_post request
        $_POST = array(
            'action' => 'wp_mcp_ai_save_settings',
            'subtab_advanced' => 'federation_mesh',
            'active_tab' => 'advanced',
            'wp_mcp_ai_settings' => array(/* ... */),
            '_wpnonce' => wp_create_nonce('wp_mcp_ai_save_settings')
        );
        
        // Trigger the admin_post action
        do_action('admin_post_wp_mcp_ai_save_settings');
        
        // Verify database was updated correctly
        $saved = get_option(WP_MCP_AI_Admin_Settings::OPTION_NAME);
        $this->assertFalse($saved['enable_mesh']);
    }
}
```

**3. Add JavaScript Tests (Optional)**

If the bug is in form manipulation:

```javascript
// tests/js/federation-form-submission.test.js
describe('Federation Mesh Form Submission', () => {
    test('subtab hidden field is set correctly', () => {
        // Test that JavaScript updates subtab_advanced field
        const form = document.querySelector('form');
        const subtabField = form.querySelector('[name="subtab_advanced"]');
        expect(subtabField.value).toBe('federation_mesh');
    });
    
    test('unchecked boxes are not in form data', () => {
        // Test that unchecked checkboxes don't submit values
        const formData = new FormData(form);
        expect(formData.has('wp_mcp_ai_settings[enable_mesh]')).toBe(false);
    });
});
```

## Test Strategy Going Forward

### For This Bug

1. **First:** Enable logging and analyze actual behavior
2. **Then:** Write failing test that reproduces the bug
3. **Fix:** Implement minimal fix
4. **Verify:** Test passes and manual testing confirms

### For Similar Features

**Use AJAX Tests When:**
- Feature uses `wp_ajax_*` action hooks
- JavaScript calls `$.ajax()` or `fetch()`
- Response is JSON returned via `wp_send_json_*`

**Use Unit/Integration Tests When:**
- Feature uses `admin_post` action hooks
- Standard form POST submission
- Page redirect after processing

## Examples of Appropriate AJAX Tests

Good use cases for AJAX tests in this codebase:

1. **Connection Testing**
   ```php
   // tests/test-ollama-ajax-handlers.php
   public function test_ollama_connection() {
       $_POST['action'] = 'wp_mcp_ai_test_ollama_connection';
       $this->_handleAjax('wp_mcp_ai_test_ollama_connection');
       $response = json_decode($this->_last_response, true);
       $this->assertTrue($response['success']);
   }
   ```

2. **Chart Data Fetching**
   ```php
   // tests/test-chart-ajax-handlers.php
   public function test_get_usage_trend() {
       $_POST['action'] = 'wp_mcp_ai_get_usage_trend';
       $this->_handleAjax('wp_mcp_ai_get_usage_trend');
       $response = json_decode($this->_last_response, true);
       $this->assertArrayHasKey('labels', $response['data']);
   }
   ```

## Conclusion

**The federation mesh checkbox bug should be tested with:**
- ✅ Enhanced unit tests
- ✅ Integration tests (admin_post)
- ✅ Manual testing with logging enabled
- ❌ NOT AJAX tests (not applicable)

**When to revisit this decision:**
- If settings save is changed to use AJAX (unlikely)
- If specific AJAX operations are added to federation mesh
- If the bug is found to be JavaScript-related (would need JS tests, not AJAX tests)

## Related Files

- `includes/admin/class-wp-mcp-ai-settings-dashboard.php` - admin_post handler
- `assets/js/settings-dashboard.js` - Form manipulation
- `tests/test-federation-directory-checkbox.php` - Existing tests
- `tests/test-chart-ajax-handlers.php` - Example AJAX test pattern
