# Chat UI Templates

## Overview

The WP oOS chat interface supports multiple visual templates (layouts) that change the appearance and structure of the chat interface. Templates are purely CSS-driven and do not affect functionality.

## Available Templates

### 1. Classic (Default)

The standard chat interface with messages displayed in rounded bubbles above the input field. This is the default template used when no template is specified.

**Characteristics:**
- Standard message bubbles with rounded corners
- Clear visual distinction between user and assistant messages
- Balanced spacing optimized for readability
- Full feature support including avatars, timestamps, and all controls

**Use Cases:**
- General-purpose chat interfaces
- Customer support widgets
- Virtual assistants on landing pages

### 2. Speech Bubbles

A stylistic variation inspired by comic-book style speech bubbles. Messages have distinctive "tails" pointing toward the speaker, emphasizing the conversational nature of the interface.

**Characteristics:**
- Comic-book style speech bubble tails on user and assistant messages
- User messages have tails on the right side
- Assistant messages have tails on the left side
- Tool and system messages use standard rounded bubbles without tails
- Slightly enhanced drop shadows for a lifted appearance

**Use Cases:**
- Creative or playful brand personalities
- Educational chatbots for children
- Entertainment or gaming-related assistants
- Conversational interfaces that emphasize dialogue

**Technical Notes:**
- Uses CSS pseudo-elements (::after) to create bubble tails
- User bubbles have `border-bottom-right-radius: 4px` for tail attachment
- Assistant bubbles have `border-bottom-left-radius: 4px` for tail attachment

### 3. Compact

A minimalist layout with reduced margins, smaller fonts, and optimized spacing. Ideal for embedding the chat in constrained spaces such as sidebars, mobile interfaces, or dashboard widgets.

**Characteristics:**
- Reduced padding and margins throughout
- Smaller font sizes (0.875rem for messages)
- Narrower maximum width (480px vs 720px)
- Smaller control buttons (32px vs 40px)
- Avatars hidden by default to save space
- Reduced message container height (240px max vs 360px)

**Use Cases:**
- Sidebar chat widgets
- Mobile-responsive layouts
- Dashboard integrations
- Embedded chat in content-heavy pages
- Quick Q&A interfaces

**Technical Notes:**
- Message font-size: 0.875rem (14px)
- Container padding: 12px (vs 20px in classic)
- Max width: 480px (vs 720px in classic)
- Message max-height: 240px (vs 360px in classic)

### 4. Sidebar

A ChatGPT-inspired layout with conversation history displayed in a persistent sidebar on the left. This template provides a two-column interface similar to modern AI chat applications.

**Characteristics:**
- Two-column grid layout (260px sidebar + flexible chat area)
- Persistent conversation history sidebar on the left
- **Available tools list** displayed in the sidebar showing all tools enabled for the assistant
- Chat interface on the right with full height
- Sidebar shows list of previous conversations
- No toggle needed - history is always visible
- Maximum width: 1200px to accommodate both panels
- Minimum height: 600px for optimal experience

**Use Cases:**
- Full-page chat applications
- AI assistant dashboards
- Multi-conversation management
- Professional/enterprise chat interfaces
- Learning management systems
- Customer support portals with conversation tracking

**Technical Notes:**
- Grid layout: `grid-template-columns: 260px 1fr`
- Sidebar background: `#f7f7f8` (light) / `#1f1f1f` (dark)
- History toggle button hidden (sidebar always visible)
- Tools list automatically populated from assistant configuration
- Responsive: Stacks vertically on screens < 768px
- Sidebar becomes scrollable horizontal panel on mobile

## Usage

### Block Editor

When using the WP oOS Chat block in the WordPress block editor:

1. Select the Chat block
2. In the block settings sidebar, locate the "Template" dropdown
3. Choose from: Classic, Speech Bubbles, Compact, or Sidebar
4. The preview will update to show the selected template

### Shortcode

When using the `[mcp_ai_chat]` shortcode:

```
[mcp_ai_chat assistant="123" template="speech-bubbles"]
```

```
[mcp_ai_chat assistant="123" template="compact"]
```

```
[mcp_ai_chat assistant="123" template="sidebar"]
```

```
[mcp_ai_chat assistant="123"] <!-- Defaults to "classic" -->
```

**Shortcode Parameters:**
- `template` (string, optional): Template name
  - Allowed values: `classic`, `speech-bubbles`, `compact`, `sidebar`
  - Default: `classic`
  - Invalid values will fallback to `classic`

### PHP/Programmatic Usage

When rendering chat interfaces programmatically:

```php
<?php
echo do_shortcode( '[mcp_ai_chat assistant="123" template="compact"]' );
?>
```

## Template Customization

### Custom CSS Variables

All templates respect the existing WP oOS CSS custom properties (CSS variables) for colors and theming:

```css
--wp-mcp-ai-color-bubble-user-background
--wp-mcp-ai-color-bubble-user-text
--wp-mcp-ai-color-bubble-assistant-background
--wp-mcp-ai-color-bubble-assistant-text
--wp-mcp-ai-color-container-border
--wp-mcp-ai-color-container-background
--wp-mcp-ai-color-container-shadow
```

### Targeting Specific Templates

To apply custom styles to a specific template, use the template-specific CSS class:

```css
/* Custom styles for speech-bubbles template */
.wp-mcp-ai-chat--template-speech-bubbles .wp-mcp-ai-chat__message {
    /* Your custom styles */
}

/* Custom styles for compact template */
.wp-mcp-ai-chat--template-compact .wp-mcp-ai-chat__input {
    /* Your custom styles */
}
```

### Data Attribute

Each chat container includes a `data-template` attribute for JavaScript or CSS targeting:

```html
<div class="wp-mcp-ai-chat wp-mcp-ai-chat--template-compact" 
     data-template="compact" 
     data-wp-mcp-ai-chat>
    <!-- Chat content -->
</div>
```

## Browser Compatibility

All templates use standard CSS features and are compatible with:

- Chrome/Edge (latest 2 versions)
- Firefox (latest 2 versions)
- Safari (latest 2 versions)
- iOS Safari (iOS 13+)
- Chrome for Android (latest)

Speech bubble tails use CSS border triangles, which have universal browser support.

## Performance Considerations

Templates are implemented using CSS classes and have minimal performance impact:

- No additional JavaScript execution
- No additional HTTP requests
- CSS is cached with the main chat stylesheet
- Template switching does not require page reload (when using block editor)

## Accessibility

All templates maintain the same semantic HTML structure and ARIA attributes:

- Role attributes unchanged
- ARIA labels preserved
- Keyboard navigation unaffected
- Screen reader compatibility maintained
- Focus indicators visible in all templates

## Future Templates

Additional templates planned for future releases:

- **Focused Input**: Larger input field with chat history scrolling above
- **Inline Chat**: Horizontal layout for simple Q&A exchanges
- **Timeline View**: Vertical timeline presentation with timestamps as waypoints

To request a specific template style, please open an issue on the GitHub repository.

## Troubleshooting

### Template Not Applying

1. **Clear browser cache** - The chat.css file may be cached
2. **Check template value** - Ensure the template name is spelled correctly (lowercase, hyphenated)
3. **Verify CSS is loaded** - Check browser DevTools to ensure chat.css is loaded
4. **Check for CSS conflicts** - Other plugins or themes may override styles

### Speech Bubble Tails Not Showing

1. Verify the browser supports CSS pseudo-elements (::after)
2. Check for conflicting CSS that may hide pseudo-elements
3. Ensure the message has the correct role class (wp-mcp-ai-chat__bubble--user or --assistant)

### Compact Mode Too Small

The compact template is optimized for tight spaces. If it's too small for your use case:

1. Use the classic template instead
2. Add custom CSS to override specific dimensions
3. Consider the speech-bubbles template as a middle ground

## Developer Notes

### Adding Custom Templates

To add your own custom template:

1. Add template name to the allowed templates array in `includes/class-wp-mcp-ai-shortcode.php`:
   ```php
   $allowed_templates = array( 'classic', 'speech-bubbles', 'compact', 'your-template' );
   ```

2. Add template option to block.json:
   ```json
   "template": {
       "type": "string",
       "default": "classic",
       "enum": [ "classic", "speech-bubbles", "compact", "your-template" ]
   }
   ```

3. Add CSS for your template in `assets/css/chat.css`:
   ```css
   .wp-mcp-ai-chat--template-your-template .wp-mcp-ai-chat__message {
       /* Your styles */
   }
   ```

### Template Detection in JavaScript

If you need to detect the current template in JavaScript:

```javascript
const container = document.querySelector('[data-wp-mcp-ai-chat]');
const template = container.getAttribute('data-template');

if (template === 'compact') {
    // Compact-specific logic
}
```

## Related Documentation

- [Chat Client Settings](./chat-client-settings.md)
- [Chat History & Persistence](./chat-history-persistence.md)
- [Chat Save Functions](../media/chat-save-functions.md)
