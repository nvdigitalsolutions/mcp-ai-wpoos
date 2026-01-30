=== NV Digital Open Operator System (oOS) ===
Contributors: nvdigitalsolutions
Donate link: https://nvdigitalsolutions.com/wpoos
Tags: ai, chatbot, openai, assistant, automation
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI Assistant framework for WordPress supporting OpenAI, Gemini, Anthropic, and Ollama (Local). Works standalone with optional third-party plugin integrations.

== Description ==

**NV Digital Open Operator System (oOS)** is a comprehensive AI Assistant framework that transforms your WordPress site into an intelligent automation platform. Create custom AI assistants that can search content, generate media, manage operations, and interact with users through a modern chat interface.

The plugin works standalone with vanilla WordPress and can be extended with optional third-party plugin integrations (JetEngine, WooCommerce, Elementor) to unlock additional capabilities.

**Important:** This plugin sends data to third-party AI services. Please review the [Privacy & Data Usage section](#privacy-policy) and each provider's terms before use:
* [OpenAI Terms of Service](https://openai.com/policies/terms-of-use) | [Privacy Policy](https://openai.com/privacy)
* [Google Gemini Terms](https://ai.google.dev/terms) | [Privacy](https://ai.google.dev/privacy)
* Ollama (self-hosted, no external data transmission)


= Why oOS? =

Unlike simple chatbot plugins, oOS is a complete **AI orchestration system** designed for modern WordPress sites:

* **Comprehensive Tool Library** - Content management, media generation, research, site operations
* **Optional Integrations** - Enhanced features with WooCommerce, JetEngine, Elementor when installed
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
* **OpenAI** - GPT-4o, GPT-4, GPT-4o-mini ([Terms](https://openai.com/policies/terms-of-use) | [Privacy](https://openai.com/privacy))
* **Google Gemini** - Gemini Pro, Gemini 1.5 ([Terms](https://ai.google.dev/terms) | [Privacy](https://ai.google.dev/privacy))
* **Ollama** - Privacy-focused local AI (self-hosted, no external data)
* **LM Studio** - Local AI with function calling (self-hosted, no external data)
* Automatic provider fallback for maximum uptime

**Built-in Tools:**
* **Content Tools** - Search posts, save drafts, manage attachments (15+ tools)
* **Media Generation** - AI images (OpenAI, Gemini, Cloudflare), text-to-speech, vectorization, graphic editing (10+ tools)
* **Research Tools** - Web search, weather, disaster alerts, Crawl4AI integration (8+ tools)
* **Site Operations** - Cache management, cron jobs, health checks, WP-CLI integration (12+ tools)
* **Analytics** - Token usage tracking, cost attribution, social media analytics (9+ tools)
* **Social Media** - Publishing, insights, and analytics across Facebook, Instagram, Twitter, LinkedIn, YouTube, TikTok (19 Pro tools)
* **E-commerce** - WooCommerce integration, product management, order processing (20 Pro tools)
* **Multi-Agent Orchestration** - DeepSeek V4-inspired agent coordination with 3 specialized tools (NEW January 2026)

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

NV oOS works perfectly standalone. Optional integrations add enhanced functionality:

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

NV oOS is 100% open source and licensed under GPLv3. We welcome contributions:

* [GitHub Repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
* [Issue Tracker](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
* [Contributing Guide](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/CONTRIBUTING.md)

== Installation ==

**Plugin Directory Status:** This plugin is currently pending approval in the WordPress Plugin Directory. We are committed to maintaining high quality and security standards throughout the review process.

= Automatic Installation =

Once approved in the WordPress Plugin Directory:

1. Go to Plugins → Add New in your WordPress admin
2. Search for "Open Operator System"
3. Click "Install Now" and then "Activate"
4. Navigate to Settings → NV oOS to configure your API key

= Manual Installation =

1. Download the plugin ZIP file
2. Go to Plugins → Add New → Upload Plugin
3. Upload the ZIP file and click "Install Now"
4. Activate the plugin

= Configuration =

1. Go to **Settings → NV oOS**
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

NV oOS supports WordPress multisite:

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

NV oOS itself is free. AI provider costs depend on usage:
* OpenAI charges per token (~$0.002 per 1K tokens for GPT-4o-mini)
* Gemini has a generous free tier
* Ollama is completely free (runs on your hardware)

= Is my data sent to OpenAI/Google? =

Yes, when you use cloud AI providers, your chat messages are sent to their APIs. For complete data privacy, use Ollama for local AI processing. Review the privacy policies of your chosen provider.

= Can I use this without JetEngine? =

Absolutely! NV oOS works perfectly with vanilla WordPress. JetEngine integration is optional and adds server-side chat transcript storage. Without it, chat history is stored in browser localStorage (24 hours).

= How do I connect Claude Desktop or LM Studio? =

NV oOS includes a full MCP server:
1. Generate API credentials from the assistant editor
2. Configure your MCP client with the credentials
3. Use endpoint: `https://yoursite.com/wp-json/mcp-ai/v1/`

See our [MCP Server Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/mcp-server-authentication.md) for detailed setup.

= Is this plugin GDPR compliant? =

NV oOS includes features to help with GDPR compliance:
* No tracking scripts or cookies
* Optional logging (can be disabled)
* API keys are never stored in plain text
* Chat transcripts can be configured or disabled

You are responsible for reviewing your AI provider's data processing agreements and informing users about AI processing.

= How do I extend NV oOS with custom tools? =

NV oOS has a developer-friendly tool registry:

`add_action( 'wp_mcp_ai_register_tools', function( $registry ) {
    require_once 'path/to/class-my-custom-tool.php';
    $registry->register_tool( 'My_Custom_Tool_Class' );
} );`

See our [Tool Reference](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/tool-reference.md) for examples.

= Where can I get support? =

* [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues) - Bug reports and feature requests
* [Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/tree/main/docs) - Comprehensive guides
* [WordPress.org Forums](https://wordpress.org/support/plugin/wp-mcp-ai/) - Community support

= Is this plugin patented? =

Yes. NV oOS is the subject of a pending patent application (Application #19/410,504) for "System and Method for Dynamic AI Orchestration Layer with Real-Time Capability Gating and Resource Budgeting."

**Your Rights:** The patent will not be used to restrict your GPL rights. This plugin is licensed under GPLv3 or later, and you have all the freedoms granted by that license:

* Freedom to use the software for any purpose
* Freedom to study and modify the source code
* Freedom to redistribute copies
* Freedom to distribute modified versions

The patent protects our novel orchestration system while ensuring the open source community retains full GPL rights. We will not use the patent offensively against GPL-licensed derivative works.

For more details, see our [CONTRIBUTING.md](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/CONTRIBUTING.md) file.

== Screenshots ==

1. **Assistant Editor** - Configure AI assistants with custom system prompts, model settings, and tool selection
2. **Chat Interface** - Modern, responsive chat UI with file attachments and streaming responses
3. **Settings Dashboard** - Configure API keys, default models, and plugin settings
4. **Tool Registry** - 127+ base tools, 70 Pro tools (197 total) for content, media, research, and operations
5. **Profession Templates** - 182 pre-built profession templates for quick assistant creation
6. **MCP Server** - Connect Claude Desktop, LM Studio, and other MCP clients

== Changelog ==

= 1.1.0 - January 2026 =

**New Features**

* **DeepSeek V4 Multi-Agent Orchestration** - Comprehensive multi-agent coordination framework with 4 specialized agent roles (Planner, Executor, Critic, Specialist)
* **Agent Team Orchestrator** - Manages team composition and coordinated workflow execution with 5 aggregation strategies
* **3 New Agent Coordination Tools** - create_agent_team, delegate_to_agent, aggregate_agent_results
* **Social Media Analytics Toolkit** - Added 4 new analytics tools:
  * Get Cross-Platform Analytics - Unified metrics dashboard across Facebook, Instagram, Twitter, LinkedIn, YouTube
  * Track Hashtag Performance - Hashtag analysis with reach and engagement tracking
  * Competitor Analysis - Monitor and benchmark competitor social media metrics
  * Influencer Identification - Discover brand influencers based on reach and engagement criteria
* **Pro Toolkit Memory-Based Tracking** - Replaced hard 5-toolkit limit with transparent memory usage display
* **Cloudflare Image Models** - Added support for Flux-2 Dev, Leonardo Lucid Origin, and Phoenix 1.0 models
* **200+ Profession Orchestration** - Intelligent agent role assignment via WP-CLI commands

**Bug Fixes**

* Fixed tool settings not persisting on Token Manager page (triple-sanitization issue)
* Fixed provider API keys being cleared on admin tab navigation (double-sanitization issue)
* Fixed team chat transcript recording for unified team and member chats
* Fixed "Apply Preset" button on Token Manager page
* Fixed HuggingFace Qwen3-Coder token limit errors
* Fixed Gmail OAuth redirect_uri_mismatch errors
* Fixed model dropdown when both base and Pro plugins are active

**Improvements**

* Pro Toolkit Infrastructure Phase 3 complete - All 11 toolkit settings pages implemented
* Social Media toolkit now includes 19 tools (15 publishing/insights + 4 new analytics)
* Multi-agent functionality: Up to 5 concurrent specialized agents (one per active toolkit)
* Documentation consolidation - Menu fixes consolidated, organized subdirectories
* Created 6 detailed fix documentation files
* Production-ready settings management with comprehensive backup and validation

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

= 1.1.0 =
Major update with DeepSeek V4 multi-agent orchestration, 4 new Social Media Analytics tools, memory-based toolkit tracking, and 7 critical bug fixes. Recommended for all users.

= 1.0.0 =
Initial release. Welcome to Open Operator System!

== External Services ==

**IMPORTANT:** This plugin connects to various third-party services to provide AI functionality and optional features. 

**📖 Complete Documentation:** For comprehensive details about all 16 external services, data transmission, and legal requirements, see our [External Services Reference](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/EXTERNAL_SERVICES.md).

Below is a summary of the most commonly used services:

= AI Provider Services (Required - At Least One Must Be Configured) =

**1. OpenAI API**
* **Purpose:** Core AI functionality (chat, image generation, text-to-speech, embeddings)
* **Data Sent:** Chat messages, system prompts, file attachments, tool results
* **When:** Every time an AI assistant is used with OpenAI as the provider
* **Service URL:** https://api.openai.com
* **Terms of Service:** https://openai.com/policies/terms-of-use
* **Privacy Policy:** https://openai.com/privacy
* **Data Retention:** 30 days for abuse monitoring, then deleted (as of March 2023)

**2. Google Gemini API**
* **Purpose:** Core AI functionality (chat, image generation, embeddings, geospatial queries)
* **Data Sent:** Chat messages, system prompts, file attachments, tool results
* **When:** Every time an AI assistant is used with Gemini as the provider
* **Service URL:** https://generativelanguage.googleapis.com
* **Terms of Service:** https://ai.google.dev/terms
* **Privacy Policy:** https://ai.google.dev/privacy

**3. Ollama (Self-Hosted)**
* **Purpose:** Privacy-focused local AI processing
* **Data Sent:** None (runs entirely on your server)
* **When:** When configured as AI provider
* **Service URL:** Your local server only
* **Privacy:** No external data transmission

**4. LM Studio (Self-Hosted)**
* **Purpose:** Local AI with function calling support
* **Data Sent:** None (runs entirely on your computer)
* **When:** When configured as AI provider
* **Service URL:** Your local computer only
* **Privacy:** No external data transmission

= Optional Third-Party Service Integrations =

These services are only contacted when specific tools/features are used:

**5. Brave Search API**
* **Purpose:** Web search functionality for AI assistants
* **Data Sent:** Search queries provided by users or AI
* **When:** When the web search tool is called by an assistant
* **Service URL:** https://api.search.brave.com/res/v1/web/search
* **Terms of Service:** https://brave.com/terms-of-use/
* **Privacy Policy:** https://brave.com/privacy/browser/

**6. Open-Meteo Weather API**
* **Purpose:** Weather forecasts and historical weather data
* **Data Sent:** Location coordinates or city names
* **When:** When weather tools are used
* **Service URL:** https://api.open-meteo.com
* **Terms of Service:** https://open-meteo.com/en/terms
* **Privacy Policy:** https://open-meteo.com/en/terms (includes privacy information)

**7. ReliefWeb API**
* **Purpose:** Humanitarian disaster and emergency reports
* **Data Sent:** Search queries for disaster reports
* **When:** When ReliefWeb tools are used
* **Service URL:** https://api.reliefweb.int/v1/reports
* **Terms of Service:** https://reliefweb.int/terms-conditions
* **Privacy Policy:** https://reliefweb.int/privacy-policy

**8. WordPress.org API**
* **Purpose:** PHP version compatibility check for site health
* **Data Sent:** Current PHP version number
* **When:** When site health tools are called
* **Service URL:** https://api.wordpress.org/core/serve-happy/1.0/
* **Terms of Service:** https://wordpress.org/about/privacy/
* **Privacy Policy:** https://wordpress.org/about/privacy/

**9. Chart.js CDN**
* **Purpose:** Chart visualization library for displaying data
* **Data Sent:** None (library loaded client-side)
* **When:** When chart generation tools create visualizations
* **Service URL:** https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js
* **Terms of Service:** https://www.jsdelivr.com/terms
* **Privacy Policy:** https://www.jsdelivr.com/privacy-policy-jsdelivr-net

= Optional OAuth/Integration Services (Pro Version Only) =

These services are only used if you explicitly configure OAuth integrations:

**10. GitHub API**
* **Purpose:** Repository management, code search, issue tracking
* **Data Sent:** OAuth tokens, repository queries, commit data
* **When:** When GitHub tools are used after OAuth setup
* **Service URL:** https://api.github.com
* **Terms of Service:** https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* **Privacy Policy:** https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement

**11. Cloudways API**
* **Purpose:** Server management for Cloudways hosting customers
* **Data Sent:** OAuth tokens, server management commands
* **When:** When Cloudways tools are used after OAuth setup
* **Service URL:** https://api.cloudways.com/api/v1
* **Terms of Service:** https://www.cloudways.com/en/terms-of-service.php
* **Privacy Policy:** https://www.cloudways.com/en/privacy-policy.php

**12. QuickBooks API (Intuit)**
* **Purpose:** Accounting and financial data integration
* **Data Sent:** OAuth tokens, financial queries
* **When:** When QuickBooks tools are used after OAuth setup
* **Service URL:** https://appcenter.intuit.com/connect/oauth2
* **Terms of Service:** https://accounts.intuit.com/terms-of-service
* **Privacy Policy:** https://www.intuit.com/privacy/statement/

**13. Mailjet API**
* **Purpose:** Email marketing and transactional email
* **Data Sent:** OAuth tokens, email campaign data
* **When:** When Mailjet tools are used after OAuth setup
* **Service URL:** https://app.mailjet.com/oauth/authorize
* **Terms of Service:** https://www.mailjet.com/legal/terms-of-use/
* **Privacy Policy:** https://www.mailjet.com/privacy-policy/

= Data Processing Summary =

**What is sent to external services:**
* User messages and chat conversations (AI providers only)
* File uploads (AI providers only)
* Search queries (when using search/weather tools)
* OAuth credentials (when using optional integrations)

**What is NOT sent:**
* WordPress admin credentials
* Database contents (unless explicitly requested via tool)
* Site configuration (unless using diagnostic tools)
* Other user data not related to AI requests

**When data is sent:**
* Only when you or your users actively use AI features
* Only to services you have explicitly configured
* Never for analytics or telemetry purposes

**Your control:**
* You choose which AI provider to use
* You control which tools are enabled
* You can use self-hosted AI (Ollama/LM Studio) for complete privacy
* OAuth integrations are entirely optional

= Recommendations =

1. **Review Provider Policies** - Read the terms and privacy policies of any service you plan to use
2. **Use Local AI for Sensitive Data** - Configure Ollama or LM Studio for maximum privacy
3. **Limit Tool Access** - Only enable tools your site actually needs
4. **Update Your Privacy Policy** - Inform your users about AI processing on your site
5. **Obtain Consent** - Get user consent before processing personal data with AI
6. **Monitor Usage** - Use the built-in token tracking to monitor API calls

== Privacy Policy ==

**Open Operator System** respects your privacy and is committed to transparency about data handling.

= What Data Does This Plugin Collect? =

**Locally Stored (Your WordPress Database):**
* Plugin settings and configuration
* AI assistant definitions and system prompts
* API keys (encrypted, never transmitted except to your configured AI provider)
* Optional: Chat transcripts (if JetEngine integration is enabled)
* Optional: Usage logs (disabled by default, controlled in settings)

**No External Tracking:**
* No analytics or telemetry sent to plugin developers
* No tracking scripts, cookies, or beacons
* No phone-home functionality
* Your data stays on your server

= What Data is Sent to AI Providers? =

When you use AI features, data is transmitted to your configured AI provider(s):

**Sent to AI Providers:**
* Chat messages and conversation history
* File attachments you upload (images, documents, PDFs)
* System prompts and assistant instructions
* Tool execution results when tools are called

**OpenAI (when configured):**
* Data sent to: https://api.openai.com
* Processed according to: [OpenAI Privacy Policy](https://openai.com/privacy)
* Terms of Service: [OpenAI Terms](https://openai.com/policies/terms-of-use)
* Data Usage: OpenAI does not use API data to train models (as of March 2023)
* Retention: API data retained for 30 days for abuse monitoring, then deleted

**Google Gemini (when configured):**
* Data sent to: https://generativelanguage.googleapis.com
* Processed according to: [Google AI Privacy](https://ai.google.dev/privacy)
* Terms of Service: [Google Gemini Terms](https://ai.google.dev/terms)
* Data Usage: Google uses API data as described in their privacy policy
* Review Google's data retention policies before use

**Ollama (when configured):**
* Data sent to: Your local server only (self-hosted)
* No external data transmission
* Complete data privacy and control
* Recommended for sensitive data

**LM Studio (when configured):**
* Data sent to: Your local computer only (self-hosted)
* No external data transmission
* Complete data privacy and control
* Recommended for sensitive data

= GDPR Compliance =

**Your Rights:**
* **Right to Access** - Export your data from WordPress admin
* **Right to Deletion** - Delete plugin data via uninstall (if enabled in settings)
* **Right to Portability** - Chat transcripts stored in standard WordPress format
* **Right to Object** - Disable AI features at any time

**Data Controller:**
* **For Plugin Data** - You (the site owner) are the data controller
* **For AI Processing** - Your chosen AI provider is a data processor
* **Recommendation** - Update your site's privacy policy to inform users about AI processing

**Processing Basis:**
* Legitimate interest for site operations
* User consent when collecting personal data for AI processing
* Review GDPR requirements for your specific use case

= Recommendations for Site Owners =

1. **Update Your Privacy Policy** - Inform users that AI processing is used
2. **Review Provider Terms** - Understand each AI provider's data handling
3. **Use Ollama for Sensitive Data** - Keep sensitive information local
4. **Disable Logging** - Turn off optional logging for maximum privacy
5. **Get Consent** - Obtain user consent before processing personal data with AI
6. **Data Processing Agreements** - Review DPAs with your AI providers

= Third-Party Services =

This plugin may connect to the following external services based on your configuration:

**Required (one must be configured):**
* OpenAI API - [Privacy](https://openai.com/privacy) | [Terms](https://openai.com/policies/terms-of-use)
* Google Gemini API - [Privacy](https://ai.google.dev/privacy) | [Terms](https://ai.google.dev/terms)
* Ollama (self-hosted) - No external service
* LM Studio (self-hosted) - No external service

**Optional (for specific features):**
* Weather data - OpenWeatherMap API
* Web search - Brave Search API
* Image generation - Requires OpenAI or Gemini API key

For complete privacy, configure Ollama or LM Studio for fully local AI processing.

Review your chosen provider's privacy policy before use.

== Credits ==

Open Operator System is developed and maintained by [NV Digital Solutions](https://nvdigitalsolutions.com/).

Special thanks to the open source community and all contributors.
