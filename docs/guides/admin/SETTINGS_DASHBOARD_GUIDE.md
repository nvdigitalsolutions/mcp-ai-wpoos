# Settings Dashboard User Guide

**Last Updated:** December 29, 2025  
**Plugin Version:** 1.1.0  
**Difficulty:** Beginner  
**Time Required:** 15-20 minutes

---

## Overview

The WP oOS Settings Dashboard is a modern, tabbed interface for managing all plugin configurations. This guide walks you through each tab and shows you how to configure the 27 new settings exposed in recent updates.

### What You'll Learn

- Navigating the tabbed settings interface
- Configuring AI providers (OpenAI, Gemini, Ollama, etc.)
- Setting up authentication (Auth0, API credentials)
- Managing tools and features
- Configuring orchestration and mesh networking
- Using the Token Manager
- Setting up security features
- Advanced configuration options

---

## Accessing the Settings Dashboard

### Via WordPress Admin

1. Log in to WordPress admin panel
2. Navigate to **Settings → WP oOS**
3. The Settings Dashboard loads with the **Overview** tab active

### Direct URL

```
https://yoursite.com/wp-admin/admin.php?page=wp-mcp-ai-dashboard
```

### Required Permissions

You must have the `manage_options` capability (typically Administrator role).

---

## Settings Dashboard Layout

```
┌─────────────────────────────────────────────────────┐
│  WP oOS Settings Dashboard              [Save All]  │
├──────┬──────────────────────────────────────────────┤
│ Tabs │ Tab Content Area                             │
│  ↓   │                                              │
│ ⬜ Overview       ┌─────────────────────────────┐  │
│ ⬜ General        │                             │  │
│ ⬜ AI Providers   │  Active tab content         │  │
│ ⬜ Authentication │  displays here              │  │
│ ⬜ Tools          │                             │  │
│ ⬜ Orchestration  │                             │  │
│ ⬜ Token Manager  │                             │  │
│ ⬜ Security       └─────────────────────────────┘  │
│ ⬜ Advanced                                          │
└──────┴──────────────────────────────────────────────┘
```

### Interface Features

- **Sticky Tabs:** Tab bar remains visible when scrolling
- **Auto-Save:** Settings saved immediately on change
- **Validation:** Real-time validation prevents invalid inputs
- **Test Connections:** Test buttons for API connections
- **Reset Options:** Reset individual settings to defaults

---

## Tab 1: Overview

The Overview tab provides a high-level dashboard of your WP oOS installation.

### Quick Stats

```
┌─────────────────────────────────────┐
│ Active Assistants: 12               │
│ Total Tools Available: 159          │
│ Enabled Tools: 142                  │
│ Total Users: 48                     │
│ Tokens Used Today: 1,234,567        │
└─────────────────────────────────────┘
```

### System Status

**Green Checkmarks = Configured:**
- ✅ OpenAI API Key configured
- ✅ Default model set (gpt-4o-mini)
- ✅ Request timeout configured (30s)
- ❌ Gemini API not configured
- ❌ Auth0 not configured

### Quick Actions

- **Test All Connections:** Verify all API keys work
- **Regenerate Security Keys:** Rotate master keys
- **Clear Cache:** Clear object cache and transients
- **Export Settings:** Download JSON backup of all settings
- **Import Settings:** Restore settings from backup

### Recent Activity (if logging enabled)

Shows last 10 plugin events:
- API calls made
- Tool executions
- Errors encountered
- Configuration changes

---

## Tab 2: General Settings

Core plugin configuration and global defaults.

### Essential Settings

#### 1. **OpenAI API Key** ⭐ Required
- **Field:** Text input (password protected)
- **Purpose:** Authenticate with OpenAI API
- **Where to Get:** https://platform.openai.com/api-keys
- **Validation:** Tests key on blur
- **Example:** `sk-proj-abc123...`

#### 2. **Default AI Provider**
- **Field:** Dropdown
- **Options:**
  - OpenAI (default)
  - Google Gemini
  - Ollama (local)
  - LM Studio (local)
  - Anthropic Claude
  - Hugging Face
- **Purpose:** Default provider for new assistants
- **Note:** Individual assistants can override

#### 3. **Default Model**
- **Field:** Dropdown (dynamic based on provider)
- **OpenAI Options:**
  - `gpt-4o` - Latest flagship model
  - `gpt-4o-mini` - Cost-effective (default)
  - `gpt-4-turbo` - Legacy flagship
  - `gpt-3.5-turbo` - Fast and cheap
- **Purpose:** Model used when assistant doesn't specify
- **Tip:** Use gpt-4o-mini for development, gpt-4o for production

#### 4. **Request Timeout**
- **Field:** Number input (seconds)
- **Range:** 5 - 300 seconds
- **Default:** 30 seconds
- **Purpose:** Maximum time to wait for API responses
- **Tip:** Increase to 60s+ for long-running tools like `run_crawl4ai_job`

#### 5. **Enable Logging** 🆕
- **Field:** Toggle switch
- **Default:** OFF
- **Purpose:** Log API calls, errors, and tool executions
- **Storage:** Logs stored in `wp_options` (last 100 entries)
- **View Logs:** Overview tab → Recent Activity
- **Warning:** Enable only for debugging (increases database writes)

---

## Tab 3: AI Providers

Configure API keys and settings for each AI provider.

### OpenAI

**API Key** - Already covered in General tab

**Organization ID** (Optional)
- **Field:** Text input
- **Purpose:** Link usage to specific OpenAI organization
- **Example:** `org-abc123...`
- **When to Use:** If you have multiple OpenAI organizations

**Text-to-Speech Settings** 🆕
- **TTS Model:** Dropdown (tts-1, tts-1-hd)
- **Voice:** Dropdown (alloy, echo, fable, onyx, nova, shimmer)
- **Format:** Dropdown (mp3, opus, aac, flac, wav, pcm)
- **Purpose:** Configure OpenAI TTS tool behavior
- **Default:** tts-1, alloy voice, mp3 format

**High Token Fallback** 🆕
- **Field:** Toggle + Model Selector
- **Purpose:** Auto-switch to larger context model when limit exceeded
- **Default:** gpt-4o-mini → gemini-2.0-flash-exp
- **Example:** User sends 150k token request → Falls back to Gemini
- **Note:** Requires Gemini API key to be configured

### Google Gemini

**API Key**
- **Field:** Text input (password protected)
- **Where to Get:** https://aistudio.google.com/app/apikey
- **Validation:** Test button verifies key
- **Example:** `AIzaSy...`

**Default Gemini Model**
- **Field:** Dropdown
- **Options:**
  - `gemini-2.0-flash-exp` - Experimental, 1M context (default)
  - `gemini-1.5-pro` - Production stable
  - `gemini-1.5-flash` - Fast and economical
- **Purpose:** Model for Gemini assistants

**Safety Settings**
- **Field:** Four dropdowns (one per harm category)
- **Categories:**
  - Hate Speech
  - Dangerous Content
  - Sexually Explicit
  - Harassment
- **Threshold Options:**
  - Block None
  - Block Low and Above
  - Block Medium and Above (default)
  - Block High Only
- **Purpose:** Content filtering for Gemini responses

### Ollama (Local AI)

**Ollama URL**
- **Field:** URL input
- **Default:** `http://localhost:11434`
- **Purpose:** Local Ollama server endpoint
- **Validation:** Test connection button
- **Tip:** Use Ollama for free, local inference

**Fetch Models**
- **Button:** Fetches available models from Ollama server
- **Populates:** Model dropdown with installed models
- **Example Models:** llama3:8b, mistral:7b, codellama:13b

### LM Studio (Local AI)

**LM Studio URL**
- **Field:** URL input
- **Default:** `http://localhost:1234`
- **Purpose:** Local LM Studio server endpoint
- **Test Connection:** Verifies server is running

**Fetch Models**
- **Button:** Gets models from LM Studio
- **Note:** Requires LM Studio server running

### Anthropic Claude (Pro Feature)

**API Key**
- **Field:** Text input (password protected)
- **Where to Get:** https://console.anthropic.com/
- **Models:** claude-3-opus, claude-3-sonnet, claude-3-haiku

### Hugging Face (Pro Feature)

**API Token**
- **Field:** Text input (password protected)
- **Where to Get:** https://huggingface.co/settings/tokens
- **Purpose:** Access Hugging Face Inference API

---

## Tab 4: Authentication

Configure authentication methods for API access and remote MCP clients.

### Auth0 Configuration (Optional)

**Enable Auth0**
- **Field:** Toggle switch
- **Default:** OFF
- **Purpose:** Enterprise SSO for ChatGPT connector

**Auth0 Domain**
- **Field:** URL input
- **Example:** `your-tenant.auth0.com`
- **Purpose:** Your Auth0 tenant domain

**Auth0 Client ID**
- **Field:** Text input
- **Example:** `abc123...`

**Auth0 Client Secret**
- **Field:** Text input (password protected)
- **Purpose:** Authenticate Auth0 API calls

**Auth0 Audience**
- **Field:** Text input
- **Example:** `https://your-api.com`
- **Purpose:** API identifier in Auth0

### Assistant API Credentials

**Enable Credential System**
- **Field:** Toggle switch
- **Default:** ON
- **Purpose:** Generate bearer tokens for remote MCP clients

**Credential Lifetime**
- **Field:** Number input (days)
- **Range:** 1 - 365 days
- **Default:** 90 days
- **Purpose:** How long tokens remain valid

**Regenerate Master Key**
- **Button:** Creates new root security key
- **Warning:** Invalidates all existing credentials
- **Use Case:** Security incident response

### Guest Access

**Allow Guest Tokens**
- **Field:** Toggle switch
- **Default:** OFF
- **Purpose:** Enable unauthenticated chat access
- **Security:** Limits tools available to guests

**Guest Token Expiry**
- **Field:** Number input (hours)
- **Default:** 24 hours
- **Purpose:** How long guest tokens remain valid

---

## Tab 5: Tools & Features

Enable/disable tools and configure tool-specific settings.

### Tool Filter Bar

**Search:** Type to filter tool list (e.g., "image" shows all image tools)

**Filter by Category:**
- All Tools (159)
- Content Management (25)
- AI Generation (18)
- Research & Data (15)
- Commerce (8)
- Communications (12)
- Integrations (31)
- Pro Tools (64)

**Filter by Status:**
- Enabled (142)
- Disabled (17)

### Tool Toggle Grid

Each tool shows:
- **Tool Name:** Human-readable label
- **Slug:** Technical identifier
- **Category:** Tool grouping
- **Capability Required:** WordPress capability (e.g., `edit_posts`)
- **Toggle:** Enable/disable switch
- **Settings:** Gear icon for tool-specific config

### Tool-Specific Settings 🆕

#### Web Search Provider

- **Field:** Dropdown
- **Options:** DuckDuckGo (default), Brave Search
- **Purpose:** Which service powers `web_search` tool
- **Brave API Key:** Text field (required if Brave selected)

#### Group Email Controls 🆕

**Allow Group Emails**
- **Field:** Toggle
- **Default:** ON
- **Purpose:** Enable `send_group_email` tool
- **Security:** Prevents spam if disabled

**Max Recipients**
- **Field:** Number input
- **Default:** 50
- **Purpose:** Limit emails sent per request
- **Anti-Spam:** Prevents mass mailing abuse

#### Varnish Cache Toggle 🆕

**Purge Varnish on Updates**
- **Field:** Toggle
- **Default:** OFF
- **Purpose:** Auto-purge Varnish when content changes
- **Requirement:** Varnish configured on server

### Bulk Tool Actions

**Select Multiple Tools:**
1. Check boxes next to tool names
2. Choose bulk action:
   - Enable Selected
   - Disable Selected
   - Reset to Defaults
3. Click **Apply**

---

## Tab 6: Orchestration

Configure AI model orchestration, tool recommendations, and queue profiles.

### Model Orchestration

**Enable Smart Model Selection**
- **Field:** Toggle
- **Default:** ON
- **Purpose:** Auto-select best model based on task complexity
- **Example:** Simple query → gpt-4o-mini, Complex reasoning → gpt-4o

**Orchestration Presets:**
- **Balanced:** Mix of performance and cost (default)
- **Performance:** Always use best models
- **Cost-Optimized:** Prefer cheaper models
- **Custom:** Define your own rules

**Apply Preset Button:** Quick-apply preset rules

### Tool Recommendations

**Enable AI Tool Suggestions**
- **Field:** Toggle
- **Default:** ON
- **Purpose:** Assistant suggests tools based on user query
- **Example:** User asks "Search for posts" → Suggests `search_content` tool

**Recommendation Threshold:**
- **Field:** Slider (0-100%)
- **Default:** 70%
- **Purpose:** Minimum confidence to show suggestion
- **Higher = Fewer, more relevant suggestions**

### Queue Profiles

**Enable Queue Management**
- **Field:** Toggle
- **Default:** OFF
- **Purpose:** Queue long-running tools for background processing
- **Use Case:** Batch jobs, crawl4ai, video generation

**Max Concurrent Jobs:**
- **Field:** Number input
- **Default:** 3
- **Purpose:** How many queued jobs run simultaneously

**Queue Profiles:**
- **Fast Lane:** Short timeout (5 min), high priority
- **Standard:** Medium timeout (30 min)
- **Slow Lane:** Long timeout (2 hours), low priority

---

## Tab 7: Token Manager

Covered in detail in the [Token Management User Guide](TOKEN_MANAGEMENT_GUIDE.md).

### Quick Summary

- **Overview:** Usage charts and statistics
- **Per User:** Manage individual user limits
- **Per Site:** Multisite usage aggregation
- **Tool Limits:** Set tool-specific multipliers

---

## Tab 8: Security

Security hardening, monitoring, and emergency controls.

### Root Security Key

**Enable Root Security Key**
- **Field:** Toggle
- **Default:** OFF
- **Purpose:** Emergency shutdown mechanism
- **Requirement:** Define `WP_MCP_AI_ROOT_SECURITY_KEY` in wp-config.php
- **Use Case:** Security incident requires plugin deactivation

**Key Status:**
- 🟢 Configured and Valid
- 🟡 Not Configured
- 🔴 Invalid Key

### Security Monitor

**Enable Security Monitoring**
- **Field:** Toggle
- **Default:** ON
- **Purpose:** Track failed auth attempts, suspicious activity
- **View Events:** Security tab → Recent Security Events

**Alert Threshold:**
- **Field:** Number input
- **Default:** 5 failed attempts
- **Purpose:** Send alert after X failed attempts
- **Action:** Emails site admin

**Auto-Block IP:**
- **Field:** Toggle
- **Default:** OFF
- **Purpose:** Temporarily block IP after threshold
- **Duration:** 1 hour default

### Rate Limiting 🆕

**Enable Rate Limiting**
- **Field:** Toggle
- **Default:** OFF
- **Purpose:** Limit API requests per user per time window
- **Use Case:** Prevent API abuse

**Requests Per Minute:**
- **Field:** Number input
- **Default:** 60
- **Purpose:** Max API calls per user per minute
- **Note:** Separate from token limits

### Content Filtering

**Enable OpenAI Moderation**
- **Field:** Toggle
- **Default:** OFF
- **Purpose:** Auto-moderate user inputs before sending to AI
- **API:** Uses OpenAI Moderation API (free)
- **Action:** Blocks requests flagged by moderation

**Block Categories:**
- Hate Speech
- Sexual Content
- Violence
- Self-Harm

---

## Tab 9: Advanced

Advanced settings, federation, mesh networking, and experimental features.

### Federation & Mesh Networking 🆕

**Enable Federation**
- **Field:** Toggle
- **Default:** OFF
- **Purpose:** Join decentralized AI peer network
- **Publishes:** `/.well-known/ai-peer` endpoint

**Federation Directory URL:**
- **Field:** URL input
- **Default:** `https://directory.nvdigitalsolutions.com`
- **Purpose:** Central directory for peer discovery
- **Alternative:** Run your own directory

**Regional Routing 🆕**
- **Regions:** North America, Europe, Asia-Pacific, South America, Africa, Oceania
- **Purpose:** Route requests to geographically close peers
- **Reduces:** Latency for mesh compute

**Data Classification Tags 🆕**
- **Tags:** public, internal, confidential, restricted
- **Purpose:** Control which peers can process data
- **Example:** "confidential" data only routes to trusted peers

**Rate Limiting (QPS) 🆕**
- **Field:** Number input
- **Default:** 10 queries per second
- **Purpose:** Limit incoming federation requests
- **Protection:** Prevents overload from mesh network

**Burst Capacity 🆕**
- **Field:** Number input
- **Default:** 50 requests
- **Purpose:** Allow brief traffic spikes above QPS
- **Note:** Bucket refills at QPS rate

**Mesh Peer Sites 🆕**
- **Field:** Textarea (one URL per line)
- **Purpose:** Manually define trusted peer sites
- **Format:** `https://peer1.com|api_key_123`
- **Use Case:** Private mesh network

**Inbound API Key 🆕**
- **Field:** Auto-generated, read-only
- **Purpose:** Give to peers who want to connect to your site
- **Regenerate:** Button to create new key (invalidates old)

### Cloudways Integration 🆕

**Cloudways Application ID:**
- **Field:** Text input
- **Purpose:** Link to Cloudways app for SSH operations
- **Example:** `123456`

**Cloudways Server ID:**
- **Field:** Text input
- **Purpose:** Cloudways server identifier
- **Example:** `789012`

**Note:** Enables Pro tools like `site_creator` and `manage_cloudways_app`

### Google Analytics 4 🆕

**Service Account JSON:**
- **Field:** Textarea
- **Purpose:** Paste entire GA4 service account JSON
- **Format:** 
  ```json
  {
    "type": "service_account",
    "project_id": "your-project",
    ...
  }
  ```
- **Enables:** `get_google_analytics_report` tool

### Media Settings 🆕

**Allowed File MIME Types:**
- **Field:** Textarea (one per line)
- **Purpose:** Whitelist file types for uploads
- **Default:** 
  ```
  image/jpeg
  image/png
  image/gif
  application/pdf
  text/plain
  ```
- **Security:** Prevents malicious file uploads

**Allowed Image MIME Types:**
- **Field:** Textarea
- **Purpose:** Separate whitelist for image tools
- **Default:** image/jpeg, image/png, image/gif, image/webp

### Cleanup on Uninstall

**Delete All Data on Uninstall:**
- **Field:** Toggle
- **Default:** OFF
- **Warning:** ⚠️ Deletes assistants, settings, usage data
- **Use Case:** Complete removal of plugin

---

## Saving Settings

### Auto-Save

Most settings auto-save on change:
- Toggle switches save immediately
- Dropdowns save on selection
- Text fields save on blur (clicking outside)

### Manual Save

Some sections require clicking **Save Changes** button:
- Federation settings
- Mesh peer configuration
- Service account JSON

### Save All Button

Click **Save All** (top right) to force-save all tabs.

### Confirmation Messages

- ✅ Green checkmark = Saved successfully
- ❌ Red X = Validation error
- ⚠️ Yellow warning = Saved with warnings

---

## Common Scenarios

### Scenario 1: Setting Up for First Time

**Goal:** Configure essentials to start using WP oOS

**Steps:**
1. **General Tab:**
   - Add OpenAI API key
   - Leave default model (gpt-4o-mini)
   - Set timeout to 30s
2. **Tools Tab:**
   - Review enabled tools
   - Disable expensive tools (image, video) for Free tier
3. **Token Manager Tab:**
   - Check default tier assignments
   - Enable alerts at 70%
4. **Test:**
   - Create first assistant
   - Send test message via chat

**Time:** 5 minutes

### Scenario 2: Adding Gemini as Secondary Provider

**Goal:** Use Gemini for high-token requests

**Steps:**
1. **AI Providers Tab:**
   - Add Gemini API key
   - Select default model: gemini-2.0-flash-exp
   - Configure safety settings (default OK)
2. **General Tab:**
   - Enable "High Token Fallback"
   - Set fallback to Gemini
3. **Test:**
   - Send 100k+ token request
   - Should auto-switch to Gemini

**Time:** 3 minutes

### Scenario 3: Enabling Federation for Mesh Network

**Goal:** Join decentralized AI network to share compute

**Steps:**
1. **Advanced Tab → Federation:**
   - Toggle "Enable Federation" ON
   - Enter directory URL (or use default)
   - Select regions: North America, Europe
   - Set data tags: public, internal
   - Set QPS: 10
   - Set burst: 50
2. **Copy Inbound API Key:**
   - Share with trusted peers
3. **Add Peer Sites:**
   - Paste peer URLs + keys
4. **Test:**
   - Verify `/.well-known/ai-peer` endpoint
   - Check federation health in Overview

**Time:** 10 minutes

### Scenario 4: Configuring Security for Production

**Goal:** Harden security for live site

**Steps:**
1. **Security Tab:**
   - Enable Security Monitoring
   - Set alert threshold: 5 attempts
   - Enable Auto-Block IP
   - Enable Rate Limiting (60 req/min)
   - Enable OpenAI Moderation
2. **Advanced Tab:**
   - Set allowed MIME types (strict list)
   - Enable root security key (add to wp-config.php)
3. **Token Manager Tab:**
   - Set aggressive tier limits
   - Enable forecasting alerts
4. **Tools Tab:**
   - Disable high-risk tools for Free tier

**Time:** 15 minutes

---

## Keyboard Shortcuts

- **Ctrl+S:** Save current tab
- **Ctrl+Shift+S:** Save all tabs
- **Ctrl+F:** Focus search in tool filter
- **Esc:** Close modal dialogs
- **Tab:** Navigate between fields
- **Ctrl+Z:** Undo last change (if not yet saved)

---

## Troubleshooting

### Settings Not Saving

**Check:**
1. Browser console for JavaScript errors
2. User has `manage_options` capability
3. No conflicting plugins (try disabling others)
4. Clear browser cache + hard refresh (Ctrl+Shift+R)

**Fix:**
- Check PHP error log for save failures
- Verify database connection OK
- Increase PHP `max_input_vars` (default 1000, need 5000+)

### Tab Content Not Loading

**Cause:** JavaScript error or AJAX failure

**Fix:**
1. Open browser console (F12)
2. Look for red errors
3. Disable browser extensions temporarily
4. Try different browser
5. Check `admin-ajax.php` responding

### Test Connection Fails

**OpenAI:**
- Verify API key format (starts with `sk-`)
- Check internet connectivity
- Verify not behind firewall blocking OpenAI

**Gemini:**
- Verify API key format (starts with `AIza`)
- Check quota not exceeded at https://aistudio.google.com/

**Ollama/LM Studio:**
- Verify server running (`http://localhost:11434` or `http://localhost:1234`)
- Check server logs for errors
- Try `curl http://localhost:11434/api/tags` to test

### Import Settings Failed

**Requirements:**
- JSON file from WP oOS export
- Same or newer plugin version
- Valid JSON format

**Fix:**
- Validate JSON at jsonlint.com
- Check file not corrupted
- Try manual import via database

---

## Best Practices

### 1. Start Simple
- Configure only essential settings initially
- General + AI Providers + Tools
- Add advanced features later

### 2. Test Each Provider
- Use "Test Connection" buttons
- Verify API keys work before relying on them
- Keep backup keys in secure location

### 3. Regular Backups
- Export settings monthly
- Store JSON file securely
- Test import process periodically

### 4. Monitor Security Tab
- Check recent events weekly
- Investigate failed auth attempts
- Update security settings as needed

### 5. Document Custom Settings
- Keep notes on why you changed defaults
- Document federation peer arrangements
- Share configuration with team

### 6. Update Gradually
- Test new settings in staging first
- Roll out to production after validation
- Keep old settings as backup

### 7. Use Presets
- Orchestration presets save time
- Test presets thoroughly
- Create custom preset for your workflow

---

## Related Documentation

- [Token Management User Guide](TOKEN_MANAGEMENT_GUIDE.md) - Deep dive on token limits
- [Quick Reference Guide](../../QUICK_REFERENCE.md) - Fast access to common tasks
- [Architecture Guide](../../architecture/core/COPILOT_ARCHITECTURE_GUIDE.md) - Technical implementation
- [Settings Registry](../../architecture/core/ARCHITECTURE_QUICK_REFERENCE.md#settings-registry) - Developer reference
- [Federation Guide](../federation/federation-discovery.md) - Mesh networking details
- [Security Hardening](../security/SECURITY_HARDENING.md) - Security best practices

---

## Support

**Need Help?**
- Check the [Troubleshooting](#troubleshooting) section above
- Review [Common Scenarios](#common-scenarios)
- See [Quick Reference Guide](../../QUICK_REFERENCE.md)
- Open an issue: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

**Feature Requests:**
- Suggest improvements via GitHub Issues
- Tag with `enhancement` and `settings`

---

**Last Updated:** December 29, 2025  
**Version:** 1.1.0  
**Maintainer:** NV Digital Solutions
