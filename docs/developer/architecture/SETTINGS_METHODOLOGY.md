# NV oOS Settings Methodology

**Last Updated:** February 5, 2026  
**Plugin Version:** 1.1.0  
**Status:** Production

## Table of Contents

1. [Overview](#overview)
2. [Two Settings Pages in NV oOS](#two-settings-pages-in-nv-oos)
3. [WordPress Options vs Settings API](#wordpress-options-vs-settings-api)
4. [Two WordPress Settings Approaches](#two-wordpress-settings-approaches)
5. [WordPress Settings API](#wordpress-settings-api)
6. [Admin Post API](#admin-post-api)
7. [Why NV oOS Uses Admin Post API](#why-nv-oos-uses-admin-post-api)
8. [Data Storage & Retrieval](#data-storage--retrieval)
9. [Data Flow Diagrams](#data-flow-diagrams)
10. [Code Examples](#code-examples)
11. [Common Issues & Fixes](#common-issues--fixes)
12. [Best Practices](#best-practices)
13. [References](#references)

---

## Overview

NV oOS provides **two settings pages** with different purposes:

1. **Main Settings Dashboard** (`admin.php?page=wp-mcp-ai-dashboard`)
   - Complex tabbed interface with 8 main tabs
   - Advanced UI with subtabs and dynamic sections
   - Tab-specific saves (only saves active tab)
   - Located under "NV oOS" top-level menu

2. **Simple Settings Page** (`options-general.php?page=wp-mcp-ai-simple-settings`)
   - Flat list of all settings for diagnostics
   - Simple two-tab interface (General + Providers)
   - Saves all visible fields at once
   - Located under WordPress "Settings" menu

Both pages use a **hybrid approach**:
- **Settings registration** via WordPress Settings API (for WordPress compatibility)
- **Form submission** via Admin Post API (for custom processing)
- **Data storage** via WordPress Options API (single option)

This document explains both pages, the APIs used, and how settings are saved, stored, and retrieved.

---

## Two Settings Pages in NV oOS

NV oOS provides two distinct settings pages, each serving a different purpose:

### 1. Main Settings Dashboard (Default)

**URL:** `admin.php?page=wp-mcp-ai-dashboard`  
**Location:** Top-level "NV oOS" menu → "General Settings"  
**Purpose:** Primary settings interface for daily configuration

#### Features

- **8 Main Tabs:**
  1. Overview - Dashboard summary and system status
  2. General - Core plugin settings
  3. Providers - AI provider configuration (OpenAI, Gemini, Ollama, etc.)
  4. Tools - Tool management and features
  5. Orchestration - Multi-agent orchestration settings
  6. Integrations - Third-party service connections
  7. Token Manager - Usage tracking and limits
  8. Advanced - Performance, logging, federation, etc.

- **Subtabs:** Many tabs have subtabs (e.g., Tools → Features, Configuration, Connections)
- **Dynamic UI:** Conditional fields, real-time validation, AJAX features
- **Tab-Specific Saves:** Only saves settings from the active tab
- **350+ Settings Fields:** Comprehensive configuration options

#### Save Behavior

```php
// Main Dashboard saves ONLY active tab
$active_tab = 'tools';  // From form submission
$save_all_tabs = false; // Default behavior

// Result: Only 'tools' tab settings are sanitized and saved
// Other tabs' settings are preserved from existing option
```

#### Use Cases

✅ Daily configuration changes  
✅ Adjusting specific provider settings  
✅ Enabling/disabling features  
✅ Configuring integrations  
✅ Most common administrative tasks  

### 2. Simple Settings Page (Diagnostic)

**URL:** `options-general.php?page=wp-mcp-ai-simple-settings`  
**Location:** WordPress "Settings" menu → "NV oOS"  
**Purpose:** Diagnostic view and bulk editing

#### Features

- **2 Tabs:**
  1. General - Core settings in flat list
  2. Providers - All provider settings in flat list

- **Flat Layout:** All settings shown in simple table format
- **Grouped by Category:** Logical grouping (not alphabetical)
- **Saves All Visible Fields:** No tab-specific saves
- **Diagnostic Tool:** Verify settings values, troubleshoot issues

#### Save Behavior

```php
// Simple Settings saves ONLY active tab (after fix)
$active_tab = 'general';  // Currently viewing General tab
$save_all_tabs = false;    // Changed from true to prevent data loss

// Result: Only 'general' tab settings are sanitized and saved
// Settings from other tabs (tools, orchestration, etc.) are preserved
```

**Important:** Prior to this fix, the Simple Settings Page had `save_all_tabs=1` which caused data loss because:
- Form only displayed General OR Providers fields
- But handler sanitized ALL tabs (General, Providers, Tools, Orchestration, etc.)
- Checkboxes from invisible tabs would be treated as unchecked
- Result: Settings from other tabs could be wiped out

**Fix Applied:** Removed `save_all_tabs=1` flag. Now Simple Settings behaves like Main Dashboard - only saves the active tab.

#### Use Cases

✅ Troubleshooting settings issues  
✅ Verifying saved values for General or Providers  
✅ Editing General/Providers settings in table format  
✅ Checking for missing/incorrect values in visible tabs  
✅ Advanced users who prefer flat view  
⚠️ **Not for bulk editing across all tabs** (only shows 2 of 8 tabs)  

### Comparison Table

| Feature | Main Dashboard | Simple Settings |
|---------|---------------|----------------|
| **URL** | `admin.php?page=wp-mcp-ai-dashboard` | `options-general.php?page=wp-mcp-ai-simple-settings` |
| **Location** | NV oOS → General Settings | Settings → NV oOS |
| **Tabs** | 8 main tabs with subtabs | 2 flat tabs |
| **UI** | Complex, dynamic, tabbed | Simple, flat, table-based |
| **Fields Shown** | ~50-80 per tab | All ~350+ fields |
| **Save Scope** | Active tab only | Active tab only (after fix) |
| **Form Action** | `admin-post.php` | `admin-post.php` |
| **Handler** | `wp_mcp_ai_save_settings` | `wp_mcp_ai_save_settings` (same) |
| **Special Flag** | `save_all_tabs=0` | `save_all_tabs=0` (after fix) |
| **Redirect** | Back to same tab | Back to simple settings |
| **Purpose** | Primary configuration | Diagnostics and verification |
| **Target Users** | All administrators | Advanced users, developers |

### When to Use Each Page

**Use Main Dashboard When:**
- ✅ Making routine configuration changes
- ✅ You want a guided, organized UI
- ✅ You're working on a specific area (e.g., just providers)
- ✅ You need subtabs and grouped settings
- ✅ You want to see only relevant fields

**Use Simple Settings When:**
- ✅ Troubleshooting General or Providers settings
- ✅ Verifying what's stored for General/Providers tabs
- ✅ Need to see General or Providers settings in table format
- ✅ Prefer simple UI over complex tabs
- ⚠️ Only works for General and Providers tabs (not all tabs)

### Technical Implementation

Both pages share the same backend handler and now behave the same way:

```php
// Shared handler: includes/admin/class-wp-mcp-ai-settings-dashboard.php
public function handle_save_settings() {
    // ...security checks...
    
    $save_all_tabs = isset( $_POST['save_all_tabs'] ) && '1' === $_POST['save_all_tabs'];
    $active_tab = isset( $_POST['active_tab'] ) ? sanitize_key( $_POST['active_tab'] ) : '';
    
    // Both pages now use tab-specific saves (save_all_tabs = false)
    if ( $save_all_tabs ) {
        // DISABLED: This would sanitize ALL tabs
        // Dangerous if form doesn't display all fields
        $sections = WP_MCP_AI_Settings_Registry::get_all_sections();
    } else {
        // Standard behavior: sanitize ONLY active tab
        // Main Dashboard: saves active tab (e.g., 'tools')
        // Simple Settings: saves active tab ('general' or 'providers')
        $sections = WP_MCP_AI_Settings_Registry::get_sections( $active_tab );
    }
    
    // ... sanitization and save ...
    
    // Redirect back to appropriate page
    $redirect_page = isset( $_POST['redirect_page'] ) 
        ? sanitize_key( $_POST['redirect_page'] ) 
        : self::PAGE_SLUG;
}
```

**Note:** The `save_all_tabs` flag was removed from Simple Settings Page to prevent data loss. It was causing settings from invisible tabs (Tools, Orchestration, etc.) to be cleared because those checkboxes weren't in the form.

### Data Consistency

Both pages read from and write to the **same WordPress option**:
- **Option Name:** `wp_mcp_ai_settings`
- **Storage:** WordPress `wp_options` table
- **Format:** Serialized PHP array

This ensures:
- ✅ Changes in Main Dashboard appear in Simple Settings
- ✅ Changes in Simple Settings appear in Main Dashboard
- ✅ No data conflicts or synchronization issues
- ✅ Single source of truth

---

## WordPress Options vs Settings API

Before diving into form submission methods, it's important to understand the difference between **WordPress Options** (data storage) and **Settings API** (form handling).

### WordPress Options API (Data Storage)

The **Options API** is WordPress's system for storing configuration data in the database.

**What It Does:**
- Stores key-value pairs in `wp_options` table
- Provides functions to save, retrieve, update, delete options
- Handles serialization automatically
- Used by core WordPress and all plugins

**Core Functions:**

```php
// Get option
$value = get_option( 'option_name', 'default_value' );

// Add option (only if doesn't exist)
add_option( 'option_name', $value, '', 'yes' );

// Update option (creates if doesn't exist)
update_option( 'option_name', $value );

// Delete option
delete_option( 'option_name' );
```

**NV oOS Usage:**

```php
// All settings stored in single option
$settings = get_option( 'wp_mcp_ai_settings', array() );

// Update settings
update_option( 'wp_mcp_ai_settings', $new_settings );
```

**Database Table:**

```sql
SELECT * FROM wp_options WHERE option_name = 'wp_mcp_ai_settings';

+----------+--------------------------+------------------------------+----------+
| option_id| option_name              | option_value                 | autoload |
+----------+--------------------------+------------------------------+----------+
| 12345    | wp_mcp_ai_settings       | a:350:{s:14:"enable_logging";| yes      |
|          |                          | b:1;s:16:"default_provider"; |          |
|          |                          | s:6:"openai";...}            |          |
+----------+--------------------------+------------------------------+----------+
```

### WordPress Settings API (Form Handling)

The **Settings API** is WordPress's system for creating and processing settings forms.

**What It Does:**
- Registers settings with WordPress
- Creates nonces for security
- Handles form submission
- Validates and sanitizes input
- Manages settings sections and fields
- Provides UI helpers

**Why Use It:**
- WordPress knows about your settings
- Enables settings export/import
- Compatible with WordPress tools
- Follows WordPress standards
- Automatic security handling (when using `options.php`)

**Registration Example:**

```php
// Register setting with WordPress
register_setting(
    'my_plugin_group',        // Option group
    'my_plugin_options',      // Option name (stored via Options API)
    array(
        'type'              => 'array',
        'sanitize_callback' => 'my_sanitize_function',
        'default'           => array(),
    )
);
```

**Key Point:** Settings API uses Options API for storage. They work together:
- **Settings API** = Form handling + validation
- **Options API** = Data storage

### NV oOS Hybrid Approach

```
┌──────────────────────────────────────┐
│     Settings API (Registration)      │
│  ✓ Makes WordPress aware of settings │
│  ✓ Enables export/import             │
│  ✓ WordPress ecosystem compatibility │
└──────────────┬───────────────────────┘
               │
               ▼
┌──────────────────────────────────────┐
│   Admin Post API (Form Submission)   │
│  ✓ Custom validation per tab         │
│  ✓ Complex multi-tab interface       │
│  ✓ Subtab support                    │
│  ✓ Side effects on save              │
└──────────────┬───────────────────────┘
               │
               ▼
┌──────────────────────────────────────┐
│     Options API (Data Storage)       │
│  ✓ Single option: wp_mcp_ai_settings │
│  ✓ Stored in wp_options table        │
│  ✓ Serialized PHP array              │
└──────────────────────────────────────┘
```

**Why This Approach:**
1. WordPress awareness (Settings API registration)
2. Custom processing (Admin Post API submission)
3. Simple storage (Options API - single option)

---

## Two WordPress Settings Approaches

WordPress provides two built-in methods for handling settings forms:

| Feature | WordPress Settings API | Admin Post API |
|---------|----------------------|----------------|
| **Form Action** | `options.php` | `admin-post.php` |
| **Registration** | `register_setting()` | `add_action('admin_post_{action}', ...)` |
| **Nonce Creation** | `settings_fields()` | `wp_nonce_field()` |
| **Nonce Validation** | Automatic by WordPress | Manual via `check_admin_referer()` |
| **Sanitization** | Via `sanitize_callback` | Manual in handler function |
| **Redirection** | Automatic back to form | Manual via `wp_safe_redirect()` |
| **Use Case** | Simple option pages | Complex forms with custom logic |
| **Complexity** | Low - WordPress handles everything | Medium - You control everything |

**Important:** You **cannot mix** both approaches in the same form! This causes nonce conflicts.

---

## WordPress Settings API

### Overview

The **Settings API** is WordPress's built-in system for creating simple settings pages. It handles everything automatically.

### How It Works

```php
// 1. REGISTER SETTING
add_action( 'admin_init', 'my_plugin_register_settings' );
function my_plugin_register_settings() {
    register_setting(
        'my_plugin_settings_group',  // Option group
        'my_plugin_options',          // Option name
        array(
            'sanitize_callback' => 'my_plugin_sanitize_settings',
        )
    );
}

// 2. CREATE FORM (submits to options.php)
<form method="post" action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>">
    <?php settings_fields( 'my_plugin_settings_group' ); ?>
    
    <input type="text" name="my_plugin_options[api_key]" value="..." />
    
    <?php submit_button(); ?>
</form>

// 3. WORDPRESS HANDLES AUTOMATICALLY:
// - Validates nonce (created by settings_fields())
// - Checks user permissions
// - Calls sanitize_callback
// - Saves to database via update_option()
// - Redirects back to form with ?updated=true
```

### What Gets Saved

- **Where:** WordPress options table (`wp_options`)
- **How:** Via `update_option( 'my_plugin_options', $sanitized_data )`
- **Format:** Serialized PHP array (typically)

### Hidden Fields Created by `settings_fields()`

When you call `settings_fields('my_plugin_settings_group')`, WordPress creates:

```html
<input type="hidden" name="option_page" value="my_plugin_settings_group" />
<input type="hidden" name="action" value="update" />
<input type="hidden" name="_wpnonce" value="[nonce for 'my_plugin_settings_group-options']" />
<input type="hidden" name="_wp_http_referer" value="[current page URL]" />
```

### Limitations

❌ No custom redirect logic  
❌ Limited control over save process  
❌ Cannot handle multi-tab interfaces easily  
❌ Difficult to add custom validation per tab  
❌ Harder to integrate with complex UI flows  

### When to Use

✅ Simple single-page settings forms  
✅ Standard WordPress admin pages  
✅ When you don't need custom processing  
✅ Plugins with basic configuration needs  

---

## Admin Post API

### Overview

The **Admin Post API** gives you full control over form processing. You handle everything: nonce validation, sanitization, saving, and redirection.

### How It Works

```php
// 1. REGISTER ACTION HANDLER
add_action( 'admin_post_my_custom_save_action', 'my_plugin_handle_save' );

function my_plugin_handle_save() {
    // 2. VERIFY NONCE
    check_admin_referer( 'my_plugin_save_settings' );
    
    // 3. CHECK PERMISSIONS
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Insufficient permissions' );
    }
    
    // 4. GET POSTED DATA
    $posted = isset( $_POST['my_plugin_settings'] ) 
        ? wp_unslash( $_POST['my_plugin_settings'] ) 
        : array();
    
    // 5. SANITIZE (custom logic)
    $sanitized = array();
    foreach ( $posted as $key => $value ) {
        if ( 'api_key' === $key ) {
            $sanitized[ $key ] = sanitize_text_field( $value );
        } elseif ( 'enable_feature' === $key ) {
            $sanitized[ $key ] = ! empty( $value );
        }
        // ... more custom logic
    }
    
    // 6. SAVE TO DATABASE
    update_option( 'my_plugin_options', $sanitized );
    
    // 7. CUSTOM REDIRECT
    wp_safe_redirect( 
        add_query_arg( 
            array( 'page' => 'my-plugin-settings', 'updated' => 'true' ),
            admin_url( 'admin.php' )
        )
    );
    exit;
}

// 8. CREATE FORM (submits to admin-post.php)
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'my_plugin_save_settings' ); ?>
    <input type="hidden" name="action" value="my_custom_save_action" />
    
    <input type="text" name="my_plugin_settings[api_key]" value="..." />
    
    <?php submit_button(); ?>
</form>
```

### What Gets Saved

- **Where:** WordPress options table (`wp_options`) - same as Settings API
- **How:** Via `update_option()` in your custom handler
- **Format:** Any format you choose (array, JSON, serialized object, etc.)
- **Control:** Full control over what gets saved and how

### Hidden Fields You Must Create

```html
<input type="hidden" name="action" value="my_custom_save_action" />
<!-- This tells admin-post.php which action hook to fire -->
```

Plus nonce via `wp_nonce_field( 'my_nonce_action' )`:

```html
<input type="hidden" name="_wpnonce" value="[nonce for 'my_nonce_action']" />
<input type="hidden" name="_wp_http_referer" value="[current page URL]" />
```

### Benefits

✅ Full control over save process  
✅ Custom validation logic per tab/section  
✅ Custom redirect logic  
✅ Can handle complex multi-tab UIs  
✅ Easier to integrate with existing systems  
✅ Can perform additional actions during save  

### When to Use

✅ Complex multi-tab settings interfaces  
✅ When you need custom validation per section  
✅ When you need to perform side effects (clear cache, trigger actions, etc.)  
✅ When you need granular control over the save process  
✅ Enterprise plugins with complex requirements  

---

## Why NV oOS Uses Admin Post API

NV oOS has a complex tabbed settings interface with:
- **8 main tabs** (Overview, General, Providers, Tools, etc.)
- **Multiple subtabs per tab** (e.g., Tools → Features, Configuration, Connections)
- **350+ settings fields** across all tabs
- **Custom validation** per section
- **Side effects** on save (clearing caches, triggering background tasks)
- **Performance optimizations** (selective saves per tab)

### Requirements That Admin Post API Meets

1. **Tab-Aware Saving**
   - Only save settings for the active tab
   - Preserve settings from other tabs
   - Different validation rules per tab

2. **Subtab Support**
   - Handle nested subtabs (Tools → Features → Pro Features)
   - Track active subtab for correct sanitization
   - Redirect back to correct subtab after save

3. **Complex Validation**
   - Different sections have different validation needs
   - Some fields depend on other fields
   - Conditional validation based on active tab

4. **Side Effects**
   - Clear settings cache after save
   - Trigger background tasks (e.g., sync playbooks)
   - Log settings changes for audit trail

5. **Custom Error Handling**
   - Display specific error messages per field
   - Allow retry logic
   - Preserve form state on error

### NV oOS Implementation

```php
// In includes/admin/class-wp-mcp-ai-settings-dashboard.php

public function __construct() {
    // Register the admin-post action handler
    add_action( 'admin_post_wp_mcp_ai_save_settings', array( $this, 'handle_save_settings' ) );
}

public function handle_save_settings() {
    // 1. Security checks
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'You do not have permission to access this page.' );
    }
    
    check_admin_referer( 'wp_mcp_ai_save_settings' );
    
    // 2. Get posted data
    $posted_settings = isset( $_POST['wp_mcp_ai_settings'] ) 
        ? wp_unslash( $_POST['wp_mcp_ai_settings'] ) 
        : array();
    
    $active_tab = isset( $_POST['active_tab'] ) 
        ? sanitize_key( $_POST['active_tab'] ) 
        : '';
    
    // 3. Get existing settings (to preserve other tabs)
    $existing_settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
    
    // 4. Sanitize only the active tab's settings
    $sections = WP_MCP_AI_Settings_Registry::get_sections( $active_tab );
    $sanitized = array();
    
    foreach ( $sections as $section ) {
        $section_input = $section->sanitize( $posted_settings );
        $sanitized = array_merge( $sanitized, $section_input );
    }
    
    // 5. Merge with existing settings (preserve other tabs)
    $merged_settings = array_merge( $existing_settings, $sanitized );
    
    // 6. Save to database
    update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $merged_settings );
    
    // 7. Clear settings cache
    delete_transient( 'wp_mcp_ai_settings_cache' );
    
    // 8. Custom redirect back to same tab/subtab
    wp_safe_redirect( 
        add_query_arg( 
            array( 
                'page'    => self::PAGE_SLUG,
                'tab'     => $active_tab,
                'updated' => 'true',
            ),
            admin_url( 'admin.php' )
        )
    );
    exit;
}
```

### Form Structure

```php
// In includes/admin/class-wp-mcp-ai-settings-dashboard.php (line ~1075)

<form id="wp-mcp-ai-settings-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'wp_mcp_ai_save_settings' ); ?>
    <input type="hidden" name="action" value="wp_mcp_ai_save_settings" />
    <input type="hidden" name="active_tab" value="<?php echo esc_attr( $active_tab ); ?>" />
    
    <!-- Settings fields rendered here -->
    
    <?php submit_button(); ?>
</form>
```

**Note:** We do NOT use `settings_fields()` because that's for the Settings API (options.php). Using both would create nonce conflicts! See [Common Issues](#common-issues--fixes) below.

---

## Data Storage & Retrieval

### Where Settings Are Stored

All NV oOS settings are stored in the WordPress options table:

```sql
SELECT * FROM wp_options WHERE option_name = 'wp_mcp_ai_settings';
```

**Option Name:** `wp_mcp_ai_settings`  
**Format:** Serialized PHP array  
**Autoload:** Yes (loaded on every WordPress request)

### Data Structure

```php
array(
    // General settings
    'enable_logging'          => true,
    'default_provider'        => 'openai',
    'request_timeout'         => 300,
    
    // API keys (stored encrypted in some cases)
    'openai_api_key'          => 'sk-...',
    'gemini_api_key'          => 'AIza...',
    
    // Feature flags
    'enable_mesh'             => false,
    'enable_federation'       => true,
    
    // Pro features
    'enable_quiz_system'      => true,
    'enable_media_toolkit'    => true,
    
    // ... 350+ more settings
)
```

### Retrieving Settings

```php
// Get all settings
$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

// Get specific setting with default
$api_key = isset( $settings['openai_api_key'] ) 
    ? $settings['openai_api_key'] 
    : '';

// Or use helper method
$api_key = WP_MCP_AI_Admin_Settings::get_setting( 'openai_api_key', '' );
```

### Updating Settings

```php
// Get existing settings
$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

// Update specific setting
$settings['enable_logging'] = true;

// Save back to database
update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $settings );

// Clear cache (important!)
delete_transient( 'wp_mcp_ai_settings_cache' );
```

### Settings Cache

NV oOS uses transient caching for performance:

```php
// Check cache first
$cached = get_transient( 'wp_mcp_ai_settings_cache' );
if ( false !== $cached ) {
    return $cached;
}

// Load from database
$settings = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );

// Cache for 1 hour
set_transient( 'wp_mcp_ai_settings_cache', $settings, HOUR_IN_SECONDS );

return $settings;
```

**Cache Key:** `wp_mcp_ai_settings_cache`  
**Duration:** 1 hour  
**Cleared:** On settings save, plugin activation, cache flush

---

## Data Flow Diagrams

### Settings API Flow (Not Used in NV oOS)

```
┌─────────────────┐
│  Settings Form  │
│                 │
│ ┌─────────────┐ │
│ │Form Fields  │ │
│ └─────────────┘ │
│                 │
│ Action:         │
│ options.php     │
└────────┬────────┘
         │ POST
         ▼
┌─────────────────┐
│  options.php    │ ← WordPress Core
│                 │
│ 1. Validate     │
│    nonce        │
│ 2. Check        │
│    permissions  │
│ 3. Call         │
│    sanitize_    │
│    callback     │
│ 4. update_      │
│    option()     │
│ 5. Redirect     │
└────────┬────────┘
         │ Redirect
         ▼
┌─────────────────┐
│ Back to Form    │
│ ?updated=true   │
└─────────────────┘
```

### Admin Post API Flow (Used in NV oOS)

```
┌─────────────────┐
│  Settings Form  │
│ (Dashboard UI)  │
│                 │
│ ┌─────────────┐ │
│ │Tabs/Subtabs │ │
│ │350+ Fields  │ │
│ └─────────────┘ │
│                 │
│ Action:         │
│ admin-post.php  │
└────────┬────────┘
         │ POST with:
         │ - action: wp_mcp_ai_save_settings
         │ - active_tab: tools
         │ - wp_mcp_ai_settings[...]: posted data
         ▼
┌─────────────────────────────┐
│     admin-post.php          │ ← WordPress Core
│                             │
│ Fires action hook:          │
│ 'admin_post_                │
│  wp_mcp_ai_save_settings'   │
└────────┬────────────────────┘
         │
         ▼
┌─────────────────────────────────────┐
│ WP_MCP_AI_Settings_Dashboard        │
│ ::handle_save_settings()            │
│                                     │
│ 1. check_admin_referer()            │
│    ├─ Validates nonce               │
│    └─ Dies if invalid               │
│                                     │
│ 2. current_user_can()               │
│    ├─ Check manage_options          │
│    └─ Dies if unauthorized          │
│                                     │
│ 3. Get posted data                  │
│    ├─ $_POST['wp_mcp_ai_settings']  │
│    ├─ $_POST['active_tab']          │
│    └─ $_POST['subtab_*'] (if any)   │
│                                     │
│ 4. Get existing settings            │
│    └─ get_option('wp_mcp_ai_...')   │
│                                     │
│ 5. Sanitize per section             │
│    ├─ Get sections for active tab   │
│    ├─ Call section->sanitize()      │
│    └─ Handle subtab-specific logic  │
│                                     │
│ 6. Merge with existing              │
│    └─ Preserve other tabs' data     │
│                                     │
│ 7. Validate (optional)              │
│    └─ Custom validation logic       │
│                                     │
│ 8. Save to database                 │
│    └─ update_option()               │
│                                     │
│ 9. Clear cache                      │
│    └─ delete_transient()            │
│                                     │
│ 10. Side effects                    │
│     ├─ Trigger background tasks     │
│     ├─ Clear other caches           │
│     └─ Log changes                  │
│                                     │
│ 11. wp_safe_redirect()              │
│     └─ Back to same tab/subtab      │
└────────┬────────────────────────────┘
         │ Redirect
         ▼
┌─────────────────┐
│ Back to Form    │
│ Same Tab Active │
│ ?updated=true   │
└─────────────────┘
```

---

## Code Examples

### Example 1: Simple Settings API Form (Not in NV oOS)

```php
// register-settings.php
add_action( 'admin_init', 'my_plugin_register_settings' );
function my_plugin_register_settings() {
    register_setting(
        'my_plugin_group',
        'my_plugin_options',
        array(
            'sanitize_callback' => 'my_plugin_sanitize',
        )
    );
}

function my_plugin_sanitize( $input ) {
    $sanitized = array();
    
    if ( isset( $input['api_key'] ) ) {
        $sanitized['api_key'] = sanitize_text_field( $input['api_key'] );
    }
    
    if ( isset( $input['enable_feature'] ) ) {
        $sanitized['enable_feature'] = ! empty( $input['enable_feature'] );
    }
    
    return $sanitized;
}

// settings-page.php
?>
<form method="post" action="options.php">
    <?php settings_fields( 'my_plugin_group' ); ?>
    
    <input type="text" 
           name="my_plugin_options[api_key]" 
           value="<?php echo esc_attr( $options['api_key'] ); ?>" />
    
    <input type="checkbox" 
           name="my_plugin_options[enable_feature]" 
           <?php checked( ! empty( $options['enable_feature'] ) ); ?> />
    
    <?php submit_button(); ?>
</form>
```

### Example 2: Admin Post API Form (NV oOS Approach)

```php
// class-settings-dashboard.php
class My_Settings_Dashboard {
    
    public function __construct() {
        add_action( 'admin_post_my_save_action', array( $this, 'handle_save' ) );
    }
    
    public function handle_save() {
        // Verify nonce
        check_admin_referer( 'my_save_nonce' );
        
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions' );
        }
        
        // Get posted data
        $posted = isset( $_POST['my_settings'] ) 
            ? wp_unslash( $_POST['my_settings'] ) 
            : array();
        
        // Sanitize
        $sanitized = array();
        if ( isset( $posted['api_key'] ) ) {
            $sanitized['api_key'] = sanitize_text_field( $posted['api_key'] );
        }
        if ( isset( $posted['enable_feature'] ) ) {
            $sanitized['enable_feature'] = ! empty( $posted['enable_feature'] );
        }
        
        // Save
        update_option( 'my_plugin_options', $sanitized );
        
        // Redirect
        wp_safe_redirect(
            add_query_arg( 
                array( 'page' => 'my-settings', 'updated' => 'true' ),
                admin_url( 'admin.php' )
            )
        );
        exit;
    }
    
    public function render_page() {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'my_save_nonce' ); ?>
            <input type="hidden" name="action" value="my_save_action" />
            
            <input type="text" name="my_settings[api_key]" value="..." />
            <input type="checkbox" name="my_settings[enable_feature]" />
            
            <?php submit_button(); ?>
        </form>
        <?php
    }
}
```

### Example 3: Hybrid Approach (NV oOS Registration)

NV oOS registers settings with Settings API (for WordPress compatibility) but uses Admin Post API for submission:

```php
// Register with Settings API (for WordPress awareness)
public function register_settings() {
    register_setting(
        'wp_mcp_ai_settings_group',
        WP_MCP_AI_Admin_Settings::OPTION_NAME,
        array(
            'type' => 'array',
            // NO sanitize_callback - we handle it manually
        )
    );
}

// But form submits to admin-post.php (NOT options.php)
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'wp_mcp_ai_save_settings' ); ?>
    <!-- NO settings_fields() call! That would conflict. -->
    <input type="hidden" name="action" value="wp_mcp_ai_save_settings" />
    ...
</form>
```

This hybrid approach gives us:
- WordPress awareness of our settings (for export/import)
- Full control over save process (for complex logic)

---

## Common Issues & Fixes

### Issue 1: "The link you followed has expired" ❌

**Symptoms:**
- Form submission fails
- WordPress shows "The link you followed has expired"
- All settings pages affected

**Root Cause:**
Mixing Settings API with Admin Post API creates nonce conflicts.

**Example of Bug:**
```php
// ❌ WRONG - This creates TWO different nonces!
<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
    <?php wp_nonce_field( 'my_save_action' ); ?>
    <?php settings_fields( 'my_settings_group' ); ?> <!-- CONFLICT! -->
    <input type="hidden" name="action" value="my_save_action" />
</form>
```

`settings_fields()` creates a nonce for 'my_settings_group-options', but the handler checks for 'my_save_action'.

**Fix:**
```php
// ✅ CORRECT - Single nonce for admin-post.php
<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
    <?php wp_nonce_field( 'my_save_action' ); ?>
    <input type="hidden" name="action" value="my_save_action" />
</form>
```

**See:** [FIX_NONCE_CONFLICT.md](../../FIX_NONCE_CONFLICT.md) for detailed fix documentation.

### Issue 2: Settings Not Saving ❌

**Symptoms:**
- Form submits but settings don't persist
- No error message shown

**Possible Causes:**

1. **Action handler not registered:**
```php
// ❌ Missing action registration
// No add_action( 'admin_post_my_action', ... )
```

Fix:
```php
// ✅ Register action handler
add_action( 'admin_post_my_action', 'my_handler_function' );
```

2. **Wrong action name in form:**
```php
// ❌ Action name mismatch
<input type="hidden" name="action" value="my_action" />
// But registered as: add_action( 'admin_post_save_settings', ... )
```

Fix: Make sure action names match exactly.

3. **Settings not merged with existing:**
```php
// ❌ Overwrites all settings (loses other tabs)
update_option( 'my_settings', $new_data );
```

Fix:
```php
// ✅ Merge with existing
$existing = get_option( 'my_settings', array() );
$merged = array_merge( $existing, $new_data );
update_option( 'my_settings', $merged );
```

### Issue 3: 403 Forbidden on admin-post.php ❌

**Symptoms:**
- Form submission returns HTTP 403 error
- Console shows "POST admin-post.php 403 (Forbidden)"

**Root Cause:**
WordPress can't find the action handler.

**Debugging:**
```php
// Add this to your plugin to verify action is registered
add_action( 'admin_init', function() {
    global $wp_filter;
    
    if ( isset( $wp_filter['admin_post_my_action'] ) ) {
        error_log( 'Action registered: admin_post_my_action' );
    } else {
        error_log( 'Action NOT registered: admin_post_my_action' );
    }
});
```

**Fix:** Ensure action is registered before admin-post.php runs:
```php
// ✅ Register early (in constructor or during plugin load)
add_action( 'admin_post_my_action', array( $this, 'handler' ) );
```

### Issue 4: Redirect Loop ❌

**Symptoms:**
- Form submission causes infinite redirects
- Browser shows "Too many redirects"

**Root Cause:**
Missing `exit;` after `wp_safe_redirect()`.

```php
// ❌ WRONG - Script continues after redirect
wp_safe_redirect( $url );
// More code here keeps executing!
```

Fix:
```php
// ✅ CORRECT - Always exit after redirect
wp_safe_redirect( $url );
exit;
```

---

## Best Practices

### 1. Choose the Right Method

**Use Settings API when:**
- ✅ You have a simple, single-page settings form
- ✅ You don't need custom validation logic
- ✅ You don't need side effects on save
- ✅ You want WordPress to handle everything automatically

**Use Admin Post API when:**
- ✅ You have a complex multi-tab interface
- ✅ You need custom validation per section
- ✅ You need to perform side effects (clear cache, trigger tasks, etc.)
- ✅ You need granular control over the save process
- ✅ You're building an enterprise-grade plugin

### 2. Never Mix Both Methods

❌ **DON'T DO THIS:**
```php
<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
    <?php wp_nonce_field( 'my_action' ); ?>
    <?php settings_fields( 'my_group' ); ?> <!-- CONFLICT! -->
    <input type="hidden" name="action" value="my_action" />
</form>
```

✅ **PICK ONE:**
```php
// Option A: Settings API
<form method="post" action="options.php">
    <?php settings_fields( 'my_group' ); ?>
    ...
</form>

// Option B: Admin Post API
<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
    <?php wp_nonce_field( 'my_action' ); ?>
    <input type="hidden" name="action" value="my_action" />
    ...
</form>
```

### 3. Always Validate Nonces

```php
// ✅ ALWAYS check nonce first
check_admin_referer( 'my_nonce_action' );

// This will:
// - Verify nonce is valid
// - Check nonce hasn't expired
// - Call wp_die() if validation fails
```

### 4. Always Check Permissions

```php
// ✅ ALWAYS verify user has permission
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( 'You do not have permission to access this page.' );
}
```

### 5. Always Sanitize Input

```php
// ✅ Sanitize based on field type
$sanitized = array();

foreach ( $input as $key => $value ) {
    switch ( $field_type ) {
        case 'text':
            $sanitized[ $key ] = sanitize_text_field( $value );
            break;
        
        case 'url':
            $sanitized[ $key ] = esc_url_raw( $value );
            break;
        
        case 'email':
            $sanitized[ $key ] = sanitize_email( $value );
            break;
        
        case 'checkbox':
            $sanitized[ $key ] = ! empty( $value );
            break;
        
        case 'number':
            $sanitized[ $key ] = absint( $value );
            break;
    }
}
```

### 6. Always Merge with Existing Settings

```php
// ✅ Preserve settings from other tabs
$existing = get_option( 'my_settings', array() );
$merged = array_merge( $existing, $sanitized );
update_option( 'my_settings', $merged );
```

### 7. Always Redirect After Save

```php
// ✅ Redirect to prevent duplicate submissions
wp_safe_redirect(
    add_query_arg(
        array( 'page' => 'my-settings', 'updated' => 'true' ),
        admin_url( 'admin.php' )
    )
);
exit; // ← CRITICAL! Always exit after redirect
```

### 8. Clear Caches After Save

```php
// ✅ Clear relevant caches
delete_transient( 'my_settings_cache' );
wp_cache_delete( 'my_settings', 'my_plugin' );

// If using object cache
wp_cache_flush();
```

### 9. Log Important Changes

```php
// ✅ Log for audit trail
if ( $settings['enable_feature'] !== $old_settings['enable_feature'] ) {
    error_log(
        sprintf(
            'User %d changed enable_feature from %s to %s',
            get_current_user_id(),
            var_export( $old_settings['enable_feature'], true ),
            var_export( $settings['enable_feature'], true )
        )
    );
}
```

### 10. Handle Errors Gracefully

```php
// ✅ Provide helpful error messages
if ( empty( $posted['api_key'] ) ) {
    wp_die(
        'API Key is required. Please go back and enter an API key.',
        'Missing API Key',
        array( 'back_link' => true )
    );
}
```

---

## References

### WordPress Documentation

- [Settings API](https://developer.wordpress.org/plugins/settings/settings-api/)
- [Admin Post API](https://developer.wordpress.org/reference/functions/wp_nonce_field/)
- [check_admin_referer()](https://developer.wordpress.org/reference/functions/check_admin_referer/)
- [register_setting()](https://developer.wordpress.org/reference/functions/register_setting/)
- [settings_fields()](https://developer.wordpress.org/reference/functions/settings_fields/)

### NV oOS Documentation

- [FIX_NONCE_CONFLICT.md](../../FIX_NONCE_CONFLICT.md) - Detailed fix for nonce conflicts
- [SIMPLE_SETTINGS_SAVER.md](SIMPLE_SETTINGS_SAVER.md) - Performance optimizations
- [ARCHITECTURE.md](ARCHITECTURE.md) - Overall plugin architecture

### Code References

- **Settings Dashboard:** `includes/admin/class-wp-mcp-ai-settings-dashboard.php`
- **Settings Registration:** Lines 199-220 (Settings API registration)
- **Form:** Lines 1075-1080 (Admin Post API form)
- **Save Handler:** Lines 268-600 (Admin Post API handler)
- **Settings Init:** `includes/admin/settings-dashboard-init.php`

---

## Conclusion

NV oOS uses a **hybrid approach** that combines the best of both worlds:

1. **Settings Registration** via WordPress Settings API
   - Makes WordPress aware of our settings
   - Enables standard WordPress settings export/import
   - Maintains compatibility with WordPress ecosystem

2. **Form Submission** via Admin Post API
   - Full control over save process
   - Complex multi-tab interface support
   - Custom validation per section
   - Side effects and background tasks
   - Optimal user experience

This architecture provides the flexibility and power needed for a complex enterprise plugin while maintaining compatibility with WordPress standards.

**Key Takeaway:** When building complex settings interfaces, use the Admin Post API for form submission but avoid mixing it with `settings_fields()` to prevent nonce conflicts.

---

**Questions or Issues?** See [Troubleshooting Guide](../troubleshooting/) or open a GitHub issue.
