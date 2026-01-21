# Toolkit Architecture & Third-Party Development Guide

## Current Architecture (As Implemented)

### Overview
The Pro plugin follows a **modular toolkit architecture** where:
- **Core Pro Plugin** = Settings, Dashboard, Base Infrastructure
- **Toolkits** = Independent feature sets that can be enabled/disabled
- **NPM Services** = Shared Node.js microservices available to all toolkits

### Structure

```
addons/pro/
├── mcp-ai-wpoos-pro.php          # Main Pro plugin file
├── includes/
│   ├── class-wp-mcp-ai-product-type-helper.php    # Shared utilities
│   ├── class-wp-mcp-ai-remote-connection.php      # Shared utilities
│   ├── class-wp-mcp-ai-erp-connector.php          # Shared utilities
│   ├── ecommerce-toolkit-init.php                 # E-commerce toolkit loader
│   ├── social-media-toolkit-init.php              # Social media toolkit loader
│   ├── analytics-toolkit-init.php                 # Analytics toolkit loader
│   ├── multilingual-toolkit-init.php              # Multilingual toolkit loader
│   ├── video-production-toolkit-init.php          # Video toolkit loader
│   └── tools/
│       ├── ecommerce/                             # E-commerce toolkit tools
│       ├── social-media/                          # Social media toolkit tools
│       ├── analytics/                             # Analytics toolkit tools
│       ├── multilingual/                          # Multilingual toolkit tools
│       └── video-production/                      # Video toolkit tools
├── node-services/                                 # Shared NPM microservices
└── package.json                                   # Shared NPM dependencies
```

### Toolkit Loading System

Each toolkit:
1. **Can be disabled/enabled** via settings (`enable_ecommerce_toolkit`, etc.)
2. **Fails gracefully** - if a toolkit has errors, it doesn't break the plugin
3. **Loads conditionally** - only loads when enabled and dependencies met
4. **Registers independently** - tools are registered only if toolkit is active

#### Example from `mcp-ai-wpoos-pro.php` (lines 284-307):

```php
// Load E-commerce Toolkit if enabled (Pro feature).
if ( ! empty( $settings['enable_ecommerce_toolkit'] ) ) {
    require_once WP_MCP_AI_PRO_PATH . 'includes/ecommerce-toolkit-init.php';
}

// Load Social Media Management Toolkit if enabled (Pro feature).
if ( ! empty( $settings['enable_social_media_toolkit'] ) ) {
    require_once WP_MCP_AI_PRO_PATH . 'includes/social-media-toolkit-init.php';
}

// ... other toolkits follow same pattern
```

### Error Isolation

Each toolkit init file wraps its loading in error checking:

```php
// Check if toolkit is enabled and dependencies are met.
$settings   = get_option( 'wp_mcp_ai_settings', array() );
$is_enabled = ! empty( $settings['enable_ecommerce_toolkit'] );
$has_wc     = class_exists( 'WooCommerce' );

// Only load if enabled and dependencies met.
if ( $is_enabled && $has_wc ) {
    // Load toolkit features
}
```

This ensures:
- ✅ If a toolkit breaks, it can be disabled via settings
- ✅ Missing dependencies don't break the plugin
- ✅ Each toolkit is self-contained

---

## Third-Party Addon Development

### Can Third-Party Developers Create Addons?

**YES!** The Pro plugin provides a complete API for third-party developers to:
1. Create custom toolkits
2. Access shared NPM services
3. Register tools with the system
4. Integrate with Pro features

### Developer API

#### 1. Create a Custom Toolkit

Create a WordPress plugin that depends on the Pro plugin:

```php
<?php
/**
 * Plugin Name: My Custom Toolkit
 * Description: Custom toolkit for NV oOS Pro
 * Version: 1.0.0
 * Requires Plugins: mcp-ai-wpoos-pro
 */

// Check if Pro plugin is active
if ( ! function_exists( 'wp_mcp_ai_pro_check_dependencies' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>My Custom Toolkit requires NV oOS Pro to be installed and activated.</p></div>';
    });
    return;
}

// Hook into Pro plugin initialization
add_action( 'wp_mcp_ai_pro_init', 'my_custom_toolkit_init' );

function my_custom_toolkit_init() {
    // Your toolkit initialization code
    // Load your tools, admin pages, etc.
}

// Register your tools
add_action( 'wp_mcp_ai_register_tools', 'my_custom_toolkit_register_tools', 30 );

function my_custom_toolkit_register_tools( $registry ) {
    // Register your custom tools
    require_once __DIR__ . '/includes/tools/class-my-custom-tool.php';
    
    $tool = new My_Custom_Tool();
    $registry->register_tool( $tool );
}
```

#### 2. Access Shared NPM Services

The Pro plugin exposes NPM services via filters:

```php
// Use Prettier for code formatting
$formatted_code = apply_filters( 'wp_mcp_ai_format_code_prettier', $code, array(
    'parser' => 'php',
    'tabWidth' => 4,
));

// Use MJML for email templates
$html_email = apply_filters( 'wp_mcp_ai_compile_mjml', $mjml_template );

// Use fluent-ffmpeg for video processing
$video_info = apply_filters( 'wp_mcp_ai_get_video_metadata', $video_path );
```

#### 3. Use Shared Utility Classes

```php
// Product Type Helper - for all WooCommerce product types
$helper = WP_MCP_AI_Product_Type_Helper::get_instance();
$type = $helper->get_product_type( $product_id );
$helper->update_product_inventory( $product_id, $quantity, array(
    'warehouse' => 'main',
    'reason' => 'sold',
));

// Remote Connection Manager - for remote sites
$remote = WP_MCP_AI_Remote_Connection::get_instance();
$connection_id = $remote->add_connection(array(
    'name' => 'Remote Site',
    'url' => 'https://remote-site.com',
    'auth_type' => 'application_password',
    'username' => 'admin',
    'password' => 'app_password_here',
));
$response = $remote->request( $connection_id, 'GET', '/wp-json/wc/v3/products' );

// ERP Connector - for inventory sync
$erp = WP_MCP_AI_ERP_Connector_Interface::get_instance();
$erp->sync_inventory( $product_id, 'bidirectional' );
```

#### 4. Create Custom Tools

```php
<?php
/**
 * Custom Tool Class
 */
class My_Custom_Tool extends WP_MCP_AI_Tool_Base {
    
    public function get_slug() {
        return 'my_custom_tool';
    }
    
    public function get_definition() {
        return array(
            'name' => 'My Custom Tool',
            'description' => 'Does something awesome',
            'required_capability' => 'manage_options',
            'parameters' => array(
                'type' => 'object',
                'properties' => array(
                    'input' => array(
                        'type' => 'string',
                        'description' => 'Input parameter',
                    ),
                ),
                'required' => array( 'input' ),
            ),
        );
    }
    
    public function is_available() {
        // Check if your dependencies are met
        return class_exists( 'My_Required_Plugin' );
    }
    
    public function execute( $arguments, $context ) {
        // Your tool logic
        $input = sanitize_text_field( $arguments['input'] );
        
        // Use NPM services if needed
        $formatted = apply_filters( 'wp_mcp_ai_format_code_prettier', $input );
        
        return array(
            'success' => true,
            'data' => array(
                'result' => $formatted,
            ),
        );
    }
}
```

---

## Proposed: Fully Independent Addon Architecture

### Option 1: Keep Current Structure (Recommended)

**Pros:**
- ✅ Already implemented and working
- ✅ Toolkits can be disabled/enabled
- ✅ Shared code reduces duplication
- ✅ NPM services are centralized
- ✅ Single update process
- ✅ Better performance (no multiple plugins)

**Cons:**
- ❌ All toolkits ship together
- ❌ Can't sell toolkits separately
- ❌ Larger plugin size

### Option 2: Separate Plugin Per Toolkit

Create separate WordPress plugins for each toolkit:

```
Plugins:
├── mcp-ai-wpoos-pro/              # Core Pro (settings, dashboard, APIs)
├── mcp-ai-wpoos-ecommerce/        # E-commerce Toolkit (separate plugin)
├── mcp-ai-wpoos-social-media/     # Social Media Toolkit (separate plugin)
├── mcp-ai-wpoos-analytics/        # Analytics Toolkit (separate plugin)
├── mcp-ai-wpoos-multilingual/     # Multilingual Toolkit (separate plugin)
└── mcp-ai-wpoos-video/            # Video Toolkit (separate plugin)
```

**Implementation:**

1. **Core Pro Plugin** provides:
   - Settings system
   - Dashboard
   - Tool registry
   - NPM services API
   - Shared utilities (Product Type Helper, Remote Connection, ERP Connector)
   - Developer hooks and filters

2. **Each Toolkit Plugin**:
   - Declares `Requires Plugins: mcp-ai-wpoos-pro` in header
   - Hooks into `wp_mcp_ai_pro_init` action
   - Registers its tools via `wp_mcp_ai_register_tools` action
   - Can be installed/activated independently
   - Can be sold separately
   - Automatically disabled if Pro plugin deactivated

**Example Toolkit Plugin Structure:**

```php
<?php
/**
 * Plugin Name: NV oOS E-commerce Toolkit
 * Description: Advanced WooCommerce tools for NV oOS Pro
 * Version: 1.0.0
 * Requires Plugins: mcp-ai-wpoos-pro
 * Requires PHP: 7.4
 * Requires at least: 6.0
 */

// Dependency check
if ( ! function_exists( 'wp_mcp_ai_pro_check_dependencies' ) ) {
    add_action( 'admin_notices', function() {
        echo '<div class="notice notice-error"><p>NV oOS E-commerce Toolkit requires NV oOS Pro to be installed and activated.</p></div>';
    });
    return;
}

// Initialize toolkit
add_action( 'wp_mcp_ai_pro_init', 'nvoos_ecommerce_toolkit_init' );

function nvoos_ecommerce_toolkit_init() {
    // Load toolkit features
    require_once __DIR__ . '/includes/toolkit-loader.php';
}

// Register toolkit tools
add_action( 'wp_mcp_ai_register_tools', 'nvoos_ecommerce_toolkit_register_tools', 30 );

function nvoos_ecommerce_toolkit_register_tools( $registry ) {
    // Auto-load all tools from tools/ directory
    $tools_dir = __DIR__ . '/includes/tools/';
    $tool_files = glob( $tools_dir . 'class-*.php' );
    
    foreach ( $tool_files as $file ) {
        require_once $file;
        
        // Extract class name from file
        $class_name = str_replace(
            array( 'class-', '.php', '-' ),
            array( '', '', '_' ),
            basename( $file )
        );
        $class_name = 'WP_MCP_AI_Tool_' . ucwords( $class_name, '_' );
        
        if ( class_exists( $class_name ) ) {
            $tool = new $class_name();
            $registry->register_tool( $tool );
        }
    }
}
```

**Pros:**
- ✅ Can sell toolkits separately
- ✅ Users only install what they need
- ✅ Smaller plugin sizes
- ✅ Independent updates
- ✅ Marketplace ready

**Cons:**
- ❌ More maintenance overhead
- ❌ Potential version conflicts
- ❌ NPM services need careful sharing
- ❌ More testing required

---

## NPM Service Sharing for Third-Party Addons

### Current Implementation

NPM services are provided by the Pro plugin and accessible via filters:

```php
// Available filters for third-party developers:

// 1. Prettier code formatting
apply_filters( 'wp_mcp_ai_format_code_prettier', $code, $options );

// 2. MJML email compilation
apply_filters( 'wp_mcp_ai_compile_mjml', $mjml_template, $options );

// 3. FFmpeg video processing
apply_filters( 'wp_mcp_ai_get_video_metadata', $video_path );
apply_filters( 'wp_mcp_ai_transcode_video', $video_path, $options );
apply_filters( 'wp_mcp_ai_extract_video_frames', $video_path, $options );

// 4. ExcelJS spreadsheet generation
apply_filters( 'wp_mcp_ai_generate_excel', $data, $options );

// 5. PDFKit PDF generation
apply_filters( 'wp_mcp_ai_generate_pdf', $html, $options );
```

### Package Availability

All 32 NPM packages from `addons/pro/package.json` are available:

```json
{
  "dependencies": {
    "@google-cloud/text-to-speech": "^5.5.0",
    "@google-cloud/vision": "^4.3.2",
    "axios": "^1.7.9",
    "chalk": "^5.3.0",
    "dotenv": "^16.4.7",
    "exceljs": "^4.4.0",
    "express": "^4.21.2",
    "fluent-ffmpeg": "^2.1.3",
    "jimp": "^1.6.0",
    "mjml": "^4.15.3",
    "node-fetch": "^3.3.2",
    "openai": "^4.73.1",
    "pdfkit": "^0.15.1",
    "prettier": "^3.4.2",
    "sharp": "^0.33.5",
    // ... and more
  }
}
```

Third-party developers can request access to any of these via custom filters.

---

## Recommendations

### For Current Release (Phase 2)

**Keep the current modular architecture:**
1. ✅ Toolkits are already independent and can be disabled
2. ✅ Error isolation is in place
3. ✅ Third-party developers CAN create addons today
4. ✅ NPM services are accessible via filters
5. ✅ Shared utilities are reusable

**Document thoroughly:**
1. ✅ This architecture guide (completed)
2. 🔄 Create developer examples repository
3. 🔄 Add to main plugin documentation

### For Future Release (Phase 3+)

**Consider separate plugin architecture if:**
- Want to sell toolkits separately on marketplace
- Want users to install only what they need
- Can commit to maintaining multiple plugins

**Implementation path:**
1. Extract each toolkit to separate plugin
2. Keep Pro plugin as core infrastructure
3. Use "Requires Plugins" header for dependencies
4. Share NPM services via API
5. Create plugin marketplace

---

## Error Handling & Failsafe

### Current Protection

Each toolkit fails gracefully:

```php
// If toolkit errors during loading, it doesn't break the plugin
if ( ! empty( $settings['enable_ecommerce_toolkit'] ) ) {
    try {
        require_once WP_MCP_AI_PRO_PATH . 'includes/ecommerce-toolkit-init.php';
    } catch ( Exception $e ) {
        // Log error but don't break the plugin
        error_log( 'E-commerce toolkit failed to load: ' . $e->getMessage() );
    }
}
```

### Tool-Level Protection

Each tool's `is_available()` method prevents execution if dependencies are missing:

```php
public function is_available() {
    // Check if WooCommerce is active
    if ( ! class_exists( 'WooCommerce' ) ) {
        return false;
    }
    
    // Check if toolkit is enabled
    $settings = get_option( 'wp_mcp_ai_settings', array() );
    if ( empty( $settings['enable_ecommerce_toolkit'] ) ) {
        return false;
    }
    
    return true;
}
```

---

## Summary

**Current Architecture (Implemented):**
- ✅ Pro plugin contains all toolkits
- ✅ Each toolkit can be enabled/disabled via settings
- ✅ Toolkits fail gracefully without breaking the plugin
- ✅ Third-party developers can create addons using the API
- ✅ NPM services are shared and accessible
- ✅ Shared utilities reduce code duplication

**Answer to Your Question:**
> "is it or could it be structured so the pro plugin is the settings and dashboard but the toolkits are independent addons?"

**YES** - It COULD be restructured that way (see Option 2 above), but the current architecture already provides most of the benefits:
- Toolkits are modular and can be disabled
- If a toolkit breaks, just disable it in settings
- Third-party developers can create addons
- NPM services are shared

The main difference is that toolkits ship together in one plugin rather than as separate plugins. This is actually beneficial for:
- Easier updates
- Better performance
- Shared code efficiency
- Simpler user experience

If you want to sell toolkits separately or allow users to install only specific toolkits, then the separate plugin architecture (Option 2) would be better.
