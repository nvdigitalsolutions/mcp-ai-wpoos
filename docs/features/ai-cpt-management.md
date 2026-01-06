# AI CPT Management Interface

**Pro Feature** - Available only in the Pro addon

## Overview

The AI CPT Management Interface adds an intelligent AI assistant directly to WordPress content edit screens (posts, pages, products, and taxonomy terms). This feature provides context-aware AI assistance while you're creating or editing content in the WordPress admin interface.

## Features

- **Contextual AI Assistance**: AI assistant has access to the current content being edited
- **Multiple Content Types**: Works with posts, pages, WooCommerce products, and taxonomy terms
- **Real-time Chat Interface**: Interactive chat interface directly in the edit screen
- **Tool Access**: Full access to all available AI tools while editing content
- **Suggestions & Examples**: Built-in welcome message with usage examples

## Supported Content Types

### Post Types
- **Posts** - Standard WordPress blog posts
- **Pages** - WordPress pages
- **Products** - WooCommerce products (when WooCommerce is active)
- **Quizzes** - Quiz CPT (when Quiz System is enabled)
- **Places** - Place CPT (when Places Management is enabled)
- **Projects** - Project CPT (when Project Management is enabled)
- **Tasks** - Task CPT (when Project Management is enabled)
- **Events** - Event CPT (when Project Management is enabled)
- **Custom Post Types** - Can be extended via filters

### Taxonomies
- **Categories** - Post categories
- **Tags** - Post tags
- **Product Categories** - WooCommerce product categories (when WooCommerce is active)
- **Product Tags** - WooCommerce product tags (when WooCommerce is active)
- **Custom Taxonomies** - Can be extended via filters

## Enabling the Feature

1. Navigate to **NV oOS → Settings → Tools & Features**
2. Click on the **Features** tab
3. Enable **AI CPT Management** checkbox
4. Save settings
5. The AI Assistant metabox will now appear on supported edit screens

## Using the AI Assistant

### On Post/Page Edit Screens

When editing a post or page, you'll see an **AI Assistant** metabox in the sidebar:

1. **Chat Interface**: Type your request in the text area
2. **Send Message**: Click the "Send" button or press Enter
3. **View Response**: AI responses appear in the chat history
4. **Context Awareness**: AI has access to:
   - Current post title
   - Current post content
   - Post type
   - Post status (draft, published, etc.)

### On Term Edit Screens

When editing a category, tag, or other taxonomy term:

1. **AI Assistant Section**: Appears below the term edit form
2. **Chat Interface**: Same functionality as post edit screens
3. **Context Awareness**: AI has access to:
   - Term name
   - Term description
   - Taxonomy type

## Example Use Cases

### Content Creation
```
"Write an engaging introduction for this blog post about AI"
"Generate 5 headline variations for this post"
"Create a summary of this content for the excerpt field"
```

### SEO Optimization
```
"Suggest SEO-friendly keywords for this post"
"Write a meta description based on this content"
"Analyze this content for SEO improvements"
```

### Image Generation
```
"Create a featured image for this blog post"
"Generate an illustration for this topic"
```

### Product Descriptions
```
"Write a compelling product description"
"Create product bullet points"
"Generate product variations"
```

### Taxonomy Management
```
"Write a description for this category"
"Suggest related categories"
"Generate SEO metadata for this term"
```

### Quiz Management
```
"Generate quiz questions based on this topic"
"Create answer options for this question"
"Suggest difficulty levels for this quiz"
```

### Place Management
```
"Write a description for this location"
"Generate location details and amenities"
"Create SEO-friendly place descriptions"
```

### Project Management
```
"Create a project plan for this initiative"
"Generate task list for this project"
"Write project status updates"
"Schedule events for project milestones"
```

## Technical Details

### Architecture

The AI CPT Management feature consists of:

1. **Integration Class**: `WP_MCP_AI_Pro_CPT_AI_Integration`
   - Registers metaboxes
   - Handles AJAX requests
   - Manages context building

2. **JavaScript**: `cpt-assistant.js`
   - Handles chat interface interactions
   - Formats messages
   - Manages UI state

3. **CSS**: `cpt-assistant.css`
   - Styles the chat interface
   - Responsive design
   - Admin theme integration

### AJAX Endpoint

**Action**: `wp_ajax_wp_mcp_ai_cpt_chat`

**Parameters**:
- `message` (string, required) - User message
- `post_id` (int, optional) - Current post ID
- `post_type` (string, optional) - Current post type
- `term_id` (int, optional) - Current term ID
- `taxonomy` (string, optional) - Current taxonomy
- `nonce` (string, required) - Security nonce

**Response**:
```json
{
  "success": true,
  "data": {
    "response": "AI assistant response text"
  }
}
```

### Context Building

The AI receives a system message with context about what the user is editing:

**For Posts/Pages**:
```
You are an AI assistant helping a WordPress user manage their content.
The user is currently editing a post titled "Example Post" (status: draft).
You have access to the current post content.
You can help them write, edit, optimize, or enhance their content.
```

**For Terms**:
```
You are an AI assistant helping a WordPress user manage their content.
The user is currently editing a category term named "News".
You can help them write descriptions, generate SEO metadata, and manage taxonomy terms.
```

## Customization

### Adding Custom Post Types

Use the `wp_mcp_ai_cpt_supported_post_types` filter:

```php
add_filter( 'wp_mcp_ai_cpt_supported_post_types', function( $post_types ) {
    $post_types[] = 'my_custom_post_type';
    return $post_types;
} );
```

### Adding Custom Taxonomies

Use the `wp_mcp_ai_cpt_supported_taxonomies` filter:

```php
add_filter( 'wp_mcp_ai_cpt_supported_taxonomies', function( $taxonomies ) {
    $taxonomies[] = 'my_custom_taxonomy';
    return $taxonomies;
} );
```

### Custom Styling

Override CSS variables or add custom styles:

```css
/* Custom metabox styling */
.wp-mcp-ai-cpt-assistant {
    /* Your custom styles */
}
```

## Security

### Capability Requirements

- Users must have `edit_posts` capability to use the AI assistant
- AJAX requests are protected by WordPress nonces
- All inputs are sanitized and validated

### Content Access

- AI only has access to content the current user can edit
- Post/term permissions are respected
- Context data is never exposed to other users

## Performance

### Asset Loading

- CSS and JavaScript only load on supported edit screens
- No impact on frontend performance
- Minimal overhead on admin pages

### API Efficiency

- Single REST API call per message
- Non-streaming responses for faster UI updates
- Caching of assistant data

## Troubleshooting

### AI Assistant Not Appearing

1. Verify the feature is enabled in settings
2. Check you're editing a supported post type/taxonomy
3. Ensure you have `edit_posts` capability
4. Check browser console for JavaScript errors

### "No AI assistant found" Error

1. Create at least one AI assistant in **NV oOS → Assistants**
2. Ensure the assistant is published
3. Verify assistant has proper configuration

### Permission Errors

1. Verify your user role has `edit_posts` capability
2. Check post/term-specific permissions
3. Ensure nonce is valid (refresh page if stale)

## Best Practices

1. **Clear Instructions**: Be specific in your requests to the AI
2. **Review AI Output**: Always review and edit AI-generated content
3. **Use Context**: Take advantage of the AI's access to current content
4. **Iterate**: Have a conversation with the AI to refine output
5. **Save Often**: Save your work before making major AI-assisted changes

## Related Features

- [AI Assistants](assistants.md) - Create and manage AI assistants
- [AI Tools](../tools/README.md) - Available AI tools
- [Content Generation Tools](../tools/content-generation.md) - Content creation tools
- [Image Generation Tools](../tools/image-generation.md) - Image creation tools

## Version Information

- **Introduced**: Version 1.0.0 (Pro)
- **Requires**: Pro addon
- **Dependencies**: 
  - At least one configured AI assistant
  - WordPress 6.0+
  - PHP 7.4+
