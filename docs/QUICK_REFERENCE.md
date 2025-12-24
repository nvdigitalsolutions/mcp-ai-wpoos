# WP oOS Quick Reference Guide

**Version:** 1.1.0  
**Last Updated:** December 24, 2025

This quick reference provides fast access to the most common tasks and commands for Open Operator System.

## 🆕 Recent Updates (December 2025)

- **Symfony Process Integration** - Pro addon tools now use Symfony Process for safer external command execution
- **27 New Settings Exposed** - Federation, mesh networking, TTS, MIME controls, and more accessible in admin UI
- **Test Team Modal** - New team testing feature for deploying multi-assistant teams
- **Pro Tool Reorganization** - 6 exec-based tools moved to Pro addon (breaking change for base users)

---

## 🚀 Quick Start

### Installation (30 seconds)
```bash
# 1. Upload plugin
# 2. Activate from WordPress admin
# 3. Go to Settings → WP oOS
# 4. Add OpenAI API key
# 5. Create your first assistant
```

### Developer Installation (GitHub Clone)

**For Cloudways (Recommended):**
```bash
# SSH into your server and clone directly into plugins directory
cd /home/master/applications/YOURAPP/public_html/wp-content/plugins/
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
npm install && composer install --no-dev --optimize-autoloader
```

**For Local/VPS:**
```bash
# Option 1: Clone directly into WordPress (recommended)
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
npm install && composer install --no-dev --optimize-autoloader

# Option 2: Clone, install, then copy
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
npm install && composer install --no-dev --optimize-autoloader
cp -r . /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/
```

**⚠️ Important:** 
- Always run `npm install` and `composer install` BEFORE moving/copying files
- On Cloudways: Clone directly into the plugins directory to avoid errors
- If you get `ENOENT: uv_cwd` or `getcwd() failed` errors: EXIT your shell and start a NEW terminal session, then navigate to the plugin directory and run the install commands
- Running npm/composer after moving files OR from an orphaned directory will fail

### First Chat (2 minutes)
```php
// Add to any page/post
[mcp_ai_chat assistant="123"]

// With guest access
[mcp_ai_chat assistant="123" allow_guests="true"]
```

---

## 🔑 Essential Settings

### Required Configuration
| Setting | Location | Default | Notes |
|---------|----------|---------|-------|
| OpenAI API Key | Settings → WP oOS | None | **Required** |
| Default Model | Settings → WP oOS | gpt-4o-mini | Cost-effective |
| Request Timeout | Settings → WP oOS | 30s | Min 5s |
| Enable Logging | Settings → WP oOS | Off | Use for debugging |

### Optional Integration Keys
- **Gemini API Key** - For Gemini provider support
- **Crawl4AI URL** - For web crawling capabilities
- **Mailjet API** - For email automation
- **QuickBooks API** - For financial reporting

---

## 👥 Common User Tasks

### Creating an Assistant
```
1. Navigate to AI Assistants → Add New
2. Enter title and description
3. Select available tools
4. Configure model defaults (optional)
5. Add base knowledge files (optional)
6. Publish assistant
```

### Using Chat Interface
```
1. Add [mcp_ai_chat assistant="ID"] to page
2. Type message in chat box
3. Press Enter or click Send
4. View assistant response with tool feedback
5. Continue conversation naturally
```

### Uploading Files to Chat
```
1. Click attachment icon in chat
2. Select file (images, PDFs, documents)
3. Add message describing what to do with file
4. Send message
5. Assistant processes file and responds
```

### Creating Prompt Shortcuts
```
1. Edit assistant
2. Find "Prompt Shortcuts" meta box
3. Click "Add Shortcut"
4. Enter label and prompt
5. Optionally select target tool
6. Save assistant
```

---

## 👨‍💻 Developer Commands

### WP-CLI Commands
```bash
# Check plugin status
wp mcp-ai status

# Test remote connection
wp mcp-ai remote https://example.com/wp-json/mcp-ai/v1 --token=YOUR_TOKEN

# List optional plugins
wp mcp-ai plugins list

# Activate plugin
wp mcp-ai plugins activate woocommerce
```

### Composer Commands
```bash
# Install dependencies
composer install

# Run linting
composer run lint

# Auto-fix code standards
composer run format

# Run tests
composer run test

# Check PHP compatibility
composer run lint:compat
```

### npm Commands
```bash
# Install JavaScript dependencies
npm install

# Lint JavaScript
npm run lint:js

# Auto-fix JavaScript
npm run lint:js:fix
```

---

## 🛠 Tool Categories & Common Tools

### Content Management
```
- search_content - Search posts/pages
- save_post - Create/update content
- get_recent_posts - List latest posts
- search_attachments - Find media files
```

### AI Generation
```
- generate_openai_image - Create images
- generate_openai_speech - Text to speech
- transcribe_openai_audio - Audio to text
- submit_document_prompt - Process documents
```

### Research
```
- web_search - Search DuckDuckGo/Brave
- run_crawl4ai_job - Crawl websites
- get_open_meteo_forecast - Weather data
- reliefweb_reports - Humanitarian alerts
```

### Operations
```
- get_site_summary - Site overview
- get_site_health - Health checks
- get_system_logs - View logs
- check_wp_cli - WP-CLI status
- count_tokens - Estimate token counts
```

---

## 🔐 Security & Authentication

### Generating Assistant Credentials
```
1. Edit assistant
2. Find "API Credentials" meta box
3. Click "Generate Credential"
4. Copy token (shown once!)
5. Use in Authorization header: Bearer cred_xxxxx.SECRET
```

### Guest Access Configuration
```php
// Shortcode with guest access
[mcp_ai_chat assistant="123" allow_guests="true"]

// Filter chat capability
add_filter( 'wp_mcp_ai_chat_capability', function( $cap ) {
    return 'public'; // Allow all visitors
} );
```

### Auth0 Setup (ChatGPT)
```
1. Settings → WP oOS
2. Add Auth0 Domain
3. Add Auth0 Audience
4. Add Auth0 Scope
5. Generate Auth0 token
6. Test with token
```

### WordPress.com/Gravatar Bridge
```
1. Settings → WP oOS → Authentication
2. Enable WordPress.com/Gravatar identity bridge
3. (Optional) Configure userinfo endpoint
4. Save settings
5. Use OAuth tokens with wordpress.com|* or gravatar|* subjects
```

---

## 🧮 Token Counting & Budget Management

### Using count_tokens Tool
```javascript
// Count tokens for text (automatic - tries tiktoken, falls back to heuristic)
{
  "text": "This is a message to count tokens for.",
  "model": "gpt-4o-mini"
}

// Count tokens for messages array
{
  "messages": [
    {"role": "system", "content": "You are a helpful assistant."},
    {"role": "user", "content": "Hello, how are you?"}
  ],
  "model": "gpt-4o-mini",
  "method": "tiktoken"  // Options: tiktoken, heuristic, auto (default)
}

// Response includes:
// - estimated_tokens: Accurate token count
// - counting_method: Which method was used (tiktoken or heuristic)
// - model_info: Context limits, TPM/RPM limits, usage percentage
// - budget_info: Safe limits, remaining tokens, recommendations
```

### Token Counting Methods
| Method | Accuracy | Speed | Requirements |
|--------|----------|-------|--------------|
| `tiktoken` | Exact (uses OpenAI's BPE) | Fast | Composer install required |
| `heuristic` | ~4 chars/token estimate | Very Fast | No dependencies |
| `auto` (default) | Tries tiktoken, falls back | Fast | Works always |

### Installation for Accurate Counting
```bash
# Install tiktoken-php library
composer install

# Verify installation
composer show rahul900day/tiktoken-php
```

---

## 🌐 REST API Quick Reference

### Base URL
```
https://your-site.com/wp-json/mcp-ai/v1
```

### Key Endpoints
```bash
# List assistants
GET /assistants

# Start chat
POST /chat
{
  "assistant_id": 123,
  "messages": [
    {"role": "user", "content": "Hello"}
  ]
}

# Execute tool
POST /tools
{
  "assistant_id": 123,
  "tool": "get_site_summary",
  "arguments": {}
}

# SSE stream (Server-Sent Events)
GET /sse
Accept: text/event-stream

# SSE job status
GET /jobs/{job_id}/stream?max_duration=300&poll_interval=2
```

### SSE Streaming Examples
```javascript
// Stream assistant directory
const eventSource = new EventSource('/wp-json/mcp-ai/v1/sse');
eventSource.addEventListener('directory', (e) => {
  const data = JSON.parse(e.data);
  console.log('Assistants:', data.assistants);
});

// Stream job status
const jobStream = new EventSource(`/wp-json/mcp-ai/v1/jobs/${jobId}/stream`);
jobStream.addEventListener('status', (e) => {
  const status = JSON.parse(e.data);
  console.log('Progress:', status.progress + '%');
});
```

### Authentication Headers
```bash
# WordPress nonce (same-origin)
X-WP-Nonce: abc123

# Bearer token (remote)
Authorization: Bearer cred_xxxxx.SECRET

# Guest token
X-WP-MCP-AI-Guest: guest_token_here
```

---

## 🐛 Troubleshooting Quick Fixes

### Chat Not Working
```
1. Check OpenAI API key in settings
2. Verify assistant is published
3. Check user has edit_posts capability (or use allow_guests="true")
4. Enable logging to see errors
5. Check browser console for JavaScript errors
```

### Tool Execution Fails
```
1. Verify tool is enabled for assistant
2. Check user has required capability
3. Ensure dependencies are installed (WooCommerce, JetEngine, etc.)
4. Enable logging to see tool errors
5. Test tool individually via REST API
```

### API Rate Limiting
```
1. Check OpenAI account limits
2. Review request timeout settings
3. Enable rate limit protection in settings
4. Consider caching frequently requested data
5. Upgrade OpenAI plan if needed
```

### File Upload Issues
```
1. Check file MIME type is allowed
2. Verify file size < 5MB (default)
3. Check WordPress upload_max_filesize
4. Ensure proper permissions on uploads folder
5. Review attachment settings in WP oOS
```

---

## 📊 Monitoring & Logs

### Viewing Logs
```bash
# Via WP-CLI
wp option get wp_mcp_ai_recent_errors --format=json
wp option get wp_mcp_ai_recent_activity --format=json

# Via PHP
$errors = get_option( 'wp_mcp_ai_recent_errors', [] );
$activity = get_option( 'wp_mcp_ai_recent_activity', [] );
```

### Usage Tracking
```php
// Get user usage
$tracker = WP_MCP_AI_Usage_Tracker::get_instance();
$usage = $tracker->get_usage( $user_id );

// Usage structure
[
  'openai' => [
    'gpt-4o-mini' => ['tokens' => 1000, 'requests' => 5]
  ]
]
```

### Performance Monitoring
```
1. Enable logging temporarily
2. Review response times in logs
3. Check database query counts
4. Monitor memory usage
5. Profile with Query Monitor plugin
```

---

## 🔧 Configuration Snippets

### wp-config.php Constants
```php
// Base version mode (fewer tools)
define( 'WP_MCP_AI_BASE_VERSION', true );

// Crawl4AI endpoint
define( 'WP_MCP_AI_CRAWL4AI_BASE_URL', 'http://localhost:8000' );

// Custom capability
define( 'WP_MCP_AI_DEFAULT_CAPABILITY', 'edit_posts' );
```

### Custom Tool Registration
```php
add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    $registry->register( 'my_tool', new My_Custom_Tool() );
} );
```

### Filter Chat Messages
```php
add_filter( 'wp_mcp_ai_chat_options', function( $options, $assistant, $request ) {
    // Modify temperature
    $options['temperature'] = 0.7;
    return $options;
}, 10, 3 );
```

### Hook Into Tool Execution
```php
add_action( 'wp_mcp_ai_before_tool_execution', function( $tool, $args, $context ) {
    error_log( "Executing tool: {$tool}" );
}, 10, 3 );
```

---

## 📱 Mobile & Responsive

### Chat Widget Sizing
```css
/* Custom chat width */
.mcp-ai-chat-container {
    max-width: 600px;
    margin: 0 auto;
}

/* Mobile optimization */
@media (max-width: 768px) {
    .mcp-ai-chat-container {
        max-width: 100%;
        padding: 10px;
    }
}
```

---

## 🎨 Customization

### Chat Theme Colors
```
Settings → WP oOS → Chat Theme
- Primary Color
- Secondary Color
- User Message Background
- Assistant Message Background
- Border Color
- Text Color
```

### Custom CSS
```css
/* Add to theme */
.mcp-ai-chat-message.user {
    background: #007cba;
    color: white;
}

.mcp-ai-chat-message.assistant {
    background: #f0f0f0;
    color: #333;
}
```

---

## 📚 Additional Resources

### Full Documentation
- [Complete README](../README.md) - 1,027 lines of comprehensive docs
- [Documentation Index](DOCUMENTATION_INDEX.md) - All 39 documentation files
- [Tool Reference](reference/tools/tool-reference.md) - All 65+ tools detailed
- [REST API Guide](reference/api/rest-api.md) - Complete API documentation
- [Orchestration Budget Enforcement](architecture/orchestration/orchestration-budget-enforcement.md) - Budget prediction and adjustment

### External Links
- [OpenAI Platform](https://platform.openai.com/)
- [WordPress Codex](https://codex.wordpress.org/)
- [JetEngine Docs](https://crocoblock.com/knowledge-base/jetengine/)
- [Elementor Developers](https://developers.elementor.com/)

---

## 💡 Pro Tips

### Performance Optimization
```
- Enable object caching (Redis, Memcached)
- Use transients for expensive operations
- Limit tool selection per assistant
- Optimize base knowledge files
- Monitor API usage and costs
```

### Security Best Practices
```
- Never commit API keys to version control
- Use environment variables for secrets
- Limit guest access to specific assistants
- Review and rotate credentials regularly
- Enable rate limiting for public endpoints
```

### Cost Management
```
- Start with gpt-4o-mini model
- Monitor token usage via dashboard
- Set up usage alerts in OpenAI
- Cache responses where appropriate
- Use prompt shortcuts to reduce typing
```

---

## 🆘 Getting Help

### Support Channels
1. **Documentation** - Check [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md)
2. **Troubleshooting** - See [deployment-troubleshooting.md](getting-started/installation-setup/deployment-troubleshooting.md)
3. **GitHub Issues** - https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues
4. **Community** - Follow contribution guidelines

### Before Asking for Help
- [ ] Check documentation
- [ ] Enable logging and review errors
- [ ] Test with default assistant
- [ ] Verify API keys are correct
- [ ] Check plugin/theme conflicts
- [ ] Review GitHub issues for similar problems

---

**Need more detail?** See [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md) for complete documentation map.

**Maintained by:** NV Digital Solutions  
**License:** GPLv3 or later
