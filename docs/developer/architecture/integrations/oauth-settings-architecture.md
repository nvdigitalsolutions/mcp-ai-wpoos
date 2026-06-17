# NV oOS Settings Architecture - OAuth Integration

## Overview

The plugin uses a **hybrid architecture** where the modern Settings Dashboard UI delegates to the legacy Admin Settings class for OAuth functionality.

```
┌─────────────────────────────────────────────────────────────────┐
│                     USER CLICKS "CONNECT"                        │
│              (in Settings Dashboard → Tools → Gmail)             │
└───────────────────────────────┬─────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│         WP_MCP_AI_Section_Integrations (NEW SYSTEM)             │
│  • Renders OAuth forms and "Connect" buttons                    │
│  • Located in: includes/admin/sections/                         │
│  • Generates URL: admin-post.php?action=wp_mcp_ai_gmail_oauth_start │
└───────────────────────────────┬─────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│         WordPress admin-post.php Handler                         │
│  • Looks for registered action: wp_mcp_ai_gmail_oauth_start    │
│  • Returns 400 if action not registered ❌                      │
└───────────────────────────────┬─────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│         WP_MCP_AI_Admin_Settings (LEGACY SYSTEM)                │
│  • Constructor registers OAuth action hooks ✅                   │
│  • Located in: includes/admin/class-wp-mcp-ai-admin-settings.php│
│  • Lines 97-98: Registers gmail_oauth_start and callback hooks  │
└───────────────────────────────┬─────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│         WP_MCP_AI_OAuth_Manager                                  │
│  • Handles OAuth flow logic                                     │
│  • Located in: includes/integrations/class-wp-mcp-ai-oauth-manager.php │
│  • Methods: handle_gmail_oauth_start(), handle_gmail_oauth_callback() │
└─────────────────────────────────────────────────────────────────┘
```

## The Problem (Before Fix)

```
❌ WP_MCP_AI_Admin_Settings was NEVER instantiated
   └─> Constructor never ran
       └─> OAuth hooks never registered
           └─> 400 Bad Request error
```

## The Solution (After Fix)

```
✅ Added to DI Container (includes/class-wp-mcp-ai-container.php)
   └─> 'admin.settings' service registered

✅ Initialize on plugin load (mcp-ai-wpoos.php line 593)
   └─> wp_mcp_ai_container()->get('admin.settings')
       └─> Constructor runs
           └─> OAuth hooks registered ✅
               └─> OAuth flow works! 🎉
```

## Why Keep the Legacy Class?

The **WP_MCP_AI_Admin_Settings** class serves multiple purposes:

### 1. OAuth Hook Registration (Critical)
```php
// Lines 97-98 in includes/admin/class-wp-mcp-ai-admin-settings.php
add_action('admin_post_wp_mcp_ai_gmail_oauth_start', ...);
add_action('admin_post_wp_mcp_ai_gmail_oauth_callback', ...);
```

Also registers hooks for:
- Meta (Facebook, Instagram, WhatsApp) OAuth
- QuickBooks OAuth  
- Mailjet OAuth
- Cloudways connection
- Cloudflare test connection

### 2. AJAX Handler Registration
```php
// Lines 107-117
add_action('wp_ajax_wp_mcp_ai_test_ollama_connection', ...);
add_action('wp_ajax_wp_mcp_ai_fetch_ollama_models', ...);
add_action('wp_ajax_wp_mcp_ai_test_lm_studio_connection', ...);
// ... and many more
```

### 3. Static Utility Methods
```php
WP_MCP_AI_Admin_Settings::get_settings();
WP_MCP_AI_Admin_Settings::get_default_settings();
WP_MCP_AI_Admin_Settings::log($message, $context);
```

These static methods are used throughout the codebase.

## Division of Responsibilities

### New Settings Dashboard System
**File**: `includes/admin/class-wp-mcp-ai-settings-dashboard.php`
**Purpose**: Modern tabbed UI, page rendering, settings form display
**Initialized**: Line 126 in `settings-dashboard-init.php`

### Integrations Section  
**File**: `includes/admin/sections/class-wp-mcp-ai-section-integrations.php`
**Purpose**: Render OAuth forms, credential inputs, "Connect" buttons
**Part of**: Settings Dashboard system (lazy-loaded)

### Legacy Admin Settings
**File**: `includes/admin/class-wp-mcp-ai-admin-settings.php`
**Purpose**: Register action hooks, AJAX handlers, provide static utilities
**Initialized**: Line 593 in `mcp-ai-wpoos.php` (after our fix)

### OAuth Manager
**File**: `includes/integrations/class-wp-mcp-ai-oauth-manager.php`  
**Purpose**: OAuth flow implementation (redirects, token exchange, etc.)
**Used by**: Admin Settings (via constructor injection)

## Migration Strategy

The plugin is gradually migrating from a monolithic settings page to a modular dashboard:

### ✅ Migrated to New System
- Settings page UI (tabs, sections, forms)
- Field rendering and validation
- Save handlers
- AJAX responses

### 🔄 Still Using Legacy System  
- OAuth action hook registration
- AJAX action hook registration
- Static utility methods
- Backward compatibility layer

### 💡 Why Not Migrate OAuth Hooks?

The OAuth hooks are registered in `WP_MCP_AI_Admin_Settings` constructor because:

1. **It works** - proven, stable implementation
2. **No benefit** - Moving hooks to Settings Dashboard adds no value
3. **Risk** - Refactoring working OAuth flows could introduce bugs
4. **Timing** - Hooks must be registered early, before admin_menu
5. **Coupling** - OAuth Manager is properly separated; hooks are just wiring

## File Loading Order

```
1. mcp-ai-wpoos.php (main plugin file)
   │
   ├─> 2. includes/class-wp-mcp-ai-container.php
   │      └─> Registers all services (including admin.settings)
   │
   ├─> 3. includes/integrations/class-wp-mcp-ai-oauth-manager.php
   │      └─> Loaded early (line 406)
   │
   ├─> 4. includes/admin/settings-dashboard-init.php
   │      └─> Loads Settings Dashboard system (line 587)
   │      └─> Initializes dashboard (line 175 in init file)
   │
   └─> 5. wp_mcp_ai_container()->get('admin.settings')
          └─> Instantiates WP_MCP_AI_Admin_Settings (line 593) ✅
          └─> Constructor registers OAuth hooks ✅
```

## Testing the Fix

### Verify OAuth Hooks Are Registered
```php
// This should return array with callbacks
global $wp_filter;
var_dump($wp_filter['admin_post_wp_mcp_ai_gmail_oauth_start']);
var_dump($wp_filter['admin_post_wp_mcp_ai_gmail_oauth_callback']);
```

### Test OAuth Flow
1. Go to: **NV oOS Dashboard** → **Tools** → **Connections** → **Gmail**
2. Enter Client ID and Client Secret
3. Click **Save Settings**
4. Click **Connect Gmail Account**
5. Should redirect to Google OAuth screen ✅

### Debug If Still Failing
```php
// Check if admin.settings was instantiated
$settings = wp_mcp_ai_container()->get('admin.settings');
var_dump($settings); // Should be object, not null

// Check hooks
var_dump(has_action('admin_post_wp_mcp_ai_gmail_oauth_start')); // Should be > 0
```

## Summary

- ✅ **Fix is correct**: Initialize legacy Admin Settings for OAuth hooks
- ✅ **Architecture is intentional**: Hybrid system during migration
- ✅ **No refactoring needed**: OAuth implementation is solid
- ✅ **Future-proof**: Can migrate OAuth later if/when beneficial
- ✅ **Minimal change**: Just ensure class is instantiated

The fix bridges the gap between the new Settings Dashboard UI and the legacy OAuth implementation without requiring a full rewrite.
