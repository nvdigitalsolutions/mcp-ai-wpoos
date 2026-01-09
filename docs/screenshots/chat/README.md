# Chat Interface Screenshots

This directory contains screenshots of the chat interface, shortcodes, and frontend user experience.

## Screenshots Needed

### Frontend Chat Interface
1. **frontend-shortcode.png** - Basic chat interface
   - Location: Any WordPress page/post with `[mcp_ai_chat assistant="123"]` shortcode
   - Should show:
     - Message input field
     - Send button
     - Chat history with messages
     - Tool shortcuts buttons (if available)
     - Clean, styled interface
   - Resolution: 1920x1080 minimum
   - Priority: HIGH
   - **Setup**: Create a test page with the shortcode

2. **frontend-guest-mode.png** - Guest access chat
   - Location: Page with `[mcp_ai_chat assistant="123" allow_guests="true"]`
   - Should show:
     - Same interface as above
     - User NOT logged in (no admin bar)
     - Guest token working seamlessly
   - Resolution: 1920x1080 minimum
   - Priority: HIGH
   - **Setup**: View page in incognito/private browsing mode

3. **chat-conversation-example.png** - Active conversation
   - Location: Chat interface with ongoing conversation
   - Should show:
     - Multiple user messages
     - Multiple assistant responses
     - Proper message formatting
     - Timestamp display (if applicable)
     - Smooth conversation flow
   - Resolution: 1920x1080 minimum
   - Priority: HIGH
   - **Setup**: Have a real conversation with sample questions

### Chat Features
4. **chat-with-attachments.png** - File upload interface
   - Location: Chat interface with attachment upload
   - Should show:
     - File upload button/area
     - Selected files preview
     - Supported file types indicator
     - File size limits message
   - Resolution: 1920x1080 minimum
   - Priority: MEDIUM
   - **Setup**: Upload a PDF or image file

5. **chat-tool-execution.png** - Tool call in progress
   - Location: Chat interface during tool execution
   - Should show:
     - Tool execution progress indicator
     - Tool name and parameters visible
     - Loading/streaming state
     - Tool output when complete
   - Resolution: 1920x1080 minimum
   - Priority: HIGH
   - **Setup**: Trigger a tool like `search_content` or `web_search`

6. **chat-streaming-response.png** - Streaming response in action
   - Location: Chat interface during streaming
   - Should show:
     - Partial response being typed out
     - Streaming indicator (if visible)
     - Real-time text appearing
   - Resolution: 1920x1080 minimum
   - Priority: MEDIUM
   - **Setup**: May need to capture video and extract frame

7. **chat-shortcuts-buttons.png** - Prompt shortcuts
   - Location: Chat interface with shortcut buttons visible
   - Should show:
     - Prompt shortcut buttons
     - Button labels and descriptions
     - Shortcut categories (if applicable)
     - Hover states or tooltips
   - Resolution: 1920x1080 minimum
   - Priority: MEDIUM
   - **Setup**: Configure assistant with custom prompt shortcuts

8. **chat-error-handling.png** - Error state
   - Location: Chat interface showing error message
   - Should show:
     - Error message display
     - Error formatting
     - Recovery options (retry, etc.)
   - Resolution: 1280x720 minimum
   - Priority: LOW
   - **Setup**: Trigger an error (invalid API key, rate limit, etc.)

### Mobile/Responsive Views
9. **chat-mobile-portrait.png** - Mobile view (portrait)
   - Location: Chat interface on mobile device or browser DevTools
   - Resolution: 375x667 (iPhone SE) or similar
   - Priority: MEDIUM
   - **Setup**: Use browser DevTools device emulation

10. **chat-mobile-landscape.png** - Mobile view (landscape)
    - Location: Chat interface on mobile in landscape
    - Resolution: 667x375 or similar
    - Priority: LOW
    - **Setup**: Use browser DevTools device emulation

### Elementor Integration
11. **elementor-chat-widget.png** - Chat widget in Elementor editor
    - Location: Elementor page editor with NV oOS Chat widget
    - Should show:
      - Widget settings panel
      - Chat preview in editor
      - Configuration options
    - Resolution: 1920x1080 minimum
    - Priority: MEDIUM
    - **Setup**: Requires Elementor plugin installed

12. **elementor-chat-widget-frontend.png** - Chat widget on frontend
    - Location: Published Elementor page with chat widget
    - Should show:
      - Widget rendered on page
      - Styling within Elementor layout
      - Integration with page design
    - Resolution: 1920x1080 minimum
    - Priority: MEDIUM
    - **Setup**: Requires Elementor plugin

13. **elementor-dashboard-widgets.png** - Elementor dashboard widgets
    - Location: Elementor page with dashboard widgets
    - Should show:
      - Activity Feed widget
      - Usage Timer widget
      - Tool Matrix widget
      - Other NV oOS Elementor widgets
    - Resolution: 1920x1080 minimum
    - Priority: LOW
    - **Setup**: Requires Elementor plugin

14. **elementor-chat-intro-widget.png** - Chat Intro widget
    - Location: Elementor page with Chat Intro widget
    - Should show:
      - Intro/FAQ content
      - Onboarding information
      - Widget styling
    - Resolution: 1280x720 minimum
    - Priority: LOW
    - **Setup**: Requires Elementor plugin

### Chat History & Persistence
15. **chat-history-localstorage.png** - Browser DevTools showing localStorage
    - Location: Chat interface with browser DevTools open
    - Should show:
      - localStorage entries for chat history
      - Data structure visible
      - 24-hour retention note
    - Resolution: 1920x1080 minimum
    - Priority: LOW
    - **Setup**: Open DevTools → Application → Local Storage

16. **chat-history-restoration.png** - Chat history restored on page reload
    - Location: Chat interface after page reload
    - Should show:
      - Previous conversation restored
      - History intact
      - Seamless continuation
    - Resolution: 1920x1080 minimum
    - Priority: LOW
    - **Setup**: Have conversation, reload page, show history persists

## Screenshot Guidelines

### For Chat Conversations
- Use realistic but generic content
- Avoid sensitive or proprietary information
- Show polished, helpful responses
- Include varied message types (text, lists, etc.)

### For Tool Execution
- Choose common tools users will recognize
- Show clear before/during/after states
- Capture tool output in readable format
- Demonstrate successful execution

### For Mobile Views
- Use standard device dimensions
- Test touch interactions work properly
- Ensure text is readable at mobile size
- Show responsive layout adapts correctly

### For Elementor Screenshots
- Show widget integration naturally
- Highlight NV oOS-specific features
- Include Elementor branding/UI for context
- Demonstrate ease of use

### Browser Setup
- Chrome or Firefox recommended
- Clean browser profile (no extensions visible unless relevant)
- 100% zoom level
- Light or dark theme (consistent across screenshots)

### File Optimization
- Chat screenshots should be relatively small
- Use PNG for UI sharpness
- Compress to keep under 300KB if possible
