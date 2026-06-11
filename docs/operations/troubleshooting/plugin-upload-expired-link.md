# Fix "The Link You Followed Has Expired" When Uploading Pro Plugin

## Problem

When trying to upload the NV oOS Pro plugin ZIP file (approximately 50-53MB) via WordPress admin (**Plugins → Add New → Upload Plugin**), you see the error:

> **"The link you followed has expired."**

## Root Cause

This error occurs when the plugin ZIP file size exceeds your server's PHP upload limits:

- **`upload_max_filesize`**: Maximum size for uploaded files (default: often 2M-8M)
- **`post_max_size`**: Maximum size of POST data (default: often 8M)
- **`memory_limit`**: PHP memory limit (default: often 128M)

The pro plugin ZIP is **50-53MB**, which exceeds these default limits.

## Quick Diagnosis

Check your current PHP limits in WordPress:

1. Go to **Tools → Site Health → Info → Server**
2. Look for these values:
   - `upload_max_filesize`
   - `post_max_size`
   - `memory_limit`

**Required minimums for pro plugin upload:**
- `upload_max_filesize`: **64M** (or higher)
- `post_max_size`: **64M** (or higher)
- `memory_limit`: **256M** (recommended)

## Solution: Increase PHP Upload Limits

Choose the method that works for your hosting environment:

### Method 1: Edit php.ini (Recommended - VPS/Dedicated Servers)

If you have server access, edit your `php.ini` file:

```ini
upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 256M
max_execution_time = 300
```

**Steps:**
1. Locate your `php.ini` file (commonly in `/etc/php/7.4/apache2/php.ini` or similar)
2. Edit the file and add/modify the above values
3. Restart your web server:
   - Apache: `sudo systemctl restart apache2`
   - Nginx: `sudo systemctl restart php7.4-fpm` (adjust PHP version)
4. Verify changes in **Tools → Site Health → Info → Server**

### Method 2: Create .user.ini (cPanel/Shared Hosting)

Create a `.user.ini` file in your WordPress root directory:

**File location:** `/home/username/public_html/.user.ini`

**Contents:**
```ini
upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 256M
max_execution_time = 300
```

**Steps:**
1. Connect via FTP or cPanel File Manager
2. Navigate to your WordPress root directory
3. Create a new file named `.user.ini` (note the leading dot)
4. Add the above content
5. Save the file
6. **Wait 5 minutes** for changes to take effect (PHP caches .user.ini files)
7. Verify changes in **Tools → Site Health → Info → Server**

**Note:** Some shared hosts may not allow `.user.ini` modifications. If this doesn't work, try Method 3 or 4.

### Method 3: Edit .htaccess (Apache Servers)

Add these lines to your WordPress `.htaccess` file:

**File location:** `/home/username/public_html/.htaccess`

**Add these lines** (before the `# BEGIN WordPress` section):

```apache
php_value upload_max_filesize 64M
php_value post_max_size 64M
php_value memory_limit 256M
php_value max_execution_time 300
```

**Steps:**
1. Connect via FTP or cPanel File Manager
2. Open `.htaccess` in your WordPress root
3. Add the lines above **before** `# BEGIN WordPress`
4. Save the file
5. Verify changes in **Tools → Site Health → Info → Server**

**Note:** This method only works on Apache servers with `mod_php`. If you get a 500 Internal Server Error, remove these lines and try another method.

### Method 4: wp-config.php (WordPress-Specific Limits)

Add these lines to your `wp-config.php` file (before `/* That's all, stop editing! */`):

```php
@ini_set( 'upload_max_filesize', '64M' );
@ini_set( 'post_max_size', '64M' );
@ini_set( 'memory_limit', '256M' );
@ini_set( 'max_execution_time', '300' );
```

**Note:** This method may not work on all servers if PHP's `suhosin` extension is active or if your host restricts `ini_set()`.

### Method 5: Contact Your Hosting Provider

If none of the above methods work, contact your hosting provider's support team:

**Sample support request:**

> Hello, I need to upload a 50MB WordPress plugin but keep getting "The link you followed has expired" error. Can you please increase these PHP settings for my account?
> 
> - `upload_max_filesize = 64M`
> - `post_max_size = 64M`
> - `memory_limit = 256M`
> - `max_execution_time = 300`
> 
> Thank you!

Most hosting providers can make these changes within minutes.

## Verify the Fix

After making changes:

1. Go to **Tools → Site Health → Info → Server** in WordPress
2. Check that the values show at least:
   - `upload_max_filesize`: 64M
   - `post_max_size`: 64M
   - `memory_limit`: 256M
3. If the values haven't changed:
   - Wait 5 minutes (for `.user.ini` changes)
   - Clear your browser cache
   - Restart PHP/Apache if you have access
4. Try uploading the pro plugin again

## Alternative: Install via FTP/SFTP

If you cannot increase PHP limits, upload the plugin manually via FTP:

1. **Unzip** the pro plugin ZIP file on your computer
2. Connect to your server via FTP/SFTP
3. Navigate to `/wp-content/plugins/`
4. Upload the **unzipped folder** (e.g., `mcp-ai-wpoos-pro/`)
5. Go to **Plugins** in WordPress admin
6. Find "NV oOS Pro" and click **Activate**

This method bypasses PHP upload limits entirely.

## Alternative: Use WP-CLI

If you have command-line access:

```bash
# Navigate to WordPress directory
cd /path/to/wordpress

# Install the plugin from ZIP
wp plugin install /path/to/mcp-ai-wpoos-pro-x.x.x.zip --activate

# Or download from URL
wp plugin install https://example.com/downloads/mcp-ai-wpoos-pro-x.x.x.zip --activate
```

## Hosting-Specific Instructions

### SiteGround
Use **Site Tools → Devs → PHP Manager → PHP Options** to increase limits.

### Bluehost
Use **cPanel → Software → Select PHP Version → Switch To PHP Options** to increase limits.

### WP Engine
Contact support - they can increase limits for your account.

### Kinsta
Go to **MyKinsta → Sites → Tools → PHP Engine** and adjust limits.

### Cloudways
Go to **Application Management → Application Settings → PHP-FPM Settings** to increase limits.

### AWS/DigitalOcean/Linode
Edit `php.ini` directly (Method 1) and restart PHP-FPM.

## Prevention

To avoid this issue in the future:

1. **Always check PHP limits** before upgrading large plugins
2. **Use FTP upload** for plugins larger than your PHP limits
3. **Keep PHP limits generous** (64M+ for uploads, 256M+ for memory)
4. **Monitor** Site Health regularly

## Still Having Issues?

If you've tried all methods and still see the error:

1. **Check server error logs** (`/var/log/apache2/error.log` or similar)
2. **Disable security plugins** temporarily (they may block large uploads)
3. **Check PHP version** (upgrade to PHP 7.4+ if on older version)
4. **Verify file permissions** on `wp-content/plugins/` (should be 755)
5. **Check disk space** (ensure you have enough space for the upload)

## Additional Resources

- [WordPress Site Health](https://wordpress.org/support/article/site-health-screen/)
- [Increasing PHP Memory Limits](https://wordpress.org/support/article/common-wordpress-errors/#increasing-php-memory)
- [PHP Configuration Changes](https://www.php.net/manual/en/configuration.changes.php)

## Summary

The **"link expired"** error when uploading large plugins is caused by **insufficient PHP upload limits**. Increase `upload_max_filesize` and `post_max_size` to **64M** (or higher) using one of the methods above, or upload the plugin via FTP to bypass the limit entirely.
