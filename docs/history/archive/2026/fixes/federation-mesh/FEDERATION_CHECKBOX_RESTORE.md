# Federation Checkbox Restoration Summary

**Date**: 2026-02-01  
**Branch**: copilot/move-federation-checkbox  
**Issue**: Restore TWO separate federation checkboxes to Advanced section

## Problem Understanding

The user clarified that the old Tools & Features page originally had **TWO separate checkboxes** for federation functionality:

1. **`enable_federation`** - General federation features
   - Controlled: Well-known endpoints (/.well-known/ai-peer, /.well-known/jwks.json)
   - Purpose: Enable peer discovery via well-known endpoints
   - Status: Was in Tools → Features, worked correctly

2. **`enable_federation_directory`** - Directory service features
   - Controlled: AI Peers CPT, Directory REST API, peer verification cron
   - Purpose: Enable full federation directory functionality
   - Status: Was in Advanced → Federation Mesh (possibly also in Tools)

### What Happened in PR #3421

PR #3421 attempted to consolidate these settings:
- Removed `enable_federation` from Tools → Features
- Kept only `enable_federation_directory` in Advanced → Federation Mesh
- Made `enable_federation_directory` control BOTH feature sets

### The Issue

After PR #3421:
- Only one checkbox remained (`enable_federation_directory`)
- User reported it didn't work properly when mesh was enabled
- User wanted the original TWO checkboxes restored, but in Advanced section

## Solution Implemented

Restored BOTH checkboxes to **Advanced → Federation Mesh** section, giving users granular control:

### 1. Enable Federation (Well-Known Endpoints)
```php
'enable_federation' => array(
    'type'           => 'checkbox',
    'label'          => __( 'Enable Federation', 'mcp-ai-wpoos' ),
    'checkbox_label' => __( 'Enable federated discovery', 'mcp-ai-wpoos' ),
    'description'    => __( 'Enables federation features including well-known endpoints...', 'mcp-ai-wpoos' ),
    'default'        => false,
),
```

**Controls:**
- Well-known endpoints: `/.well-known/ai-peer`
- JWKS endpoint: `/.well-known/jwks.json`
- Basic peer discovery features

### 2. Enable Federation Directory (Full Directory Service)
```php
'enable_federation_directory' => array(
    'type'           => 'checkbox',
    'label'          => __( 'Enable Federation Directory', 'mcp-ai-wpoos' ),
    'checkbox_label' => __( 'Enable federation directory service', 'mcp-ai-wpoos' ),
    'description'    => __( 'Allows this site to participate in the federation directory...', 'mcp-ai-wpoos' ),
    'default'        => false,
),
```

**Controls:**
- AI Peers Custom Post Type
- Federation Directory REST API
- Peer verification cron job
- Full directory participation

## Files Modified

### Core Settings Files
1. **includes/admin/sections/class-wp-mcp-ai-section-advanced.php**
   - Added both checkbox definitions
   - Added both to federation_mesh fields array
   - Updated status display to show 3 items (Mesh, Federation, Federation Directory)
   - Updated render_federation_mesh() to track both settings

2. **includes/admin/class-wp-mcp-ai-simple-settings-saver.php**
   - Added `enable_federation_directory` to checkbox fields list
   - Both settings now save correctly

3. **includes/admin/class-wp-mcp-ai-admin-settings-base.php**
   - Added `enable_federation_directory` to defaults
   - Updated mesh API key generation to check all 3 settings:
     - `enable_mesh`
     - `enable_federation`
     - `enable_federation_directory`

4. **includes/class-wp-mcp-ai-federation-settings.php**
   - Added `enable_federation_directory` to defaults array
   - Fixed `is_directory_enabled()` to check `enable_federation_directory`
   - Kept `is_federation_enabled()` checking `enable_federation`

5. **includes/admin/class-wp-mcp-ai-mcp-server-diagnostic.php**
   - Added Federation Directory row to diagnostic display
   - Shows AI peer count when directory is enabled
   - Updated condition to show section if any federation setting is enabled

## Status Display

The Advanced → Federation Mesh page now shows a clear status for all features:

```
Current Status
├── Mesh Computing: [Enabled/Disabled]
├── Federation (Well-Known Endpoints): [Enabled/Disabled]
├── Federation Directory: [Enabled/Disabled]
└── Registered AI Peers: [count]
```

## Helper Methods

Two helper methods available in `WP_MCP_AI_Federation_Settings`:

```php
// Check if general federation is enabled (well-known endpoints)
WP_MCP_AI_Federation_Settings::is_federation_enabled()

// Check if directory service is enabled (AI Peers, REST API)
WP_MCP_AI_Federation_Settings::is_directory_enabled()
```

## Use Cases

### Enable Only Well-Known Endpoints
- Check: ✓ Enable Federation
- Uncheck: ☐ Enable Federation Directory
- Result: Peer discovery via well-known endpoints, no directory participation

### Enable Full Federation
- Check: ✓ Enable Federation
- Check: ✓ Enable Federation Directory
- Result: Complete federation functionality

### Enable Only Directory (Not Recommended)
- Uncheck: ☐ Enable Federation
- Check: ✓ Enable Federation Directory
- Result: Directory features active but no well-known endpoints

## Migration Notes

### For Existing Installations

After this update:
1. Users with `enable_federation_directory=true` from PR #3421 will keep that setting
2. `enable_federation` will be `false` by default (was removed)
3. To restore full original functionality, users should enable BOTH:
   - ✓ Enable Federation
   - ✓ Enable Federation Directory

### For Fresh Installations

Both settings default to `false`. Users can enable:
- Just Federation for basic peer discovery
- Both for complete federation functionality

## Testing

To verify the implementation:

1. Navigate to: `wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh`
2. Observe TWO separate checkboxes:
   - "Enable Federation"
   - "Enable Federation Directory"
3. Check one or both
4. Click "Save Settings"
5. Verify status badges update correctly
6. Check that mesh API key is generated when any setting is enabled

## Benefits

✅ **Granular Control**: Users can enable features independently  
✅ **Clear Labeling**: Each checkbox explains what it controls  
✅ **Correct Behavior**: Each setting controls its specific features  
✅ **Status Visibility**: Dashboard shows status of all 3 features  
✅ **Backward Compatible**: Existing settings preserved  

## Documentation References

- Original consolidation: `FEDERATION_SETTINGS_CONSOLIDATION.md`
- Status fix: `docs/fixes/FEDERATION_DIRECTORY_STATUS_FIX.md`
- Implementation details: `FEDERATION_CHECKBOX_FIX.md`
- Current document: `FEDERATION_CHECKBOX_RESTORE.md`
