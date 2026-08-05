=== NV Digital Open Operator System (oOS) ===
Contributors: nvdigitalsolutions
Donate link: https://nvdigitalsolutions.com/wpoos
Tags: ai assistant, openai, chatbot, mcp, automation
Requires at least: 6.0
Tested up to: 6.10
Requires PHP: 7.4
Stable tag: 1.1.45
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI Assistant framework with 13 AI providers: OpenAI, Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi, DigitalOcean, NVIDIA NIM, Cloudflare, Hugging Face, LM Studio & Ollama. 250+ built-in tools.

== Submission Scope ==

This WordPress.org submission contains **only the base plugin** — every file in this ZIP lives outside the `addons/` directory of the upstream GitHub repository. The following addons are developed in the same repository but are **NOT** part of this submission and are distributed only via GitHub Releases (`-full.zip` artifact) or via separate sales channels:

* `addons/pro` — NV oOS Pro (PHP 8.1+ extension; sold separately)
* `addons/embedded` — Embedded device runtime
* `addons/fantasy-football` — Fantasy Sports tooling
* `addons/algorave`, `addons/canvas`, `addons/cornerstone3d`, `addons/graphify` — domain-specific add-ons
* `addons/docs-hub`, `addons/chat-spa`, `addons/canvas-toolkit`, `addons/document-editor`, `addons/media-studio`, `addons/toolkit-shell` — React Single-Page-App add-ons
* `addons/saas-controller`, `addons/cloud-worker` — server-side companion plugins

If a code reviewer sees a path beginning with `addons/` in any reported finding, that finding is out of scope for this submission. The release pipeline (`.github/workflows/release.yml`) builds two artifacts: a base-only ZIP (this submission) and a separate full ZIP for GitHub. Both `.distignore` and the workflow's rsync exclusion list assert `addons/` is absent before any upload. See `SUBMISSION.md` in the upstream repository for the full payload manifest.

== Description ==

**NV Digital Open Operator System (oOS)** is a comprehensive AI Assistant framework that transforms your WordPress site into an intelligent automation platform. Create custom AI assistants that can search content, generate media, manage operations, and interact with users through a modern chat interface.

The plugin works standalone with vanilla WordPress and can be extended with optional third-party plugin integrations (JetEngine, WooCommerce, Elementor) to unlock additional capabilities.

= Versions =

**Base Plugin (PHP 7.4+):** Works out of the box on any PHP 7.4+ installation. Includes all tools shipped in `includes/tools/` — currently 250+ tools covering content management, media generation, research, site operations, analytics, MCP server, and more. Tools that integrate with optional third-party plugins (WooCommerce, JetEngine, Elementor, etc.) are also included and activate automatically when those plugins are detected; no Pro addon is required to use them. **All base plugin features are fully available without any license key or paid upgrade.**

**Pro Addon (PHP 8.1+ required):** A completely separate plugin that **adds brand-new tools** not present in the base plugin. It is a genuine extension — not an upgrade that unlocks hidden base-plugin capabilities. Pro-only tools are built with modern PHP 8.1+ features (enums, readonly properties, named arguments, fibers) and include entirely new toolsets: advanced multi-agent orchestration, autonomous research pipelines, project management, vault/secret management, real-time collaboration, Shopify catalog, medical imaging, CRM integrations, and more. Installing the Pro addon does not change how any existing base plugin tool works.

**How they relate:** The base plugin is complete and fully functional on its own. The Pro addon sits alongside it to add more tools — the same way a second plugin would. No tool in `includes/tools/` is hidden, locked, or limited without the Pro addon.

**Important:** This plugin sends data to third-party AI services. Please review the [Privacy & Data Usage section](#privacy-policy) and each provider's terms before use:
* [OpenAI Terms of Service](https://openai.com/policies/terms-of-use) | [Privacy Policy](https://openai.com/privacy)
* [Google Gemini Terms](https://ai.google.dev/terms) | [Privacy](https://policies.google.com/privacy)
* [Anthropic Terms](https://www.anthropic.com/legal/consumer-terms) | [Privacy](https://www.anthropic.com/legal/privacy)
* [Cloudflare Terms](https://www.cloudflare.com/terms/) | [Privacy](https://www.cloudflare.com/privacypolicy/)
* [Hugging Face Terms](https://huggingface.co/terms-of-service) | [Privacy](https://huggingface.co/privacy)
* [NVIDIA Terms](https://www.nvidia.com/en-us/about-nvidia/privacy-policy/) | [NIM Terms](https://www.nvidia.com/en-us/data-center/products/nvidia-ai-enterprise/eula/)
* [DeepSeek Terms](https://platform.deepseek.com/terms) | [Privacy](https://platform.deepseek.com/privacy)
* [OpenRouter Terms](https://openrouter.ai/terms) | [Privacy](https://openrouter.ai/privacy)
* [Kimi (Moonshot AI) Terms](https://platform.moonshot.ai/docs/policy/service-agreement) | [Privacy](https://platform.moonshot.ai/docs/policy/privacy-policy)
* [DigitalOcean Terms](https://www.digitalocean.com/legal/terms-of-service-agreement) | [Privacy](https://www.digitalocean.com/legal/privacy-policy)
* [Baseten Terms](https://www.baseten.co/terms-and-conditions/) | [Privacy](https://www.baseten.co/privacy-policy/)
* Ollama (self-hosted, no external data transmission)
* LM Studio (self-hosted, no external data transmission)


= Why oOS? =

Unlike simple chatbot plugins, oOS is a complete **AI orchestration system** designed for modern WordPress sites:

* **Comprehensive Tool Library** - Content management, media generation, research, site operations
* **Optional Integrations** - Enhanced features with WooCommerce, JetEngine, Elementor when installed
* **Multi-Provider Support** - OpenAI, Google Gemini, Anthropic, DeepSeek, OpenRouter, Baseten, Kimi (Moonshot AI), DigitalOcean Serverless Inference, NVIDIA NIM, Cloudflare, Hugging Face, Ollama (local AI), and LM Studio
* **MCP Server** - Standards-compliant Model Context Protocol server for Claude Desktop, LM Studio, and other AI clients
* **Enterprise Security** - Rate limiting, usage tracking, capability-based access control
* **Zero Lock-in** - Works with vanilla WordPress; optional integrations enhance functionality

= Key Features =

**AI Assistant Management**
* Create unlimited AI assistants with custom system prompts
* Per-assistant model configuration (temperature, max tokens)
* 296 pre-built profession templates across 17 industry categories
* One-click team deployments for coordinated AI workflows
* 16 pre-built Agent Skills (document editing, design, MCP server building, testing, and more) included in the base plugin — auto-installed on activation, fully customisable

**Multi-Provider AI Routing**
* **OpenAI** - GPT-4o, GPT-4, GPT-4o-mini ([Terms](https://openai.com/policies/terms-of-use) | [Privacy](https://openai.com/privacy))
* **Google Gemini** - Gemini Pro, Gemini 1.5 ([Terms](https://ai.google.dev/terms) | [Privacy](https://policies.google.com/privacy))
* **Anthropic** - Claude 3.5 Sonnet, Claude 3 Opus ([Terms](https://www.anthropic.com/legal/consumer-terms) | [Privacy](https://www.anthropic.com/legal/privacy))
* **Baseten** - Managed open-source LLMs (DeepSeek, GLM, Kimi) via OpenAI-compatible API ([Terms](https://www.baseten.co/terms-and-conditions/) | [Privacy](https://www.baseten.co/privacy-policy/))
* **Cloudflare Workers AI** - Image generation models ([Terms](https://www.cloudflare.com/terms/) | [Privacy](https://www.cloudflare.com/privacypolicy/))
* **Hugging Face** - Dataset access and exploration ([Terms](https://huggingface.co/terms-of-service) | [Privacy](https://huggingface.co/privacy))
* **NVIDIA NIM** - Llama, Mistral, Nemotron via NVIDIA cloud inference ([Terms](https://www.nvidia.com/en-us/data-center/products/nvidia-ai-enterprise/eula/) | [Privacy](https://www.nvidia.com/en-us/about-nvidia/privacy-policy/))
* **Ollama** - Privacy-focused local AI (self-hosted, no external data)
* **LM Studio** - Local AI with function calling (self-hosted, no external data)
* **DeepSeek** - deepseek-chat, deepseek-reasoner, deepseek-coder ([Terms](https://platform.deepseek.com/terms) | [Privacy](https://platform.deepseek.com/privacy))
* **OpenRouter** - Unified gateway to 200+ models (OpenAI, Anthropic, Meta, Mistral and more) via one API key ([Terms](https://openrouter.ai/terms) | [Privacy](https://openrouter.ai/privacy))
* **Kimi (Moonshot AI)** - Kimi K2.7 Code, K2.6, K2.5, K2 with 256K context and tool calling ([Terms](https://platform.moonshot.ai/docs/policy/service-agreement) | [Privacy](https://platform.moonshot.ai/docs/policy/privacy-policy))
* **DigitalOcean Serverless Inference** - Llama, DeepSeek-R1, and more via DigitalOcean's OpenAI-compatible cloud inference API ([Terms](https://www.digitalocean.com/legal/terms-of-service-agreement) | [Privacy](https://www.digitalocean.com/legal/privacy-policy))
* Automatic provider fallback for maximum uptime

**Built-in Tools:**
* **Content Tools** - Search posts, save drafts, manage attachments (15+ tools)
* **Media Generation** - AI images (OpenAI, Gemini, Cloudflare), text-to-speech, vectorization, graphic editing (10+ tools)
* **Research Tools** - Web search, weather, disaster alerts, Crawl4AI integration (8+ tools)
* **Site Operations** - Cache management, cron jobs, health checks, WP-CLI integration (12+ tools)
* **Analytics** - Token usage tracking, cost attribution, social media analytics (9+ tools)
* **Multi-Agent Orchestration** - DeepSeek V4-inspired agent coordination with 9 specialized tools, A2A protocol for inter-agent communication, Agent Command Center dashboard

**Chat Interface**
* Modern, responsive chat UI
* Shortcode: `[mcp_ai_chat assistant="123"]`
* Floating chat bubble widget (Elementor + Gutenberg) — configurable position, size, animations
* Elementor widget support
* File attachments (images, PDFs, documents)
* Real-time streaming responses (SSE)
* Chat history persistence (24h localStorage)
* Sub-agent panel with live workflow tracking

**MCP Server (Model Context Protocol 2024-11-05)**
* Full JSON-RPC 2.0 implementation with batching support (up to 20 messages per batch)
* Connect Claude Desktop, LM Studio, and other MCP clients
* All 11 MCP methods: `initialize`, `ping`, `tools/list`, `tools/call`, `resources/list`, `resources/read`, `prompts/list`, `prompts/get`, `completion/complete`, `logging/setLevel`, `notifications/cancelled`
* Tool annotations (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`)
* Argument autocompletion for tools and prompts
* Session management via `Mcp-Session-Id` header (1h TTL)
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
* Activation tracking is opt-in and collects no PII (see External Services section)
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
4. **Tool Registry** - 250+ tools for content, media, research, and operations
5. **Profession Templates** - 296 pre-built profession templates for quick assistant creation
6. **MCP Server** - Connect Claude Desktop, LM Studio, and other MCP clients

== Changelog ==

= 1.1.45 - August 5, 2026 =

Bumped to 1.1.45 across plugin header, WP_MCP_AI_VERSION constant, readme.txt Stable tag, README.md, CHANGELOG.md, QUICK_REFERENCE.md, and DOCUMENTATION_INDEX.md. Tool count: ~265 base + ~1,237 Pro (~1,502 total; live registry authoritative).

**Self-Hosted OCR (Unlimited-OCR + DeepSeek-OCR), Embedded v0.2.0, AI Transparency, Graphify Ecosystem**

* **Self-Hosted OCR — Proposal 018 (17 files, +4,087 lines).** Unified vLLM client for Baidu Unlimited-OCR (93.23% OmniDocBench) and DeepSeek-OCR. New Pro tools: pro_unlimited_ocr (structured output, table/form extraction) and pro_batch_ocr (Action Scheduler). Structured extraction service. Embedded backend + health dashboard. Admin settings UI with Test Connection buttons.
* **Embedded Addon v0.2.0.** Voice tool calling, OpenMed healthcare tools, MCP abilities. OCR document ability via WordPress Abilities API.
* **AI Transparency & SGI Compliance (Proposal 017).** Regulatory compliance infrastructure for AI operations.
* **Comic Reader v0.2.0.** Enhanced format support and reading interface.
* **Graphify Ecosystem.** Standalone plugins: nvoos-graphify v1.0.1, nvoos-graphify-ai v1.0.0, nvoos-graphify-ai-platform v1.0.0.
* **Build Automation.** Auto-build nvdigital-oos WP.org packages on every release.

= 1.1.38 - July 10, 2026 =

Bumped to 1.1.38 across plugin header, WP_MCP_AI_VERSION constant, readme.txt Stable tag, README.md, CHANGELOG.md, QUICK_REFERENCE.md, ROADMAP.md, and DOCUMENTATION_INDEX.md. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live registry authoritative).

**Page Agent Addon, Pro SPA v2 Parity, User Memory Toggle, Tool Enhancements, Workflow Improvements**

* **Page Agent Addon v0.1.0.** AI-powered browser page control copilot powered by Alibaba Page Agent (MIT). Client-side only — no headless browser required. Shortcode, Elementor widget, REST endpoints, MCP tool bridge.
* **Pro SPA v2 — Major Parity & Polish.** Voice pipeline, tasks drawer, workflow tracker, file attachment upload to Media Library. Tool Shortcuts and Slash Commands drawers. Mobile hamburger sidebar. Speech/audio button fixes. Autoscroll fixes. Viewport height chain fixes. filemtime cache-busting. Assistant preloading. Model sync and auth bypass fixes. Conversation title improvements.
* **Per-User Chat Memory Preferences.** Users can toggle chat memory on/off from WordPress user profile.
* **create_post / save_post Enhancements.** Markdown-to-HTML conversion via new trait. Smart taxonomy suggestions. Block content corruption fix for non-post post types.
* **Workflow Blueprint & Schedule Improvements.** Existing-content awareness in Content Publisher and Keyword Pipeline blueprints. Blog schedule presets now check for duplicate content. Readable result delivery responses.
* **SPA Accessibility.** Annotation pills made clickable with meaningful screen-reader labels.

= 1.1.37 - July 8, 2026 =

Bumped to 1.1.37 across all version-bearing files. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live registry authoritative). Provider count: 15 first-class.

**JetEngine Meta Helper, Places Enrichment, RabbitMQ + Queue Infrastructure, Multi-Tenant DB Isolation, DSpark Admin UI, Crocoblock DS Addon, Test Coverage, Docs Hub Broken Link Engine**

* **JetEngine Meta Helper (Universal).** Unified REST exposure for all 25 Pro CPTs via show_in_rest and register_post_meta. ECA _eca_* and _student_* field registration. MCP routing and load-order fixes.
* **Places Toolkit Enrichment.** enrich_place_coordinates and enrich_place_details tools for batch geocoding. Social and booking fields added to Places CCT. HTTrack import fixes.
* **RabbitMQ Integration.** RabbitMQ client, queue manager, and async tool interception wired into the plugin. Queue storage migrated to custom DB tables with health endpoint and queue worker.
* **Multi-Tenant Database Isolation (Phase 0–4).** Database isolation primitives, query scoping, schema isolation, cross-tenant safety guards, and admin controls.
* **DSpark Admin UI & Orchestration.** Settings page, threshold configuration, efficiency dashboard, presets, and hook system.
* **Crocoblock Design System Addon.** All 5 phases: unified CSS custom properties, preset templates, admin-controlled theming.
* **Test Coverage Campaign.** 301 previously untested tools, 6 untested pro toolkits, 22 toolkits now covered.
* **Docs Hub Broken Link Engine.** Automated detection and repair with Accept fix buttons.
* **30+ Bug Fixes.** Shopify Catalog API, recurring sync, sync log batching, webhook auto-reply, import-blueprints, extended-cognition, tool test infrastructure, CRM/PM/DietPi/Cloudways test fixes, js-yaml CVE, ESLint/WPCS compliance.

= 1.1.36 - July 4, 2026 =

Bumped to 1.1.36 across all version-bearing files. Tool count: ~195 base + ~830+ Pro (~1,025+ total; live registry authoritative). Provider count: 15 first-class.

**EZuite Inventory Sync, Ralph Loop Orchestration, JetBooking/JetAppointment, Moonshot/Z.AI Parity, Sync Log Manager, Tool Presets, HTTrack Cache**

* **EZuite Inventory Sync Pro Toolkit.** ERP-integrated inventory sync: product pull, inventory query, item create/update, ERP settings, CLI sync commands.
* **Ralph Loop CCT Migration & Orchestration.** Circuit breaker pattern, execution logger, CCT migration tools.
* **JetBooking/JetAppointment Integration (8 tools).** Adapter layer for Crocoblock booking/appointment plugins.
* **Moonshot AI (Kimi) & Z.AI (GLM) Provider Parity.** Both providers upgraded to full DeepSeek-level chat client capabilities.
* **Unified Sync Log Manager.** Per-item audit trail with sync history, error tracking, and status dashboards.
* **Tool Presets Auto-Select & Chips Bar.** Selected tools display as clickable chips with +N overflow.
* **HTTrack Cache Support & Place-to-Service Bridge.** HTML mirror import supports HTTrack cache directories.
* **45+ Bug Fixes.** CCT module API mismatches, EZuite sync, FlowHub sync, Shopify sync, duplicate column errors, SQLite meta cache, HTTrack import, Necessity Gate, and more.

= 1.1.35 - June 29, 2026 =

Bumped to 1.1.35 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt` Stable tag, `README.md`, `CHANGELOG.md`, `QUICK_REFERENCE.md`, `ROADMAP.md`, and `DOCUMENTATION_INDEX.md`. Tool count: ~195 base + ~810+ Pro (~1,005+ total; live registry via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

**FlowHub Inventory Sync, Shopify Sync, Necessity Gate Layer J, Local Voice STT, Remote Site Admin Blueprint, Bulk Import, Fixes**

* **FlowHub Inventory Sync Pro Toolkit (PR #5501).** 6-tool cannabis dispensary management: products, inventory, locations, sync, analytics, alerts. P1 proxy support via `http_api_curl` hook + P2 CCT auto-registration (PR #5502). Auth, decryption, location_id, null-guard fixes (PRs #5500, #5503, #5507, #5510).
* **Shopify Sync Pro Toolkit (PR #5502).** 5-tool bi-directional e-commerce sync: products, orders, inventory, analytics, settings. Dashboard widget. Tool reference docs.
* **Necessity Gate Layer J.** Irreversibility-weighted safety profiles scoring tool calls by risk before execution. Safety profile trait. Request context crash fix.
* **Local Voice Embedded STT (PR #5498).** Three pluggable browser-side STT backends (Web Speech, Whisper.cpp WASM, Vosk WASM). Offline-first architecture.
* **Remote Site Administrator Blueprint.** 22-tool assistant blueprint for full remote/local WP/WooCommerce management with JetEngine, JetFormBuilder, and REST API control.
* **Places & Calendar Bulk Import (PR #5509).** Batch import tools for Places and Calendar Booking toolkits.
* **CLI site-import Subcommand.** Multi-phase HTML mirror import for migrating static sites into WordPress.
* **Voice Realtime Auto-Detect (PR #5508).** WebRTC/WebSocket auto-selection, duplicate message fix, VAD improvements.
* **Remote Connections Fixes (PR #5499).** WordPress case handling, FlowHub/Printful storage, Printful connection type.
* **Bug Fix — Token-Scoped Assistant Resolution (PR #5497).** Token-scoped assistant preferred over site default.
* **Bug Fix — User ID Empty Fallback (PR #5495).** `user_id` fallback uses `empty()` instead of `isset()`.
* **Bug Fix — Local Credential Token Mapping (PR #5493).** Identity resolution consistency fix.
* **Bug Fix — Post Type Name Lengths (PR #5484).** Exceeding 20-char limit now truncated with warnings.
* **Bug Fix — OpenAI Image Deprecation Cleanup (PRs #5489–#5491).** Removed deprecated DALL-E models, applied chat model fallback, fixed defaults.
* **Documentation.** Agent context sync — AGENTS.md, CLAUDE.md, .context/pro-vs-base.md updated.
* **Housekeeping.** Stale 1.1.34 build zips removed (6 files).

= 1.1.34 - June 27, 2026 =

Bumped to 1.1.34 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt` Stable tag, `README.md`, `CHANGELOG.md`, `DOCUMENTATION_INDEX.md`, `QUICK_REFERENCE.md`, and `ROADMAP.md`. Tool count: ~195 base + ~795 Pro (~990 total; live registry via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

**GPT-Realtime-2 Voice Models, Multi-Channel Result Delivery UI, Graphify Ecosystem, Bug Fixes**

* **GPT-Realtime-2 Voice Models Upgrade (PR #5479).** Migrated from deprecated beta to GA Realtime API endpoints. WebRTC transport as primary (WebSocket fallback preserved) with ephemeral token minting and SDP relay. GPT-Realtime-2 model with 128K context and 5-level configurable reasoning effort. 12-section structured prompt template with per-section filter hooks. 2 new models: GPT-Realtime-Translate (70+ input → 13 output languages) and GPT-Realtime-Whisper (streaming STT). New `wait_for_user` tool for silence/noise handling. PTT mode extended to WebRTC. Commentary phase support. 7 new admin settings defaults. Per-session ICE reconnection with exponential backoff.
* **Multi-Channel Result Delivery UI (PR #5465).** Telegram, Discord, WhatsApp, and Google Chat channels added to schedule edit modal Result Delivery section. All 11 delivery channels now exposed in admin UI (up from 4: Email, Slack, Paper Store, WordPress Post).
* **Pro Scheduler AI/Workflow Response Delivery (PR #5466).** AI/workflow-generated responses routed through the pro scheduler delivery pipeline for richer formatting and multi-step AI output delivery.
* **Graphify Ecosystem Enhancements (PRs #5475–#5480).** Standalone remote source drivers with Bridge class for nvoos-graphify standalone plugin (PRs #5476, #5477). WP 7.0 Connectors credential resolution integrated into graphify ecosystem (PR #5478). wp.org compliance sweep: escape, crypto, webhook, config, truncate, and readme fixes for nvoos-graphify (PR #5480). Plugin Sources tab and Remote Sources UI fix (PR #5475).
* **API/Web Search Mode Toggle for Upwork & LinkedIn (PR #5473).** New `mode` parameter on Upwork and LinkedIn remote connections: `api` (direct API access) or `web_search` (browser-based search). Allows fallback to web search when API credentials are unavailable.
* **Bug Fix — 3 Reasoning Tools.** `enable_reasoning_mode`, `analyze_code_sequence`, and `validate_reasoning_chain` called non-existent `$this->success()` causing PHP fatal errors. Replaced with canonical `format_chat_response()`. Also fixed `trim()` on array TypeError in `validate_reasoning_chain` and added `count()` on null guard in `get_environment_status`.
* **Bug Fix — CRM Pipeline (PRs #5474, #5469).** Deal import and Gmail source pipeline fixed. Configured sources count corrected and multi-source auto-import pipeline added.
* **Bug Fix — Docs Hub (PRs #5472, #5468).** REST fatal error and plain-permalink URL bug resolved. Settings sync reliability improved, rebuild reliability hardened, repo lookup logic fixed.
* **Bug Fix — nv-cloud-init (PR #5470).** `require_once` for nv-cloud-init.php now guarded with `file_exists()` check to prevent fatal errors when the file is absent.
* **Bug Fix — wait_for_user Tool.** Base class loading ensured before extending and invalid base class extension removed.
* **Security.** http-proxy-middleware CVE-2026-55602 CRLF injection fix (PR #5464). Gemini prompt caching `cache_control` error resolved (PR #5463). GPT image models correctly routed to Responses API (PR #5461).
* **Documentation.** GPT-Realtime-2 upgrade proposal + 1,166-line implementation plan. FastAPI porting implementation plan (PR #5467). Multi-channel result delivery enhancement proposal. Voice feature guide and wait_for_user tool reference added.
* **Housekeeping.** Stale build artifacts and `build/toolkit-addons` directory removed.

= 1.1.33 - June 24, 2026 =

Bumped to 1.1.33 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt` Stable tag, `README.md`, and `CHANGELOG.md`. Tool count: ~195 base + ~795 Pro (~990 total; live registry via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

**WP 7.0 Connectors Credential Integration, nvoos-graphify v1.0.0, Security Fixes**

* **WP 7.0 Connectors Credential Integration (PR #5458).** Credential_Resolver integrated into all 17 AI client `get_api_key()` methods. Fallback chain: WP 7.0 Connectors → plugin settings → env vars → PHP constants. `get_key_source()` / `get_key_source_label()` added to Credential_Resolver. Credential source badges and WP 7.0 Connectors hints rendered in admin settings UI. All 13 provider API key field descriptions updated to mention alternative sources. Provider diagnostics now show key source column. Settings health check counts credentials via resolver.
* **nvoos-graphify v1.0.0 Release (PR #5456).** Standalone nvoos-graphify plugin released at v1.0.0 (Plugin Check compliant). nvoos-graphify-ai plugin released at v1.0.0-dev. Fixed 8 output-escaping errors and critical `->prepare()` spread-operator bug in `Db::listNodes()`. Renamed `vector` column → `embedding_vector` to avoid MariaDB/MySQL reserved-word conflict; bumped DB version. Fixed snake_case→camelCase method calls across tools, controllers, and cross-plugin integrations. Documented actual REST access model: read + guest token for reads, `manage_options` for export/write.
* **Security Dependencies (PR #5457).** guzzlehttp/guzzle 7.10.0 → 7.12.1 (CVE-2026-55568, CVE-2026-55767). guzzlehttp/psr7 2.11.0 → 2.12.1 (CVE-2026-55766). guzzlehttp/promises 2.3.0 → 2.5.0. undici npm override tightened from `>=7.28.0` to `>=8.5.0`.
* **npm Security (PR #5438).** 29 npm alerts resolved across 14 packages: undici (TLS bypass CVE-2026-9697, cache info disclosure CVE-2026-9678), http-proxy-middleware (CRLF injection CVE-2026-55603), nodemailer (raw option bypass GHSA-p6gq-j5cr-w38f), webpack-dev-server (HMR interception CVE-2026-9595), dompurify (ALLOWED_ATTR pollution GHSA-cmwh-pvxp-8882). Fixed critical duplicate `overrides` key in root `package.json`.
* **Bug Fix — WP All Import/Export Pro Tools.** Fixed four missing `-pro-` in require_once paths in the Pro bootstrap, causing fatal errors when those tools loaded.
* **Bug Fix — Tool Status Label Loader.** Replaced `@file_get_contents()` with explicit `set_error_handler` to prevent leaked warnings from corrupting MCP JSON-RPC HTTP responses.
* **Dependencies.** 15 Dependabot bumps: composer (guzzlehttp/psr7), npm (eslint-plugin, stripe, zod, p-queue, csv-parse, react-query, puppeteer, vitest, workers-types, types/node, wrangler), GitHub Actions (codecov/codecov-action 4→7, softprops/action-gh-release 2→3).
* **Housekeeping.** Stale 1.1.31 and 1.1.32 build zips removed. SPA addon ZIPs rebuilt with updated security overrides.

= 1.1.32 - June 19, 2026 =

Bumped to 1.1.32 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `readme.txt` Stable tag, `README.md`, and `CHANGELOG.md`. Tool count: ~195 base + ~795 Pro (~990 total; live registry via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

**Content Format Templates, Result Delivery Pipeline, Featured Images, Provider Timeout Fixes**

* **Content Format Templates & Featured Image Generation (PR #5433).** New Content Format Template CPT. Content Template Engine with Anthropic-optimised AI prompts. Featured Image Service with 3-provider fallback (DALL-E/Gemini/Cloudflare) and 5 image styles. Image Generation Provider dropdown on CPT metabox. Provider image settings (model, quality, aspect ratio) respected instead of hardcoded defaults. 5 workflow presets updated with image gen nodes. `resolve_node_template_variables` for cross-node variable passthrough. 5 default templates seeded on activation.
* **Result Delivery Pipeline (PR #5425).** `WP_MCP_AI_Result_Delivery_Service` (1,056 lines) routes schedule results to 8 channels: email, Slack, Discord, Telegram, SMS, Paper Store, WordPress post, webhook. Both success and failure paths deliver. Pre-configured Paper Store delivery for 8 presets.
* **ECA Document Generation (PR #5423).** ECA Consolidate & Add page with document generation tools.
* **Duplicate Posts Fix (PR #5434).** WordPress delivery removed from `weekly_blog_post_writer` and `weekly_blog_topic_research` presets. Systemic guard skips WordPress delivery when AI tool calls include `create_post`/`save_post`.
* **6 Provider Clients Timeout Fix (PR #5431).** DeepSeek, Baseten, DigitalOcean, OpenRouter, Kimi, and Cloudflare now respect global `request_timeout` setting.
* **Schedule Trigger Stability (PRs #5429, #5430).** Trigger crash fixed with try/catch + REST check. Result delivery sanitizer preserves channels wrapper. AJAX handler captures PHP warnings that corrupt JSON.
* **Paper Store Delete Fix (PR #5432).** Hidden inputs added to delete confirmation form.
* **ECA Settings & Attachment Fix (PR #5421).** Menu placement and upload crash resolved.
* **npm CI & Jest Resilience (PR #5428).** Babel ESM override for Node 18/20. Jest graceful fallback. npm ci lockfile sync for 5 addons.
* **Dependencies.** 14 Dependabot bumps. 8 npm audit CVEs (nodemailer, tar, tar-fs). phpspreadsheet 5.7.0→5.8.0.

= 1.1.31 - June 17, 2026 =

Bumped to 1.1.31 across plugin header, constants, readme.txt, README.md, and CHANGELOG.md. Tool count: ~195 base + ~795 Pro (~990 total; live registry authoritative).

**Media Command Center, Pro SPA v2, Workflow Presets, npm CVE Fixes, Gemini 3.1 Flash**

* **Media Command Center (PR #5402).** Top-level NV Media admin menu with command center for media templates, presets, and blueprints.
* **Pro SPA v2 — Rich Rendering & Scoping (PRs #5401, #5412, #5414, #5416).** Ported to feature parity. Rich markdown rendering, per-assistant scoping, agent selector. Conversations primary, threads read-only. Version 2.0.1.
* **34 Workflow Preset Tools (PR #5418).** All missing presets implemented across 10 toolkits. PHPCS compliance and test suite for 36 tools.
* **npm Audit CVE Fixes (PR #5419).** 12 CVEs (vite, launch-editor, markdown-it, ws, js-yaml, form-data, hono, dompurify, babel, opentelemetry, joi). Root audit: 51→0.
* **Gemini 3.1 Flash Image (PR #5404).** Default image model upgraded.
* **Media Toolkit Blueprints (PRs #5398, #5411).** Blueprints and presets synced to Data Management.
* **OpenAI/DeepSeek stream_options (PR #5400).** Streaming usage tracking enabled.
* **Agentic-Loop Cost Tracking (PRs #5394, #5395).** Tool result costs and provider pricing fixed.
* **Vite CVEs (PR #5403).** vite ^8.0.16 for CVE-2026-53571 / CVE-2026-53632.
* **1,658 PHPCS Lint Fixes (PR #5397).** Across base + pro (44 toolkits).
* **CI Disk Space (PR #5417).** Free disk space step in build workflows.
* **Data Integrity Fixes.** PII pseudonymisation restored (PR #5398). CPT type mismatch resolved (PR #5396).

= 1.1.30 - June 15, 2026 =

Bumped to 1.1.30 across manifests. Tool count: ~195 base + ~795 Pro (~990 total; live registry authoritative).

**Chat SPA Phase 8, PM Toolkit A–D, CRM Duplicates/Hygiene, DietPi Toolkit, LibreChat, Context Windows, WP 7.0 Bridge**

* **Chat SPA Phase 8 (PRs #5381, #5383, #5390).** Conversations sidebar. Auto-create thread. Message actions: edit, delete, regenerate, copy, content enrichment.
* **PM Toolkit A–D (PR #5370).** Shared PM Engine. Command Center dashboard. 28 new AI tools.
* **CRM Duplicates & Hygiene (PRs #5362, #5367, #5368).** Duplicate detection/merge. Email hygiene. Top Customers/Clients analytics. LinkedIn & Upwork sourcing.
* **DietPi Pro Toolkit (PRs #5346, #5348, #5350).** 19+ tools for DietPi server management. MCP server integration.
* **LibreChat Addon (PR #5336).** Code interpreter, speech services, web search reranker.
* **Layer I Guardrails (PRs #5340, #5344).** Jailbreak prevention. Configurable per-assistant.
* **Context Window Management (PRs #5335, #5348, #5352).** Pre-flight validation for all 13 providers. tiktoken integration. Token-budget tool capping.
* **WP 7.0 Bridge (PR #5387).** Forward-compatible provider credential bridge.
* **Chat Transcript & Memory Retention (PRs #5356, #5357).** TTL-based cleanup (base). Agent memory lifecycle (Pro).
* **Pro Toolkit Optimizations (PRs #5355, #5356, #5357).** Performance across 6 toolkits.
* **OAuth Disconnect (PR #5351).** One-click disconnect with token clearing.
* **30+ Fixes.** SPA reliability sweep. CPT slug limits. Security CVEs (guzzlehttp/psr7, esbuild, shell-quote).

= 1.1.28 - June 8, 2026 =

Bumped to 1.1.28 across manifests. Tool count: ~195 base + ~765 Pro (~960 total; live registry authoritative).

**CRM Phase C, Customer CPT, Support Tickets, QKV Attention Routing, Graphify Ecosystem**

* **CRM Phase C (PRs #5290–#5310).** IMAP email polling, Twilio SMS, Meta WhatsApp, Gmail OAuth.
* **Customer CPT + 360 (PR #5324).** 5 CRUD tools. Customer Research & Add with 360 dashboard. Lead-to-customer conversion.
* **Support Ticket Module (PRs #5300–#5310).** 10 AI tools + SLA. Ticket lifecycle with cron.
* **QKV Attention Routing (PR #5315).** 5-head multi-head attention for semantic tool selection.
* **TF-IDF + BM25 Search (PR #5315).** Dual-algorithm relevance ranking.
* **Funiq Bridge Addon (PR #5240).** Payload-to-WordPress bridge with React admin SPA.
* **NVOOS Graphify Ecosystem (PR #5325).** 3 standalone plugins. 14 tools. Visual knowledge graph.
* **Demo Video Pipeline (PR #5325).** Scripted scene recording, AI voiceover, automated assembly.
* **CRM Enhancements.** Lead/company tables enriched. Data completeness KPI.
* **Documentation.** Folder READMEs for new CRM subdirectories. Compliance check errors resolved.

= 1.1.27 - June 5, 2026 =

Bumped to 1.1.27 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

**Real-Time SSE Streaming, 35 New OOS Core Tools, JFB Submission Fixes, and Model Pricing Update**

* **Real-Time SSE Streaming.** Enabled for OpenAI, DeepSeek, and all OpenAI-compatible providers. "Disable Native Streaming" setting in Advanced → System tab. `wp_mcp_ai_disable_native_streaming` filter. WPCS violations fixed in streaming clients.
* **35 New OOS Core Tools.** 35 tools migrated with full test coverage: Data Tools (GetPostTaxonomies, CountPosts, GetPostMeta, TruncateText, MergeArrays), Format Tools (FormatDate, TimeAgo, ParseCsv, MathEval, ColorConvert), Infrastructure (EventDispatcher, Queue), Cache Tools + DeleteSettingTool. OOS/core test infrastructure established.
* **Extended Cognition Vision Recognition.** Visual product/brand recognition. Camera viewfinder UI with detection overlays, consent gate, camera switcher, torch, scan region, and file upload.
* **Graphify Tools Fixes.** Missing `WP_MCP_AI_Tool_Default_Capability` trait added. Explicit `get_required_capability()` added to all Graphify tools.
* **JetFormBuilder Submission Tools — 8 Fixes.** Empty results for non-admin users resolved (direct DB fallback). Form discovery pipeline fixed. PHPCS warnings resolved. REST routes matched to actual JFB endpoints. Form-type auto-detection fixed for JFB vs Elementor. Plugin detection fixed with namespaced class. Integration reference docs added.
* **DeepSeek Agentic Tool Result Handling.** Tool message filtering and payload normalisation for agentic multi-turn workflows.
* **Documentation Fixes.** Broken doc links after Unix-theory reorganization resolved.
* **Model Pricing Update.** All 13 provider pricing updated to June 2026 rates.
* **Plugin Restructuring Proposals.** Graphify-core spec and base restructuring roadmap added. Proposals updated to v3.0 Graphify-centric architecture.

= 1.1.26 - June 3, 2026 =

Bumped to 1.1.26 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts: ~195 base + ~765 Pro (~960 total; live registry via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative).

**Cross-Platform Extraction Engine Phases 0–2 + Site-Builder Node-Graph + SPA a11y Hardening + Screenshot & Docs Overhaul**

* **Cross-Platform Extraction Engine (Phases 0–2).** Framework-agnostic OOS core in `lib/`: monorepo foundations, 8 domain interfaces with WordPress adapters, 12 AI provider clients, core application layer, AbstractTool base class, SkillRegistry, 33 migrated tools. OOS bridge with feature flag (`WP_MCP_AI_OOS_ENGINE_ENABLED`). Full proposal: `docs/project/proposals/cross-platform-extraction-architecture.md`.
* **Site-Builder Node-Graph Pipeline (Phases 1–4).** Visual site construction subsystem with node-graph architecture. SPA blueprint v3.0 from pipeline output.
* **SPA a11y Hardening Phase 5.** axe-core accessibility testing across all 7 SPA addons. Keyboard navigation, ARIA labels, focus management, screen-reader support.
* **Screenshot Overhaul.** 137 Playwright captures, 79 tracked pages, inventory + maintenance plan + coverage checker.
* **Docs Reorganization.** Unix-theory separation of concerns across `docs/`. Per-folder READMEs restored.
* **Form Submissions Data Source.** JFB + Elementor integration with admin dashboard. PHPUnit tests + PHPStan fixes.
* **Cloudways Dashboard SPA v0.1.0.** New React SPA addon.
* **Laravel & Craft CMS Adapters.** OOS core extraction adapters.
* **Blueprint Profession Roles.** 6 missing definitions + CRM/healthcare role assignments.
* **Pro Toolkits Security Audit Phase 1.** 9 HIGH-severity findings fixed.
* **OOS Engine Stability.** PSR-4 event classes, ErrorFactoryInterface imports, provider client imports, event dispatcher dependency, parse/CacheStore errors, Gemini tools fix, team/profession integration.
* **Reviewer Onboarding Docs.** Complete suite at `docs/project/FOR_REVIEWERS.md`.
* **Docker Dev Environments.** WordPress, Laravel, Craft CMS all fixed.
* **Test Infrastructure.** 95% of PHPUnit failures resolved across all suites.
* **Infrastructure Fixes.** TCPDF autoloader, Pro vendor files, puppeteer detection, NPM package status, vendor gitignore, shallow clone recommendation.

= 1.1.21 - May 20, 2026 =

Bumped to 1.1.21 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

**WordPress.org Compliance Multi-Sweep Hardening + Capability Fence P2b Full Rollout + Security Center + Model Catalog May 2026 Refresh**

* **WordPress.org Compliance Re-Audit (May 20).** All inline JS/CSS removed from 53 base-plugin files — converted to `wp_add_inline_style()` and `wp_print_inline_script_tag()`. PHP parse errors fixed (duplicate `<?php`, spurious `?>` tags) across 7 files. Findings F3/F5/F6/F7b resolved. Four new audit categories verified clean (dangerous-functions, superglobal-access, HTTP-timeout, inline-notice). Build pipeline excludes `.codex-wordpress` and `phpcs` from ZIPs. Pro Settings CSS loading restored.
* **Capability Fence P2b — Full Rollout.** `get_required_capability()` on `WP_MCP_AI_Tool_Interface` deployed to all ~830 tool classes. `WP_MCP_AI_Tool_Default_Capability` trait for test stubs. Central capability map with sanitized values. `WPMCPAI.Tools.RequiredCapabilityDeclared` PHPCS sniff at severity 5. Capability Fence Audit UI fixed.
* **Security Center.** 5-tab admin page: Posture (live scoring), Compliance Report, OTel Telemetry, Deprecated-Alias Tracking, MCP Token Inventory. REST endpoints + PHPUnit coverage.
* **Model Catalog May 2026 Refresh.** DeepSeek V4 model family. Gemini consolidation. May 2026 pricing across all providers. 88 WPCS lint errors resolved.
* **Domain Migration.** `nvoos.com` → `nvoos.pro` / `nvoos.cloud` (docs + ISO 27001 + cloud-worker).
* **Cloud Worker Local Dev Setup.** `README-LOCAL.md`, `scripts/seed-local.mjs`, `wp-config-local.php`.
* **Translation Loading Fix.** `NOOP_Translations` pre-population + deferred Security Audit CPT registration to `init`.
* **Unix Theory P5 Part 2.** `git_operations` → `git_inspect` + `git_change`.
* **Docs Hub + SaaS Controller fixes.** Non-JSON REST response guard. Base-plugin detection corrected.
* **Folder README Convention Phase P7.** Every `includes/` and `addons/pro/includes/` PHP subdirectory ships a `README.md` — completing Unix Theory P0–P7.

= 1.1.20 - May 18, 2026 =

Bumped to 1.1.20 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

**Memory Layer 2026 — Phase 7 UI/UX complete (7a/7b/7c)**

* **Phase 7a — Memory Health subtab.** Added Orchestration → Memory Health view with live status, threshold policy, memory budget insights, and chat-memory gate state.
* **Phase 7b — Retrieval Waterfall panel.** Added Memory Drawer retrieval-path panel (RRF + legacy breakdowns + path metadata) using existing recall response metadata, with no REST shape breakage.
* **Phase 7c — Session Replay tab.** Added read-only chat-memory replay endpoint `GET /mcp-ai/v1/chat-memory/sessions/{session_id}` and wired a new Session Replay tab in the Memory Drawer (`sessionReplay` via localized `memoryEndpoints.sessionBase`).

= 1.1.19 - May 18, 2026 =

Bumped to 1.1.19 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative. Provider count: 10 first-class language-model providers.

**Kimi (Moonshot AI) provider (10th provider) + Agent Client Protocol (ACP) Server + MCP Bridge + Unix Theory P7 + GDPR + Security Hardening (5 patches) + Chat Bubble / Test Model UI Sweep (13 PRs)**

* **Kimi (Moonshot AI) provider — 10th first-class language-model provider.** New `WP_MCP_AI_Kimi_Client` wrapping the OpenAI-compatible API at `https://api.moonshot.cn/v1`. Models: `kimi-k2.6` (256K context, multimodal, tool calling — default), `kimi-k2.5`, `kimi-k2` (reasoning), `kimi-k2-thinking` (chain-of-thought), legacy `moonshot-v1-8k/-32k/-128k`. Settings → Providers → Kimi subtab. WP.org compliance docs updated with Kimi, OpenRouter, and DigitalOcean service disclosures.

* **Agent Client Protocol (ACP) Server.** Full ACP standard enabling external AI clients (Zed, JetBrains, Neovim, Claude Desktop) to natively drive NV oOS assistants over JSON-RPC 2.0 + HTTP/SSE. Core: `WP_MCP_AI_ACP_Server`, `WP_MCP_AI_ACP_JSONRPC_Dispatcher`, `WP_MCP_AI_ACP_Session_Manager`, `WP_MCP_AI_ACP_Session_Bridge`, `WP_MCP_AI_ACP_Transport_HTTP`. `/.well-known/ai-peer` extended; `enable_acp_server` + `acp_require_approval` toggles in Orchestration → Settings. PHPUnit coverage scaffolding in `tests/acp/`.

* **MCP Bridge (`bin/mcp-bridge.js`).** Node.js stdio-to-HTTP relay for Claude Desktop, Cursor, Zed. Bridges MCP stdio transport to the plugin's HTTP + SSE endpoint.

* **Unix Theory Phase P7 — Folder README convention.** Every PHP-bearing `includes/` subdirectory ships a `README.md`. Enforced by `composer run docs:check-folder-readmes`. Completes P0–P7.

* **GDPR — JetEngine Privacy Exporters.** Privacy exporter classes for JetEngine CCT data (transcripts, agent memory, approval queue entries) via `wp_privacy_personal_data_exporters`.

* **Security hardening (5 patches).** Settings-key encryption + admin-UI masking (#4990); webhook secret enforcement (#4988); SSRF hardening via `wp_safe_remote_get` (#4991); attachment URL scheme allowlist (#4975); client-log debug-gate (#4984).

* **Chat Bubble / Test Model UI sweep (13 PRs).** Self-init via `wpMcpAiChatInit.init(scope)`; `kses_chat_output()` preserves form/button/input controls; Test Model submission restored; panel-fit + scoped CSS; bubble re-init isolated; submit button fixed inside outer page forms; unified team chat response normalization.

* **Asset Inventory.** Discover Assets button restored; `discover_assets` gains debug logging + Jest coverage.

= 1.1.18 - May 14, 2026 =

Bumped to 1.1.18 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

**Unix Theory Compliance Phases P0–P6 + DigitalOcean Serverless Inference + Async Chat Continuation + Jobs/Tasks Drawer + Toolkit MCP Servers Phase 7 Admin UI**

* **Unix Theory P0–P6 (Phases landed across this cycle):**
  * **P0/P1 — Canonical return envelope.** Tool-agnostic trait `WP_MCP_AI_Tool_Envelope` (`includes/tools/trait-wp-mcp-ai-tool-envelope.php`); `WP_MCP_AI_Tool_Chat_Response` composes it. New PHPCS sniff `WPMCPAI.Tools.CanonicalReturnEnvelope` (severity 5) warns on `array( 'success' => false, ... )` — visible under `composer run lint`, silent under `composer run lint:base`.
  * **P2 — Capability-fence audit.** All optional-dep touch-points verified fenced (Rank Math, WPCode, JetEngine, Elementor, WooCommerce); no Base→Pro reach-throughs.
  * **P3 — Data-contract metadata.** New optional `WP_MCP_AI_Tool_Data_Contract_Interface` (`get_data_contract() => array{produces?, consumes?}`). Tool-service appends `[Data contract: produces=X, consumes=A|B]` to the OpenAI function-calling description. Filter `wp_mcp_ai_tool_data_contract_description_suffix`.
  * **P4 — Tool lifecycle descriptor.** Optional 5th arg on `wp_mcp_ai_after_tool_execution`; helper `WP_MCP_AI_Tool_Lifecycle_Descriptor::build()` returns `{success, error_code, data_type, duration_ms}`. OTel spans gain `nvoos.tool.data_type` + `nvoos.tool.duration_ms`. 4-arg subscribers stay back-compat.
  * **P5 — Back-compat alias infrastructure.** `WP_MCP_AI_Tool_Registry::register_deprecated_alias()`, `get_deprecated_aliases()`, `resolve_deprecated_alias()`, `reset_deprecated_alias_invocations()`. Action `wp_mcp_ai_tool_deprecated_alias_invoked` fires once per request per slug. Aliases live in a separate map invisible to `build_tools_payload`. Sets up Tier-A decompositions for v1.3.0.
  * **P6 — Sanitize-at-entry sniff.** New PHPCS sniff `WPMCPAI.Tools.SanitizeAtEntry` enforces Gate 1 of the two-gate sanitisation rule. Codification: `docs/project/proposals/audits/P6-sanitize-escape-codification-2026-05.md`.

* **DigitalOcean Serverless Inference provider (new — 9th provider).** `WP_MCP_AI_DigitalOcean_Client` wraps the OpenAI-compatible API at `https://inference.do-ai.run/v1`. Chat completions, tool/function calling, JSON mode, SSE streaming, native `/embeddings`, model listing, reasoning passthrough. Settings → Providers → DigitalOcean subtab; Model Discovery `digitalocean` branch; Provider Diagnostics card with `GET /v1/models` probe; default embedding model `gte-large-en-v1.5`. DigitalOcean Agent endpoints (`*.agents.do-ai.run`) intentionally out of scope.

* **Async chat continuation (slices 1–6 complete).** Durable continuation store + dispatcher for async tool jobs, LLM re-entry, session frame buffer, SSE stream controller, chat.js client integration, Pro webhook notifier (`addons/pro/includes/services/class-wp-mcp-ai-pro-chat-continuation-notifier.php`), OTel hooks + Jest tests. Plan doc `docs/features/chat/async-continuation.md`.

* **Jobs/Tasks Drawer + cron-status (PRs A–G complete).**
  * Inline job progress card (BEM `.wp-mcp-ai-job-card__*`) — progress bar, ETA, step list, Cancel/Retry buttons; feature-gated via `state.config.inlineJobCard`. Subscribes to `wpMcpAiJobBus` events (`job:started`, `job:step`, `job:progress`, `job:completed`, `job:failed`, `job:cancelled`).
  * New REST routes `POST /mcp-ai/v1/cron-status/{job_id}/cancel` and `.../retry`. Async-executor gains `cancel_job()`, `retry_job()`, `is_owned_by()`. Actions: `wp_mcp_ai_job_cancelled`, `wp_mcp_ai_job_retried`.
  * Tasks Drawer + toasts in chat shortcode; default on via filter `wp_mcp_ai_chat_tasks_drawer`. localStorage key `wp_mcp_ai_tasks_{assistantId}` (max 200 entries).
  * Five new OTel hooks (`wp_mcp_ai_chat_jobs_snapshot|stream|cancel|retry`) emit `nvoos.chat.jobs.*` OTLP spans.
  * Docs: `docs/features/chat/cron-status-integration.md`, `docs/developer/tool-development/registering-a-job-source.md`.

* **Toolkit MCP Servers Phase 7 admin UI.** New `WP_MCP_AI_Pro_Toolkit_MCP_Servers_Page` (slug `nvoos-pro-toolkit-mcp-servers`) — 5-tab admin page (Servers / Detail / Audit / Discovery / Help). Action hook `wp_mcp_ai_toolkit_mcp_server_toggled`. Observability card + assistant metabox links updated. `addons/pro/mcp-ai-wpoos-pro.php` load order fix so the toolkit page registers before the admin block.

* **JetEngine CCT memory mirror.** `retrieve_agent_memory` + `recall` now hydrate directly from the JetEngine CCT mirror when available; pipeline deduped to avoid double-suppression.

* **Security / maintenance.**
  * Bumped npm `langsmith` minimum to `>=0.6.0` (GHSA-3644-q5cj-c5c7).
  * Added `wp_read_video_metadata` guard (`media.php`) in Veo and Sora video tools to handle hosts without the helper preloaded.
  * Production autoload restored: `--no-dev --classmap-authoritative`; `vendor/composer/installed.json` (`dev: false`, `dev-package-names: []`) and `installed.php` (`dev => false`) patched manually.

**Agent surfaces refreshed.** All hidden folders that host coding-agent context (`.bmad/`, `.codex/`, `.context/`, `.devcontainer/`, `.github/agents/`, `.vscode/`, `.zed/`) refreshed in this release so contributors get consistent guidance regardless of editor or agent. The `toolkit-spa-maintainer` agent is now mirrored from `examples/agents/` into both `.github/agents/` and `.zed/settings.json` (13 profiles).

= 1.1.17 - May 10, 2026 =

Bumped to 1.1.17 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

**WP.org Compliance Hardening + Chat SPA (all 7 phases) + Docs Hub v0.3.8 + Toolkit SPA Blueprint Phases 5–12 + Coverage Campaign + Dependabot Security Sweep**

*Fixed — WordPress.org Reviewer Findings (PRs #4892, #4902)*

* B3 — Inline script/style echoes removed; config blocks converted to wp_print_inline_script_tag(); telemetry CSS moved to wp_add_inline_style().
* B8 — Cache path moved from WP_CONTENT_DIR/cache/wp-mcp-ai to wp_upload_dir()['basedir']/wp-mcp-ai-cache.
* B10 — New WP_MCP_AI_User_Context_Helper::safe_set_current_user() validates get_userdata() and multisite membership before touching global state.
* B13 — wp_unslash() added to approval handler $_POST reads; phpcs:ignore annotations annotated with explanations.
* B1/B2/B5/B11/B12 — unescape-before-output patterns removed, dead WP < 5.7 branches deleted, permission callback gaps closed.
* 49/49 base AJAX handlers confirmed with check_ajax_referer(). Full evidence: docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_05_09.md.

*Added — Chat SPA addon (addons/chat-spa/, v0.6.0 — all 7 phases)*

* React replacement for the legacy chat shortcode using Vercel AI SDK UI with a custom SSE adapter.
* Phase 2: tool-call cards + memory pills + admin embed (WP-Admin → NV oOS Chat).
* Phase 3: transcripts sidebar (load / save / delete); session key matches legacy chat.js format.
* Phase 4: memory drawer with Memories / Scope / Audit tabs; scope persisted in localStorage.
* Phase 5: HITL approval bar polling /mcp-ai/v1/approvals every 6 s during streaming.
* Phase 6: file attachments (5 MB per file, 10 MB total, 10 files max), regenerate, message branching.
* Phase 7: WP_MCP_AI_LEGACY_CHAT_JS constant (default true) gates the legacy shortcode; blueprint §20 migration guide.

*Added — Docs Hub addon (addons/docs-hub/, v0.1.0 → v0.3.8)*

* Remote-first defaults + tree-picker UX; chunked rebuild + CLI subcommand; mobile sidebar; RemoteAnchor; in-page link routing.
* SSRF hardening (resolve_public_ip via DNS A/AAAA); defensive remote_repos coercion.
* a11y: ARIA root attrs, skip-link, prefers-reduced-motion. Syntax highlighting via rehype-highlight + lowlight.
* NV_oOS_Docs_Hub_Sitemap_Provider (WP_Sitemaps_Provider); PageFooter (last_modified + edit-on-GitHub); admin repo-picker.js extracted.

*Added — Toolkit SPA Blueprint Phases 5–12*

* SPA a11y CI (spa-a11y.yml), bundle-size CI (spa-bundle-size.yml), i18n pass (wp.i18n external + wp_set_script_translations), expanded PHPUnit tests (Phase 7).
* Scaffolder auto-patches CI workflows on new addon creation (Phase 9).
* All 10 Tier-A toolkit-shell manifests complete (Phase 10).
* canvas-toolkit v0.2.0: whiteboard (tldraw v5), bpmn (bpmn-js), mermaid modes. document-editor v0.2.0: GrapesJS site-creator. media-studio Phase 4: image-editor, media-player, audio-waveform.

*Added — Build Pipeline*

* bin/build-plugin-zip.sh --wp-org flag: produces WP.org-compliant base-only ZIP (addons/, .zed, root *.md excluded).

*Security — Dependabot Alert Sweep (33 alerts)*

* Root: axios, basic-ftp, ip-address overrides bumped (3 alerts).
* addons/pro: axios bumped (3 alerts).
* addons/saas-controller: @wordpress/scripts, diff, esbuild, miniflare + webpack-dev-server overrides (17 alerts).
* addons/docs-hub: react-router-dom 7.5.3 → ^7.15.0 (2 alerts).
* addons/cloud-worker: @cloudflare/vitest-pool-workers, vitest, wrangler bumped (10 alerts).
* Dependabot extended to all addon manifests (4 npm + 4 composer watchers added).

*Tests*

* PHPUnit + Vitest coverage campaign (PRs #1–#11): 271 AJAX handlers covered; PHPUnit baseline + non-regression CI gate; Vitest scaffolding for all 6 SPA addons (~71 tests).
* New test files: test-user-context-helper.php, test-hooks-tool-lifecycle.php, test-hooks-chat-lifecycle.php, test-hooks-registry.php, test-security-regression.php, and Pro/service/REST controller suites.

= 1.1.16 - May 6, 2026 =

**SaaS Controller Addon (v0.1.0) + Structured Logging Integration**

*Added — SaaS Controller Addon (addons/saas-controller/)*

* Operator-side WordPress admin toolkit (WP-Admin → NV oOS SaaS, manage_options) for provisioning and managing the NV oOS Cloud control plane — Cloudflare Workers + D1 + KV + AI Gateway, Stripe billing, and OpenRouter — without leaving WP-Admin.
* Four admin tabs: Overview (React Credentials Wizard + masked-credentials fallback), Deployment (topology editor + Run Plan), Operations (HITL Apply + Drift Detector + Orphan Review + Webhook Events + Smoke Tests + Audit Log), Packages (credits surface).
* Phases 2–11 shipped: encrypted credential store (AES-256-CBC), deployment-config store, connection tester, read-only Cloudflare client, reconcile-plan generator (creates/updates/noops/orphans/errors), mutating Cloudflare client (D1/KV/AI Gateway/Worker upload), HITL-gated Apply (sync + background async), drift detector (manifest + post-apply fingerprint), orphan cleanup, Stripe webhook verifier (HMAC-SHA256, constant-time, 300 s replay guard), webhook event store (200-entry ring buffer, idempotent), audit log (200-entry ring buffer).
* REST namespace /wp-json/nvoos-saas/v1/ — all routes require manage_options except POST /webhooks/stripe (signature-gated). 19 routes covering credentials, deployment, plan, audit-log, smoke tests, apply (sync + async + orphans), drift, and webhooks.
* Key filters: nvoos_saas_controller_apply_token_ttl, nvoos_saas_controller_audit_log_max_entries, nvoos_saas_controller_audit_log_record, nvoos_saas_controller_webhook_events_max_entries, nvoos_saas_controller_apply_job_state_ttl, nvoos_saas_controller_worker_dist_path.
* See addons/saas-controller/README.md for the full implementation reference.

*Added / Improved — Structured Logging Integration (PR #4849)*

* WP_MCP_AI_Agent_Memory_CCT_Bridge — all bridge writes, CCT mirror failures, filter-suppressed writes, and deletions logged via WP_MCP_AI_Logger.
* WP_MCP_AI_Transcript_Mining_Job — structured logging for the full job lifecycle (enqueue, tick, completion, cancellation, all error paths).
* WP_MCP_AI_Logger integrated across Algorave, Canvas, Webchat, Fantasy Football, Graphify, SaaS Controller addons; admin (Run Timeline, Approvals, Settings Base); cost calculator; model catalog migration; workflow engine V2; harness layers; and more.
* New PHPUnit tests: test-agent-memory-cct-bridge-logging.php and test-transcript-mining-job-logging.php.

= 1.1.15 - May 5, 2026 =

**New providers (OpenRouter, DeepSeek), Orchestration Phases 1–7 re-landed, LLM Harnessing GA, 19 new slash commands, Memory Bridge G-series, Retroactive Transcript Mining, Graphify NV oOS data-source bridge, stability sweep**

*Added — New AI providers*

* **OpenRouter** — `WP_MCP_AI_OpenRouter_Client`, a unified OpenAI-compatible gateway in front of OpenAI, Anthropic, Google, Meta, Mistral, and others via a single API key. Selectable from Settings → Providers. PR #4840.
* **DeepSeek** — `WP_MCP_AI_DeepSeek_Client`, first-class provider with model selection and `reasoning_content` passthrough. PR #4820.
* **Kimi K2.6 + Qwen 3.6** — added to the model catalog and provider dropdowns. PR #4810.

*Added — LM Studio parity (May 2026 LM Studio capabilities)*

Real-time token streaming via native cURL SSE (#4839) plus full provider parity: native `/api/v0` endpoint opt-in (#4818), embeddings, bearer-token auth, capability-aware tool gating, `reasoning_content` passthrough, malformed-argument repair, TTL + structured-output pass-through, and improved `test_connection()` fallback to `/api/v0/models`. New filter: `wp_mcp_ai_lm_studio_stream_request_args`.

*Added — Orchestration roadmap Phases 1–7 (re-landed with JetEngine CCT init-priority fix)*

HITL approval queue (`WP_MCP_AI_Approval_Queue`, CPT `mcp_ai_approval`, REST `/mcp-ai/v1/approvals/*`), prompt-injection guardrail (Layer I, `WP_MCP_AI_Prompt_Injection_Detector`), structured-output guardrail, OTel span exporter, visual DAG builder, durable run store, trigger CPTs + webhooks, sub-agent dispatch, Pro vector-store adapter (openai/pgvector/qdrant), and Pro team budget manager (per-team daily caps). All CCT bootstraps now register on `init` at priority 11+ to avoid racing JetEngine's cache hydration. PRs #4816, #4821.

*Added — Observability UI*

Observability dashboard surfaced under the **Orchestration** tab (#4833). OpenTelemetry OTLP endpoint and token configurable under **Tools → Connections** (#4837).

*Added — LLM Harnessing Subsystem (Layers A-H)*

Seven opt-in per-request layers in `includes/harness/` improve response quality without changing existing tool behaviour: Layer A (Prompt/Cue Library with 7 named templates), Layer B (Reasoning Trace + self-consistency vote), Layer C (Tool Routing with preset_weights), Layer D (Retrieval fan-out + citation verification), Layer E (Self-Refine loop with cost caps), Layer F (Memory Scoping + PII Filter), Layer G (Eval Scheduler cron). Pro Layer H exports fine-tune curricula as OpenAI JSONL. Profile stored in `_wp_mcp_ai_harness_profile` post meta. Reference: `docs/features/llm-harness.md`.

*Added — 19 new slash commands (11 base + 8 Pro, 32 total)*

New base commands: `/jobs`, `/status`, `/cost`, `/diagnose`, `/tools`, `/skills`, `/preset`, `/model`, `/markup-stats`, `/remember`, `/forget`, `/scope`, `/compact`, `/context`, `/clear`, `/reset`, `/resume`, `/workflow`, `/sync-docs`, `/optimize-perf`. New Pro commands: `/schedule`, `/schedule-preset`, `/workflow-preset`, `/run`, `/agent`, `/mcp-app`, `/persona`, `/broadcast`.

*Added — Chat-client Memory Bridge (G-series completion)*

Memory Drawer with three tabs (Memories / Scope / Audit), auto-badge on memory-touching messages, SSE `memory_event` frame, pagehide auto-capture, drawer export. REST proxy at `/mcp-ai/v1/chat-memory/`. Three gates: `wp_mcp_ai_chat_memory_enabled` filter, per-user meta, and a new site-wide **Enable Chat-Client Memory** toggle in **Orchestration → Settings** (#4802).

*Added — Retroactive Transcript Mining*

`WP_MCP_AI_Transcript_Mining_Job` background worker with REST API (`/mcp-ai/v1/transcript-mining/jobs*`). New `transcripts` source on `mine_agent_memory` tool with provenance metadata and dedupe.

*Added — Graphify NV oOS data-source bridge*

Private CPTs, JetEngine CCT resolvers, MemPalace edges, and external `$wpdb` tables are now first-class Graphify data sources. A new **Sources (CPT / CCT)** tab on the Knowledge Graph settings page exposes per-source control. PR #4834.

*Added — Pro Packages Tier 5*

Five new NPM packages: `nvoos-client-tools`, `nvoos-chat-memory`, `nvoos-attachments`, `nvoos-cron-status`, `nvoos-transcription`.

*Added — DX*

* Custom devcontainer image for WordPress plugin development (#4811).
* `examples/agents/` roster mirrored as native Zed agent profiles in `.zed/settings.json` (#4808).

*Fixed*

* Transcript-mining queued job never executed — future cron timestamp + missing `spawn_cron()` call. Three-root-cause sweep (#4804, #4826).
* "Workflow not found" error on admin Workflows tab for orchestrator-managed workflows (#4803).
* `workflow-cpt` `map_meta_cap=false` blocked JetEngine on WP 6.1 delete_post notice (#4822).
* Graphify `get_settings()` infinite recursion causing 502 on admin page (#4835).
* Graphify Sources (CPT / CCT) tab missing from Knowledge Graph settings page (#4836).
* Graphify admin settings file PHP linting CI failure (#4838).
* Multi-agent dashboard TypeError when `primary_roles` post meta is unset (#4823).
* Nonce field-name mismatches breaking Generate Credential, Revoke, and Delete credential buttons (#4824, #4825).
* JetEngine CCT table prefix corrected to `jet_cct_` (underscores) in transcript repository and all direct SQL paths; chat channel SQL queries now backtick-quote hyphenated table names to satisfy MySQL (#4827, #4828, #4830).
* Site-health polyfill `wp_is_auto_update_forced_for_item` added before early-return guard (#4832).
* README TOC anchor links corrected (#4807).
* `/status` slash command PHP notice on malformed async health-check shape.

*Versioning*

Bumped to 1.1.15 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`. Tool counts remain reconciled at ~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative.

= 1.1.14 - May 2, 2026 =

**Agent Skills v2 (progressive disclosure + skill packs + remote catalogues), Markup Subsystem (Base), MemPalace Capture Framework Phases A + B1, Graphify CPT/CCT integration suite, follow-up fixes**

*Added — Markup Subsystem (Base, PR #4778)*

A new in-the-loop image / document markup system that lets tools pause the agentic loop, surface a Konva canvas widget in the chat UI for the user to draw on, and resume the same tool call with the rasterised mask / crop / region polygon.

* **Loop integration** — `WP_MCP_AI_Markup_Loop_Interceptor` short-circuits any tool that implements `WP_MCP_AI_Markup_Aware_Tool_Interface` and emits a `markup_elicitation` SSE frame instead of the tool result. The chat client persists a `request_id` and resumes the call once the user submits the markup. Master toggle: `wp_mcp_ai_markup_enabled` filter.
* **Chat canvas widget** auto-enqueued whenever the main chat bundle is on the page — supports `mask`, `crop`, and `region` modes against image targets.
* **Markup-aware tools** — `edit_openai_image` (`mask`), `crop_image` (`crop`), `edit_gemini_image` (`region`).
* **REST controller** — `/wp-json/mcp-ai/v1/markup/{request_id}` accepts a W3C Web Annotation envelope, runs `WP_MCP_AI_Markup_Validator` + `WP_MCP_AI_Markup_Rasterizer`, and re-invokes the source tool with the resulting artifacts in the execution context.
* **Settings UI** toggle under **NV oOS → Settings → General**.
* **Telemetry** — bounded option `wp_mcp_ai_markup_telemetry` aggregates per-tool / per-mode counters and last-seen timestamps for seven outcome buckets.
* **Slash command** `/markup-stats` (alias `/markup`) renders the summary as Markdown with `--verbose`, `--json`, and `--reset` flags.
* **Admin dashboard** under **NV oOS → Markup Telemetry** renders the same summary as a server-rendered HTML table with a colour-coded completion-rate card, per-tool / per-mode breakdowns, relative `last_seen` timestamps, and a nonce-protected `Reset counters` form.
* **Hooks** (4 actions, 4 filters): `wp_mcp_ai_markup_request_created`, `wp_mcp_ai_markup_submitted`, `wp_mcp_ai_markup_validated`, `wp_mcp_ai_markup_resolved`, `wp_mcp_ai_markup_enabled`, `wp_mcp_ai_markup_widget_payload`, `wp_mcp_ai_markup_mcp_elicitation`, `wp_mcp_ai_markup_rasterized_artifacts`. Documented in `docs/reference/hooks/hooks-reference.md`.
* **Daily cleanup** cron (`wp_mcp_ai_markup_cleanup`) prunes expired markup transients and orphan mask attachments.
* Reference: `docs/features/markup-subsystem.md`.

*Added — Agent Skills Phases 1–4 (PR #4771)*

* **Phase 1 — Bundled WP-developer skills**: 28+ new `SKILL.md` files curated from the MIT-licensed [Lonsdale201/wp-agent-skills](https://github.com/Lonsdale201/wp-agent-skills) catalogue under `addons/pro/includes/bundled-skills/`, covering WooCommerce (HPOS, payment gateways, REST API v4, shipping, Stripe, variations, customer & sessions, classic emails, coupons, product search/select), WooCommerce Memberships (access discounts, subscriptions linkage, hooks), WooCommerce Subscriptions (renewal scheduler, switching/gifting data model, hooks), JetEngine (dynamic visibility, listings callbacks, query builder custom types), JetFormBuilder (action events, external API, item decorator, messages, form actions, sidebar panels, settings tabs), and WP Rocket (cache invalidation, rejection filters). Base plugin gains a `wp-abilities-api` skill under `includes/bundled-skills/`. New `THIRD_PARTY_NOTICES.md` files in both `bundled-skills/` directories carry attribution and license text.
* **Phase 2 — Remote skill catalogues (Pro)**: new `WP_MCP_AI_Skill_Catalogue_Service` (`addons/pro/includes/services/class-wp-mcp-ai-skill-catalogue-service.php`) discovers `SKILL.md` files in registered public Git repositories using the GitHub trees API, supports `catalogue.json` manifests when present, caches manifests in 24-hour transients, and refreshes them daily via the `wp_mcp_ai_skill_catalogue_refresh` WP-Cron job. Pre-seeded with `Lonsdale201/wp-agent-skills` and `anthropics/skills`. New `WP_MCP_AI_Skill_Catalogue_REST_Controller` exposes admin-only endpoints under the `mcp-ai-pro/v1` namespace (`/catalogues`, `/catalogues/{id}/skills`, `/catalogues/{id}/install`, `/catalogues/{id}/refresh`). All fetches reuse the SSRF-safe HTTPS-only helper, the existing extension allowlist, and the decompression-bomb cap.
* **Phase 3 — Progressive disclosure (`load_skill` tool)**: each assistant gains a "Use progressive disclosure" checkbox on its Skills metabox. When enabled, the system prompt receives only a short `# Available Skills` catalogue (skill name + description), and the model calls the new base-plugin `load_skill({ name })` tool when it decides a skill applies — the full SKILL.md is then returned in the tool result. This dramatically reduces baseline context cost for skill-heavy assistants.
* **Phase 4 — Skill packs**: curated, named collections of related skills addressable as a single unit ("WordPress Developer", "Document Authoring", etc.). The Skill Manager admin UI gains tabs for browsing catalogues, managing packs, and editing individual skills.
* Filters: `wp_mcp_ai_skill_catalogue_manifest_ttl`, `wp_mcp_ai_skill_catalogue_refresh_cadence`.

*Added — MemPalace Capture Framework Phases A + B1*

* **Phase A — foundation** — base capture interface, lifecycle hooks, and shared time-source / tier-logging utilities, with a follow-up review fix for time consistency and tier-logging payload shape.
* **Phase B1 — five highest-leverage capture tools** that write into the durable `ai_agent_memories` Custom Content Type through the Phase 4a/4b bridge shipped in 1.1.13.
* See `docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md` for the unified MemPalace / Letta / Zep / mem0 / Cognee schema rationale.

*Added — Graphify CPT/CCT integration suite*

JetEngine custom post types and Custom Content Types are now first-class citizens across every Graphify surface.

* **Knowledge graph builds (#4779)** — JetEngine CPTs and CCTs are detected, structured (`cct_{slug}` nodes + `AUTHORED_BY` edges), and semantically embedded end-to-end through dedicated cache prefixes and cron actions.
* **Graph Explorer type filter** — JetEngine CPTs and CCTs now appear alongside core post types in the Explorer's type filter.
* **Related-content widget + recommendations** expanded to all CPTs (audit follow-up).
* **Semantic extractor extended to JetEngine CCT items (#4781)**.
* **Re-index All Nodes** button on the embeddings tab now triggers a full re-index of every Graphify node.
* **Per-source detection counts + CCT skip reason** surfaced on the embeddings/diagnostics screens.
* **Settings persistence on tabbed admin page (#4784)** — Graphify settings now persist correctly across tab switches.
* JS escaping fix (#4780): extracted constant, hoisted filter, added a label-field filter for downstream customisation.
* `ucwords` for multi-word post-type slug fallback so `case_study` produces `Case Study` instead of `Case_study`.

*Added — Agent context system (.github/agents/ + examples/agents/)*

* `.github/agents/` is now a layered context surface — each `*.agent.md` carries only role-specific metadata + behavior and links to `AGENTS.md`, `CLAUDE.md`, and `.context/` for shared rules. Slim template at `.context/templates/agent-file-template.md`; copy-ready examples under `examples/agents/`.
* New 10-agent roster covers the full NV oOS surface (REST reviewer, security reviewer, WP.org compliance auditor, PHP-compat reviewer, tool author, slash-command author, chat-UI author, PHPUnit test author, agent-skill curator, addon maintainer, release engineer, docs maintainer). See `examples/agents/README.md`.

*Fixed*

* **Markup subsystem — server-side ownership check on admin fallback page** — a markup `request_id` issued for one user can no longer be opened by another.
* **Markup subsystem — null-safe hex color, best-effort hardening file writes** — review-pass hardening on the rasteriser path.
* **Graphify Memory Bridge stale "not installed" status (#4769)** — the orchestration dashboard's Phase 4a memory-bridge widget could report "not installed" even after the bridge had been activated, due to a stale-cache `bridge_active` recomputation path. Cache invalidation now runs on activation/deactivation and the widget re-reads the live status. Regression covered by `tests/test-orchestration-dashboard-stale-cache.php`.
* **Orchestration dashboard — JetEngine availability recomputed on cache hit** — `get_agent_memory_stats()` caches results for 5 min; both `bridge_active` (Graphify) and `persistent_storage.available` (JetEngine CCT) are now re-checked on cache hit so the dashboard no longer reports "not installed" for up to 5 minutes after the underlying plugin is activated.
* **Pro Mini App Builder — TMA bundle enqueue when `asset.php` is missing** — the Telegram Mini App bundle now enqueues correctly even when the build pipeline does not emit a sibling `asset.php` manifest, so the builder loads on a clean install.
* **cURL SSL error 60 fetching remote skill catalogues (#4772)** — the new catalogue-fetcher (Phase 2) could fail with `cURL error 60: SSL certificate problem` on hosts with outdated CA bundles when reaching `api.github.com` and `raw.githubusercontent.com`. The HTTP layer now uses WordPress's `wp_remote_get()` certificate bundle path consistently and surfaces a structured `WP_Error` instead of a fatal request failure when verification still fails.
* **"Dynamic require of dicom-parser" in Medical Imaging Viewer (#4773)** — the Pro Medical Imaging Viewer bundle could fail at runtime with `Dynamic require of "dicom-parser" is not supported`. The viewer now imports `dicom-parser` statically so the esbuild output no longer relies on a runtime CommonJS shim.
* **Stored embeddings display (#4787)** — the stored-embeddings admin display rendered incorrectly under certain dataset shapes; it now degrades gracefully and surfaces accurate counts.

*Build*

* **All distribution ZIPs rebuilt at v1.1.13 (#4775, #4782)** — `bin/rebuild-all-zips.sh` regenerated the four original (`mcp-ai-wpoos-base|pro|combined|core`) and four WordPress.org (`nvdigital-open-operator-system-oos-*`) packages plus the standalone toolkit add-on ZIPs (19 toolkit ZIPs in the v1.1.13 rebuild pass).
* **Production autoloader reaffirmed (#4774)** — `vendor/composer/installed.json` and the autoload classmap regenerated with `composer install --no-dev --classmap-authoritative` to confirm the production posture established in 1.1.13.

*Documentation*

* `docs/features/markup-subsystem.md` (new) walks through the end-to-end markup flow, REST contract, validator rules, rasteriser output shape, and observability surfaces.
* `docs/reference/hooks/hooks-reference.md` extended with the 4 markup actions + 4 markup filters.
* `docs/features/agent-skills.md` updated end-to-end with the Phases 1–4 narrative.
* `README.md`, `MAINTAINER_MAP.md`, `AGENTS.md`, `CLAUDE.md`, and `.github/copilot-instructions.md` refreshed with v1.1.14 and reconciled tool counts (~195 base / ~635 Pro / ~830 total — the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` remains authoritative).

*Versioning*

* Bumped to 1.1.14 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

= 1.1.13 - May 1, 2026 =

**OpenAI `gpt-image-2` (Images 2.0), Phase 4a/4b durable agent-memory bridge (MemPalace-inspired), AI Harmonization sub-toolkit, production-only Composer autoloader**

*Added*

* **OpenAI `gpt-image-2` (Images 2.0)** — first-class support and the new default image model across the base plugin and Pro image tools (was `gpt-image-1.5`). Existing sites with a saved `openai_image_model` setting are unaffected. New 2K aspect-ratio sizes for `gpt-image-2`: `2048x2048` (square 2K), `2048x1152` (16:9 widescreen), `1152x2048` (9:16 vertical). Cost / token-estimation tables and admin model dropdowns ("Images 2.0 (Recommended)") updated to match. Pro tools `generate_architectural_drawing`, `product_actualization`, harmonization base, and `generate_scene_background` default to `gpt-image-2`.
* **Phase 4a/4b — durable agent-memory bridge** — when JetEngine is active, every transient memory write is now mirrored into a durable `ai_agent_memories` Custom Content Type with a schema aligned to industry-standard agent-memory architectures: Letta / MemGPT (memory tier, verbatim immutability flag, expires_at TTL anchor), Zep (bi-temporal validity, source provenance), mem0 (importance, verbatim discipline, source tracking), Cognee, and [MemPalace](https://github.com/MemPalace/mempalace) (hierarchical scope via wing/room, verbatim-storage discipline). Transients remain the primary fast read path. New `wp_mcp_ai_memory_deleted` action; "Persistent (CCT) / Cache only" stat card on the agent-memory dashboard.
* **AI Harmonization sub-toolkit (Pro)** — 14 new Pro tools for cross-model output reconciliation. Admin docs cross-link the Architectural Design and other Pro toolkits.

*Changed*

* **Production-only Composer autoloader** — `composer install --no-dev --classmap-authoritative` (no separate `dump-autoload` invocation) regenerates the autoloader as part of `install`. `vendor/composer/installed.json` now reports `"dev": false` with an empty `dev-package-names` array — no dev references survive in the production tree. `vendor/composer/autoload_real.php` calls `setClassMapAuthoritative(true)`, so PSR-4 filesystem fallback lookups are skipped at runtime. Net classmap diff: −6,761 / +279 lines as `phpunit/`, `phpcs/`, `wp-phpunit/`, and other dev-only packages drop out of `vendor/`. The repo can now be cloned and used as a production WordPress plugin without an extra build step.
* **Source citations to MemPalace** — file headers across the agent-memory subsystem (`class-wp-mcp-ai-tool-store-agent-context.php`, `class-wp-mcp-ai-tool-wake-up-context.php`, `class-wp-mcp-ai-tool-mine-agent-memory.php`, `class-wp-mcp-ai-jetengine-agent-memories-cct.php`, `interface-wp-mcp-ai-embedding-provider.php`, `class-wp-mcp-ai-embedding-provider-openai.php`, `class-wp-mcp-ai-embedding-provider-ollama.php`, `class-wp-mcp-ai-vector-context-service.php`) now cite the upstream project URL so source attribution matches `docs/features/memory/AGENT-MEMORY-COMPLETE-GUIDE.md`.

*Tests*

* `tests/test-openai-image-tool.php` — new `test_gpt_image_2_is_recognized_and_default` covers the default, the `hd → high` quality remap, and the suppression of `response_format` on the wire. `test_image_model_supports_style` extended.
* `tests/test-jetengine-agent-memories-cct.php` (slug/schema/REST args/required fields/field-id ranges) and `tests/test-agent-memory-cct-bridge.php` (tier classifier, record build, filter mutation, delete-event payload). 13 new tests; 24 existing regression tests still pass.

*Version*

* Bumped to 1.1.13 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

= 1.1.12 - April 29, 2026 =

**Architectural Design Toolkit (Phases A–E), Graphify Federation/RAG, Tier 4 Browser-AI Runtime, Production Cleanup, Security Patches**

*Added*

* **Architectural Design Toolkit** — five-phase rollout: Phase A foundations refactor; Phase B regional-compliance + analysis tools (with PHPUnit suite and regional fixtures); Phase C EDGE/LEED scoring + Bill-of-Quantities + Value-Engineering options; Phase D IFC / gbXML interop + BIM Execution Plan + RFI / submittal logs; Phase E precedent library + semantic search + curated regional examples.
* **Production Cleanup admin buttons** — new controls under *Settings → Advanced → Data Management* safely clear test/runtime artefacts.
* **`plan_schedules_from_workflow` tool + Research & Add Schedule admin page** — base-plugin workflow-to-schedule planner.
* **Graphify Phases 1–5** — connector foundations + Woo / CSV / Webhook (Phase 1); SaaS connectors HubSpot, GitHub, Slack, Google Drive, Jira, Zendesk, M365/SharePoint, ServiceNow (Phase 3, Pro); Generic GraphQL, Generic SQL (read-only), and S3 remote drivers (Phase 4, Pro); schema.org auto-typing, embeddings-on-ingest, field-mapping admin UI with live validation (Phase 5); remote sources, federation, vector embeddings, and RAG retrieval.
* **Algorave** — safe guest access for the live coder shortcode.
* **Tier 4 browser-AI runtime packages** — `llm-worker`, `model-loader`, and `transformers-client` NPM packages for in-browser AI.
* **`WARRANTY.md`** — formal warranty, liability, and safe-use notice; cross-referenced from `README.md` and `SECURITY.md`.

*Changed*

* **TCPDF extracted into `oos-toolkit-tcpdf` addon** — removed from the combined ZIP with classmap cleanup; vendor-only supplement toolkits require PHP 8.1+.
* **AV-clean deploy tooling** — new `bin/strip-dev-files.sh` and expanded `.gitattributes` `export-ignore` rules; AV-triggering payload literals in nefarious-monitor tests obfuscated to keep regression suites scannable.
* Composer / vendor regenerated as production classmap-authoritative; Pro vendor rebuilt for `phpoffice/phpspreadsheet` 5.7.0 + `symfony/polyfill-mbstring` v1.37.0.

*Fixed*

* **Graphify** — `do_settings_sections_filtered()` calls now correctly prefixed with `self::`.

*Security*

* **`phpoffice/phpspreadsheet` bumped to ^5.7.0** to patch HTML Writer XSS.
* **`uuid` overridden to `>=14.0.0`** to fix `GHSA-w5hq-g745-h8pq`.

*Version*

* Bumped to 1.1.12 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `package-lock.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

= 1.1.11 - April 27, 2026 =

**WordPress.org Compliance Hardening — readme metadata, slug-aware build pipeline, and verification gates**

*Fixed*

* **Inconsistent tool count in readme** — The headline description and the Base Plugin section now both report `250+ tools`, matching the Tool Registry screenshot caption and the audited `tool-reference.md` figure.
* **Missing canonical `mcp` tag** — `Tags:` line now includes `mcp` alongside `ai assistant`, `openai`, `chatbot`, `automation` (5 tags maximum, per WordPress.org guidelines). The MCP protocol is a primary feature and was previously undiscoverable in plugin search.

*Changed*

* **`bin/build-wordpress-org-from-base.sh`** — Added a per-package readme-normalization step that rewrites the WordPress.org support-forum URL to match each transformed slug (`nvdigital-open-operator-system-oos`, `…-pro`, `…-core`). The build now also runs a verification grep at the end of `transform_package()` and exits non-zero if any legacy `wp-mcp-ai` slug or unrewritten `mcp-ai-wpoos` text-domain reference survives in `readme.txt`. Prevents the metadata regressions silently re-entering future releases.
* **`bin/review-zips.sh`** — Asserts the same readme invariants when auditing already-built `.zip` packages, so a stale build can no longer pass review.

*Documentation*

* **`docs/operations/compliance/WORDPRESS_ORG_PLUGIN_CHECK_REPORT.md`** and **`docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`** updated with the 1.1.11 status table and a note that source-level identifier prefix migration (`wp_mcp_ai_*` → slug-derived prefix, ~14k identifiers across base + Pro) remains scheduled for v2.0 with a coordinated options/postmeta/cron migration; it is not a WordPress.org submission blocker.

*Version*

* Bumped to 1.1.11 across plugin header (`mcp-ai-wpoos.php`), `WP_MCP_AI_VERSION` constant (`includes/bootstrap/constants.php`), `package.json`, `readme.txt` Stable tag, and `CHANGELOG.md`.

= 1.1.10 - April 27, 2026 =

**April 2026 Security Audit Summary, Production-Ready Vendor Autoload, Veo 3.1 Seed-Parameter Fix**

*Documentation*

* New [`docs/operations/compliance/SECURITY_AUDIT_2026_04.md`](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/operations/compliance/SECURITY_AUDIT_2026_04.md) — published summary of the April 2026 security & compliance code review (base plugin + Pro addon + 6 minor addons). Cross-references the 9 deliverables under `docs/project/audits/2026-04/`. Headline verdict: no Critical findings; 5 High (3 Fixed, 2 Partially Fixed); 14 Medium (all Fixed); 21 Low (14 closed); 10 Informational
* `docs/DOCUMENTATION_INDEX.md`, `docs/operations/compliance/README.md`, `docs/QUICK_REFERENCE.md`, and `docs/project/audits/2026-04/README.md` updated to cross-reference the new audit summary

*Changed*

* **Production-ready vendor autoload (PR #4733)** — `vendor/` regenerated with `composer install --no-dev --classmap-authoritative` (677 production classes); the plugin is now deployable from a clean clone without a separate `composer install` step. Local development still requires `composer install` to pull dev dependencies (PHPUnit, WPCS)

*Fixed*

* **Veo 3.1 `generate_veo_video` `INVALID_ARGUMENT` when `seed` is supplied (PR #4735)** — the `seed` parameter is now sent only to Veo 2.0 (`veo-2.0-generate-001`); Veo 3.1 (`veo-3.1-generate-preview`) rejects the parameter and the tool now silently drops it on that model

*Version*

* Bumped to 1.1.10 across plugin header, `WP_MCP_AI_VERSION` constant, `package.json`, `readme.txt` Stable tag, and CHANGELOG.md

= 1.1.9 - April 25, 2026 =

**Measurement Subsystem GA, PHPUnit 11 Upgrade (CVE Fix), Chart.js Handle Normalization, Graphify Addon Restore**

*Stock Metrics & Persistence*

* Tool-execution, chat-loop, agentic-loop, and SSE/stream metrics emitted through a single `wp_mcp_ai_register_metrics` registry — every signal carries a privacy tier, a direction (higher_is_better / lower_is_better / neutral), and a counter metric so dashboards cannot Goodhart a single dimension
* Persistent metric event store (`{prefix}mcp_ai_metric_events`) with `wp_mcp_ai_metric_retention_days` filter (default 30 days) and table dropped on uninstall when `Delete data on uninstall` is enabled
* Dashboard time-range selector and persisted-metric panel under **Tools → Measurement**

*Eval Harness*

* Suites + cases registered via `wp_mcp_ai_register_eval_suites`; runner enforces verifier-independence against the suite's `generator_context` so a judge cannot share provenance with the candidate
* Pro rubric presets (`prompt_adherence`, `json_schema`, `citation_presence`) and counterfactual runner shipped — `WP_MCP_AI_Eval_Runner::run_counterfactual()` flags measurement invalidity when the verifier fails to prefer the candidate over a degraded variant

*CLI & Regression Alerting*

* `wp mcp-ai measurement run <suite>` — runs a registered suite using a generator callable resolved through the `wp_mcp_ai_cli_measurement_generator` filter; persists the run summary and emits `eval.suite.pass_rate`
* `wp mcp-ai measurement alert-check <suite> [--window=N] [--webhook=<url>]` — exits 2 on regression and emits `eval.suite.regression.count` per offending metric; webhook failures never mask the exit code so CI cannot silently mark a regression as green
* `wp mcp-ai measurement list-runs <suite>` — formatted `table|json|yaml|csv` output of persisted run history
* New stock metrics: `eval.suite.pass_rate` (gauge) and `eval.suite.regression.count` (counter), each tagged with the suite slug and the offending metric

*GA Polish*

* Contextual help tabs on the Measurement dashboard (overview / metrics / privacy / cli) — extensible via the `wp_mcp_ai_measurement_help_tabs` filter
* Reference snippets shipped under `assets/examples/measurement/`: a custom rule verifier, an eval-suite registration, and a CLI generator callable
* Documentation: `docs/reference/measurement/README.md`, `docs/reference/measurement/rollout-plan.md`, and `docs/reference/measurement/goodhart-checklist.md` updated through PR 11

*Security & Tooling*

* **PHPUnit upgraded to 11** with WordPress-compatibility patches to fix argument-injection vulnerability **GHSA-qrr6-mg7r-m243**; `@dataProvider` docblocks migrated to attributes; CI PHP bumped 8.1 → 8.2 (PHPUnit 11 requires PHP ≥ 8.2)
* `patches.lock.json` regenerated to include phpunit and wp-phpunit-phpunit10 patches
* WPCS test-suite cleanup — 83 lint errors resolved in `tests/test-provider-client-adapters.php`
* Production autoload classmap regenerated with `composer install --no-dev --classmap-authoritative`

*Frontend & Addons*

* **Chart.js handle normalization** — every admin screen now enqueues a single `wp-mcp-ai-chartjs` script handle (ECA dashboard, Schedule Manager, Agent Command Center, Measurement dashboard) to avoid duplicate registrations and version drift
* **Graphify addon v0.5.0 restored** — WordPress Knowledge Graph addon, living under `addons/graphify/`, is back in the distribution after a short revert cycle

*Documentation*

* New `docs/reference/orchestration/ORCHESTRATION_REFERENCE.md` — single authoritative reference for the orchestration layer (all 10 workflow presets, 13 resource presets with full settings matrices, PSO algorithm, tool-execution orchestrator, load balancer, reasoning controller, multi-agent system, health monitoring, budget enforcement, hooks / filters, storage keys, admin UI, and service file index)
* Version bumped to 1.1.9 across plugin header, `WP_MCP_AI_VERSION` constant, readme.txt stable tag, and CHANGELOG.md

= 1.1.8 - April 15, 2026 =

**Erlang C Queuing Theory Tools, Full Tool-Reference Audit**

*Erlang C Workforce Management Tools*

* 4 new tools in the base plugin (no Pro addon required) built on the Erlang C formula
* `calculate_erlang_c` — general-purpose staffing solver: given arrival rate, average handle time, and target SLA returns agents needed, probability of waiting, avg wait time, and utilisation
* `erlang_c_concurrency_advisor` — reads plugin session telemetry and returns a data-driven recommendation for the Max Concurrent Sessions setting
* `erlang_c_staffing_advisor` — multi-channel staffing with chat concurrency multiplier, bot-deflection-rate adjustment, and optional NICE WFM / Genesys / Verint / Calabrio endpoint integration
* `erlang_c_queue_health` — real-time SLA monitoring: polls a contact-centre REST endpoint, fires `wp_mcp_ai_queue_alert` action on breach, stores snapshots in JetEngine CCT
* New `wp_mcp_ai_queue_alert` action hook for SLA breach notifications — full parameter schema documented in `docs/reference/hooks/hooks-reference.md`
* Shared helper class `WP_MCP_AI_Erlang_C` with `erlang_c()`, `avg_wait_time()`, `min_agents_for_service_level()`, and `service_level()` static methods

*Documentation*

* `docs/reference/tools/tool-reference.md` fully audited — all 250+ tools in `load_default_tools` (base + extended) now documented
* 14 new sections added to tool-reference.md: OpenAI file/model management, text embeddings & vector stores, multi-agent orchestration, agent memory management, reasoning & code analysis, deep research, browser-native AI (client-side NLP), Yahoo Fantasy Football toolkit, Newsletter plugin integration, WP All Import/Export integration, Flowhub cannabis dispensary, PayHere payment gateway, and Erlang C queuing tools
* New feature guide `docs/features/erlang-c-staffing-tools.md` with industry standards table, usage scenarios, and helper class API reference
* `docs/reference/hooks/hooks-reference.md` — added `wp_mcp_ai_queue_alert` section with full `$snapshot` schema and Slack/webhook usage example
* `docs/QUICK_REFERENCE.md` — updated to v1.1.8 with Erlang C in Recent Updates
* `docs/DOCUMENTATION_INDEX.md` — added April 15 update block and new feature doc entry

*Compliance*

* Full re-audit of base plugin against all 13 WordPress.org Plugin Developer Guidelines — all pass
* New compliance document `docs/operations/compliance/WORDPRESS_ORG_COMPLIANCE_2026_04_15.md` with detailed evidence for each guideline
* Pro Addon External Services (P1–P3: Replicate, ESPN Fantasy, Yahoo Fantasy) documented in readme.txt, clearly marked as not present in base plugin
* Version bumped to 1.1.8 across plugin header, constants, readme.txt, and CHANGELOG.md

= 1.1.7 - April 11, 2026 =

**MCP Protocol Completion, MCP Apps, CRE Debt Toolkit, Pro Professions/Teams, Compliance Hardening**

*MCP Protocol 2024-11-05 Completion (April 14)*

* Full MCP 2024-11-05 spec compliance — all 11 protocol methods now implemented
* `resources/read` — read resource content by URI with MIME-typed responses (text or blob)
* `prompts/get` — get full prompt content with system instructions and argument values
* `ping` — server liveness check
* `completion/complete` — argument autocompletion (enum/boolean for tools, slug matching for prompts)
* `logging/setLevel` — client-controlled log verbosity (8 standard levels, action hook: `wp_mcp_ai_mcp_logging_set_level`)
* `notifications/cancelled` — request cancellation handler (action hook: `wp_mcp_ai_mcp_request_cancelled`)
* JSON-RPC batching — process up to 20 messages per batch (configurable via `wp_mcp_ai_max_batch_size` filter)
* Tool annotations — maps `WP_MCP_AI_Tool_Capability_Flags_Interface` to MCP hints (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`)
* `Mcp-Session-Id` session management — transient-backed session state with 1 hour TTL
* Comprehensive test suite for all new methods (`test-mcp-protocol-completion.php`)

*New Features*

* MCP Apps (SEP-1865) — Per-assistant remote MCP server connections (up to 10 per assistant) with JSON-RPC 2.0 tool bridging, transient-cached discovery, admin metabox, and REST endpoints
* CRE Debt & Securitization Pro Toolkit — 57 new tools across 5 modules (Originations, Underwriting, CMBS, Debt Fund, Asset Management) with shared financial calculator engine
* CRE Debt CPT/CCT infrastructure with Chart.js admin dashboard
* 36 new pro toolkit professions across 5 knowledge bases (CRE debt, financial services, digital media, business operations, specialized services)
* 17 new team configurations including 7 CRE debt lifecycle teams and 10 cross-functional pro toolkit teams
* Total professions: 296 (was 259). Total teams: 100
* Assistant tool presets updated with new pro toolkit tool slugs

*WordPress.org Compliance (April 9–11)*

* Fixed 2 broken URLs in readme.txt External Services section (Trade.gov, Mailjet)
* Corrected 13 base tool capability flags from `local-only` to `external-api` (tools making external HTTP calls)
* Restricted CLI assistant export to dedicated uploads subdirectory (`uploads/mcp-ai/exports/`)
* Removed sync-docs file write to plugin/theme directories
* AJAX capability checks added — `dismiss_directory_notice` and `dismiss_price_notice` require `manage_options`
* $_POST sanitisation hardened — `sanitize_key()` on keys and `sanitize_text_field( wp_unslash() )` on values
* Missing closing class braces fixed in vision-object-localization and vision-product-search tools
* Full proactive audit passed — ABSPATH guards, text domain, CDN scripts, obfuscation, redirects, nonces, SQL, sanitization

*Algorave Audio Fixes (April 8–11)*

* AudioContext synchronous resume within user-gesture handler before async operations
* channelCount=0 proxy fix — data descriptor for maxChannelCount clamped to [1,32] with eager initializeAudioOutput
* Visualizer AnalyserNode connection timing fixed across 5 PRs
* Async aliasBank CDN redirect and unhandled rejection fixes

*Security*

* nodemailer updated to 8.0.5 (SMTP CRLF injection fix)
* basic-ftp updated to 5.2.1 (CRLF command injection fix)
* mathjs and langsmith updated for security vulnerabilities

*Build*

* Production classmap regenerated via `composer install --no-dev --classmap-authoritative`
* All 30+ distribution ZIPs rebuilt for v1.1.7
* CLAUDE.md excluded from plugin ZIP builds

= 1.1.6 - April 2026 (Updated April 6) =

**A2A Protocol, JetEngine MCP, Agent Command Center, Chat Bubble Widget**

* JetEngine 3.8 MCP Server integration — 7 new Pro tools for CPT/taxonomy/meta field creation via JSON-RPC 2.0
* Agent-to-Agent (A2A) protocol — `/.well-known/agent.json` discovery, task state machine, push notifications
* Agent Command Center dashboard — 7 tabs with KPI cards, analytics, approvals, uptime monitoring
* Floating chat bubble widget for Elementor and Gutenberg — 4 positions, dark mode, WCAG keyboard nav
* Anthropic & Gemini subscription tier support with custom base URLs
* ECA Pro Toolkit — 24 new tools across 8 categories plus 4 upgraded tools
* Image validation tools for product actualization and vehicle estimates (A–F ratings)
* 5 new agent workflow presets (supervisor, pipeline, swarm, hierarchical, review QA)
* Chat UI sub-agent panel with agent cards, workflow tracker, delegation notices
* Enterprise TMA templates — 5 inline templates upgraded to 5-tab architecture
* New Shopify Shop TMA (React SPA) + critical Shopify Jewelry TMA fixes
* Per-connection TMA URL routing for multi-bot Telegram setups
* Schedule preset install overrides for `assistant_id` and `credentials`

**Security**

* SQL query hardening — `$wpdb->prepare()` replacing raw interpolation in 5 files
* Guest token TTL wired to admin setting with absolute max (7 days) enforcement
* Output escaping fix — shortcode content via `wp_kses_post()`
* Removed unsafe `urldecode()` after `sanitize_text_field()`
* Lodash security vulnerability fix in pro addon

**Bug Fixes**

* `execute()` signature compatibility fix for 7 JetEngine MCP tool classes
* Analytics tab wired to actual hooks with real per-agent metrics
* TMA React imports from `react` directly (not `@wordpress/element`)
* Multiple TMA auth, session, and white screen fixes
* Model pricing auto-update for missing CCT models

= 1.1.6 - April 2026 =

**WordPress.org Compliance — Final Pass Before Resubmission**

* Optional component downloads now require explicit opt-in consent (admin notice with Download button)
* All "Powered by" / credit attribution gated behind explicit administrator opt-in setting
* Fixed 3 invalid URLs in External Services section (ReliefWeb, NV Digital Services)
* Updated Symfony packages to 6.4.36 (cache, validator, http-client)
* Added ITA Tariff Rates API to External Services documentation
* CLI export command restricted to WordPress uploads directory (security hardening)
* Field-specific sanitization for `register_setting()` — API keys/secrets preserved correctly
* REST API `/no-sse` endpoint uses correct `permissions_check_assistant_list` callback
* JSON input from `$_POST` now sanitized with `wp_mcp_ai_sanitize_recursive()` after decode
* Cookie names and values sanitized before forwarding in JetEngine tool handlers
* Full 13-guideline compliance audit completed and verified
* NVIDIA NIM added as 8th AI provider in Getting Started wizard

**Security**

* Fixed brace-expansion zero-step sequence DoS (CVE-2026-33750)
* Fixed serialize-javascript CPU exhaustion (CVE-2026-34043)

= 1.1.5 - March 2026 =

**New: NV oOS Canvas Addon**

* New standalone `nvoos-canvas` WordPress plugin distributes the platform-specific canvas npm binary (Linux-only, ~50 MB compressed) as a separate, optional install
* CI builds and commits `nvoos-canvas-linux-x64.zip` and `nvoos-canvas-linux-arm64.zip` to `build/`
* OCR service detects canvas via `NVOOS_CANVAS_PATH` env var; falls back to node_modules if addon absent
* Canvas admin install hint updated to `npm install canvas@2` with EACCES workaround for shared hosts
* New `canvas-service.js` Node.js service supports `generate` (canvas spec) and `chart` (Chart.js) actions

**Embedded LLM (Pro Addon)**

* Moved `WP_MCP_AI_Embedded_Client` and `WP_MCP_AI_Embedded_Model_Ajax` from `includes/` to `addons/pro/includes/`
* Base plugin language model router uses `class_exists()` guard and falls back when Pro is absent
* Added Gemma 2 2B Instruct (`gemma-2-2b-it-q4_k_m`) as 4th server-side GGUF model; set `gemma-2-2b-it-q4f16_1-MLC` as client-side WebLLM default
* `create_soname_symlinks()` creates SONAME symlinks after extraction; falls back to `copy()` when `symlink()` is blocked (e.g., Cloudways)
* `get_shared_libs_status()` calls `create_soname_symlinks()` on every status check to auto-repair missing SONAMEs
* `sanitise_binary_filename()` uses `[A-Za-z0-9._-]` allowlist to preserve `.so.X.Y.Z`-style filenames
* `build_inference_command()` prepends `LD_LIBRARY_PATH` for reliable shared library resolution
* `test_connection()` uses stderr fallback for llama.cpp builds b8479+ that write `--version` to stderr
* Provider diagnostic page now shows resolved binary path and all co-located shared library filenames
* Fixed fatal `E_ERROR` when `symlink()` is in `disable_functions` on provider diagnostic page
* Added Re-install llama.cpp Binary button in embedded provider settings
* Added `WP_MCP_AI_Logger` integration to all key embedded client operations

**Embedded Chat Client — Streaming Fixes**

* `chat.js` now uses native `fetch + ReadableStream` for SSE (bypasses Ky's 30 s AbortController timeout)
* `send_sse_headers()` disables `zlib.output_compression`, calls `ob_end_clean()`, uses `wp_die()` — fixes ERR_HTTP2_PROTOCOL_ERROR on HTTP/2 connections
* `max_tokens` injected from `WP_MCP_AI_Resource_Manager` into shortcode config (no more hardcoded 2048 fallback)
* Elementor widget now always emits `enable_streaming` attribute so disabling streaming takes effect
* `disableForm()` scoped to input area and send button only — message bubble buttons remain clickable during streaming
* `WebLLMFunctionCallingClient` class definition deferred inside `waitForDependencies().then()` for reliable dependency resolution

**New Email Integrations (Pro)**

* New tool `send_mailgun_email` — transactional email via Mailgun API (US/EU regions; tags as array o:tag fields)
* New tools `send_brevo_email`, `manage_brevo_contacts`, `get_brevo_statistics` — Brevo email marketing and CRM via api-key header auth

**New WP-CLI Command Groups (Pro)**

* `wp mcp-ai pro status` — Pro addon version, license, and active toolkit summary
* `wp mcp-ai toolkit list/enable/disable` — manage Pro toolkits from CLI
* `wp mcp-ai connection list/get/test/delete` — manage Chat Channel connections
* `wp mcp-ai project list/get/create/delete` — manage AI project CPT entries
* `wp mcp-ai task list/get/create/complete/delete` — manage AI task CPT entries

**WordPress.org Compliance**

* Telemetry opt-in: activation tracking is now disabled by default; users must explicitly enable it via Settings → NV oOS → General → Enable Activation Tracking (Guideline 7)
* Removed Pro add-on gating from base tool registry; all base plugin tools load without license checks (Guideline 5)
* Improved `sanitize_settings_callback` to recursively sanitize nested array settings using `sanitize_textarea_field()` and `esc_url_raw()` (Guideline 6)
* Fixed 15 broken external service URLs in readme.txt (Guideline 2)
* Confirmed Pro addon is a genuine extension: no base plugin tools are gated behind a license check

**Bug Fixes**

* Fixed "tool_call_id did not have response messages" error: orphaned assistant `tool_calls` messages filtered out before next turn when `max_iterations` is reached
* Fixed DICOM UID filesystem paths: `sanitize_uid_for_path()` used instead of `sanitize_file_name()` to preserve dots in UIDs
* Added `WP_MCP_AI_Logger` to all 5 Ollama client methods — all concrete AI chat clients now fully logged
* Fixed Pro Workflow Builder webpack config (correct output path/entry name) and CI auto-commit of built assets
* Regenerated production classmap autoloader; removed stale gitlinks in `addons/pro/vendor/`

**Dependencies**

* Updated `symfony/cache` from 6.4.34 to 6.4.35
* Updated `symfony/validator` from 6.4.34 to 6.4.35
* Regenerated production classmap autoloader

= 1.1.4 - March 2026 =

**Security Hardening**

* Upgraded AES encryption to AES-256-GCM for stronger encryption of stored credentials
* Fixed finfo MIME detection to fail-closed (deny on detection failure rather than allow)
* Fixed OCR error responses that could expose internal path/stack information
* Fixed Discord webhook replay attack protection
* Enforced HTTPS for all external webhook and remote connections
* Fixed backup file path leak in file export handlers
* Added ZIP bomb protection to file upload handlers

**Chat Channels — Slack**

* Fixed Slack bot not responding to channel @mentions
* Slack auto-reply now uses mrkdwn formatting; channel settings surfaced on connection page
* Enhanced Slack channel type handling per 2025 industry standards

**Chat Channels — Google Chat**

* Fixed Google Chat bot not responding: route conflict, OIDC bypass, DM initial message handling
* Fixed Google Chat channel events not received when connection tests pass
* Fixed Google Chat auto-reply for DMs/mentions and connection test when OIDC is disabled
* Added Google Chat webhook diagnostic log to Settings page for easier connection troubleshooting

**Chat Channels — Microsoft Teams**

* Enhanced Teams webhook with cross-channel consistency improvements (Slack/Telegram parity)
* Extended Teams to support multiple simultaneous connections with per-connection setup guide
* Added Teams declarative agent manifest generation to Chat Channels admin page
* Microsoft OAuth 2.0 one-click connect: Azure AD client ID/secret, auto-refresh token

**Chat Channels — Telegram**

* Added typing indicator and rate-limiting enforcement for Telegram chat channel
* Dynamically integrate mcp-ai-slash-commands plugin into Telegram bot at runtime
* Added /vectorstore to Telegram /start message and admin slash command reference table

**Telegram Mini App**

* TMA doctor tab now uses the assistant assigned to the connection (not a hardcoded default)
* AI replies in TMA doctor and coach chat tabs rendered as formatted HTML via Markdown renderer
* Vitals log import fallback improved for partial-row edge cases

**AI Providers — Gemini**

* Gemini embedding: added gemini-embedding-001 model, output_dimensionality parameter, 9 new task types, per-request model override in batch calls

**Pro Toolkit**

* Product actualization tool: AI-powered provider-agnostic product integration using Gemini (gemini-2.5-flash-image) or OpenAI (gpt-image-1) as default mode; composite mode retained as legacy fallback
* Fixed JetEngine list_types returning null slugs/names when JetEngine returns incomplete type data
* Fixed wp_tempnam() undefined function error in pro tools by requiring wp-admin/includes/file.php
* Fixed consolidate & add page: singleton pattern, init registration, sanitize delegation
* Fixed HTML-to-PDF tool: load wp-admin includes before calling media_handle_sideload
* Fixed PDF generation: pdfkit, cheerio, docx, and exceljs now bundled into generate-*.bundle.js (no runtime node_modules needed)

**WordPress.org Compliance**

* Excluded .gitattributes hidden file from plugin distribution ZIPs
* Included composer.json alongside vendor/ directory in distribution ZIPs (plugin check requirement)
* Created languages/ directory so the Domain Path: /languages plugin header resolves correctly

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
* **296 Profession Orchestration** - Intelligent agent role assignment via WP-CLI commands

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
* Multi-provider support: OpenAI, Gemini, NVIDIA NIM, Ollama, LM Studio
* Full MCP (Model Context Protocol) server implementation
* Modern chat interface with streaming responses
* 296 profession templates across 17 industry categories
* Comprehensive REST API
* SSE (Server-Sent Events) streaming support
* Rate limiting and usage tracking
* Capability-based access control
* WordPress multisite support
* Extensive documentation

= Development History =

This plugin has been in active development since October 2024. See the complete [CHANGELOG.md](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/CHANGELOG.md) for detailed development history.

== Upgrade Notice ==

= 1.1.15 =
**OpenRouter + DeepSeek** added as first-class providers; **Kimi K2.6 + Qwen 3.6** in model catalog. **LM Studio** gains native cURL SSE streaming. **Orchestration Phases 1–7** re-landed with JetEngine CCT init-priority fix: HITL approval queue, prompt-injection guardrail, structured output, OTel, DAG builder, durable runs, triggers/webhooks, sub-agents, Pro vector-store adapter, and team budget manager. **LLM Harnessing Subsystem (Layers A–H)** ships GA. **19 new slash commands** (11 base + 8 Pro). **Chat-client Memory Bridge G-series** complete with site-wide toggle. **Retroactive Transcript Mining** stuck-job root causes fixed. **Graphify NV oOS data-source bridge** with private CPTs, CCT resolvers, and MemPalace edges. Multiple stability fixes (transcript-mining, workflow tab, credential nonces, JetEngine CCT prefix, site-health polyfill). Safe upgrade.

= 1.1.14 =
**Markup Subsystem (Base)** — tools can now pause the agentic loop, surface a Konva canvas in the chat for the user to draw on, and resume with the rasterised mask / crop / region. Three tools (`edit_openai_image`, `crop_image`, `edit_gemini_image`) are markup-aware out of the box. New **NV oOS → Markup Telemetry** dashboard, `/markup-stats` slash command, and 4 actions + 4 filters. **Agent Skills v2** ships progressive disclosure (`load_skill` tool), curated skill packs, and remote skill catalogues (Pro). **MemPalace Capture Framework Phases A + B1** layer five capture tools onto the durable agent-memory bridge from 1.1.13. **Graphify** now treats JetEngine CPTs and CCTs as first-class citizens in the knowledge graph, Graph Explorer, related-content widget, and embeddings re-index path. Plus follow-up fixes to the orchestration dashboard, the Pro Mini App Builder enqueue path, the skill-catalogue cURL fetcher, the Pro Medical Imaging Viewer bundle, and the stored-embeddings admin display. Safe upgrade.

= 1.1.13 =
OpenAI **`gpt-image-2` (Images 2.0)** is now the default image model with native 2K aspect-ratio support. Phase 4a/4b adds a durable agent-memory bridge that mirrors transient memory into a JetEngine `ai_agent_memories` CCT (industry-standard schema; transients still primary read path). New AI Harmonization sub-toolkit (14 Pro tools). Production-only Composer autoloader so the repo can be cloned as a deployable plugin (`composer install --no-dev --classmap-authoritative` — no separate `dump-autoload`). Existing sites with a saved image-model setting are unaffected. Safe upgrade.

= 1.1.9 =
Measurement subsystem ships GA: stock metrics, eval suites, regression alerting, persistent metric store, and `wp mcp-ai measurement` CLI. New `mcp_ai_metric_events` table is dropped on uninstall when `Delete data on uninstall` is enabled. PHPUnit upgraded to 11 to resolve argument-injection CVE GHSA-qrr6-mg7r-m243 (test-suite only; affects CI/dev environments). No breaking changes for runtime plugin users; safe upgrade.

= 1.1.0 =
Major update with DeepSeek V4 multi-agent orchestration, 4 new Social Media Analytics tools, memory-based toolkit tracking, and 7 critical bug fixes. Recommended for all users.

= 1.0.0 =
Initial release. Welcome to Open Operator System!

== External Services ==

**IMPORTANT:** This plugin connects to various third-party services to provide AI functionality and optional features. All external services used by this plugin are documented below.

**📖 Additional Details:** For supplementary documentation about data transmission and legal requirements, see our [External Services Reference](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/EXTERNAL_SERVICES.md).

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
* **Privacy Policy:** https://policies.google.com/privacy

**2a. Google Gemini Semantic Retrieval API (Corpus / RAG)**
* **Purpose:** Native Retrieval-Augmented Generation (RAG) — store and query document corpora for grounded AI responses
* **Data Sent:** Corpus display names, document content uploaded to corpora, natural-language query strings; only transmitted when a corpus is configured for the assistant and the user sends a message
* **When:** Only when a Gemini assistant has a corpus name configured (optional feature, off by default)
* **Service URL:** https://generativelanguage.googleapis.com/v1beta/corpora
* **Terms of Service:** https://ai.google.dev/terms
* **Privacy Policy:** https://policies.google.com/privacy

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
* **Service URL:** Your local server only (default: http://localhost:11434)
* **Terms of Service:** https://github.com/ollama/ollama/blob/main/LICENSE (MIT License)
* **Privacy Policy:** N/A — self-hosted software; no data leaves your server

**5. LM Studio (Self-Hosted)**
* **Purpose:** Local AI with function calling support
* **Data Sent:** None (runs entirely on your computer)
* **When:** When configured as AI provider
* **Service URL:** Your local computer only (default: http://localhost:1234)
* **Terms of Service:** Self-hosted software — see https://github.com/lmstudio-ai
* **Privacy Policy:** N/A — self-hosted software; no data leaves your computer

**6. Cloudflare Workers AI**
* **Purpose:** AI image generation and inference
* **Data Sent:** Image generation prompts, model inference requests
* **When:** When using Cloudflare AI tools
* **Service URL:** https://api.cloudflare.com/client/v4/accounts/{account_id}/ai/run/{model}
* **Terms of Service:** https://www.cloudflare.com/terms/
* **Privacy Policy:** https://www.cloudflare.com/privacypolicy/

**6a. NVIDIA NIM API**
* **Purpose:** Cloud AI inference via NVIDIA's optimized model platform (Llama, Mistral, Nemotron, and more)
* **Data Sent:** Chat messages, system prompts, tool results
* **When:** Every time an AI assistant is used with NVIDIA NIM as the provider
* **Service URL:** https://integrate.api.nvidia.com/v1 (default cloud endpoint; supports custom/self-hosted NIM endpoints)
* **Terms of Service:** https://www.nvidia.com/en-us/data-center/products/nvidia-ai-enterprise/eula/
* **Privacy Policy:** https://www.nvidia.com/en-us/about-nvidia/privacy-policy/

**6b. DeepSeek API**
* **Purpose:** Cloud AI inference via DeepSeek's OpenAI-compatible API (deepseek-chat, deepseek-reasoner, deepseek-coder)
* **Data Sent:** Chat messages, system prompts, tool definitions, and tool results
* **When:** Every time an AI assistant is used with DeepSeek as the provider
* **Service URL:** https://api.deepseek.com (default; supports custom base URL for proxies or regional endpoints)
* **Terms of Service:** https://platform.deepseek.com/terms
* **Privacy Policy:** https://platform.deepseek.com/privacy
* **Data Usage:** See DeepSeek's privacy policy for data handling details

**6c. OpenRouter API**
* **Purpose:** Unified OpenAI-compatible AI gateway routing to 200+ models across providers (OpenAI, Anthropic, Google, Meta, Mistral, and more) via a single API key
* **Data Sent:** Chat messages, system prompts, tool definitions, and tool results
* **When:** Every time an AI assistant is used with OpenRouter as the provider
* **Service URL:** https://openrouter.ai/api/v1
* **Terms of Service:** https://openrouter.ai/terms
* **Privacy Policy:** https://openrouter.ai/privacy
* **Data Usage:** See OpenRouter's privacy policy; requests may be routed to upstream provider APIs

**6d. Kimi (Moonshot AI) API**
* **Purpose:** Cloud AI inference via Moonshot AI's OpenAI-compatible API (Kimi K2.6, K2.5, K2, K2-Thinking, and legacy moonshot-v1-* models)
* **Data Sent:** Chat messages, system prompts, tool definitions, and tool results; token-count estimation requests (messages only, no user-identifying data)
* **When:** Every time an AI assistant is used with Kimi as the provider; token estimation runs before each request when configured
* **Service URL:** https://api.moonshot.cn/v1 (default; supports custom base URL for proxies)
* **Terms of Service:** https://platform.moonshot.cn/docs/policy/service-agreement
* **Privacy Policy:** https://platform.moonshot.cn/docs/policy/privacy-policy
* **Data Usage:** See Moonshot AI's privacy policy for data handling details

**6e. DigitalOcean Serverless Inference API**
* **Purpose:** Cloud AI inference via DigitalOcean's OpenAI-compatible serverless inference platform (Llama, DeepSeek-R1, GTE embeddings, and more)
* **Data Sent:** Chat messages, system prompts, tool definitions, tool results, and embedding requests (text only)
* **When:** Every time an AI assistant is used with DigitalOcean as the provider; embedding requests sent when vector-context features are active and DigitalOcean is the embedding provider
* **Service URL:** https://inference.do-ai.run/v1
* **Terms of Service:** https://www.digitalocean.com/legal/terms-of-service-agreement
* **Privacy Policy:** https://www.digitalocean.com/legal/privacy-policy
* **Data Usage:** See DigitalOcean's privacy policy; processed within DigitalOcean's infrastructure

**6f. Baseten API**
* **Purpose:** AI model inference provider for running open-source models (Llama, Mistral, etc.)
* **Data Sent:** Prompt text, conversation history, tool definitions
* **When:** When the Baseten provider is configured with an API key in plugin settings
* **Service URL:** https://inference.baseten.co/v1
* **Terms of Service:** https://www.baseten.co/terms-and-conditions/
* **Privacy Policy:** https://www.baseten.co/privacy-policy/

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
* **Privacy Policy:** https://reliefweb.int/terms-conditions

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

**16. Gmail API & Google OAuth token exchange (Google)**
* **Purpose:** Email search and retrieval for AI assistants; server-side OAuth token exchange during Gmail OAuth setup
* **Data Sent:** OAuth access tokens, search queries, label filters; during OAuth setup: authorisation code and client credentials sent to the token endpoint
* **When:** When Gmail search tools are used after OAuth setup; OAuth code-exchange request is made once during the initial OAuth authorisation flow
* **Service URL:** https://gmail.googleapis.com/gmail/v1/ (Gmail API and profile lookup), https://oauth2.googleapis.com/token (OAuth token exchange)
* **Terms of Service:** https://policies.google.com/terms
* **Privacy Policy:** https://policies.google.com/privacy

**17. remove.bg API**
* **Purpose:** Background removal from images
* **Data Sent:** API key for account verification; images for background removal
* **When:** When the remove background tool is used
* **Service URL:** https://api.remove.bg/v1.0/
* **Terms of Service:** https://www.remove.bg/tos
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
* **Privacy Policy:** https://plaid.com/legal/

**20. PayHere API**
* **Purpose:** Payment processing for Sri Lankan merchants
* **Data Sent:** Merchant credentials, payment data
* **When:** When PayHere payment tools are used
* **Service URL:** https://www.payhere.lk/merchant/v1/
* **Terms of Service:** https://www.payhere.lk/terms
* **Privacy Policy:** https://www.payhere.lk/privacy

**21. Auth0 API**
* **Purpose:** Enterprise authentication and user management via Auth0
* **Data Sent:** OAuth tokens, user subject identifiers; JWKS public keys retrieved for JWT signature verification (no user data transmitted)
* **When:** When Auth0 integration is configured for authentication
* **Service URL:** https://{your-auth0-domain}/oauth/token (server-side POST: client credentials token generation); https://{your-auth0-domain}/.well-known/jwks.json (server-side GET: JWT public-key retrieval for bearer token validation); https://{your-auth0-domain}/api/v2/ (optional: user management API)
* **Terms of Service:** https://auth0.com/web-terms
* **Privacy Policy:** https://auth0.com/privacy

**22. Mubert Music API**
* **Purpose:** AI-generated music and audio track creation
* **Data Sent:** Music generation parameters (tempo, genre, duration), API key
* **When:** When music generation tools are used
* **Service URL:** https://music-api.mubert.com/api/v3/public/tracks
* **Terms of Service:** https://mubert.com/documents/mubert_website_tou.pdf
* **Privacy Policy:** https://mubert.com/render/docs/privacy-policy

**23. GDACS (Global Disaster Alert and Coordination System)**
* **Purpose:** Global disaster and emergency event data retrieval
* **Data Sent:** None (read-only public data retrieval)
* **When:** When the GDACS disaster events tool is used
* **Service URL:** https://www.gdacs.org/gdacsapi/api/events/geteventlist/MAP
* **Terms of Service:** https://www.gdacs.org/About/termofuse.aspx
* **Privacy Policy:** https://www.gdacs.org/About/overview.aspx

**23a. ITA Tariff Rates API (Trade.gov)**
* **Purpose:** Automated tariff rate lookups for international trade compliance (Pro addon feature)
* **Data Sent:** Country codes, product classification codes (HS codes), API key
* **When:** When the import duty lookup tool is used (requires ITA API key configuration)
* **Service URL:** https://api.trade.gov/v1/tariff_rates/search
* **Terms of Service:** https://developer.trade.gov/
* **Privacy Policy:** https://developer.trade.gov/ (ITA Developer Portal — U.S. government data; see site footer for ITA privacy information)

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
* **When:** Only when a site owner explicitly opts in via Settings → NV oOS → "Enable activation tracking". Tracking is **disabled by default** and requires explicit consent. Tracking is never sent from local/development environments.
* **Service URL:** https://nvdigitalsolutions.com/api/plugin-tracking/activation
* **Terms of Service:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/LICENSE (GPLv3)
* **Privacy Policy:** https://nvdigitalsolutions.com/privacy-policy
* **Opt-In:** Enable via Settings → NV oOS → "Enable activation tracking" or return `true` from the `wp_mcp_ai_enable_usage_tracking` filter. Tracking is OFF by default.

**27. NV Digital Solutions License Server & Optional Component Downloads**
* **Purpose:** (a) Optional license validation for future premium add-on support; (b) On-demand download of optional plugin components (profession-playbook knowledge base) hosted on GitHub releases to reduce base plugin ZIP size — downloads only occur after explicit administrator consent via an admin notice
* **Data Sent:** (a) License key, site URL, product identifier — only when a user manually enters and activates a license key; (b) Standard HTTP GET request with no user data — only the plugin version is embedded in the URL path
* **When:** (a) Only when a user manually enters and activates a license key; (b) Only when the site administrator explicitly clicks "Download Optional Components" in the admin notice (opt-in required, never automatic)
* **Service URL:** https://nvdigitalsolutions.com/api/plugin-tracking/activation (license/tracking server); https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases (optional component ZIP downloads from the plugin's own GitHub releases)
* **Terms of Service:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/LICENSE (GPLv3); https://docs.github.com/en/site-policy/github-terms/github-terms-of-service
* **Privacy Policy:** https://nvdigitalsolutions.com/privacy-policy; https://docs.github.com/en/site-policy/privacy-policies/github-privacy-statement

= Optional OAuth/Integration Services =

These services are only used if you explicitly configure OAuth integrations:

**28. GitHub API & GitHub OAuth token exchange**
* **Purpose:** Repository management, code search, issue tracking, and OAuth authorisation flow to connect the GitHub integration
* **Data Sent:** OAuth tokens, repository queries, commit data; during OAuth setup: client ID, client secret (as Basic Auth header), authorisation code, and redirect URI
* **When:** When GitHub tools are used after OAuth setup; the OAuth token exchange endpoint is called once per authorisation when a user connects their GitHub account
* **Service URL:** https://api.github.com (GitHub REST API); https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/authorizing-oauth-apps (OAuth token exchange — server-side POST to exchange the authorisation code for access/refresh tokens)
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
* **Service URL:** https://appcenter.intuit.com/connect/oauth2 (OAuth authorize), https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer (OAuth token exchange), https://quickbooks.api.intuit.com/v3 (accounting data API)
* **Terms of Service:** https://accounts.intuit.com/terms-of-service
* **Privacy Policy:** https://www.intuit.com/privacy/statement/

**31. Mailjet API**
* **Purpose:** Email marketing and transactional email
* **Data Sent:** OAuth tokens, email campaign data
* **When:** When Mailjet tools are used after OAuth setup
* **Service URL:** https://app.mailjet.com/oauth/authorize (OAuth authorize), https://api.mailjet.com/v3/REST (email campaign API)
* **Terms of Service:** https://www.mailjet.com/legal/terms/
* **Privacy Policy:** https://www.mailjet.com/privacy-policy/

**32. Tavily Search API**
* **Purpose:** AI-first web search purpose-built for LLM agents and RAG pipelines; returns structured results including page excerpts and publication dates
* **Data Sent:** Search query string; sent only when Tavily is selected as the web search provider
* **When:** When an AI assistant uses the `web_search` tool and the provider setting is set to "Tavily"
* **Service URL:** https://api.tavily.com/search
* **Terms of Service:** https://www.tavily.com/terms
* **Privacy Policy:** https://docs.tavily.com/documentation/privacy

**33. Exa AI Search API**
* **Purpose:** Neural/semantic web search purpose-built for AI agents; returns full-text page content and metadata
* **Data Sent:** Search query string and search parameters (number of results, content type)
* **When:** When an AI assistant uses the `web_search` tool and the provider setting is set to "Exa"
* **Service URL:** https://api.exa.ai/search
* **Terms of Service:** https://trust.exa.ai/
* **Privacy Policy:** https://exa.ai/privacy-policy

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

**36. Google Drive API & Google OAuth (Google)**
* **Purpose:** Search and list files stored in a user's Google Drive; server-side OAuth token exchange and user-profile lookup during Google Drive OAuth setup
* **Data Sent:** OAuth access token and search query parameters; during OAuth setup: authorisation code and client credentials (token exchange), access token (profile lookup to confirm authorised email address)
* **When:** When the `search_drive` tool is used after Google OAuth integration is configured; OAuth code-exchange and profile requests are made once during the initial OAuth authorisation flow
* **Service URL:** https://www.googleapis.com/drive/v3 (Drive API), https://oauth2.googleapis.com/token (OAuth token exchange), https://www.googleapis.com/oauth2/v2/userinfo (Google OAuth profile)
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
* **Service URL:** https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.8.1
* **Terms of Service:** https://www.jsdelivr.com/terms
* **Privacy Policy:** https://www.jsdelivr.com/privacy-policy-jsdelivr-net

**40. WebLLM — MLC AI (esm.run CDN)**
* **Purpose:** Browser-native large language model runner; enables the "Embedded Browser LLM" provider to perform inference entirely within the visitor's browser using WebGPU, with no server-side AI API call
* **Data Sent:** None — only the library file itself is downloaded; all inference runs locally in the visitor's browser
* **When:** Only when the "Embedded (Browser)" AI provider is selected for an assistant and the visitor's browser supports WebGPU (opt-in, off by default)
* **Service URL:** https://esm.run/@mlc-ai/web-llm (ESM CDN proxy for https://github.com/mlc-ai/web-llm)
* **Terms of Service:** https://esm.run/ (re-exports packages under their original licences; Apache 2.0 for web-llm)
* **Privacy Policy:** https://esm.sh/ (esm.run is powered by esm.sh — see https://esm.sh/privacy)

**41. Google Cloud Speech-to-Text API**
* **Purpose:** Convert audio recordings to text transcripts using Google Cloud's speech recognition engine
* **Data Sent:** Audio file data (binary); sent only when the speech-to-text feature is explicitly triggered via an AI assistant tool
* **When:** Only when an AI assistant calls the speech-to-text feature and the Gemini provider is configured with a valid API key
* **Service URL:** https://speech.googleapis.com/v1/speech:recognize
* **Terms of Service:** https://cloud.google.com/terms/
* **Privacy Policy:** https://policies.google.com/privacy

**42. Google Cloud Text-to-Speech API**
* **Purpose:** Convert text to natural-sounding speech audio using Google Cloud's text-to-speech engine
* **Data Sent:** Text content to be synthesised; sent only when the text-to-speech feature is explicitly triggered via an AI assistant tool
* **When:** Only when an AI assistant calls the text-to-speech feature and the Gemini provider is configured with a valid API key
* **Service URL:** https://texttospeech.googleapis.com/v1/text:synthesize
* **Terms of Service:** https://cloud.google.com/terms/
* **Privacy Policy:** https://policies.google.com/privacy

**43. QR Server API (api.qrserver.com)**
* **Purpose:** Generate QR code images for two-factor authentication (TOTP) setup; the QR code encodes the authenticator app enrollment URI
* **Data Sent:** TOTP enrollment URI (contains site name, user e-mail address, and the one-time-password secret); the request is made server-side by WordPress — the user's browser never contacts this service directly
* **When:** Only when the `setup_2fa` tool is used and the TOTP (authenticator app) method is selected
* **Service URL:** https://api.qrserver.com/v1/create-qr-code/
* **Terms of Service:** https://goqr.me/api/
* **Privacy Policy:** https://goqr.me/privacy-safety-security/

**44. Crawl4AI (Self-Hosted or Configurable Endpoint)**
* **Purpose:** Web page crawling and content extraction for AI context ingestion and price lookup
* **Data Sent:** Target URLs to crawl, crawl configuration parameters (extraction strategy, chunking, CSS selectors); site URL included in User-Agent header
* **When:** When the `run_crawl4ai_job` or `crawl4ai_price_lookup` tools are used and a Crawl4AI endpoint is configured
* **Service URL:** Configurable via Settings → NV oOS → Crawl4AI Base URL (no default — must be explicitly configured by the administrator); typically self-hosted (e.g., http://localhost:11235)
* **Terms of Service:** https://github.com/unclecode/crawl4ai/blob/main/LICENSE (Apache 2.0)
* **Privacy Policy:** N/A — self-hosted by default; if using a third-party hosted instance, consult that provider's privacy policy

**45. Varnish Cache Server (Self-Hosted Infrastructure)**
* **Purpose:** HTTP cache purging via PURGE requests to a Varnish reverse-proxy server
* **Data Sent:** HTTP PURGE method request with the URL path to invalidate and an optional X-Purge-Regex header; no user data or credentials are transmitted
* **When:** When the `purge_varnish_cache` tool is used
* **Service URL:** Configurable via the `wp_mcp_ai_varnish_host` filter (default: 127.0.0.1:6081 — localhost)
* **Terms of Service:** https://varnish-cache.org/intro/index.html (BSD-2-Clause)
* **Privacy Policy:** N/A — self-hosted infrastructure; no data leaves your server by default

**46. Workforce Management (WFM) Endpoints (User-Configured)**
* **Purpose:** Optional real-time queue data for Erlang C staffing and queue-health tools (supports NICE WFM, Genesys, Verint, Calabrio, or any contact-centre REST API)
* **Data Sent:** HTTP GET request with optional Bearer token; no user or site data is transmitted
* **When:** When the `erlang_c_staffing_advisor` or `erlang_c_queue_health` tools are used with a WFM endpoint configured
* **Service URL:** User-configured via tool arguments (no default endpoint; disabled unless explicitly provided)
* **Terms of Service:** Per the user's WFM vendor agreement
* **Privacy Policy:** Per the user's WFM vendor agreement

**47. Agent-to-Agent (A2A) Protocol — Remote Agent Discovery & Task Delegation**
* **Purpose:** Discover and communicate with remote A2A-compatible agents via the Google A2A open protocol
* **Data Sent:** HTTP GET to `{agent_url}/.well-known/agent.json` for discovery; JSON-RPC task payloads (task description, context) for delegation
* **When:** When a site administrator configures remote agents and the AI assistant delegates tasks via A2A
* **Service URL:** User-configured remote agent URLs (no default endpoint; disabled unless explicitly configured)
* **Terms of Service:** https://a2aproject.github.io/A2A/ (Apache-2.0 protocol specification)
* **Privacy Policy:** Per the remote agent operator's privacy policy

**48. Mesh Router — Peer-to-Peer Agent Communication**
* **Purpose:** Distribute AI workload across multiple NV oOS instances configured as mesh peers
* **Data Sent:** JSON-RPC requests containing task payloads and routing metadata to configured peer endpoints
* **When:** When mesh routing is enabled and peer endpoints are configured by the site administrator
* **Service URL:** User-configured peer NV oOS instance URLs (no default; disabled unless explicitly configured)
* **Terms of Service:** N/A — communication between self-hosted NV oOS instances operated by the same organisation
* **Privacy Policy:** N/A — data stays within the operator's own infrastructure

= Pro Addon External Services =

The following services are **only** used by the separately installed **NV oOS Pro** addon. They are **not** present in the base plugin. They are documented here for completeness and transparency.

**P1. Replicate API (AI Music Generation)**
* **Purpose:** AI-powered music and audio generation via Replicate's hosted model inference
* **Data Sent:** Music generation parameters (prompt, tempo, duration, genre), API key
* **When:** When the AI music generation tool is used (Algorave addon, requires Pro)
* **Service URL:** https://api.replicate.com/v1/predictions
* **Terms of Service:** https://replicate.com/terms
* **Privacy Policy:** https://replicate.com/privacy

**P2. ESPN Fantasy Football API**
* **Purpose:** Retrieve ESPN Fantasy Football league data, rosters, scores, and standings
* **Data Sent:** League ID, season ID, team ID; SWID and ESPN_S2 authentication cookies for private leagues
* **When:** When ESPN Fantasy Football tools are used (Fantasy Football addon, requires Pro)
* **Service URL:** https://fantasy.espn.com/apis/v3/games/ffl/seasons
* **Terms of Service:** https://www.espn.com/espn/news/story?page=terms-of-use
* **Privacy Policy:** https://privacy.thewaltdisneycompany.com/en/current-privacy-policy/

**P3. Yahoo Fantasy Sports API**
* **Purpose:** Retrieve Yahoo Fantasy Football league data, rosters, player stats, standings, and trade analysis
* **Data Sent:** League key, player keys, OAuth2 access token (obtained via Yahoo OAuth2 — see service #38 above)
* **When:** When Yahoo Fantasy Football tools are used (Fantasy Football addon, requires Pro)
* **Service URL:** https://fantasysports.yahooapis.com/fantasy/v2/
* **Terms of Service:** https://legal.yahoo.com/us/en/yahoo/terms/otos/index.html
* **Privacy Policy:** https://legal.yahoo.com/us/en/yahoo/privacy/index.html

**What is sent to external services:**
* User messages and chat conversations (AI providers only)
* File uploads (AI providers only)
* Search queries (when using search/weather tools)
* OAuth credentials (when using optional integrations)
* Anonymous activation data (opt-in only; see service #26 above)

**What is NOT sent:**
* WordPress admin credentials
* Database contents (unless explicitly requested via tool)
* Site configuration (unless using diagnostic tools)
* Other user data not related to AI requests

**When data is sent:**
* Only when you or your users actively use AI features
* Only to services you have explicitly configured
* Anonymous activation tracking only when explicitly opted in (see service #26 above)

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

**Activation Tracking (Opt-In Only):**
* Anonymous usage data is sent to NV Digital Solutions only when explicitly enabled by the site administrator
* Data collected: hashed site URL (SHA-256 HMAC, non-reversible), plugin version, WordPress version, PHP version, locale, multisite status
* No personally identifiable information (PII) is collected or stored
* No tracking scripts, cookies, or beacons are used
* Tracking is **OFF by default** — enable via Settings → NV oOS → "Enable activation tracking" or the `wp_mcp_ai_enable_usage_tracking` filter
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
* Processed according to: [Google AI Privacy](https://policies.google.com/privacy)
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

**DeepSeek (when configured):**
* Data sent to: https://api.deepseek.com
* Processed according to: [DeepSeek Privacy Policy](https://platform.deepseek.com/privacy)
* Terms of Service: [DeepSeek Terms](https://platform.deepseek.com/terms)
* Used for: Chat completions with deepseek-chat, deepseek-reasoner, and deepseek-coder models

**OpenRouter (when configured):**
* Data sent to: https://openrouter.ai/api/v1
* Processed according to: [OpenRouter Privacy Policy](https://openrouter.ai/privacy)
* Terms of Service: [OpenRouter Terms](https://openrouter.ai/terms)
* Used for: Unified AI gateway routing requests to 200+ upstream models

**Kimi (Moonshot AI) (when configured):**
* Data sent to: https://api.moonshot.cn/v1
* Processed according to: [Moonshot AI Privacy Policy](https://platform.moonshot.cn/docs/policy/privacy-policy)
* Terms of Service: [Moonshot AI Service Agreement](https://platform.moonshot.cn/docs/policy/service-agreement)
* Used for: Chat completions with Kimi K2.6, K2.5, K2, and moonshot-v1 model families; supports 256K context and tool calling

**DigitalOcean Serverless Inference (when configured):**
* Data sent to: https://inference.do-ai.run/v1
* Processed according to: [DigitalOcean Privacy Policy](https://www.digitalocean.com/legal/privacy-policy)
* Terms of Service: [DigitalOcean Terms of Service](https://www.digitalocean.com/legal/terms-of-service-agreement)
* Used for: Chat completions and embeddings via DigitalOcean's cloud AI inference platform

**Web Search Providers (when configured):**
* Brave Search: data sent to https://api.search.brave.com — [Privacy](https://brave.com/privacy/browser/) | [Terms](https://brave.com/terms-of-use/)
* Tavily: data sent to https://api.tavily.com — [Privacy](https://docs.tavily.com/documentation/privacy) | [Terms](https://www.tavily.com/terms)
* Exa AI: data sent to https://api.exa.ai — [Privacy](https://exa.ai/privacy-policy) | [Terms](https://trust.exa.ai/)
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

**Google Cloud Speech-to-Text and Text-to-Speech APIs (when voice features are used):**
* Data sent to: https://speech.googleapis.com (audio → text), https://texttospeech.googleapis.com (text → audio)
* Processed according to: [Google Privacy Policy](https://policies.google.com/privacy)
* Terms of Service: [Google Cloud Terms](https://cloud.google.com/terms/)
* Used for: Transcribing audio uploads to text and synthesising speech from AI responses; only triggered when the respective tool is called by an AI assistant

**QR Server API (when TOTP two-factor authentication setup is used):**
* Data sent to: https://api.qrserver.com — server-side request made by WordPress (not by the user's browser); the request payload is the TOTP enrollment URI (contains site name, user e-mail address, and the one-time-password secret)
* Processed according to: [goQR.me Privacy Policy](https://goqr.me/privacy-safety-security/)
* Terms of Service: https://goqr.me/api/
* Used for: Generating a QR code image for scanning with an authenticator app during 2FA setup; the returned image is converted to a base64 data URI so the user's browser never contacts api.qrserver.com directly

**Browser-Native AI CDN Libraries (when optional features are enabled, client-side only):**
* Transformers.js (when "Browser-Native AI Tasks" feature is enabled): browser downloads library from https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.8.1 — [jsDelivr Privacy](https://www.jsdelivr.com/privacy-policy-jsdelivr-net) | [Terms](https://www.jsdelivr.com/terms); no user chat data is sent to jsDelivr; all inference runs in the visitor's browser
* WebLLM (when "Embedded Browser LLM" provider is selected): browser downloads library from https://esm.run/@mlc-ai/web-llm — [esm.sh Privacy](https://esm.sh/privacy); no user chat data is sent; all inference runs locally via WebGPU

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
* Google Gemini API - [Privacy](https://policies.google.com/privacy) | [Terms](https://ai.google.dev/terms)
* Anthropic API - [Privacy](https://www.anthropic.com/legal/privacy) | [Terms](https://www.anthropic.com/legal/consumer-terms)
* DeepSeek API - [Privacy](https://platform.deepseek.com/privacy) | [Terms](https://platform.deepseek.com/terms)
* OpenRouter API - [Privacy](https://openrouter.ai/privacy) | [Terms](https://openrouter.ai/terms)
* Kimi (Moonshot AI) API - [Privacy](https://platform.moonshot.cn/docs/policy/privacy-policy) | [Terms](https://platform.moonshot.cn/docs/policy/service-agreement)
* DigitalOcean Serverless Inference - [Privacy](https://www.digitalocean.com/legal/privacy-policy) | [Terms](https://www.digitalocean.com/legal/terms-of-service-agreement)
* Ollama (self-hosted) - No external service
* LM Studio (self-hosted) - No external service

**Optional (for specific features):**
* Cloudflare Workers AI - [Privacy](https://www.cloudflare.com/privacypolicy/) | [Terms](https://www.cloudflare.com/terms/)
* Hugging Face - [Privacy](https://huggingface.co/privacy) | [Terms](https://huggingface.co/terms-of-service)
* Weather data - Open-Meteo API
* Web search - Brave Search, DuckDuckGo, Tavily, Exa AI, or Perplexity (provider must be configured)
* Image generation - OpenAI, Gemini, or Cloudflare
* Google Cloud Vision API - [Privacy](https://policies.google.com/privacy) | [Terms](https://cloud.google.com/terms/)
* Google Cloud Speech-to-Text API - [Privacy](https://policies.google.com/privacy) | [Terms](https://cloud.google.com/terms/)
* Google Cloud Text-to-Speech API - [Privacy](https://policies.google.com/privacy) | [Terms](https://cloud.google.com/terms/)
* Google Drive API - [Privacy](https://policies.google.com/privacy) | [Terms](https://policies.google.com/terms)
* WordPress.com OAuth2 (Gravatar) - [Privacy](https://automattic.com/privacy/) | [Terms](https://wordpress.com/tos/)
* Yahoo OAuth2 (Fantasy Sports) - [Privacy](https://legal.yahoo.com/us/en/yahoo/privacy/index.html) | [Terms](https://legal.yahoo.com/us/en/yahoo/terms/otos/index.html)
* QR Server API (2FA setup, server-side only) - [Privacy](https://goqr.me/privacy-safety-security/) | [Terms](https://goqr.me/api/)

**Optional browser-native AI CDN libraries (no user data transmitted):**
* Transformers.js (browser-native AI tasks, opt-in) - loaded from jsDelivr CDN - [Privacy](https://www.jsdelivr.com/privacy-policy-jsdelivr-net) | [Terms](https://www.jsdelivr.com/terms)
* WebLLM / MLC AI (embedded browser LLM provider, opt-in) - loaded from esm.run CDN - [Privacy](https://esm.sh/privacy) | [Terms](https://esm.run/)

For complete privacy, configure Ollama or LM Studio for fully local AI processing.

Review your chosen provider's privacy policy before use.

== Credits ==

Open Operator System is developed and maintained by [NV Digital Solutions](https://nvdigitalsolutions.com/).

NV oOS stands on the shoulders of an extraordinary open-source ecosystem. The full, authoritative list of every third-party library, vendored asset, bundled skill, font, and methodology that ships with NV oOS — together with each upstream owner, license, and source URL — is maintained in [CREDITS.md](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/CREDITS.md).

Highlight acknowledgements (non-exhaustive):

* **PHP libraries:** Guzzle (guzzlehttp), Symfony components, PSR interfaces (PHP-FIG), league/oauth2-client, php-http/discovery, Nyholm/psr7, rahul900day/tiktoken-php — all MIT-licensed.
* **Pro addon PHP libraries:** TCPDF (Nicola Asuni), Dompdf, PHPSpreadsheet, PHPWord (PHPOffice), smalot/pdfparser, Masterminds/html5-php, sabberworm/php-css-parser, dvdoug/BoxPacker, maennchen/ZipStream-PHP, thiagoalessio/tesseract-ocr-for-php, MarkBaker/PHPComplex, MarkBaker/PHPMatrix, thecodingmachine/safe.
* **JavaScript libraries (base):** Chart.js, DOMPurify (cure53), @microsoft/fetch-event-source, marked (markedjs), React (Meta), reactflow (xyflow), @dnd-kit (Claude Lefebvre), @mlc-ai/web-llm, @neplex/vectorizer, ky (sindresorhus), Konva (konvajs).
* **JavaScript libraries (Pro / addons):** Sharp (lovell), pdfkit, pdf-lib, exceljs, docx (dolanmiu), tesseract.js (naptha), pdf.js (Mozilla), Cheerio, Stripe, Twitter API v2 (PLhery), Turf.js, KaTeX, mathjs, MJML, axios, validator.js, libphonenumber-js, qrcode (soldair), Tone.js, @strudel/web (TidalCycles / Felix Roos), Tonal, WebMidi, Cytoscape (The Cytoscape Consortium) + cytoscape-fcose / cose-base / layout-base (iVis lab, Bilkent), Cornerstone3D and dicom-parser.
* **Bundled Agent Skills:** curated from anthropics/skills (© Anthropic, MIT) and Lonsdale201/wp-agent-skills (© Soczó Kristóf, MIT). Per-skill attribution lives in `includes/bundled-skills/THIRD_PARTY_NOTICES.md` and `addons/pro/includes/bundled-skills/THIRD_PARTY_NOTICES.md`.
* **Fonts:** DejaVu Fonts project, GNU FreeFont, KaTeX fonts.
* **Methodology / inspiration:** MemPalace, Letta/MemGPT, Zep, mem0, Cognee, BMAD-METHOD, and the broader GSD context-engineering community — see CREDITS.md for full attribution.

If you spot a missing or incorrect attribution, please open an issue with the `credits` label at https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues — corrections are treated as priority fixes.

Special thanks to every contributor and to the open source community at large.

== Licensing ==

= Base Plugin =

This plugin is licensed under the [GNU General Public License v3 or later](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/LICENSE). All source code in the base plugin is open source. You have full freedom to use, study, modify, and redistribute the base plugin under the terms of the GPLv3.

= Pro Addon =

The Pro addon (`addons/pro/`) is a completely separate, optional plugin distributed under a **proprietary license**. It is copyrighted by NV Digital Solutions and all rights are reserved. The Pro addon is not included in the WordPress.org distribution — it is installed separately and is not required for the base plugin to function.

= How They Differ =

* **Base plugin (this plugin):** GPLv3 — open source, freely redistributable, modifiable
* **Pro addon (separate plugin):** Proprietary — requires a license from NV Digital Solutions, not redistributable

The base plugin and the Pro addon are independent codebases. The base plugin does not contain any Pro addon code, and no base plugin features are gated behind the Pro addon's license. The Pro addon adds entirely new tools and capabilities built on PHP 8.1+ features.

= Patent Notice =

NV oOS is the subject of a pending patent application (Application #19/410,504). The patent does not restrict your GPL rights to the base plugin. See the "Is this plugin patented?" FAQ entry above for details.
