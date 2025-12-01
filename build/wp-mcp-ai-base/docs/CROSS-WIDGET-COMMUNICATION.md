# Cross-Widget Communication: Loading Sessions into Chat

## Overview

The WP oOS plugin now supports loading chat sessions from the User Chat History widget into the main Chat widget. This enables users to review previous conversations and seamlessly load them into the active chat interface.

## Feature Description

When you have both a Chat widget and a User Chat History widget on the same page, users can now:

1. Browse their previous chat sessions in the User Chat History widget
2. Click "Load into chat" button next to any session
3. The selected conversation will be loaded into the Chat widget
4. Continue the conversation from where it left off

## Setup

### Basic Setup (Auto-detection)

If you have a single Chat widget on your page, no configuration is needed:

1. Add a Chat widget to your page
2. Add a User Chat History widget to your page
3. The User Chat History will automatically detect and target the Chat widget

### Multiple Chat Widgets Setup

If you have multiple Chat widgets on the page, you can specify which one to target:

#### Option 1: Using CSS Selector (Recommended)

1. Give your target Chat widget a unique ID:
   - In Elementor: Select the Chat widget → Advanced tab → CSS ID field → Enter "my-main-chat"
   
2. Configure the User Chat History widget:
   - Select User Chat History widget
   - Settings → Target Chat Widget field
   - Enter: `#my-main-chat`

#### Option 2: Auto-detection

If no target is specified, the system will automatically select the **closest** Chat widget based on DOM position.

## Configuration Options

### User Chat History Widget Settings

| Setting | Description | Example |
|---------|-------------|---------|
| Target Chat Widget | CSS selector for the target chat widget | `#my-chat` or `.main-chat` |

### Supported CSS Selectors

- **ID selector**: `#my-chat-widget`
- **Class selector**: `.main-chat`
- **Data attribute**: `[data-chat="main"]`

## How It Works

### Technical Flow

1. **Initialization**:
   - Chat widgets store their state in `container.__wpMcpAiChatState`
   - User Chat History widget scans for available Chat widgets
   - Auto-detects closest widget or uses configured selector

2. **Loading a Session**:
   - User clicks "Load into chat" button
   - Fetches full session details from REST API
   - Calls `window.wpMcpAiLoadSession()` with:
     - Session key
     - Assistant ID
     - Message array
     - Target widget selector
   - Chat widget loads messages and updates conversation state

3. **Session Restoration**:
   - Clears current chat messages
   - Loads historical messages in order
   - Updates session key for continuation
   - Saves to localStorage for persistence

## API Reference

### Global JavaScript API

#### `window.wpMcpAiLoadSession(options)`

Programmatically load a session into a chat widget.

**Parameters:**

```javascript
{
  sessionKey: string,        // Session identifier
  assistantId: number,       // Assistant ID
  messages: Array<{          // Message array
    role: string,            // 'user', 'assistant', 'system', 'tool'
    content: string          // Message content
  }>,
  target: string|HTMLElement // CSS selector or element
}
```

**Returns:** `boolean` - True if successful, false otherwise

**Example:**

```javascript
window.wpMcpAiLoadSession({
  sessionKey: 'session-123',
  assistantId: 45,
  messages: [
    { role: 'user', content: 'Hello' },
    { role: 'assistant', content: 'Hi! How can I help?' }
  ],
  target: '#my-chat-widget'
});
```

## Styling Customization

### Load Button Styles

You can customize the "Load into chat" button appearance:

```css
.wp-mcp-ai-user-chats__load-button {
  /* Your custom styles */
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  border-radius: 8px;
  padding: 0.5rem 1rem;
}

.wp-mcp-ai-user-chats__load-button:hover {
  transform: scale(1.05);
  box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}
```

## Troubleshooting

### "Unable to load into chat" Error

**Possible Causes:**

1. **Target widget not found**
   - Verify the CSS selector in Target Chat Widget field
   - Ensure the Chat widget has the specified ID or class
   - Check browser console for warnings

2. **Multiple widgets on page**
   - Specify explicit target using Target Chat Widget field
   - Use unique IDs for each Chat widget

3. **JavaScript not loaded**
   - Check browser console for errors
   - Verify both widgets are on the same page
   - Clear browser cache and reload

### Load Button Not Appearing

**Possible Causes:**

1. **No Chat widget detected**
   - Add at least one Chat widget to the page
   - Ensure it's not hidden with CSS

2. **JavaScript error**
   - Open browser console (F12)
   - Look for JavaScript errors
   - Report errors to support

### Session Loads but Doesn't Continue

**Expected Behavior:**

- Loading a session replaces the current chat conversation
- You can continue the loaded conversation by sending new messages
- The session key is preserved for continuation

## Best Practices

1. **Widget Placement**
   - Place User Chat History widget near the Chat widget for better UX
   - Use columns or tabs to organize widgets

2. **Multiple Chat Widgets**
   - Always use explicit Target Chat Widget configuration
   - Give each Chat widget a unique ID
   - Test loading into each widget

3. **User Experience**
   - Consider adding explanatory text above the User Chat History widget
   - Use clear labels like "Load this conversation" or "Continue in chat"

4. **Performance**
   - Limit max sessions displayed (default: 20)
   - Sessions with many messages may take longer to load

## Examples

### Example 1: Simple Two-Column Layout

```
[Column 1: User Chat History]
[Column 2: Chat Widget]
```

**Configuration:**
- No target configuration needed (auto-detected)

### Example 2: Tabbed Interface

```
Tab 1: Active Chat [Chat Widget ID: "active-chat"]
Tab 2: History [User Chat History → Target: "#active-chat"]
```

**Configuration:**
- Chat widget: CSS ID = "active-chat"
- User Chat History: Target Chat Widget = "#active-chat"

### Example 3: Multiple Chat Widgets

```
[Support Chat Widget ID: "support-chat"]
[Sales Chat Widget ID: "sales-chat"]
[User Chat History → Target: "#support-chat"]
```

**Configuration:**
- Support Chat: CSS ID = "support-chat"
- Sales Chat: CSS ID = "sales-chat"
- User Chat History: Target Chat Widget = "#support-chat"

## Security Considerations

- Session loading respects WordPress user permissions
- Users can only load their own sessions (unless admin)
- REST API nonce validation ensures secure requests
- No sensitive data exposed in JavaScript API

## Browser Compatibility

- Modern browsers (Chrome, Firefox, Safari, Edge)
- ES5+ JavaScript support required
- No polyfills needed

## Related Documentation

- [Elementor Widgets Guide](./elementor-widgets.md)
- [REST API Reference](./rest-api.md)
- [Chat Widget Configuration](./chat-widget-configuration.md)

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- Documentation: https://github.com/nvdigitalsolutions/wp-mcp-ai/tree/main/docs
