# Federation Settings Consolidation Summary

**Date**: 2026-02-01  
**Branch**: copilot/move-federation-settings  
**Issue**: Enable Federation Directory service setting consolidation

## Problem

Previously, there were **two separate federation settings** that confused users:

1. **`enable_federation`** - Located in Tools → Features
   - Controlled: Well-known endpoints (/.well-known/ai-peer, /.well-known/jwks.json)
   - Description: "Enable federated discovery"

2. **`enable_federation_directory`** - Located in Advanced → Federation Mesh
   - Controlled: Directory service (AI Peers CPT, Directory REST API, peer verification cron)
   - Description: "Enable federation directory service"

### User Confusion
- Users didn't understand which setting to enable
- The `enable_federation` setting in Tools caused the status badge in Advanced to show "Enabled" even when `enable_federation_directory` was disabled
- This was a known bug documented in `docs/fixes/FEDERATION_DIRECTORY_STATUS_FIX.md`

## Solution

**Consolidated to a single setting:** `enable_federation_directory` in Advanced → Federation Mesh

When enabled, this single setting now controls ALL federation features:
- ✅ Well-known endpoints (/.well-known/ai-peer, /.well-known/jwks.json)
- ✅ AI Peers Custom Post Type
- ✅ Federation Directory REST API
- ✅ Peer verification cron job

## Changes Made

### Files Modified

1. **includes/admin/sections/class-wp-mcp-ai-section-tools.php**
   - Removed `enable_federation` setting definition (lines 359-365)
   - Removed `enable_federation` from features fields array (line 764)

2. **includes/admin/class-wp-mcp-ai-simple-settings-saver.php**
   - Removed `enable_federation` from known fields list (line 58)

3. **includes/admin/class-wp-mcp-ai-admin-settings-base.php**
   - Removed `enable_federation` from default settings (line 405)

4. **includes/admin/sections/class-wp-mcp-ai-section-overview.php**
   - Updated federation status check to use `enable_federation_directory` (line 399)

5. **includes/admin/sections/class-wp-mcp-ai-section-advanced.php**
   - Removed unused `$federation_enabled` variable (line 1726)

6. **includes/class-wp-mcp-ai-federation-settings.php**
   - Updated `is_federation_enabled()` to check `enable_federation_directory` (line 331)
   - Removed `enable_federation` from defaults array (line 284)
   - Removed old `enable_federation` field registration (lines 59-65)
   - Removed `render_enable_federation_field()` method (lines 119-139)

7. **includes/class-wp-mcp-ai-federation.php**
   - Consolidated feature loading in `maybe_load_federation_features()` (lines 79-95)
   - Now loads both well-known endpoints AND directory features when `enable_federation_directory` is enabled
   - Updated `on_activation()` to use single setting check (lines 107-119)

8. **includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php**
   - Updated all references to use `enable_federation_directory`

9. **Test Files**
   - Updated 6 test files to use `enable_federation_directory`
   - Fixed duplicate assertions from search-and-replace

### Code Quality

✅ **PHP Syntax**: All modified files pass PHP linting  
✅ **Code Review**: Completed with issues fixed  
✅ **Security Scan**: CodeQL check passed (no security issues)

## Migration Notes

### For Existing Installations

If a site previously had `enable_federation` set to `true` in Tools → Features:
- The setting no longer exists in the database schema
- Users need to enable `enable_federation_directory` in Advanced → Federation Mesh to restore federation functionality
- This is the correct behavior - users should consciously choose to enable the full federation directory service

### For New Installations

- Only one federation setting exists: `enable_federation_directory` in Advanced → Federation Mesh
- When enabled, all federation features work automatically

## Testing Recommendations

1. **Enable Federation Directory**:
   - Navigate to Advanced → Federation Mesh
   - Check "Enable Federation Directory"
   - Save Settings

2. **Verify Features**:
   - AI Peers menu appears in WordPress admin sidebar
   - Well-known endpoints are accessible: `/.well-known/ai-peer` and `/.well-known/jwks.json`
   - Status badge in Advanced shows "Enabled" (green)

3. **Disable Federation Directory**:
   - Uncheck "Enable Federation Directory"
   - Save Settings
   - AI Peers menu should be hidden
   - Status badge shows "Disabled" (orange)

## Benefits

✅ **Simpler UX**: One setting instead of two  
✅ **Less Confusion**: Clear what federation does  
✅ **Bug Fix**: Status badge now works correctly  
✅ **Cleaner Code**: Removed duplicate logic  
✅ **Better Organization**: Federation settings all in Advanced section

## Commits

1. `782f428` - Initial plan
2. `dbcf2f6` - Remove enable_federation setting, consolidate to enable_federation_directory
3. `2c3a83f` - Remove legacy enable_federation field registration and update tests
4. `22d00d0` - Fix duplicate test assertions from search-and-replace

## Related Documentation

- `docs/fixes/FEDERATION_DIRECTORY_STATUS_FIX.md` - Documents the original bug
- `docs/guides/admin/FEDERATION_SETUP_GUIDE.md` - User guide for federation setup
