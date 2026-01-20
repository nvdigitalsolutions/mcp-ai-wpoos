# Screenshot Capture Quick Guide

This guide provides step-by-step instructions for capturing the remaining screenshots for NV oOS documentation.

## Prerequisites

- Docker and Docker Compose installed
- Git repository cloned
- Browser with screenshot extension (recommended: Chrome with Full Page Screen Capture)
- Or use Playwright automation (advanced)

## Quick Start with Docker

```bash
# 1. Start WordPress environment
cd /path/to/mcp-ai-wpoos
docker compose up -d

# 2. Wait 30-60 seconds for WordPress to initialize

# 3. Access WordPress
# URL: http://localhost:8000
# Username: admin  
# Password: password (or what you set during install)

# 4. Activate NV oOS plugin
# Go to Plugins → Activate "NV Digital Open Operator System Complete (oOS)"
```

## Screenshot Workflow

### 1. WordPress Admin Screenshots (Priority: HIGH)

**Settings Page** (already captured):
✅ General Settings - `admin/04-settings-general.png`

**Still Needed**:
- [ ] Navigate to each settings tab and capture:
  - AI Providers tab
  - Authentication tab  
  - Tools & Features tab
  - Security tab
  - Advanced tab

**AI Assistants**:
- [ ] Go to AI Assistants → All Assistants
  - Take screenshot: `admin/assistant-list.png`
- [ ] Click Add New
  - Capture profession grid: `admin/profession-grid.png`
- [ ] Create a test assistant from any profession
- [ ] Edit the assistant and capture:
  - General tab: `admin/assistant-editor-general.png`
  - Tools section: `admin/assistant-editor-tools.png`
  - Knowledge section: `admin/assistant-editor-knowledge.png`
  - Shortcuts section: `admin/assistant-editor-shortcuts.png`
  - Credentials section: `admin/assistant-editor-credentials.png`

**Testing Pages**:
- [ ] AI Assistants → Test Assistant
  - Screenshot: `admin/test-assistant.png`
- [ ] Professions → Test Profession
  - Screenshot: `admin/test-profession.png`
- [ ] Teams → Test Team
  - Screenshot: `admin/test-team.png`

### 2. Tools Manager (Priority: HIGH)

- [ ] Navigate to Settings → NV oOS → Tools tab (if available)
  - Or look for Tools Manager in menu
- [ ] Capture full page showing:
  - Complete tool list
  - Status labels (STA, BET, DEV, etc.)
  - Enable/disable toggles
  - Screenshot: `tools/tools-manager.png`

### 3. Dashboard Pages (Priority: MEDIUM)

**Pro Dashboard**:
- [ ] Navigate to NV oOS Pro → Overview
  - Screenshot: `dashboard/pro-dashboard-overview.png`

**Other Dashboards**:
- [ ] NV oOS → Token Manager
  - Screenshot: `dashboard/token-manager.png`
- [ ] NV oOS → Cron Manager
  - Screenshot: `dashboard/cron-manager.png`
- [ ] Navigate to Security Audit page
  - Screenshot: `dashboard/security-audit.png`

### 4. Frontend Chat (Priority: HIGH)

**Create a Test Page**:
- [ ] Pages → Add New
- [ ] Add shortcode: `[mcp_ai_chat assistant="1"]`
  - (Replace 1 with actual assistant ID)
- [ ] Publish and view page
- [ ] Screenshot the chat interface: `chat/frontend-shortcode.png`

**Test Chat Features**:
- [ ] Have a conversation with the assistant
- [ ] Screenshot active conversation: `chat/chat-conversation-example.png`
- [ ] Upload a file
- [ ] Screenshot with attachments: `chat/chat-with-attachments.png`

### 5. Integration Screenshots (Priority: LOW)

These require installing additional plugins:

**WooCommerce**:
- [ ] Install WooCommerce plugin
- [ ] Check Tools Manager - WooCommerce tools now enabled
- [ ] Screenshot: `integrations/woocommerce-integration.png`

**Elementor**:
- [ ] Install Elementor plugin
- [ ] Go to Elementor editor
- [ ] Show NV oOS widgets
- [ ] Screenshot: `integrations/elementor-integration.png`

## Using Playwright for Automation

If you want to automate screenshot capture using Playwright:

```javascript
// Example Playwright script
const { chromium } = require('playwright');

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage();
  
  // Navigate to WordPress admin
  await page.goto('http://localhost:8000/wp-admin');
  
  // Login
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  
  // Wait for dashboard
  await page.waitForLoadState('networkidle');
  
  // Navigate to settings
  await page.goto('http://localhost:8000/wp-admin/admin.php?page=wp-mcp-ai-dashboard');
  
  // Take screenshot
  await page.screenshot({ 
    path: 'docs/screenshots/admin/settings-main.png',
    fullPage: true 
  });
  
  await browser.close();
})();
```

## Screenshot Optimization

After capturing, optimize file sizes:

### Using pngquant (Linux/Mac)
```bash
# Install
brew install pngquant  # Mac
apt-get install pngquant  # Linux

# Optimize single file
pngquant --quality=65-80 --ext .png --force screenshot.png

# Optimize all PNGs in directory
find docs/screenshots -name "*.png" -exec pngquant --quality=65-80 --ext .png --force {} \;
```

### Using TinyPNG (Online)
- Visit https://tinypng.com/
- Upload PNG files
- Download optimized versions

### Using ImageOptim (Mac)
- Download from https://imageoptim.com/
- Drag and drop PNG files
- Automatically optimizes

## Screenshot Checklist Progress

Track your progress using the comprehensive checklist in:
- `docs/screenshots/README.md` - Master checklist (71 items)
- `docs/screenshots/admin/README.md` - Admin pages (25 items)
- `docs/screenshots/tools/README.md` - Tools pages (8 items)
- `docs/screenshots/chat/README.md` - Chat interface (16 items)
- `docs/screenshots/integrations/README.md` - Integrations (11 items)
- `docs/screenshots/dashboard/README.md` - Dashboards (17 items)

## Tips for Quality Screenshots

1. **Clean Environment**
   - Use fresh WordPress install
   - Clear browser cache
   - Use incognito/private mode
   - Remove browser extensions

2. **Consistent Settings**
   - Always use 100% zoom
   - Same browser (Chrome or Firefox)
   - Same screen resolution (1920x1080)
   - Same time for all screenshots (avoid mixing day/night times)

3. **Content Quality**
   - Use realistic but generic data
   - No real API keys or sensitive data
   - Create sample assistants with descriptive names
   - Use placeholder emails (admin@example.com)

4. **File Management**
   - Follow naming convention (kebab-case)
   - Save to correct directory
   - Check file size (<500KB)
   - Verify image quality before committing

## Committing Screenshots

```bash
# Add new screenshots
git add docs/screenshots/

# Commit with descriptive message
git commit -m "Add [category] screenshots: [list]"

# Push to repository
git push origin your-branch
```

## Questions?

Refer to:
- Main screenshot guide: `docs/screenshots/README.md`
- Category-specific guides in each subdirectory
- GitHub issues for questions or problems

## Current Status

As of January 9, 2026:
- ✅ 4 screenshots captured (admin pages)
- ⏳ 67 screenshots remaining
- 📁 Complete documentation structure created
- 🎯 Ready for community contributions
