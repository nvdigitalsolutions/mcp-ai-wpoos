# AI Peer CPT Creation Fix

## Issue
AI Peer CPT (Custom Post Type) entries were not being created when new mesh peers were added through:
1. **Federation & Mesh Settings Page** (Advanced Settings → Federation & Mesh)
2. **Remote Sites** (Pro addon)

Users would add mesh peers, but the corresponding `ai_peer` CPT entries would not appear in the AI Peers admin menu.

## Root Cause

### The Bug
In `/includes/class-wp-mcp-ai-federation.php` line 114:

```php
// BEFORE (BUG):
if ( $is_mesh_enabled && $is_directory_enabled ) {
    $this->mesh_peer_sync = new WP_MCP_AI_Mesh_Peer_Sync();
}
```

The `WP_MCP_AI_Mesh_Peer_Sync` class was only instantiated when **BOTH** conditions were true:
- `$is_mesh_enabled` = true (Enable Mesh Computing checkbox)
- `$is_directory_enabled` = true (Enable Federation Directory checkbox)

### Why This Is Wrong
Mesh computing and federation directory are **independent features**:
- **Mesh Computing**: Distribute AI workloads across peer sites
- **Federation Directory**: Discover peer sites via a centralized directory

Users should be able to use mesh peers **without** enabling the federation directory. The mesh peer sync should work independently.

### Impact
- Users with only mesh enabled: No CPT entries created
- Users had to enable BOTH features even if they only needed mesh
- Remote Sites workflow broken for mesh-only configurations

## Solution

### The Fix
Changed the condition to only require mesh enabled:

```php
// AFTER (FIXED):
if ( $is_mesh_enabled ) {
    $this->mesh_peer_sync = new WP_MCP_AI_Mesh_Peer_Sync();
}
```

### How It Works Now

**Scenario 1: Mesh Only**
```
User enables: ✅ Enable Mesh Computing
User disables: ❌ Enable Federation Directory

Result: ✅ Mesh peer sync initializes
        ✅ CPT entries created when peers added
```

**Scenario 2: Both Enabled**
```
User enables: ✅ Enable Mesh Computing
User enables: ✅ Enable Federation Directory

Result: ✅ Mesh peer sync initializes
        ✅ CPT entries created when peers added
        ✅ Federation directory also works
```

**Scenario 3: Mesh Disabled**
```
User disables: ❌ Enable Mesh Computing

Result: ❌ Mesh peer sync does NOT initialize (correct)
        ❌ No CPT entries created (correct)
```

## CPT Creation Flow

### 1. User Adds Mesh Peer

**Via Federation & Mesh Settings:**
```
1. User goes to Advanced Settings → Federation & Mesh
2. Adds peer in mesh_peer_sites table
3. Clicks "Save Changes"
4. WordPress triggers: update_option_wp_mcp_ai_settings
5. Mesh Peer Sync receives hook
6. Creates/updates ai_peer CPT entry
```

**Via Remote Sites (Pro):**
```
1. User goes to NV oOS Pro → Remote Sites
2. Adds "Mesh Peer (Distributed AI)" connection
3. Clicks "Save Connection"
4. Pro triggers: wp_mcp_ai_pro_remote_site_saved
5. Bidirectional Sync writes to mesh_peer_sites
6. WordPress triggers: update_option_wp_mcp_ai_settings
7. Mesh Peer Sync receives hook
8. Creates/updates ai_peer CPT entry
```

### 2. CPT Structure

Each ai_peer CPT entry contains:

**Post Fields:**
- `post_title`: Peer name (e.g., "Test Peer Site")
- `post_type`: `ai_peer`
- `post_status`: `publish`

**Post Meta:**
- `_wp_mcp_ai_mesh_peer_id`: Unique ID (mesh_<md5(url)>)
- `_wp_mcp_ai_connection_type`: "mesh"
- `_wp_mcp_ai_peer_url`: Peer URL
- `_wp_mcp_ai_peer_api_key`: API key (encrypted)
- `_wp_mcp_ai_health_status`: healthy/degraded/down
- `_wp_mcp_ai_last_verified`: Timestamp

### 3. Badge Display

In the AI Peers admin list, mesh peers show with a **MESH** badge to distinguish them from federation peers.

## Testing

### Test Suite Created
File: `/tests/test-mesh-peer-cpt-creation.php`

**Tests:**
1. `test_mesh_sync_initializes_without_federation_directory()`
   - Verifies mesh sync works with mesh-only configuration

2. `test_cpt_created_when_mesh_peer_added()`
   - Verifies CPT is created when peer added via settings
   - Checks post title matches peer name

3. `test_cpt_created_with_both_enabled()`
   - Verifies CPT creation still works when both features enabled
   - Ensures backward compatibility

4. `test_cpt_not_created_when_mesh_disabled()`
   - Verifies mesh sync doesn't initialize when mesh disabled
   - Ensures no CPT creation when mesh is off

### Manual Testing Procedure

**Test 1: Mesh Only Configuration**
```bash
1. Go to Settings → NV oOS → Advanced Settings → Federation & Mesh
2. ✅ Check "Enable Mesh Computing"
3. ❌ Uncheck "Enable Federation Directory"
4. Click "Save Changes"
5. Add a mesh peer in the table
6. Click "Save Changes"
7. Go to AI Peers menu
8. ✅ Verify peer appears with MESH badge
```

**Test 2: Remote Sites Workflow**
```bash
1. Go to NV oOS Pro → Remote Sites
2. Click "Add Connection"
3. Select "Mesh Peer (Distributed AI)"
4. Enter peer details
5. Click "Save Connection"
6. Go to AI Peers menu
7. ✅ Verify peer appears with MESH badge
```

**Test 3: Both Features Enabled**
```bash
1. Enable both Mesh Computing AND Federation Directory
2. Add mesh peers via either interface
3. ✅ Verify CPT entries created
4. ✅ Verify federation features also work
```

## Files Changed

### Primary Fix
**`/includes/class-wp-mcp-ai-federation.php`**
- Line 114: Removed `&& $is_directory_enabled` condition
- Updated comment to reflect independence from federation directory

### Tests
**`/tests/test-mesh-peer-cpt-creation.php`** (new)
- Comprehensive test suite for CPT creation scenarios
- 4 test cases covering all configuration combinations

## Related Components

### Mesh Peer Sync
**File:** `/includes/class-wp-mcp-ai-mesh-peer-sync.php`

**Responsibilities:**
- Hooks into `update_option_wp_mcp_ai_settings`
- Compares old vs new mesh_peer_sites array
- Creates/updates/deletes ai_peer CPT entries accordingly
- Validates peer data (name, URL, API key)

### Bidirectional Sync (Pro)
**File:** `/addons/pro/includes/class-wp-mcp-ai-pro-mesh-peer-bidirectional-sync.php`

**Responsibilities:**
- Syncs Remote Sites ↔ mesh_peer_sites
- Prevents infinite loops with `$syncing` flag
- Triggers CPT creation via mesh peer sync

### AI Peer CPT
**File:** `/includes/class-wp-mcp-ai-ai-peer-cpt.php`

**Responsibilities:**
- Registers `ai_peer` custom post type
- Defines meta keys and fields
- Handles admin UI (list table, badges)

## Migration & Compatibility

### Existing Installations
- ✅ No breaking changes
- ✅ Existing mesh peers continue to work
- ✅ Users with both features enabled: No change
- ✅ Users with mesh-only: Will now see CPT entries after plugin update

### New Installations
- ✅ Works correctly out of the box
- ✅ Mesh peers can be used independently
- ✅ Federation directory optional

### Upgrade Path
1. Update plugin to latest version
2. Go to Settings → NV oOS
3. If mesh was enabled but CPT entries missing:
   - Resave mesh peer settings to trigger sync
   - OR: Add/edit a mesh peer to trigger sync
4. CPT entries will be created automatically

## Security Considerations

- ✅ No new security vulnerabilities introduced
- ✅ Capability checks remain in place
- ✅ API keys remain encrypted
- ✅ URL validation still enforced

## Performance

- ✅ No performance impact
- ✅ Mesh sync only runs on settings save (not on every request)
- ✅ Efficient hook usage
- ✅ No additional database queries

## Known Limitations

None. This fix resolves the limitation that previously prevented mesh-only configurations.

## Future Enhancements

Potential improvements for future versions:
1. Manual CPT resync button in admin
2. Bulk CPT creation for existing mesh peers
3. CPT health status monitoring
4. Automated CPT cleanup for deleted peers

## Support & Troubleshooting

### If CPT Entries Still Not Created

**Check 1: Mesh Enabled?**
```bash
Settings → NV oOS → Advanced Settings → Federation & Mesh
✅ Verify "Enable Mesh Computing" is checked
```

**Check 2: Valid Peer Data?**
```bash
- Name: Must not be empty
- URL: Must be valid URL format (https://...)
- API Key: Optional but recommended
```

**Check 3: WordPress Hooks Working?**
```bash
- Check if other settings save correctly
- Test with WP Debug enabled
- Check error logs
```

**Check 4: CPT Registered?**
```bash
Go to AI Peers menu
- If menu doesn't exist: CPT not registered
- Check if federation was initialized
```

### Debug Logging

Enable debug logging:
```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
```

Look for:
```
[NV oOS] Mesh peer sync initializing
[NV oOS] Creating CPT for mesh peer: <peer_name>
[NV oOS] CPT ID: <post_id>
```

## Conclusion

This fix ensures that mesh computing can be used independently of the federation directory, as originally intended. Users can now add mesh peers and see corresponding CPT entries regardless of their federation directory configuration.

The fix is minimal, focused, and maintains backward compatibility with all existing configurations.

---

**Status:** ✅ Fixed  
**Version:** Next release  
**Priority:** High (affects core mesh functionality)  
**Backward Compatible:** Yes  
**Breaking Changes:** None
