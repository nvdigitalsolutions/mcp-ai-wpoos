# Settings Save Logic - Critical Issues & Fixes

## Executive Summary

The save logic analysis revealed **3 CRITICAL issues** that can cause data loss:

1. **Multi-Subtab Detection Bug** - Only first section's subtab is detected, others return empty array
2. **Checkbox Clearing Bug** - Checkboxes on non-active tabs get unchecked 
3. **Incomplete Cache Invalidation** - Changed settings don't invalidate relevant caches

## Critical Issue #1: Multi-Subtab Detection Breaks After First Match

### Current Broken Code
```php
// handle_save_settings(), Line 184-189
foreach ( $_POST as $key => $value ) {
    if ( strpos( $key, 'subtab_' ) === 0 && ! empty( $value ) ) {
        $active_subtab = sanitize_key( $value );
        $active_section_id = sanitize_key( str_replace( 'subtab_', '', $key ) );
        break;  // ← BREAKS! Only processes FIRST subtab found
    }
}
```

### Problem Scenario
```
Tab: "authentication"
├─ Section: authentication (subtab_authentication = "auth0")
└─ Section: oauth (subtab_oauth = "google")

Form submits both subtabs, but loop breaks after finding subtab_authentication.
Result: oauth section's sanitize_with_subtabs() doesn't find its subtab,
returns empty array, ALL oauth fields get cleared!
```

### Fix Implementation
```php
// Collect ALL subtabs per section
$active_subtabs = array();
foreach ( $_POST as $key => $value ) {
    if ( preg_match( '/^subtab_([a-z_]+)$/', $key, $matches ) && ! empty( $value ) ) {
        $section_id = $matches[1];
        $active_subtabs[ $section_id ] = sanitize_key( $value );
    }
}

// Pass to sanitize_settings()
$sanitized_new = $this->sanitize_settings( $posted_settings, $active_tab, $active_subtabs );

// Update sanitize_settings signature
public function sanitize_settings( $input, $active_tab = '', $active_subtabs = array() ) {
    // Pass section-specific subtab to each section
    foreach ( $sections as $section ) {
        $section_subtab = $active_subtabs[ $section->get_id() ] ?? null;
        $section_input = $section->sanitize( $input, $section_subtab );
        // ... rest
    }
}

// Update section sanitize() signature
public function sanitize( $input, $active_subtab = null ) {
    if ( method_exists( $this, 'get_subtab_groups' ) ) {
        return $this->sanitize_with_subtabs( $input, $active_subtab );
    }
    return $this->sanitize_fields( $input, $this->get_fields(), false );
}

// Update sanitize_with_subtabs() to accept explicit subtab
protected function sanitize_with_subtabs( $input, $explicit_subtab = null ) {
    // Use explicit subtab if provided, otherwise detect from POST/GET
    if ( null !== $explicit_subtab ) {
        $active_subtab = $explicit_subtab;
    } else {
        $active_subtab = $this->get_active_subtab();
    }
    
    // Validate subtab exists
    $subtab_groups = $this->get_subtab_groups();
    if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
        return array(); // Invalid subtab, don't process
    }
    
    // ... rest of logic
}
```

## Critical Issue #2: Checkbox Clearing on Non-Active Tabs

### Current Broken Code
```php
// abstract-wp-mcp-ai-settings-section.php, Line 95-103
public function sanitize( $input ) {
    if ( method_exists( $this, 'get_subtab_groups' ) ) {
        return $this->sanitize_with_subtabs( $input );
    }
    
    // BUG: Calls sanitize_fields with is_form_submit=true (default)!
    return $this->sanitize_fields( $input, $this->get_fields() );
}
```

### Problem
When saving settings on Tab A, all sections (including Tab B, Tab C) call `sanitize()`.
Non-subtab sections call `sanitize_fields()` with `is_form_submit=true` by default.
Checkboxes not in POST get set to false, clearing user's selections.

### Fix Implementation
```php
// Update sanitize() to accept active tab information
public function sanitize( $input, $active_subtab = null, $is_active_tab = false ) {
    if ( method_exists( $this, 'get_subtab_groups' ) ) {
        return $this->sanitize_with_subtabs( $input, $active_subtab );
    }
    
    // Only treat as form submission if this section's tab is active
    return $this->sanitize_fields( $input, $this->get_fields(), $is_active_tab );
}

// In sanitize_settings()
foreach ( $sections as $section ) {
    $section_subtab = $active_subtabs[ $section->get_id() ] ?? null;
    $is_active = ( $section->get_tab() === $active_tab );
    $section_input = $section->sanitize( $input, $section_subtab, $is_active );
    // ... rest
}
```

## Critical Issue #3: Incomplete Cache Invalidation

### Current Code
```php
// Line 375-383
if ( 'orchestration' === $active_tab ) {
    WP_MCP_AI_Cache_Helper::invalidate_orchestration_caches();
    WP_MCP_AI_Orchestration_Health_Service::clear_health_cache();
}
// ← No cache clearing for other tabs!
```

### Problem
- User updates `openai_api_key` on Providers tab → Cached API responses persist
- User enables/disables tools on Tools tab → Cached tool list not cleared
- User changes authentication settings → Auth cache not cleared

### Fix Implementation
```php
/**
 * Invalidate caches based on which tab was modified.
 *
 * @param string $active_tab The tab that was saved.
 * @param array  $merged_settings The merged settings after save.
 */
private function invalidate_tab_caches( $active_tab, $merged_settings ) {
    switch ( $active_tab ) {
        case 'providers':
            // Clear provider and model caches.
            wp_cache_delete( 'wp_mcp_ai_providers' );
            wp_cache_delete( 'wp_mcp_ai_models' );
            wp_cache_delete( 'wp_mcp_ai_provider_priority' );
            do_action( 'wp_mcp_ai_providers_updated' );
            break;
            
        case 'tools':
            // Clear tool-related caches.
            if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
                WP_MCP_AI_Cache_Helper::invalidate_tool_caches();
            }
            wp_cache_delete( 'wp_mcp_ai_available_tools' );
            wp_cache_delete( 'wp_mcp_ai_tool_limits' );
            do_action( 'wp_mcp_ai_tools_updated' );
            break;
            
        case 'authentication':
            // Clear authentication caches.
            wp_cache_delete( 'wp_mcp_ai_auth_config' );
            wp_cache_delete( 'wp_mcp_ai_oauth_tokens' );
            do_action( 'wp_mcp_ai_authentication_updated' );
            break;
            
        case 'orchestration':
            // Clear orchestration caches (existing code).
            if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
                WP_MCP_AI_Cache_Helper::invalidate_orchestration_caches();
            }
            if ( class_exists( 'WP_MCP_AI_Orchestration_Health_Service' ) ) {
                WP_MCP_AI_Orchestration_Health_Service::clear_health_cache();
            }
            break;
            
        case 'advanced':
            // Clear advanced settings caches.
            if ( isset( $merged_settings['enable_logging'] ) || 
                 isset( $merged_settings['enable_extended_logging'] ) ) {
                wp_cache_delete( 'wp_mcp_ai_logging_config' );
            }
            if ( isset( $merged_settings['mesh_peer_sites'] ) ) {
                wp_cache_delete( 'wp_mcp_ai_mesh_peers' );
            }
            break;
            
        case 'general':
            // Clear general settings cache.
            wp_cache_delete( 'wp_mcp_ai_general_config' );
            break;
    }
    
    // Always clear the settings cache.
    WP_MCP_AI_Admin_Settings::reset_settings_cache();
}

// In handle_save_settings(), after update_option():
$this->invalidate_tab_caches( $active_tab, $merged_settings );
```

## Implementation Plan

### Phase 1: P0 Critical Fixes (Immediate)
1. ✅ Fix multi-subtab detection (2 hours)
2. ✅ Fix checkbox clearing on non-active tabs (1 hour)
3. ✅ Add comprehensive cache invalidation (1 hour)
4. ✅ Test all three fixes together (2 hours)

### Phase 2: P1 High Priority (Next)
1. Consolidate sensitive key definitions into single class
2. Add data loss detection and logging
3. Add settings change audit trail
4. Add configuration validation hooks

### Phase 3: P2 Medium Priority (Future)
1. Add rollback capability (keep 3 previous versions)
2. Add email alerts for potential data loss
3. Add batch save capability for performance
4. Add settings export/import

## Testing Strategy

### Test Case 1: Multi-Subtab Save
```
Setup:
- Tab with 2 sections, each with subtabs
- authentication section (subtab: auth0)
- oauth section (subtab: google)

Test:
1. Set values in both sections
2. Save
3. Navigate away and back
4. Verify both sections retained values

Expected: Both sections' values preserved
Current Bug: oauth section values cleared
With Fix: Both sections work ✓
```

### Test Case 2: Checkbox on Different Tab
```
Setup:
- Checkbox on Tab A: enable_feature = true
- Modify settings on Tab B

Test:
1. Check enable_feature on Tab A, save
2. Navigate to Tab B, modify field, save
3. Return to Tab A
4. Verify enable_feature still checked

Expected: Checkbox remains checked
Current Bug: Checkbox gets unchecked
With Fix: Checkbox preserved ✓
```

### Test Case 3: Cache Invalidation
```
Setup:
- Cached API response using openai_api_key

Test:
1. Update openai_api_key on Providers tab
2. Make API request
3. Verify new key is used (not cached old key)

Expected: New key used immediately
Current Bug: Cached response with old key persists
With Fix: Cache cleared, new key used ✓
```

## Risk Assessment

### Low Risk ✓
- Changes are surgical and targeted
- Backward compatible (expanded signatures with defaults)
- Well-tested protection layers remain intact
- Clear rollback path

### Files Modified
1. `includes/admin/class-wp-mcp-ai-settings-dashboard.php`
   - handle_save_settings() - subtab collection
   - sanitize_settings() - pass subtabs
   - Add invalidate_tab_caches() method

2. `includes/admin/sections/abstract-wp-mcp-ai-settings-section.php`
   - sanitize() - accept subtab and is_active_tab
   - sanitize_with_subtabs() - accept explicit subtab

### Rollback Plan
If issues occur:
1. Revert single commit
2. Or comment out subtab passing (falls back to detection)
3. Or disable cache invalidation for specific tab

## Success Criteria

✅ All subtabs on same tab can be saved simultaneously  
✅ Checkboxes on non-active tabs remain unchanged  
✅ Cache cleared when relevant settings change  
✅ No breaking changes to existing functionality  
✅ All existing tests pass  
✅ New test cases pass
