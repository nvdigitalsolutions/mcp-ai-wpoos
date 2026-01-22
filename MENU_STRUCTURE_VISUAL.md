# WordPress Admin Menu Structure - Visual Guide

## Before Changes

```
WordPress Admin Sidebar
├── Dashboard
├── ...
├── 📱 NV oOS (wp-mcp-ai-dashboard)
│   ├── ⚙️  General Settings (main page)
│   ├── 🔗 Remote Sites ❌ (TO BE MOVED)
│   ├── 🔄 Orchestration Dashboard ✅
│   └── 📋 Task Plans ✅
├── ...
└── 🛡️  NV oOS Pro (nvoos-pro-dashboard)
    ├── 📊 Pro Dashboard (main page)
    └── ... [other Pro features]
```

## After Changes ✅

```
WordPress Admin Sidebar
├── Dashboard
├── ...
├── 📱 NV oOS (wp-mcp-ai-dashboard)
│   ├── ⚙️  General Settings (main page) ✅
│   ├── 🔄 Orchestration Dashboard ✅
│   └── 📋 Task Plans (mcp_task_plan CPT) ✅
├── ...
└── 🛡️  NV oOS Pro (nvoos-pro-dashboard)
    ├── 📊 Pro Dashboard (main page)
    ├── 🔗 Remote Sites ✅ (MOVED HERE)
    └── ... [other Pro features]
```

## What Changed?

### ❌ Removed From
**NV oOS** main menu no longer contains:
- Remote Sites submenu

### ✅ Added To
**NV oOS Pro** menu now contains:
- Remote Sites submenu (moved from main menu)

### ✅ Verified (No Changes)
**NV oOS** main menu correctly contains:
- General Settings (main page)
- Orchestration Dashboard (for multi-agent monitoring)
- Task Plans CPT (for multi-agent orchestration)

## Access URLs

| Menu Item | URL | Location |
|-----------|-----|----------|
| General Settings | `/wp-admin/admin.php?page=wp-mcp-ai-dashboard` | NV oOS (main) |
| Orchestration Dashboard | `/wp-admin/admin.php?page=mcp-ai-orchestration` | NV oOS (submenu) |
| Task Plans | `/wp-admin/edit.php?post_type=mcp_task_plan` | NV oOS (CPT) |
| Pro Dashboard | `/wp-admin/admin.php?page=nvoos-pro-dashboard` | NV oOS Pro (main) |
| Remote Sites | `/wp-admin/admin.php?page=wp-mcp-ai-remote-sites` | NV oOS Pro (submenu) ← MOVED |

## Menu Hierarchy Details

### Main NV oOS Menu
- **Menu Slug**: `wp-mcp-ai-dashboard`
- **Icon**: `dashicons-format-chat` (💬)
- **Position**: 30
- **Purpose**: Core plugin settings and orchestration

**Submenus**:
1. **General Settings** (main page)
   - Slug: `wp-mcp-ai-dashboard`
   - Tabbed interface for all core settings
   
2. **Orchestration Dashboard**
   - Slug: `mcp-ai-orchestration`
   - Real-time monitoring for autonomous AI sessions
   - Capacity analysis (Little's Law)
   - Session management controls
   
3. **Task Plans** (Custom Post Type)
   - Post Type: `mcp_task_plan`
   - Multi-agent orchestration templates
   - Ralph Wiggum autonomous patterns

### NV oOS Pro Menu
- **Menu Slug**: `nvoos-pro-dashboard`
- **Icon**: `dashicons-shield-alt` (🛡️)
- **Position**: 25
- **Purpose**: Enterprise features and compliance

**Submenus**:
1. **Pro Dashboard** (main page)
   - Slug: `nvoos-pro-dashboard`
   - ISO 27001 compliance monitoring
   - Tabbed interface for compliance features
   
2. **Remote Sites** ← MOVED HERE
   - Slug: `wp-mcp-ai-remote-sites`
   - Remote WordPress/WooCommerce connections
   - OAuth integrations (Gmail, Google Drive)
   - REST API testing

## Code Implementation

### Menu Registration

**General Settings (Main NV oOS Menu)**
```php
// File: includes/admin/class-wp-mcp-ai-settings-dashboard.php
add_menu_page(
    __( 'NV oOS Settings', 'mcp-ai-wpoos' ),
    __( 'NV oOS', 'mcp-ai-wpoos' ),
    'manage_options',
    'wp-mcp-ai-dashboard',
    array( $this, 'render_dashboard' ),
    'dashicons-format-chat',
    30
);
```

**Orchestration Dashboard (Submenu)**
```php
// File: includes/admin/class-wp-mcp-ai-orchestration-dashboard.php
add_submenu_page(
    'wp-mcp-ai-dashboard',  // Parent
    __( 'Orchestration Dashboard', 'mcp-ai-wpoos-pro' ),
    __( 'Orchestration', 'mcp-ai-wpoos-pro' ),
    'manage_options',
    'mcp-ai-orchestration',
    array( $this, 'render_dashboard' )
);
```

**Task Plans (CPT)**
```php
// File: includes/orchestration-init.php
register_post_type( 'mcp_task_plan', array(
    'show_in_menu' => 'wp-mcp-ai-dashboard',  // Shows under main menu
    // ... other args
) );
```

**Pro Dashboard (Pro Menu)**
```php
// File: includes/admin/class-wp-mcp-ai-pro-dashboard.php
add_menu_page(
    __( 'NV oOS Pro Dashboard', 'mcp-ai-wpoos' ),
    __( 'NV oOS Pro', 'mcp-ai-wpoos' ),
    'manage_options',
    'nvoos-pro-dashboard',
    array( $this, 'render_dashboard_with_tabs' ),
    'dashicons-shield-alt',
    25
);
```

**Remote Sites (CHANGED - Now under Pro)**
```php
// File: addons/pro/includes/admin/class-wp-mcp-ai-pro-remote-sites-admin.php
add_submenu_page(
    'nvoos-pro-dashboard',  // CHANGED from 'wp-mcp-ai-dashboard'
    __( 'Remote Site Connections', 'wp-mcp-ai-pro' ),
    __( 'Remote Sites', 'wp-mcp-ai-pro' ),
    'manage_options',
    'wp-mcp-ai-remote-sites',
    array( $this, 'render_admin_page' )
);
```

## Hook Suffixes

WordPress generates hook suffixes for enqueuing assets:

| Menu Item | Hook Suffix | Pattern |
|-----------|-------------|---------|
| General Settings | `toplevel_page_wp-mcp-ai-dashboard` | Top-level menu |
| Orchestration | `nv-oos_page_mcp-ai-orchestration` | `{parent}_page_{slug}` |
| Remote Sites (OLD) | `nv-oos_page_wp-mcp-ai-remote-sites` | Old parent: "NV oOS" |
| Remote Sites (NEW) | `nv-oos-pro_page_wp-mcp-ai-remote-sites` | New parent: "NV oOS Pro" |

Note: WordPress sanitizes menu titles for hook generation:
- "NV oOS" → `nv-oos`
- "NV oOS Pro" → `nv-oos-pro`

## User Experience

### For Regular Users
- Access general settings and orchestration from main **NV oOS** menu
- Create and manage autonomous task plans
- Monitor real-time orchestration sessions

### For Pro Users
- Access compliance features from **NV oOS Pro** menu
- Manage remote site connections
- Configure ISO 27001 compliance settings

### Navigation Flow
```
User logs into WordPress Admin
    ↓
Sees two main menu items:
    ├── 📱 NV oOS (position 30)
    │   ↳ For general settings and orchestration
    └── 🛡️  NV oOS Pro (position 25)
        ↳ For enterprise features and remote sites
```

## Testing Checklist

When testing this change, verify:

- [ ] **Menu Visibility**
  - Remote Sites appears under NV oOS Pro (not NV oOS)
  - Orchestration appears under NV oOS
  - Task Plans appears under NV oOS
  - General Settings is first item under NV oOS

- [ ] **Page Loading**
  - All menu items load without errors
  - CSS/JS assets load correctly
  - No 404 or permission errors

- [ ] **Functionality**
  - Remote Sites page functions correctly
  - Can create/edit remote connections
  - OAuth flows work properly
  - Orchestration dashboard displays data
  - Task Plans can be created/edited

- [ ] **User Permissions**
  - Only users with `manage_options` can see menus
  - Non-admin users cannot access these pages
