# Trade.gov API Key Settings Fix

**Date:** February 1, 2026  
**Issue:** Trade.gov API key setting was incorrectly grouped with Google Analytics  
**PR:** copilot/fix-api-key-validation  
**Status:** ✅ Complete

## Problem

When users encountered the error:
```
⚠️ Tool "get_import_duty" execution failed: The tariff service redirected the request. 
Verify that your Trade.gov API key is valid and stored in the settings.
```

They had difficulty finding where to configure the API key because:
1. The setting was hidden in the Google Analytics subtab
2. The error message didn't specify the exact location
3. The placement didn't match the logical grouping (tariff rates ≠ analytics)

## Solution

### 1. Created Dedicated Subtab

**Before:**
```
NV oOS > General Settings > Tools & Features > External Tools
  └─ Google Analytics (Pro)
      ├─ Google Analytics Property ID
      ├─ Google Analytics Credentials
      ├─ Google Analytics Credentials JSON
      └─ ITA Tariff Rate API Key  ❌ Wrong location!
```

**After:**
```
NV oOS > General Settings > Tools & Features > External Tools
  ├─ Google Analytics (Pro)
  │   ├─ Google Analytics Property ID
  │   ├─ Google Analytics Credentials
  │   └─ Google Analytics Credentials JSON
  └─ Trade.gov Tariff Rates (Pro)  ✅ New dedicated subtab!
      └─ ITA Tariff Rate API Key
```

### 2. Enhanced Error Message

**Before:**
```
The tariff service redirected the request. Verify that your Trade.gov API key 
is valid and stored in the settings.
```

**After:**
```
The tariff service redirected the request. Verify that your Trade.gov API key 
is valid and stored in NV oOS > General Settings > Tools & Features > 
External Tools > Trade.gov Tariff Rates.
```

### 3. Added Comprehensive Documentation

Created `docs/getting-started/TRADE-GOV-API-SETUP.md` with:
- Step-by-step setup instructions
- API key acquisition guide
- Troubleshooting section
- Tool usage examples
- Links to official documentation

## Files Changed

| File | Change Type | Description |
|------|-------------|-------------|
| `includes/admin/sections/class-wp-mcp-ai-section-integrations.php` | Modified | Added `ita_tariff` subtab, removed from Google Analytics |
| `addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-import-duty.php` | Modified | Enhanced error message with navigation path |
| `tests/test-phase3-unexposed-settings.php` | Modified | Updated test to check new subtab location |
| `docs/getting-started/TRADE-GOV-API-SETUP.md` | Created | New setup and troubleshooting guide |
| `docs/getting-started/README.md` | Modified | Added link to new guide |

## Code Changes

### includes/admin/sections/class-wp-mcp-ai-section-integrations.php

```php
// BEFORE (line 446)
'google_analytics' => array(
    'id'     => 'google_analytics',
    'label'  => $is_pro_active ? __( 'Google Analytics', 'mcp-ai-wpoos' ) : __( 'Google Analytics (Pro)', 'mcp-ai-wpoos' ),
    'icon'   => 'dashicons-chart-bar',
    'fields' => array( 'google_analytics_property_id', 'google_analytics_credentials', 'google_analytics_credentials_json', 'ita_tariff_api_key' ),
    'pro'    => true,
),

// AFTER (lines 442-455)
'google_analytics' => array(
    'id'     => 'google_analytics',
    'label'  => $is_pro_active ? __( 'Google Analytics', 'mcp-ai-wpoos' ) : __( 'Google Analytics (Pro)', 'mcp-ai-wpoos' ),
    'icon'   => 'dashicons-chart-bar',
    'fields' => array( 'google_analytics_property_id', 'google_analytics_credentials', 'google_analytics_credentials_json' ),
    'pro'    => true,
),
'ita_tariff'       => array(
    'id'     => 'ita_tariff',
    'label'  => $is_pro_active ? __( 'Trade.gov Tariff Rates', 'mcp-ai-wpoos' ) : __( 'Trade.gov Tariff Rates (Pro)', 'mcp-ai-wpoos' ),
    'icon'   => 'dashicons-admin-site',
    'fields' => array( 'ita_tariff_api_key' ),
    'pro'    => true,
),
```

### addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-import-duty.php

```php
// BEFORE (line 176)
__( 'The tariff service redirected the request. Verify that your Trade.gov API key is valid and stored in the settings.', 'mcp-ai-wpoos-pro' )

// AFTER (line 176)
__( 'The tariff service redirected the request. Verify that your Trade.gov API key is valid and stored in NV oOS > General Settings > Tools & Features > External Tools > Trade.gov Tariff Rates.', 'mcp-ai-wpoos-pro' )
```

## Testing

- ✅ PHP syntax validation passed
- ✅ Test `test_ita_tariff_api_key_in_subtab` updated and verified
- ✅ Code review completed with no issues
- ✅ Documentation reviewed and validated

## User Impact

### Before Fix
1. Users receive vague error message
2. Settings in illogical location
3. No setup documentation
4. Difficult to troubleshoot

### After Fix
1. ✅ Clear error message with exact navigation path
2. ✅ Logical settings location (Trade.gov Tariff Rates)
3. ✅ Comprehensive setup guide with troubleshooting
4. ✅ Easy to find and configure

## Related Issues

This fix addresses the problem statement:
```
where is this API set in the settings

⚠️ Tool "get_import_duty" execution failed: The tariff service redirected 
the request. Verify that your Trade.gov API key is valid and stored in 
the settings.
```

## Documentation

- Setup Guide: [docs/getting-started/TRADE-GOV-API-SETUP.md](../getting-started/TRADE-GOV-API-SETUP.md)
- Tool Reference: [docs/reference/tools/tool-reference.md](../reference/tools/tool-reference.md#lookup-import-duty)
- Settings Architecture: [docs/guides/admin/settings/SETTINGS-ARCHITECTURE-COMPARISON.md](../guides/admin/settings/SETTINGS-ARCHITECTURE-COMPARISON.md)

## Conclusion

This fix improves user experience by:
1. Making the API key setting discoverable
2. Providing clear navigation in error messages
3. Adding comprehensive setup documentation
4. Following logical UI grouping patterns

The setting is now in the correct location and users can easily find it when needed.
