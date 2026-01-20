# Prompt Shortcuts System Enhancement

## Overview

The Pre-built Prompt Shortcuts system has been comprehensively enhanced to industry standards with better UX, search/filter capabilities, hide functionality, and automatic recommendations for all tools.

## Key Enhancements

### 1. **Automatic Shortcut Recommendations**

**File:** `includes/helpers/class-wp-mcp-ai-shortcut-recommendations.php`

Previously, only 13 out of 162 tools (8%) had explicit shortcuts. Now **ALL tools** get intelligent, pattern-based shortcut recommendations:

- Content tools: "Show recent posts", "Search for content", "Create new item"
- Media tools: "Generate image", "Edit image", "Analyze video"
- E-commerce: "View orders", "List products", "Add product"
- And 20+ other categories

The system uses regex pattern matching on tool slugs to provide contextually appropriate shortcuts.

### 2. **Hide/Show Individual Shortcuts**

Users can now **temporarily hide shortcuts without deleting them**:

- Added `hidden` boolean flag to shortcut data structure
- Checkbox in UI: "Hide this shortcut (keep it but don't show in chat)"
- Visual indicators (dashicon, reduced opacity) for hidden items
- Hidden shortcuts are preserved in database but filtered from frontend
- Works for both custom shortcuts and pre-built tool shortcuts

**Benefits:**
- Test shortcuts without committing to them
- Seasonal content (hide/show based on time of year)
- User training (gradually reveal advanced shortcuts)
- A/B testing different prompts

### 3. **Grouping & Filtering System**

Tools are now organized into 10 logical categories:

1. **Content & Publishing** - Posts, pages, taxonomy
2. **Media & Images** - Image/video/audio generation and editing
3. **E-commerce** - WooCommerce and product management
4. **Analytics & Reporting** - Charts, reports, insights
5. **AI & Machine Learning** - Embeddings, vectors, moderation
6. **Site Management** - Health, cache, updates, security
7. **Development** - Code snippets, CLI, debugging
8. **Communication** - Email, SMS, social media
9. **SEO & Marketing** - SEO analysis, search, marketing
10. **Other Tools** - Uncategorized tools

**Filter Options:**
- **Search:** Real-time text search by tool name or slug
- **Category:** Dropdown to filter by tool group
- **Mode:** Show only customized or default shortcuts
- **Clear button:** Reset all filters

### 4. **Improved User Experience**

#### Pre-built Shortcuts Section
- Only appears when tools are selected (reduces clutter!)
- Tool descriptions shown inline
- Shortcut count badges
- Mode indicators (custom vs defaults)
- Better field labels: "Button Label", "Prompt Text", "Description"
- Placeholder text with examples
- Contextual help text for every field
- Info boxes with usage tips

#### Custom Shortcuts Section
- Renamed from "Prompt shortcuts" to "Custom Prompt Shortcuts"
- Clear explanation: for prompts not tied to specific tools
- Example use cases provided
- Consistent styling with pre-built shortcuts
- Hide functionality included

#### "Disable All" Checkbox
- Clearer labeling and description
- Explains relationship to individual hide feature
- Positioned with helpful context

### 5. **Enhanced Visual Design**

**Styling improvements:**
- Group headers with colored borders
- Details/summary elements with hover states
- Badge-style counters and mode indicators
- Hidden item styles (opacity, background color)
- Dashicons for actions (plus, trash, hidden)
- Responsive layout
- Better spacing and hierarchy

### 6. **Developer-Friendly**

**Pattern-based Recommendations:**
```php
WP_MCP_AI_Shortcut_Recommendations::get_recommendations_for_tool( $tool );
```

Returns array of shortcuts based on tool slug patterns. Integrates automatically into existing flow.

**Filters Available:**
- `wp_mcp_ai_tool_shortcut_tasks` - Modify tool shortcuts
- `wp_mcp_ai_assistant_custom_tool_shortcuts` - Modify custom shortcuts
- `wp_mcp_ai_assistant_tool_shortcuts` - Final shortcuts array

## User Workflows

### For Non-Technical Users

1. **Select tools** from the Available Tools section
2. **Review shortcuts** in the Pre-built Prompt Shortcuts section
   - Shortcuts appear automatically for selected tools
   - Read the description to understand each shortcut
3. **Hide unwanted shortcuts** using the checkbox (don't delete!)
4. **Add custom shortcuts** for common tasks not covered by tools

### For Power Users

1. **Use search/filter** to quickly find specific tools
2. **Customize tool shortcuts** - switch to "Customize" mode
3. **Create custom shortcuts** for complex workflows
4. **Hide shortcuts seasonally** - turn them on/off as needed
5. **Disable all tool shortcuts** if you want complete control

### For Developers

1. **Implement `WP_MCP_AI_Tool_Shortcuts_Interface`** in your custom tools
2. **Return array of shortcuts** from `get_shortcut_tasks()`
3. **Use filters** to modify shortcuts programmatically
4. **Access pattern-based recommendations** via helper class

## Technical Details

### Data Structure

#### Custom Shortcut
```php
array(
    'label'       => 'Button text',
    'payload'     => 'Actual prompt sent to AI',
    'tool'        => 'optional_tool_slug',
    'description' => 'Hover help text',
    'hidden'      => false, // NEW!
)
```

#### Pre-built Shortcut Override
```php
array(
    'tool_slug' => array(
        'mode'      => 'custom', // or 'inherit'
        'shortcuts' => array(
            array(
                'label'       => '...',
                'payload'     => '...',
                'description' => '...',
                'hidden'      => false, // NEW!
            ),
        ),
    ),
)
```

### Database Storage

- **Custom shortcuts:** `_wp_mcp_ai_tool_shortcuts` post meta
- **Pre-built overrides:** `_wp_mcp_ai_tool_prebuilt_shortcuts` post meta
- **Disable flag:** `_wp_mcp_ai_disable_tool_shortcuts` post meta

### Frontend Filtering

Hidden shortcuts are filtered in `WP_MCP_AI_Shortcode::get_assistant_tool_shortcuts()`:

```php
// Skip hidden shortcuts
if ( isset( $shortcut['hidden'] ) && $shortcut['hidden'] ) {
    continue;
}
```

## Migration Notes

### Backward Compatibility

✅ **Fully backward compatible** - no breaking changes

- Existing shortcuts without `hidden` flag work normally
- Old data structure supported
- No database migration required
- New features are additive only

### Updating from Previous Version

1. **No action required** - system auto-upgrades
2. Existing shortcuts remain unchanged
3. New recommendations appear for tools without shortcuts
4. Hidden flag defaults to `false` (not hidden)

## Best Practices

### When to Use Custom vs Pre-built Shortcuts

**Use Custom Shortcuts for:**
- General conversation starters ("Explain this", "Summarize")
- Domain-specific prompts ("Check inventory")
- Multi-tool workflows
- Prompts not tied to one tool

**Use Pre-built Tool Shortcuts for:**
- Tool-specific actions
- Leveraging automatic recommendations
- Standard tool operations
- Context-aware prompts

### Organizing Large Shortcut Sets

1. **Use grouping** - let the category system organize for you
2. **Hide, don't delete** - preserve shortcuts for later
3. **Use search** - find specific shortcuts quickly
4. **Be descriptive** - good labels help users understand
5. **Add descriptions** - tooltip help is valuable

### Performance Considerations

- Shortcuts are loaded per-assistant (not globally)
- Filtering happens client-side (fast, no server calls)
- Hidden shortcuts are in database but not sent to frontend
- Pattern matching is done once during recommendation generation

## Examples

### Example 1: Content Writer Assistant

**Selected Tools:**
- get_recent_posts
- create_post
- search_content

**Auto-generated Shortcuts:**
- "Show recent posts" → Uses get_recent_posts
- "Create new post" → Uses create_post  
- "Search for content" → Uses search_content

**Custom Shortcuts:**
- "Explain in simple terms" → General prompt
- "Give me 3 ideas" → Brainstorming prompt

### Example 2: E-commerce Assistant

**Selected Tools:**
- get_woo_recent_orders
- get_woo_products
- create_woo_product

**Auto-generated Shortcuts:**
- "View orders" → Last 7 days
- "List products" → Browse catalog
- "Add product" → Create new

**Hidden Shortcuts:**
- "Delete product" (hidden during holiday season)

### Example 3: Developer Assistant

**Selected Tools:**
- create_wpcode_snippet
- check_wp_cli
- probe_chat

**Auto-generated Shortcuts:**
- "Create code snippet" → Add custom functionality
- "Check CLI" → Verify WP-CLI
- "Test connection" → Probe service

**Custom Shortcuts:**
- "Debug this error" → Error analysis
- "Optimize performance" → Performance review

## Future Enhancements

Potential future improvements:

1. **Shortcut templates** - Library of pre-made shortcuts
2. **Import/Export** - Share shortcuts between assistants
3. **Analytics** - Track which shortcuts are used most
4. **Conditional shortcuts** - Show based on context
5. **Shortcut ordering** - Drag-and-drop reordering
6. **Shortcut icons** - Visual indicators beyond text
7. **Shortcut groups** - User-defined categories
8. **Keyboard shortcuts** - Quick access via hotkeys

## Support

### Troubleshooting

**Q: Shortcuts not appearing in chat?**
A: Check if "Disable all pre-built shortcuts" is enabled, or if individual shortcuts are hidden.

**Q: Too many shortcuts cluttering the UI?**
A: Use the hide functionality to temporarily disable shortcuts, or use search/filter to find specific ones.

**Q: Tool added but no shortcuts showing?**
A: The tool may not have explicit shortcuts defined. Check if pattern-based recommendations were generated.

**Q: Custom shortcut not working?**
A: Verify the shortcut isn't hidden, and that associated tool (if any) is selected.

### Getting Help

- **Documentation:** `docs/` directory
- **Issues:** GitHub Issues
- **Code:** Well-commented inline

## Credits

Enhanced by the NV Digital Solutions team with focus on:
- User experience for non-technical users
- Power features for advanced users
- Developer extensibility
- Industry best practices

---

**Version:** 1.1.0+  
**Last Updated:** January 2026  
**Status:** Production Ready ✅
