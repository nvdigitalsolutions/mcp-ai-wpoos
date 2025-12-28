# Sidebar Template - ChatGPT-Style Layout

## Visual Representation

```
┌─────────────────────────────────────────────────────────────────────┐
│                     WP oOS Chat - Sidebar Template                  │
│                                                                      │
│  ┌───────────────────┬────────────────────────────────────────────┐│
│  │ CONVERSATIONS     │  Assistant Name                            ││
│  │ ─────────────     │  ──────────────────────────────────────── ││
│  │                   │                                             ││
│  │ ┌───────────────┐ │  ┌──────────────────────────────────────┐ ││
│  │ │ + New Chat    │ │  │                                        │ ││
│  │ └───────────────┘ │  │  ┌──────────────────┐                 │ ││
│  │                   │  │  │  User Message    │                 │ ││
│  │ AVAILABLE TOOLS   │  │  └──────────────────┘                 │ ││
│  │ ───────────────   │  │                                        │ ││
│  │ ✦ search_posts    │  │          ┌──────────────────┐          │ ││
│  │ ✦ create_post     │  │          │ Assistant Reply  │          │ ││
│  │ ✦ update_post     │  │          └──────────────────┘          │ ││
│  │ ✦ list_users      │  │                                        │ ││
│  │                   │  │  ┌──────────────────┐                 │ ││
│  │ ◉ Current Chat    │  │  │  User Message    │                 │ ││
│  │   Today, 10:30am  │  │  └──────────────────┘                 │ ││
│  │                   │  │                                        │ ││
│  │ ○ Previous Chat   │  │          ┌──────────────────┐          │ ││
│  │   Yesterday       │  │          │ Assistant Reply  │          │ ││
│  │                   │  │          └──────────────────┘          │ ││
│  │ ○ Another Chat    │  │                                        │ ││
│  │   2 days ago      │  │                                        │ ││
│  │                   │  └──────────────────────────────────────┘ ││
│  │ [Load More...]    │                                             ││
│  │                   │  ┌──────────────────────────────────────┐ ││
│  │                   │  │ Type your message here...            │ ││
│  │                   │  └──────────────────────────────────────┘ ││
│  │                   │  [📎] [🎤] [Send]                         ││
│  │                   │                                             ││
│  └───────────────────┴────────────────────────────────────────────┘│
│       260px                      Flexible Width                     │
│     Sidebar                       Chat Area                         │
└─────────────────────────────────────────────────────────────────────┘
```

## Key Features

### Layout Structure
- **Two-column grid**: `grid-template-columns: 260px 1fr`
- **Total max width**: 1200px
- **Minimum height**: 600px for optimal experience
- **Sidebar**: Fixed 260px width, scrollable conversation list
- **Tools list**: Displays all available tools for the assistant
- **Chat area**: Flexible width, full-height messages + input at bottom

### Sidebar Panel (Left)
- **Background**: Light gray (#f7f7f8) or dark (#1f1f1f)
- **"Conversations" header**: Always visible at top
- **New Chat button**: Full-width, prominent at top of list
- **Available Tools section**: 
  - Header: "AVAILABLE TOOLS"
  - Lists all tools enabled for the assistant
  - Each tool shown with icon and formatted name
  - Tool names auto-formatted (e.g., "search_posts" → "Search Posts")
  - Styled with subtle background and border
  - Hover effects for better UX
- **Conversation list**: 
  - Scrollable history of previous chats
  - Each item shows preview and timestamp
  - Current conversation highlighted
  - Hover effects on items
  - Delete buttons on hover
- **Load More button**: At bottom when more conversations available

### Chat Area (Right)
- **Assistant header**: Name and description at top
- **Messages container**: Scrollable, takes available height
- **Input area**: Fixed at bottom
  - Message textarea
  - Attachment, voice, and send buttons
  - Control buttons (save, export)

### Responsive Behavior

**Desktop (>768px)**:
```
┌──────────┬─────────────┐
│ Sidebar  │  Chat Area  │
│ 260px    │  Flexible   │
│ (Always  │  (Full      │
│  visible)│   height)   │
└──────────┴─────────────┘
```

**Mobile (<768px)**:
```
┌───────────────────────┐
│   Sidebar (200px h)   │
│   Scrollable          │
├───────────────────────┤
│   Chat Area           │
│   (Remaining height)  │
└───────────────────────┘
Stacks vertically
```

## CSS Implementation

### Main Container
```css
.wp-mcp-ai-chat--template-sidebar {
    display: grid;
    grid-template-columns: 260px 1fr;
    max-width: 1200px;
    min-height: 600px;
}
```

### Sidebar
```css
.wp-mcp-ai-chat--template-sidebar .wp-mcp-ai-chat__history {
    display: flex !important; /* Always visible */
    border-right: 1px solid #e5e5e5;
    background: #f7f7f8;
    overflow-y: auto;
}
```

### Chat Area
```css
.wp-mcp-ai-chat--template-sidebar .wp-mcp-ai-chat__messages {
    flex: 1;
    max-height: none; /* Full available height */
    overflow-y: auto;
}
```

## Comparison with ChatGPT

| Feature | ChatGPT | WP oOS Sidebar Template |
|---------|---------|-------------------------|
| Sidebar width | ~260px | 260px ✓ |
| Sidebar position | Left | Left ✓ |
| Always visible | Yes | Yes ✓ |
| Conversation list | Yes | Yes ✓ |
| New chat button | Top of sidebar | Top of sidebar ✓ |
| Full-height design | Yes | Yes ✓ |
| Responsive | Collapses | Stacks ✓ |
| Dark mode | Yes | Yes ✓ |

## Usage

### Shortcode
```php
[mcp_ai_chat assistant="123" template="sidebar"]
```

### Block Editor
1. Add "AI Chat" block
2. Select template: "Sidebar"
3. Preview shows two-column layout

### Best For
- Full-page chat applications
- AI assistant dashboards  
- Enterprise chat interfaces
- Multi-conversation management
- Customer support portals
- Learning management systems

## Technical Notes

- **Pure CSS**: No JavaScript changes needed
- **Grid layout**: Modern CSS Grid for reliable two-column design
- **Flexbox inside**: Sidebar and chat area use flexbox for content flow
- **Hidden elements**: History toggle button hidden (sidebar always visible)
- **CSS custom properties**: Respects all existing color variables
- **Performance**: No additional HTTP requests

## Browser Support

- Chrome/Edge: ✓ (Grid + Flexbox)
- Firefox: ✓ (Grid + Flexbox)
- Safari: ✓ (Grid + Flexbox)
- Mobile: ✓ (Responsive stacking)

## Future Enhancements

Potential JavaScript additions (not included in this CSS-only implementation):
- Collapse/expand sidebar on mobile
- Drag to resize sidebar width
- Search conversations in sidebar
- Pin important conversations
- Folders/categories for conversations
