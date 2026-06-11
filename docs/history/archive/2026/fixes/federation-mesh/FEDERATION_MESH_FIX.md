# Federation Mesh Fix - Well-Known Endpoint Loading

**Date**: 2026-02-01  
**Branch**: `copilot/fix-mesh-enablement-issue`  
**Issue**: Federation mesh showing disabled and keys not generated even with both checkboxes enabled

## Problem Statement

The user reported that the federation mesh was showing as disabled at:
https://bots.nvdigital.solutions/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=advanced&subtab=federation_mesh

Even with both "Enable Federation" and "Enable Federation Directory" checkboxes enabled, the mesh appeared disabled and keys were not being generated.

Additionally, the "Enable Mesh Computing" setting in Tools → Features was not clearly integrated with the federation settings.

## Root Cause

1. The code in `class-wp-mcp-ai-federation.php` was only checking `is_directory_enabled()` (which looks at `enable_federation_directory`) to determine whether to load the well-known endpoints handler.

2. The mesh inbound API key display section only showed when `enable_mesh` was true, not when federation settings were enabled.

3. The descriptions didn't clearly explain how the three settings work together.

This meant that:
- If only `enable_federation` was enabled → well-known endpoints were NOT loaded
- If only `enable_federation_directory` was enabled → well-known endpoints WERE loaded
- The mesh key generation worked correctly for all three settings (already had OR logic)
- The mesh key was only displayed when `enable_mesh` was enabled, even though it was generated for federation settings too

## Solution Implemented

### 1. Updated `maybe_load_federation_features()` in `class-wp-mcp-ai-federation.php`

**Before:**
```php
$is_directory_enabled = WP_MCP_AI_Federation_Settings::is_directory_enabled();

if ( $is_directory_enabled ) {
    // Load well-known endpoints
    $this->wellknown_handler = new WP_MCP_AI_Federation_WellKnown( $this->registry );
    
    // Load directory features
    $this->peer_cpt_handler = new WP_MCP_AI_AI_Peer_CPT();
    $this->directory_rest_handler = new WP_MCP_AI_Federation_Directory_REST();
    
    // Schedule cron
    // ...
}
```

**After:**
```php
$is_federation_enabled = WP_MCP_AI_Federation_Settings::is_federation_enabled();
$is_directory_enabled  = WP_MCP_AI_Federation_Settings::is_directory_enabled();

// Load well-known endpoints if either federation or directory is enabled
if ( $is_federation_enabled || $is_directory_enabled ) {
    $this->wellknown_handler = new WP_MCP_AI_Federation_WellKnown( $this->registry );
}

// Load directory features only if directory is enabled
if ( $is_directory_enabled ) {
    $this->peer_cpt_handler = new WP_MCP_AI_AI_Peer_CPT();
    $this->directory_rest_handler = new WP_MCP_AI_Federation_Directory_REST();
    // Schedule cron
    // ...
}
```

### 2. Updated `on_activation()` in `class-wp-mcp-ai-federation.php`

Now flushes rewrite rules when either `enable_federation` OR `enable_federation_directory` is enabled.

### 3. Updated `maybe_flush_rewrite_rules()` in `class-wp-mcp-ai-federation-settings.php`

Now flushes rewrite rules when either `enable_federation` OR `enable_federation_directory` setting changes.

### 4. Updated field descriptions in `class-wp-mcp-ai-section-advanced.php`

Clarified that:
- `enable_federation` controls well-known endpoints only
- `enable_federation_directory` controls the full directory service (AI Peers CPT, REST API, cron)

### 5. Updated `enable_mesh` description in `class-wp-mcp-ai-section-tools.php`

Added reference to Advanced → Federation & Mesh for configuring mesh peer sites.

### 6. Updated mesh inbound API key display logic in `class-wp-mcp-ai-section-advanced.php`

Changed the condition from `if ( $mesh_enabled )` to `if ( $mesh_enabled || $federation_enabled || $directory_enabled )` so the key is displayed when any of the three settings are enabled.

## Three Settings Explained

### `enable_mesh` (Tools → Features)
**Label:** Enable Mesh Computing  
**Controls:**
- Mesh computing features (distributed workload processing)
- Auto-generates `mesh_inbound_api_key`

**Does NOT control:**
- Well-known endpoints
- AI Peers Custom Post Type
- Directory REST API

**Use case:** Enable when you want to participate in mesh computing networks for distributed AI processing.

### `enable_federation` (Advanced → Federation & Mesh)
**Label:** Enable Federation  
**Controls:**
- Well-known endpoints: `/.well-known/ai-peer` and `/.well-known/jwks.json`
- Auto-generates `mesh_inbound_api_key`

**Does NOT control:**
- AI Peers Custom Post Type
- Directory REST API
- Peer verification cron job

**Use case:** Enable when you want to publish your site's capabilities via well-known endpoints but don't need to manage a directory of other peers.

### `enable_federation_directory` (Advanced → Federation & Mesh)
**Label:** Enable Federation Directory  
**Controls:**
- AI Peers Custom Post Type (for managing federated peers)
- Directory REST API (for peer registration and discovery)
- Peer verification cron job (hourly health checks)
- Well-known endpoints (automatically enabled when directory is enabled)
- Auto-generates `mesh_inbound_api_key`

**Use case:** Enable when you want full federation capabilities including managing a directory of other AI peers.

## Setting Combinations

| enable_mesh | enable_federation | enable_federation_directory | Well-Known | AI Peers CPT | API Key | Mesh Computing |
|------------|-------------------|----------------------------|------------|--------------|---------|----------------|
| ❌         | ❌               | ❌                         | ❌         | ❌           | ❌      | ❌             |
| ✅         | ❌               | ❌                         | ❌         | ❌           | ✅      | ✅             |
| ❌         | ✅               | ❌                         | ✅         | ❌           | ✅      | ❌             |
| ❌         | ❌               | ✅                         | ✅         | ✅           | ✅      | ❌             |
| ✅         | ✅               | ❌                         | ✅         | ❌           | ✅      | ✅             |
| ✅         | ❌               | ✅                         | ✅         | ✅           | ✅      | ✅             |
| ❌         | ✅               | ✅                         | ✅         | ✅           | ✅      | ❌             |
| ✅         | ✅               | ✅                         | ✅         | ✅           | ✅      | ✅             |

**Recommended configurations:**
- **Full federation:** All three enabled
- **Federation discovery only:** `enable_federation` only
- **Mesh computing only:** `enable_mesh` only
- **Lightweight federation:** `enable_mesh` + `enable_federation`

## Mesh Key Generation

The `mesh_inbound_api_key` is automatically generated when ANY of the following are enabled:
1. `enable_mesh` (in Tools → Features)
2. `enable_federation` (in Advanced → Federation & Mesh)
3. `enable_federation_directory` (in Advanced → Federation & Mesh)

This OR logic was already correct and did not need modification.

## Mesh Key Display

The mesh inbound API key section is now displayed when ANY of the following are enabled:
1. `enable_mesh` (Mesh Computing)
2. `enable_federation` (Federation)
3. `enable_federation_directory` (Federation Directory)

This ensures users can see and copy the key regardless of which feature they've enabled.

## Testing

### Manual Testing Steps

1. **Test enable_mesh alone:**
   - Enable only "Enable Mesh Computing" in Tools → Features
   - Go to Advanced → Federation & Mesh
   - Verify mesh_inbound_api_key is displayed
   - Verify well-known endpoints are NOT accessible

2. **Test enable_federation alone:**
   - Disable "Enable Mesh Computing"
   - Enable only "Enable Federation" in Advanced → Federation & Mesh
   - Save settings
   - Verify well-known endpoints are accessible:
     - `/.well-known/ai-peer` should return JSON
     - `/.well-known/jwks.json` should return JSON
   - Verify mesh_inbound_api_key is displayed
   - Verify AI Peers menu does NOT appear in WordPress admin

3. **Test enable_federation_directory alone:**
   - Disable "Enable Federation"
   - Enable only "Enable Federation Directory"
   - Save settings
   - Verify well-known endpoints are accessible
   - Verify AI Peers menu DOES appear in WordPress admin
   - Verify mesh_inbound_api_key is displayed
   - Verify directory REST API is accessible

4. **Test all three enabled:**
   - Enable all checkboxes
   - Save settings
   - Verify all features work
   - Verify mesh status shows "Enabled" for all three

### Automated Testing

The existing test in `tests/test-mesh-api-key-generation.php` already validates that:
- Keys are generated when `enable_federation` is enabled
- Keys are generated when `enable_mesh` is enabled
- Keys are preserved on subsequent saves
- Keys are NOT generated when all settings are disabled

These tests should continue to pass with the new logic.

## Files Modified

1. `includes/class-wp-mcp-ai-federation.php` (28 lines changed)
2. `includes/class-wp-mcp-ai-federation-settings.php` (17 lines changed)
3. `includes/admin/sections/class-wp-mcp-ai-section-advanced.php` (6 lines changed - descriptions and display logic)
4. `includes/admin/sections/class-wp-mcp-ai-section-tools.php` (1 line changed - description)

## Commits

1. `372bf6f` - Fix federation mesh well-known endpoint loading logic
2. `e3b4155` - Update field descriptions and add fix documentation
3. `ed07a23` - Add comprehensive testing guide for federation mesh fix
4. (pending) - Integrate enable_mesh with federation display logic

## Migration Impact

### For Existing Users

**Scenario 1:** User has only `enable_federation` enabled
- **Before:** Well-known endpoints were NOT loaded (broken), key was NOT displayed
- **After:** Well-known endpoints ARE loaded (fixed), key IS displayed (fixed)
- **Action required:** None - behavior is now correct

**Scenario 2:** User has only `enable_federation_directory` enabled
- **Before:** Well-known endpoints were loaded, key was NOT displayed
- **After:** Well-known endpoints are loaded (no change), key IS displayed (fixed)
- **Action required:** None

**Scenario 3:** User has only `enable_mesh` enabled
- **Before:** Well-known endpoints were NOT loaded, key WAS displayed (correct)
- **After:** Well-known endpoints are NOT loaded (no change), key IS displayed (no change)
- **Action required:** None

**Scenario 4:** User has multiple settings enabled
- **Before:** Behavior varied depending on which settings were enabled
- **After:** All features work as expected based on the combination
- **Action required:** None

### Breaking Changes

None. This is a bug fix that makes the behavior match the intended design.

## Related Documentation

- `FEDERATION_CHECKBOX_FIX.md` - Previous attempt to consolidate settings
- `docs/guides/admin/FEDERATION_SETUP_GUIDE.md` - User guide for federation setup
- `tests/test-mesh-api-key-generation.php` - Test coverage for key generation
- `TESTING_FEDERATION_MESH_FIX.md` - User testing guide

## Conclusion

This fix ensures that:
1. Well-known endpoints are properly loaded when either `enable_federation` or `enable_federation_directory` is enabled
2. The mesh inbound API key is displayed when any of the three settings (`enable_mesh`, `enable_federation`, `enable_federation_directory`) are enabled
3. The three settings work together logically and independently
4. Users can see the status and generated keys regardless of which combination they choose

The three settings now work as intended:
- `enable_mesh` - For distributed AI workload processing
- `enable_federation` - For publishing capabilities via well-known endpoints
- `enable_federation_directory` - For managing a peer directory with full federation features

All three automatically generate the mesh inbound API key, and all three show the key in the UI.
