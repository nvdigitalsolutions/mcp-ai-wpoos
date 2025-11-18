# Tool Manager UI Visual Guide

## Tools Manager Interface

### Layout

```
┌─────────────────────────────────────────────────────────────────────┐
│  WP oOS Settings > Tools > Tools Manager                            │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  Tools Manager                                                       │
│  View and manage all 65 registered AI tools. Tools can be          │
│  filtered by category and searched by name or description.         │
│                                                                      │
├─────────────────────────────────────────────────────────────────────┤
│  ┌────────────────────────────────────────────────────────────────┐ │
│  │ Search: [Search tools...    ] Category: [All Categories ▼]    │ │
│  │         [Filter] [Clear]                                        │ │
│  └────────────────────────────────────────────────────────────────┘ │
│                                                                      │
│  ┌─ WordPress Core (35) ─────────────────────────────────────────┐ │
│  │                                                                 │ │
│  │ ┌───────────────────────────────────────────────────────────┐ │ │
│  │ │ Tool Name  │ Slug              │ Description │ Status │ Actions││
│  │ ├───────────────────────────────────────────────────────────┤ │ │
│  │ │ Search     │ search_content    │ Search for  │ 🟢     │ ●──○ ││
│  │ │ Content    │                   │ content...  │ Enabled│      ││
│  │ ├───────────────────────────────────────────────────────────┤ │ │
│  │ │ Get Recent │ get_recent_posts  │ Retrieve... │ ⚪     │ ○──● ││
│  │ │ Posts      │                   │             │Disabled│      ││
│  │ ├───────────────────────────────────────────────────────────┤ │ │
│  │ │ Save Post  │ save_post         │ Create or   │ 🟢     │ ●──○ ││
│  │ │            │                   │ update...   │ Enabled│      ││
│  │ └───────────────────────────────────────────────────────────┘ │ │
│  └─────────────────────────────────────────────────────────────────┘ │
│                                                                      │
│  ┌─ WordPress Plugins (12) ──────────────────────────────────────┐ │
│  │                                                                 │ │
│  │ ┌───────────────────────────────────────────────────────────┐ │ │
│  │ │ Tool Name  │ Slug              │ Description │ Status │ Actions││
│  │ ├───────────────────────────────────────────────────────────┤ │ │
│  │ │ Get        │ get_elementor_    │ Retrieve... │ 🔴     │  ⚠   ││
│  │ │ Elementor  │ templates         │             │Unavail │      ││
│  │ │ Templates  │                   │ Missing:    │        │      ││
│  │ │            │                   │ Elementor   │        │      ││
│  │ └───────────────────────────────────────────────────────────┘ │ │
│  └─────────────────────────────────────────────────────────────────┘ │
│                                                                      │
│  ┌─ External Tools (18) ─────────────────────────────────────────┐ │
│  │  ... (more tools)                                              │ │
│  └─────────────────────────────────────────────────────────────────┘ │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

## UI Components

### Toggle Switch States

**Enabled (Green)**
```
 ┌──────────────┐
 │  ●──────○    │  Tool is enabled and active
 │  Green       │
 └──────────────┘
```

**Disabled (Gray)**
```
 ┌──────────────┐
 │  ○──────●    │  Tool is disabled
 │  Gray        │
 └──────────────┘
```

**During Toggle (Processing)**
```
 ┌──────────────┐
 │  ●──────○    │  Toggle is disabled
 │  Opacity 60% │  (AJAX request in progress)
 └──────────────┘
```

### Status Badges

**Enabled**
```
 ┌─────────┐
 │ Enabled │  Green background (#46b450)
 │ ✓       │  White text
 └─────────┘
```

**Disabled**
```
 ┌──────────┐
 │ Disabled │  Gray background (#999)
 │ ○        │  White text
 └──────────┘
```

**Unavailable**
```
 ┌─────────────┐
 │ Unavailable │  Red background (#dc3232)
 │ !           │  White text
 └─────────────┘
```

## Interaction Flow

### Enabling a Tool

```
1. User clicks toggle switch
   ┌──────────────┐
   │  ○──────●    │ → Click
   └──────────────┘

2. Toggle becomes disabled (processing)
   ┌──────────────┐
   │  ●──────○    │ (Opacity 60%)
   └──────────────┘

3. AJAX request sent
   POST /wp-admin/admin-ajax.php
   action: wp_mcp_ai_toggle_tool
   tool_slug: search_content
   tool_action: enable
   nonce: abc123...

4. Response received
   {
     "success": true,
     "data": {
       "message": "Tool enabled successfully.",
       "enabled": true
     }
   }

5. UI updates
   Status badge: Gray → Green
   ┌─────────┐
   │ Enabled │
   └─────────┘
   
   Toggle: ○──● → ●──○
   
   Notification shown:
   ┌─────────────────────────────────────┐
   │ ✓ Tool enabled successfully.        │
   │                                [×]  │
   └─────────────────────────────────────┘
```

### Search/Filter Interaction

```
User types: "search"

Before search:
- All 65 tools shown in categories

After search:
- Only matching tools shown:
  ✓ "Search Content" (matches name)
  ✓ "search_content" (matches slug)
  ✓ "Search for content..." (matches description)
  
Category filters automatically adjust:
- WordPress Core (3) ← was (35)
- External Tools (1) ← was (18)
- WordPress Plugins (0) ← hidden
```

## Responsive Behavior

### Table Hover Effect
```
Default row:
┌───────────────────────────────────┐
│ Search Content │ ... │ Enabled │  │
└───────────────────────────────────┘

On hover:
┌───────────────────────────────────┐
│ Search Content │ ... │ Enabled │  │ ← Background: #f9f9f9
└───────────────────────────────────┘
```

### Status Badge Animation
```
When status changes:

From:  ┌──────────┐
       │ Disabled │
       └──────────┘

To:    ┌─────────┐  (0.3s transition)
       │ Enabled │
       └─────────┘
       
Color smoothly transitions from gray to green
```

## Accessibility Features

- ✅ Toggle switches have proper labels (hidden for screen readers)
- ✅ Status badges use color AND text
- ✅ Keyboard navigation supported
- ✅ ARIA attributes on interactive elements
- ✅ Focus states visible
- ✅ Clear success/error messages

## Browser Compatibility

Toggle switches and animations work in:
- ✅ Chrome/Edge (latest)
- ✅ Firefox (latest)
- ✅ Safari (latest)
- ✅ Mobile browsers

CSS uses standard properties (no vendor prefixes needed for modern browsers)
