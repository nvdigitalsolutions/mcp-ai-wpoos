# Settings Management Quick Reference

**Quick access guide to NV oOS Settings Management features**

## Access

**Navigation**: NV oOS → Advanced → Settings Management

## Five Core Features

### 1. 🔍 Health Check
**Button**: Check Settings Health  
**Purpose**: Diagnose configuration issues  
**Shows**: Issues (red), Warnings (orange), Info (blue)

**Quick Checks**:
- ✓ Settings exist and valid
- ✓ Providers configured
- ✓ Critical fields present
- ✓ Cache status
- ✓ Backup count

### 2. 💾 Export Settings
**Button**: Export Settings (JSON)  
**Output**: `nv-oos-settings-YYYY-MM-DD-HH-MM-SS.json`  
**Contains**: All settings + metadata

**⚠️ Security**: Contains API keys - store securely!

### 3. 📤 Import Settings
**Button**: Upload & Import  
**Accepts**: `.json` files (max 5MB)  
**Safety**: Auto-backup before import

**Process**:
1. Choose file
2. Click Upload & Import
3. Confirm
4. Auto-reload on success

### 4. 🗑️ Clear Cache
**Button**: Clear All Caches  
**Clears**: Static cache, object cache, transients  
**Use when**: Settings changes don't take effect

### 5. ↩️ Reset to Defaults
**Button**: Reset All Settings  
**⚠️ WARNING**: Removes ALL settings  
**Safety**: Auto-backup before reset

## Common Workflows

### Daily Operations

**Before Making Changes**:
```
1. Export Settings (backup)
2. Make changes
3. Save
4. If issues: Import backup
```

**Settings Not Working**:
```
1. Clear Cache
2. Test
3. If still broken: Check Health
4. Review diagnostic report
```

### Migration

**Staging → Production**:
```
1. Configure on staging
2. Export from staging
3. Import to production
4. Run Health Check
5. Test functionality
```

### Troubleshooting

**Step-by-Step**:
```
1. Check Health → Identify issues
2. Clear Cache → Remove stale data
3. Export Settings → Create backup
4. Make fixes
5. Check Health Again → Verify
```

## Backup Strategy

**Automatic Backups** (No action needed):
- Every save creates backup
- Keeps 5 most recent
- Stored in database

**Manual Backups** (Recommended):
- **Daily**: If making frequent changes
- **Weekly**: For stable sites
- **Before**: Updates, major changes
- **Keep**: Multiple versions in secure location

## Security Checklist

- [ ] Never commit exports to Git
- [ ] Encrypt exports before cloud storage
- [ ] Delete exports after use
- [ ] Limit admin access
- [ ] Audit export/import operations
- [ ] Review Health Check monthly

## Error Messages

| Message | Meaning | Solution |
|---------|---------|----------|
| "Invalid JSON format" | Corrupted file | Re-export from source |
| "File too large" | > 5MB | Check for bloated data |
| "Validation failed" | Invalid settings | Review error details |
| "Permission denied" | Not admin | Login as administrator |
| "No file uploaded" | Selection missing | Choose file first |

## Status Indicators

**Health Check Results**:
- **GOOD** 🟢: All checks passed
- **WARNING** 🟡: Minor issues detected
- **CRITICAL** 🔴: Urgent problems found

**Import/Export**:
- **Success**: Green notice, auto-reload
- **Error**: Red notice, specific message
- **In Progress**: Button disabled, "Processing..."

## Keyboard Shortcuts

None currently - all operations require button clicks for safety.

## Related Commands

**Via WP-CLI** (if needed):
```bash
# Export settings
wp option get wp_mcp_ai_settings --format=json > backup.json

# Clear cache
wp cache flush

# View backups
wp option list | grep wp_mcp_ai_settings_backup
```

## Emergency Recovery

**If Import Breaks Site**:
1. Find backup in database: `wp_mcp_ai_settings_backup_pre_import_*`
2. Copy settings array
3. Update `wp_mcp_ai_settings` option manually
4. Clear all caches
5. Reload site

**If Reset Was Accidental**:
1. Find: `wp_mcp_ai_settings_backup_pre_reset_*`
2. Export that backup to JSON
3. Import via UI
4. Verify settings restored

## Performance Notes

- **Export**: Instant (generates JSON)
- **Import**: 1-3 seconds (validation + save)
- **Health Check**: 1-2 seconds (6 checks)
- **Clear Cache**: < 1 second
- **Reset**: 1-2 seconds + reload

## Limits & Constraints

- **File Size**: Max 5MB for imports
- **Backup Count**: Keeps 5 automatic backups
- **File Types**: JSON only
- **Access**: Administrators only
- **Concurrent Imports**: One at a time

## Best Practices

**DO**:
- ✓ Export before major changes
- ✓ Test imports on staging first
- ✓ Run Health Check regularly
- ✓ Keep backups off-site
- ✓ Clear cache after imports
- ✓ Document what changed

**DON'T**:
- ✗ Share export files publicly
- ✗ Import without backup first
- ✗ Reset without good reason
- ✗ Skip health checks
- ✗ Ignore validation errors
- ✗ Store exports in Git

## Integration with Other Features

**Works With**:
- All settings tabs and subtabs
- Provider configurations
- Tool orchestration settings
- Advanced system settings
- Integration settings

**Preserves**:
- API keys and credentials
- Provider priorities
- Tool limits and multipliers
- Orchestration presets
- Custom configurations

## Support

**Documentation**:
- Full Guide: `docs/guides/admin/settings-management.md`
- Settings Dashboard: `docs/guides/admin/settings/README.md`
- Troubleshooting: `docs/troubleshooting/settings-issues.md`

**Getting Help**:
1. Check documentation first
2. Run Health Check for diagnostics
3. Export settings for support analysis
4. Check GitHub issues
5. Contact support with logs

---

**Version**: 1.0.0  
**Last Updated**: 2025-01-20
