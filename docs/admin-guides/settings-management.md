# Settings Management Guide

**NV oOS Settings Management** provides comprehensive tools for backing up, restoring, and maintaining your plugin configuration.

## Table of Contents

- [Overview](#overview)
- [Accessing Settings Management](#accessing-settings-management)
- [Features](#features)
  - [Settings Health Check](#settings-health-check)
  - [Export Settings](#export-settings)
  - [Import Settings](#import-settings)
  - [Clear Cache](#clear-cache)
  - [Reset to Defaults](#reset-to-defaults)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)
- [Technical Details](#technical-details)

## Overview

Settings Management is your control center for maintaining plugin configuration integrity. It provides:

- **Automatic Backups**: Every save operation creates a timestamped backup
- **Export/Import**: Migrate settings between sites or create manual backups
- **Health Monitoring**: Run diagnostics to verify settings integrity
- **Cache Management**: Clear stale caches when changes don't take effect
- **Reset Capability**: Return to default settings when needed

## Accessing Settings Management

1. Navigate to **NV oOS → Advanced**
2. Click the **Settings Management** subtab
3. You'll see the Settings Management dashboard

## Features

### Settings Health Check

**Purpose**: Diagnose configuration issues and verify settings integrity.

**How to Use**:
1. Click **Check Settings Health** button
2. Review the diagnostic report showing:
   - **Issues**: Critical problems requiring immediate attention (red)
   - **Warnings**: Potential problems to review (orange)
   - **Info**: General status information (blue)

**What It Checks**:
- ✓ Settings exist in database
- ✓ Settings data structure is valid
- ✓ Critical fields are present and populated
- ✓ AI providers are configured
- ✓ Cache status
- ✓ Number of backup snapshots available

**Example Output**:
```
Health check complete. Status: GOOD

Info:
• Total settings fields: 247
• Configured providers: 3
• Object cache status: Active
• Settings backups available: 5
```

**When to Use**:
- After major configuration changes
- When experiencing unexpected behavior
- Before performing system updates
- As part of regular maintenance

---

### Export Settings

**Purpose**: Create a portable backup of all plugin settings for safekeeping or migration.

**How to Use**:
1. Click **Export Settings (JSON)** button
2. Your browser will download a file named: `nv-oos-settings-YYYY-MM-DD-HH-MM-SS.json`
3. Save this file in a secure location

**File Contents**:
```json
{
  "version": "1.0",
  "exported_at": "2025-01-20 19:30:00",
  "exported_by": "admin",
  "site_url": "https://example.com",
  "plugin_version": "1.0.0",
  "settings": {
    "openai_api_key": "sk-...",
    "default_model": "gpt-4o",
    ...
  }
}
```

**Use Cases**:
- **Before Major Changes**: Create a backup before modifying critical settings
- **Site Migration**: Transfer configuration to staging or production
- **Disaster Recovery**: Keep regular backups for emergency restoration
- **Multi-Site Consistency**: Share settings across multiple installations

**Security Note**: 
⚠️ **The export file contains sensitive API keys and credentials.** Store it securely and never commit it to public repositories.

---

### Import Settings

**Purpose**: Restore settings from a previously exported backup file.

**How to Use**:
1. Click **Choose File** and select a `.json` export file
2. Click **Upload & Import** button
3. Confirm the import operation
4. Wait for validation and import to complete
5. Page will auto-reload with imported settings

**Safety Features**:
- ✓ **Pre-Import Backup**: Current settings are backed up automatically before import
- ✓ **File Validation**: Checks file type, size, and JSON structure
- ✓ **Size Limit**: Maximum 5MB to prevent memory issues
- ✓ **Data Sanitization**: All imported data is sanitized and validated
- ✓ **Integrity Checks**: 7 validation checks before applying changes

**Validation Process**:
```
1. Check file is valid JSON
2. Verify settings structure
3. Sanitize all values
4. Validate merged settings:
   - Array structure
   - Critical fields present
   - Numeric values are numbers
   - URLs are valid
   - Emails are valid
5. Create pre-import backup
6. Apply settings atomically
7. Clear all caches
```

**Error Handling**:
If validation fails, you'll see specific error messages:
- "Invalid JSON format: Syntax error"
- "Settings validation failed: Critical field 'default_provider' is missing"
- "File too large. Maximum size: 5 MB"

**Recovery**:
If import causes issues, restore from the automatic backup:
- Check database for option: `wp_mcp_ai_settings_backup_pre_import_[timestamp]`

---

### Clear Cache

**Purpose**: Remove all cached settings data to ensure fresh values are used.

**How to Use**:
1. Click **Clear All Caches** button
2. Confirm the operation
3. Wait for confirmation message

**What Gets Cleared**:
- **Static Cache**: In-memory PHP cache (`$settings_cache`)
- **Object Cache**: WordPress object cache entries
- **Transients**: Any temporary cached settings
- **Full Cache**: Optionally flushes entire WordPress object cache

**When to Use**:
- Settings changes not taking effect immediately
- Seeing stale/old values after update
- After plugin update or migration
- When troubleshooting configuration issues
- After importing settings

**Performance Note**:
Cache clearing is safe and automatic. The next settings access will rebuild the cache with fresh data from the database.

---

### Reset to Defaults

**Purpose**: Return all plugin settings to their default values.

**How to Use**:
1. Click **Reset All Settings** button
2. Read the warning carefully
3. Confirm you want to proceed
4. Wait for reset to complete
5. Page will auto-reload with default settings

**⚠️ WARNING**: 
This operation:
- Resets **ALL** settings to defaults
- Removes all API keys and credentials
- Clears provider configurations
- Resets all tool settings
- **Cannot be undone** (except via backup restoration)

**Safety Features**:
- ✓ **Pre-Reset Backup**: Current settings saved as `wp_mcp_ai_settings_backup_pre_reset_[timestamp]`
- ✓ **Double Confirmation**: Requires explicit user confirmation
- ✓ **Descriptive Warning**: Clear explanation of consequences

**Use Cases**:
- Starting fresh after testing
- Resolving corruption issues
- Cleaning up after failed migration
- Returning to known-good state

**Recovery**:
To restore after accidental reset:
1. Find the pre-reset backup in database options table
2. Export that backup to JSON
3. Import it using the Import Settings feature

---

## Best Practices

### Regular Backups

**Recommended Schedule**:
- **Daily**: If making frequent changes
- **Weekly**: For stable production environments
- **Before Changes**: Always before major configuration updates
- **Before Updates**: Before plugin or WordPress updates

**Backup Strategy**:
```
1. Export settings to JSON
2. Name file descriptively: site-name-YYYY-MM-DD.json
3. Store in secure location (encrypted cloud storage)
4. Keep multiple versions (weekly, monthly)
5. Document what changed in each version
```

### Settings Migration Workflow

**Staging to Production**:
```
1. Configure settings on staging site
2. Test thoroughly
3. Export from staging
4. Review export file (remove test API keys if needed)
5. Import to production
6. Verify critical settings
7. Test core functionality
8. Keep staging export as rollback point
```

### Troubleshooting Workflow

**When Settings Don't Persist**:
```
1. Check Settings Health
   - Look for critical issues
   - Verify providers configured

2. Clear Cache
   - Ensure fresh data loaded

3. Check Logs (if enabled)
   - Navigate to Advanced → Logging
   - Look for save errors

4. Export Current Settings
   - Create backup before changes

5. Make changes incrementally
   - Change one section at a time
   - Test after each change

6. Run Health Check Again
   - Verify changes applied
```

### Security Best Practices

1. **Protect Export Files**
   - Never commit to Git
   - Encrypt before cloud storage
   - Share via secure channels only
   - Delete after use

2. **Regular Audits**
   - Run Health Check monthly
   - Review configured providers
   - Verify API keys are current
   - Check backup availability

3. **Access Control**
   - Only administrators can access
   - Audit who exports settings
   - Monitor import operations
   - Log all management actions

---

## Troubleshooting

### Problem: Import Fails with "Invalid JSON"

**Solutions**:
- Verify file wasn't corrupted during transfer
- Open file in text editor to check format
- Ensure file is complete (not truncated)
- Try exporting again from source site

### Problem: Settings Not Persisting

**Solutions**:
1. Run Health Check to identify issues
2. Clear all caches
3. Check file permissions on `wp_options` table
4. Enable logging to see save errors
5. Verify no PHP errors in error log

### Problem: Cache Clear Doesn't Help

**Solutions**:
- Clear browser cache too
- Check for proxy/CDN caching
- Verify database values directly
- Restart PHP-FPM if using it
- Check for OPcache issues

### Problem: Too Many Backups

**Automatic Cleanup**:
- System keeps 5 most recent backups
- Older backups deleted automatically
- Manual backups persist forever

**Manual Cleanup**:
```sql
DELETE FROM wp_options 
WHERE option_name LIKE 'wp_mcp_ai_settings_backup_%' 
AND option_name NOT IN (
  SELECT option_name FROM (
    SELECT option_name FROM wp_options 
    WHERE option_name LIKE 'wp_mcp_ai_settings_backup_%'
    ORDER BY option_name DESC LIMIT 5
  ) as keep
);
```

---

## Technical Details

### Backup Storage

**Location**: WordPress `wp_options` table
**Format**: Serialized PHP array
**Naming**: `wp_mcp_ai_settings_backup_[timestamp]`
**Autoload**: No (performance optimization)

**Types of Backups**:
- `wp_mcp_ai_settings_backup_[timestamp]` - Automatic on every save
- `wp_mcp_ai_settings_backup_pre_import_[timestamp]` - Before import
- `wp_mcp_ai_settings_backup_pre_reset_[timestamp]` - Before reset

### Settings Save Process

```
┌─────────────────────────────────────────┐
│ 1. User Clicks Save                     │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 2. Clear All Caches (Pre-Save)          │
│    • Static cache                       │
│    • Object cache                       │
│    • Transients                         │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 3. Read Fresh Settings from Database    │
│    • Bypasses all caches                │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 4. Create Timestamped Backup            │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 5. Sanitize New Values                  │
│    • Section-based sanitization         │
│    • Type-specific validation           │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 6. Protect Sensitive Keys               │
│    • Remove empty provider keys         │
│    • Preserve existing credentials      │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 7. Validate Merged Settings             │
│    • 7 validation checks                │
│    • Structure integrity                │
│    • Required fields                    │
└──────────────┬──────────────────────────┘
               │
               ▼
          ┌────┴────┐
          │ Valid?  │
          └────┬────┘
               │
       ┌───────┴───────┐
       │               │
      Yes              No
       │               │
       ▼               ▼
┌──────────┐    ┌─────────────┐
│ 8. Save  │    │ Show Errors │
│ Atomic   │    │ Rollback    │
└────┬─────┘    └─────────────┘
     │
     ▼
┌─────────────────────────────────────────┐
│ 9. Clear All Caches (Post-Save)         │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 10. Fire Action Hooks                   │
│     • wp_mcp_ai_settings_saved          │
└──────────────┬──────────────────────────┘
               │
               ▼
┌─────────────────────────────────────────┐
│ 11. Cleanup Old Backups (Keep 5)        │
└─────────────────────────────────────────┘
```

### Cache Hierarchy

```
Request for Settings
         │
         ▼
    ┌─────────┐
    │ Static  │  First Check: PHP static variable
    │ Cache   │  Lifetime: Current request only
    └────┬────┘
         │ Miss
         ▼
    ┌─────────┐
    │ Object  │  Second Check: WordPress object cache
    │ Cache   │  Lifetime: Until cleared or expired
    └────┬────┘
         │ Miss
         ▼
    ┌─────────┐
    │Database │  Final Source: wp_options table
    │ (Fresh) │  Always authoritative
    └─────────┘
```

### API Endpoints

**Export Settings**:
```
GET /wp-admin/admin-ajax.php?action=wp_mcp_ai_export_settings&nonce=[nonce]
Response: JSON file download
```

**Import Settings**:
```
POST /wp-admin/admin-ajax.php
Action: wp_mcp_ai_import_settings
Data: FormData with settings_file
Response: JSON {success, data: {message, imported_count}}
```

**Clear Cache**:
```
POST /wp-admin/admin-ajax.php
Action: wp_mcp_ai_clear_settings_cache
Response: JSON {success, data: {message}}
```

**Reset Settings**:
```
POST /wp-admin/admin-ajax.php
Action: wp_mcp_ai_reset_settings
Response: JSON {success, data: {message}}
```

**Health Check**:
```
POST /wp-admin/admin-ajax.php
Action: wp_mcp_ai_check_settings_health
Response: JSON {success, data: {status, issues, warnings, info}}
```

---

## Related Documentation

- [Settings Dashboard Overview](../settings/README.md)
- [Advanced Settings](../settings/advanced-settings.md)
- [Troubleshooting Guide](../../troubleshooting/settings-issues.md)
- [Security Best Practices](../../security/best-practices.md)

---

**Last Updated**: 2025-01-20  
**Version**: 1.0.0
