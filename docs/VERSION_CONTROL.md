# Version Control via wp-config.php

## Overview

When you clone the repository, you can control whether the plugin operates in **Base Version** (165 tools) or **Complete Version** (519 tools) by setting a constant in your `wp-config.php` file.

## Default Behavior

**New Default (as of this update):**
- When repository is cloned → **Base Version** (165 core tools)
- No wp-config.php setting needed for base version

## Enabling Complete Version

To enable the **Complete Version** with all 519 tools (165 base + 348 pro + 6 core/memory):

**Add to your `wp-config.php` file:**
```php
define( 'WP_MCP_AI_BASE_VERSION', false ); // Enable complete version
```

Place this constant **before** the `/* That's all, stop editing! */` line.

## Version Comparison

| Feature | Base Version (Default) | Complete Version (Opt-in) |
|---------|----------------------|---------------------------|
| **Tool Count** | 165 core tools | 519 tools total |
| **Third-party Plugins** | None required | WooCommerce, JetEngine, Elementor, etc. |
| **External APIs** | None required | Google, Social media, QuickBooks, etc. |
| **wp-config.php** | No setting needed | `define( 'WP_MCP_AI_BASE_VERSION', false );` |
| **Use Case** | Clean WordPress installations | Production sites with integrations |

## Base Version Tools (165)

The base version includes:
- ✅ Core WordPress tools (posts, pages, users, media)
- ✅ AI generation tools (images, video, audio, text)
- ✅ Content management and publishing
- ✅ Basic media processing
- ✅ System maintenance tools
- ✅ Chat and conversation management
- ❌ WooCommerce integration (requires complete version)
- ❌ JetEngine/JetFormBuilder tools (requires complete version)
- ❌ Social media APIs (requires complete version)
- ❌ External service integrations (requires complete version)

## Complete Version Additional Tools (354)

The complete version adds:
- ✅ WooCommerce tools (3 tools)
- ✅ JetEngine/JetFormBuilder integration (5 tools)
- ✅ Social media automation (8 tools)
- ✅ Google services (5 tools)
- ✅ Pro toolkits (348 tools across 8 specialized areas)
- ✅ Advanced workflow orchestration
- ✅ External API integrations

## When to Use Each Version

### Use Base Version (Default) When:
- 🎯 Starting fresh with WordPress
- 🎯 Development or testing environments
- 🎯 Simple installations without external dependencies
- 🎯 Don't need e-commerce or advanced integrations
- 🎯 Want fastest setup with minimal configuration
- 🎯 Don't want to install third-party plugins

### Use Complete Version When:
- 🎯 Production sites with WooCommerce already installed
- 🎯 Sites using JetEngine for custom post types
- 🎯 Need social media automation
- 🎯 Require external API integrations (Google, QuickBooks, etc.)
- 🎯 Advanced workflows requiring Pro toolkits
- 🎯 Server-side chat transcript storage (requires JetEngine)

## How It Works

1. **Repository Clone:**
   ```bash
   git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
   ```

2. **Default Activation:**
   - WordPress activates `mcp-ai-wpoos.php`
   - Plugin defaults to Base Version (165 tools)
   - No configuration needed to start using it

3. **Optional: Enable Complete Version:**
   - Edit your site's `wp-config.php`
   - Add: `define( 'WP_MCP_AI_BASE_VERSION', false );`
   - Reload any WordPress admin page
   - Complete version (519 tools) is now active

## Technical Details

### How the Constant is Checked

In `mcp-ai-wpoos.php`:
```php
/**
 * Define base version mode constant.
 *
 * Defaults to true (base mode with core tools only - 165 tools).
 * Set to false in wp-config.php to enable full mode (all available tools - 519 tools).
 */
if ( ! defined( 'WP_MCP_AI_BASE_VERSION' ) ) {
    define( 'WP_MCP_AI_BASE_VERSION', true );
}
```

### Load Order
1. WordPress loads `wp-config.php` first
2. If `WP_MCP_AI_BASE_VERSION` is defined there, that value is used
3. If not defined, plugin sets default to `true` (base version)
4. Plugin uses this constant throughout to determine which tools to load

### Verification

To check which version is active, use the plugin's Site Health info:
- Go to **Tools → Site Health → Info**
- Look for **NV oOS** section
- Check the **Base Version Mode** value

Or check programmatically:
```php
$is_base_version = wp_mcp_ai_is_base_version();
if ( $is_base_version ) {
    echo 'Running Base Version (165 tools)';
} else {
    echo 'Running Complete Version (519 tools)';
}
```

## Migration Guide

### Switching from Complete to Base

If you previously had the complete version and want to switch to base:

1. Edit `wp-config.php`
2. Add or change: `define( 'WP_MCP_AI_BASE_VERSION', true );`
3. Clear any WordPress caches
4. Reload admin page

**Note:** Tools requiring third-party plugins will no longer be available.

### Switching from Base to Complete

If you're running base and want to switch to complete:

1. Install any required third-party plugins (optional):
   - WooCommerce (for e-commerce tools)
   - JetEngine (for transcript storage and custom post types)
   - Elementor (for page builder integration)

2. Edit `wp-config.php`
3. Add: `define( 'WP_MCP_AI_BASE_VERSION', false );`
4. Clear any WordPress caches
5. Reload admin page

**All 519 tools are now available!**

## Support

If you encounter issues:
- Check that the constant is properly set in `wp-config.php`
- Ensure it's placed before `/* That's all, stop editing! */`
- Clear all caches (plugin, object, and transient caches)
- Check **Settings → NV oOS → System Info** for version status

## Change History

- **2026-02-02:** Changed default from Complete (false) to Base (true)
  - Cloning repo now gives Base version by default
  - Users must opt-in to Complete version via wp-config.php
  - Aligns with WordPress.org distribution model
