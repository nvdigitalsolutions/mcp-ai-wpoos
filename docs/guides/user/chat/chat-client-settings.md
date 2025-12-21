# Chat Client Settings Documentation

## Overview

The Chat Client Settings page provides comprehensive configuration options for the frontend chat interface. This settings section is located under **WP oOS → General Settings → Chat Client**.

## Features

### 1. Appearance Tab

Configure the visual aspects of your chat interface:

- **Chat Theme**: Choose between Light, Dark, or Auto (system preference)
- **Primary Color**: Set the main brand color for UI elements (HEX format)
- **User Message Bubble Color**: Customize user message background color
- **Assistant Message Bubble Color**: Customize assistant message background color
- **Border Radius**: Adjust the roundness of chat bubbles (0-50px)
- **Font Size**: Set base font size for messages (10-24px)
- **Show Timestamps**: Display message timestamps
- **Show Avatars**: Display user and assistant avatars
- **Compact Mode**: Reduce spacing for a condensed view

### 2. Behavior Tab

Control how the chat interface behaves:

- **Max History Messages to Display**: Limit visible message history (10-200)
- **Message Animation Delay**: Control appearance animation speed (0-2000ms)
- **Typing Indicator**: Show "..." while assistant responds
- **Auto-Scroll**: Automatically scroll to newest messages
- **Markdown Rendering**: Enable markdown formatting in messages
- **Code Syntax Highlighting**: Highlight code blocks
- **Persist Chat History**: Save conversations to browser localStorage (24hr)
- **Welcome Message**: Custom greeting when chat loads
- **Input Placeholder Text**: Custom placeholder in message input
- **Send Button Text**: Custom text for send button (or icon only)

### 3. Features Tab

Enable or disable chat functionality:

**Message Actions:**
- Copy Button - Copy messages to clipboard
- Save Button - Save messages locally
- Delete Button - Remove messages from history
- Regenerate Response - Request alternative responses

**Input Methods:**
- File Upload - Allow file attachments
- Voice Input (Transcription) - Voice-to-text using OpenAI Whisper
- Text-to-Speech Button - Audio playback using OpenAI TTS

**Additional Features:**
- Tool Shortcuts - Quick access tool buttons
- Message Search - Search conversation history
- Export Conversation - Download as text/PDF

**File Upload Settings:**
- Allowed File Types - Comma-separated extensions (jpg,png,pdf,docx)
- Max File Size - Upload limit in megabytes

### 4. LLM Sanitization Tab

Control content safety and response formatting:

**Sanitization Level:**
- **None** - Display raw LLM output (not recommended)
- **Basic** - Strip harmful HTML/JavaScript
- **Moderate** - Allow safe HTML, remove scripts/iframes (recommended)
- **Strict** - Convert all HTML to plain text

**Response Limits:**
- Max Response Length - Character limit for responses (0 = unlimited)

**3 Results Buttons Feature:**

When enabled, displays three customizable action buttons below each assistant response:

- **Button 1** - Default: "Refine" - Request refined/improved response
- **Button 2** - Default: "Alternative" - Request alternative approach
- **Button 3** - Default: "Expand" - Request more detailed explanation

Each button includes:
- Custom label text
- Custom system prompt (use `{original_response}` placeholder)

Example prompts:
```
Button 1: "Please refine your previous response: {original_response}"
Button 2: "Please provide an alternative approach to: {original_response}"
Button 3: "Please expand on your previous response with more detail: {original_response}"
```

### 5. Presets Tab

Quick-start configurations for common use cases:

#### Minimal Preset
Clean, distraction-free interface for focused conversations:
- Light theme
- Copy button only
- No file uploads
- Basic markdown
- Moderate sanitization

#### Full-Featured Preset
All features enabled for maximum functionality:
- All message action buttons
- File uploads enabled (jpg,png,pdf,docx,txt)
- Voice input/output
- Code highlighting
- Export & search
- 3 Results buttons enabled

#### Professional Preset
Business-focused setup for enterprise use:
- Light theme with professional colors
- Document uploads (pdf,docx,xlsx,txt,csv)
- Export conversations
- Search enabled
- Strict content sanitization
- No voice features

#### Accessible Preset
Optimized for users with accessibility needs:
- Large font size (18px)
- High contrast colors (#000000 primary, #0066CC user bubbles)
- Voice input/output enabled
- Timestamps & avatars visible
- Clear visual feedback
- Moderate sanitization

## Usage

### Accessing Settings

1. Navigate to **WordPress Admin Dashboard**
2. Click **WP oOS** in the left sidebar
3. Click **General Settings** tab (should be active by default)
4. Scroll down to find **Chat Client** section
5. Use the sub-tabs to navigate between settings categories

### Applying a Preset

1. Go to the **Presets** sub-tab
2. Review the four available presets
3. Click **Apply Preset** button on your desired configuration
4. Settings will be immediately populated in form fields
5. Click **Save Changes** at the bottom to apply

### Custom Configuration

1. Navigate to the appropriate sub-tab (Appearance, Behavior, Features, or LLM Sanitization)
2. Modify settings as needed
3. Click **Save Changes** at the bottom
4. Changes will apply immediately to all chat instances

## Technical Details

### Storage

Settings are stored in WordPress options table under the `wp_mcp_ai_settings` option. All chat client settings use the `chat_` prefix.

### JavaScript Integration

The chat client reads settings at initialization. To apply settings dynamically:

```javascript
// Settings are available in the localized wpMcpAiChat object
const chatSettings = wpMcpAiChat.chatSettings || {};
```

### Filter Hooks

Developers can override settings using WordPress filters:

```php
// Override chat theme
add_filter('wp_mcp_ai_chat_theme', function($theme) {
    return 'dark'; // Force dark theme
});

// Modify 3 results buttons configuration
add_filter('wp_mcp_ai_chat_result_buttons', function($buttons) {
    $buttons['enabled'] = true;
    $buttons['button_1_label'] = 'Custom Label';
    return $buttons;
});
```

### Security Considerations

- **File Uploads**: Always validate file types and sizes on the server
- **Sanitization**: Use "Moderate" or "Strict" levels for public-facing chats
- **LLM Prompts**: Custom button prompts are sent to the LLM; avoid including sensitive data
- **localStorage**: Chat history expires after 24 hours for privacy

## Troubleshooting

### Settings Not Saving

- Check user has `manage_options` capability
- Verify WordPress nonce is valid
- Check browser console for JavaScript errors
- Enable logging in General Settings to debug

### Preset Not Applying

- Ensure JavaScript is enabled in browser
- Clear browser cache
- Check console for errors
- Click "Save Changes" after applying preset

### Features Not Appearing

Some features require tools to be enabled:
- **Text-to-Speech**: Enable "Generate OpenAI Speech" tool
- **Voice Input**: Enable "Transcribe OpenAI Audio" tool
- **File Upload**: Check WordPress upload limits

## Best Practices

1. **Start with a Preset**: Choose the preset closest to your needs, then customize
2. **Test Thoroughly**: Preview changes on a test/staging site first
3. **Consider Your Audience**: 
   - Public sites: Use Accessible or Professional preset
   - Internal tools: Use Full-Featured preset
   - Simple chatbots: Use Minimal preset
4. **Security First**: Always use at least "Moderate" sanitization for user-facing chats
5. **Performance**: Disable unused features to reduce client-side JavaScript load
6. **Mobile**: Test appearance settings on mobile devices (use Compact Mode if needed)

## Related Documentation

- [General Settings](../../../QUICK_REFERENCE.md)
- [Tool Reference](../../../reference/tools/tool-reference.md)
- [REST API](../../../reference/api/rest-api.md)
- [Security Best Practices](../../developer/best-practices/BEST_PRACTICES.md)

## Support

For issues or questions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: See `docs/` directory
- Security Issues: See SECURITY.md
