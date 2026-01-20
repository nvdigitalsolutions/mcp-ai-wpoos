# Screenshot Capture Tools - Quick Reference

## Purpose

Automated tools for capturing the 16 chat interface screenshots required for NV oOS documentation.

## Files

1. **`capture-chat-screenshots.sh`** - Setup script (Bash)
   - Configures WordPress environment
   - Sets up AI provider
   - Creates test assistant and pages
   
2. **`playwright-capture-screenshots.js`** - Automation script (Node.js)
   - Automates screenshot capture using Playwright
   - Captures 12 of 16 screenshots automatically

3. **`../docs/screenshots/CHAT_CAPTURE_GUIDE.md`** - Comprehensive documentation
   - Detailed instructions for all 16 screenshots
   - Troubleshooting guide
   - Quality guidelines

## Prerequisites

- Docker and Docker Compose running
- AI Provider API key (one of):
  - OpenAI: `OPENAI_API_KEY`
  - Google Gemini: `GEMINI_API_KEY`
  - Ollama: `OLLAMA_URL`
- Node.js and npm (for Playwright automation)

## Quick Start

```bash
# 1. Ensure Docker is running
docker compose up -d

# 2. Set your AI provider API key
export OPENAI_API_KEY="sk-your-key-here"

# 3. Run setup script
./bin/capture-chat-screenshots.sh

# This will output page IDs like:
# Created page with ID: 123
# Created guest page with ID: 124

# 4. Set page IDs from output
export PAGE_ID="123"
export GUEST_PAGE_ID="124"

# 5. Install Playwright (first time only)
npm install playwright

# 6. Run screenshot automation
node bin/playwright-capture-screenshots.js

# 7. Done! Check docs/screenshots/chat/ for screenshots
```

## What Gets Automated

### Setup Script (`capture-chat-screenshots.sh`)

✅ Installs WordPress if needed
✅ Activates NV oOS plugin
✅ Configures AI provider with your API key
✅ Creates test assistant with basic configuration
✅ Creates standard chat page with shortcode
✅ Creates guest mode chat page
✅ Outputs URLs and IDs for next steps

**Time:** 2-3 minutes

### Playwright Automation (`playwright-capture-screenshots.js`)

✅ Captures desktop views (1920x1080)
✅ Captures mobile portrait (375x667)
✅ Captures mobile landscape (667x375)
✅ Tests conversation flow
✅ Captures file upload interface
✅ Captures tool execution
✅ Captures streaming responses
✅ Captures error handling
✅ Tests guest mode (incognito)
✅ Tests history persistence

**Time:** 5-10 minutes
**Result:** 12 of 16 screenshots captured

### Manual Captures (Elementor)

The following 4 screenshots require Elementor plugin and manual capture:

```bash
# Install Elementor
docker compose run --rm wp-cli plugin install elementor --activate
```

Then follow instructions in `docs/screenshots/CHAT_CAPTURE_GUIDE.md` for:
- `elementor-chat-widget.png`
- `elementor-chat-widget-frontend.png`
- `elementor-dashboard-widgets.png`
- `elementor-chat-intro-widget.png`

**Time:** 10-15 minutes

## Environment Variables

### Required (choose one)
- `OPENAI_API_KEY` - OpenAI API key (e.g., "sk-proj-...")
- `GEMINI_API_KEY` - Google Gemini API key
- `OLLAMA_URL` - Ollama server URL (e.g., "http://localhost:11434")

### Optional
- `WORDPRESS_URL` - WordPress URL (default: http://localhost:8000)
- `WP_ADMIN_USER` - Admin username (default: admin)
- `WP_ADMIN_PASS` - Admin password (default: StrongPassword123!)
- `PAGE_ID` - Chat page ID (from setup script output)
- `GUEST_PAGE_ID` - Guest chat page ID (from setup script output)

## Outputs

### Setup Script Output

```
✅ Prerequisites check passed!
✅ WordPress already installed
✅ Plugin activated
✅ AI provider configured successfully
✅ Created assistant with ID: 5
✅ Created page with ID: 7
✅ Created guest page with ID: 8

=========================================
Setup Complete! Ready to capture screenshots
=========================================

WordPress Admin:
  URL: http://localhost:8000/wp-admin
  User: admin
  Pass: StrongPassword123!

Chat Pages:
  Standard: http://localhost:8000/?page_id=7
  Guest Mode: http://localhost:8000/?page_id=8
```

### Playwright Automation Output

```
🚀 Starting screenshot capture...

📸 Capturing: frontend-shortcode.png
✅ Saved: frontend-shortcode.png

📸 Capturing: chat-conversation-example.png
✅ Saved: chat-conversation-example.png

[... continues for all screenshots ...]

✅ Screenshot capture complete!
📁 Screenshots saved to: docs/screenshots/chat
```

## Troubleshooting

### Docker not running
```bash
docker compose up -d
docker compose ps  # Verify containers are Up
```

### WordPress not responding
```bash
docker compose logs wordpress | tail -50
# Wait 30-60 seconds for initialization
```

### API key not working
```bash
# Verify key is set
echo $OPENAI_API_KEY

# Check WordPress options
docker compose run --rm wp-cli option get wp_mcp_ai_openai_api_key
docker compose run --rm wp-cli option get wp_mcp_ai_default_provider
```

### Playwright installation fails
```bash
# Install globally
npm install -g playwright

# Or use local installation
npm install playwright
npx playwright install chromium
```

### Screenshots directory doesn't exist
```bash
mkdir -p docs/screenshots/chat
```

## Testing

### Test Setup Script Only
```bash
# Dry run - check each step
./bin/capture-chat-screenshots.sh 2>&1 | tee setup-log.txt
```

### Test Single Screenshot
```bash
# Modify playwright-capture-screenshots.js to capture only screenshot #1
# Or use browser DevTools to manually capture
```

## Optimization

After capturing, optimize file sizes:

```bash
# Using pngquant (recommended)
find docs/screenshots/chat -name "*.png" -exec pngquant --quality=65-80 --ext .png --force {} \;

# Using TinyPNG online
# Upload files to https://tinypng.com/

# Using ImageOptim (Mac)
# Drag and drop files to ImageOptim app
```

Target: Keep screenshots under 500KB each.

## Verification

Before committing, verify:

```bash
# Check all files exist
ls -lh docs/screenshots/chat/

# Count files (should be 16 total when complete)
ls docs/screenshots/chat/*.png | wc -l

# Check file sizes
du -sh docs/screenshots/chat/*

# Preview in browser
open docs/screenshots/chat/frontend-shortcode.png
```

## Cleanup

To reset environment for fresh capture:

```bash
# Stop and remove containers
docker compose down -v

# Start fresh
docker compose up -d

# Re-run setup
./bin/capture-chat-screenshots.sh
```

## Support

For detailed instructions, see:
- **Comprehensive Guide**: `docs/screenshots/CHAT_CAPTURE_GUIDE.md`
- **Progress Tracking**: `docs/screenshots/SCREENSHOT_PROGRESS.md`
- **GitHub Issues**: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

## Contributing

To improve these tools:

1. Test the scripts with different AI providers
2. Report bugs or issues via GitHub
3. Submit PRs for enhancements
4. Update documentation with edge cases
5. Add more error handling

## License

GPL v3 or later (matches plugin license)
