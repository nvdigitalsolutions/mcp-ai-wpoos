# Remote Connection Migration Guide

## Overview

This guide explains how to migrate existing API credentials from plugin settings to the new Remote Site Connections system.

## Why Migrate?

The Remote Site Connections system provides several advantages over storing credentials in plugin settings:

1. **Multiple Instances**: Create separate connections for different environments (staging, production) or different accounts
2. **Per-Assistant Control**: Enable/disable specific connections for specific assistants
3. **Centralized Management**: All external connections in one place with health monitoring
4. **Better Security**: Encrypted credentials with connection-level access control
5. **Improved Organization**: Clear separation between connection types with dedicated UI

## Supported Services

The migration script automatically detects and migrates credentials for:

- **iSAMS** (School Management System)
- **Flowhub** (POS/Retail System)
- **PayHere** (Payment Gateway)
- **QuickBooks** (Accounting Software)

## Prerequisites

Before running the migration:

1. Ensure you have existing API credentials configured in **Settings → NV oOS → Connections**
2. Back up your WordPress database (optional but recommended)
3. Make sure you have SSH/command-line access to your WordPress installation

## Migration Steps

### 1. Dry Run (Recommended First Step)

Run the script in dry-run mode to see what would be migrated without making any changes:

```bash
cd /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos
php bin/migrate-settings-to-connections.php --dry-run
```

This will output:
- Which services have credentials configured
- What connections would be created
- Which services will be skipped (no credentials found)

### 2. Run the Migration

Once you've verified the dry-run output looks correct, run the actual migration:

```bash
php bin/migrate-settings-to-connections.php
```

### 3. Verbose Output (Optional)

For detailed information during migration, add the `--verbose` flag:

```bash
php bin/migrate-settings-to-connections.php --verbose
```

### 4. Verify the Connections

After migration:

1. Go to **Settings → NV oOS → Remote Sites** in your WordPress admin
2. Verify that connections were created for each service
3. Test each connection using the "Test" button
4. Update connection names if desired (default names are auto-generated)

## What Gets Migrated

### iSAMS
- **From Settings**: `isams_api_url`, `isams_api_key`, `isams_api_secret`
- **To Connection**: iSAMS connection with API credentials
- **Connection Name**: "iSAMS School Management"

### Flowhub
- **From Settings**: `flowhub_api_key`, `flowhub_client_id`, `flowhub_client_secret`, `flowhub_location_id`
- **To Connection**: Flowhub connection with all credentials
- **Connection Name**: "Flowhub POS"
- **Default URL**: https://api.flowhub.com

### PayHere
- **From Settings**: `payhere_app_id`, `payhere_app_secret`, `payhere_sandbox_mode`
- **To Connection**: PayHere connection with app credentials
- **Connection Name**: "PayHere Payment Gateway"
- **Default URL**: https://www.payhere.lk
- **Preserves**: Sandbox mode setting

### QuickBooks
- **From Settings**: `quickbooks_client_id`, `quickbooks_client_secret`, `quickbooks_company_id`
- **To Connection**: QuickBooks connection with OAuth credentials
- **Connection Name**: "QuickBooks Accounting"
- **Default URL**: https://quickbooks.api.intuit.com

## After Migration

### Original Settings

The migration script **DOES NOT** remove original settings from the plugin configuration. This ensures:

1. **Backward Compatibility**: Existing tools still work using settings fallback
2. **Safety**: You can verify connections work before removing old settings
3. **Reversibility**: Easy to roll back if needed

### Removing Old Settings (Optional)

Once you've verified the connections work correctly, you can manually remove the old settings:

1. Go to **Settings → NV oOS → Connections**
2. Clear the credential fields for migrated services
3. Save the settings

### Using the New Connections

After migration, tools will automatically use the new connection system when you provide a `connection_id` parameter. The tools still fall back to settings if no connection is specified, maintaining full backward compatibility.

## Troubleshooting

### Connection Already Exists

If you see "Connection already exists" messages, the script detected existing connections for those services and skipped creating duplicates. This is normal if you've already manually created connections.

### Missing Credentials

If a service is skipped with "No credentials found", it means the required API keys/credentials are not configured in your plugin settings. You'll need to configure them in **Settings → NV oOS → Connections** first.

### Permission Errors

The script requires WordPress admin privileges. If running via CLI, make sure you're running as a user with appropriate permissions.

### Failed to Create Connection

If you see errors about failed connection creation, check:
1. That all required fields for the connection type are present
2. That URLs are valid (must start with http:// or https://)
3. The error message for specific validation failures

## Manual Connection Creation

If you prefer not to use the migration script, you can manually create connections:

1. Go to **Settings → NV oOS → Remote Sites**
2. Click **Add New Connection**
3. Select the appropriate connection type from the dropdown
4. Fill in all required fields (marked with *)
5. Click **Add Connection**

Each connection type has specific required fields displayed when you select it.

## Support

For issues or questions:

- Check the [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- Review the [Remote Sites documentation](../docs/features/remote-sites.md)
- Contact support at NV Digital Solutions

## See Also

- [Remote Sites Documentation](../docs/features/remote-sites.md)
- [Tool Reference](../docs/tool-reference.md)
- [Migration Status](../MIGRATION_STATUS.md)
