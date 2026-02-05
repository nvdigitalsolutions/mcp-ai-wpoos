# Fix: Nonce Conflict Causing "Link Expired" Error

## Problem
All settings pages were unable to save changes, showing the error message: "The link you followed has expired."

## Root Cause
The settings form was using **both** approaches for WordPress form handling:

1. **Settings API approach** (for `options.php`):
   - Used `settings_fields( 'wp_mcp_ai_settings_group' )`
   - Creates nonce with action: `'wp_mcp_ai_settings_group-options'`
   - Designed for forms that POST to `wp-admin/options.php`

2. **Admin Post API approach** (for `admin-post.php`):
   - Used `wp_nonce_field( 'wp_mcp_ai_save_settings' )`
   - Form POSTs to `wp-admin/admin-post.php`
   - Handler checks nonce with: `check_admin_referer( 'wp_mcp_ai_save_settings' )`

The conflict occurred because:
- The form created TWO nonces with DIFFERENT action names
- The handler expected the 'wp_mcp_ai_save_settings' nonce
- But `settings_fields()` was also creating its own nonce
- This caused nonce validation to fail

## Solution
Removed the `settings_fields()` call from line 1077 of `includes/admin/class-wp-mcp-ai-settings-dashboard.php`.

### Before (Broken):
```php
<form id="wp-mcp-ai-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'wp_mcp_ai_save_settings' ); ?>
    <?php settings_fields( 'wp_mcp_ai_settings_group' ); ?> <!-- REMOVED -->
    <input type="hidden" name="action" value="wp_mcp_ai_save_settings" />
    ...
</form>
```

### After (Fixed):
```php
<form id="wp-mcp-ai-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'wp_mcp_ai_save_settings' ); ?>
    <input type="hidden" name="action" value="wp_mcp_ai_save_settings" />
    ...
</form>
```

## Technical Details

### What `settings_fields()` does:
Creates these hidden fields:
1. `<input type="hidden" name="option_page" value="wp_mcp_ai_settings_group" />`
2. `<input type="hidden" name="action" value="update" />` (conflicts with our action!)
3. `<input type="hidden" name="_wpnonce" value="[nonce for 'wp_mcp_ai_settings_group-options']" />` (wrong nonce!)
4. `<input type="hidden" name="_wp_http_referer" value="[current page]" />`

### Why this matters:
- **Settings API** (`options.php`): WordPress's built-in way to save settings
  - Uses `register_setting()`, `add_settings_section()`, `add_settings_field()`
  - Form submits to `wp-admin/options.php`
  - Nonce checked by WordPress core

- **Admin Post API** (`admin-post.php`): Custom form handler
  - Uses `add_action( 'admin_post_{action}', 'callback' )`
  - Form submits to `wp-admin/admin-post.php`
  - You manually check nonce with `check_admin_referer()`

**You can't mix both approaches in the same form!**

## Files Changed
1. `includes/admin/class-wp-mcp-ai-settings-dashboard.php` (line 1077) - Removed `settings_fields()` call
2. `includes/admin/settings-dashboard-init.php` - Added debug logging

## Testing
After this fix, all settings tabs should save properly:
- ✅ Pro Features tab
- ✅ Providers tab
- ✅ Tools tab
- ✅ Integrations tab
- ✅ All other tabs

## References
- [WordPress Settings API](https://developer.wordpress.org/plugins/settings/settings-api/)
- [WordPress Admin Post API](https://developer.wordpress.org/reference/functions/wp_nonce_field/)
- [check_admin_referer()](https://developer.wordpress.org/reference/functions/check_admin_referer/)
