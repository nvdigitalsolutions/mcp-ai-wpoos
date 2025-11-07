# Troubleshooting: "Unexpected Token 'private'" Syntax Errors

## Problem

You're seeing fatal PHP errors like:
```
Parse error: syntax error, unexpected token "private" in /path/to/wp-content/plugins/wpos/includes/admin/class-wp-mcp-ai-admin-settings.php on line 32
```

## Root Cause

Despite PHP 8.1 being installed (which fully supports this syntax), this error is almost always caused by **corrupted OPcache**. OPcache is PHP's bytecode cache that stores compiled PHP code. When it becomes corrupted, it can cause parse errors even in perfectly valid PHP files.

## Why This Happens

1. **File Updates**: When plugin files are updated via FTP/SFTP, the OPcache may cache an incomplete or corrupted version
2. **Server Issues**: Memory pressure or server restarts can corrupt cached bytecode
3. **Deployment Issues**: Files uploaded during active PHP processing
4. **Cloudways Specific**: Cloudways uses aggressive caching which can cause this issue

## Quick Fix (Recommended)

### Method 1: Use the OPcache Reset Utility

1. Download the `opcache-reset.php` file from the plugin directory
2. Upload it to your WordPress root directory (same level as wp-config.php)
3. Access it via browser: `https://yoursite.com/opcache-reset.php`
4. **IMPORTANT**: Delete the file immediately after use for security

### Method 2: Restart PHP-FPM (Fastest)

For Cloudways users:
1. Log into Cloudways Platform
2. Go to your Server Management
3. Click "Services"
4. Restart PHP-FPM service

For other hosts with SSH access:
```bash
sudo service php8.1-fpm restart
# or
sudo systemctl restart php8.1-fpm
```

### Method 3: Clear OPcache via Control Panel

**Cloudways:**
1. Servers → Select your server
2. Settings & Packages → Packages
3. Click "Purge Varnish" button (this also clears OPcache)

**cPanel:**
1. Go to "MultiPHP INI Editor"
2. Select your domain
3. Find OPcache settings
4. Temporarily disable, then re-enable

### Method 4: Disable OPcache Temporarily

Add this to the TOP of your `wp-config.php` file (after the opening <?php tag):

```php
// Temporary OPcache disable - remove after fixing
ini_set('opcache.enable', '0');
```

After the error is gone, you can remove this line.

## Prevention

To prevent this from happening again:

### 1. Use Proper Deployment Methods

Instead of FTP, use deployment tools that handle cache clearing:
- WP-CLI: `wp cache flush`
- Git deployment with post-deploy hooks
- Managed WordPress hosts that auto-clear cache

### 2. Clear Cache After Updates

After uploading new files via FTP:

```bash
# SSH into your server
cd /path/to/wordpress
wp cache flush
```

Or add this to your deployment script:
```bash
#!/bin/bash
# Upload files
rsync -avz --progress ./wp-mcp-ai/ user@server:/path/to/wp-content/plugins/wp-mcp-ai/

# Clear cache
ssh user@server "cd /path/to/wordpress && wp cache flush"
```

### 3. Configure OPcache Properly

Add to `php.ini` or `.user.ini`:

```ini
; Validate cached files every request (in development)
opcache.validate_timestamps=1
opcache.revalidate_freq=0

; Or for production (better performance):
opcache.validate_timestamps=1
opcache.revalidate_freq=60
```

## Verification

After applying the fix, verify it worked:

1. Try accessing your WordPress admin: `https://yoursite.com/wp-admin`
2. Check plugin is activated without errors
3. Test the AI assistant functionality

## Still Having Issues?

### Check PHP Version

Even though you reported PHP 8.1, verify it:

```bash
php -v
```

The plugin requires PHP 7.4 or higher. If you see an older version, ask your host to upgrade.

### Check File Permissions

```bash
cd /path/to/wp-content/plugins/wp-mcp-ai
find . -type f -exec chmod 644 {} \;
find . -type d -exec chmod 755 {} \;
```

### Check for File Corruption

Re-upload the plugin files:

1. Download a fresh copy from the repository
2. Delete the entire plugin folder on the server
3. Upload the fresh copy
4. Clear OPcache using one of the methods above

### Contact Support

If none of these solutions work, create an issue at:
https://github.com/nvdigitalsolutions/wp-mcp-ai/issues

Include:
- PHP version (`php -v`)
- WordPress version
- Hosting provider (especially if Cloudways)
- Complete error message
- Steps you've tried

## Technical Details

The error references line 32 in `class-wp-mcp-ai-admin-settings.php`:

```php
private static $settings_cache = null;
```

This is perfectly valid PHP 5.3+ syntax. The error only occurs when:
1. OPcache has a corrupted bytecode cache
2. The file is served from a corrupted source
3. Server configuration issues interfere with parsing

The plugin includes a PHP version check that prevents activation on PHP < 7.4, so version incompatibility is ruled out if the plugin activated successfully.

## For Developers

If you're developing plugins that might encounter this issue:

1. **Always clear OPcache** after file changes in production
2. **Test with OPcache enabled** during development
3. **Add version checks** before loading classes (we do this in `wp-mcp-ai.php`)
4. **Provide clear error messages** when version requirements aren't met

Our plugin now includes:
- PHP version check before loading any classes
- Clear error messages if requirements aren't met
- `Requires PHP: 7.4` header for WordPress to enforce requirements
- This troubleshooting guide for production issues
