# Plugin Screenshot Documentation

This directory contains screenshots of the NV oOS WordPress plugin admin interface and features. Screenshots are organized by functional area to make navigation easy.

## Directory Structure

```
docs/screenshots/
├── admin/           # WordPress admin pages (Settings, general UI)
├── tools/           # Tool Manager and tool-specific pages
├── chat/            # Chat interface, shortcodes, and frontend UI
├── integrations/    # Third-party plugin integration pages
├── dashboard/       # Analytics, monitoring, and dashboard pages
└── README.md        # This file
```

## Screenshot Naming Convention

Use descriptive, kebab-case filenames that indicate the page/feature:

- `admin/settings-main.png` - Main settings page
- `admin/assistant-editor.png` - Assistant editor interface
- `tools/tools-manager.png` - Tools management page
- `chat/frontend-shortcode.png` - Frontend chat interface
- `dashboard/pro-dashboard-overview.png` - Pro dashboard main view

## Image Requirements

- **Format**: PNG (preferred) or JPEG
- **Resolution**: Minimum 1280x720, recommended 1920x1080
- **File size**: Keep under 500KB where possible (use compression tools)
- **Browser**: Chrome or Firefox at 100% zoom
- **WordPress version**: Should show current version (6.4+)
- **Plugin version**: Should show current version (1.1.0+)

## Screenshot Checklist

### Priority 1: Core Admin Pages

These are the most important pages that users will see first:

#### Settings & Configuration
- [ ] `admin/settings-main.png` - Main NV oOS settings page (Settings → NV oOS)
  - Show API key field, default model, provider settings
  - Capture full page with all sections visible

- [ ] `admin/settings-api-configuration.png` - API configuration section
  - OpenAI API key, Gemini API key, Ollama settings
  - Model defaults and temperature settings

- [ ] `admin/settings-attachments.png` - Attachment configuration
  - MIME type allow lists for images and files
  - File size limits

- [ ] `admin/settings-logging.png` - Logging configuration
  - Enable/disable logging toggle
  - Recent errors and activity

#### Assistant Management
- [ ] `admin/assistant-list.png` - AI Assistants list page
  - Show multiple assistants with status
  - Capture columns: Title, Provider, Model, Tools, Status

- [ ] `admin/assistant-editor-general.png` - Assistant editor - General tab
  - Title, description, provider/model selection
  - System prompt editor

- [ ] `admin/assistant-editor-tools.png` - Assistant editor - Available Tools section
  - Tool selection checkboxes with categories
  - Tool status labels (STA, BET, DEV, EXP)
  - Dependency warnings for unavailable tools

- [ ] `admin/assistant-editor-knowledge.png` - Assistant editor - Base Knowledge section
  - Memory files uploader
  - Vector store ID field

- [ ] `admin/assistant-editor-shortcuts.png` - Assistant editor - Prompt Shortcuts section
  - Custom prompt shortcuts interface
  - Tool affinity settings

- [ ] `admin/assistant-editor-credentials.png` - Assistant editor - API Credentials section
  - Generate credential button
  - Credential history table
  - Revoke/delete actions

#### Profession & Team Templates
- [ ] `admin/profession-grid.png` - Add New Assistant page with profession grid
  - Visual grid of profession categories
  - 182 profession templates displayed
  - Category filters

- [ ] `admin/profession-modal.png` - Create assistant from profession modal
  - Profession selection
  - Customization options
  - AI settings configuration

- [ ] `admin/team-list.png` - Teams list page
  - Pre-built teams (Engineering, Pharmaceutical, etc.)
  - Custom teams

- [ ] `admin/team-editor.png` - Team editor interface
  - Team members selection
  - Team-wide defaults

### Priority 2: Tools & Testing

#### Tools Management
- [ ] `tools/tools-manager.png` - Tools Manager page (Settings → NV oOS → Tools tab)
  - Full tool list with status labels
  - Enable/disable toggles
  - Search and filter options
  - Tool categories visible

- [ ] `tools/tool-status-labels.png` - Close-up of tool status labels
  - Show STA, BET, DEV, EXP, BUG, DEP labels
  - Multiple tools with different statuses

- [ ] `tools/tool-dependencies.png` - Tool dependency warnings
  - Show tools requiring third-party plugins
  - Informational notices explaining unavailable tools

#### Testing Pages
- [ ] `admin/test-assistant.png` - Test Assistant page (AI Assistants → Test Assistant)
  - Chat interface for testing
  - Tool execution feedback
  - File upload interface
  - Streaming responses

- [ ] `admin/test-profession.png` - Test Profession page
  - Profession template testing interface
  - Role descriptions visible
  - Knowledge base preview

- [ ] `admin/test-team.png` - Test Team page
  - Team testing interface
  - Multiple assistant conversation

### Priority 3: Dashboard & Monitoring

#### Pro Dashboard
- [ ] `dashboard/pro-dashboard-overview.png` - Pro Dashboard main view
  - Chart.js visualizations
  - Real-time monitoring
  - Navigation tabs

- [ ] `dashboard/pro-dashboard-analytics.png` - Analytics tab
  - Usage statistics
  - Model performance metrics

- [ ] `dashboard/pro-dashboard-chart-settings.png` - Chart settings page
  - Chart configuration options
  - Data source settings

#### Admin Dashboard Pages
- [ ] `dashboard/analytics-dashboard.png` - Analytics Dashboard page
  - Usage tracking summaries
  - Provider/model billing data

- [ ] `dashboard/token-manager.png` - Token Manager page
  - Active tokens list
  - Token generation interface
  - Security settings

- [ ] `dashboard/cron-manager.png` - Cron Manager page
  - Scheduled tasks list
  - Job status and next run times
  - Create/delete cron jobs

- [ ] `dashboard/dlq-manager.png` - Dead Letter Queue Manager
  - Failed operations queue
  - Retry mechanisms

#### Security & Compliance
- [ ] `dashboard/security-audit.png` - Security Audit Admin page
  - ISO 27001/SOC 2/HIPAA compliance dashboard
  - Control implementation status
  - Audit logs

- [ ] `dashboard/security-training.png` - Security Training Admin page
  - Training materials
  - Compliance procedures

### Priority 4: Integration Pages

#### JetEngine Integration
- [ ] `integrations/jetengine-integration.png` - JetEngine Integration settings
  - CCT synchronization status
  - JetEngine-specific tools
  - Chat transcript CCT configuration

- [ ] `integrations/jetformbuilder-integration.png` - JetFormBuilder settings
  - Form access configuration
  - Submission handling

#### WooCommerce Integration
- [ ] `integrations/woocommerce-integration.png` - WooCommerce Integration page
  - Product/order tool settings
  - E-commerce automation options

#### Elementor Integration
- [ ] `integrations/elementor-integration.png` - Elementor Integration page
  - Widget availability
  - Template management settings

### Priority 5: Frontend & Chat

#### Chat Interface
- [ ] `chat/frontend-shortcode.png` - Frontend chat interface via shortcode
  - `[mcp_ai_chat]` shortcode output
  - Message input field
  - Tool shortcuts buttons
  - Chat history

- [ ] `chat/frontend-guest-mode.png` - Guest mode chat interface
  - `[mcp_ai_chat allow_guests="true"]` output
  - Anonymous user experience

- [ ] `chat/chat-with-attachments.png` - Chat with file attachments
  - File upload interface
  - Attached files display
  - MIME type restrictions visible

- [ ] `chat/chat-tool-execution.png` - Tool execution feedback in chat
  - Tool call progress
  - Tool results display
  - Streaming tool output

#### Elementor Widgets
- [ ] `chat/elementor-chat-widget.png` - Elementor chat widget in editor
  - Widget settings panel
  - Live preview

- [ ] `chat/elementor-dashboard-widgets.png` - Elementor dashboard widgets
  - Activity feed widget
  - Usage timer widget
  - Tool matrix widget

### Priority 6: Specialized Features

#### Datasets & Data Management
- [ ] `admin/datasets-admin.png` - Datasets Admin page
  - Dataset management interface
  - Import/export options

- [ ] `admin/asset-inventory.png` - Asset Inventory Admin page
  - Media library integration
  - Asset tracking

#### Advanced Settings
- [ ] `admin/crawl4ai-monitor.png` - Crawl4AI Monitor page
  - Job status tracking
  - Queue management
  - Success/failure metrics

- [ ] `admin/gmail-crawl.png` - Gmail Crawl Admin page
  - Gmail integration settings
  - Search configuration

- [ ] `admin/auth0-setup.png` - Auth0 Setup page
  - Enterprise authentication configuration
  - OAuth settings

#### Diagnostic Pages
- [ ] `admin/dashboard-diagnostic.png` - Dashboard Diagnostic page
  - System health checks
  - Configuration validation

- [ ] `admin/mcp-server-diagnostic.png` - MCP Server Diagnostic page
  - MCP protocol status
  - Server connectivity tests

- [ ] `admin/provider-diagnostics.png` - Provider Diagnostics page
  - OpenAI/Gemini/Ollama connection tests
  - API health checks

- [ ] `admin/rest-context-diagnostic.png` - REST Context Diagnostic page
  - REST API endpoint status
  - Authentication testing

## How to Capture Screenshots

### Prerequisites
1. Install WordPress 6.4+ with PHP 8.1+
2. Install and activate NV oOS plugin (version 1.1.0+)
3. Configure at least one AI assistant with tools
4. Optional: Install WooCommerce, JetEngine, Elementor for integration screenshots

### Using Docker (Recommended)

```bash
# Start WordPress environment
cd /path/to/mcp-ai-wpoos
docker compose up -d

# Wait for WordPress to be ready (30-60 seconds)
# Access at http://localhost:8000

# Complete WordPress installation
# Login: admin / admin (or configure your own)

# Activate the plugin from WordPress admin
```

### Browser Setup
1. Use Chrome or Firefox at 1920x1080 resolution
2. Set browser zoom to 100%
3. Clear browser cache and cookies for clean screenshots
4. Use incognito/private mode to avoid interference from browser extensions

### Taking Screenshots

#### Full-Page Screenshots (Preferred)
Use browser extensions for full-page capture:
- **Chrome**: Awesome Screenshot, Full Page Screen Capture
- **Firefox**: Fireshot, Nimbus Screenshot

#### Manual Cropping
1. Take screenshot of relevant area (avoid capturing empty space)
2. Include WordPress admin header/sidebar for context
3. Crop to remove personal information (usernames, emails, API keys)
4. Ensure text is readable at display size

#### Annotations
Add annotations for clarity where needed:
- Highlight important buttons/fields with red rectangles
- Add numbered callouts for multi-step processes
- Use arrows to show relationships between elements

### Image Optimization

After capturing screenshots, optimize file size:

```bash
# Using ImageOptim (Mac)
imageoptim screenshot.png

# Using pngquant (Linux/Mac)
pngquant --quality=65-80 screenshot.png

# Using TinyPNG online
# https://tinypng.com/
```

## Screenshot Review Checklist

Before committing screenshots, verify:

- [ ] No sensitive data visible (API keys, personal emails, real domains)
- [ ] Plugin version shown matches current release
- [ ] Image resolution is at least 1280x720
- [ ] File size is under 500KB
- [ ] Filename follows naming convention
- [ ] Screenshot is placed in correct directory
- [ ] README.md updated with screenshot reference

## Adding Screenshots to Documentation

Reference screenshots in markdown files using relative paths:

```markdown
![Main Settings Page](../screenshots/admin/settings-main.png)

*Figure 1: Main NV oOS settings page showing API configuration and model defaults*
```

## Updating Screenshots

Screenshots should be updated when:
- Plugin version changes significantly (major/minor releases)
- UI/UX changes are introduced
- New features are added
- Existing features are redesigned

Keep a changelog of screenshot updates:
- Date updated
- Reason for update (feature change, bug fix, redesign)
- Version number

## Copyright & Attribution

All screenshots are:
- Copyright © NV Digital Solutions
- Licensed under GPLv3 (matching plugin license)
- May be used in plugin documentation, tutorials, and marketing materials

## Contributing Screenshots

To contribute screenshots:

1. Follow the checklist and requirements above
2. Name files according to convention
3. Optimize images for web
4. Submit via pull request
5. Include description of what's shown

## Questions?

For questions about screenshot requirements or contributions:
- GitHub Issues: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
- Documentation: https://github.com/nvdigitalsolutions/mcp-ai-wpoos#documentation
