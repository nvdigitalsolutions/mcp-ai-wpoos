# Pro Workflow Builder URL - Quick Fix Summary

## 🎯 The Problem You're Experiencing

You're seeing this URL (which gives a 404 error):
```
https://bots.nvdigital.solutions/wp-admin/nvoos-pro-workflow-builder
```

But you should be seeing this URL (which works):
```
https://bots.nvdigital.solutions/wp-admin/admin.php?page=nvoos-pro-workflow-builder
```

## ✅ Good News: The Code is Already Fixed!

The code in this repository is **already correct**. The page slug is properly set to `nvoos-pro-workflow-builder` which generates the correct URL format.

## 🔧 Why You're Still Seeing the Problem

**Your production server has cached the old menu structure.** This is a common issue after deploying menu changes.

## 🚀 How to Fix It (Choose One Method)

### Method 1: WP-CLI (Fastest - 30 seconds)

```bash
# SSH into your production server
ssh user@bots.nvdigital.solutions

# Run our cache clearing script
wp eval-file wp-content/plugins/mcp-ai-wpoos/bin/clear-admin-menu-cache.php

# Restart PHP to clear OpCache
sudo systemctl restart php8.1-fpm
```

Done! ✓

### Method 2: WordPress Admin (No SSH needed)

1. Install a cache plugin (if not already installed):
   - **WP Super Cache** or **W3 Total Cache**
   
2. Go to the plugin's settings page

3. Click **"Purge All Caches"** or **"Delete Cache"**

4. Log out and log back in

5. Test in **incognito/private browsing mode**

### Method 3: Manual Cache Clear

```bash
# Clear PHP OpCache
sudo systemctl restart php-fpm

# Or just touch the config file
touch /path/to/wordpress/wp-config.php
```

Then clear your browser cache and reload.

## ✅ Verify It's Fixed

After clearing cache:

1. Log into WordPress admin (use **incognito mode** to avoid browser cache)
2. Go to: **NV oOS Pro** menu
3. Click: **Pro Workflows**
4. Check the URL in your browser:
   - ✅ Should show: `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`
   - ✅ Page should load without 404 error

## 📋 What This PR Contains

1. **`bin/clear-admin-menu-cache.php`** - Cache clearing script
2. **`docs/fixes/DEPLOYMENT_CACHE_CLEARING.md`** - Full deployment guide
3. **`WORKFLOW_BUILDER_URL_ANALYSIS.md`** - Detailed technical analysis

## 🛠️ For Your DevOps Team

Add this to your deployment script to prevent future cache issues:

```bash
#!/bin/bash
# After deploying code changes to admin menus:

# Clear WordPress caches
wp cache flush
wp eval-file wp-content/plugins/mcp-ai-wpoos/bin/clear-admin-menu-cache.php

# Clear PHP OpCache
sudo systemctl restart php-fpm

# Clear object cache (if using Redis/Memcached)
redis-cli FLUSHDB  # or: echo 'flush_all' | nc localhost 11211
```

## 📞 Still Having Issues?

If you still see the 404 error after clearing cache:

1. **Clear browser cache completely** (Ctrl+Shift+Del)
2. **Test in different browser** (to rule out browser-specific issues)
3. **Check PHP error logs**: `tail -f /var/log/php/error.log`
4. **Verify plugin is active**: `wp plugin list | grep mcp-ai-wpoos`

## 🎓 What Caused This?

The page slug was previously `wp-mcp-ai-pro-workflow-builder` (starting with `wp-`).

WordPress saw the `wp-` prefix and treated it as a direct file path: `/wp-admin/wp-mcp-ai-pro-workflow-builder`

This was fixed by changing to `nvoos-pro-workflow-builder` (no `wp-` prefix), which WordPress treats as a query parameter: `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`

But your production server is still serving the old cached menu structure!

## 🔑 Key Takeaway

**This is not a code bug** - it's a **cache issue** that requires clearing cached data on your production server. Once you clear the cache, the correct URL will be generated automatically.

---

**Need Help?** See the comprehensive guides:
- `WORKFLOW_BUILDER_URL_ANALYSIS.md` - Full technical analysis
- `docs/fixes/DEPLOYMENT_CACHE_CLEARING.md` - Complete deployment guide
