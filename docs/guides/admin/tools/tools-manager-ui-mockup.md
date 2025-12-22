# Tools Manager UI Mockup

## Screenshot 1: Tools Manager - Overview with WordPress Core Tools

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Open Operator System - Settings Dashboard                                │
├─────────────────────────────────────────────────────────────────────────────┤
│ Tabs: [Overview] [General] [AI Providers] [Authentication] [Tools & Features*] │
│       [Orchestration] [Token Manager] [Security] [Advanced]                  │
├─────────────────────────────────────────────────────────────────────────────┤
│ Sub-tabs: [Tools Manager*] [Features] [AI Media Library] [AI Comments]      │
│           [Site Creator]                                                     │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│ Tools Manager                                                                │
│ ─────────────                                                                │
│ View and manage all 65 registered AI tools. Tools can be filtered by        │
│ category and searched by name or description.                               │
│                                                                              │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ Search: [           search tools...          ] Category: [All Categories▼]│ │
│ │         [Filter] [Clear]                                                │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
│ ▼ WordPress Core                                                       [25]  │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ Tool Name      │ Slug              │ Description        │ Status │ Actions││
│ ├────────────────┼───────────────────┼───────────────────┼────────┼────────┤│
│ │Search Content  │search_content     │Search WordPress   │Available│  ✓    ││
│ │                │                   │content            │ (green)│        ││
│ ├────────────────┼───────────────────┼───────────────────┼────────┼────────┤│
│ │Get Recent Posts│get_recent_posts   │Retrieve recent    │Available│  ✓    ││
│ │                │                   │posts              │ (green)│        ││
│ ├────────────────┼───────────────────┼───────────────────┼────────┼────────┤│
│ │Save Post       │save_post          │Create/update      │Available│  ✓    ││
│ │                │                   │posts              │ (green)│        ││
│ ├────────────────┼───────────────────┼───────────────────┼────────┼────────┤│
│ │Get User Info   │get_user_info      │Get WordPress user │Available│  ✓    ││
│ │                │                   │information        │ (green)│        ││
│ ├────────────────┼───────────────────┼───────────────────┼────────┼────────┤│
│ │... (21 more tools in this category) ...                                 ││
│ └────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Screenshot 2: Tools Manager - WordPress Plugins Category with Unavailable Tools

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ▼ WordPress Plugins                                                     [12] │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ Tool Name        │ Slug                │ Description    │ Status │ Actions││
│ ├──────────────────┼─────────────────────┼────────────────┼────────┼────────┤│
│ │Get Woo Recent... │get_woo_recent_orders│Get WooCommerce │Unavailab│  ⚠    ││
│ │                  │                     │recent orders   │ (red)  │        ││
│ │                  │                     │Missing: WooCommerce                ││
│ ├──────────────────┼─────────────────────┼────────────────┼────────┼────────┤│
│ │Get Woo Products  │get_woo_products     │Get WooCommerce │Unavailab│  ⚠    ││
│ │                  │                     │products        │ (red)  │        ││
│ │                  │                     │Missing: WooCommerce                ││
│ ├──────────────────┼─────────────────────┼────────────────┼────────┼────────┤│
│ │Create Woo Product│create_woo_product   │Create WooComm- │Unavailab│  ⚠    ││
│ │                  │                     │erce product    │ (red)  │        ││
│ │                  │                     │Missing: WooCommerce                ││
│ ├──────────────────┼─────────────────────┼────────────────┼────────┼────────┤│
│ │Get Jetengine...  │get_jetengine_items  │Get JetEngine   │Unavailab│  ⚠    ││
│ │                  │                     │items           │ (red)  │        ││
│ │                  │                     │Missing: JetEngine                  ││
│ ├──────────────────┼─────────────────────┼────────────────┼────────┼────────┤│
│ │... (8 more tools in this category) ...                                  ││
│ └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Screenshot 3: Tools Manager - Search Results

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Tools Manager                                                                │
│ ─────────────                                                                │
│ View and manage all 65 registered AI tools. Tools can be filtered by        │
│ category and searched by name or description.                               │
│                                                                              │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ Search: [email                           ] Category: [All Categories▼] │ │
│ │         [Filter] [Clear]                                                │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
│ ▼ WordPress Core                                                        [1]  │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ Tool Name        │ Slug              │ Description       │ Status │ Actions││
│ ├──────────────────┼───────────────────┼──────────────────┼────────┼────────┤│
│ │Send Group Email  │send_group_email   │Send emails via   │Available│  ✓    ││
│ │                  │                   │wp_mail()         │ (green)│        ││
│ └────────────────────────────────────────────────────────────────────────┘ │
│                                                                              │
│ ▼ External Tools                                                        [2]  │
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ Tool Name        │ Slug              │ Description       │ Status │ Actions││
│ ├──────────────────┼───────────────────┼──────────────────┼────────┼────────┤│
│ │Send Mailjet Email│send_mailjet_email │Send email via    │Available│  ✓    ││
│ │                  │                   │Mailjet API       │ (green)│        ││
│ ├──────────────────┼───────────────────┼──────────────────┼────────┼────────┤│
│ │Search Gmail      │search_gmail       │Search Gmail      │Available│  ✓    ││
│ │                  │                   │messages          │ (green)│        ││
│ └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Screenshot 4: Tools Manager - Information Footer

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ┌────────────────────────────────────────────────────────────────────────┐ │
│ │ ℹ About Tool Categories                                                 │ │
│ │                                                                          │ │
│ │ • WordPress Core: Tools that work with base WordPress installation      │ │
│ │   without any external dependencies.                                    │ │
│ │                                                                          │ │
│ │ • WordPress Plugins: Tools that require specific third-party WordPress  │ │
│ │   plugins to be installed and active.                                   │ │
│ │                                                                          │ │
│ │ • External Tools: Tools that require external API credentials or        │ │
│ │   third-party service integrations.                                     │ │
│ │                                                                          │ │
│ │ For detailed information about each tool and its requirements, see the  │ │
│ │ Tool Reference Documentation →                                           │ │
│ └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
```

## UI Elements Legend

### Status Badges
- **Available** (Green badge): ✓ - Tool is ready to use
- **Unavailable** (Red badge): ⚠ - Tool has missing dependencies

### Actions Column
- ✓ (Green checkmark) - Tool is available
- ⚠ (Red warning icon) - Tool is unavailable

### Interactive Elements
- Search textbox - Type to search tools
- Category dropdown - Filter by category
- [Filter] button - Apply search/filter
- [Clear] button - Reset all filters
- Category headers - Collapsible sections
- Tool rows - Display tool information

## Color Scheme
- Available status: `#46b450` (green)
- Unavailable status: `#dc3232` (red)
- Category header background: `#f0f0f0` (light gray)
- Category badge: `#0073aa` (blue)
- Info box background: `#f0f6fc` (light blue)
- Info box border: `#c3e6ff` (light blue)

## Responsive Design
- Table uses WordPress core `.wp-list-table` class
- Follows WordPress admin styling conventions
- Mobile-friendly with horizontal scroll if needed
- Consistent with other WP oOS admin pages
