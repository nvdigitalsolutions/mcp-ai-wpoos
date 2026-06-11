# Admin Menu Cache Clearing Guide

## Problem

After deploying code changes that modify admin menu slugs or URLs, WordPress may serve cached menu structures causing:
- 404 errors on admin pages
- Incorrect menu URLs
- Menu items pointing to non-existent pages

## Example Issue

**Pro Workflow Builder URL Issue:**
- Old cached URL: `/wp-admin/nvoos-pro-workflow-builder` (404 error)
- Correct URL: `/wp-admin/admin.php?page=nvoos-pro-workflow-builder` (works)

## Root Causes

1. **WordPress Options Cache** - Menu structures stored in `wp_options` table
2. **PHP OpCache** - Server-side caching of PHP files
3. **Object Cache** - Redis/Memcached caching of WordPress objects
4. **Browser Cache** - Client-side caching of admin HTML

## Solution Methods

### Method 1: WP-CLI Command (Recommended)

```bash
# Navigate to WordPress root directory
cd /path/to/wordpress

# Run the cache clearing script
wp eval-file wp-content/plugins/mcp-ai-wpoos/bin/clear-admin-menu-cache.php

# Or run directly with PHP
php wp-content/plugins/mcp-ai-wpoos/bin/clear-admin-menu-cache.php
```

### Method 2: Manual Cache Clearing

#### A. Clear PHP OpCache (Server)

```bash
# Option 1: Restart PHP-FPM
sudo systemctl restart php8.1-fpm  # Adjust version as needed

# Option 2: Touch wp-config.php to bust OpCache
touch /path/to/wordpress/wp-config.php
```

#### B. Clear WordPress Cache (Database)

```sql
-- Connect to MySQL
mysql -u username -p database_name

-- Delete menu transients
DELETE FROM wp_options 
WHERE option_name LIKE '_transient_%menu%' 
   OR option_name LIKE '_site_transient_%menu%';

-- Clear object cache entries
DELETE FROM wp_options 
WHERE option_name LIKE '_transient_timeout_%' 
   AND option_value < UNIX_TIMESTAMP();
```

#### C. Clear Object Cache (If Using Redis/Memcached)

```bash
# Redis
redis-cli FLUSHDB

# Memcached
echo 'flush_all' | nc localhost 11211
```

#### D. Clear Browser Cache

**For Testing:**
1. Open browser in **Incognito/Private mode**
2. Log into WordPress admin
3. Test the menu link

**For Permanent Fix:**
1. Press `Ctrl+Shift+Del` (Chrome/Firefox)
2. Select "Cached images and files"
3. Click "Clear data"

### Method 3: WordPress Admin UI

1. Install a caching plugin like **WP Super Cache** or **W3 Total Cache**
2. Navigate to the plugin's settings
3. Click "Delete Cache" or "Purge All Caches"
4. Log out and log back in

## Verification Steps

After clearing cache:

1. **Check the correct URL format:**
   ```
   https://yourdomain.com/wp-admin/admin.php?page=nvoos-pro-workflow-builder
   ```

2. **Verify menu link:**
   - Go to: WP Admin > NV oOS Pro
   - Click: "Pro Workflows"
   - Inspect the browser address bar
   - Should show: `admin.php?page=nvoos-pro-workflow-builder`

3. **Check for 404 errors:**
   - The page should load successfully
   - No "Page not found" errors

## Prevention

### For Development

Add to `.gitignore`:
```
*.cache
wp-content/cache/
wp-content/object-cache.php
```

### For Deployment

**Always run after deploying admin menu changes:**

```bash
# 1. Clear PHP OpCache
sudo systemctl restart php-fpm

# 2. Clear WordPress cache
wp cache flush

# 3. Run our custom script
wp eval-file wp-content/plugins/mcp-ai-wpoos/bin/clear-admin-menu-cache.php

# 4. Verify
wp admin menu list
```

### For CI/CD Pipeline

Add to deployment script:

```yaml
# Example: GitHub Actions
- name: Clear WordPress Caches
  run: |
    wp cache flush
    wp eval-file wp-content/plugins/mcp-ai-wpoos/bin/clear-admin-menu-cache.php
    sudo systemctl restart php-fpm
```

## Common Errors

### "Page Not Found" or 404

**Symptom:** Clicking menu item shows 404 error

**Solution:**
```bash
wp rewrite flush
wp cache flush
```

### Menu Item Missing

**Symptom:** Menu item doesn't appear

**Solution:**
```bash
# Check if plugin is active
wp plugin list

# Re-activate plugin
wp plugin deactivate mcp-ai-wpoos && wp plugin activate mcp-ai-wpoos

# Clear cache
wp cache flush
```

### Wrong URL Format

**Symptom:** URL shows `/wp-admin/page-slug` instead of `/wp-admin/admin.php?page=page-slug`

**Solution:** This is the exact issue - clear all caches per methods above.

## Testing Checklist

After clearing cache, verify:

- [ ] PHP OpCache cleared (check `phpinfo()` or restart service)
- [ ] WordPress transients deleted
- [ ] Object cache flushed (if applicable)
- [ ] Browser cache cleared (use incognito mode)
- [ ] Menu link generates correct URL format
- [ ] Admin page loads without 404 error
- [ ] Menu item appears in correct location

## Related Files

- Cache clearing script: `bin/clear-admin-menu-cache.php`
- Pro Workflow Builder: `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`
- URL fix documentation: `docs/fixes/pro-workflow-builder-url-fix-2026-02-04.md`

## Support

If issues persist after following this guide:

1. Check PHP error logs: `tail -f /var/log/php/error.log`
2. Check WordPress debug log: Enable `WP_DEBUG_LOG` in `wp-config.php`
3. Verify plugin is active: `wp plugin list --status=active`
4. Check file permissions: `ls -la wp-content/plugins/mcp-ai-wpoos/`

## References

- [WordPress Transients API](https://developer.wordpress.org/apis/transients/)
- [WP-CLI Cache Commands](https://developer.wordpress.org/cli/commands/cache/)
- [PHP OpCache Documentation](https://www.php.net/manual/en/book.opcache.php)
