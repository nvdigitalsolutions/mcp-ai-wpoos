# Fix: Embedded Provider Settings Not Showing (Container Integration)

**Date:** 2026-02-16  
**Issue:** Embedded chat client provider settings not showing in cloned plugin  
**PR:** #3752  
**Related:** Auto-enable embedded provider when Pro addon is active

## Problem

When accessing the embedded provider settings at `/wp-admin/admin.php?page=wp-mcp-ai-dashboard&tab=providers&subtab=embedded`, the settings page would not display. The "Embedded LLM" subtab would not appear in the providers tab navigation.

## Root Cause

The base `WP_MCP_AI_Section_Providers` class was trying to retrieve the Pro Providers section using:
```php
$pro_providers_section = WP_MCP_AI_Settings_Registry::get_section( 'pro_providers' );
```

However, according to `settings-dashboard-init.php` (lines 149-155), the Pro Providers section is **intentionally NOT registered** with the Settings Registry to prevent duplicate rendering. Instead, it should only provide subtabs that are merged into the base Providers section.

The section is registered in the container but not in the registry:
- ✅ Container: `$container->get('section.pro_providers')` returns the section instance
- ❌ Registry: `WP_MCP_AI_Settings_Registry::get_section('pro_providers')` returns `null`

This architectural decision prevents the Pro Providers section from appearing twice on the page (once as a standalone section and once merged into the base section).

## Solution

Modified the base Providers section to retrieve the Pro Providers section from the container instead of the registry in two places:

### 1. Subtab Merging (`get_subtab_groups()` method)

**Before:**
```php
if ( class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) ) {
    $pro_providers_section = WP_MCP_AI_Settings_Registry::get_section( 'pro_providers' );
    // ... rest of merging logic
}
```

**After:**
```php
if ( class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) && function_exists( 'wp_mcp_ai_container' ) ) {
    $container             = wp_mcp_ai_container();
    $pro_providers_section = $container->get( 'section.pro_providers' );
    // ... rest of merging logic
}
```

### 2. Field Rendering (`render()` method)

Same change applied when rendering the embedded subtab's fields.

## Technical Details

### Why Not Use the Registry?

The Settings Registry is designed for sections that:
1. Render as standalone sections on the settings page
2. Have their own heading and description
3. Appear in the section list

The Pro Providers section is different - it's a "mixin" that:
1. Provides subtabs to be merged into the base Providers section
2. Doesn't render as a standalone section
3. Should not appear in the section list

### Container vs Registry Pattern

| Aspect | Container | Registry |
|--------|-----------|----------|
| Purpose | Dependency injection, lazy instantiation | Section registration for rendering |
| Returns | Instance or null | Registered section instance or null |
| Use Case | All sections (for DI) | Only sections that render standalone |
| Pro Providers | ✅ Registered | ❌ Not registered (intentional) |

## Files Modified

- `includes/admin/sections/class-wp-mcp-ai-section-providers.php`
  - Line ~1007: Changed `get_subtab_groups()` to use container
  - Line ~1139: Changed `render()` to use container
  - Added explanatory comments

## Verification Steps

### 1. Check Container Registration
```php
$container = wp_mcp_ai_container();
$section = $container->get( 'section.pro_providers' );
// Should return WP_MCP_AI_Section_Pro_Providers instance
```

### 2. Check Registry (Should Be Null)
```php
$section = WP_MCP_AI_Settings_Registry::get_section( 'pro_providers' );
// Should return null (not registered)
```

### 3. Check Subtab Merging
```php
$providers_section = new WP_MCP_AI_Section_Providers();
$reflection = new ReflectionClass( $providers_section );
$method = $reflection->getMethod( 'get_subtab_groups' );
$method->setAccessible( true );
$groups = $method->invoke( $providers_section );
// Should include 'embedded' subtab when Pro is active
```

### 4. Manual UI Check
1. Navigate to **NV oOS → General Settings → AI Providers**
2. Verify the "Embedded LLM" subtab appears in the navigation
3. Click "Embedded LLM" subtab
4. Verify settings appear:
   - Enable Embedded LLM Provider (checkbox)
   - Default Embedded Model (dropdown)
   - Available Models (info section)

## Testing

Existing tests in `tests/test-embedded-provider-subtab-integration.php` cover:
- ✅ Embedded subtab appears in base Providers section
- ✅ Base Providers section can render embedded subtab
- ✅ Embedded fields are properly delegated to Pro section
- ✅ Embedded subtab only appears when Pro addon is active

The test in `tests/test-pro-providers-section-registration.php` explicitly verifies:
- ✅ Pro Providers section is available from container
- ✅ Pro Providers section is NOT in Settings Registry
- ✅ Pro subtabs are merged into base section (not standalone)

## Related Documentation

- `docs/fixes/embedded-llm-provider-settings-fix-2026-02-16.md` - Original fix attempt using reflection
- `docs/fixes/embedded-provider-settings-integration-fix-2026-02-16.md` - Container registration fix
- `includes/admin/settings-dashboard-init.php` - Registry initialization and Pro section comments
- `includes/class-wp-mcp-ai-container.php` - Container service definitions

## Architecture Pattern

This fix reinforces the following architectural pattern for Pro sections:

1. **Load** - Class file loaded in `wp_mcp_ai_pro_load_admin_sections()`
2. **Register in Container** - Entry created with null check for when Pro is not active
3. **Conditionally Register in Registry** - Only if section renders standalone
4. **Use Container for Integration** - Base sections use container to access Pro sections for merging

### Pro Section Types

| Section | Container | Registry | Reason |
|---------|-----------|----------|--------|
| Pro Providers | ✅ | ❌ | Subtabs merged into base Providers |
| Pro Integrations | ✅ | ✅ | Renders as standalone section |
| Performance | ✅ | ✅ | Renders as standalone section |

## Impact

- **User Impact:** Embedded provider settings now visible and accessible
- **Developer Impact:** Reinforces container-first pattern for Pro section access
- **Performance:** No impact (container already used for other sections)
- **Backward Compatibility:** Full compatibility (base version unchanged)

## Future Considerations

When adding new Pro sections that need to be merged into base sections:
1. Register in container (always)
2. Do NOT register in Settings Registry if merging
3. Base section must use container to access Pro section
4. Add comments explaining the pattern
5. Test that section is not in registry but is in container
