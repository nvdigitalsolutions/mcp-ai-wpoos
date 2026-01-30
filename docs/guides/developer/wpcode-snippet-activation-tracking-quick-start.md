# Quick Setup Guide: WPCode Snippet for Plugin Tracking

## 📋 What This Is

A WordPress REST API endpoint to receive anonymous plugin activation tracking data from NV oOS plugin installations.

## 🚀 Quick Install (5 minutes)

### Step 1: Copy the Code
Open `wpcode-snippet.php` in this repository and copy the entire content.

### Step 2: Add to WPCode
1. Log in to https://nvdigitalsolutions.com/wp-admin
2. Navigate to **Code Snippets** → **+ Add Snippet**
3. Click **Create Custom Snippet (New Snippet)**
4. Paste the code
5. Set these options:
   - **Title**: "NV oOS Plugin Activation Tracking"
   - **Code Type**: PHP Snippet
   - **Location**: Site Wide Footer (or Everywhere)
   - **Status**: Active
6. Click **Update**

### Step 3: Test the Endpoint
```bash
curl -X POST https://nvdigitalsolutions.com/wp-json/api/plugin-tracking/activation \
  -H "Content-Type: application/json" \
  -d '{"plugin_variant":"complete","plugin_version":"1.1.0","wordpress_version":"6.7","php_version":"8.1","locale":"en_US","multisite":false,"site_hash":"test123","timestamp":1738108800}'
```

Expected response: `{"success":true}`

## 📊 What It Does

- ✅ Creates REST endpoint at `/wp-json/api/plugin-tracking/activation`
- ✅ Validates incoming data (variant, version, etc.)
- ✅ Creates database table automatically (`wp_nvoos_plugin_tracking`)
- ✅ Stores activation/deactivation events
- ✅ Updates existing records based on site_hash (unique site identifier)
- ✅ Returns success/error responses

## 🗄️ Database Structure

The snippet automatically creates a table with these fields:
- `plugin_variant` - complete, base, pro, or core
- `plugin_version` - e.g., "1.1.0"
- `wordpress_version` - e.g., "6.7"
- `php_version` - e.g., "8.1"
- `locale` - e.g., "en_US"
- `multisite` - boolean
- `site_hash` - unique anonymized site identifier
- `event` - "activation" or "deactivation"
- `timestamp` - Unix timestamp
- `received_at` - When data was received

## 📈 View Your Data

### Via phpMyAdmin
Query the `wp_nvoos_plugin_tracking` table.

### Quick Stats Query
```sql
SELECT 
    plugin_variant,
    COUNT(*) as total_sites
FROM wp_nvoos_plugin_tracking
GROUP BY plugin_variant;
```

### Enable Dashboard Widget (Optional)
Uncomment the dashboard widget code at the bottom of `wpcode-snippet.php` to see stats in WordPress admin.

## 🔍 Troubleshooting

### "Endpoint not found"
- Check that WPCode snippet is **Active**
- Verify WordPress permalinks are enabled (Settings → Permalinks)
- Try visiting: https://nvdigitalsolutions.com/wp-json/ (should show REST API index)

### "Table not created"
The table is created automatically on first request. If issues persist:
1. Check database permissions
2. Run the CREATE TABLE SQL manually (see full documentation)

### "Data not saving"
- Check PHP error logs
- Verify database connection
- Ensure wpdb has write permissions

## 📚 Full Documentation

For detailed documentation including:
- Google Analytics 4 integration
- File logging
- Advanced queries
- Dashboard widgets
- Maintenance tips

See: `docs/wpcode-snippet-activation-tracking.md`

## 🆘 Support

- Plugin issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Privacy documentation: See `docs/EXTERNAL_SERVICES.md` in plugin repo

---

**Version:** 1.0.0  
**Last Updated:** January 28, 2026
