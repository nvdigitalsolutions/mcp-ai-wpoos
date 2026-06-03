# UI Mockup: Tool Selection Presets

## Visual Description

When editing an AI Assistant in WordPress admin, the **Available Tools** meta box now includes a new section immediately after the "Disable pre-built prompt shortcuts from selected tools" checkbox.

### Layout

```
┌─────────────────────────────────────────────────────────────────┐
│ Available Tools                                                 │
│                                                                 │
│ Select the tools this assistant is permitted to invoke.        │
│ Expand a group to review related capabilities...               │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────┐   │
│ │ ☐ Disable pre-built prompt shortcuts from selected     │   │
│ │   tools                                                 │   │
│ │   When enabled, only the custom shortcuts you define   │   │
│ │   below will appear in the chat interface.             │   │
│ └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│ ┌─────────────────────────────────────────────────────────┐   │
│ │ Quick Tool Selection Presets                           │   │
│ │                                                         │   │
│ │ Click a preset to quickly select tools for common      │   │
│ │ tasks. This will replace your current tool selection.  │   │
│ │                                                         │   │
│ │ ┌─────────────┐ ┌──────────────┐ ┌──────────────┐    │   │
│ │ │   Content   │ │  E-commerce  │ │     Site     │    │   │
│ │ │   Writing   │ │   Support    │ │  Management  │    │   │
│ │ └─────────────┘ └──────────────┘ └──────────────┘    │   │
│ │                                                         │   │
│ │ ┌─────────────┐ ┌──────────────┐ ┌──────────────┐    │   │
│ │ │   SEO &     │ │ Development  │ │   Data &     │    │   │
│ │ │  Marketing  │ │              │ │  Analytics   │    │   │
│ │ └─────────────┘ └──────────────┘ └──────────────┘    │   │
│ └─────────────────────────────────────────────────────────┘   │
│                                                                 │
│ ▼ WordPress Core (12 tools)                                   │
│   ┌───────────────────────────────────────────────────┐       │
│   │ ☑ Search Content                                 │       │
│   │   Search for posts, pages, and custom post types │       │
│   └───────────────────────────────────────────────────┘       │
│   ... (more tools)                                             │
└─────────────────────────────────────────────────────────────────┘
```

## Visual Features

### Preset Section Box
- **Background:** White (#fff)
- **Border:** 1px solid light gray (#dcdcde)
- **Padding:** 1rem
- **Margin:** 1rem top spacing from the checkbox above
- **Border radius:** 4px

### Section Header
- **Font size:** 14px
- **Font weight:** Normal (not bold)
- **Margin:** 0 top, 0.5rem bottom
- **Color:** Default WordPress text color

### Description Text
- **Style:** WordPress `.description` class
- **Margin:** 0 top, 1rem bottom
- **Font size:** 13px
- **Color:** Medium gray (#50575e)

### Preset Buttons Container
- **Display:** Flexbox
- **Flex wrap:** Enabled (buttons wrap to new lines on small screens)
- **Gap:** 0.5rem between buttons
- **Margin bottom:** 1rem

### Individual Preset Buttons
- **Style:** WordPress `.button` class (default button styling)
- **Padding:** Standard WordPress button padding
- **Border radius:** Default WordPress button radius
- **Cursor:** Pointer
- **Hover state:** Standard WordPress button hover (darker background)

### Button Interaction States

1. **Normal:** Default WordPress button appearance
2. **Hover:** Standard WordPress button hover state
3. **Active/Clicked:** 
   - Background: WordPress blue (#2271b1)
   - Color: White (#fff)
   - Border color: WordPress blue (#2271b1)
   - Duration: 500ms, then returns to normal

4. **Tooltip:** Browser default tooltip showing preset description on hover

### Responsive Behavior

- **Desktop (>782px):** Buttons displayed in rows with wrapping
- **Tablet/Mobile (≤782px):** Buttons stack vertically or wrap to 2-3 columns depending on space

## User Interaction Flow

1. User hovers over a preset button → Sees tooltip with preset description
2. User clicks preset button → Button briefly turns blue
3. All tool checkboxes below are updated (previous selection cleared, new tools checked)
4. Page smoothly scrolls to the tools list
5. User can verify the selection and make additional adjustments

## Integration with Existing UI

The preset section integrates seamlessly with:
- Existing WordPress admin styling
- The tools meta box layout
- The disabled shortcuts checkbox above it
- The expanded/collapsed tool groups below it

No custom CSS classes are required in the global stylesheet - all styling is inline or uses existing WordPress classes, ensuring consistency and maintainability.
