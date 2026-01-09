# Chat Interface Screenshot Capture Guide

This guide provides comprehensive instructions for capturing the 16 required chat interface screenshots for NV oOS documentation.

## Overview

The chat interface screenshots demonstrate the various features and states of the NV oOS chat functionality, including:
- Basic interface and conversation flow
- File attachments and tool execution
- Mobile responsive views
- Guest access mode
- History persistence
- Error handling
- Optional Elementor integration

## Prerequisites

### Required
- Docker and Docker Compose installed
- AI Provider API key (one of):
  - OpenAI API key (`OPENAI_API_KEY`)
  - Google Gemini API key (`GEMINI_API_KEY`)
  - Ollama server URL (`OLLAMA_URL`)

### Optional (for automation)
- Node.js and npm (for Playwright automation)
- Playwright: `npm install playwright`

## Quick Start (Automated Setup)

The fastest way to capture screenshots is using our automation scripts:

### Step 1: Environment Setup

```bash
# Clone the repository (if not already done)
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos

# Start Docker environment
docker compose up -d

# Wait for WordPress to be ready (30-60 seconds)
sleep 30
```

### Step 2: Configure AI Provider

```bash
# Set your AI provider API key
export OPENAI_API_KEY="sk-your-openai-key-here"
# OR
export GEMINI_API_KEY="your-gemini-key-here"
# OR
export OLLAMA_URL="http://localhost:11434"
```

### Step 3: Run Setup Script

```bash
# Make the script executable
chmod +x bin/capture-chat-screenshots.sh

# Run the setup script
./bin/capture-chat-screenshots.sh
```

This script will:
1. ✅ Verify Docker is running
2. ✅ Install WordPress (if needed)
3. ✅ Activate the NV oOS plugin
4. ✅ Configure your AI provider
5. ✅ Create a test assistant
6. ✅ Create chat demo pages (standard and guest mode)
7. ✅ Output URLs and instructions for screenshot capture

### Step 4: Capture Screenshots (Automated)

```bash
# Extract page IDs from the setup script output
export PAGE_ID="<standard-page-id>"
export GUEST_PAGE_ID="<guest-page-id>"

# Install Playwright dependencies (if not already installed)
npm install playwright

# Run the Playwright automation script
node bin/playwright-capture-screenshots.js
```

The Playwright script will automatically capture 12 of the 16 screenshots. The remaining 4 (Elementor widgets) require manual capture.

## Manual Screenshot Capture

If you prefer manual capture or need to supplement automated captures:

### Access WordPress

- **URL**: http://localhost:8000
- **Admin**: http://localhost:8000/wp-admin
- **Username**: admin
- **Password**: StrongPassword123!

### Screenshot Specifications

| Screenshot | Filename | Resolution | Priority | Description |
|------------|----------|------------|----------|-------------|
| 1 | `frontend-shortcode.png` | 1920x1080 | HIGH | Basic chat interface via shortcode |
| 2 | `chat-conversation-example.png` | 1920x1080 | HIGH | Active conversation with multiple messages |
| 3 | `chat-with-attachments.png` | 1920x1080 | MEDIUM | File upload interface with selected file |
| 4 | `chat-tool-execution.png` | 1920x1080 | HIGH | Tool execution in progress with feedback |
| 5 | `chat-streaming-response.png` | 1920x1080 | MEDIUM | Streaming response animation |
| 6 | `chat-shortcuts-buttons.png` | 1920x1080 | MEDIUM | Prompt shortcuts interface |
| 7 | `chat-error-handling.png` | 1280x720 | LOW | Error state display |
| 8 | `chat-mobile-portrait.png` | 375x667 | MEDIUM | Mobile portrait view (iPhone SE) |
| 9 | `chat-mobile-landscape.png` | 667x375 | LOW | Mobile landscape view |
| 10 | `frontend-guest-mode.png` | 1920x1080 | HIGH | Guest access (incognito/logged out) |
| 11 | `chat-history-localstorage.png` | 1920x1080 | LOW | DevTools showing localStorage |
| 12 | `chat-history-restoration.png` | 1920x1080 | LOW | History restored after page reload |
| 13 | `elementor-chat-widget.png` | 1920x1080 | MEDIUM | Elementor editor with chat widget |
| 14 | `elementor-chat-widget-frontend.png` | 1920x1080 | MEDIUM | Published Elementor page with widget |
| 15 | `elementor-dashboard-widgets.png` | 1920x1080 | LOW | Elementor dashboard widgets panel |
| 16 | `elementor-chat-intro-widget.png` | 1280x720 | LOW | Chat intro widget configuration |

### Detailed Capture Instructions

#### 1. Basic Interface (`frontend-shortcode.png`)

1. Navigate to the chat page: http://localhost:8000/?page_id=<PAGE_ID>
2. Wait for page to fully load
3. Ensure chat interface is visible
4. Take full-page screenshot
5. Save as `docs/screenshots/chat/frontend-shortcode.png`

#### 2. Active Conversation (`chat-conversation-example.png`)

1. On the same chat page
2. Send message: "Hello! Can you help me understand what you can do?"
3. Wait for AI response
4. Send message: "What tools do you have access to?"
5. Wait for AI response
6. Send message: "Can you search for content on this website?"
7. Wait for response
8. Take full-page screenshot showing conversation history
9. Save as `docs/screenshots/chat/chat-conversation-example.png`

#### 3. File Attachments (`chat-with-attachments.png`)

1. Click the file upload button (paperclip icon or similar)
2. Select a file (PDF, image, or document)
3. Wait for file preview to appear
4. Take screenshot showing:
   - Upload button
   - Selected file name/preview
   - File size indicator
5. Save as `docs/screenshots/chat/chat-with-attachments.png`

#### 4. Tool Execution (`chat-tool-execution.png`)

1. Send a message that triggers a tool: "Search my website for 'WordPress'"
2. Capture screenshot immediately during tool execution
3. Should show:
   - Tool name being executed
   - Progress indicator
   - Partial tool output (if available)
4. Save as `docs/screenshots/chat/chat-tool-execution.png`

#### 5. Streaming Response (`chat-streaming-response.png`)

1. Send message: "Tell me a detailed story about WordPress plugins"
2. Immediately after clicking send, prepare to screenshot
3. Capture while text is still streaming (partial response visible)
4. Should show:
   - Message being typed out in real-time
   - Streaming cursor/indicator
5. Save as `docs/screenshots/chat/chat-streaming-response.png`

#### 6. Prompt Shortcuts (`chat-shortcuts-buttons.png`)

*Note: Requires assistant configured with prompt shortcuts*

1. If assistant has shortcuts configured, they should be visible
2. Take screenshot showing:
   - Shortcut buttons with labels
   - Button styling and layout
   - Tooltip on hover (if possible)
3. Save as `docs/screenshots/chat/chat-shortcuts-buttons.png`

#### 7. Error Handling (`chat-error-handling.png`)

1. Trigger an error state (options):
   - Disconnect network temporarily
   - Send message while API is unavailable
   - Invalidate API key in settings
2. Take screenshot showing error message
3. Should include:
   - Error text
   - Error styling (color, icon)
   - Retry button (if available)
4. Save as `docs/screenshots/chat/chat-error-handling.png`

#### 8. Mobile Portrait (`chat-mobile-portrait.png`)

1. Open browser DevTools (F12)
2. Click device toolbar icon
3. Select "iPhone SE" or custom 375x667
4. Reload chat page
5. Take screenshot in portrait orientation
6. Save as `docs/screenshots/chat/chat-mobile-portrait.png`

#### 9. Mobile Landscape (`chat-mobile-landscape.png`)

1. In DevTools device mode
2. Rotate to landscape or set custom 667x375
3. Reload if needed
4. Take screenshot in landscape orientation
5. Save as `docs/screenshots/chat/chat-mobile-landscape.png`

#### 10. Guest Mode (`frontend-guest-mode.png`)

1. Open incognito/private browsing window
2. Navigate to guest page: http://localhost:8000/?page_id=<GUEST_PAGE_ID>
3. Verify no admin bar is visible (not logged in)
4. Send a test message
5. Take full-page screenshot
6. Save as `docs/screenshots/chat/frontend-guest-mode.png`

#### 11. localStorage View (`chat-history-localstorage.png`)

1. On chat page with conversation history
2. Open DevTools (F12)
3. Go to Application tab
4. Expand Local Storage → http://localhost:8000
5. Find keys related to wp_mcp_ai or chat history
6. Take screenshot showing:
   - DevTools localStorage panel
   - Chat history data
   - Key-value pairs
7. Save as `docs/screenshots/chat/chat-history-localstorage.png`

#### 12. History Restoration (`chat-history-restoration.png`)

1. Have an active conversation with 3-4 messages
2. Note the conversation content
3. Reload the page (Ctrl+R or Cmd+R)
4. Wait for page load
5. Take screenshot showing restored conversation
6. Should demonstrate:
   - All previous messages restored
   - Conversation flow maintained
   - Ready for new messages
7. Save as `docs/screenshots/chat/chat-history-restoration.png`

#### 13-16. Elementor Widgets (Requires Elementor Plugin)

**Prerequisites:**
```bash
# Install Elementor
docker compose run --rm wp-cli plugin install elementor --activate
```

##### 13. Elementor Chat Widget (`elementor-chat-widget.png`)

1. Go to Pages → Add New
2. Click "Edit with Elementor"
3. In left panel, search for "NV oOS" or "Chat"
4. Drag "NV oOS Chat" widget to page
5. Take screenshot showing:
   - Elementor editor interface
   - Widget in left panel
   - Chat widget on canvas
   - Widget settings panel
6. Save as `docs/screenshots/chat/elementor-chat-widget.png`

##### 14. Elementor Chat Widget Frontend (`elementor-chat-widget-frontend.png`)

1. In Elementor editor, configure chat widget
2. Click "Publish" button
3. Click "View Page" or open frontend
4. Take screenshot of published page with chat widget
5. Should show:
   - Elementor page design
   - Integrated chat widget
   - Clean frontend appearance
6. Save as `docs/screenshots/chat/elementor-chat-widget-frontend.png`

##### 15. Elementor Dashboard Widgets (`elementor-dashboard-widgets.png`)

1. In Elementor editor, find NV oOS dashboard widgets:
   - Activity Feed
   - Usage Timer
   - Tool Matrix
2. Drag widgets to page
3. Configure each widget
4. Take screenshot showing all dashboard widgets
5. Save as `docs/screenshots/chat/elementor-dashboard-widgets.png`

##### 16. Elementor Chat Intro Widget (`elementor-chat-intro-widget.png`)

1. In Elementor editor, find "Chat Intro" widget
2. Drag to page
3. Configure intro content (FAQ, instructions, etc.)
4. Take screenshot of widget configuration
5. Save as `docs/screenshots/chat/elementor-chat-intro-widget.png`

## Screenshot Quality Guidelines

### Technical Requirements
- **Format**: PNG (preferred for UI screenshots)
- **Compression**: Optimize to keep under 500KB when possible
- **Resolution**: Match specified dimensions for each screenshot
- **Browser**: Chrome or Firefox at 100% zoom
- **Color Depth**: 24-bit color minimum

### Content Guidelines
- **Clean Data**: Use generic, example content (no real user data)
- **No Secrets**: Remove API keys, tokens, or sensitive information
- **Consistent Theme**: Use same theme/styling across all screenshots
- **Readable Text**: Ensure all text is crisp and legible
- **Meaningful State**: Capture relevant UI states (not empty/loading states)

### Best Practices
1. Clear browser cache before capturing
2. Use consistent sample data across screenshots
3. Capture during daytime (if UI shows timestamps)
4. Include relevant context (e.g., admin bar for authenticated views)
5. Avoid capturing personal information or real domains
6. Use placeholder emails: admin@example.com
7. Crop screenshots to remove empty space (keep context)

## Screenshot Optimization

After capturing, optimize file sizes:

### Using pngquant (Command Line)

```bash
# Install pngquant
# Mac: brew install pngquant
# Ubuntu: apt-get install pngquant

# Optimize single file
pngquant --quality=65-80 --ext .png --force docs/screenshots/chat/frontend-shortcode.png

# Optimize all chat screenshots
find docs/screenshots/chat -name "*.png" -exec pngquant --quality=65-80 --ext .png --force {} \;
```

### Using TinyPNG (Online)

1. Visit https://tinypng.com/
2. Upload PNG files (up to 20 at once)
3. Download optimized versions
4. Replace original files

### Using ImageOptim (Mac)

1. Download from https://imageoptim.com/
2. Drag and drop screenshot files
3. Automatically optimizes and saves

## Troubleshooting

### Docker Issues

**Problem**: Docker containers not starting
```bash
# Solution
docker compose down
docker compose up -d
docker compose ps  # Verify status
```

**Problem**: WordPress not accessible
```bash
# Solution: Wait longer or check logs
docker compose logs wordpress
docker compose logs db
```

### WordPress Issues

**Problem**: Plugin not activated
```bash
# Solution
docker compose run --rm wp-cli plugin activate wp-mcp-ai
```

**Problem**: White screen / PHP errors
```bash
# Solution: Check error logs
docker compose logs wordpress | grep -i error
```

### AI Provider Issues

**Problem**: Chat not responding
```bash
# Solution: Verify API key
docker compose run --rm wp-cli option get wp_mcp_ai_openai_api_key
docker compose run --rm wp-cli option get wp_mcp_ai_default_provider
```

**Problem**: Tool execution fails
```bash
# Solution: Check plugin logs
docker compose run --rm wp-cli option get wp_mcp_ai_recent_errors
```

### Screenshot Issues

**Problem**: UI elements not visible
- Solution: Wait for full page load (networkidle)
- Solution: Check viewport size is correct
- Solution: Verify CSS is loaded

**Problem**: Mobile view not responsive
- Solution: Use DevTools device emulation
- Solution: Set exact pixel dimensions
- Solution: Reload page after resizing

**Problem**: Elementor widgets missing
- Solution: Install Elementor plugin
- Solution: Activate Elementor integration in NV oOS settings
- Solution: Clear Elementor cache

## Verification Checklist

Before submitting screenshots, verify:

- [ ] All 16 screenshots captured
- [ ] File naming convention followed
- [ ] Files saved to `docs/screenshots/chat/`
- [ ] No sensitive data visible (API keys, real emails, etc.)
- [ ] Image resolution matches specifications
- [ ] File sizes optimized (under 500KB preferred)
- [ ] Images are clear and readable
- [ ] UI states are meaningful (not loading/empty states)
- [ ] Mobile screenshots use correct dimensions
- [ ] Guest mode screenshot shows no admin bar
- [ ] DevTools screenshot clearly shows localStorage

## Next Steps

After capturing screenshots:

1. **Commit to repository**:
   ```bash
   git add docs/screenshots/chat/
   git commit -m "Add chat interface screenshots (16 total)"
   git push origin your-branch
   ```

2. **Update documentation**:
   - Update `docs/screenshots/SCREENSHOT_PROGRESS.md` to mark chat screenshots as complete
   - Add screenshot references to relevant documentation files
   - Update README.md with screenshot examples

3. **Quality review**:
   - Review all screenshots for clarity
   - Check file sizes and optimize if needed
   - Verify no sensitive information is visible
   - Ensure consistency across all screenshots

## Additional Resources

- **Main Documentation**: [docs/README.md](../README.md)
- **Screenshot Index**: [docs/screenshots/README.md](../screenshots/README.md)
- **Chat Documentation**: [docs/screenshots/chat/README.md](../screenshots/chat/README.md)
- **Quick Reference**: [docs/QUICK_REFERENCE.md](../QUICK_REFERENCE.md)

## Support

For questions or issues:
- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- **Documentation**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos#documentation
- **Contributing**: [CONTRIBUTING.md](../../CONTRIBUTING.md)

---

**Last Updated**: January 9, 2026
**Version**: 1.0.0
**Status**: ✅ Ready for Use
