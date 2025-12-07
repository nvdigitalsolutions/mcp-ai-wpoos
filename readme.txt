=== Open Operator System (WP oOS) ===
Contributors: nvdigitalsolutions
Donate link: https://nvdigitalsolutions.com/wpoos
Tags: ai, chatbot, openai, assistant, automation
Requires at least: 6.0
Tested up to: 6.7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI Assistant framework for WordPress with 35+ core tools, supporting OpenAI, Gemini, and Ollama. Optional third-party integrations available.

== Description ==

**Open Operator System (WP oOS)** is a comprehensive AI Assistant framework that transforms your WordPress site into an intelligent automation platform. Create custom AI assistants that can search content, generate media, manage operations, and interact with users through a modern chat interface.

The plugin works standalone with 35+ core tools and can be extended with optional third-party plugin integrations (JetEngine, WooCommerce, Elementor) to unlock additional capabilities.

**Patent Pending:** WP oOS is the subject of a pending patent application (19/410,504) for its novel System and Method for Dynamic AI Orchestration Layer with Real-Time Capability Gating and Resource Budgeting.

= Why WP oOS? =

Unlike simple chatbot plugins, WP oOS is a complete **AI orchestration system** designed for modern WordPress sites:

* **35+ Core Tools** - Content management, media generation, research, site operations (base version)
* **30+ Additional Tools** - Available with optional third-party plugins (WooCommerce, JetEngine, Elementor)
* **Multi-Provider Support** - OpenAI, Google Gemini, Ollama (local AI), and LM Studio
* **MCP Server** - Standards-compliant Model Context Protocol server for Claude Desktop, LM Studio, and other AI clients
* **Enterprise Security** - Rate limiting, usage tracking, capability-based access control
* **Zero Lock-in** - Works with vanilla WordPress; optional integrations enhance functionality

= Key Features =

**AI Assistant Management**
* Create unlimited AI assistants with custom system prompts
* Per-assistant model configuration (temperature, max tokens)
* 182 pre-built profession templates across 12 industry categories
* One-click team deployments for coordinated AI workflows

**Multi-Provider AI Routing**
* OpenAI GPT models (GPT-4o, GPT-4, GPT-4o-mini)
* Google Gemini (Gemini Pro, Gemini 1.5)
* Ollama for privacy-focused local AI
* LM Studio integration with function calling support
* Automatic provider fallback for maximum uptime

**35+ Core Tools (Base Version):**
* **Content Tools** - Search posts, save drafts, manage attachments
* **Media Generation** - OpenAI images, Gemini images, text-to-speech
* **Research Tools** - Web search, weather, disaster alerts
* **Site Operations** - Cache management, cron jobs, health checks
* **Analytics** - Token usage tracking, cost attribution

**Chat Interface**
* Modern, responsive chat UI
* Shortcode: `[mcp_ai_chat assistant="123"]`
* Elementor widget support
* File attachments (images, PDFs, documents)
* Real-time streaming responses (SSE)
* Chat history persistence (24h localStorage)

**MCP Server (Model Context Protocol)**
* Full JSON-RPC 2.0 implementation
* Connect Claude Desktop, LM Studio, and other MCP clients
* REST API endpoints for remote integration
* SSE streaming for real-time responses

**Security & Compliance**
* Capability-based access control
* API key management (never stored in plain text)
* Rate limiting per user/model
* Comprehensive audit logging
* GDPR-ready with data export options

= Third-Party Plugin Support (Optional) =

WP oOS works perfectly standalone. Optional integrations add enhanced functionality:

* **JetEngine** (paid) - Server-side chat transcript storage, CCT integration
* **WooCommerce** (free) - E-commerce automation tools
* **Elementor** (freemium) - Template management, pre-built widgets
* **Rank Math SEO** (freemium) - SEO analysis and optimization
* **WPCode** (freemium) - Code snippet automation

= System Requirements =

* WordPress 6.0 or higher
* PHP 7.4 or higher (PHP 8.0+ recommended)
* MySQL 5.7 or higher (or MariaDB 10.3+)
* API key from OpenAI, Google, or local AI server

= Documentation =

Comprehensive documentation is available in the plugin's `/docs/` directory:

* [Quick Reference Guide](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/QUICK_REFERENCE.md)
* [REST API Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/rest-api.md)
* [Tool Reference](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/tool-reference.md)
* [MCP Server Authentication](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/mcp-server-authentication.md)

= Open Source =

WP oOS is 100% open source and licensed under GPLv3. We welcome contributions:

* [GitHub Repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
* [Issue Tracker](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
* [Contributing Guide](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/CONTRIBUTING.md)

== Installation ==

= Automatic Installation =

1. Go to Plugins → Add New in your WordPress admin
2. Search for "Open Operator System"
3. Click "Install Now" and then "Activate"
4. Navigate to Settings → WP oOS to configure your API key

= Manual Installation =

1. Download the plugin ZIP file
2. Go to Plugins → Add New → Upload Plugin
3. Upload the ZIP file and click "Install Now"
4. Activate the plugin

= Configuration =

1. Go to **Settings → WP oOS**
2. Enter your OpenAI API key (get one at [platform.openai.com](https://platform.openai.com))
3. (Optional) Configure Gemini API key for Google AI support
4. (Optional) Configure Ollama endpoint for local AI

= Creating Your First Assistant =

1. Go to **AI Assistants → Add New**
2. Give your assistant a name and description
3. Configure the system prompt (instructions for the AI)
4. Select which tools the assistant can use
5. Publish the assistant
6. Add `[mcp_ai_chat assistant="YOUR_ID"]` to any page

= Multisite Installation =

WP oOS supports WordPress multisite:

* **Network Activation** - Activate network-wide from Network Admin → Plugins
* **Individual Activation** - Activate on specific sites as needed
* Settings are configured per-site for maximum flexibility

== Frequently Asked Questions ==

= Do I need an OpenAI API key? =

Yes, you need an API key from at least one AI provider. OpenAI is recommended for beginners. Alternatively, you can use:
* Google Gemini API key
* Ollama (free, runs locally on your server)
* LM Studio (free, runs on your computer)

= How much does it cost to use the AI features? =

WP oOS itself is free. AI provider costs depend on usage:
* OpenAI charges per token (~$0.002 per 1K tokens for GPT-4o-mini)
* Gemini has a generous free tier
* Ollama is completely free (runs on your hardware)

= Is my data sent to OpenAI/Google? =

Yes, when you use cloud AI providers, your chat messages are sent to their APIs. For complete data privacy, use Ollama for local AI processing. Review the privacy policies of your chosen provider.

= Can I use this without JetEngine? =

Absolutely! WP oOS works perfectly with vanilla WordPress. JetEngine integration is optional and adds server-side chat transcript storage. Without it, chat history is stored in browser localStorage (24 hours).

= How do I connect Claude Desktop or LM Studio? =

WP oOS includes a full MCP server:
1. Generate API credentials from the assistant editor
2. Configure your MCP client with the credentials
3. Use endpoint: `https://yoursite.com/wp-json/mcp-ai/v1/`

See our [MCP Server Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/mcp-server-authentication.md) for detailed setup.

= Is this plugin GDPR compliant? =

WP oOS includes features to help with GDPR compliance:
* No tracking scripts or cookies
* Optional logging (can be disabled)
* API keys are never stored in plain text
* Chat transcripts can be configured or disabled

You are responsible for reviewing your AI provider's data processing agreements and informing users about AI processing.

= How do I extend WP oOS with custom tools? =

WP oOS has a developer-friendly tool registry:

`add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    require_once 'path/to/class-my-custom-tool.php';
    $registry->register_tool( 'My_Custom_Tool_Class' );
} );`

See our [Tool Reference](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/tool-reference.md) for examples.

= Where can I get support? =

* [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues) - Bug reports and feature requests
* [Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs) - Comprehensive guides
* [WordPress.org Forums](https://wordpress.org/support/plugin/wp-mcp-ai/) - Community support

== Screenshots ==

1. **Assistant Editor** - Configure AI assistants with custom system prompts, model settings, and tool selection
2. **Chat Interface** - Modern, responsive chat UI with file attachments and streaming responses
3. **Settings Dashboard** - Configure API keys, default models, and plugin settings
4. **Tool Registry** - 74+ built-in tools for content, media, research, and operations
5. **Profession Templates** - 182 pre-built profession templates for quick assistant creation
6. **MCP Server** - Connect Claude Desktop, LM Studio, and other MCP clients

== Changelog ==

= 1.0.0 =

**Initial Release**

* 74+ built-in tools for content, media, research, and site operations
* Multi-provider support: OpenAI, Gemini, Ollama, LM Studio
* Full MCP (Model Context Protocol) server implementation
* Modern chat interface with streaming responses
* 182 profession templates across 12 industry categories
* Comprehensive REST API
* SSE (Server-Sent Events) streaming support
* Rate limiting and usage tracking
* Capability-based access control
* WordPress multisite support
* Extensive documentation

= Development History =

This plugin has been in active development since October 2024. See the complete [CHANGELOG.md](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/CHANGELOG.md) for detailed development history.

== Upgrade Notice ==

= 1.0.0 =
Initial release. Welcome to Open Operator System!

== Privacy Policy ==

Open Operator System respects your privacy:

* **No Tracking** - No analytics, telemetry, or tracking scripts
* **Local Data** - All settings stored in your WordPress database
* **API Communications** - Chat messages sent to your configured AI provider (OpenAI, Gemini, or Ollama)
* **Optional Logging** - Disabled by default, controlled in settings

When using cloud AI providers (OpenAI, Gemini), your chat messages are processed according to their privacy policies. For complete data privacy, use Ollama for local AI processing.

Review your AI provider's data processing policies:
* [OpenAI Privacy Policy](https://openai.com/privacy)
* [Google AI Privacy](https://ai.google.dev/privacy)

== Credits ==

Open Operator System is developed and maintained by [NV Digital Solutions](https://nvdigitalsolutions.com/).

Special thanks to the open source community and all contributors.

== External Services ==

This plugin connects to external AI services when configured:

**OpenAI API**
* Service: [platform.openai.com](https://platform.openai.com/)
* Privacy Policy: [openai.com/privacy](https://openai.com/privacy)
* Terms: [openai.com/policies](https://openai.com/policies)

**Google Gemini API**
* Service: [ai.google.dev](https://ai.google.dev/)
* Privacy Policy: [ai.google.dev/privacy](https://ai.google.dev/privacy)
* Terms: [ai.google.dev/terms](https://ai.google.dev/terms)

**Ollama (Optional, Self-Hosted)**
* Service: Runs locally on your server
* No data leaves your infrastructure
