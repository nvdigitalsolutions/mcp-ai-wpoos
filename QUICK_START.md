# WP oOS - Quick Start Guide

**Get up and running with WP Open Operator System in 5 minutes!**

This guide gets you from zero to chatting with an AI assistant as fast as possible. For detailed documentation, see [README.md](README.md) or [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md).

---

## 🚀 Installation (2 minutes)

### Method 1: WordPress Admin (Easiest)
1. Download the plugin ZIP from [GitHub Releases](https://github.com/nvdigitalsolutions/wp-mcp-ai/releases)
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP file and click **Activate**

### Method 2: Manual Installation
```bash
cd wp-content/plugins/
git clone https://github.com/nvdigitalsolutions/wp-mcp-ai.git
cd wp-mcp-ai
composer install --no-dev
npm install
npm run build
```

Then activate in WordPress Admin → Plugins.

---

## ⚙️ Basic Configuration (2 minutes)

### 1. Add Your AI Provider API Key

Go to **Settings → WP oOS** and add at least one API key:

**OpenAI (Recommended for beginners)**
- Get key: https://platform.openai.com/api-keys
- Paste in "OpenAI API Key" field
- Click "Save Changes"

**OR Google Gemini (Free tier available)**
- Get key: https://aistudio.google.com/app/apikey
- Paste in "Gemini API Key" field
- Click "Save Changes"

**OR Ollama (Local, no API key needed)**
- Install Ollama: https://ollama.ai/download
- Set base URL: `http://localhost:11434`
- Click "Save Changes"

### 2. Create Your First Assistant

1. Go to **Assistants → Add New**
2. Enter a name: "My First Assistant"
3. Set system prompt: "You are a helpful WordPress assistant."
4. Select model: "gpt-4o-mini" (OpenAI) or "gemini-2.0-flash-exp" (Gemini)
5. Click **Publish**
6. Copy the Assistant ID (you'll see it in the URL or sidebar)

---

## 💬 Start Chatting (1 minute)

### Option 1: Chat Widget Shortcode (Easiest)

Add this to any post or page:
```
[mcp_ai_chat assistant="123"]
```
Replace `123` with your Assistant ID.

Visit the page and start chatting!

### Option 2: Admin Test Interface

1. Go to **Assistants → All Assistants**
2. Click "Test Chat" under your assistant
3. Start chatting in the admin panel

### Option 3: Elementor Widget

1. Edit a page with Elementor
2. Search for "MCP AI Chat" widget
3. Drag it to your page
4. Select your assistant
5. Publish and chat!

---

## ✅ Verify It's Working

You should see:
- ✅ Chat interface with input box
- ✅ Send button that responds when clicked
- ✅ AI responses appearing in real-time
- ✅ Streaming text (words appearing one by one)

If you see errors:
1. Check your API key is valid
2. Check Settings → WP oOS for error messages
3. See [Troubleshooting](#-troubleshooting) below

---

## 🎯 What to Try Next

### Enable More Tools
By default, basic tools are enabled. Enable more in **Settings → WP oOS → Tool Configuration**:

**Useful Tools to Enable:**
- 📝 **search-content** - Search WordPress posts
- 🖼️ **generate-image** - Create images with DALL-E
- 📧 **send-email** - Send emails via Mailjet
- 🎤 **generate-speech** - Text-to-speech
- 🔍 **web-search** - Search the internet

### Try Professional Templates
WP oOS includes 182 pre-built assistant templates:

1. Go to **Assistants → Add New**
2. Click **"Load from Template"** (if available)
3. Choose a profession (e.g., "Content Writer", "SEO Specialist")
4. Customize and publish

See [README.md § Professional & Team Layers](README.md) for details.

### Connect External Apps
WP oOS works as an MCP server. Connect:
- **Claude Desktop** - Anthropic's desktop app
- **LM Studio** - Local AI models
- **Custom MCP Clients** - Any app supporting MCP

See [docs/remote-client-quickstart.md](docs/remote-client-quickstart.md)

---

## 🛠️ Essential Settings

### Temperature (Creativity)
**Settings → WP oOS → Default Temperature**
- `0.0-0.3` = Precise, factual (customer support, data analysis)
- `0.4-0.7` = Balanced (general conversation)
- `0.8-1.0` = Creative (content writing, brainstorming)

### Models
**Settings → WP oOS → Default Model**

**OpenAI Models:**
- `gpt-4o-mini` - Fast, cheap, good quality (recommended)
- `gpt-4o` - Higher quality, slower, more expensive
- `gpt-4-turbo` - Legacy, stable

**Gemini Models:**
- `gemini-2.0-flash-exp` - Free tier, very fast (recommended)
- `gemini-1.5-pro` - Higher quality, paid
- `gemini-1.5-flash` - Fast, cheap

**Ollama Models:**
- `llama3.2` - Meta's latest, good quality
- `mistral` - Fast, efficient
- `qwen` - Good for coding

### Memory & Context
**Assistant Editor → Knowledge Base**
- Upload PDFs, documents for RAG
- Assistant can search these files
- Great for documentation, policies, etc.

---

## 🔒 Security Best Practices

### For Production Sites

1. **Limit Tool Access**
   - Only enable tools you need
   - Check capability requirements
   - Test in staging first

2. **Set Rate Limits**
   - Settings → WP oOS → Rate Limiting
   - Prevents API abuse
   - Recommended: 100 requests/hour per user

3. **Enable Logging**
   - Settings → WP oOS → Enable Logging
   - Track usage and errors
   - Review logs regularly

4. **Restrict Access**
   - Use WordPress capabilities
   - Only give edit_posts to trusted users
   - Consider custom capability roles

See [SECURITY.md](SECURITY.md) for complete security guide.

---

## 💡 Tips & Tricks

### Better Prompts
**Instead of:**
> "Write something"

**Try:**
> "Write a 300-word blog post about WordPress security best practices for beginners. Use simple language and include 3 actionable tips."

### Tool Shortcuts
Create reusable prompts:
1. Edit your assistant
2. Find "Tool Shortcuts" section
3. Add shortcut: "Generate blog post outline"
4. Shortcut appears as button in chat

### Streaming Status
Watch the status bar below chat input - shows:
- What the AI is thinking
- Which tools it's using
- Progress on long tasks

### Multisite
Each site in a multisite network can have:
- Own API keys
- Own assistants
- Own settings

Configure per-site or network-wide.

---

## 🐛 Troubleshooting

### Chat doesn't load
**Check:**
1. JavaScript errors in browser console (F12)
2. Shortcode syntax: `[mcp_ai_chat assistant="123"]`
3. Assistant ID is correct and published

**Fix:**
- Clear browser cache
- Disable conflicting plugins
- Check WordPress debug log

### "No API key configured" error
**Check:**
1. Settings → WP oOS → API key is saved
2. Click "Test Connection" to verify
3. Key has correct permissions

**Fix:**
- Regenerate API key on provider website
- Check for typos (no spaces before/after)
- Ensure account has credits/quota

### "Tool not found" error
**Check:**
1. Tool is enabled in Settings → WP oOS
2. User has required capability
3. Dependencies installed (WooCommerce, JetEngine, etc.)

**Fix:**
- Enable tool in settings
- Check user role capabilities
- Install missing plugins

### Streaming doesn't work
**Check:**
1. Browser supports Server-Sent Events (all modern browsers do)
2. Server supports SSE (most do)
3. No aggressive caching/CDN blocking SSE

**Fix:**
- Try different browser
- Disable CDN for chat pages
- Check server PHP timeout settings

### For More Help
- Check [README.md § Troubleshooting](README.md)
- Review [docs/deployment-troubleshooting.md](docs/deployment-troubleshooting.md)
- Search [GitHub Issues](https://github.com/nvdigitalsolutions/wp-mcp-ai/issues)
- Open new issue with details

---

## 📚 Learn More

### Documentation
- **[README.md](README.md)** - Complete documentation (4,000+ lines)
- **[DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md)** - Find any doc quickly
- **[BUILD.md](BUILD.md)** - Development and testing
- **[CONTRIBUTING.md](CONTRIBUTING.md)** - How to contribute

### Features Deep-Dives
- **[Tool Reference](docs/tool-reference.md)** - All 105+ tools explained
- **[REST API](docs/rest-api.md)** - API documentation
- **[MCP Endpoint](docs/mcp-endpoint.md)** - MCP server setup
- **[Professional Templates](README.md)** - 182 assistant templates

### Community
- **GitHub**: https://github.com/nvdigitalsolutions/wp-mcp-ai
- **Issues**: Report bugs, request features
- **Discussions**: Ask questions, share tips

---

## 🎓 Recommended Learning Path

### Week 1: Basics
1. ✅ Complete this quick start
2. Read [README.md § Overview](README.md)
3. Create 3-5 test assistants
4. Try different models and temperatures
5. Enable 5-10 tools and test them

### Week 2: Customization
1. Read [README.md § Assistant Editor](README.md)
2. Create assistants with knowledge bases
3. Set up tool shortcuts
4. Customize system prompts
5. Test with real use cases

### Week 3: Integration
1. Read [docs/rest-api.md](docs/rest-api.md)
2. Connect external MCP client
3. Try API calls from Postman
4. Integrate with your WordPress theme
5. Set up Elementor widgets

### Week 4: Production
1. Read [SECURITY.md](SECURITY.md)
2. Configure rate limiting
3. Set up logging and monitoring
4. Create production assistants
5. Deploy to staging, then production

### Beyond
- Develop custom tools (see README.md § Tool Development)
- Contribute to the project (see CONTRIBUTING.md)
- Share your assistants and templates
- Help others in community

---

## 🎉 You're Ready!

You now know enough to:
- ✅ Install and configure WP oOS
- ✅ Create and customize assistants
- ✅ Chat with AI from WordPress
- ✅ Enable tools and features
- ✅ Troubleshoot common issues

**Next Steps:**
1. Create your first real assistant for actual use
2. Share it with team or users
3. Explore advanced features in [README.md](README.md)
4. Join the community and share feedback

Welcome to WP oOS! 🚀

---

**Need Help?**
- Documentation: [DOCUMENTATION_INDEX.md](DOCUMENTATION_INDEX.md)
- Issues: https://github.com/nvdigitalsolutions/wp-mcp-ai/issues
- Security: [SECURITY.md](SECURITY.md)
