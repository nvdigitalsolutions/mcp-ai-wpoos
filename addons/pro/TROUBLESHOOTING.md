# Open Operator System (NV oOS) - Pro Add-on Troubleshooting

This document provides troubleshooting guidance for common issues with the Pro Add-on when installed in different configurations.

## Table of Contents

1. [Installation Configurations](#installation-configurations)
2. [JavaScript/CSS Not Loading](#javascriptcss-not-loading)
3. [NPM Package Issues](#npm-package-issues)
4. [Admin Page Issues](#admin-page-issues)

## Installation Configurations

The Pro Add-on supports two installation configurations:

### Configuration 1: Cloned Repository (Bundled)
- Base plugin cloned from repository with `addons/pro/` directory included
- Pro addon automatically loaded by base plugin
- **Path**: `wp-content/plugins/mcp-ai-wpoos/addons/pro/`
- **URL**: `{base-plugin-url}/addons/pro/`

### Configuration 2: Base + Pro (Separate Plugins)
- Base plugin installed from WordPress.org or distribution package
- Pro addon installed as separate plugin
- **Path**: `wp-content/plugins/mcp-ai-wpoos-pro/`
- **URL**: `{pro-plugin-url}/`

## JavaScript/CSS Not Loading

### Symptoms
- Password Generator & Authenticator page displays HTML but buttons don't work
- No JavaScript errors in console (scripts simply don't load)
- Forms submit but nothing happens
- Copy buttons don't function

### Root Cause
In versions prior to the fix (commit 644ba02), the Pro addon incorrectly determined its asset URL when both plugins were installed separately. The constant `WP_MCP_AI_PRO_URL` was set to `{base-plugin-url}/addons/pro/` even when Pro was installed as a separate plugin, causing 404 errors for all assets.

### Solution (Fixed in v1.3.0+)
The Pro addon now correctly detects whether it's bundled or separate by checking if the plugin path contains `addons/pro/`:

```php
// Updated logic in mcp-ai-wpoos-pro.php
$is_bundled = defined( 'WP_MCP_AI_URL' ) && 
              defined( 'WP_MCP_AI_PATH' ) && 
              strpos( WP_MCP_AI_PRO_PATH, WP_MCP_AI_PATH . 'addons/pro' ) !== false;

if ( $is_bundled ) {
    define( 'WP_MCP_AI_PRO_URL', WP_MCP_AI_URL . 'addons/pro/' );
} else {
    define( 'WP_MCP_AI_PRO_URL', plugin_dir_url( WP_MCP_AI_PRO_FILE ) );
}
```

### Verification Steps

1. **Enable WP_DEBUG** to see asset URLs in PHP error log:
   ```php
   // wp-config.php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   ```

2. **Check Error Log** for Pro addon script enqueue messages:
   ```
   Password Vault: Enqueuing scripts. WP_MCP_AI_PRO_URL: {url}, WP_MCP_AI_PRO_PATH: {path}
   ```

3. **Verify Asset URLs** in browser DevTools:
   - Open the Password Generator page
   - Check Network tab for 404 errors
   - Correct URL should match plugin installation type:
     - Bundled: `.../mcp-ai-wpoos/addons/pro/assets/js/password-vault-admin.js`
     - Separate: `.../mcp-ai-wpoos-pro/assets/js/password-vault-admin.js`

### Manual Workaround (if fix not available)
If you're using an older version, you can manually fix by editing `addons/pro/mcp-ai-wpoos-pro.php`:

```php
// Replace lines 41-49 with the fixed code above
```

## NPM Package Issues

### sharp Installation Problems

**Symptoms**: Image processing tools fail with "sharp not found" error

**Solution**:
```bash
# Ubuntu/Debian
sudo apt-get install build-essential libvips-dev

# CentOS/RHEL
sudo yum install gcc-c++ vips-devel

# macOS
brew install vips

# Then reinstall sharp
cd wp-content/plugins/mcp-ai-wpoos/addons/pro
npm install sharp --production
```

### fluent-ffmpeg Not Working

**Symptoms**: Video processing tools fail or return errors

**Solution**: Install FFmpeg on server
```bash
# Ubuntu/Debian
sudo apt-get install ffmpeg

# CentOS/RHEL
sudo yum install ffmpeg

# Verify installation
ffmpeg -version
```

### Missing Node Modules

**Symptoms**: Tools report "module not found" errors

**Solution**: 
```bash
cd wp-content/plugins/mcp-ai-wpoos
npm install --production
```

## Admin Page Issues

### Password Vault Generator Page Not Loading

**Symptoms**:
- Page shows HTML structure but no interactivity
- JavaScript console shows no errors
- Forms don't submit via AJAX

**Solutions**:

1. **Check Plugin Activation Order**:
   - Ensure base plugin (`mcp-ai-wpoos`) loads before Pro (`mcp-ai-wpoos-pro`)
   - WordPress loads plugins alphabetically, so this should happen automatically

2. **Verify Constants**:
   Add debug output to see constant values:
   ```php
   // In wp-config.php (remove after debugging)
   add_action('admin_init', function() {
       if (defined('WP_MCP_AI_PRO_URL') && defined('WP_MCP_AI_PRO_PATH')) {
           error_log('WP_MCP_AI_PRO_URL: ' . WP_MCP_AI_PRO_URL);
           error_log('WP_MCP_AI_PRO_PATH: ' . WP_MCP_AI_PRO_PATH);
       }
   });
   ```

3. **Clear Caches**:
   - Clear WordPress object cache
   - Clear any page caching plugins
   - Clear browser cache
   - Try in incognito/private browsing mode

4. **Check File Permissions**:
   ```bash
   # Ensure assets are readable
   chmod -R 755 wp-content/plugins/mcp-ai-wpoos-pro/assets/
   # OR for bundled installation
   chmod -R 755 wp-content/plugins/mcp-ai-wpoos/addons/pro/assets/
   ```

### AJAX Requests Failing

**Symptoms**: Forms submit but return 400/403/500 errors

**Solutions**:

1. **Check Nonces**: Ensure nonces are being passed correctly
2. **Verify Capabilities**: User must have `manage_options` capability
3. **Check AJAX URL**: Should be `wp-admin/admin-ajax.php`
4. **Enable Debug Mode**:
   ```php
   // wp-config.php
   define('SCRIPT_DEBUG', true);
   ```

## Getting Help

If you continue to experience issues:

1. **Check Debug Log**: Review `wp-content/debug.log` for error messages
2. **Browser Console**: Check for JavaScript errors in DevTools console
3. **Network Tab**: Look for 404 or failed requests in DevTools Network tab
4. **GitHub Issues**: Report bugs at https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
5. **Commercial Support**: Contact NV Digital Solutions for priority support

## Version Information

This troubleshooting guide applies to:
- Pro Add-on v1.3.0+
- Base Plugin v1.0.0+

For older versions, please update to the latest release for bug fixes and improvements.

---

**Last Updated**: January 2026
**Copyright (c) 2025 NV Digital Solutions**
