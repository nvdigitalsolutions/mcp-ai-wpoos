# Content Assistant Metabox - Quick Reference

## Overview

The Content Assistant metabox provides context-aware AI assistance directly within WordPress post and page edit screens. It offers quick actions for common content tasks and a full chat interface for custom requests.

## Location

**Metabox Position**: Right sidebar (side metabox) on post/page edit screens  
**Priority**: High (appears near the top)

## Features

### 1. Assistant Selection
- Dropdown list of all published AI assistants
- Must select an assistant before using any features
- Selection persists during the editing session

### 2. Quick Actions (6 Built-in)

| Action | Description | Use Case |
|--------|-------------|----------|
| **Improve Content** | Enhances existing content quality | Refining drafts |
| **Generate Outline** | Creates structured content outline | Planning new posts |
| **SEO Optimize** | Provides SEO recommendations | Improving search visibility |
| **Rewrite** | Rewrites content in different style | Tone adjustments |
| **Expand** | Adds more detail and depth | Making content comprehensive |
| **Summarize** | Creates concise summary | Adding excerpts |

### 3. Full Chat Interface
- Click "Open AI Assistant" for modal chat window
- Full-featured chat with file uploads, transcription, etc.
- Context includes current post title, content, status, author
- Streaming responses for real-time feedback

## Technical Implementation

### File Locations

```
includes/
  metaboxes/
    class-wp-mcp-ai-content-assistant-metabox.php  # Main metabox class
  content-assistant-init.php                       # Feature initialization
assets/
  css/
    admin-content-assistant.css                    # Metabox and modal styles
  js/
    admin-content-assistant.js                     # Chat interface and actions
```

### Class Structure

```php
class WP_MCP_AI_Content_Assistant_Metabox {
    const METABOX_ID = 'wp_mcp_ai_content_assistant';
    
    public function __construct()
    public function register_metabox()
    protected function get_enabled_post_types()
    public function enqueue_assets( $hook )
    protected function ensure_chat_localization()
    protected function get_context_data( $post )
    protected function get_assistant_title( $post_type )
    protected function get_placeholder_text( $post_type )
    public function render( $post )
    protected function render_quick_actions( $post )
    protected function get_quick_actions()
    protected function render_ai_modal( $post )
    protected function get_available_assistants()
}
```

### Context Data Sent to AI

```php
array(
    'post_id'      => $post->ID,
    'post_type'    => $post->post_type,
    'post_title'   => $post->post_title,
    'post_content' => $post->post_content,
    'post_status'  => $post->post_status,
    'post_author'  => $post->post_author,
    'post_date'    => $post->post_date,
    'post_excerpt' => $post->post_excerpt,
)
```

## Configuration

### Settings

#### Enable/Disable Feature
```php
// In wp_mcp_ai_settings option
'enable_content_assistant_metabox' => true, // default: true
```

#### Configure Post Types
```php
// In wp_mcp_ai_settings option
'content_assistant_post_types' => array( 'post', 'page' ), // default
```

### Filters

#### Modify Supported Post Types
```php
add_filter( 'wp_mcp_ai_content_assistant_post_types', function( $post_types ) {
    // Add custom post type
    $post_types[] = 'product';
    return $post_types;
} );
```

#### Modify Quick Actions
```php
add_filter( 'wp_mcp_ai_content_assistant_quick_actions', function( $actions ) {
    // Add custom action
    $actions[] = array(
        'slug'  => 'custom_action',
        'label' => __( 'Custom Action', 'textdomain' ),
        'icon'  => 'admin-tools',
    );
    return $actions;
} );
```

#### Enable/Disable Feature Programmatically
```php
add_filter( 'wp_mcp_ai_content_assistant_enabled', function( $enabled ) {
    // Disable for specific roles
    if ( current_user_can( 'subscriber' ) ) {
        return false;
    }
    return $enabled;
} );
```

## Usage Examples

### Example 1: Generate Content Outline
1. Create new post
2. Enter title: "10 Tips for Better WordPress Security"
3. Select an AI assistant from dropdown
4. Click "Generate Outline" button
5. Review AI-generated outline in result area
6. Copy outline to content editor

### Example 2: SEO Optimization
1. Edit existing post with content
2. Select an AI assistant
3. Click "SEO Optimize" button
4. Review SEO recommendations
5. Apply suggestions to improve post

### Example 3: Custom Request via Chat
1. Edit post or page
2. Select an AI assistant
3. Click "Open AI Assistant" button
4. In chat modal, type custom request:
   - "Add 3 call-to-action sections"
   - "Suggest related topics for internal linking"
   - "Create meta description"
5. AI responds with context-aware suggestions

## Browser Compatibility

- **Chrome/Edge**: Full support
- **Firefox**: Full support
- **Safari**: Full support
- **Mobile**: Responsive design, works on tablets

## Security

### Capability Checks
- Requires `edit_post` capability for the current post
- Nonce verification on all AJAX requests
- REST API authentication via WordPress nonce

### Data Sanitization
- All user input sanitized via `sanitize_text_field()`, `wp_kses_post()`
- All output escaped via `esc_html()`, `esc_attr()`, `esc_url()`

### Permissions
- Feature respects WordPress post editing permissions
- Only users who can edit the post can use the AI assistant
- Assistants must be published and accessible

## Performance

### Asset Loading
- CSS/JS only loaded on edit screens (`post.php`, `post-new.php`)
- Assets enqueued only for enabled post types
- Chat interface lazy-loaded when modal opens

### Caching
- Assistant list cached via WordPress transients
- No additional database queries during page load

## Troubleshooting

### Metabox Not Appearing
1. Check if feature is enabled in settings
2. Verify post type is in enabled list
3. Check user has `edit_post` capability
4. Clear WordPress object cache

### Quick Actions Not Working
1. Verify assistant is selected from dropdown
2. Check browser console for JavaScript errors
3. Verify AJAX endpoint is accessible
4. Check nonce is valid

### Modal Not Opening
1. Check JavaScript dependencies loaded (jQuery, chat.js)
2. Verify `wpMcpAiChat` global is defined
3. Check browser console for errors
4. Hard refresh browser (Ctrl+Shift+R)

### Chat Interface Issues
1. Verify REST API is accessible
2. Check REST nonce is valid
3. Verify assistant ID is valid post ID
4. Check browser console for API errors

## Development

### Adding Custom Quick Actions

1. **Filter the actions array**:
```php
add_filter( 'wp_mcp_ai_content_assistant_quick_actions', function( $actions ) {
    $actions[] = array(
        'slug'  => 'generate_faq',
        'label' => __( 'Generate FAQ', 'textdomain' ),
        'icon'  => 'editor-help',
    );
    return $actions;
} );
```

2. **Handle the action in JavaScript**:
```javascript
// Extend executeQuickAction() function
switch (action) {
    case 'generate_faq':
        prompt = 'Generate FAQ section for: ' + title;
        break;
}
```

### Extending for Custom Post Types

```php
// Enable for WooCommerce products
add_filter( 'wp_mcp_ai_content_assistant_post_types', function( $post_types ) {
    $post_types[] = 'product';
    return $post_types;
} );

// Modify context data for products
add_filter( 'wp_mcp_ai_content_assistant_context_data', function( $context, $post ) {
    if ( 'product' === $post->post_type ) {
        $context['price'] = get_post_meta( $post->ID, '_price', true );
        $context['stock'] = get_post_meta( $post->ID, '_stock', true );
    }
    return $context;
}, 10, 2 );
```

## Related Documentation

- [Project Management AI Assistant Metabox](../addons/pro/includes/metaboxes/) - Reference implementation
- [Chat Interface Documentation](./CHAT_INTERFACE.md)
- [REST API Documentation](./rest-api.md)
- [Tool System Documentation](./tool-reference.md)

## Support

For issues or feature requests, see:
- [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- [Contributing Guidelines](../CONTRIBUTING.md)
- [Documentation Index](./DOCUMENTATION_INDEX.md)
