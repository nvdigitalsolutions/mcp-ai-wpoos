# Pro Workflow Builder & Orchestration Dashboard Menu Fix - February 5, 2026

## Problem Statement

The Pro Workflow Builder admin page and Pro Orchestration Dashboard were not appearing under the "NV oOS" menu after recent PRs. Instead, they were incorrectly placed under "NV oOS Pro" menu.

**Affected URLs:**
- Pro Workflow Builder: `/wp-admin/admin.php?page=nvoos-pro-workflow-builder`
- Pro Orchestration Dashboard: `/wp-admin/admin.php?page=mcp-ai-orchestration-pro`

**Symptoms:**
- Menu items appeared under "NV oOS Pro" instead of "NV oOS"
- Pages were accessible but in wrong location
- Inconsistent with base Orchestration Dashboard placement

## Root Cause

The Pro Workflow Builder and Pro Orchestration Dashboard classes were registering their admin pages under the wrong parent menu slug.

### Menu Structure

WordPress has two separate top-level menus for this plugin:

1. **"NV oOS"** (Main Menu)
   - Slug: `wp-mcp-ai-dashboard`
   - Priority: 30
   - Purpose: Main plugin settings and operational dashboards
   - Icon: `dashicons-format-chat`

2. **"NV oOS Pro"** (Pro Dashboard)
   - Slug: `nvoos-pro-dashboard`
   - Priority: 25
   - Purpose: ISO 27001 compliance and enterprise features
   - Icon: `dashicons-shield-alt`

### Incorrect Registration

Both Pro pages were incorrectly using `nvoos-pro-dashboard` as their parent menu:

**Pro Workflow Builder** (`class-wp-mcp-ai-pro-workflow-builder-page.php`):
```php
add_submenu_page(
    'nvoos-pro-dashboard',  // ❌ WRONG - Should be under main NV oOS
    __( 'Pro Workflow Builder', 'mcp-ai-wpoos' ),
    __( 'Pro Workflows', 'mcp-ai-wpoos' ),
    'manage_options',
    'nvoos-pro-workflow-builder',
    array( $this, 'render_page' )
);
```

**Pro Orchestration Dashboard** (`class-wp-mcp-ai-orchestration-dashboard.php`):
```php
add_submenu_page(
    'nvoos-pro-dashboard',  // ❌ WRONG - Should be under main NV oOS
    __( 'Real-Time Orchestration Monitor (Pro)', 'mcp-ai-wpoos-pro' ),
    __( 'Orchestration Monitor', 'mcp-ai-wpoos-pro' ),
    'manage_options',
    'mcp-ai-orchestration-pro',
    array( $this, 'render_dashboard' )
);
```

### Why This Matters

The **base Orchestration Dashboard** was correctly placed under the main "NV oOS" menu:

```php
// Base version - CORRECT
add_submenu_page(
    'wp-mcp-ai-dashboard',  // ✅ Correct parent
    __( 'Orchestration Dashboard', 'mcp-ai-wpoos' ),
    __( 'Orchestration', 'mcp-ai-wpoos' ),
    'manage_options',
    'mcp-ai-orchestration',
    array( $this, 'render_dashboard' )
);
```

Operational dashboards (orchestration, workflows) should be under the main "NV oOS" menu, while compliance/enterprise features should be under "NV oOS Pro".

## Solution

### Change 1: Update Parent Menu Slug

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`

```php
public function register_page() {
    add_submenu_page(
        'wp-mcp-ai-dashboard',  // ✅ Changed from 'nvoos-pro-dashboard'
        __( 'Pro Workflow Builder', 'mcp-ai-wpoos' ),
        __( 'Pro Workflows', 'mcp-ai-wpoos' ),
        'manage_options',
        self::PAGE_SLUG,
        array( $this, 'render_page' )
    );
}
```

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-orchestration-dashboard.php`

```php
public function add_menu_page() {
    add_submenu_page(
        'wp-mcp-ai-dashboard',  // ✅ Changed from 'nvoos-pro-dashboard'
        __( 'Real-Time Orchestration Monitor (Pro)', 'mcp-ai-wpoos-pro' ),
        __( 'Orchestration Monitor', 'mcp-ai-wpoos-pro' ),
        'manage_options',
        'mcp-ai-orchestration-pro',
        array( $this, 'render_dashboard' )
    );
}
```

### Change 2: Update Admin Hook Expectations

When the parent menu changes, the WordPress admin hook format also changes. WordPress generates hooks as: `{sanitized_parent_title}_page_{submenu_slug}`

**Pro Workflow Builder Hook Update:**

```php
public function enqueue_assets( $hook ) {
    // Hook format: {sanitized_parent_title}_page_{PAGE_SLUG}
    // Parent menu title: "NV oOS" -> sanitized to "nv-oos"
    // Submenu slug: "nvoos-pro-workflow-builder"
    // Expected hook: nv-oos_page_nvoos-pro-workflow-builder
    $expected_hook = 'nv-oos_page_' . self::PAGE_SLUG;  // ✅ Changed from 'nvoos-pro-dashboard_page_'
    
    // Debug logging for troubleshooting asset enqueue issues.
    if ( $this->is_debug_logging_enabled() ) {
        error_log( sprintf( 
            'Workflow Builder: Hook=%s, Expected=%s, Match=%s', 
            $hook, 
            $expected_hook, 
            ( $expected_hook === $hook ) ? 'YES' : 'NO' 
        ) );
    }

    if ( $expected_hook !== $hook ) {
        return;
    }
    // ... rest of asset enqueuing
}
```

**Pro Orchestration Dashboard Hook Update:**

```php
public function enqueue_assets( $hook ) {
    // Check for orchestration page.
    // Hook format: 'nv-oos_page_mcp-ai-orchestration-pro'
    // Parent menu title: "NV oOS" -> sanitized to "nv-oos"
    // Also check via $_GET for additional safety.
    $is_orchestration_page = ( 'nv-oos_page_mcp-ai-orchestration-pro' === $hook ) ||  // ✅ Changed
        ( isset( $_GET['page'] ) && 'mcp-ai-orchestration-pro' === $_GET['page'] );

    // Debug logging for troubleshooting asset enqueue issues.
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( sprintf( 
            'Orchestration Dashboard: Hook=%s, GET page=%s, Is orchestration page=%s', 
            $hook, 
            isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'not set', 
            $is_orchestration_page ? 'YES' : 'NO' 
        ) );
    }

    if ( ! $is_orchestration_page ) {
        return;
    }
    // ... rest of asset enqueuing
}
```

### Change 3: Update Test Expectations

**File:** `tests/test-orchestration-dashboard-menu.php`

Updated `test_pro_orchestration_dashboard_registered()` to check for the Pro dashboard under the main NV oOS menu instead of the Pro dashboard:

```php
public function test_pro_orchestration_dashboard_registered() {
    // Skip if Pro addon is not active.
    if ( ! class_exists( 'WP_MCP_AI_Orchestration_Dashboard' ) ) {
        $this->markTestSkipped( 'Pro addon not active' );
    }

    global $submenu;

    // Trigger the admin_menu action to register menus.
    do_action( 'admin_menu' );

    // Pro orchestration dashboard should now be under the main NV oOS menu.
    $this->assertArrayHasKey(
        'wp-mcp-ai-dashboard',  // ✅ Changed from 'nvoos-pro-dashboard'
        $submenu,
        'Main NV oOS menu should be registered'
    );

    // Find the Pro orchestration dashboard in the main NV oOS submenu.
    $pro_orchestration_found = false;
    foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {  // ✅ Changed lookup location
        if ( isset( $item[2] ) && 'mcp-ai-orchestration-pro' === $item[2] ) {
            $pro_orchestration_found = true;
            $this->assertStringContainsString(
                'Orchestration',
                $item[0],
                'Pro orchestration menu title should contain "Orchestration"'
            );
            break;
        }
    }

    $this->assertTrue(
        $pro_orchestration_found,
        'Pro orchestration dashboard should be registered in main NV oOS submenu'
    );
}
```

Updated `test_orchestration_pages_use_different_slugs()` to look for both pages under the same menu:

```php
// Get base orchestration slug from base menu.
$base_slug = null;
if ( isset( $submenu['wp-mcp-ai-dashboard'] ) ) {
    foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
        if ( isset( $item[2] ) && 'mcp-ai-orchestration' === $item[2] ) {
            $base_slug = $item[2];
            break;
        }
    }
}

// Get Pro orchestration slug from the same main menu (both are now under wp-mcp-ai-dashboard).
$pro_slug = null;
if ( isset( $submenu['wp-mcp-ai-dashboard'] ) ) {  // ✅ Changed from 'nvoos-pro-dashboard'
    foreach ( $submenu['wp-mcp-ai-dashboard'] as $item ) {
        if ( isset( $item[2] ) && 'mcp-ai-orchestration-pro' === $item[2] ) {
            $pro_slug = $item[2];
            break;
        }
    }
}
```

## Testing

### Manual Verification Steps

1. **Navigate to WordPress Admin**
   ```
   /wp-admin/
   ```

2. **Verify Menu Structure:**
   - Look for "NV oOS" menu in the left sidebar
   - Expand the "NV oOS" menu
   - Verify these items appear:
     - General Settings
     - Orchestration (base)
     - Orchestration Monitor (Pro)
     - Pro Workflows
     - Other operational pages

3. **Verify "NV oOS Pro" Menu:**
   - Look for "NV oOS Pro" menu
   - Expand the menu
   - Verify it contains only compliance/enterprise features:
     - Overview
     - Security Audits
     - Security Training
     - Supplier Security
     - Asset Inventory

4. **Test Pro Workflow Builder:**
   ```
   Navigate to: NV oOS → Pro Workflows
   URL should be: /wp-admin/admin.php?page=nvoos-pro-workflow-builder
   ```
   - Page should load without errors
   - React interface should render
   - Assets (CSS/JS) should load

5. **Test Pro Orchestration Dashboard:**
   ```
   Navigate to: NV oOS → Orchestration Monitor
   URL should be: /wp-admin/admin.php?page=mcp-ai-orchestration-pro
   ```
   - Page should load without errors
   - Dashboard should display
   - Assets should load

6. **With WP_DEBUG Enabled:**
   
   Enable debug logging:
   ```php
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   ```
   
   Check the debug log for these messages:
   ```
   Workflow Builder: Hook=nv-oos_page_nvoos-pro-workflow-builder, Expected=nv-oos_page_nvoos-pro-workflow-builder, Match=YES
   Orchestration Dashboard: Hook=nv-oos_page_mcp-ai-orchestration-pro, GET page=mcp-ai-orchestration-pro, Is orchestration page=YES
   ```

### Automated Testing

Run the orchestration dashboard menu test:
```bash
composer run test -- tests/test-orchestration-dashboard-menu.php
```

Expected results:
- ✅ `test_base_orchestration_dashboard_registered` - PASS
- ✅ `test_pro_orchestration_dashboard_registered` - PASS
- ✅ `test_orchestration_pages_use_different_slugs` - PASS
- ✅ `test_base_orchestration_page_title` - PASS

### Regression Testing

Ensure these still work correctly:
- ✅ Base Orchestration Dashboard appears under "NV oOS"
- ✅ Pro Dashboard menu items remain under "NV oOS Pro"
- ✅ All menu items have correct URLs
- ✅ Assets load on all pages
- ✅ AJAX handlers work correctly
- ✅ No JavaScript console errors

## WordPress Hook System Reference

### How WordPress Generates Admin Page Hooks

WordPress uses this format for admin page hooks:
```
{sanitized_parent_menu_title}_page_{submenu_slug}
```

### Sanitization Rules

WordPress sanitizes menu titles by:
1. Converting to lowercase
2. Replacing spaces with hyphens
3. Removing special characters

Examples:
- "NV oOS" → `nv-oos`
- "NV oOS Pro" → `nv-oos-pro`  (Actually: `nvoos-pro-dashboard` for menu slug, but `nv-oos-pro` for hook)

### Hook Examples from This Plugin

| Parent Menu | Parent Slug | Submenu Slug | Generated Hook |
|-------------|-------------|--------------|----------------|
| NV oOS | `wp-mcp-ai-dashboard` | `mcp-ai-orchestration` | `nv-oos_page_mcp-ai-orchestration` |
| NV oOS | `wp-mcp-ai-dashboard` | `mcp-ai-orchestration-pro` | `nv-oos_page_mcp-ai-orchestration-pro` |
| NV oOS | `wp-mcp-ai-dashboard` | `nvoos-pro-workflow-builder` | `nv-oos_page_nvoos-pro-workflow-builder` |
| NV oOS Pro | `nvoos-pro-dashboard` | `security-audits` | `nv-oos-pro_page_security-audits` |

## Menu Organization Guidelines

### Main "NV oOS" Menu (wp-mcp-ai-dashboard)

**Purpose:** Core operational features and settings

**Should Include:**
- General Settings
- Assistants management
- Orchestration dashboards (base and Pro)
- Workflow builders
- Task management
- Integration settings
- Diagnostic tools

### "NV oOS Pro" Menu (nvoos-pro-dashboard)

**Purpose:** ISO 27001 compliance and enterprise features

**Should Include:**
- Compliance Overview
- Security Audits (ISO 27001 Control A.5.35)
- Security Training (ISO 27001 Control A.6.3)
- Supplier Security (ISO 27001 Controls A.5.19-A.5.22)
- Asset Inventory (ISO 27001 Control A.5.9)
- Risk Management
- Multi-Framework Compliance

## Files Modified

1. `addons/pro/includes/admin/class-wp-mcp-ai-pro-workflow-builder-page.php`
   - Changed parent menu from `nvoos-pro-dashboard` to `wp-mcp-ai-dashboard`
   - Updated hook expectation from `nvoos-pro-dashboard_page_*` to `nv-oos_page_*`
   - Enhanced documentation and debug logging

2. `addons/pro/includes/admin/class-wp-mcp-ai-orchestration-dashboard.php`
   - Changed parent menu from `nvoos-pro-dashboard` to `wp-mcp-ai-dashboard`
   - Updated hook expectation from `nvoos-pro-dashboard_page_*` to `nv-oos_page_*`
   - Enhanced documentation

3. `tests/test-orchestration-dashboard-menu.php`
   - Updated test expectations to look for Pro pages under main menu
   - Updated test logic to check correct parent menu

## Commit History

- `c5a379e` - Fix Pro Workflow Builder and Orchestration Dashboard menu placement under NV oOS
- `8fe7596` - Update test to reflect Pro Orchestration Dashboard now under main NV oOS menu

## Related Documentation

- `docs/fixes/pro-workflow-builder-empty-page-fix-2026-02-05-complete.md` - Previous fix for workflow builder initialization
- `docs/fixes/admin-menu-priority-fix-2026-02-04.md` - Menu priority fixes
- `docs/compliance/iso27001/Pro-Dashboard-Design.md` - Pro Dashboard design and structure

## Prevention Guidelines

### For Future Menu Additions

1. **Determine Correct Parent Menu:**
   - Operational features → `wp-mcp-ai-dashboard` (NV oOS)
   - Compliance/enterprise → `nvoos-pro-dashboard` (NV oOS Pro)

2. **Calculate Expected Hook:**
   - Get parent menu title
   - Apply WordPress sanitization
   - Format as `{sanitized_title}_page_{your_slug}`
   - Use in asset enqueue checks

3. **Add Debug Logging:**
   ```php
   if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
       error_log( sprintf( 
           'Page Name: Hook=%s, Expected=%s, Match=%s', 
           $hook, 
           $expected_hook, 
           ( $expected_hook === $hook ) ? 'YES' : 'NO' 
       ) );
   }
   ```

4. **Write Tests:**
   - Test menu registration
   - Test hook expectations
   - Test asset enqueuing

## Status

✅ **COMPLETE** - Fix implemented, tested, and documented

## Authors

- GitHub Copilot Workspace Agent
- nvdigitalsolutions

## Date

February 5, 2026
