# Open Operator System (WP oOS)

[![PHPUnit](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/phpunit.yml/badge.svg)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/phpunit.yml)
[![JavaScript Tests](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/javascript-tests.yml/badge.svg)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/javascript-tests.yml)
[![PHP Linting](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/php-linting.yml/badge.svg)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/actions/workflows/php-linting.yml)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://www.php.net/)
[![Patent Pending](https://img.shields.io/badge/Patent-Pending-orange.svg)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos#patent-pending)

**Version:** 1.0.0 (Beta)  
**MCP Specification:** 2024-11-05  
**Maintained by [NV Digital](https://nvdigitalsolutions.com/wpoos)**  
**License:** GPLv3 or later  
**Requires:** WordPress 6.0+, PHP 7.4+  
**Patent Status:** Patent Pending (Application #19/410,504)

## 📑 Table of Contents

### Getting Started
- [🧩 Overview](#-overview)
- [🎯 Our Mission](#-mission-modernizing-small-to-medium-business-websites)
- [🛡️ Active Security Monitoring](#%EF%B8%8F-active-security-monitoring)
- [🏗 System Architecture](#-system-architecture)
- [🚀 Features](#-features)
- [📦 Installation](#-installation)
- [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins)
- [⚙️ Configuration Checklist](#-configuration-checklist-action-items)
- [📚 Documentation](#-documentation)

### Core Functionality
- [🧠 Memory & Tool Stack Overview](#-memory--tool-stack-overview)
- [🛠 Built-in tools & automations](#-built-in-tools--automations)
- [🗨️ Front-end chat surfaces](#-front-end-chat-surfaces)
- [💬 Frontend Shortcode](#-frontend-shortcode)

### AI Providers & Integration
- [🧠 Language Model Providers](#-language-model-providers-openai-gemini-ollama--lm-studio)
- [🧱 ChatKit Integration](#-chatkit-integration)
- [🌐 Crawl4AI Integration](#-crawl4ai-integration)
- [📡 Job Notification System](#-job-notification-system)
- [🧊 Elementor Widgets](#-elementor-widgets)

### Performance & Optimization
- [⚡ Message Bundling](#-message-bundling)
- [🎯 Agentic Loop Token Management](#-agentic-loop-token-management)
- [🔄 Chat Performance Optimizations](#-chat-performance-optimizations)
- [🌐 Mesh Compute Routing](#-mesh-compute-routing)
- [🔗 Federation & Discovery System](#-federation--discovery-system)

### Remote MCP Setup
- [🔒 MCP Server Authentication](#-mcp-server-authentication)
- [🌐 Connecting Remote MCP Clients](#-connecting-remote-mcp-clients)
- [🛰 REST API Endpoints](#-rest-api-endpoints)
- [🌊 SSE Streaming Support](#-sse-streaming-support)
- [📝 MCP JSON-RPC 2.0 Endpoint](#-mcp-json-rpc-2-0-endpoint)
- [🔑 Assistant API Credentials](#-assistant-api-credentials)
- [🎫 Token Management UI](#-token-management-ui)

### Assistant Management
- [🛠 Assistant Editor Overview](#-assistant-editor-overview)
- [📊 Assistant Storage: CPT vs CCT](#-assistant-storage-cpt-vs-cct)
- [⚡ Assistant Tool Shortcuts](#-assistant-tool-shortcuts)
- [👔 Professional & Team Layers](#-professional--team-layers)
- [🧵 REST Chat Payloads & Attachments](#-rest-chat-payloads--attachments)

### Development
- [🐳 Local Development with Docker](#-local-development-with-docker)
- [🧑‍💻 Development Tooling](#--development-tooling)
- [🧪 Testing & QA](#-testing--qa)
- [🧩 Hooks & Filters](#-hooks--filters)
- [🧰 WP-CLI Commands](#-wp-cli-commands)

### Reference
- [🔐 JetEngine Capability Reference](#-jetengine-capability-reference)
- [🛰 JetEngine REST API Reference](#-jetengine-rest-api-reference)
- [🧮 Usage Tracking](#-usage-tracking)
- [🧷 Attachment MIME Controls](#-attachment-mime-controls)
- [🧾 Logging](#-logging)
- [🧾 JetEngine REST Endpoint Report Helper](#-jetengine-rest-endpoint-report-helper)
- [🔌 Optional Tools & Dependencies](#-optional-tools--dependencies)
- [✅ Manual QA Scenarios](#-manual-qa-scenarios)

---

## 🧩 Overview

**WP oOS** is a modular AI framework for WordPress that connects your site's data with OpenAI's GPT models, Gemini, Anthropic, and Ollama (Local).
It allows you to create and manage AI Assistants that can interact with users, access WordPress data, and perform custom tool functions.

The plugin works standalone with **71 core tools** (base version) and optionally extends through the **Pro addon**, which adds **38 Pro tools** including advanced integrations (WooCommerce, social media APIs, GitHub, Google services) and exec-based tools (FFmpeg, WP-CLI, Python rembg, Jukebox), bringing the total to **109 built-in tools**.

### 🎯 Mission: Modernizing Small to Medium Business Websites

**WP oOS** is specifically designed to help **small to medium-sized businesses** fast-track their outdated, stale, or insecure company websites to modern technology standards—**without the need to add yet another wrapper around API calls**. Instead, we're trying to **peel back decades of API wrappers with the help of AI**, providing:

- **Direct AI Integration** - No middleware required. Connect directly to OpenAI, Gemini, and Ollama without custom development
- **Security-First Architecture** - Built-in protection against nefarious usage with active monitoring and prevention systems
- **Enterprise-Grade Features** - Access to capabilities typically requiring expensive custom development
- **Compliance & Audit Tools** - Comprehensive logging, rate limiting, and usage tracking built-in
- **Zero Technical Debt** - Modern codebase following WordPress standards, ready for current technology stacks

### 🛡️ Active Security Monitoring

**WP oOS actively prevents and monitors against nefarious behavior**. The plugin includes:

- **Nefarious Usage Monitor** - Real-time detection of suspicious patterns and automatic emergency shutdown capabilities【F:includes/class-wp-mcp-ai-nefarious-usage-monitor.php†L1-L676】
- **Root Security Key** - Optional emergency authentication layer to prevent unauthorized reactivation after security incidents【F:docs/root-security-key.md†L1-L511】
- **Granular Capability Controls** - Every tool and API endpoint enforces WordPress capabilities to prevent unauthorized access
- **Rate Limiting** - Built-in protection against abuse with configurable limits per user, model, and time period
- **Comprehensive Audit Logging** - Track all API calls, tool executions, and security events for compliance and forensic analysis
- **Input Sanitization & Output Escaping** - All user input sanitized, all output escaped following WordPress security best practices

**This is not a tool for circumventing security or promoting bad practices.** Every feature is designed with security, transparency, and responsible AI usage as core principles. The plugin actively works to stop and prevent misuse before it happens.

## Patent Pending

**WP oOS is the subject of a pending patent application** for its novel **System and Method for Dynamic AI Orchestration Layer with Real-Time Capability Gating and Resource Budgeting**.

**Application Number:** 19/410,504

The patent covers WP oOS's innovative approach to implementing sophisticated AI orchestration in WordPress's request-based PHP architecture—a platform not designed for real-time streaming, asynchronous operations, or persistent state management. This technical achievement enables enterprise-grade AI capabilities on WordPress by recreating event-driven behavior within PHP's synchronous execution model.

**Key Innovations Covered:**
- Dynamic resource budget allocation during streaming operations
- Capability-based access control for AI tool execution
- Registry-state-based scheduling in stateless environments
- Metrics-driven budget adjustment for real-time optimization
- Persistent-behavior illusion in request-based architectures

The orchestration layer makes WP oOS unique in the WordPress ecosystem by solving fundamental architectural limitations that prevent traditional WordPress plugins from supporting advanced AI features. See the [System Architecture](#-system-architecture) section below for technical details on how these innovations work together.

## 🏗 System Architecture

WP oOS implements a comprehensive orchestration layer for managing AI operations during real-time streaming events. The system architecture comprises:

> **📖 For a detailed explanation of how WP oOS extends standard SSE and MCP protocols with novel orchestration features, see [ORCHESTRATION-LAYER-ARCHITECTURE.md](docs/ORCHESTRATION-LAYER-ARCHITECTURE.md)**

### Why This Architecture Is Novel: Overcoming PHP's Limitations

**Critical Context:** Most real-time AI streaming systems are built with Node.js, Python FastAPI, or Go — platforms designed for asynchronous, event-driven operations. These platforms natively support:
- Long-lived connections and persistent state
- Non-blocking I/O and parallel execution
- Event loops and background workers

**PHP/WordPress, by contrast, is fundamentally request-based:**
- Every HTTP request spawns a new process that dies after responding
- I/O operations block execution
- No persistent memory between requests
- No native event loop or async coordination

**WP oOS solves this** by implementing an orchestration layer that creates a "persistent-behavior illusion" — effectively **recreating Node.js's event loop behavior within WordPress's synchronous, request-based architecture**. This architectural compensation is the system's core technical innovation:

| PHP Limitation | WP oOS Solution |
|----------------|-----------------|
| No persistent state | Registry & policy engine maintain state via database/cache |
| No event loop | Cron Manager extends orchestration across time-shifted operations |
| Blocking I/O | Predictive budget allocator prevents blocking operations |
| Request-based lifecycle | SSE controller implements streaming within request boundaries |
| No background workers | WordPress cron system simulates async job processing |

This makes WP oOS patent-worthy as a **technical workaround** — it achieves sophisticated AI orchestration in an environment specifically not designed for such patterns. See [ORCHESTRATION-LAYER-ARCHITECTURE.md](docs/ORCHESTRATION-LAYER-ARCHITECTURE.md) for the complete technical analysis.

### Computer-Implemented Resource Management

The system operates as a computer-implemented method executing on a processor with memory, performing:

1. **Dynamic Resource Budget Allocation**: The orchestration layer dynamically allocates token and memory budgets to tool execution requests based on real-time system capacity and operation requirements. The `WP_MCP_AI_Resource_Manager` continuously monitors server resources (PHP memory limits, execution time constraints) and automatically adjusts operational parameters.

2. **Capability-Based Access Control**: Tool execution endpoints enforce granular capability-based access controls. Each tool in the registry declares required WordPress capabilities, and the REST API controller validates user permissions before allowing execution. This ensures secure, policy-driven access to all operations.

3. **Registry-State-Based Scheduling**: The `WP_MCP_AI_Tool_Registry` maintains tool availability state and schedules execution based on policy constraints. Tools are loaded conditionally based on dependency availability, and execution is scheduled according to assistant configuration and user permissions.

4. **Metrics-Driven Budget Adjustment**: The system continuously monitors execution metrics (memory usage, API response times, token consumption) and adjusts resource budgets in response to prevent resource exhaustion and reduce latency. The `WP_MCP_AI_Token_Budget_Manager` implements safety margins and dynamic chunking to prevent API limit overruns.

### System Components

The system comprises a processor and memory storing instructions that:
- Monitor real-time resource availability through PHP runtime introspection
- Enforce capability checks at REST endpoint boundaries
- Schedule tool execution through a centralized registry
- Adjust token and memory budgets based on detected system metrics
- Maintain operation logs for audit and optimization

This architecture is embodied in non-transitory computer-readable media (PHP source files) that, when executed by a web server processor, cause the system to perform the complete resource management workflow. The implementation prioritizes stability, security, and efficient resource utilization across diverse hosting environments.

### Symfony Process Integration (December 2025)

WP oOS Pro addon integrates the Symfony Process component for secure external command execution. This modern framework replaces direct `exec()` calls in 6 Pro tools and 2 supporting services, providing:

- **Enhanced Security**: Proper argument escaping and command validation
- **Timeout Management**: Configurable timeouts with graceful handling
- **Better Error Handling**: Comprehensive exception catching and WordPress-friendly error reporting
- **Process Control**: Real-time output streaming and cancellation support

**Migrated Tools & Services:**
- FFmpeg operations (video frame extraction, metadata reading)
- Python rembg (background removal)
- WP-CLI execution
- Meta AI Jukebox (music generation)
- Supporting services for video and audio processing

The Process Service (`WP_MCP_AI_Process_Service`) provides WordPress-friendly wrappers with WP_Error integration, making external process execution consistent with WordPress coding standards.【F:includes/services/class-wp-mcp-ai-process-service.php†L1-L220】【F:docs/SYMFONY_PHASE2B_PROCESS_INTEGRATION.md†L1-L100】

---

## 🚀 Features

> **Note:** Some features require third-party plugins (WooCommerce, JetEngine, Elementor, etc.). See [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins) for details.

### Assistant & conversation tools
- 🧠 Create AI Assistants via a custom post type (`mcp_ai_assistant`)
- 👔 **Professional & Team Templates** - Deploy assistants from 182 pre-built profession templates spanning 12 industry categories, or create entire teams of specialists with one click. Includes backend testing for professions, teams, and assistants before public deployment.
- 🔄 Automatic synchronization to JetEngine Custom Content Types when available (CPT → CCT)
- 💬 Chat interface via `[mcp_ai_chat assistant="ID"]`
- 🧰 Per-assistant defaults for model, temperature, and system prompt baked into every chat request
- 🔍 Search Media Library knowledge attachments with permission-aware download URLs
- ⚡ Build reusable prompt shortcuts with optional tool targeting and inline descriptions so operators can trigger common tasks with one click.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L893-L1048】【F:includes/class-wp-mcp-ai-shortcode.php†L430-L693】【F:assets/js/chat.js†L600-L666】
- 🧊 Elementor widgets for embedding chat surfaces, onboarding content, and MCP dashboards inside Elementor

### Language routing & knowledge management
- 🔁 Route conversations through OpenAI or Gemini using a provider-aware language model router
- 🎯 Enhanced Gemini API integration: list models dynamically, count tokens for budget management, create embeddings for RAG/semantic search, and streaming support for real-time responses【F:docs/gemini-api-enhancements.md†L1-L100】
- 🧠 Assistant knowledge base management with Media Library files and optional vector store IDs
- 🔎 Perform lightweight web searches (DuckDuckGo or Brave) without leaving the assistant conversation
- 🌐 Crawl4AI job runner tool for large-scale content gathering workflows

### Media generation & transcription
- 🔊 Generate speech audio via OpenAI's Text-to-Speech API and save the result to the Media Library
- 🎵 Generate instrumental music using Google Gemini Lyria with controls for genre, mood, tempo, and instrumentation
- 🎨 Generate on-brand imagery with OpenAI's Images API, honouring the configured response format (including GPT-Image-1's `url` responses) and storing the files as WordPress attachments
- 🎧 Transcribe or translate uploaded audio with OpenAI's speech-to-text endpoints

### Commerce & finance workflows
- 🛍 WooCommerce-aware tools (fetch orders or products, requires WooCommerce)
- 📊 Finance-ready QuickBooks Online reporting tool for surfacing Profit and Loss, Balance Sheet, and other statements inside assistant conversations【F:includes/tools/class-wp-mcp-ai-tool-get-quickbooks-report.php†L15-L214】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L955】

### Communications & outreach
- ✉️ Mailjet-powered outbound email automation with granular capability enforcement and sender defaults configurable in the MCP settings.【F:includes/tools/class-wp-mcp-ai-tool-send-mailjet-email.php†L19-L405】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1008-L1054】
- 📅 Google Workspace automations for creating calendar events and searching connected Gmail inboxes directly from assistant workflows.【F:includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php†L1-L200】【F:includes/tools/class-wp-mcp-ai-tool-search-gmail.php†L1-L200】
- 🧾 JetFormBuilder orchestration for listing forms, reviewing submissions, and proxying REST calls on behalf of assistants (requires JetFormBuilder)
- 📚 JetEngine REST route reference tool for surfacing endpoint metadata inside AI workflows
- 🧱 Ready for extension with ChatKit integration

### Integrations, security & controls
- 🔧 Tool Registry for registering PHP functions callable by the AI
- ⚙️ JetEngine integration for dynamic content queries (requires JetEngine)
- 🧷 Granular control over allowed attachment MIME types for chat uploads
- 🔐 Secure REST API endpoints
- 🔑 **Root Security Key** - Optional wp-config.php constant that can be enabled during emergency shutdown to require authentication before re-initializing the plugin. Provides an additional layer of protection against unauthorized reactivation after security incidents.【F:docs/root-security-key.md†L1-L511】【F:includes/class-wp-mcp-ai-root-security-key.php†L1-L360】
- 🛰 Assistant directory endpoint that advertises MCP tool/resource capabilities and negotiates Server-Sent Events handshakes for clients such as LM Studio or Claude Desktop.【F:includes/class-wp-mcp-ai-rest.php†L520-L666】【F:includes/class-wp-mcp-ai-rest.php†L1690-L1772】
- 📝 Full JSON-RPC 2.0 MCP endpoint (`/mcp`) for standards-compliant remote client communication
- 🔑 Configurable API credentials and defaults for OpenAI and Gemini
- 🤖 ChatGPT’s connector beta currently requires an Auth0 tenant; the plugin’s assistant credentials are compatible with LM Studio, Claude, and other MCP clients that support bearer headers directly.【F:docs/mcp-server-authentication.md†L22-L46】
- 🌐 **Mesh networking** for distributed compute pooling across multiple WordPress sites. Server-to-server architecture enables anonymous and authenticated users to benefit from shared AI resources, budget pooling, and workload distribution across 100+ trusted peer sites. Backend assistants coordinate mesh operations via secure inter-site keys while maintaining user attribution and audit trails for compliance.【F:docs/mesh-compute-pooling.md†L1-L615】【F:includes/tools/class-wp-mcp-ai-tool-query-remote-site.php†L1-L237】
- 🔗 **Federation & Discovery** - Decentralized AI capability network allowing WordPress sites to publish their capabilities via well-known endpoints (`/.well-known/ai-peer`) and discover peer sites through directory services. Supports peer registration, health verification, search & ranking by capability/region/policy, and automatic cron-based health monitoring. Enable federation to join the network or run your own directory service for private peer discovery.【F:docs/federation-discovery.md†L1-L511】【F:FEDERATION-IMPLEMENTATION-SUMMARY.md†L1-L381】
- 🧾 Optional logging of chat interactions, tool executions, and API errors
- 🧮 Built-in per-user usage tracking for provider/model billing summaries
- 🧩 Developer hooks and filters for integrating custom behaviours
- ⏱ Per-site request timeout control with sensible minimum enforcement
- 🗑 Toggleable uninstall cleanup to purge stored assistants and settings automatically

### Performance & reliability
- ⚡ Client-side message bundling (800ms window) to reduce API calls and server load【F:docs/message-bundling-feature.md†L1-L80】
- 🎯 Intelligent token overflow handling with automatic model switching (gpt-4.1-mini → Gemini 2.0 Flash)【F:docs/high-token-tool-handling.md†L1-L80】
- 📡 **Server-Sent Events (SSE) support** for real-time streaming responses and job notifications【F:docs/ENABLE-SSE-STREAMING.md†L1-L100】
- 🌊 Real-time job status updates via SSE streaming and webhook notifications for async operations【F:docs/job-notification-system.md†L1-L100】
- 🔧 **Symfony Process Component** - Modern process execution framework replacing direct `exec()` calls in Pro addon tools for enhanced security, timeout management, and error handling【F:includes/services/class-wp-mcp-ai-process-service.php†L1-L220】【F:docs/SYMFONY_PHASE2B_PROCESS_INTEGRATION.md†L1-L100】
- 🔄 Server-side WP-Cron polling for long-running tasks (Crawl4AI, background jobs)
- 💾 Chat history persistence with localStorage (24h) and optional JetEngine CCT storage【F:docs/chat-history-persistence.md†L1-L50】
- ⚙️ **Optimized settings page** with external CSS stylesheet (240 lines added to admin-settings.css) and request-level caching for improved admin performance【F:assets/css/admin-settings.css†L1-L984】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L27-L32】

## 🧠 Memory & Tool Stack Overview

### Model defaults

Global settings capture the default provider, model, and timeout used when assistants are created, ensuring every conversation inherits stable generation behaviour until explicitly overridden. These defaults ship with sensible values for OpenAI and Gemini out of the box and can be tailored from the WP oOS settings screen.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L36-L77】

### Base knowledge

Each assistant can preload Media Library files and optionally link to an external vector store, giving the model persistent project context before a chat begins. Editors manage these knowledge sources from the assistant post type via the “Base Knowledge” meta box, which supports multiple attachments and vector store identifiers.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L892-L1002】

### Available tools

The tool registry boots with a curated catalogue of content, commerce, automation, and research utilities, then exposes hooks so developers can register their own providers. During initialisation the registry loads each bundled tool class—ranging from JetEngine accessors to Crawl4AI jobs and Mailjet automations—and makes them callable within conversations.【F:includes/class-wp-mcp-ai-tool-registry.php†L74-L220】

### Workflow families & tool combos

The core plugin ships with a centrally registered tool catalogue that lets assistants mix and match capabilities into cohesive workflows without additional coding. Teams can chain authoring, media, research, commerce, marketing, and operational tools to deliver end-to-end outcomes inside a single conversation.

- **Content & knowledge production** – Combine `submit_document_prompt`, `search_content`, and `search_attachments` to gather source material, then follow up with `save_post`, `create_wpcode_snippet`, or `get_rankmath_seo` for structured drafting and optimisation.
- **Media generation & transcription** – Pair `generate_openai_image` or `generate_gemini_image` with `generate_openai_speech` and `transcribe_openai_audio` to build multimedia assets that flow into editorial or marketing outputs.
- **Research & situational awareness** – Chain discovery helpers like `web_search`, `run_crawl4ai_job`, `reliefweb_reports`, `get_gdacs_events`, and `get_nhc_active_storms` to assemble briefing packs before drafting follow-up actions.
- **Commerce & finance operations** – Use WooCommerce and finance tools such as `create_woo_product`, `get_woo_products`, `get_woo_recent_orders`, `crawl4ai_price_lookup`, `get_import_duty`, and `quickbooks_report` to coordinate merchandising, pricing, and bookkeeping reviews.
- **Marketing & analytics insights** – Combine measurement tools including `google_analytics_report`, `get_google_business_insights`, `get_facebook_instagram_insights`, `get_linkedin_insights`, and `get_tiktok_insights` to guide campaigns and reporting.
- **Publishing & outreach automations** – Trigger distribution via `post_facebook_instagram`, `post_google_business_update`, `post_linkedin_update`, `post_tiktok_video`, `send_group_email`, `send_mailjet_email`, `send_telegram_message`, `send_whatsapp_message`, and `schedule_notify_sms` once plans are ready.
- **Integrations & scheduling** – Connect external systems with `create_google_calendar_event`, `search_gmail`, `list_jetengine_rest_routes`, `invoke_jetengine_route`, and `run_openai_external_action` as part of larger automations.
- **Operations & diagnostics** – Close the loop with `create_cron_job`, `list_cron_jobs`, `get_cron_job`, `delete_cron_job`, `check_wp_cli`, `purge_cache`, `purge_cloudflare_cache`, `purge_varnish_cache`, `get_site_summary`, `get_site_health`, `get_system_logs`, `get_update_status`, and OpenAI usage/log review helpers for monitoring and maintenance.
- **Automation & scheduling workflows** – Agents can autonomously schedule background tasks with `create_cron_job`, monitor scheduled operations via `list_cron_jobs` and `get_cron_job`, and clean up outdated automations with `delete_cron_job`. Combine with cache management tools (`purge_cache`, `purge_cloudflare_cache`, `purge_varnish_cache`) to orchestrate content publishing workflows where agents schedule posts, then automatically invalidate caches at publication time.

---



## 🛠 Built-in tools & automations

The assistant registry ships with a comprehensive catalogue of editorial, marketing, commerce, and operational helpers. The tables below outline every bundled tool and the slug assistants call when orchestrating workflows.

### Content & knowledge workflows
| Tool | Slug | Summary |
| --- | --- | --- |
| Submit Document Prompt | `submit_document_prompt` | Uploads WordPress attachments or OpenAI file IDs alongside an instruction so multimodal prompts reach the Responses API with the required file context.【F:includes/tools/class-wp-mcp-ai-tool-submit-document-prompt.php†L20-L214】|
| Search Content | `search_content` | Queries public post types with optional taxonomy and meta filters to surface structured post metadata for the assistant.【F:includes/tools/class-wp-mcp-ai-tool-search-content.php†L12-L280】|
| Search Attachments | `search_attachments` | Scans the Media Library with keyword or MIME filters while honouring attachment capability checks and signed download URLs.【F:includes/tools/class-wp-mcp-ai-tool-search-attachments.php†L15-L207】|
| Get Recent Posts | `get_recent_posts` | Returns the latest entries for a given post type with titles, permalinks, excerpts, and timestamps for quick editorial summaries.【F:includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php†L12-L104】|
| Get Elementor Templates | `get_elementor_templates` | Lists Elementor library templates with status, type, and edit links when Elementor is available and the caller has access.【F:includes/tools/class-wp-mcp-ai-tool-get-elementor-templates.php†L12-L239】|
| Get JetEngine Items | `get_jetengine_items` | Retrieves JetEngine-managed content with capability-aware access checks for each registered custom post type.【F:includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php†L12-L118】|
| Get JetFormBuilder Forms | `get_jetformbuilder_forms` | Proxies JetFormBuilder REST controllers to return paginated form metadata with automatic REST/HTTP fallbacks.【F:includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-forms.php†L15-L155】|
| Get JetFormBuilder Submissions | `get_jetformbuilder_submissions` | Lists recent JetFormBuilder entries with normalised field snapshots and capability enforcement.【F:includes/tools/class-wp-mcp-ai-tool-get-jetformbuilder-submissions.php†L15-L154】|
| Save Post | `save_post` | Drafts or updates posts and custom post types with sanitised Gutenberg content, slug/title overrides, and edit links.【F:includes/tools/class-wp-mcp-ai-tool-save-post.php†L15-L268】|
| Create WPCode Snippet 🌟 | `create_wpcode_snippet` | Provisions or updates WPCode-managed snippets, validating code types, insert locations, and activation status. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-create-wpcode-snippet.php†L15-L224】|
| Get Rank Math SEO Overview | `get_rankmath_seo` | Surfaces Rank Math SEO scores, focus keywords, robots metadata, and schema details for a specific post when the plugin is active.【F:includes/tools/class-wp-mcp-ai-tool-get-rankmath-seo.php†L15-L220】|
| Get User Information | `get_user_info` | Inspects the acting user or a supplied account while respecting multisite membership and capability requirements.【F:includes/tools/class-wp-mcp-ai-tool-get-user-info.php†L12-L89】|

### Media generation & transcription
| Tool | Slug | Summary |
| --- | --- | --- |
| Generate OpenAI Image | `generate_openai_image` | Calls the OpenAI Images API with configurable defaults, saving the rendered asset to the Media Library with optional overrides.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php†L17-L218】|
| Generate Gemini Image | `generate_gemini_image` | Uses Gemini’s multimodal image endpoint to render creative, aspect-ratio-aware visuals that are persisted as WordPress attachments.【F:includes/tools/class-wp-mcp-ai-tool-generate-gemini-image.php†L17-L200】|
| Generate OpenAI Speech | `generate_openai_speech` | Converts text to audio via OpenAI’s text-to-speech models, honouring default voice/format selections and storing results in the Media Library.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php†L17-L199】|
| Generate Music | `generate_music` | Creates instrumental music from text descriptions using Google Gemini Lyria model with controls for genre, mood, duration, and tempo.|
| Transcribe OpenAI Audio | `transcribe_openai_audio` | Sends uploaded audio to OpenAI’s transcription/translation endpoints and returns structured transcripts with language and duration metadata.【F:includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php†L17-L195】|

### Research & situational awareness
| Tool | Slug | Summary |
| --- | --- | --- |
| Web Search | `web_search` | Performs lightweight lookups against DuckDuckGo or Brave, normalising related topics and enforcing per-user result caps.【F:includes/tools/class-wp-mcp-ai-tool-web-search.php†L12-L320】|
| Run Crawl4AI Job | `run_crawl4ai_job` | Executes Crawl4AI harvests locally or remotely, collecting Markdown, HTML, and error payloads for long-form content ingestion workflows.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L32-L745】|
| ReliefWeb Reports | `reliefweb_reports` | Queries ReliefWeb’s humanitarian dataset by country or disaster type and returns structured report metadata for situational updates.【F:includes/tools/class-wp-mcp-ai-tool-reliefweb-reports.php†L15-L234】|
| Get GDACS Events | `get_gdacs_events` | Fetches Global Disaster Alert and Coordination System events with optional date filters and capability checks for emergency planning.【F:includes/tools/class-wp-mcp-ai-tool-get-gdacs-events.php†L12-L200】|
| Get NHC Active Storms | `get_nhc_active_storms` | Retrieves the National Hurricane Center’s active storm feed, sanitising advisory data for assistant consumption.【F:includes/tools/class-wp-mcp-ai-tool-get-nhc-active-storms.php†L15-L146】|
| Get Open-Meteo Forecast | `get_open_meteo_forecast` | Pulls hourly weather data from Open-Meteo with coordinate, timezone, and variable controls for itinerary-aware responses.【F:includes/tools/class-wp-mcp-ai-tool-get-open-meteo-forecast.php†L15-L309】|
| Vision Product Search | `vision_product_search` | Searches for similar products using Google Cloud Vision API Product Search feature. Note: Requires proper Google Cloud authentication credentials to succeed.【F:includes/tools/class-wp-mcp-ai-tool-vision-product-search.php†L1-L200】|
| Vision Object Localization | `vision_object_localization` | Detects and localizes multiple objects in images using Google Cloud Vision API. Note: Requires proper Google Cloud authentication credentials to succeed.【F:includes/tools/class-wp-mcp-ai-tool-vision-object-localization.php†L1-L200】|

### Commerce & finance operations
| Tool | Slug | Summary |
| --- | --- | --- |
| Create WooCommerce Product Draft | `create_woo_product` | Builds draft WooCommerce products with merchandising copy, pricing, images, and brand metadata when WooCommerce is active.【F:includes/tools/class-wp-mcp-ai-tool-create-woo-product.php†L15-L258】|
| Get WooCommerce Products | `get_woo_products` | Surfaces catalogue listings with pricing, stock status, and optional SKU/status filters for merchandiser reviews.【F:includes/tools/class-wp-mcp-ai-tool-get-woo-products.php†L12-L140】|
| Get Woo Recent Orders | `get_woo_recent_orders` | Summarises recent WooCommerce orders with totals, billing details, and ISO timestamps for fulfilment teams.【F:includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php†L12-L117】|
| Wholesale Club Price Lookup | `crawl4ai_price_lookup` | Uses Crawl4AI’s web search endpoint to compare BJ’s, Sam’s Club, and Costco pricing for a given product query.【F:includes/tools/class-wp-mcp-ai-tool-crawl4ai-price-lookup.php†L17-L189】|
| Lookup Import Duty 🌟 | `get_import_duty` | Queries the ITA Tariff Rates API for HS codes or descriptions to surface import duty rates for supported countries. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-import-duty.php†L15-L152】|
| QuickBooks Online Report 🌟 | `quickbooks_report` | Requests Profit & Loss, Balance Sheet, or custom QuickBooks Online reports with optional date ranges and accounting methods. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-quickbooks-report.php†L15-L214】|

### Marketing & analytics insights
| Tool | Slug | Summary |
| --- | --- | --- |
| Google Analytics Report 🌟 | `google_analytics_report` | Runs GA4 Analytics Data API queries with metrics, dimensions, date ranges, and aggregation controls to monitor site performance. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-google-analytics-report.php†L15-L158】|
| Google Business Insights | `get_google_business_insights` | Fetches Google Business Profile metrics for a location using OAuth tokens, time ranges, and timezone hints. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-google-business-insights.php†L15-L149】|
| Meta Social Insights | `get_facebook_instagram_insights` | Pulls Facebook Page or Instagram business metrics via the Graph API with selectable periods and metric sets. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-facebook-instagram-insights.php†L15-L146】|
| LinkedIn Insights | `get_linkedin_insights` | Queries LinkedIn organizational share statistics with optional timeframe and granularity filters. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-linkedin-insights.php†L15-L138】|
| TikTok Insights | `get_tiktok_insights` | Calls the TikTok Open API to return account performance metrics across configurable windows and granularities. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-get-tiktok-insights.php†L15-L136】|

### Publishing & outreach
| Tool | Slug | Summary |
| --- | --- | --- |
| Publish Meta Social Post 🌟 | `post_facebook_instagram` | Publishes Facebook Page or Instagram business posts through the Meta Graph API with message, caption, and media controls. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-facebook-instagram.php†L15-L170】|
| Publish Google Business Update 🌟 | `post_google_business_update` | Creates Google Business Profile local posts with summaries, language codes, and optional call-to-action links. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-google-business-update.php†L15-L168】|
| Publish LinkedIn Update 🌟 | `post_linkedin_update` | Sends LinkedIn UGC posts for members or organisations with optional share URLs via the LinkedIn Marketing API. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-linkedin-update.php†L15-L160】|
| Publish TikTok Video 🌟 | `post_tiktok_video` | Submits hosted video assets to TikTok’s Open API share endpoint with optional captions. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-post-tiktok-video.php†L15-L152】|
| Send Group Email | `send_group_email` | Orchestrates structured or free-form email campaigns with capability-based audience limits and logging hooks. [Full documentation](docs/send-group-email-usage.md).【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L16-L650】|
| Send Mailjet Email 🌟 | `send_mailjet_email` | Delivers transactional and marketing emails through Mailjet with sender defaults, CC/BCC routing, and response metadata. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-mailjet-email.php†L19-L405】|
| Send Telegram Message 🌟 | `send_telegram_message` | Posts formatted updates to Telegram chats or channels with capability filters and audit logging. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-telegram-message.php†L16-L232】|
| Send WhatsApp Message 🌟 | `send_whatsapp_message` | Sends WhatsApp Cloud API text messages with preview controls using phone-number specific access tokens. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-send-whatsapp-message.php†L15-L178】|
| Schedule Notify.lk SMS 🌟 | `schedule_notify_sms` | Queues Notify.lk SMS messages for future delivery using the official SDK and site cron orchestration. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-schedule-notify-sms.php†L15-L180】|

### Integrations & scheduling
| Tool | Slug | Summary |
| --- | --- | --- |
| Create Google Calendar Event | `create_google_calendar_event` | Builds calendar events with attendees, reminders, and timeout overrides using OAuth tokens or service accounts.【F:includes/tools/class-wp-mcp-ai-tool-create-google-calendar-event.php†L17-L378】|
| Search Gmail Messages | `search_gmail` | Performs delegated Gmail queries with optional label filters and pagination, returning normalised message metadata. **Pro addon tool**.【F:addons/pro/includes/src/Tools/class-wp-mcp-ai-pro-tool-search-gmail.php†L1-L200】|
| List JetEngine REST Routes | `list_jetengine_rest_routes` | Enumerates JetEngine REST endpoints with method, callback, and capability metadata for developers.【F:includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php†L12-L151】|
| Invoke JetEngine REST Route | `invoke_jetengine_route` | Proxies JetEngine CRUD operations using the authenticated user context with REST/HTTP fallbacks.【F:includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php†L12-L133】|
| Run OpenAI External Action | `run_openai_external_action` | Triggers OpenAI Responses API workflows or assistants with payload sanitisation, timeout overrides, and structured errors.【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L17-L211】|

### Operations & diagnostics
| Tool | Slug | Summary |
| --- | --- | --- |
| **Cron Management Suite** | | **AI agents can autonomously schedule, monitor, and manage WordPress background tasks** |
| Create Cron Job | `create_cron_job` | Schedules one-off or recurring WP-Cron events with duplicate detection and sanitised hooks/arguments. Agents can automate periodic maintenance, content publishing, or custom workflows by scheduling actions to run at specific times or intervals.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L16-L168】|
| List Cron Jobs | `list_cron_jobs` | Lists all scheduled WordPress cron jobs with details about schedule, next run time, and creator. Enables agents to provide visibility into scheduled automation tasks and audit what background processes are running.【F:includes/tools/class-wp-mcp-ai-tool-list-cron-jobs.php†L17-L141】|
| Get Cron Job | `get_cron_job` | Retrieves detailed information about a specific WordPress cron job by its job ID, including schedule interval details and execution metadata. Allows agents to inspect individual scheduled tasks for troubleshooting or reporting.【F:includes/tools/class-wp-mcp-ai-tool-get-cron-job.php†L17-L145】|
| Delete Cron Job | `delete_cron_job` | Deletes a scheduled WordPress cron job and removes it from both the plugin tracking and WP-Cron. Enables agents to cancel outdated or unnecessary automation tasks on behalf of operators.【F:includes/tools/class-wp-mcp-ai-tool-delete-cron-job.php†L17-L90】|
| **Cache Management** | | **AI agents can coordinate multi-layer cache invalidation** |
| Purge Cache | `purge_cache` | Master cache purge tool that coordinates multi-layer cache clearing (Cloudflare, Varnish, etc.) in the correct order. Agents can ensure content updates are properly reflected across all caching layers.【F:includes/tools/class-wp-mcp-ai-tool-purge-cache.php†L17-L150】|
| Purge Cloudflare Cache | `purge_cloudflare_cache` | Sends targeted or full-zone invalidations to Cloudflare with configurable timeouts and admin-only access controls.【F:includes/tools/class-wp-mcp-ai-tool-purge-cloudflare-cache.php†L17-L292】|
| Purge Varnish Cache | `purge_varnish_cache` | Purges the local Varnish cache with support for full-cache bans and specific URL purges. Agents can clear server-side caching to ensure immediate content updates.【F:includes/tools/class-wp-mcp-ai-tool-purge-varnish-cache.php†L17-L150】|
| **System Monitoring & Diagnostics** | | |
| Check Site Security | `check_site_security` | Checks if the WordPress site has security vulnerabilities that make it unsafe to use this AI plugin. Scans for common security issues and provides remediation guidance for administrators.【F:includes/tools/class-wp-mcp-ai-tool-check-site-security.php†L1-L200】|
| Check WP-CLI Status | `check_wp_cli` | Scans for the WordPress CLI binary, returning detected paths, version output, and environment warnings.【F:includes/tools/class-wp-mcp-ai-tool-check-wp-cli.php†L17-L309】|
| Count Tokens | `count_tokens` | Estimates token counts for text and messages using heuristic estimation (approximately 4 characters per token) for planning and budgeting purposes. Helps with capacity planning before sending requests to AI providers.【F:includes/tools/class-wp-mcp-ai-tool-count-tokens.php†L1-L200】|
| Get Site Summary | `get_site_summary` | Provides high-level site metadata, content counts, and admin contact details for context-aware assistants.【F:includes/tools/class-wp-mcp-ai-tool-get-site-summary.php†L12-L66】|
| Get MCP Environment Status | `get_environment_status` | Summarises WordPress versions, MCP defaults, assistant counts, and dependency warnings for incident response.【F:includes/tools/class-wp-mcp-ai-tool-get-environment-status.php†L12-L178】|
| Get Site Health Status | `get_site_health` | Runs WordPress Site Health diagnostics and returns grouped pass/warn/fail tests with remediation guidance.【F:includes/tools/class-wp-mcp-ai-tool-get-site-health.php†L12-L255】|
| Get System Logs | `get_system_logs` | Aggregates WP oOS logs, WordPress/PHP error logs, and plugin log files to aid in debugging workflows.【F:includes/tools/class-wp-mcp-ai-tool-get-system-logs.php†L12-L352】|
| Get Update Status | `get_update_status` | Reports pending core, plugin, and theme updates with version and download metadata for maintenance planning.【F:includes/tools/class-wp-mcp-ai-tool-get-update-status.php†L12-L182】|
| **Testing & Validation** | | |
| Probe Assistant Chat | `probe_chat` | Issues a chat probe against a published assistant to confirm sanitisation, configuration, and REST handling without consuming model tokens.【F:includes/tools/class-wp-mcp-ai-tool-probe-chat.php†L12-L178】|
| Probe Remote MCP REST | `probe_remote_mcp` | Reuses the remote connectivity tester to exercise `/assistants` and `/chat` on another site with optional bearer, guest, or nonce credentials.【F:includes/tools/class-wp-mcp-ai-tool-probe-remote-mcp.php†L12-L164】|
| **Mesh Networking** | | **Distributed compute pooling across WordPress sites** |
| Query Remote Site | `query_remote_site` | Executes chat requests on peer WordPress sites in a mesh network. Requires `manage_options` capability and mesh networking to be enabled. Coordinates server-to-server compute pooling with secure inter-site key authentication, enabling distributed AI workloads across trusted peers while maintaining user attribution and audit trails. Backend assistants use this tool to fan out work across the mesh on behalf of anonymous or authenticated users.【F:includes/tools/class-wp-mcp-ai-tool-query-remote-site.php†L1-L237】【F:docs/mesh-compute-pooling.md†L1-L615】|
| Query Mesh (Intelligent Routing) | `query_mesh_intelligent` | Send a prompt to the mesh network with AI-powered peer selection and automatic failover. The system intelligently routes requests to the optimal peer site based on current load, response times, and task complexity. Provides resilient distributed compute with automatic retry logic.【F:includes/tools/class-wp-mcp-ai-tool-query-mesh-intelligent.php†L1-L300】|
| **Provider Dashboards** | | |
| Open OpenAI Logs | `open_openai_logs` | Returns dashboard shortcuts for reviewing OpenAI request logs in the provider console.【F:includes/tools/class-wp-mcp-ai-tool-open-openai-logs.php†L12-L66】|
| Open OpenAI Usage | `open_openai_usage` | Provides direct links to OpenAI usage dashboards so admins can audit consumption quickly.【F:includes/tools/class-wp-mcp-ai-tool-open-openai-usage.php†L12-L66】|
| **Authentication** | | |
| Generate Simple JWT Token | `generate_simple_jwt_token` | Generates a Simple JWT Login bearer token for the current user, enabling authenticated API access across sessions. Agents can help users obtain authentication tokens for headless WordPress integrations.【F:includes/tools/class-wp-mcp-ai-tool-generate-simple-jwt-token.php†L15-L120】|

### What the Cron Manager means to AI agents

The **Cron Management Suite** transforms AI assistants from reactive responders into proactive automation orchestrators. By providing full control over WordPress's background task scheduler, agents can:

**Autonomous Task Scheduling**
- Schedule content publishing workflows to go live at optimal times without human intervention
- Automate recurring maintenance tasks like cache clearing, database optimization, or backup operations
- Coordinate multi-step operations that span hours or days by chaining scheduled hooks

**Intelligent Monitoring & Self-Management**
- List and inspect all scheduled tasks to understand what automation is currently active
- Audit who created each task and when it's scheduled to run next
- Identify and remove outdated or redundant scheduled tasks to maintain system health

**Real-World Agent Workflows**
1. **Content Calendar Automation** - An agent helping with content strategy can schedule posts to publish at researched optimal engagement times, set up recurring social media cross-posts, and schedule follow-up email campaigns.
2. **Site Maintenance Orchestration** - When troubleshooting performance issues, agents can schedule off-peak cache purges, coordinate database cleanup tasks, and set up recurring health check notifications.
3. **Business Process Automation** - Agents can schedule recurring report generation, periodic data syncs with external systems, and automated backup verification checks.

**Technical Implementation**
The cron manager tracks all scheduled tasks in `wp_mcp_ai_cron_jobs` option with full audit trails including:
- Job ID for unique identification
- Hook name and sanitized arguments
- Schedule type (single-run or recurring interval)
- Creation timestamp and user attribution
- Next execution time for monitoring

Jobs are automatically pruned when they complete (single-run) or are manually removed (recurring), keeping the tracking database clean. All cron operations require `manage_options` capability, ensuring only authorized users can delegate automation authority to agents.【F:includes/class-wp-mcp-ai-cron-manager.php†L12-L280】

Each tool inherits the assistant context and authenticated user from the REST layer, making it easy to layer custom permissions or extend behaviour via the documented filters and actions.【F:includes/class-wp-mcp-ai-rest.php†L236-L360】【F:includes/class-wp-mcp-ai-rest.php†L1124-L1198】

Need per-tool prerequisites or capability callouts? Consult [`docs/tool-reference.md`](docs/tool-reference.md) for a detailed matrix of every bundled integration.


---

## 🗨️ Front-end chat surfaces

WP oOS ships multiple ways to embed assistants on the front end:

- **Classic chat shortcode** – `[mcp_ai_chat]` renders the bundled interface with attachment uploads, tool invocation feedback, and optional guest access via `allow_guests="true"`. When guest mode is enabled, the shortcode provisions a temporary token and injects it into the JavaScript bootstrap so visitors without WordPress accounts can continue chatting while still respecting capability checks and attachment safety limits.【F:includes/class-wp-mcp-ai-shortcode.php†L132-L258】【F:includes/class-wp-mcp-ai-shortcode.php†L188-L226】
- **Elementor widgets** – Drop the chat UI anywhere Elementor is active, pair it with intro/FAQ blocks, and surface dashboard telemetry without custom code. The chat widget mirrors the shortcode controls (including `allow_guests`), and companion widgets expose onboarding content, usage timers, provider quick links, and activity feeds for operational views.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L79-L138】【F:includes/class-wp-mcp-ai-elementor-integration.php†L48-L98】【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L47-L140】【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L48-L226】【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L48-L167】

Guest tokens are honoured by the REST endpoints through the `X-WP-MCP-AI-Guest` header or `guest_token` parameter, allowing the chat shortcode and Elementor widget to make authenticated requests on behalf of public visitors without exposing persistent credentials.【F:includes/class-wp-mcp-ai-rest.php†L289-L307】【F:includes/class-wp-mcp-ai-rest.php†L2088-L2104】

### Chat History Persistence

The chat interface automatically persists conversation history to the browser's localStorage, preventing data loss when users navigate away or refresh the page. Conversations are:

- **Automatically saved** after each user message and assistant response
- **Automatically restored** when returning to the chat page (within 24 hours)
- **Stored per assistant** so different assistant conversations remain separate
- **Server-side storage available with JetEngine** - See note below about optional JetEngine integration

#### Server-Side Chat Transcript Storage (Requires JetEngine)

⚠️ **Third-Party Plugin Required:** [JetEngine](https://crocoblock.com/plugins/jetengine/?ref=16658) (not included with WP oOS)

Without JetEngine, chat conversations are **only stored in browser localStorage** (client-side, 24-hour retention). To enable permanent server-side chat transcript archiving:

1. Install and activate the [JetEngine](https://crocoblock.com/plugins/jetengine/?ref=16658) plugin (third-party, paid plugin from Crocoblock)
2. Enable the **Custom Content Types** module in JetEngine settings
3. WP oOS will automatically provision the `ai_chat_transcripts` CCT for permanent storage

**What you get with JetEngine:**
- ✅ Permanent server-side chat transcript storage
- ✅ Cross-device conversation access
- ✅ Admin visibility into chat history
- ✅ Database-backed chat logs for compliance/auditing

**Without JetEngine:**
- ⚠️ Chat history only stored in browser localStorage
- ⚠️ Limited to 24-hour retention
- ⚠️ No cross-device synchronization
- ⚠️ Lost if browser data is cleared

See [docs/chat-history-persistence.md](docs/chat-history-persistence.md) for complete details on the persistence mechanism, data structure, and troubleshooting.

---

## 📦 Installation

### For Developers (GitHub Clone)

If you're cloning from GitHub for development:

#### Option 1: Cloudways and Managed Hosting (Recommended)

For Cloudways and similar managed hosting platforms, clone directly into the WordPress plugins directory:

```bash
# SSH into your server
# Navigate to WordPress plugins directory
cd /home/master/applications/YOURAPP/public_html/wp-content/plugins/

# Clone the repository
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos

# Verify you're in the correct directory
pwd  # Should show the plugins path

# Install dependencies
npm install && composer install --no-dev --optimize-autoloader
```

**⚠️ Cloudways Important Notes:**
- Always clone directly into `/home/master/applications/YOURAPP/public_html/wp-content/plugins/`
- Do NOT clone elsewhere and then move/copy - this causes `getcwd() failed` errors
- Replace `YOURAPP` with your actual Cloudways application name

#### Option 2: Local Development or VPS

For local development or standard VPS hosting:

```bash
# Option A: Clone directly into WordPress plugins directory (recommended)
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
npm install && composer install --no-dev --optimize-autoloader

# Option B: Clone, install, then copy
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
npm install && composer install --no-dev --optimize-autoloader
cp -r . /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/
```

#### Final Steps

1. Activate **Open Operator System Complete (WP oOS)** from WordPress admin
2. You now have the **complete version** with all 109 tools (71 base + 38 Pro)

**What you get from the repository clone:**
- ✅ Complete plugin with base + Pro features combined
- ✅ All 109 built-in tools ready to use
- ✅ Single plugin activation (not separate base + pro)
- ✅ Pro features automatically available (no separate Pro plugin to install)

**Notes**: 
- The repository includes `mcp-ai-wpoos-base.php` and `addons/pro/mcp-ai-wpoos-pro.php` which are used for building separate distributions but do NOT appear as separate plugins when cloning
- Only the main plugin file (`mcp-ai-wpoos.php`) has a plugin header in the repository
- The build script adds headers to the other files when creating standalone distributions

### Standard Installation
1. Upload `mcp-ai-wpoos.zip` to `/wp-content/plugins/`
2. Activate **WP oOS** from the WordPress admin
3. Go to **Settings → WP oOS**
4. Enter your OpenAI API key
5. Create a new “AI Assistant” in **AI Assistants**
6. Add `[mcp_ai_chat assistant="123"]` to a page or post

### Optional: JetEngine Integration

⚠️ **Third-Party Plugin (Not Included):** [JetEngine](https://crocoblock.com/plugins/jetengine/?ref=16658) is a paid plugin from Crocoblock

**JetEngine is completely optional** - WP oOS works perfectly without it. However, if you want server-side chat transcript storage:

1. Purchase and install [JetEngine](https://crocoblock.com/plugins/jetengine/?ref=16658) separately
2. Enable the **Custom Content Types** module in JetEngine settings
3. WP oOS will automatically provision the `ai_chat_transcripts` CCT for permanent chat storage

**What works WITHOUT JetEngine:**
- ✅ All core AI assistant features
- ✅ Chat interface and conversations
- ✅ 74 base tools (105 in Full Version with other plugins)
- ✅ MCP server functionality (`/wp-json/mcp-ai/v1/`)
- ✅ Browser-based chat history (localStorage, 24 hours)
- ✅ OpenAI/Gemini/Ollama integrations

**What requires JetEngine:**
- ❌ Server-side chat transcript storage (chat history only in browser without it)
- ❌ 5 JetEngine-specific tools (see [🔌 Optional Tools & Dependencies](#-optional-tools-dependencies))

---

## 🔌 What You Lose Without Third-Party Plugins

WP oOS works perfectly with vanilla WordPress, but certain features require third-party plugins (sold separately). Here's exactly what you lose without each plugin:

### Without JetEngine (Crocoblock - Paid Plugin)

**Lost Features:**
- ❌ **Server-side chat transcript storage** - Chat history only stored in browser localStorage (24 hours)
- ❌ **Cross-device chat synchronization** - No database-backed conversation history
- ❌ **Admin chat history access** - Cannot view/audit conversations from admin panel
- ❌ **Assistant CCT synchronization** - Assistants only in WordPress CPT (MCP server still works perfectly)

**Lost Tools (5 tools):**
- `get_jetengine_items` - Query JetEngine custom post types
- `list_jetengine_rest_routes` - List JetEngine REST API routes
- `invoke_jetengine_route` - Execute JetEngine REST operations
- `get_jetformbuilder_forms` - List JetFormBuilder forms (also requires JetFormBuilder)
- `get_jetformbuilder_submissions` - Get form submissions (also requires JetFormBuilder)

**✅ Still Works:** All core features, MCP server, 74 base tools, AI conversations

[Get JetEngine →](https://crocoblock.com/plugins/jetengine/?ref=16658)

---

### Without WooCommerce (Free Plugin)

**Lost Features:**
- ❌ **E-commerce automation** - Cannot create or manage products via AI
- ❌ **Order management** - Cannot query or analyze orders
- ❌ **Product catalog access** - Cannot search or update product data

**Lost Tools (3 tools):**
- `create_woo_product` - Build draft WooCommerce products with AI-generated descriptions, pricing, and images
- `get_woo_products` - Search and retrieve product catalog with pricing and stock status
- `get_woo_recent_orders` - Summarize recent orders with billing details and totals

**Use Cases Lost:** E-commerce content generation, order fulfillment assistance, product merchandising

[Get WooCommerce →](https://wordpress.org/plugins/woocommerce/)

---

### Without Elementor (Freemium Plugin)

**Lost Features:**
- ❌ **Template management** - Cannot list or reference Elementor templates via AI
- ❌ **Elementor widgets** - Cannot use pre-built chat/dashboard widgets (shortcodes still work)

**Lost Tools (2 tools):**
- `get_elementor_templates` - List Elementor library templates with status, type, and edit links
- `import_elementor_template_kit` - Import Elementor template kits

**Lost UI Components:**
- Elementor Chat Widget
- Elementor Chat Intro Widget
- Elementor Dashboard Widgets (Tool Matrix, User Capabilities, Activity Feed, etc.)

**✅ Still Works:** Standard `[mcp_ai_chat]` shortcode, all AI features

[Get Elementor →](https://wordpress.org/plugins/elementor/)

---

### Without Rank Math SEO (Freemium Plugin)

**Lost Features:**
- ❌ **SEO analysis** - Cannot query SEO scores or optimization recommendations
- ❌ **Schema data access** - Cannot retrieve structured data for posts

**Lost Tools (1 tool):**
- `get_rankmath_seo` - Get SEO scores, focus keywords, robots metadata, and schema details for posts

**Use Cases Lost:** AI-powered SEO content optimization, SEO audit assistance

[Get Rank Math →](https://wordpress.org/plugins/seo-by-rank-math/)

---

### Without WPCode (Freemium Plugin)

**Lost Features:**
- ❌ **Code snippet management** - Cannot create or update code snippets via AI
- ❌ **Custom functionality automation** - Cannot automate adding hooks, filters, or custom code

**Lost Tools (1 tool):**
- `create_wpcode_snippet` - Create or update code snippets with validation and activation control

**Use Cases Lost:** AI-assisted custom development, automated code snippet generation

[Get WPCode →](https://wordpress.org/plugins/insert-headers-and-footers/)

---

### Without Simple JWT Login (Free Plugin)

**Lost Features:**
- ❌ **JWT token generation** - Cannot generate JWT bearer tokens for headless WordPress integrations

**Lost Tools (1 tool):**
- `generate_simple_jwt_token` - Generate JWT bearer tokens for authenticated API access

**Use Cases Lost:** Headless WordPress authentication, mobile app integration, SPA authentication

[Get Simple JWT Login →](https://wordpress.org/plugins/simple-jwt-login/)

---

### Summary: Third-Party Plugin Dependencies

| Plugin | Type | Tools Lost | Key Feature Lost |
|--------|------|------------|------------------|
| **JetEngine** | Paid (Crocoblock) | 5 | Server-side chat transcript storage |
| **WooCommerce** | Free | 3 | E-commerce automation |
| **Elementor** | Freemium | 2 + Widgets | Elementor template integration |
| **Rank Math** | Freemium | 1 | SEO analysis |
| **WPCode** | Freemium | 1 | Code snippet management |
| **Simple JWT Login** | Free | 1 | JWT token generation |

**Total Impact:** Without these plugins, you lose **13 tools** but retain **74 core tools** and all essential AI assistant functionality.

---

### Base Version (Default)

**WP oOS runs in Base Version mode by default**, providing 74 essential tools that work with vanilla WordPress without requiring any third-party plugins:

**Base Version includes 74 essential tools that work with vanilla WordPress:**
- Content management (search, save posts, attachments)
- AI media generation (images via OpenAI/Gemini, speech, transcription, video)
- Research tools (web search, weather, disaster alerts)
- Site operations (health checks, logs, cron jobs, cache management)
- WordPress-native email (via wp_mail)
- Image manipulation (resize, crop, rotate, convert)
- Profession and assistant management
- GitHub integration tools
- Google Maps Platform tools

**Base Version excludes 31 tools requiring third-party plugins or external APIs:**
- **Third-party WordPress plugins** (13 tools) - See [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins) for details
  - WooCommerce tools (3)
  - JetEngine/JetFormBuilder tools (5)
  - Elementor tools (2)
  - RankMath/WPCode/Simple JWT Login tools (3)
- **External API services** (18 tools) - Require API credentials
  - Google services (5)
  - Social media integrations (8)
  - External messaging services (4)
  - QuickBooks (1)

### Full Version Installation (Opt-in)

To enable the **Full Version** with all third-party integrations and external API tools, add this constant to your `wp-config.php` file:

```php
define( 'WP_MCP_AI_BASE_VERSION', false );
```

📖 See BASE-VERSION.md for the complete tool list and customization options.

**When to use Base Version:**
- Starting fresh with WordPress
- Testing or development environments
- Simpler installations without external dependencies
- Sites that don't need e-commerce or advanced integrations
- Don't want to purchase/install third-party plugins

**When to use Full Version:**
- Production sites with WooCommerce, JetEngine, or Elementor already installed
- Sites needing social media automation (requires API credentials)
- Advanced workflows requiring external APIs
- Need server-side chat transcript storage (requires JetEngine)

📖 **See detailed breakdown:** [🔌 What You Lose Without Third-Party Plugins](#-what-you-lose-without-third-party-plugins)


---

## 📚 Documentation

WP oOS includes comprehensive documentation covering all aspects of the plugin. **Documentation was reorganized in November 2025** - 95+ historical documents moved to `docs/archive/` for better navigation.

### Quick Links
- **[Quick Reference Guide](docs/QUICK_REFERENCE.md)** - Fast access to common tasks and commands
- **[Documentation Index](docs/DOCUMENTATION_INDEX.md)** - Complete map of all documentation files
- **[Tool Reference](docs/tool-reference.md)** - Detailed guide to all 105 built-in tools
- **[REST API Documentation](docs/rest-api.md)** - Complete API reference with examples
- **[Testing & Quality Report](docs/TESTING_AND_QUALITY_REPORT.md)** - Comprehensive test results and code quality analysis

### For New Users
- [Setup Checklist](docs/mcp-ai-plugin-setup-checklist.md) - Step-by-step installation and configuration
- [Remote Client Quickstart](docs/remote-client-quickstart.md) - Connect Claude Desktop, LM Studio, or other MCP clients
- [Best Practices](docs/BEST_PRACTICES.md) - Recommended usage patterns and optimization tips

### For Developers
- **[Testing & Quality Report](docs/TESTING_AND_QUALITY_REPORT.md)** - Test suite results (2,106 tests, 73.4% pass rate), code quality analysis, security audit
- [Code Review Master](docs/CODE-REVIEW-MASTER.md) - Comprehensive code quality analysis (95/100 score)
- [Action Items](docs/ACTION_ITEMS.md) - Prioritized development tasks (180+ hours)
- [Authentication Guide](docs/mcp-server-authentication.md) - Authentication methods and security
- [MCP JSON-RPC 2.0 Endpoint](docs/mcp-endpoint.md) - Model Context Protocol implementation

### For Administrators
- [Deployment Troubleshooting](docs/deployment-troubleshooting.md) - Common issues and solutions
- [Multisite Support](docs/multisite-support.md) - WordPress multisite configuration
- [Rate Limit Protection](docs/rate-limit-protection.md) - API rate limiting setup
- [Mesh Routing Guide](docs/mesh-routing-guide.md) - Intelligent compute routing across sites and providers
- [Federation & Discovery](docs/federation-discovery.md) - Decentralized AI capability network with peer discovery and well-known endpoints

### Performance & Optimization
- [Message Bundling](docs/message-bundling-feature.md) - Client-side message optimization
- [High Token Tool Handling](docs/high-token-tool-handling.md) - Agentic loop token management
- [Job Notification System](docs/job-notification-system.md) - Real-time async job updates
- [Chat Performance Optimizations](docs/chat-performance-optimizations.md) - Complete performance guide
- [Mesh Routing Guide](docs/mesh-routing-guide.md) - Intelligent compute routing across sites and providers

### Historical Documentation
- **[Archive Directory](docs/archive/)** - 95+ historical documents organized by category:
  - `implementations/` - Implementation summaries and technical details
  - `phases/` - Development phase documents
  - `fixes/` - Bug fix summaries and issue resolutions
  - `features/` - Feature documentation
  - `code-reviews/` - Code review reports
  - `testing/` - Test infrastructure documentation

---

## ⚙️ Configuration Checklist (Action Items)

Complete these after installation to unlock every integration point:

- [ ] **Add your OpenAI API key** in **Settings → WP oOS → OpenAI API Key** so API calls are authorised.
- [ ] **Add your Gemini API key** in **Settings → WP oOS → Gemini API Key** if you plan to route assistants through Gemini.
- [ ] **Confirm or override the default model** via **Settings → WP oOS → Default Model** (`gpt-4.1` ships as the default).
- [ ] **Set a default Gemini model** under **Settings → WP oOS → Default Gemini Model** when Gemini is enabled.
- [ ] **Choose the default provider** from **Settings → WP oOS → Default Provider** so new assistants know whether to use OpenAI or Gemini by default.
- [ ] **Adjust the request timeout** under **Settings → WP oOS → Request Timeout** (minimum 5 s, default 30 s) to match your hosting environment.
- [ ] **Select a default assistant** with **Settings → WP oOS → Default Assistant** so REST and shortcode requests have a fallback.
- [ ] **Decide on logging** with **Settings → WP oOS → Enable Logging** when you need verbose diagnostics.
- [ ] **Monitor token usage** in **Settings → WP oOS → Token Usage Statistics** to track API consumption across users, providers, and models for billing and budget management.
- [ ] **Choose your uninstall behaviour** via **Settings → WP oOS → Remove Data on Uninstall** if this site should purge assistants and settings during cleanup.
- [ ] **Configure Crawl4AI access** in **Settings → WP oOS → Tools** when you want the Crawl4AI tool to be available to assistants.
- [ ] **Review attachment MIME overrides** in **Settings → WP oOS → Attachments** before enabling file uploads for end users.
- [ ] **Review Send Group Email permissions** in **Settings → WP oOS → Tools** to choose the capability and recipient cap for the group email automation.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L348-L359】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L938-L953】
- [ ] **Connect QuickBooks Online** under **Settings → WP oOS → QuickBooks Company ID / API Key** so the bundled reporting tool can fetch finance statements for authorised operators.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L955】
- [ ] **Configure Mailjet credentials** in **Settings → WP oOS → Mailjet API Key / Secret / From Email / From Name** before enabling Mailjet-powered tools or Elementor widgets that send email on behalf of assistants.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L1008-L1054】
- [ ] **Enable Federation & Discovery** (Optional) in **Settings → WP oOS → Federation & Discovery** to publish your site's AI capabilities via `/.well-known/ai-peer` and optionally run a directory service for peer discovery. Configure regions, data tags, and rate limits to control how your site participates in the decentralized AI network.【F:docs/federation-discovery.md†L1-L511】【F:FEDERATION-IMPLEMENTATION-SUMMARY.md†L1-L381】
- [ ] **Configure Root Security Key** (Optional) by adding `define( 'WP_MCP_AI_ROOT_SECURITY_KEY', 'your-secure-key' );` to wp-config.php. This provides an additional security layer that can be enabled during emergency shutdown to require authentication before re-initializing the plugin.【F:docs/root-security-key.md†L1-L511】

## 🧠 Language Model Providers (OpenAI, Gemini, Ollama & LM Studio)

A dedicated router transparently forwards chat completions to the active provider, allowing each request to target OpenAI, Gemini, a local Ollama instance, or LM Studio while sharing the same assistant UX.【F:includes/class-wp-mcp-ai-language-model-router.php†L12-L63】 Configure the required API keys, default models, and the global default provider in **Settings → WP oOS** so new assistants inherit sensible defaults and administrators can switch providers without code changes.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L124-L333】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L505-L530】 Assistants can still override provider, model, and generation parameters on a per-post basis.

### LM Studio Support

**LM Studio with Function Calling** - Full support for OpenAI-compatible function calling with local LM Studio instances:
- OpenAI-compatible message structure preserved for tool calls
- Tools/functions can be invoked by LM Studio models (e.g., qwen/qwen3-coder-30b)
- Streaming automatically disabled when tools are present for reliable execution
- Full backward compatibility with non-tool scenarios
- Connect via JSON-RPC endpoint (recommended) or SSE streaming
- See [LM Studio setup guide](#-lm-studio-setup) for configuration details

### Provider Priority List & Automatic Fallback

The plugin includes an intelligent provider priority system that automatically tries alternative providers when the primary one fails or is unavailable. In **Settings → WP oOS**, you can:

- **Drag and drop** providers to set your preferred order
- **Automatic fallback** - if the first provider fails, the system tries the next one
- **Visual management** - see all available providers (OpenAI, Gemini, Ollama, LM Studio) in one sortable list
- **Flexible prioritization** - adjust based on cost, performance, or availability needs

The first provider in the list serves as the default. If any provider returns an error, the router automatically attempts the next provider in the list until one succeeds. This ensures maximum uptime and resilience without manual intervention. All fallback attempts are logged for debugging and monitoring.

### Local AI with Ollama

The Ollama provider enables privacy-focused, cost-free AI processing by connecting to a local Ollama or LM Studio instance running on your server or development machine. This is ideal for:
- **Privacy-sensitive deployments** where data must stay on-premises
- **Development and testing** without incurring API costs
- **Custom or fine-tuned models** not available through cloud providers
- **Air-gapped environments** without internet access

To configure Ollama:
1. Install [Ollama](https://ollama.ai) on your server or local machine
2. Pull a model (e.g., `ollama pull llama2`)
3. Navigate to **Settings → WP oOS → Ollama Configuration**
4. Enter your Ollama endpoint URL (default: `http://localhost:11434`)
5. Click "Test Connection" to verify connectivity
6. Click "Fetch Models" to see available models
7. Select a model from the list or manually enter a model name
8. Set "Default Provider" to "Ollama (Local AI)" if you want it as the system default

The Ollama client supports the standard chat completion flow and automatically normalizes responses to match the OpenAI format for downstream compatibility. Note that some advanced features like tool calling may vary depending on the specific Ollama model you're using.

### OpenAI model coverage

The plugin ships with presets for OpenAI’s current Responses, Reasoning, Audio, and Image APIs so site owners can choose the right model for each workflow. Token windows describe the maximum request size (messages, attachments, and tool payloads) the OpenAI API will accept for that model, while output limits reflect the largest single response the service will stream back. Leave a safety margin below each ceiling so assistants can add system instructions, tool calls, and knowledge snippets without hitting provider limits.

| Capability | Model | Max context tokens | Max output tokens | Notes |
| --- | --- | --- | --- | --- |
| Responses (flagship) | `gpt-5.2` | 400,000 | 128,000 | Latest flagship multimodal model with 400K context window (Dec 2025). Ideal for large documents and complex workflows. |
| Responses (pro reasoning) | `gpt-5.2-pro` | 400,000 | 128,000 | Advanced reasoning variant with enhanced capabilities for mission-critical tasks requiring maximum accuracy. |
| Responses (high throughput) | `gpt-5.2-instant` | 400,000 | 128,000 | High-volume optimized variant for customer support and content generation at scale. |
| Responses (deep analysis) | `gpt-5.2-thinking` | 400,000 | 128,000 | Deeper analysis variant with reasoning time dial for multi-step analysis and research tasks. |
| Responses (general) | `gpt-4.1` | 128,000 | 16,384 | Flagship multimodal model that balances quality and latency for production chat, tool, and multimodal calls. |
| Responses (cost optimised) | `gpt-4.1-mini` | 128,000 | 16,384 | Budget-friendly 4.1 variant recommended for day-to-day assistants and background automations. |
| Responses (advanced) | `gpt-4o` | 128,000 | 16,384 | Previous generation multimodal model with strong reasoning capabilities. |
| Responses (legacy) | `gpt-4o-mini` | 128,000 | 16,384 | Lower-latency 4o tier that keeps the larger context window while reducing cost for iterative workflows. |
| Reasoning | `o1-preview` | 128,000 | 32,768 | Deliberate reasoning model suited to multi-step planning and analysis; expect slower responses while it “thinks”. |
| Reasoning (fast) | `o1-mini` | 128,000 | 32,768 | Lighter o1 variant that trades some reasoning depth for responsiveness in operational assistants. |

#### Media and multimodal defaults

| Capability | Model | Size or duration limits | Notes |
| --- | --- | --- | --- |
| Image generation | `gpt-image-1` | Up to 2048×2048 output (square) or proportional 1024/512 variants | Produces photorealistic or illustrative renders; respect OpenAI’s safety filters when prompting. |
| Text-to-speech | `gpt-4o-mini-tts` | Up to ~4,096 input tokens per request | Generates natural-sounding speech in multiple voices; longer scripts should be chunked into multiple calls. |
| Speech-to-text | `gpt-4o-mini-transcribe` | Optimised for recordings ≤ 90 minutes | Handles multilingual transcription and translation; large files are automatically chunked client-side before upload. |

OpenAI regularly revises token policies and media limits, so review the [model specification dashboard](https://platform.openai.com/docs/models) before rolling out new assistants or increasing attachment budgets. Updating your defaults in **Settings → WP oOS** keeps every assistant aligned with the latest provider guidance.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L36-L105】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L2298-L2398】

## 🧱 ChatKit Integration

The [ChatKit](https://github.com/nvdigitalsolutions/chatkit) module now ships with the core WP oOS plugin, so no separate add-on installation is required. Once enabled it self-registers through ChatKit’s filter and action APIs as soon as both plugins load, exposing the `mcp-ai/v1` REST namespace while advertising chat, tool invocation, attachment download, and guest token support without any manual bootstrapping. Return `false` from the `wp_mcp_ai_chatkit_is_available` filter if you need to disable the automatic registration for bespoke environments.【F:includes/class-wp-mcp-ai-chatkit-integration.php†L30-L204】【F:includes/class-wp-mcp-ai-rest.php†L16-L2104】

From the ChatKit dashboard configure the **WP oOS** integration and supply at least one assistant ID so ChatKit knows which conversation to join. Optional fields let you override the system prompt or preload tool shortcut payloads for operators; capability checks inherit the `wp_mcp_ai_chat_capability` filter, so you can align ChatKit access with the same policies used for shortcodes or REST calls.【F:includes/class-wp-mcp-ai-chatkit-integration.php†L182-L210】【F:mcp-ai-wpoos.php†L25-L72】

Consult [`docs/chatkit-integration.md`](docs/chatkit-integration.md) for a full configuration walkthrough, JSON examples for shortcut presets, and notes on extending the definition via filters.

## 🌐 Crawl4AI Integration

Administrators with `manage_options` capabilities can run the **Run Crawl4AI Job** tool without any external service: when no Crawl4AI endpoint is configured the plugin performs the crawl directly on the WordPress server using the built-in HTTP client, extracts headings and text as Markdown, and records the raw HTML and response metadata for the assistant.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L32-L745】 Errors for individual URLs are captured in the response metadata so partial crawls still return useful context. When a remote Crawl4AI endpoint is configured the request now returns immediately with a task token while WP-Cron powered background polling captures the final payload and makes it available to the assistant UI once the crawl finishes.【F:includes/crawler/class-wp-mcp-ai-crawler.php†L1-L214】【F:assets/js/chat.js†L1-L2200】

Configure remote endpoints or API keys under **Settings → WP oOS → Tools** to tailor how the Crawl4AI integration runs across environments.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L248-L521】

Supplying a Crawl4AI base URL (and optional API key) switches the tool back to proxying crawl jobs to the remote Crawl4AI REST API, preserving backwards compatibility with existing deployments.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L206-L339】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L248-L521】 Local environments can still feed a custom endpoint to the integration through the `WP_MCP_AI_CRAWL4AI_BASE_URL` or `CRAWL4AI_BASE_URL` environment variable when you want to test against a dedicated Crawl4AI service.【F:mcp-ai-wpoos.php†L54-L96】

## 📡 Job Notification System

WP oOS includes a general-purpose infrastructure for real-time notifications on async WordPress jobs, providing SSE streaming and webhook support for external integrations.【F:docs/job-notification-system.md†L1-L100】

### Architecture

```
Async Job → WordPress Action → Job Notifier → [SSE | Webhooks]
                                                 ↓       ↓
                                            Frontend  External
```

### Automatic Crawl4AI Integration

The system automatically hooks into Crawl4AI jobs via the `wp_mcp_ai_crawl4ai_job_completed` action, providing real-time status updates as crawls progress. No additional code is needed—Crawl4AI jobs automatically trigger notifications.【F:includes/crawler/class-wp-mcp-ai-crawler.php†L1-L214】

### Frontend SSE Subscription

JavaScript clients can subscribe to job status updates using Server-Sent Events:

```javascript
const jobId = 'crawl_abc123';
const eventSource = new EventSource(
    `/wp-json/mcp-ai/v1/jobs/${jobId}/stream?max_duration=300&poll_interval=2`
);

eventSource.addEventListener('status', (e) => {
    const status = JSON.parse(e.data);
    console.log('Job status:', status.status, status.progress);
    updateProgressBar(status.progress);
});

eventSource.addEventListener('complete', (e) => {
    const data = JSON.parse(e.data);
    console.log('Job completed:', data.final_status);
    eventSource.close();
});
```

### Webhook Registration

External systems can receive HTTP callbacks when jobs complete:

```php
WP_MCP_AI_Job_Notifier::register_webhook(
    'crawl_abc123',
    'https://example.com/webhook',
    array( 'completed', 'failed' )
);
```

➡️ See [docs/job-notification-system.md](docs/job-notification-system.md) for complete implementation details.

## 🧊 Elementor Widgets

Sites running Elementor automatically register a suite of MCP blocks so you can assemble onboarding pages, operational dashboards, and standalone chat layouts without writing markup.【F:includes/class-wp-mcp-ai-elementor-integration.php†L12-L98】 The integration only boots when Elementor is present, so non-Elementor installs avoid any overhead.【F:includes/class-wp-mcp-ai-elementor-integration.php†L29-L46】

### Chat surfaces and companion blocks
- **WP oOS Chat** – Renders the assistant interface with the same controls exposed by the `[mcp_ai_chat]` shortcode, including the `allow_guests` toggle for minting temporary visitor tokens.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L17-L138】
- **WP oOS Chat Intro** – Adds a configurable hero block above the conversation with headings, talking points, and an optional call-to-action button to guide visitors before they engage the model.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L47-L190】
- **WP oOS Chat FAQ** – Surfaces a repeater-driven FAQ list alongside the chat so product teams can document policies and best practices in context.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-faq-widget.php†L47-L150】
- **WP oOS Usage & Timer** – Combines a focus timer with per-user token totals, gracefully handling logged-out visitors, disabled tracking, and empty usage histories.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L48-L340】

### Operations dashboards
- **WP oOS Tool Matrix** – Pulls the tool registry, groups integrations by focus area, and highlights the required capability for each assistant tool so administrators can plan enablement safely. The Send Group Email row now mirrors the capability and recipient limit configured in the MCP settings so editorial policies stay front-of-mind.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php†L48-L440】
- **WP oOS User Capability Snapshot** – Summarises the signed-in operator’s profile, common capabilities, JetEngine access, and multisite memberships to support governance reviews. It also surfaces the configured Send Group Email capability and limit so administrators immediately know whether the current user can trigger bulk mail jobs.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php†L48-L392】
- **WP oOS Theme Preview** – Renders a mock conversation using the saved chat color tokens and optionally displays a legend of every branding token for quick QA during rollouts.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php†L48-L198】
- **WP oOS Provider Quick Links** – Reuses the OpenAI usage/log tools to populate external billing and telemetry shortcuts that open in new tabs for rapid debugging.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php†L48-L166】
- **WP oOS Activity Feed** – Streams the latest MCP log entries (tool runs, chat interactions, and optional provider requests), collapsing raw context into expandable JSON blocks for deeper analysis.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L48-L210】

## 🧮 Usage Tracking

The plugin records aggregate token usage per user, provider, and model whenever responses include usage metadata, simplifying internal reconciliation or billing workflows. Usage data is stored as user meta and automatically purged when accounts are deleted, and hooks are exposed for custom reporting pipelines.【F:includes/class-wp-mcp-ai-usage-tracker.php†L12-L119】

### Token Usage Management Dashboard

Administrators with `manage_options` capability can view comprehensive token usage statistics in **Settings → WP oOS**:

**Global Statistics (All Users):**
- Total requests across all users
- Total tokens consumed (prompt + completion)
- Prompt tokens used
- Completion tokens generated
- Cached tokens (for providers supporting prompt caching)
- Reset all usage data button (with confirmation)

**Individual User Statistics:**
- Your personal token consumption
- Per-user breakdown of requests and tokens
- Reset personal usage data button

**Detailed Breakdown:**
- Usage by provider (OpenAI, Gemini, Ollama, LM Studio)
- Usage by specific model (e.g., `gpt-4.1-mini`, `gemini-2.0-flash`)
- Request counts per provider/model combination
- Last used timestamp for each model
- Comprehensive table view with all metrics

The usage tracking system automatically:
- Records usage from all API responses that include usage metadata
- Aggregates data by user, provider, and model
- Updates in real-time as conversations occur
- Supports the **Open OpenAI Usage** tool for quick access to provider dashboards
- Provides AJAX-powered reset functionality for administrators

## 🧷 Attachment MIME Controls

Administrators can override the default image and file MIME allowlists used by the chat uploader. The settings screen accepts one MIME type per line, and the attachment helper merges the overrides with its defaults before enforcing them on upload and shortcode configuration.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L225-L669】【F:includes/class-wp-mcp-ai-message-attachments.php†L503-L559】 Leave the fields empty to fall back to the bundled safe defaults.

## ⚡ Message Bundling

WP oOS implements client-side message bundling to optimize API usage and reduce server load. When enabled, messages sent within an 800ms window are automatically grouped into a single API request, reducing costs and improving performance for users who send multiple messages in quick succession.【F:docs/message-bundling-feature.md†L1-L80】

### How It Works

1. User sends a message → Displayed immediately in the chat UI
2. 800ms timer starts → System waits for additional messages
3. More messages arrive → Timer resets with each new message
4. Timer expires → All queued messages sent together in one request

### Visual Feedback

- **"Preparing to send…"** - Messages are being queued during the bundling window
- **"Sending…"** - Bundled messages are being transmitted to the server

### Benefits

- **Reduced API costs** - Fewer requests mean lower costs for pay-per-request APIs
- **Lower server load** - Fewer requests to process and respond to
- **Better mobile experience** - Ideal for users who type in short bursts
- **Backward compatible** - Server code unchanged, same payload format

### Configuration

Message bundling is enabled by default and requires no configuration. To disable for debugging:

```javascript
window.wpMcpAiChatDebugMode = true;
```

➡️ See [docs/message-bundling-feature.md](docs/message-bundling-feature.md) for configuration options and implementation details.

## 🎯 Agentic Loop Token Management

WP oOS includes intelligent handling for tools that return large responses, preventing token overflow errors during agentic loops (where the AI automatically calls multiple tools).【F:docs/high-token-tool-handling.md†L1-L80】

### The Problem

Tools like `run_crawl4ai_job` can return 100,000+ tokens of content. In agentic loops, each API call includes all previous messages, causing token counts to grow rapidly and exceed model limits (e.g., gpt-4.1-mini's 200k TPM limit).

### The Solution: Three-Tier Strategy

#### Tier 1: Token Limit Detection
- Estimates total tokens before each API call
- Checks against model's TPM (Tokens Per Minute) limit
- Prevents requests that would exceed limits

#### Tier 2: Automatic Model Switching
- When limits exceeded, auto-switches to fallback model
- Default fallback: Gemini 2.0 Flash (1-2 million token capacity)
- Preserves full context without data loss
- Transparent to the user

#### Tier 3: Message Truncation
- If even fallback model can't handle tokens
- Truncates older messages from conversation
- Always preserves system prompts and recent context
- Logs what was truncated for debugging

### Configuration

Automatic model switching is enabled by default. Configure fallback model under **Settings → WP oOS**:

```php
// Default fallback model
'fallback_model' => 'gemini-2.0-flash-exp'
```

➡️ See [docs/high-token-tool-handling.md](docs/high-token-tool-handling.md) for complete technical details and examples.

## 🔄 Chat Performance Optimizations

WP oOS includes several performance optimizations to enhance the chat experience:

- **Message bundling** - Reduces API calls by grouping rapid user inputs
- **Token budget management** - Prevents API limit overruns with safety margins【F:docs/tpm-limit-validation.md†L1-L50】
- **Chat history persistence** - LocalStorage (24h) + optional JetEngine CCT storage【F:docs/chat-history-persistence.md†L1-L50】
- **Automatic model switching** - Seamlessly handles token overflow scenarios
- **Rate limit protection** - Intelligent retry with exponential backoff【F:docs/rate-limit-protection.md†L1-L50】

➡️ See [docs/chat-performance-optimizations.md](docs/chat-performance-optimizations.md) for detailed performance tuning guide.

## 🌐 Mesh Compute Routing

WP oOS includes **intelligent mesh compute routing** that automatically distributes AI workload across multiple sites OR multiple providers using AI-powered decision-making. This feature works in two modes:

1. **Multi-Site Mesh**: Distribute load across multiple WordPress installations
2. **Single-Site Multi-Provider**: Balance load across OpenAI, Gemini, and Ollama on one site

Both modes use the same AI-powered routing engine to optimize for cost, performance, and reliability.

### Key Capabilities

- **AI-Optimized Routing** - Analyzes prompt complexity and routes to optimal provider/site
- **Cost Optimization** - Use GPT-4o-mini for simple queries, GPT-4o for complex tasks
- **Automatic Failover** - Switch providers on rate limits or outages
- **Compute Hubs** - Designate powerful servers for heavy workloads
- **Rate Limit Management** - Auto-switch to alternative providers when limits hit
- **Privacy Control** - Route sensitive data to local Ollama instances

### Quick Start Examples

**Single-Site Setup** (No mesh required):
- Configure multiple AI providers (OpenAI + Gemini + Ollama)
- Set assistant routing strategy to "AI Optimized"
- Save 90% on costs by routing simple queries to cheaper models

**Multi-Site Setup** (Distributed compute):
- Enable mesh networking on all sites
- Designate compute hubs with larger models
- Automatic load balancing across peer sites
- Cross-server compute pooling for Cloudways, SiteGround, etc.

➡️ See [docs/mesh-routing-guide.md](docs/mesh-routing-guide.md) for complete setup guide, routing strategies, and use cases.
➡️ See [docs/mesh-compute-pooling.md](docs/mesh-compute-pooling.md) for architecture and authentication details.

## 🔗 Federation & Discovery System

WP oOS includes a **decentralized AI capability network** that allows WordPress sites to publish their capabilities and discover peer sites. Think of it as "npm for AI tools" — sites can advertise what they offer and find complementary capabilities from trusted peers.

### Overview

The Federation & Discovery system provides three deployment modes:

1. **Publisher Mode**: Publish your site's capabilities via `/.well-known/ai-peer`
2. **Directory Mode**: Run a discovery service for peer registration and search
3. **Consumer Mode**: Query directories to find and use peer capabilities

### Quick Start

**Enable Federation (Publisher Mode):**
1. Navigate to **Settings → WP oOS → Federation & Discovery**
2. Check **Enable federation**
3. Configure regions (e.g., `us, eu, ap`) and data tags (e.g., `no_pii, gdpr_ok`)
4. Your capabilities are now published at `https://yoursite.com/.well-known/ai-peer`

**Enable Directory Service (Optional):**
1. In the same settings section, check **Enable directory service**
2. Your directory API is now available at `https://yoursite.com/wp-json/ai-dir/v1`
3. Automatic hourly health checks verify registered peers

### Key Features

- 📡 **Well-Known Endpoints** - Standards-based capability publishing
- 🔍 **Peer Discovery** - Search by capability, region, and data policy
- ✅ **Health Monitoring** - Automatic cron-based peer verification
- 🏆 **Smart Ranking** - Scores peers by region, latency, and policy match
- 🔐 **JWKS Verification** - Built-in security with public key discovery
- ⚙️ **Conditional Loading** - Zero overhead when disabled

### API Endpoints

**Directory REST API (`/wp-json/ai-dir/v1`):**
- `POST /peers/register` - Register a new peer
- `GET /peers` - List all peers with health status
- `GET /peers/{id}` - Get peer details
- `GET /search` - Search peers by capability/region/policy
- `POST /reverify/{id}` - Manually trigger health check
- `POST /report/{id}` - Report peer issues

**Well-Known Endpoints:**
- `GET /.well-known/ai-peer` - Your site's capability manifest
- `GET /.well-known/jwks.json` - Public keys for verification

### Use Cases

**Private Organization Network:**
- Multiple WordPress sites within one organization
- Share AI capabilities across internal sites
- Central directory for discovery
- Private peer network with secure authentication

**Public Directory Service:**
- Community-run capability discovery
- Accept registrations from external sites
- Provide search API for consumers
- Build an ecosystem marketplace

**Capability Consumer:**
- Query public directories for needed capabilities
- Integrate with mesh router for automatic peer selection
- No need to publish your own capabilities
- Access specialized tools from the network

### Configuration Options

- **Regions**: Geographic locations (e.g., `us, eu, ap, global`)
- **Data Tags**: Compliance policies (e.g., `no_pii, gdpr_ok, hipaa_like`)
- **QPS Limit**: Queries per second (default: 5)
- **Burst Capacity**: Simultaneous requests (default: 10)

➡️ **Complete Documentation:** [docs/federation-discovery.md](docs/federation-discovery.md)
➡️ **Implementation Summary:** FEDERATION-IMPLEMENTATION-SUMMARY.md

## 🕵️ Code Review

The 2025-10-31 internal review confirms the hardening of the group email automation (header filtering and attachment caps) and the case-sensitive variable handling in the OpenAI external action tool, and only flags a low-severity performance concern around guest token transient churn for public chat embeds. These findings have been consolidated into the master code review document. One follow-up action item recommends re-using or rate-limiting guest tokens to keep the options table tidy on cache-less hosts.

➡️ See [docs/CODE-REVIEW-MASTER.md](docs/CODE-REVIEW-MASTER.md) for the complete code quality assessment.

## 🔒 MCP Server Authentication

Remote MCP assistants should authenticate with Auth0-issued bearer tokens (`Authorization: Bearer YOUR_TOKEN`) whose audience and scope align with the values configured under **Settings → WP oOS**. Same-origin experiences (the dashboard editor and shortcode UI) continue to rely on the `X-WP-Nonce` header tied to the logged-in WordPress session. Review [docs/mcp-server-authentication.md](docs/mcp-server-authentication.md) for a complete setup guide plus a breakdown of the structured error responses returned on failure, and keep the [deployment troubleshooting checklist](docs/deployment-troubleshooting.md) handy when diagnosing capability or credential regressions.

### Using WP oOS as an MCP server

1. **Install the plugin and create assistants.** Each WordPress instance that activates WP oOS exposes an MCP-ready assistant directory backed by the `ai_assistant` custom post type, so every published assistant becomes available to remote clients once credentials are issued.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L460-L620】
2. **Configure the REST and connector settings.** Populate the Auth0, model provider, and optional integration credentials under **Settings → WP oOS** so the REST controller can advertise the correct namespace URLs and enforce bearer tokens per your tenant, scope, and provider defaults.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L36-L118】
3. **Expose the MCP directory endpoints.** The REST layer publishes `/assistants`, `/chat`, `/tools`, and an SSE-compatible `/sse` handshake inside the `wp-json/mcp-ai/v1` namespace, automatically scoping responses to the authenticated assistant or returning every assistant the caller may read.【F:includes/class-wp-mcp-ai-rest.php†L234-L703】 Hand-held clients can subscribe to the streaming directory event or call the JSON routes directly using the base URLs returned in the directory payload.【F:includes/class-wp-mcp-ai-rest.php†L653-L703】
4. **Register any additional tools.** Extend the server’s capabilities by hooking into `wp_mcp_ai_register_tools` and loading custom tool classes; registered slugs flow through the assistant directory and tool execution endpoint without extra wiring.【F:includes/class-wp-mcp-ai-tool-registry.php†L75-L195】
5. **Verify the deployment before sharing credentials.** Run `wp mcp-ai remote https://example.com/wp-json/mcp-ai/v1 --token=YOUR_TOKEN` from any WP-CLI environment to confirm authentication, assistant scope, and chat probes succeed before you hand tokens to operators or client teams.【F:includes/class-wp-mcp-ai-cli-command.php†L137-L220】

### Operating multiple MCP deployments

Provision a separate WordPress site (or network site) for each MCP server you need, activate WP oOS, and repeat the configuration steps above with environment-specific Auth0 audiences, scopes, and provider keys. Because the assistant directory response includes the resolved REST base and namespace metadata, MCP clients can be pointed at different deployments simply by swapping the base URL and the bearer credential minted for that site’s assistants.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L48-L118】【F:includes/class-wp-mcp-ai-rest.php†L653-L703】

Sites that enable the Simple JWT Login integration can now reuse those bearer tokens alongside Auth0 credentials. The plugin validates tokens with Simple JWT Login’s native services, falls back to manual JWT decoding when the dependency cannot resolve a user, and automatically scopes REST requests to the assistant encoded in the token so cross-assistant hops are blocked with actionable errors.【F:includes/class-wp-mcp-ai-simple-jwt-login-integration.php†L47-L214】【F:includes/integrations/class-wp-mcp-ai-integration-simple-jwt.php†L240-L378】【F:includes/class-wp-mcp-ai-rest.php†L2769-L2808】


## 🌐 Connecting Remote MCP Clients

WP oOS works seamlessly with popular MCP clients including Claude Desktop, LM Studio, and ChatGPT connectors. Each client connects to your WordPress site via the MCP REST API at `/wp-json/mcp-ai/v1` and can access assistants, execute tools, and interact with your WordPress data remotely.

**SSE Support:** All MCP endpoints support Server-Sent Events (SSE) for real-time streaming. Enable SSE in your client configuration for better response times and real-time updates. See the [SSE Streaming Support](#-sse-streaming-support) section for details.

### Quick Start

1. **Generate an assistant credential** from any published assistant's **API Credentials** meta box
2. **Copy the token** (format: `cred_xxxxx.SECRET`) — shown only once!
3. **Configure your MCP client** with your site's base URL and the credential
4. **Test the connection** using the provided test script or WP-CLI command

### Claude Desktop setup

Claude Desktop supports MCP servers through a JSON configuration file. Add your WordPress site:

```json
{
  "mcpServers": {
    "wordpress-site": {
      "url": "https://your-site.com/wp-json/mcp-ai/v1",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET"
      },
      "sse": true
    }
  }
}
```

See the complete [Claude Desktop setup guide](docs/remote-client-setup.md#claude-desktop-setup) and [example configurations](assets/examples/claude-desktop-config.json) for multi-assistant deployments.

### LM Studio Setup

**⚠️ Having SSE content-type errors?** Use the JSON-RPC endpoint instead!

LM Studio can connect using **two methods**:

#### Method 1: JSON-RPC (Recommended - No SSE)

Use this if you're getting `SSE error: Invalid content type, expected "text/event-stream"`:

```json
{
  "servers": [
    {
      "id": "wordpress-mcp",
      "name": "WordPress Site",
      "url": "https://your-site.com/wp-json/mcp-ai/v1/mcp",
      "auth": {
        "type": "bearer",
        "token": "cred_xxxxx.SECRET"
      },
      "timeout": 30000
    }
  ]
}
```

**Configure in LM Studio:**
- **Server Name:** WordPress Site
- **URL:** `https://your-site.com/wp-json/mcp-ai/v1/mcp`
- **Auth Type:** Bearer Token
- **Token:** `cred_xxxxx.SECRET`
- **Do NOT enable SSE**

#### Method 2: SSE Streaming (Optional)

If you want to use SSE for real-time updates:

- **Base URL:** `https://your-site.com/wp-json/mcp-ai/v1`
- **Enable SSE:** ✓ (checked)
- **SSE Endpoint:** `/sse`

See the complete [LM Studio setup guide](docs/remote-client-setup.md#lm-studio-setup) and example configurations:
- [lmstudio-mcp-without-sse.json](assets/examples/lmstudio-mcp-without-sse.json) - Recommended
- [lmstudio-config.json](assets/examples/lmstudio-config.json) - With SSE

### ChatGPT connector setup

⚠️ **Note:** ChatGPT connectors currently require Auth0 authentication. Assistant-issued credentials are not yet supported by OpenAI's ChatGPT platform.

To connect via ChatGPT:
1. Configure Auth0 in **Settings → WP oOS**
2. Generate an Auth0 access token with the configured audience
3. Add the MCP server in ChatGPT's connector settings

See the [ChatGPT connector guide](docs/remote-client-setup.md#chatgpt-connector-setup) for detailed Auth0 setup steps.

### Testing your connection

Use the built-in test script to verify connectivity:

```bash
./bin/test-remote-connection.sh \
  -u https://your-site.com/wp-json/mcp-ai/v1 \
  -t cred_xxxxx.SECRET
```

Or use WP-CLI:

```bash
wp mcp-ai remote https://your-site.com/wp-json/mcp-ai/v1 \
  --token=cred_xxxxx.SECRET
```

Expected output confirms the server is reachable and lists available assistants.

### Complete documentation

For comprehensive setup guides, troubleshooting, and advanced configurations, see:
- **[MCP Client Configurations](docs/mcp-client-configurations.md)** – **⭐ NEW:** Complete guide for all MCP clients (LM Studio, Claude Desktop, Cursor, Continue.dev, Cline, OpenAI)
- **[Remote Client Setup Guide](docs/remote-client-setup.md)** – Step-by-step instructions for Claude Desktop, LM Studio, and ChatGPT
- **[MCP Server Authentication](docs/mcp-server-authentication.md)** – Authentication methods and credential management
- **[REST API Reference](docs/rest-api.md)** – Endpoint documentation and payload examples
- **[Example Configurations](assets/examples/)** – Ready-to-use config files for all major MCP clients

---

## 🎫 Token Management UI

**WP oOS 1.0.0 introduces a centralized Token Manager** for managing all external agent access tokens across your assistants. Access it via **WP oOS → Token Manager** in the admin menu.

### Features

- **Centralized Control** - Manage all assistant credentials in one place
- **Security Best Practice** - Tokens shown only once after creation (cannot be retrieved later)
- **Lifecycle Management** - Create, view, revoke, and delete credentials
- **Audit Trail** - Track who created/revoked each token and when
- **Metadata Display** - See creation date, status (active/revoked), associated assistant  
- **Bulk Visibility** - View credentials across all assistants at a glance

### How It Works

The Token Manager follows industry standards similar to GitHub Personal Access Tokens, Stripe API keys, and Auth0 credentials:

1. **Create Token** - Generate new credentials from the assistant editor
2. **Copy Immediately** - Token shown once and cannot be retrieved later
3. **Use in MCP Clients** - Configure external applications (Codex CLI, MCP clients, custom integrations)
4. **Revoke When Needed** - Disable compromised tokens without deleting audit history
5. **Delete When Done** - Permanently remove tokens and all metadata

### Security Notes

- Tokens are hashed before storage (only hash stored, never plaintext)
- Requires `manage_options` capability
- All actions logged with user attribution
- Revoked tokens cannot be reactivated (must create new)
- HTTPS strongly recommended for token transmission

### Usage Example

```bash
# In assistant editor: Create credential → Copy token immediately
# Token format: cred_[YOUR_PREFIX].[YOUR_SECRET_KEY_HERE]
# Example format only - never share real tokens!

# Configure MCP client (e.g., Codex CLI)
export WPOOS_BEARER_TOKEN="your_token_here"
codex chat --assistant 123 "Hello world"

# Later: Revoke from Token Manager UI if compromised
# Or: Delete entirely when integration removed
```

⚠️ **Security Warning:** The examples above use placeholder tokens. Never share real tokens publicly or commit them to version control.

### Access Requirements

- **Capability:** `manage_options` (administrators only)
- **Menu Location:** WP oOS → Token Manager
- **REST API:** `/wp-json/mcp-ai/v1/token-manager/*`

For complete documentation, see [Token Management Guide](docs/token-management.md).

---

## 🤖 ChatGPT Connector
OpenAI’s ChatGPT connector beta currently authenticates exclusively through Auth0. Because WP oOS issues its own assistant-scoped bearer credentials, you can connect LM Studio, Claude Desktop, and other MCP-aware clients today, while ChatGPT support will require either Auth0 bridging or native bearer support from OpenAI. We’ll update this section as soon as ChatGPT adds compatibility with first-party tokens.【F:docs/mcp-server-authentication.md†L22-L46】

## 🛰 REST API Endpoints

All front-end chat surfaces ultimately call the MCP REST namespace at `/wp-json/mcp-ai/v1`, which exposes dedicated endpoints for chat completions and direct tool execution. Both routes share the same authentication rules described above: supply an Auth0 bearer token, a plugin-issued assistant credential, or a WordPress REST nonce for same-origin requests. Guest tokens issued by the shortcode or Elementor widget continue to be honoured when `allow_guests="true"` is enabled.【F:includes/class-wp-mcp-ai-rest.php†L230-L322】【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L1288-L1336】

- **`GET /assistants`** – Returns a directory of accessible assistants with provider defaults, tool counts, capability metadata, and implementation details so remote clients can choose which assistant to call. Credential tokens are automatically scoped to their issuing assistant while Auth0 tokens and REST nonces surface every published assistant the caller can read.【F:includes/class-wp-mcp-ai-rest.php†L238-L666】 The endpoint also supports Server-Sent Events for MCP clients that expect streaming discovery payloads, emitting a single `directory` event with cache-busting headers before closing the stream.【F:includes/class-wp-mcp-ai-rest.php†L1690-L1772】
- **`GET /sse`** – Mirrors the assistant directory response but forces a Server-Sent Events handshake so MCP clients that negotiate `/sse` subscriptions receive the streaming `directory` payload without additional query parameters.【F:includes/class-wp-mcp-ai-rest.php†L400-L715】
- **`POST /chat`** – Normalises structured `messages`, injects assistant defaults, auto-enables the Submit Document Prompt tool when uploads are present, and forwards the request through the language model router. Responses include the assistant ID and the raw provider payload so clients can stream or render messages as needed.【F:includes/class-wp-mcp-ai-rest.php†L230-L322】【F:includes/class-wp-mcp-ai-rest.php†L931-L1095】
- **`POST /tools`** – Executes a specific registered tool outside of a chat turn. The endpoint enforces assistant tool allowlists, scopes credential-based requests to the issuing assistant, merges assistant defaults (such as external action identifiers), and returns the tool result with execution metadata.【F:includes/class-wp-mcp-ai-rest.php†L264-L322】【F:includes/class-wp-mcp-ai-rest.php†L1162-L1321】

See [docs/rest-api.md](docs/rest-api.md) for payload examples, attachment handling rules, and troubleshooting tips when integrating custom clients.

## 🌊 SSE Streaming Support

WP oOS includes comprehensive Server-Sent Events (SSE) support for real-time streaming responses, enabling faster perceived response times and better user experience.

### What is SSE?

Server-Sent Events provide unidirectional server-to-client streaming over HTTP, allowing the server to push updates as they become available rather than waiting for the complete response.

**Benefits:**
- ⚡ **Faster perceived response time** - Users see content immediately as it's generated
- 🔄 **Real-time updates** - Progressive loading for long-running operations
- 📶 **Connection keep-alive** - Prevents timeouts during lengthy responses
- 🎯 **Better UX** - ChatGPT-style typing effect for AI responses

### SSE-Enabled Endpoints

#### 1. Assistant Directory Streaming (`GET /assistants`)

Stream the assistant directory for MCP clients expecting SSE handshakes:

```bash
curl -H "Accept: text/event-stream" \
  https://your-site.com/wp-json/mcp-ai/v1/assistants
```

The endpoint emits a single `directory` event with all accessible assistants, then closes the connection.

#### 2. Dedicated SSE Endpoint (`GET /sse`)

Force SSE mode for MCP clients that specifically probe the `/sse` endpoint:

```bash
curl https://your-site.com/wp-json/mcp-ai/v1/sse
```

This mirrors the `/assistants` response but always uses SSE format, ensuring compatibility with LM Studio and Claude Desktop.

#### 3. Job Status Streaming (`GET /jobs/{job_id}/stream`)

Subscribe to real-time updates for async operations like Crawl4AI jobs:

```javascript
const eventSource = new EventSource(
    `/wp-json/mcp-ai/v1/jobs/${jobId}/stream?max_duration=300&poll_interval=2`
);

eventSource.addEventListener('status', (e) => {
    const status = JSON.parse(e.data);
    console.log('Progress:', status.progress + '%');
});

eventSource.addEventListener('complete', (e) => {
    console.log('Job finished:', e.data);
    eventSource.close();
});
```

### SSE Configuration

#### Enable POST Method for SSE (LM Studio Compatibility)

By default, SSE uses the standard GET method. For clients with SSE bugs (like LM Studio), enable POST support:

1. Go to **Settings → WP oOS → Assistant Settings**
2. Enable **"Enable POST Method on SSE Endpoint"**
3. Save settings

⚠️ **Note:** Standard SSE specification uses GET. Only enable POST if you experience client compatibility issues.

### Modern SSE Features (2024-2025)

The SSE implementation includes current best practices:
- **Automatic reconnection** with `retry:` directive (3-second interval)
- **Event IDs** for tracking reconnection state
- **HTTP/2 compatibility** for multiplexing
- **Proper CORS headers** for cross-origin requests
- **Cache-Control directives** to prevent proxy buffering
- **Heartbeat messages** to keep connections alive

### Frontend Integration

Enable SSE streaming in your JavaScript client:

```javascript
// Request streaming in chat
const response = await fetch('/wp-json/mcp-ai/v1/chat', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'text/event-stream',
        'X-WP-Nonce': wpMcpAi.nonce
    },
    body: JSON.stringify({
        assistant_id: 123,
        messages: [{ role: 'user', content: 'Hello' }],
        stream: true
    })
});

// Process SSE stream
const reader = response.body.getReader();
const decoder = new TextDecoder();
let buffer = '';

while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    
    buffer += decoder.decode(value, { stream: true });
    const events = buffer.split('\n\n');
    buffer = events.pop();
    
    for (const event of events) {
        if (event.startsWith('data: ')) {
            const data = JSON.parse(event.substring(6));
            // Update UI with streaming chunk
            updateChatUI(data);
        }
    }
}
```

### Documentation

For complete SSE implementation details, configuration options, and troubleshooting:
- **[SSE Streaming Guide](docs/ENABLE-SSE-STREAMING.md)** - Complete implementation guide with code examples
- **[MCP and SSE](docs/MCP-AND-SSE.md)** - Understanding SSE benefits for MCP protocol
- **[Job Notification System](docs/job-notification-system.md)** - Real-time job status via SSE
- **[REST API Reference](docs/rest-api.md)** - SSE endpoint specifications

## 📝 MCP JSON-RPC 2.0 Endpoint

WP oOS implements a dedicated `/mcp` endpoint that follows the **Model Context Protocol specification version 2024-11-05** using JSON-RPC 2.0 for bidirectional communication with AI assistants and tools.【F:docs/mcp-endpoint.md†L1-L80】

**MCP Version:** 2024-11-05  
**Compliance:** OAuth 2.1, Streamable HTTP transport, Progress notifications

### What's New in MCP 2024-11-05

The latest specification includes significant enhancements:
- **OAuth 2.1 Security**: PKCE, token rotation, mandatory HTTPS
- **Streamable HTTP Transport**: Better reconnection and bidirectional communication
- **Progress Notifications**: Descriptive status updates during tool execution
- **Tool Annotations**: Metadata for read-only, destructive operations
- **Session Management**: State recovery via `Mcp-Session-Id` header
- **JSON-RPC Batching**: Efficient parallel task processing

### Endpoint URL

```
POST /wp-json/mcp-ai/v1/mcp
```

### JSON-RPC 2.0 Format

All requests must use standard JSON-RPC 2.0 format:

```json
{
  "jsonrpc": "2.0",
  "id": "unique-request-id",
  "method": "initialize",
  "params": {}
}
```

### Supported Methods

- **`initialize`** - Initialize MCP connection and retrieve server capabilities (with 2024-11-05 enhancements)
- **`tools/list`** - List available tools with annotations for the authenticated assistant
- **`tools/call`** - Execute a specific tool with progress notifications support
- **`resources/list`** - List available resources (knowledge files, etc.) with metadata
- **`prompts/list`** - List available prompt shortcuts

### Authentication (OAuth 2.1 Enhanced)

The MCP endpoint uses enhanced authentication aligned with MCP 2024-11-05 security standards:
- WordPress Nonce (`X-WP-Nonce` header)
- Bearer Tokens (`Authorization: Bearer <token>`) with rotation support
- Assistant Credentials (generated from assistant editor, OAuth 2.1 compliant)
- Auth0 JWT (for enterprise authentication)
- Session Management (`Mcp-Session-Id` header for reconnection)

### Error Handling

**Enhanced Error System** (Phase 3):
- **Severity Levels**: CRITICAL, ERROR, WARNING, INFO, DEBUG for categorized logging
- **User-Friendly Messages**: Automatic translation of technical errors into actionable guidance
- **Recovery Suggestions**: Built-in troubleshooting steps for common failure scenarios
- **Centralized Error Handler**: Consistent error creation with automatic logging
- **Comprehensive Logging**: Track errors, tool executions, and chat interactions
- **Sensitive Data Protection**: Automatic redaction of API keys and tokens in logs

See [Error Handling Documentation](docs/ERROR_HANDLING.md) for detailed usage.

**MCP Standard Error Codes**:
- **-32700**: Parse error (invalid JSON)
- **-32600**: Invalid Request (malformed JSON-RPC)
- **-32601**: Method not found
- **-32603**: Internal error

### Use Cases

| Scenario | Use Endpoint | Method | MCP 2024-11-05 Feature |
|----------|--------------|--------|------------------------|
| Remote MCP client connection | `/mcp` | POST | OAuth 2.1, Sessions |
| Real-time streaming responses | `/sse` | GET | Traditional SSE |
| Streamable HTTP (new) | `/mcp` | POST | Bidirectional streaming |
| Standard chat interface | `/chat` | POST | N/A |
| Direct tool execution | `/tools` | POST | Tool annotations |

### Learn More

➡️ **Complete MCP Documentation:**
- [MCP Endpoint Reference](docs/mcp-endpoint.md) - Complete method documentation and 2024-11-05 features
- [MCP and SSE Explained](docs/MCP-AND-SSE.md) - Understanding transport layers and protocol updates
- [MCP Server Authentication](docs/mcp-server-authentication.md) - OAuth 2.1 and security enhancements
- [MCP Client Configurations](docs/mcp-client-configurations.md) - Connect LM Studio, Claude Desktop, etc.
- [Official MCP Specification 2024-11-05](https://modelcontextprotocol.info/specification/2024-11-05/)

---

## 🛠 Assistant Editor Overview

Assistant posts ship with dedicated controls that map directly to runtime behaviour:

- **Available Tools** – Choose which registered tools (core, WooCommerce, JetEngine, or custom) the model may invoke. Dependency-aware notices explain why certain tools are unavailable, and you can now disable the pre-built prompt shortcuts that tools normally contribute.
- **Model Defaults** – Provide assistant-specific overrides for the OpenAI model, temperature (0–2), and system prompt applied to every conversation.
- **Base Knowledge** – Attach Media Library items that are chunked, truncated, and streamed as memory context, and optionally store an external **Vector Store ID** to coordinate retrieval workflows.
- **Prompt Shortcuts** – Capture labelled prompts with optional descriptions and tool affinities; they render as accessible quick actions in the chat UI so operators can seed conversations instantly.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L893-L1048】【F:includes/class-wp-mcp-ai-shortcode.php†L430-L693】【F:assets/js/chat.js†L600-L666】

If an API or shortcode request omits the `assistant` parameter, the plugin automatically uses the default assistant configured in the global settings.

## 📊 Assistant Storage: CPT vs CCT

WP oOS uses a **Custom Post Type (CPT)** as the primary storage for AI assistants, with automatic synchronization to a **JetEngine Custom Content Type (CCT)** when JetEngine is available.

### Storage Architecture

- **CPT (`mcp_ai_assistant`)**: The authoritative source for all assistant data
  - Full-featured WordPress editor with 14 meta fields
  - Supports credentials, shortcuts, memory files, and advanced features
  - Always available in both Base and Full versions
  - Primary REST endpoint: `/wp-json/mcp-ai/v1/`

- **CCT (`assistants`)**: Synchronized secondary storage (Full Version only)
  - Receives automatic updates when CPT is saved
  - 7 basic fields: title, description, provider, model, system_prompt, temperature, tools
  - Available via JetEngine REST endpoint: `/wp-json/jet-cct/assistants`
  - Ideal for JetEngine-based integrations and queries

### Automatic Synchronization (v1.0.0+)

When you save an assistant through the WordPress admin:

1. **CPT is updated** with all settings
2. **CCT is automatically synced** (if JetEngine is active)
3. **Link is maintained** via `_wp_mcp_ai_cct_item_id` meta
4. **Deletion cascades** - removing CPT also removes linked CCT item

**What gets synced:** Basic configuration (title, description, provider, model, system_prompt, temperature, tools)  
**What's CPT-only:** Advanced features (credentials, shortcuts, memory files, role rules, vector store, external actions)

### When to Use Each Endpoint

**Use CPT endpoint (`/wp-json/mcp-ai/v1/`)** for:
- Chat, tools, and directory interactions
- Full assistant configuration access
- Credential-based authentication
- Primary integration scenarios

**Use CCT endpoint (`/wp-json/jet-cct/assistants`)** for:
- JetEngine-specific queries and filters
- Building JetEngine relations
- Integrating with JetEngine dashboards
- Querying basic assistant metadata

➡️ **[Read the complete CPT vs CCT guide](docs/assistant-storage-cpt-vs-cct.md)** for detailed comparisons, code examples, and migration information.

## ⚡ Assistant Tool Shortcuts

Every assistant exposes a **Prompt Shortcuts** meta box so editors can curate prewritten instructions, scope them to registered tools, and add operator-facing descriptions that appear as tooltips and screen reader hints in the chat UI.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L893-L1048】【F:includes/class-wp-mcp-ai-shortcode.php†L430-L693】【F:assets/js/chat.js†L600-L666】 The shortcode merges these custom prompts with each tool’s declared shortcut tasks and always appends a safe fallback so assistants remain usable even without bespoke entries.【F:includes/class-wp-mcp-ai-shortcode.php†L430-L693】

Developers can extend or replace these prompts with filters such as `wp_mcp_ai_assistant_custom_tool_shortcuts` and `wp_mcp_ai_default_tool_shortcut`, letting sites tailor default quick actions per assistant or environment.【F:includes/class-wp-mcp-ai-shortcode.php†L444-L692】

➡️ [Read the full guide to assistant prompt shortcuts.](docs/assistant-tool-shortcuts.md)

## 👔 Professional & Team Layers

WP oOS includes an enterprise-grade **template system** for rapid assistant deployment through **Professions** and **Teams**. Instead of manually configuring each assistant from scratch, administrators can:

1. **Select from 182 pre-built professional templates** spanning 12 industry categories
2. **Create custom profession templates** with reusable configurations
3. **Deploy entire teams** of specialized assistants with one click
4. **Test everything from the backend** before exposing to end users

### 🎓 Professional Templates

Professions are reusable assistant templates with pre-configured:
- **Role descriptions** and expertise areas
- **Default tools** curated for each profession
- **Knowledge bases** with industry-specific best practices
- **AI model defaults** (provider, model, temperature)
- **Warnings and disclaimers** for professional contexts

**Available Categories (182 professions):**
- 🌾 Agriculture & Natural Resources (10 professions)
- 🎨 Art, Media & Entertainment (24 professions)
- 💼 Business & Finance (16 professions)
- 🎓 Education (10 professions)
- 🏥 Healthcare & Medicine (25 professions)
- ⚖️ Law & Public Safety (11 professions)
- 🔬 Science & Engineering (17 professions)
- 🍽️ Service Industry (12 professions)
- 💻 Technology (12 professions)
- 🔧 Trades & Manual Labor (13 professions)
- 🚚 Transportation (10 professions)
- 📋 Miscellaneous (22 professions)

**Example Professions:**
- Software Developer, Web Developer, Data Scientist
- Accountant, Financial Advisor, Marketing Consultant
- Registered Nurse, Physician, Pharmacist
- Attorney, Paralegal, Mediator
- Content Writer, Graphic Designer, Social Media Manager
- And 170+ more...

### Creating Assistants from Templates

Navigate to **AI Assistants → Add New** to browse the visual profession grid:

1. **Browse by category** or search for a specific role
2. **Click "Create"** on any profession to open a customization modal
3. **Customize** the assistant name and AI settings (or use defaults)
4. **Deploy** your configured assistant instantly

Each profession template includes:
- Pre-written system prompts with role-specific expertise
- Curated tool selections appropriate for the profession
- Industry knowledge bases and best practices
- Recommended model settings for optimal performance

### 👥 Team Deployments

Teams group multiple professionals for coordinated workflows. Deploy an entire team of specialists with one click:

**Pre-Built Teams:**
- **Engineering Team** - Software, Mechanical, Electrical, Civil Engineers
- **Pharmaceutical Development Team** - Pharmacist, Researcher, Clinical Pharmacologist, Regulatory Affairs
- **Research & Data Science Team** - Data Scientist, Research Scientist, Statistician, Computer Scientist
- **Marketing & Growth Team** - Marketing Consultant, Content Creator, Graphic Designer, Business Consultant

**Team Features:**
- **Centralized configuration** - Set provider, model, and temperature for all team members
- **One-click deployment** - Creates all team member assistants simultaneously
- **Consistent settings** - Team defaults override individual profession defaults
- **Custom teams** - Create your own teams with any combination of professions

Navigate to **Teams → Add Team** to deploy a pre-configured team or create custom team combinations.

### 🧪 Backend Testing

Test assistants, professions, and teams directly from the WordPress admin **before** deploying to end users:

#### Test Assistant (Admin → AI Assistants → Test Assistant)

- **Full feature parity** with frontend chat interfaces
- **All tools enabled** including sensitive/restricted tools (admin-only)
- **File upload support** with complete MIME type configuration
- **Transcript saving** for debugging and analysis
- **Tool shortcuts** pre-loaded from assistant configuration
- **Streaming responses** with real-time feedback

#### Test Profession (Admin → Professions → Test Profession)

- **Preview profession templates** before creating assistants
- **Validate role descriptions** and expertise areas
- **Test default tool selections** in live conversations
- **Verify knowledge base** content and accuracy
- **Assess AI model performance** with profession-specific tasks

#### Test Team (Admin → Teams → Test Team)

- **Test entire teams** before deployment
- **Validate team member coordination** and role separation
- **Verify shared settings** propagate correctly
- **Multi-assistant conversations** to test team dynamics
- **Performance benchmarking** across team members

**Security Note:** All test pages require `manage_options` capability and are restricted to WordPress administrators. Sensitive tools are enabled in test environments because administrators already have full site access.

**Documentation:**
- [Test Assistant Feature Enhancements](docs/test-assistant-enhancements.md) - Complete testing capabilities guide
- [Dynamic Assistant Creation System](docs/archive/VISUAL_GUIDE_DYNAMIC_ASSISTANTS.md) - Visual guide to profession and team architecture

### Custom Professions & Teams

Administrators can create custom profession templates and teams:

**Create Custom Profession:**
1. Navigate to **Professions → Add New**
2. Set title, description, and category
3. Define expertise areas and role description
4. Select default tools from the registry
5. Add knowledge base content
6. Configure AI model defaults
7. Publish for use in assistant creation

**Create Custom Team:**
1. Navigate to **Teams → Add New**
2. Set team name and description
3. Select profession members from your library
4. Configure team-wide defaults (provider, model, temperature)
5. Publish to enable one-click team deployment

### Benefits

**For Organizations:**
- ✅ Rapid assistant deployment without manual configuration
- ✅ Consistent configurations across similar roles
- ✅ Template library grows with your organization
- ✅ Share profession templates across sites
- ✅ Professional-grade assistant quality out of the box

**For Administrators:**
- ✅ Test everything safely from the backend
- ✅ No coding required for template-based assistants
- ✅ Visual template selection interface
- ✅ Reusable configurations reduce errors
- ✅ Full control over custom templates

**For Developers:**
- ✅ JSON-based knowledge base system
- ✅ Extensible via filters and hooks
- ✅ WordPress standard CPT architecture
- ✅ REST API access for profession and team data
- ✅ Automated seeding from knowledge base files

---

## 🔑 Assistant API credentials

Administrators can issue per-assistant access tokens from the **API Credentials** meta box that appears on every assistant edit screen. Tokens are only available to users with the `manage_options` capability, surface the credential history in a table, and expose one-click revoke and delete actions for rapid cleanup.【F:includes/assistants/class-wp-mcp-ai-assistant-cpt.php†L483-L595】 When you click **Generate Credential** the plugin produces a single-use token in the form `cred_xxxxx.SECRET`, hashes the secret server-side, and records the issuer so you have an audit trail of who created each credential.【F:includes/class-wp-mcp-ai-credentials.php†L94-L135】

Remote integrations can authenticate by sending that token in the standard `Authorization: Bearer` header—no Auth0 dependency required. The REST layer validates the credential, emits structured errors when a token is revoked or malformed, and scopes the request to the assistant that issued the token so clients cannot hop between assistants without an explicit credential for each one.【F:includes/class-wp-mcp-ai-rest.php†L316-L444】【F:includes/class-wp-mcp-ai-rest.php†L1282-L1321】【F:includes/class-wp-mcp-ai-credentials.php†L242-L297】

---

## 🐳 Local Development with Docker

Spin up a disposable WordPress instance that mounts the plugin source directly into the container:

```bash
docker compose up -d
```

- WordPress will be available at [http://localhost:8000](http://localhost:8000).
- The plugin source in this repository is mounted to `/var/www/html/wp-content/plugins/mcp-ai-wpoos` inside the container, so edits on your machine are reflected immediately.
- The MySQL service is provisioned with the `wordpress` database, user, and password (`wordpress` / `wordpress`).

Visit the site in your browser to complete the standard WordPress installation flow, using the database credentials above when prompted. When you're finished developing, stop the stack with `docker compose down`.

### 🔁 Codex environment startup script

If you are working inside an OpenAI Codex environment, add `bin/codex-startup.sh` to your workspace start-up tasks so a fresh WordPress install is provisioned automatically for every session — no Docker required.

```bash
bin/codex-startup.sh
```

The script performs the following steps:

- Downloads WP-CLI locally (if necessary) and uses it to fetch the latest WordPress core files into `.codex-wordpress/wordpress`.
- Installs the [SQLite Database Integration](https://wordpress.org/plugins/sqlite-database-integration/) plugin so WordPress can run without a MySQL server.
- Symlinks this repository into the new install's `wp-content/plugins/mcp-ai-wpoos` directory.
- Installs Composer development dependencies (when available) and provisions the WordPress test suite so `composer run test` works immediately.
- Runs `wp core install`, activates the **WP oOS** plugin, enables pretty permalinks, and sets a default site tagline.
- Boots a development server on port `8000` via `wp server` and logs output to `.codex-wordpress/wp-server.log`.

Default credentials:

| Setting | Value |
| --- | --- |
| Site URL | `http://localhost:8000` |
| Admin user | `admin` |
| Admin password | `password` |
| Admin email | `admin@example.com` |

Override any of these values by exporting the environment variables `WORDPRESS_URL`, `WORDPRESS_TITLE`, `WORDPRESS_ADMIN_USER`, `WORDPRESS_ADMIN_PASSWORD`, `WORDPRESS_ADMIN_EMAIL`, or `WORDPRESS_PORT` before running the script.

---

## 🧑‍💻 Development Tooling

Install the PHP development dependencies (including PHP_CodeSniffer, the WordPress Coding Standards ruleset, and PHPUnit) with:

```bash
bin/setup-dev.sh
```

The script runs `composer install` and makes the following Composer scripts available:

| Purpose | Command |
| --- | --- |
| WordPress coding standards lint | `composer run lint` |
| PHP compatibility checks (PHP 7.4–8.3) | `composer run lint:compat` |
| Auto-fix coding standards violations | `composer run format` |
| Generate the translation template | `composer run pot` |
| Install the WordPress unit test scaffolding | `composer run test:install` |
| Execute the PHPUnit suite | `composer run test` |

These commands automatically resolve the bundled `vendor/bin` tools (such as `phpcs`, `phpcbf`, and `phpunit`), so a global installation is no longer required.

> [!NOTE]
> The `test:install` script prefers the Composer-provided `wp-phpunit/wp-phpunit` package for the WordPress test suite. Run `composer install` before invoking it, especially on networks where `develop.svn.wordpress.org` is inaccessible.

---

## 🧪 Testing & QA


- `composer run test` executes the PHPUnit suite bundled with `wp-phpunit/wp-phpunit` and Yoast’s polyfills, covering REST, tooling, and helper contracts.【F:composer.json†L16-L23】
- Run `composer run test:install` once per environment to provision the WordPress test scaffolding before the first test pass.【F:composer.json†L16-L23】
- For offline or air-gapped environments, use `./bin/package-vendor-dev.sh` to create a downloadable test framework package (~140 MB), then `./bin/install-vendor-dev.sh` to deploy it without requiring composer or internet access.

### Coding standards & static analysis
- Enforce the WordPress Coding Standards with `composer run lint`; auto-fix what you can with `composer run format`.【F:composer.json†L16-L23】
- Validate cross-version compatibility (PHP 7.4–8.3) via `composer run lint:compat` prior to release builds.【F:composer.json†L16-L23】

### Manual smoke tests
- Follow the scenarios in [## ✅ Manual QA Scenarios](#-manual-qa-scenarios) after significant changes to chat flows, tool execution, or authentication wiring.
- For logging-centric debugging, enable logging in the WP oOS settings and reference the retrieval commands in [🪵 Logging](#-logging).

---

## 💬 Frontend Shortcode
Embed a published assistant anywhere on the site with the shortcode. Replace `123` with the post ID of the assistant you created under **AI Assistants**.

```html
[mcp_ai_chat assistant="123"]
```

### How it works
- The shortcode renders a lightweight chat UI that talks to the plugin's REST API endpoints.
- Scripts and styles are enqueued automatically and include REST nonces plus the selected assistant ID.
- Responses are displayed inline, including tool invocation feedback when the model requests a registered tool.

### Requirements
- The assistant post must be **published** and, by default, the current user must have the `edit_posts` capability (matching the REST permission check). Add `allow_guests="true"` to the shortcode when you want anonymous visitors to participate in the chat.
- An OpenAI API key and default model must be configured in **Settings → WP oOS**.

### Tips
- Omit the `assistant` attribute to fall back to the default assistant configured in the settings screen.
- Multiple shortcodes can be added to the same page; each chat instance maintains its own conversation context on the client.
- Use `allow_guests="true"` to expose the chat UI to logged-out visitors. Each render issues a short-lived guest token that authorises REST requests without a WordPress login.
- REST interactions rely on the `[wp_rest]` nonce, so caching plugins should avoid caching pages for logged-in editors running the chat.

### Elementor widget
- Elementor sites automatically gain an **WP oOS Chat** widget that mirrors the shortcode controls, including the optional assistant selector and the guest access toggle.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L17-L109】
- Leaving the assistant control blank falls back to the default assistant configured in the plugin settings, and enabling **Allow Guests** injects the same temporary tokens used by the shortcode flow.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L45-L110】【F:includes/class-wp-mcp-ai-shortcode.php†L132-L224】
- The Elementor chat widget can surface everything saved on the assistant post—model defaults, knowledge files, prompt shortcuts, and assigned tools—so you can build documentation and dashboards without copying values manually.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L95-L845】

---

## 🧵 REST Chat Payloads & Attachments

The `/wp-json/mcp-ai/v1/chat` endpoint accepts rich, multi-part messages. Each message object still requires a `role`, but the
`content` may now be either a plain string or an array of structured segments that map to OpenAI's multimodal contract.

```json
{
  "assistant_id": 123,
  "messages": [
    {
      "role": "user",
      "content": [
        { "type": "text", "text": "Describe this photo" },
        { "type": "input_image", "attachment_id": 456, "detail": "high" }
      ]
    }
  ],
  "options": {
    "response_format": { "type": "json_schema", "json_schema": { "name": "caption" } }
  }
}
```

### Supported segment types

- `text` – Free-form text (`text` property). Strings supplied directly to `content` are automatically wrapped in this format. For backwards compatibility, existing `input_text` payloads sent to the REST API are still accepted and normalised to the new schema.
- `input_image` – Reference an uploaded WordPress attachment (`attachment_id`) or provide a remote `url`. Optional `detail`
  hints (`low`, `auto`, `high`) and `caption` fields are preserved. *(Fixed in v1.0.0: Chat client attachments now properly processed)*
- `input_file` – Reference an uploaded attachment that should be streamed to the model. *(Fixed in v1.0.0: Chat client file attachments now properly processed)*

The REST controller validates attachment ownership/permissions, enforces a default 5 MB size cap (filterable via
`wp_mcp_ai_max_attachment_bytes`), and only allows safe MIME types by default. Text and structured data formats include
Markdown, CSV/TSV, HTML, JSON/JSONL/NDJSON, and XML; binary documents cover PDFs and Microsoft Word/PowerPoint/Excel variants;
and audio/video uploads accept AAC/FLAC/M4A/MP3/OGG/OPUS/WAV/WEBM plus MP4 or QuickTime sources. 【F:includes/class-wp-mcp-ai-message-attachments.php†L642-L709】

Whenever attachments are present, the plugin automatically inlines the asset data when sending requests to OpenAI's Responses API.
Image segments are converted to data URLs and file segments include the base64-encoded payload alongside the original filename,
so integrators do not need to upload assets manually before invoking a model.

REST requests that include attachments automatically gain access to the bundled **Submit Document Prompt** tool so the files reach OpenAI even when the assistant has the tool disabled in its configuration.【F:includes/class-wp-mcp-ai-rest.php†L22-L29】【F:includes/class-wp-mcp-ai-rest.php†L963-L991】

Assistant memory files configured on the post (`memory_files`) are also promoted to structured `text` segments on the
system channel, retaining the existing chunking/truncation safeguards.

Need to relax or tighten the allowed file types? Administrators can override the image and file MIME lists directly in **Settings → WP oOS → Attachments**, and the same values are used by shortcode-driven chat surfaces (including the Elementor widget) when building upload restrictions.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L225-L267】【F:includes/class-wp-mcp-ai-message-attachments.php†L456-L565】【F:includes/class-wp-mcp-ai-shortcode.php†L197-L218】 When JSON Lines support is enabled in the allowlist the plugin also registers `.jsonl` and `.ndjson` extensions with WordPress so uploads succeed without additional filters.【F:mcp-ai-wpoos.php†L236-L272】

Assistants can also query existing knowledge files with the **Search Attachments** tool, which reuses `WP_MCP_AI_Message_Attachments::user_can_access_attachment()` so only publicly accessible or user-owned media is returned alongside download URLs and file metadata for the model to reuse.【F:includes/tools/class-wp-mcp-ai-tool-search-attachments.php†L15-L207】【F:includes/class-wp-mcp-ai-message-attachments.php†L480-L575】

---

## 🔐 JetEngine Capability Reference

When the plugin interacts with JetEngine objects it defers to the capabilities enforced by JetEngine’s own REST handlers and editor interfaces. Use the following table to review the specific capability checks that gate each object type:

| Object / Context | Capability string(s) | Notes |
| --- | --- | --- |
| Custom Post Type editor & REST endpoints | `manage_options` | Editing built-in post types and all CPT REST endpoints require the user to have `manage_options`. |
| Custom Taxonomy editor & REST endpoints | `manage_options` | Built-in taxonomy edits and every taxonomy REST endpoint enforce the `manage_options` capability. |
| Relation management UI & REST endpoints | `manage_options` | Creating, editing, listing, or deleting relations through the admin REST handlers requires `manage_options`. |
| Relation REST access settings (`rest_get_access`, `rest_post_access`) | Stored capability string or `'public'` (default `manage_options`) | The public REST controller checks a capability stored in relation args; if blank or `'public'` the request is allowed, otherwise `current_user_can( $cap )` is enforced. Newly created relations default `rest_post_access` to `manage_options` in the editor UI. |
| Relation object type “Posts” | `edit_post`, `delete_post` | Editing or deleting related post items requires the corresponding post capability for the specific post ID. |
| Relation object type “Taxonomy Terms” | `edit_term`, `delete_term` | Term relations check the matching term capabilities for the targeted term ID. |
| Relation object type “Mix → Users” | `edit_users` (for edits); deletion disallowed | Editing user relations needs `edit_users`; deletions are explicitly forbidden (returns `false`). Other mix objects defer to filters for capability checks. |
| Relation object type “Custom Content Types (CCT)” | Configured capability (defaults to `manage_options`) | Relation checks defer to the CCT’s `user_has_access()`, which in turn checks `current_user_can( $this->user_cap() )`; the capability defaults to `manage_options` unless overridden in the CCT settings or filters. |



---

## 🛰 JetEngine REST API Reference

- 📄 Review the full endpoint catalogue in [`docs/jet-engine-rest-routes.md`](docs/jet-engine-rest-routes.md) for route paths, callbacks, and required parameters.
- 🤖 When JetEngine is active, assistants can invoke the **List JetEngine REST Routes** tool to retrieve the same metadata directly inside a conversation (requires a user with the `manage_options` capability).

---

## 🪵 Logging

- Enable or disable logging from **Settings → WP oOS → Enable Logging**.
- When logging is enabled the plugin records:
  - Chat requests and responses processed by the REST API.
  - Tool executions (including permission denials).
  - Errors returned from the OpenAI API and internal validation.
- Log entries are written via PHP's `error_log()` and can be filtered with `wp_mcp_ai_log_entry` to route them elsewhere.【F:includes/class-wp-mcp-ai-logger.php†L16-L137】
- Recent errors and activity snapshots are also persisted in the `wp_mcp_ai_recent_errors` (50 entries) and `wp_mcp_ai_recent_activity` (100 entries) options for dashboards and widgets, keeping autoload disabled to avoid bloating frontend requests.【F:includes/class-wp-mcp-ai-logger.php†L611-L662】
- Retrieve those rolling buffers quickly with WP-CLI when debugging production incidents:
  ```bash
  wp option get wp_mcp_ai_recent_errors --format=json
  wp option get wp_mcp_ai_recent_activity --format=json
  ```

---

## 🧾 JetEngine REST Endpoint Report Helper

Use the JetEngine report helper to surface the CRUD coverage matrix that was compiled during the REST endpoint audit. The helper exposes the underlying endpoint metadata as a structured array so you can reuse it in documentation, dashboards, or custom checks.

```php
$report = wp_mcp_ai_get_jetengine_endpoint_report();

foreach ( $report['coverage'] as $resource => $operations ) {
    printf( "%s supports: %s\n", ucfirst( $resource ), implode( ', ', array_keys( array_filter( $operations ) ) ) );
}

if ( empty( $report['missing'] ) ) {
    echo "All CRUD operations are covered.";
}
```

The helper is filterable via:

- `wp_mcp_ai_jetengine_endpoint_routes` – Adjust the source routes before the coverage matrix is derived.
- `wp_mcp_ai_jetengine_endpoint_coverage` – Modify the generated CRUD coverage.
- `wp_mcp_ai_jetengine_missing_operations` – Override the derived list of missing operations per resource.

Each filter receives the full data set so you can extend or replace the output when JetEngine adds new endpoints or when your project needs to surface additional metadata.

---

## 🔌 Optional Tools & Dependencies

**WP oOS works perfectly with vanilla WordPress** - you don't need any third-party plugins for core functionality.

However, certain features require third-party plugins (sold separately). The plugin automatically detects which plugins are active and enables the corresponding tools:

### Plugin Detection & Tool Loading

- **JetEngine** (5 tools) – Server-side chat transcripts, JetEngine content access, JetFormBuilder integration
- **WooCommerce** (3 tools) – E-commerce automation, product/order management
- **Elementor** (1 tool + widgets) – Template management, pre-built chat widgets
- **Rank Math SEO** (1 tool) – SEO analysis and schema data access
- **WPCode** (1 tool) – Code snippet management and automation

**📖 See the complete breakdown:** [🔌 What You Lose Without Third-Party Plugins](#what-you-lose-without-third-party-plugins)

### How It Works

1. Each tool description in the admin UI shows which plugin it requires
2. Tools are automatically hidden when their dependency is missing
3. Administrators see informational notices explaining unavailable tools
4. No errors occur - the plugin gracefully handles missing dependencies

---

## ✅ Manual QA Scenarios

The project currently relies on manual verification. Run these checks after updating the plugin:

1. **Baseline (no optional plugins)**
   - Deactivate WooCommerce and JetEngine.
   - Load the AI Assistant edit screen and confirm only core tools appear. No PHP notices or fatal errors should occur.
   - Visit the WordPress dashboard to confirm the informational notices explain why optional tools are disabled.
2. **WooCommerce enabled**
   - Activate WooCommerce.
   - Reload the Assistant editor and ensure the WooCommerce Orders and Products tools appear and can be selected.
   - Trigger each tool (e.g., via an assistant conversation) and confirm recent orders and product summaries return without errors.
3. **JetEngine enabled**
   - Activate JetEngine.
   - Confirm the JetEngine Items tool appears for assistants and returns data for a configured JetEngine post type.
4. **Tool call retry resilience**
   - Initiate a chat conversation that triggers a tool call (for example, request an operation that requires either WooCommerce tool).
   - After the tool output appears, send a follow-up message that prompts the assistant to continue without invoking another tool.
   - Confirm the follow-up succeeds without a JavaScript console error referencing a missing `tool_call_id`.

Document the results of each scenario when preparing releases to ensure optional integrations remain stable.

---

## 🧩 Hooks & Filters

Use the following hooks to extend the plugin:

| Hook | Type | Description |
| --- | --- | --- |
| `do_action( 'wp_mcp_ai_before_chat_request', $assistant_id, $messages, $options, $request )` | Action | Fires before a chat request is sent to OpenAI. |
| `do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request )` | Action | Fires after a chat response is received. |
| `apply_filters( 'wp_mcp_ai_chat_options', $options, $assistant_config, $request )` | Filter | Modify the OpenAI request options before dispatch. |
| `apply_filters( 'wp_mcp_ai_chat_capability', $capability, $assistant_id, $context )` | Filter | Adjust the capability required to use the chat shortcode and REST endpoints (defaults to `edit_posts`). Return `'public'` or an empty value to allow any visitor. |
| `do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $arguments, $context )` | Action | Runs immediately before a tool executes. |
| `apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $arguments, $context )` | Filter | Inspect or transform tool output before it is returned. |
| `do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result )` | Action | Runs after a tool completes execution. |
| `apply_filters( 'wp_mcp_ai_log_entry', $entry, $type, $message, $context )` | Filter | Intercept or redirect logging output. |

---

## 🧰 WP-CLI Commands

Manage the WP oOS environment from the command line when WP-CLI is available.

| Command | Description |
| --- | --- |
| `wp mcp-ai status` | Summarises WordPress core details, PHP version, and WP oOS supported plugin coverage. |
| `wp mcp-ai remote <base>` | Probes a remote MCP REST namespace (such as `https://example.com/wp-json/mcp-ai/v1`) by loading the assistant directory and issuing a lightweight `POST /chat` probe, reporting connectivity, assistant counts, and token scope metadata. |
| `wp mcp-ai plugins list` | Lists optional dependencies (WooCommerce, JetEngine, etc.) with install and activation state. |
| `wp mcp-ai plugins activate <slug>` | Activates a supported plugin; pass `--network` on multisite installations. |
| `wp mcp-ai plugins deactivate <slug>` | Deactivates a supported plugin; pass `--network` on multisite installations. |

`wp mcp-ai remote` accepts additional flags so you can mirror the authentication mode used by your deployment while exercising TLS and timeout controls:

- `--token=<token>` – Include an Auth0 access token or assistant-issued credential via the `Authorization` header.
- `--guest-token=<token>` – Attach a guest token when testing public chat surfaces that rely on the `X-WP-MCP-AI-Guest` header.
- `--nonce=<nonce>` – Supply a WordPress REST nonce for same-origin checks.
- `--assistant-id=<id>` – Hint which assistant to load when the directory endpoint supports scoped tokens.
- `--timeout=<seconds>` – Override the default 15-second timeout when probing slow networks.
- `--verify-ssl=<boolean>` – Toggle certificate validation (defaults to `true`).
- `--user-agent=<agent>` – Send a custom user agent instead of the built-in `WP-MCP-AI-Remote-Tester/<version>` signature.

Filter `wp_mcp_ai_supported_plugins` to expose additional managed dependencies to the CLI helpers.

Each hook receives sanitized data and respects the current user's permissions and multisite membership.

---

## 🆘 Getting Help & Support

### Documentation Resources

Start with the comprehensive documentation before seeking additional support:

1. **[Quick Reference Guide](docs/QUICK_REFERENCE.md)** - Fast answers to common questions and tasks
2. **[Documentation Index](docs/DOCUMENTATION_INDEX.md)** - Navigate all 32 documentation files
3. **[Troubleshooting Guide](docs/deployment-troubleshooting.md)** - Solutions to common issues
4. **[REST API Reference](docs/rest-api.md)** - Complete API documentation

### Before Reporting Issues

When encountering problems, please:

- [ ] Check the [troubleshooting guide](docs/deployment-troubleshooting.md)
- [ ] Enable logging in Settings → WP oOS to capture detailed errors
- [ ] Review the [common issues section](#-common-issues) below
- [ ] Search [existing GitHub issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- [ ] Test with a default assistant to isolate configuration issues

### Common Issues

#### npm/Composer Install Error After Cloning
If you get `ENOENT: no such file or directory, uv_cwd` (npm) or `getcwd() failed` (composer) errors:

**For Cloudways Users (Most Common):**

These errors occur when you try to run npm or composer from a directory that has been moved, deleted, or no longer exists. This commonly happens when you clone outside the WordPress plugins directory and then move/copy files while your shell session is still in the original location.

**Solution: Always clone directly into the plugins directory:**

```bash
# SSH into your Cloudways server
cd /home/master/applications/YOURAPP/public_html/wp-content/plugins/

# Clone directly (replace YOURAPP with your application name)
git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos

# Verify you're in the right place
pwd  # Should show the full plugins path

# Install dependencies
npm install && composer install --no-dev --optimize-autoloader
```

**For Local Development or VPS:**

1. **Ensure you're in the correct directory** - Run `pwd` to verify you're in the `mcp-ai-wpoos` directory
2. **Check package.json and composer.json exist** - Run `ls -la package.json composer.json` to confirm files are present
3. **Do not run commands from a moved/deleted directory** - If you moved files, open a new terminal session in the new location
4. **Follow the correct workflow**:
   ```bash
   # Clone the repository
   git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
   cd mcp-ai-wpoos
   
   # Install dependencies BEFORE moving/copying
   npm install
   composer install --no-dev --optimize-autoloader
   
   # THEN copy to WordPress plugins directory
   cp -r . /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/
   ```

5. **Alternative: Clone directly into WordPress** - This avoids copy/move issues:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   git clone https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
   cd mcp-ai-wpoos
   npm install && composer install --no-dev --optimize-autoloader
   ```

#### Chat Not Working
1. Verify OpenAI API key is configured in Settings → WP oOS
2. Ensure assistant is published
3. Check user has `edit_posts` capability or add `allow_guests="true"` to shortcode
4. Enable logging and check browser console for errors

#### Tool Execution Failures
1. Verify tool is enabled for the assistant
2. Check required dependencies are installed (WooCommerce, JetEngine, etc.)
3. Ensure user has necessary capabilities
4. Review tool-specific requirements in [tool reference](docs/tool-reference.md)

#### Remote Client Connection Issues
1. Verify credentials are correct and not expired
2. Test with [remote client quickstart guide](docs/remote-client-quickstart.md)
3. Use WP-CLI command: `wp mcp-ai remote <url> --token=<token>`
4. Review [authentication documentation](docs/mcp-server-authentication.md)

### Reporting Issues

When creating a GitHub issue, please include:

- **Plugin version** (found in WordPress admin)
- **WordPress version** and PHP version
- **Error messages** from logs (enable logging in settings)
- **Steps to reproduce** the issue
- **Expected behavior** vs actual behavior
- **Screenshots** if applicable

Create issues at: https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues

### Contributing

We welcome contributions! Please see:

- [CONTRIBUTING.md](CONTRIBUTING.md) - Contribution guidelines
- [CODE-REVIEW-MASTER.md](docs/CODE-REVIEW-MASTER.md) - Code quality standards (96/100 score)
- [CONSOLIDATED_BUGS_AND_FIXES.md](docs/CONSOLIDATED_BUGS_AND_FIXES.md) - All bugs, fixes, and improvements
- [CONSOLIDATED_SESSION_SUMMARIES.md](docs/CONSOLIDATED_SESSION_SUMMARIES.md) - Complete development history
- [ACTION_ITEMS.md](docs/ACTION_ITEMS.md) - Current development priorities

### Documentation

Comprehensive documentation is available:

- **[DOCUMENTATION_INDEX.md](docs/DOCUMENTATION_INDEX.md)** - Complete documentation index
- **[CONSOLIDATED_BUGS_AND_FIXES.md](docs/CONSOLIDATED_BUGS_AND_FIXES.md)** - Master bugs and fixes report
- **[CONSOLIDATED_SESSION_SUMMARIES.md](docs/CONSOLIDATED_SESSION_SUMMARIES.md)** - Master session summaries
- **[CODE-REVIEW-MASTER.md](docs/CODE-REVIEW-MASTER.md)** - Master code review (96/100)
- **[TESTING_AND_QUALITY_REPORT.md](docs/TESTING_AND_QUALITY_REPORT.md)** - Testing & quality analysis

### Security Vulnerabilities

For security issues, please review our [Security Policy](SECURITY.md) and report vulnerabilities responsibly.

**Do not** create public GitHub issues for security vulnerabilities.

### Community & Updates

- **GitHub Repository:** https://github.com/nvdigitalsolutions/mcp-ai-wpoos
- **Maintained by:** [NV Digital Solutions](https://nvdigitalsolutions.com/)
- **License:** GPLv3 or later

---

## 📄 License

This plugin is licensed under the GNU General Public License v3.0 or later.

See [LICENSE](LICENSE) for full text.

---

**Thank you for using Open Operator System!**

For the latest updates, documentation, and support, visit the [GitHub repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos).
