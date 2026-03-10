=== NV Digital Open Operator System (oOS) ===
Contributors: nvdigitalsolutions
Donate link: https://nvdigitalsolutions.com/wpoos
Tags: ai, chatbot, openai, assistant, automation
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI Assistant framework with OpenAI, Gemini, and Ollama integration. Base Version (165 core tools) or Full Version (519 tools) via the Pro add-on plugin.

== Description ==

**NV Digital Open Operator System (oOS)** is a comprehensive AI Assistant framework that transforms your WordPress site into an intelligent automation platform. Create custom AI assistants that can search content, generate media, manage operations, and interact with users through a modern chat interface.

The plugin works standalone with vanilla WordPress and can be extended with optional third-party plugin integrations (JetEngine, WooCommerce, Elementor) to unlock additional capabilities.

= Versions =

**Base Version (default — 165 core tools):** Active out of the box. Covers content management, media generation, research, site operations, analytics, MCP server, and more.

**Full Version (519 tools):** Unlocks all Pro add-ons. Install the separate **NV oOS Pro add-on** plugin alongside this plugin to enable the Full Version automatically.

Pro add-ons include WooCommerce e-commerce tools, JetEngine CPT/Taxonomy AI integration, social media publishing and analytics, GitHub integration, Google services, FFmpeg media processing, WP-CLI automation, and multi-agent orchestration.

**Important:** This plugin sends data to third-party AI services. Please review the [Privacy & Data Usage section](#privacy-policy) and each provider's terms before use:
* [OpenAI Terms of Service](https://openai.com/policies/terms-of-use) | [Privacy Policy](https://openai.com/privacy)
* [Google Gemini Terms](https://ai.google.dev/terms) | [Privacy](https://ai.google.dev/privacy)
* [Anthropic Terms](https://www.anthropic.com/legal/consumer-terms) | [Privacy](https://www.anthropic.com/legal/privacy)
* [Cloudflare Terms](https://www.cloudflare.com/terms/) | [Privacy](https://www.cloudflare.com/privacypolicy/)
* [Hugging Face Terms](https://huggingface.co/terms-of-service) | [Privacy](https://huggingface.co/privacy)
* Ollama (self-hosted, no external data transmission)
* LM Studio (self-hosted, no external data transmission)


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
* **Anthropic** - Claude 3.5 Sonnet, Claude 3 Opus ([Terms](https://www.anthropic.com/legal/consumer-terms) | [Privacy](https://www.anthropic.com/legal/privacy))
* **Cloudflare Workers AI** - Image generation models ([Terms](https://www.cloudflare.com/terms/) | [Privacy](https://www.cloudflare.com/privacypolicy/))
* **Hugging Face** - Dataset access and exploration ([Terms](https://huggingface.co/terms-of-service) | [Privacy](https://huggingface.co/privacy))
* **Ollama** - Privacy-focused local AI (self-hosted, no external data)
* **LM Studio** - Local AI with function calling (self-hosted, no external data)
* Automatic provider fallback for maximum uptime

**Built-in Tools:**
* **Content Tools** - Search posts, save drafts, manage attachments (15+ tools)
* **Media Generation** - AI images (OpenAI, Gemini, Cloudflare), text-to-speech, vectorization, graphic editing (10+ tools)
* **Research Tools** - Web search, weather, disaster alerts, Crawl4AI integration (8+ tools)
* **Site Operations** - Cache management, cron jobs, health checks, WP-CLI integration (12+ tools)
* **Analytics** - Token usage tracking, cost attribution, social media analytics (9+ tools)
* **JetEngine Integration** - AI metaboxes for CPTs/taxonomies, Research & Add pages with automatic field mapping (Pro tools)
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

* **JetEngine** (paid) - Advanced integration with AI-powered features
  - AI metaboxes for all JetEngine CPTs and taxonomies
  - Research & Add pages with automatic field mapping
  - Server-side chat transcript storage via CCT
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
* [REST API Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/api/rest-api.md)
* [Tool Reference](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/tools/tool-reference.md)
* [MCP Server Authentication](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/api/mcp-server-authentication.md)

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

Absolutely! NV oOS works perfectly with vanilla WordPress. JetEngine integration is optional and adds:
- AI metaboxes for all JetEngine custom post types and taxonomies
- Research & Add pages with automatic field mapping for AI-powered content creation
- Server-side chat transcript storage via CCT

Without it, chat history is stored in browser localStorage (24 hours).

= How do I connect Claude Desktop or LM Studio? =

NV oOS includes a full MCP server:
1. Generate API credentials from the assistant editor
2. Configure your MCP client with the credentials
3. Use endpoint: `https://yoursite.com/wp-json/mcp-ai/v1/`

See our [MCP Server Documentation](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/api/mcp-server-authentication.md) for detailed setup.

= Is this plugin GDPR compliant? =

NV oOS includes features to help with GDPR compliance:
* Activation tracking is opt-out and collects no PII (see External Services section)
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

See our [Tool Reference](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/tools/tool-reference.md) for examples.

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
4. **Tool Registry** - 165+ base tools for content, media, research, and operations
5. **Profession Templates** - 182 pre-built profession templates for quick assistant creation
6. **MCP Server** - Connect Claude Desktop, LM Studio, and other MCP clients

== Changelog ==

= 1.1.3 - March 2026 =

**WordPress.org Compliance — Final Audit**

* Added `esc_attr()` escaping to 5 unescaped CSS class attribute echoes in admin pages (profession settings, team settings, slash commands dashboard, orchestration dashboard)
* Added `phpcs:ignore` with documented justification for safe JSON/CSS/script buffer echoes
* Added `ABSPATH` exit guard to 4 PHP files that were missing it (toolkit-metadata-mapping.php, filesystem-service.php, process-service.php, validator-service.php)
* Removed last hardcoded admin menu position (85 → null) from Pro Dashboard `add_menu_page()` call
* Reviewed and confirmed Telegram Mini App media tab changes (PR #4004) are fully compliant: `pathinfo()` cast to string, JS uses `escHtml()`, CSS changes only
* WordPress.org submission compliance status: **100% — READY FOR SUBMISSION**

**Telegram Mini App**

* Media tab now shows file-type extension badge (`.TXT`, `.PDF`, `.DOCX`, etc.) overlaid on file-type icon for non-renderable files
* Adds `ext` field (lowercase extension) to all `handle_media()` REST responses
* New CSS classes: `.tma-media-icon-emoji`, `.tma-media-ext-badge` (monospace pill with WCAG-compliant contrast)
* File icon layout updated to `flex-direction:column` for icon/badge stacking

= 1.1.2 - February 2026 =

**WordPress.org Compliance Fixes**

* Removed hardcoded admin menu positions from 5 locations (Assistant CPT, Team CPT, Profession CPT, AI Peer CPT, Main Admin Menu)
* Changed menu_position values from fixed numbers to null for automatic positioning
* Prevents conflicts with other plugins per WordPress.org guidelines
* Moved pro-only integration settings to pro addon (Mailjet, Google Analytics, Yahoo Fantasy, ESPN Fantasy)
* Base plugin now only includes settings for base tools
* Better architecture: Settings match tool location

**JetEngine CPT/Taxonomy AI Integration**

* AI Assistant Metaboxes: Automatically adds AI assistant metabox to all JetEngine CPT and taxonomy edit screens
* Research & Add Pages: Creates dedicated Research & Add submenu pages for each JetEngine CPT with AI-powered content creation
* Automatic Field Mapping: Dynamically maps all JetEngine meta fields (text, select, media, gallery, repeater, etc.) to form inputs
* Version Compatibility: Full support for JetEngine 3.7+ with compatibility layer for different API versions
* Settings: Two independent toggles - "Enable AI Assistant for JetEngine CPTs" and "Enable Research & Add Pages for JetEngine CPTs"

**Package Pre-Bundling System**

* Enhanced vendor directory pre-bundling for critical npm packages
* Added pdf-lib, puppeteer-core, pdfkit, docx, exceljs, qrcode, turndown, cheerio
* Eliminates need for npm install on production servers
* Faster deployment with packages ready out-of-the-box

**Product Research Page Fixes**

* Fixed admin hook detection pattern causing CSS/JS not to load on Product Consolidate page
* Fixed all workflow tabs displaying simultaneously
* Improved asset enqueuing priority and hook detection
* Removed duplicate "Research & Add" tab

**Pro Workflow Builder Stability**

* Fixed React asset loading and initialization issues
* Fixed double instantiation causing duplicate DOM elements
* Fixed initialization timing race conditions
* Fixed menu placement inconsistencies
* Fixed empty page display issue

**OAuth & API Connection Fixes**

* Fixed Google OAuth approval prompt not displaying to users
* Fixed Yahoo OAuth redirect URL construction issues
* Fixed Mailjet API authentication credential handling

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

**IMPORTANT:** This plugin connects to various third-party services to provide AI functionality and optional features. All external services used by this plugin are documented below.

**📖 Additional Details:** For supplementary documentation about data transmission and legal requirements, see our [External Services Reference](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/EXTERNAL_SERVICES.md).

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

**2a. Google Gemini Semantic Retrieval API (Corpus / RAG)**
* **Purpose:** Native Retrieval-Augmented Generation (RAG) — store and query document corpora for grounded AI responses
* **Data Sent:** Corpus display names, document content uploaded to corpora, natural-language query strings; only transmitted when a corpus is configured for the assistant and the user sends a message
* **When:** Only when a Gemini assistant has a corpus name configured (optional feature, off by default)
* **Service URL:** https://generativelanguage.googleapis.com/v1beta/corpora
* **Terms of Service:** https://ai.google.dev/terms
* **Privacy Policy:** https://ai.google.dev/privacy

**3. Anthropic API (Claude)**
* **Purpose:** Core AI functionality (chat, vision, document analysis)
* **Data Sent:** Chat messages, system prompts, file attachments, tool results
* **When:** Every time an AI assistant is used with Anthropic as the provider
* **Service URL:** https://api.anthropic.com/v1/messages
* **Terms of Service:** https://www.anthropic.com/legal/consumer-terms
* **Privacy Policy:** https://www.anthropic.com/legal/privacy
* **Data Usage:** Anthropic does not train models on API data

**4. Ollama (Self-Hosted)**
* **Purpose:** Privacy-focused local AI processing
* **Data Sent:** None (runs entirely on your server)
* **When:** When configured as AI provider
* **Service URL:** Your local server only
* **Privacy:** No external data transmission

**5. LM Studio (Self-Hosted)**
* **Purpose:** Local AI with function calling support
* **Data Sent:** None (runs entirely on your computer)
* **When:** When configured as AI provider
* **Service URL:** Your local computer only
* **Privacy:** No external data transmission

**6. Cloudflare Workers AI**
* **Purpose:** AI image generation and inference
* **Data Sent:** Image generation prompts, model inference requests
* **When:** When using Cloudflare AI tools
* **Service URL:** https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/run/{model}
* **Terms of Service:** https://www.cloudflare.com/terms/
* **Privacy Policy:** https://www.cloudflare.com/privacypolicy/

= Optional Third-Party Service Integrations =

These services are only contacted when specific tools/features are used:

**7. Hugging Face API**
* **Purpose:** Access to public machine learning datasets and AI model inference (text-to-speech, chat)
* **Data Sent:** Dataset queries and filters; text or chat prompts for model inference
* **When:** When dataset exploration tools or Hugging Face AI inference tools are used
* **Service URL:** https://router.huggingface.co/v1 (Inference Router / chat completions), https://datasets-server.huggingface.co (Datasets Server), https://api-inference.huggingface.co/models/ (legacy inference, if configured)
* **Terms of Service:** https://huggingface.co/terms-of-service
* **Privacy Policy:** https://huggingface.co/privacy

**8. Brave Search API**
* **Purpose:** Web search functionality for AI assistants
* **Data Sent:** Search queries provided by users or AI
* **When:** When the web search tool is called by an assistant
* **Service URL:** https://api.search.brave.com/res/v1/web/search
* **Terms of Service:** https://brave.com/terms-of-use/
* **Privacy Policy:** https://brave.com/privacy/browser/

**9. Open-Meteo Weather API**
* **Purpose:** Weather forecasts and historical weather data
* **Data Sent:** Location coordinates or city names
* **When:** When weather tools are used
* **Service URL:** https://api.open-meteo.com
* **Terms of Service:** https://open-meteo.com/en/terms
* **Privacy Policy:** https://open-meteo.com/en/terms (includes privacy information)

**10. ReliefWeb API**
* **Purpose:** Humanitarian disaster and emergency reports
* **Data Sent:** Search queries for disaster reports
* **When:** When ReliefWeb tools are used
* **Service URL:** https://api.reliefweb.int/v1/reports
* **Terms of Service:** https://reliefweb.int/terms-conditions
* **Privacy Policy:** https://reliefweb.int/privacy-policy

**11. WordPress.org API**
* **Purpose:** PHP version compatibility check for site health
* **Data Sent:** Current PHP version number
* **When:** When site health tools are called
* **Service URL:** https://api.wordpress.org/core/serve-happy/1.0/
* **Terms of Service:** https://wordpress.org/about/privacy/
* **Privacy Policy:** https://wordpress.org/about/privacy/

**12. Chart.js**
* **Purpose:** Chart visualization library for displaying data
* **Data Sent:** None — Chart.js is bundled locally within the plugin; no external CDN is contacted
* **When:** When chart generation tools create visualizations
* **Service URL:** N/A — Chart.js v4.5.1 is included locally in `assets/js/vendor/chart.min.js`
* **Terms of Service:** https://github.com/chartjs/Chart.js/blob/master/LICENSE.md (MIT)
* **Privacy Policy:** N/A — no external connection made

**13. DuckDuckGo Instant Answer API**
* **Purpose:** Fallback web search and instant answers
* **Data Sent:** Search queries
* **When:** When the web search tool uses DuckDuckGo as a search provider
* **Service URL:** https://api.duckduckgo.com/
* **Terms of Service:** https://duckduckgo.com/terms
* **Privacy Policy:** https://duckduckgo.com/privacy

**14. National Hurricane Center (NHC / NOAA)**
* **Purpose:** Active tropical storm and hurricane data
* **Data Sent:** None (read-only public data retrieval)
* **When:** When the NHC active storms tool is used
* **Service URL:** https://www.nhc.noaa.gov/CurrentStorms.json
* **Terms of Service:** https://www.weather.gov/disclaimer
* **Privacy Policy:** https://www.weather.gov/privacy

**15. Cloudflare API (Zone and Cache Management)**
* **Purpose:** CDN cache purging and DNS zone verification
* **Data Sent:** Zone IDs, cache purge requests, bearer token authentication
* **When:** When Cloudflare cache purge tools or zone verification features are used
* **Service URL:** https://api.cloudflare.com/client/v4/zones/
* **Terms of Service:** https://www.cloudflare.com/terms/
* **Privacy Policy:** https://www.cloudflare.com/privacypolicy/

**16. Gmail API (Google)**
* **Purpose:** Email search and retrieval for AI assistants
* **Data Sent:** OAuth access tokens, search queries, label filters
* **When:** When Gmail search tools are used after OAuth setup
* **Service URL:** https://gmail.googleapis.com/gmail/v1/
* **Terms of Service:** https://policies.google.com/terms
* **Privacy Policy:** https://policies.google.com/privacy

**17. remove.bg API**
* **Purpose:** Background removal from images
* **Data Sent:** API key for account verification; images for background removal
* **When:** When the remove background tool is used
* **Service URL:** https://api.remove.bg/v1.0/
* **Terms of Service:** https://www.remove.bg/terms-of-service
* **Privacy Policy:** https://www.remove.bg/privacy

**18. Flowhub API**
* **Purpose:** Cannabis dispensary inventory and compliance management
* **Data Sent:** Client ID, API key, inventory queries
* **When:** When Flowhub inventory or compliance tools are used
* **Service URL:** https://api.flowhub.co
* **Terms of Service:** https://flowhub.com/terms-of-service
* **Privacy Policy:** https://flowhub.com/privacy-policy

**19. Plaid API**
* **Purpose:** Financial data integration and bank account connectivity testing
* **Data Sent:** Client ID, secret key, connection test requests
* **When:** When Plaid connection testing or financial tools are used
* **Service URL:** https://sandbox.plaid.com / https://production.plaid.com
* **Terms of Service:** https://plaid.com/legal/
* **Privacy Policy:** https://plaid.com/legal/privacy-policy

**20. PayHere API**
* **Purpose:** Payment processing for Sri Lankan merchants
* **Data Sent:** Merchant credentials, payment data
* **When:** When PayHere payment tools are used
* **Service URL:** https://www.payhere.lk/merchant/v1/
* **Terms of Service:** https://www.payhere.lk/terms
* **Privacy Policy:** https://www.payhere.lk/privacy

**21. Auth0 API**
* **Purpose:** Enterprise authentication and user management via Auth0
* **Data Sent:** OAuth tokens, user subject identifiers
* **When:** When Auth0 integration is configured for authentication
* **Service URL:** https://{your-auth0-domain}/api/v2/
* **Terms of Service:** https://auth0.com/web-terms
* **Privacy Policy:** https://auth0.com/privacy

**22. Mubert Music API**
* **Purpose:** AI-generated music and audio track creation
* **Data Sent:** Music generation parameters (tempo, genre, duration), API key
* **When:** When music generation tools are used
* **Service URL:** https://music-api.mubert.com/api/v3/public/tracks
* **Terms of Service:** https://mubert.com/corporate/terms
* **Privacy Policy:** https://mubert.com/corporate/privacy

**23. GDACS (Global Disaster Alert and Coordination System)**
* **Purpose:** Global disaster and emergency event data retrieval
* **Data Sent:** None (read-only public data retrieval)
* **When:** When the GDACS disaster events tool is used
* **Service URL:** https://www.gdacs.org/gdacsapi/api/events/geteventlist/MAP
* **Terms of Service:** https://www.gdacs.org/About/termofuse.aspx
* **Privacy Policy:** https://www.gdacs.org/About/privacy.aspx

**24. Google Maps Platform API**
* **Purpose:** Geocoding, place search, place details, and autocomplete
* **Data Sent:** Location queries, coordinates, place IDs, API key
* **When:** When location/mapping tools are used
* **Service URL:** https://maps.googleapis.com/maps/api/
* **Terms of Service:** https://cloud.google.com/maps-platform/terms
* **Privacy Policy:** https://policies.google.com/privacy

**25. Meta / Facebook Graph API**
* **Purpose:** OAuth authentication and social media integration
* **Data Sent:** OAuth tokens, user queries, content data
* **When:** When Meta/Facebook integration is configured and used
* **Service URL:** https://graph.facebook.com/v18.0/
* **Terms of Service:** https://www.facebook.com/legal/terms
* **Privacy Policy:** https://www.facebook.com/privacy/policy/

= Plugin Services (NV Digital Solutions) =

**26. NV Digital Solutions Activation Tracking**
* **Purpose:** Anonymous plugin activation/deactivation analytics to understand usage patterns
* **Data Sent:** Hashed site URL (non-reversible SHA-256 HMAC using per-installation WordPress AUTH_KEY salt), plugin version, WordPress version, PHP version, locale, multisite status. No personally identifiable information is collected.
* **When:** On plugin activation and deactivation (opt-out available via settings or filter)
* **Service URL:** https://nvdigitalsolutions.com/api/plugin-tracking/activation
* **Terms of Service:** https://nvdigitalsolutions.com/terms
* **Privacy Policy:** https://nvdigitalsolutions.com/privacy-policy
* **Opt-Out:** Disable via Settings → NV oOS → "Disable activation tracking" or the `wp_mcp_ai_enable_usage_tracking` filter. Tracking is automatically skipped in local/development environments.

**27. NV Digital Solutions License Server**
* **Purpose:** Optional license validation for future premium add-on support
* **Data Sent:** License key, site URL, product identifier
* **When:** Only when a user manually enters and activates a license key
* **Service URL:** https://nvdigitalsolutions.com/api/licenses
* **Terms of Service:** https://nvdigitalsolutions.com/terms
* **Privacy Policy:** https://nvdigitalsolutions.com/privacy-policy

= Optional OAuth/Integration Services =

These services are only used if you explicitly configure OAuth integrations:

**28. GitHub API**
* **Purpose:** Repository management, code search, issue tracking
* **Data Sent:** OAuth tokens, repository queries, commit data
* **When:** When GitHub tools are used after OAuth setup
* **Service URL:** https://api.github.com
* **Terms of Service:** https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* **Privacy Policy:** https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement

**29. Cloudways API**
* **Purpose:** Server management for Cloudways hosting customers
* **Data Sent:** OAuth tokens, server management commands
* **When:** When Cloudways tools are used after OAuth setup
* **Service URL:** https://api.cloudways.com/api/v1
* **Terms of Service:** https://www.cloudways.com/en/terms-of-service.php
* **Privacy Policy:** https://www.cloudways.com/en/privacy-policy.php

**30. QuickBooks API (Intuit)**
* **Purpose:** Accounting and financial data integration
* **Data Sent:** OAuth tokens, financial queries
* **When:** When QuickBooks tools are used after OAuth setup
* **Service URL:** https://appcenter.intuit.com/connect/oauth2
* **Terms of Service:** https://accounts.intuit.com/terms-of-service
* **Privacy Policy:** https://www.intuit.com/privacy/statement/

**31. Mailjet API**
* **Purpose:** Email marketing and transactional email
* **Data Sent:** OAuth tokens, email campaign data
* **When:** When Mailjet tools are used after OAuth setup
* **Service URL:** https://app.mailjet.com/oauth/authorize
* **Terms of Service:** https://www.mailjet.com/legal/terms-of-use/
* **Privacy Policy:** https://www.mailjet.com/privacy-policy/

**32. Tavily Search API**
* **Purpose:** AI-first web search purpose-built for LLM agents and RAG pipelines; returns structured results including page excerpts and publication dates
* **Data Sent:** Search query string; sent only when Tavily is selected as the web search provider
* **When:** When an AI assistant uses the `web_search` tool and the provider setting is set to "Tavily"
* **Service URL:** https://api.tavily.com/search
* **Terms of Service:** https://tavily.com/terms-of-use
* **Privacy Policy:** https://tavily.com/privacy-policy

**33. Exa AI Search API**
* **Purpose:** Neural/semantic web search purpose-built for AI agents; returns full-text page content and metadata
* **Data Sent:** Search query string and search parameters (number of results, content type)
* **When:** When an AI assistant uses the `web_search` tool and the provider setting is set to "Exa"
* **Service URL:** https://api.exa.ai/search
* **Terms of Service:** https://exa.ai/terms
* **Privacy Policy:** https://exa.ai/privacy

**34. Perplexity AI API**
* **Purpose:** AI-powered web search that returns synthesised answers with inline citations
* **Data Sent:** Search query string, model identifier
* **When:** When an AI assistant uses the `web_search` tool and the provider setting is set to "Perplexity"
* **Service URL:** https://api.perplexity.ai/chat/completions
* **Terms of Service:** https://www.perplexity.ai/hub/legal/terms-of-service
* **Privacy Policy:** https://www.perplexity.ai/hub/legal/privacy-policy

**35. Google Cloud Vision API**
* **Purpose:** Image annotation, product visual search, and object localisation using Google's pre-trained Vision models
* **Data Sent:** Base64-encoded image data and API key
* **When:** When the `vision_product_search` or `vision_object_localization` tools are used
* **Service URL:** https://vision.googleapis.com/v1/images:annotate
* **Terms of Service:** https://cloud.google.com/terms/
* **Privacy Policy:** https://policies.google.com/privacy

**36. Google Drive API**
* **Purpose:** Search and list files stored in a user's Google Drive
* **Data Sent:** OAuth access token and search query parameters
* **When:** When the `search_drive` tool is used after Google OAuth integration is configured
* **Service URL:** https://www.googleapis.com/drive/v3
* **Terms of Service:** https://policies.google.com/terms
* **Privacy Policy:** https://policies.google.com/privacy

**37. WordPress.com OAuth2 API (Gravatar)**
* **Purpose:** Validate WordPress.com / Gravatar bearer tokens to authenticate users against their WordPress.com or Gravatar profile
* **Data Sent:** OAuth bearer token (no user content)
* **When:** When the WordPress.com / Gravatar authentication integration is enabled and a bearer token is presented
* **Service URL:** https://public-api.wordpress.com/oauth2/userinfo
* **Terms of Service:** https://wordpress.com/tos/
* **Privacy Policy:** https://automattic.com/privacy/

**38. Yahoo OAuth2 API**
* **Purpose:** OAuth2 authorisation flow for Yahoo Fantasy Sports integration — exchanges an authorisation code for access/refresh tokens
* **Data Sent:** Client ID, client secret (base64-encoded in Authorization header), authorisation code, redirect URI
* **When:** Only when a user completes the Yahoo Fantasy Sports OAuth authorisation flow (optional integration, off by default)
* **Service URL:** https://api.login.yahoo.com/oauth2/get_token
* **Terms of Service:** https://legal.yahoo.com/us/en/yahoo/terms/otos/index.html
* **Privacy Policy:** https://legal.yahoo.com/us/en/yahoo/privacy/index.html

= Optional Browser-Native AI CDN Libraries =

The following libraries are loaded as external CDN connections directly in the visitor's browser (not server-side) when specific optional AI features are enabled. These are external service contacts and are disclosed here in accordance with WordPress.org Plugin Guidelines. No user chat data or personal information is transmitted — the browser only downloads a JavaScript library file.

**39. Transformers.js (jsDelivr CDN)**
* **Purpose:** Browser-native machine learning library enabling in-browser NLP tasks (summarisation, sentiment analysis, entity extraction, translation, semantic search) without sending data to a remote AI provider
* **Data Sent:** None — only the library file itself is downloaded; all inference runs locally in the visitor's browser
* **When:** Only when the "Browser-Native AI Tasks (Transformers.js)" feature is explicitly enabled by the administrator (disabled by default)
* **Service URL:** https://cdn.jsdelivr.net/npm/@xenova/transformers@2.17.2
* **Terms of Service:** https://www.jsdelivr.com/terms
* **Privacy Policy:** https://www.jsdelivr.com/privacy-policy-jsdelivr-net

**40. WebLLM — MLC AI (esm.run CDN)**
* **Purpose:** Browser-native large language model runner; enables the "Embedded Browser LLM" provider to perform inference entirely within the visitor's browser using WebGPU, with no server-side AI API call
* **Data Sent:** None — only the library file itself is downloaded; all inference runs locally in the visitor's browser
* **When:** Only when the "Embedded (Browser)" AI provider is selected for an assistant and the visitor's browser supports WebGPU (opt-in, off by default)
* **Service URL:** https://esm.run/@mlc-ai/web-llm (ESM CDN proxy for https://github.com/mlc-ai/web-llm)
* **Terms of Service:** https://esm.run/ (re-exports packages under their original licences; Apache 2.0 for web-llm)
* **Privacy Policy:** https://esm.sh/ (esm.run is powered by esm.sh — see https://esm.sh/privacy)

**41. LangChain Core (jsDelivr CDN)**
* **Purpose:** Optional LangChain orchestration primitives loaded client-side to support advanced multi-agent workflow coordination in the browser
* **Data Sent:** None — only the library file itself is downloaded; no user chat data is sent to jsDelivr
* **When:** Only when the experimental LangChain browser orchestration feature is in use (opt-in, off by default)
* **Service URL:** https://cdn.jsdelivr.net/npm/@langchain/core/+esm
* **Terms of Service:** https://www.jsdelivr.com/terms
* **Privacy Policy:** https://www.jsdelivr.com/privacy-policy-jsdelivr-net

= Data Processing Summary =

**What is sent to external services:**
* User messages and chat conversations (AI providers only)
* File uploads (AI providers only)
* Search queries (when using search/weather tools)
* OAuth credentials (when using optional integrations)
* Anonymous activation data (opt-out available; see service #26 above)

**What is NOT sent:**
* WordPress admin credentials
* Database contents (unless explicitly requested via tool)
* Site configuration (unless using diagnostic tools)
* Other user data not related to AI requests

**When data is sent:**
* Only when you or your users actively use AI features
* Only to services you have explicitly configured
* Anonymous activation/deactivation tracking on plugin lifecycle events (opt-out available)

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

**Activation Tracking (Opt-Out Available):**
* On plugin activation and deactivation, anonymous usage data is sent to NV Digital Solutions
* Data collected: hashed site URL (SHA-256 HMAC, non-reversible), plugin version, WordPress version, PHP version, locale, multisite status
* No personally identifiable information (PII) is collected or stored
* No tracking scripts, cookies, or beacons are used
* Opt-out: Disable via Settings → NV oOS → "Disable activation tracking" or the `wp_mcp_ai_enable_usage_tracking` filter
* Tracking is automatically skipped in local/development environments (localhost, .local, .test, .dev)

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
* Corpus/RAG feature: When a Gemini assistant has a corpus configured, document content and queries are also sent to https://generativelanguage.googleapis.com/v1beta/corpora (Semantic Retrieval API)

**Anthropic (when configured):**
* Data sent to: https://api.anthropic.com/v1/messages
* Processed according to: [Anthropic Privacy Policy](https://www.anthropic.com/legal/privacy)
* Terms of Service: [Anthropic Terms](https://www.anthropic.com/legal/consumer-terms)
* Data Usage: Anthropic does not train models on API data (Claude API)
* Review Anthropic's data retention policies before use

**Cloudflare Workers AI (when configured):**
* Data sent to: https://api.cloudflare.com
* Processed according to: [Cloudflare Privacy Policy](https://www.cloudflare.com/privacypolicy/)
* Terms of Service: [Cloudflare Terms](https://www.cloudflare.com/terms/)
* Used for: Image generation and AI inference
* Review Cloudflare's data handling policies before use

**Hugging Face (when configured):**
* Data sent to: https://router.huggingface.co/v1 (Inference Router), https://datasets-server.huggingface.co (Datasets Server)
* Processed according to: [Hugging Face Privacy](https://huggingface.co/privacy)
* Terms of Service: [Hugging Face Terms](https://huggingface.co/terms-of-service)
* Used for: Dataset access, exploration, and AI model inference (chat, text-to-speech)
* Review Hugging Face's data policies before use

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

**Web Search Providers (when configured):**
* Brave Search: data sent to https://api.search.brave.com — [Privacy](https://brave.com/privacy/browser/) | [Terms](https://brave.com/terms-of-use/)
* Tavily: data sent to https://api.tavily.com — [Privacy](https://tavily.com/privacy-policy) | [Terms](https://tavily.com/terms-of-use)
* Exa AI: data sent to https://api.exa.ai — [Privacy](https://exa.ai/privacy) | [Terms](https://exa.ai/terms)
* Perplexity: data sent to https://api.perplexity.ai — [Privacy](https://www.perplexity.ai/hub/legal/privacy-policy) | [Terms](https://www.perplexity.ai/hub/legal/terms-of-service)
* DuckDuckGo: data sent to https://api.duckduckgo.com — [Privacy](https://duckduckgo.com/privacy) | [Terms](https://duckduckgo.com/terms)

**Google Cloud Vision API (when vision tools are used):**
* Data sent to: https://vision.googleapis.com
* Processed according to: [Google Privacy Policy](https://policies.google.com/privacy)
* Terms of Service: [Google Cloud Terms](https://cloud.google.com/terms/)
* Used for: Image annotation, product visual search, and object localisation

**Google Drive API (when search_drive tool is used):**
* Data sent to: https://www.googleapis.com/drive/v3
* Processed according to: [Google Privacy Policy](https://policies.google.com/privacy)
* Terms of Service: [Google Terms](https://policies.google.com/terms)
* Used for: Search and listing of files in a user's Google Drive (requires OAuth setup)

**WordPress.com OAuth2 / Gravatar (when configured):**
* Data sent to: https://public-api.wordpress.com/oauth2/userinfo
* Processed according to: [Automattic Privacy Policy](https://automattic.com/privacy/)
* Terms of Service: [WordPress.com Terms](https://wordpress.com/tos/)
* Used for: Validating WordPress.com / Gravatar bearer tokens for user authentication

**Yahoo OAuth2 (when Yahoo Fantasy Sports integration is used):**
* Data sent to: https://api.login.yahoo.com/oauth2/get_token
* Processed according to: [Yahoo Privacy Policy](https://legal.yahoo.com/us/en/yahoo/privacy/index.html)
* Terms of Service: [Yahoo Terms of Service](https://legal.yahoo.com/us/en/yahoo/terms/otos/index.html)
* Used for: Exchanging an authorisation code for Yahoo OAuth2 access/refresh tokens (Fantasy Sports integration only)

**Browser-Native AI CDN Libraries (when optional features are enabled, client-side only):**
* Transformers.js (when "Browser-Native AI Tasks" feature is enabled): browser downloads library from https://cdn.jsdelivr.net/npm/@xenova/transformers — [jsDelivr Privacy](https://www.jsdelivr.com/privacy-policy-jsdelivr-net) | [Terms](https://www.jsdelivr.com/terms); no user chat data is sent to jsDelivr; all inference runs in the visitor's browser
* WebLLM (when "Embedded Browser LLM" provider is selected): browser downloads library from https://esm.run/@mlc-ai/web-llm — [esm.sh Privacy](https://esm.sh/privacy); no user chat data is sent; all inference runs locally via WebGPU
* LangChain Core (when browser orchestration feature is active): browser downloads library from https://cdn.jsdelivr.net/npm/@langchain/core — [jsDelivr Privacy](https://www.jsdelivr.com/privacy-policy-jsdelivr-net) | [Terms](https://www.jsdelivr.com/terms); no user chat data is sent to jsDelivr

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
* Anthropic API - [Privacy](https://www.anthropic.com/legal/privacy) | [Terms](https://www.anthropic.com/legal/consumer-terms)
* Ollama (self-hosted) - No external service
* LM Studio (self-hosted) - No external service

**Optional (for specific features):**
* Cloudflare Workers AI - [Privacy](https://www.cloudflare.com/privacypolicy/) | [Terms](https://www.cloudflare.com/terms/)
* Hugging Face - [Privacy](https://huggingface.co/privacy) | [Terms](https://huggingface.co/terms-of-service)
* Weather data - Open-Meteo API
* Web search - Brave Search, DuckDuckGo, Tavily, Exa AI, or Perplexity (provider must be configured)
* Image generation - OpenAI, Gemini, or Cloudflare
* Google Cloud Vision API - [Privacy](https://policies.google.com/privacy) | [Terms](https://cloud.google.com/terms/)
* Google Drive API - [Privacy](https://policies.google.com/privacy) | [Terms](https://policies.google.com/terms)
* WordPress.com OAuth2 (Gravatar) - [Privacy](https://automattic.com/privacy/) | [Terms](https://wordpress.com/tos/)
* Yahoo OAuth2 (Fantasy Sports) - [Privacy](https://legal.yahoo.com/us/en/yahoo/privacy/index.html) | [Terms](https://legal.yahoo.com/us/en/yahoo/terms/otos/index.html)

**Optional browser-native AI CDN libraries (no user data transmitted):**
* Transformers.js (browser-native AI tasks, opt-in) - loaded from jsDelivr CDN - [Privacy](https://www.jsdelivr.com/privacy-policy-jsdelivr-net) | [Terms](https://www.jsdelivr.com/terms)
* WebLLM / MLC AI (embedded browser LLM provider, opt-in) - loaded from esm.run CDN - [Privacy](https://esm.sh/privacy) | [Terms](https://esm.run/)
* LangChain Core (browser orchestration, opt-in) - loaded from jsDelivr CDN - [Privacy](https://www.jsdelivr.com/privacy-policy-jsdelivr-net) | [Terms](https://www.jsdelivr.com/terms)

For complete privacy, configure Ollama or LM Studio for fully local AI processing.

Review your chosen provider's privacy policy before use.

== Credits ==

Open Operator System is developed and maintained by [NV Digital Solutions](https://nvdigitalsolutions.com/).

Special thanks to the open source community and all contributors.
