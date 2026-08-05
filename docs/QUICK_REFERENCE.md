# NV oOS Quick Reference Guide

**Version:** 1.1.45
**Last Updated:** August 5, 2026

This quick reference provides fast access to the most common tasks and commands for Open Operator System.

## Recent Updates (August 2026)

- **v1.1.42** (July 29): Security infrastructure (7 classes: Request Guard, Security Posture with 21 signals, Destructive Ops Gate, URL Guard, Concurrency Guard, Cost Tracker, API Key Store). Site Health checks. Production hardening guide. CORS/rate limiting/error verbosity/body size enforcement with dashboard posture signals. nvoos/core framework-agnostic engine (32 contracts + 21 WP adapters, 109 tools migrated, 5 parity gaps closed). Status page & incident communication (Pro) with 4 AI tools. 21 coding-time agent skills + 6 BMAD agent definitions. Algorave addon (9 tools). Critical bug fixes (request guard param order, nonce query param auth). 13 new security unit tests. Addon count: 27.
- **v1.1.43** (August 1): MCP 2026-07-28 stateless core upgrade. Security v1.1.43 hardening (SSRF/CSRF/SQL/XSS across 16 files). OKF v0.2 trust-signal support (recursive descent parser, trust tiers, new validation tool). ICP System (Pro CRM Phase G, 7-dimension scoring). Pro Module Registry PSR-4. Hexagonal architecture purity (PlatformFlushInterface). 7 playbook/profession sync fixes. Phase 3 operational security hardening. WPCS 3.4.1 (CVE-2026-45293). Addon count: 27. Knowledge base: 311 professions.
- **v1.1.45** (August 5): Self-hosted OCR (Unlimited-OCR + DeepSeek-OCR) — 17 files, +4,087 lines. New unified vLLM client, Pro tools (`pro_unlimited_ocr`, `pro_batch_ocr`), structured extraction service, Embedded OCR backend + health dashboard, admin settings UI. Embedded addon v0.2.0 (voice, OpenMed, MCP abilities). AI transparency & SGI compliance. Comic Reader v0.2.0. Graphify standalone plugins v1.0.1. Build/release automation.
- **v1.1.44** (August 4): CCT stability (mutex lock, FlowHub guard, base-plugin fatal w/out lib/core, Veo async context). API key fixes (Gemini video + Veo fallback). Proposal 016 architecture hardening (277 autoload optimizations, phpcs sweep, 8 findings). Proposal 017 polling/queue/load-balancing (12 weaknesses). Deferred security items #5755. npm security: undici >=8.10.0, fast-uri >=3.1.4, ip-address >=10.4.0 across 11 pkg. Docs: FOR_REVIEWERS v1.1.43 (~1,500 tools), 16 broken links fixed, Graphify ecosystem audit.
- **v1.1.40** (July 15): Content Format Awareness helper (Markdown/HTML/plain text detection). Research to Paper Store to WordPress Draft pipeline with new `create_post_from_research` tool. Settings credential split (two-option isolation with transparent merge). Demo video pipeline complete (Phases 0-5). Kimi & DeepSeek client parity. Model catalog update (24 files, July 2026 defaults). OOS Engine SchemaStoreInterface + 45 tests. SSE HTTP/2 fixes (`ob_clean`, 524 timeout). Vector store sync no polling. Settings import/export batch fixes (4 PRs). **Phase 8 MCP servers: 33 total (4 new — Pro Scheduler, FlowHub, Shopify Sync, EZuite). OAuth 2.0 MCP authentication (PKCE, hierarchical scopes, token management UI, browser-based login). Per-toolkit MCP settings slug fix.** Validated tool slug allowlist fix.
- **v1.1.41** (July 22): OKF Integration (Open Knowledge Format v0.1 engine + 6 MCP tools, 41 bundled skills OKF-conformant). Security compliance (11 HIGH/P0 fixes: HMAC policy tokens, health auth-gating, ZIP validation, CSRF nonces, SRI hashes). Playbook sync fixes (duplicate AJAX handler resolved, silent failures reported, CPT class guards). Model provider credential resolution (all 4 key sources). Dependency bumps (adm-zip, axios, brace-expansion — 18 alerts, 0 audit vulns).
- **v1.1.39** (July 13): Meta-Harness auto-optimization system (all 7 phases). Agent delegation rework (inline execution, REST dispatch, cron resilience, spawn_cron, name-based resolution). Pro SPA v2 polish (20+ PRs: vector store/autocomplete fixes, cost badges, allowSensitiveTools, tool result rendering, auto-save transcripts, attachments/save/storage, tasks drawer toolbar, speech/audio, capability flags, usage badges, sidebar, media, system prompt, layout). Tool presets refactor (essentials layers, auto-upgrade, SSE fix, tool_call_id fallback). CRM fixes (cache loop, Upwork rate limiting, freelance sourcing). Infrastructure (Veo 2.0 to Gemini Omni Flash with deprecation detection, workflow auth, ZAP scan, npm rebuild).
- **v1.1.38** (July 10): Page Agent addon v0.1.0 (AI browser page control copilot). Pro SPA v2 major parity update (voice pipeline, tasks drawer, workflow tracker, file attachments, tool shortcuts, slash commands, mobile hamburger, autoscroll/viewport fixes, cache-busting, assistant preloading). Per-user chat memory toggle. create_post/save_post Markdown-to-HTML conversion + smart taxonomy suggestions. Workflow blueprint existing-content awareness. SPA accessibility: annotation pills.
- **v1.1.37** (July 8): JetEngine Meta Helper universal (25 CPTs, REST, ECA), Places enrichment tools, RabbitMQ + queue infrastructure, Multi-tenant DB isolation Phase 0–4, DSpark admin UI + orchestration, Crocoblock DS addon, Test coverage: 329 tools/28 toolkits, Docs Hub broken link engine, OWASP ZAP DAST, 30+ bug fixes.
- **v1.1.36** (July 4): EZuite Inventory Sync Pro Toolkit, Ralph Loop CCT migration + circuit breaker, JetBooking/JetAppointment (8 tools), Moonshot AI (Kimi) & Z.AI (GLM) → DeepSeek parity, unified sync log manager, tool presets auto-select + chips bar, HTTrack cache + Place-to-Service bridge, Generate Default Mapping + read-only sync, 429 web search retry, 45+ bug fixes across CCT/sync/tools/infrastructure.
- **v1.1.35** (June 29): FlowHub Inventory Sync Pro Toolkit (6 tools), Shopify Sync Pro Toolkit (5 tools), Necessity Gate Layer J (irreversibility-weighted safety), Local Voice Embedded STT (3 backends, offline-first), Remote Site Administrator blueprint (22 tools), Places & Calendar bulk import, CLI site-import subcommand, voice realtime auto-detect, 7 bug fixes.
- **v1.1.34** (June 27): GPT-Realtime-2 voice models with WebRTC transport + Translate/Whisper clients + reasoning. Multi-channel result delivery UI (Telegram, Discord, WhatsApp, Google Chat). Pro scheduler AI/workflow response delivery. Graphify ecosystem: remote drivers, WP 7.0 Connectors, wp.org compliance. 3 reasoning-tool fatal bugs fixed. CRM deal import, multi-source auto-import, Upwork/LinkedIn toggle. Docs Hub REST + settings sync fixes. CVE-2026-55602, Gemini cache fix, GPT image routing fix. FastAPI porting plan.
- **v1.1.29**

- **v1.1.29** (June 12): **Pro Toolkit Optimizations Phase 1–3** across 6 toolkits; **Chat Transcript & Agent Memory Retention**; **DietPi Pro Toolkit** (19+ tools, MCP server, SSH proxy); **Layer I Guardrails** (jailbreak prevention); **Context Window Management** (13-provider validation, tiktoken, token capping); **LibreChat Addon**; **Schedule Anything SaaS**; **Vector Search** (HNSW, hybrid); **CRM Enhancements** (email import, lead pruning, inline tags, duplicates); **25+ bug fixes**. Full docs: [`docs/features/`](features/).
- **v1.1.28** (June 8): CRM Phase C (IMAP/Twilio/WhatsApp/Gmail inbound), Customer CPT + 360, Support Ticket Module (10 tools + SLA), TF-IDF + BM25, Attention Routing (QKV 5-head), Funiq Bridge, NVOOS Graphify.
- **v1.1.27** (June 5): Real-Time SSE Streaming, 35 OOS Core Tools, Extended Cognition Vision, JFB fixes, Graphify compliance, June 2026 model pricing.
- **v1.1.26** (June 3): Cross-Platform Extraction Engine, Site-Builder Pipeline, SPA a11y Hardening, Cloudways Dashboard SPA.
- **v1.1.25** (May 31): Unified Blueprint System (55 blueprints), Cloudways Toolkit (60 tools), CRM Toolkit A–E (70+ tools), Chat UI Enhancements.

### Previous Updates (April 2026)

- **Harmonization Sub-Toolkit** 🎨 — 14 new Pro tools under `addons/pro/includes/tools/image-production/harmonization/` that complement the end-to-end `product_actualization` tool with composable AI-compositing primitives (color harmonization, relighting, shadow synthesis, reflection, boundary refinement, AI-assisted background generation, outpainting, placement suggestion, lighting analysis, and an end-to-end orchestrator). See [`docs/harmonization-architecture.md`](harmonization-architecture.md). Example LLM prompts:
  - *"Place this product photo on an AI-generated kitchen counter."*
  - *"Drop the attached subject onto this uploaded background, lower-center, with a soft contact shadow."*
  - *"Rebuild this catalog page with consistent harmonization across all eight products."*
- **April 2026 Security Audit Summary** 🛡️ (v1.1.10) — New [`SECURITY_AUDIT_2026_04.md`](operations/compliance/SECURITY_AUDIT_2026_04.md) consolidates the nine deliverables under [`audits/2026-04/`](project/audits/2026-04/). No Critical findings; 5 High (3 Fixed, 2 Partially Fixed); 14 Medium (all Fixed); 21 Low (14 closed); 10 Informational; 50 total. Standards: WP Plugin Handbook, WP.org Plugin Directory Guidelines, OWASP Top 10 / API Top 10, WPCS 3.3, PHPCompatibilityWP, GDPR/CCPA, MCP/SSE.
- **Production-Ready Vendor Autoload** (v1.1.10) — `vendor/` regenerated with `composer install --no-dev --classmap-authoritative`; plugin is deployable from a clean clone (PR #4733).
- **Veo 3.1 `generate_veo_video` Fix** (v1.1.10) — `seed` parameter now sent only to Veo 2.0 (`veo-2.0-generate-001`); Veo 3.1 (`veo-3.1-generate-preview`) rejects it (PR #4735).
- **Measurement Subsystem GA** ⭐ (v1.1.9) — 12 sequenced PRs delivered the full measurement / evals / reward stack: stock metrics for tool-execution, chat-loop, agentic-loop, and SSE; persistent `{prefix}mcp_ai_metric_events` table with retention cron (`wp_mcp_ai_metric_retention_days`, default 30 days); eval harness with verifier-independence enforcement; Pro rubric presets (`prompt_adherence`, `json_schema`, `citation_presence`) and counterfactual runner; OTel JSON exporter; Measurement dashboard under **Tools → Measurement** with time-range + sparkline; `wp mcp-ai measurement run|alert-check|list-runs` WP-CLI runner with regression-aware exit codes. See [`docs/measurement/README.md`](measurement/README.md).
- **PHPUnit 11 Upgrade (CVE Fix)** 🔒 (v1.1.9) — PHPUnit upgraded to 11.x with WordPress-compatibility patches to resolve the argument-injection vulnerability **GHSA-qrr6-mg7r-m243**. CI PHP bumped 8.1 → 8.2.
- **Chart.js Handle Normalization** (v1.1.9) — All admin dashboards now enqueue a single `wp-mcp-ai-chartjs` handle to eliminate duplicate registrations and version drift.
- **Graphify Knowledge Graph Addon v0.5.0** (v1.1.9) — Optional WordPress Knowledge Graph addon restored under `addons/graphify/`.
- **Orchestration Reference Doc** (v1.1.9) — New [`docs/ORCHESTRATION_REFERENCE.md`](ORCHESTRATION_REFERENCE.md) documents every workflow preset, resource preset, the PSO algorithm, and all orchestration hooks / filters / storage keys in one place.
- **Erlang C Queuing Theory Tools** (v1.1.8) – 4 workforce-management tools built on the Erlang C formula. `calculate_erlang_c` (general staffing solver), `erlang_c_concurrency_advisor` (AI session tuning), `erlang_c_staffing_advisor` (multi-channel with bot-deflection and WFM endpoint), `erlang_c_queue_health` (real-time SLA monitoring with `wp_mcp_ai_queue_alert` action hook). All four ship in the base plugin with no external dependencies. See [`docs/features/erlang-c-staffing-tools.md`](features/erlang-c-staffing-tools.md).
- **tool-reference.md fully updated** – historical April audit superseded by current ~830-tool framing; use `WP_MCP_AI_Tool_Registry::get_tools()` for live counts. Added 14 new sections covering: OpenAI file/model management, text embeddings & vector stores, multi-agent orchestration, agent memory management, reasoning & code analysis, deep research, browser-native AI (client-side NLP), Yahoo Fantasy Football toolkit, Newsletter plugin integration, WP All Import/Export integration, Flowhub cannabis dispensary, PayHere payment gateway, and Erlang C queue tools.
- **MCP Protocol Completion** ⭐ (v1.1.7) – Full MCP 2024-11-05 spec compliance: `resources/read`, `prompts/get`, `ping`, `completion/complete`, `logging/setLevel`, `notifications/cancelled`, JSON-RPC batching (up to 20 messages), tool annotations, `Mcp-Session-Id` management.
- **MCP Apps (SEP-1865)** ⭐ (v1.1.7) – Per-assistant remote MCP server connections (up to 10) with JSON-RPC 2.0 tool bridging, transient-cached discovery, admin metabox.
- **CRE Debt & Securitization Pro Toolkit** ⭐ (v1.1.7) – 57 new tools across 5 modules (Originations, Underwriting, CMBS, Debt Fund, Asset Management). 36 new professions, 17 new team configurations.

### Previous Updates (April 2026 — v1.1.6)

- **Getting Started Wizard** ⭐ NEW – 4-step onboarding wizard with 8 use-case presets (Content Creator, Customer Support, E-commerce, SEO & Research, Developer Copilot, Media & Creative Studio, Site Administrator, General Purpose). Selecting a preset creates a fully-configured assistant with tools, system prompt, and tuned temperature — working out of the box. WCAG 2.1 accessible with keyboard navigation. Access via **NV oOS → Getting Started**.
- **Quick Tool Selection Presets** ⭐ NEW – broad preset coverage on the assistant CPT edit page; verify exact live coverage against the registry (~830 current framing). New `📋 Registration & Compliance` preset (44 tools). Expanded 20+ existing presets with Shopify, full cross-platform messaging, tool scaffolding, cloud storage, site builder sections, appointment management, and more.
- **Security Hardening** ⭐ NEW – AES-256-GCM encryption upgrade, finfo fail-closed MIME detection, Discord replay attack protection, HTTPS enforcement, ZIP bomb protection, OCR error info-disclosure fix.
- **Chat Channels** – Fixed Slack @mentions, Google Chat OIDC/route issues, Teams multi-connection with OAuth one-click, Telegram typing indicator and slash-command integration.
- **Telegram Mini App** – Doctor tab now uses connection-assigned assistant; AI replies rendered as Markdown HTML; vitals log import improved.
- **AI Providers** – Gemini embedding-001 model, output_dimensionality, 9 new task types. Product actualization tool defaults to AI-powered mode (Gemini/OpenAI).
- **PDF Generation** – pdfkit/cheerio/docx/exceljs bundled into generate-*.bundle.js; no runtime node_modules needed.
- **WordPress.org Compliance** – .gitattributes excluded from ZIPs; composer.json now ships with vendor/; languages/ directory created.

### Previous Updates (February – early March 2026)

- **WordPress.org Compliance** - Removed hardcoded admin menu positions (v1.1.2)
- **JetEngine CPT/Taxonomy AI Integration** - AI metaboxes and Research & Add pages for all JetEngine CPTs
- **Package Pre-Bundling** - Critical npm packages pre-bundled in vendor directory (no npm install required)
- **Product Research Fixes** - Fixed CSS/JS loading and tab system issues
- **Pro Workflow Builder** - Fixed React initialization and stability issues
- **OAuth Improvements** - Fixed Google, Yahoo, and Mailjet authentication flows
- **Telegram Mini App CMS Overhaul** – Full WordPress CMS interface in Telegram WebView (CPTs, tools, media)
- **Discord/Telegram Reactions** – `add_discord_message_reaction`, `add_telegram_message_reaction`, `get_discord_voice_channel_members`
- **WhatsApp & Messenger Fixes** – Group routing, auto-reply error #133010, webhook processing, Messenger test connection
- **Google Chat Fixes** – HTTP 404 test connection fix, auto-reply thread routing, OAuth improvements

---

## 🚀 Quick Start

### Requirements

**Minimum:**
- WordPress 6.0+
- PHP 7.4+ (PHP 8.0+ recommended)
- MySQL 5.7+ or MariaDB 10.3+

**Optional (for enhanced features):**
- **Node.js 14+**: For image vectorization (`vectorize_image` tool)
- **PHP Functions**: `proc_open`, `proc_close`, `proc_terminate`
  - Required for Node.js integration and Process Service
  - Often disabled on shared hosting
  - **Can be enabled on Cloudways**: Settings & Packages → Application Settings → PHP FPM → Remove from `disable_functions`
- **JetEngine**: For CCT storage and content tools
- **WooCommerce**: For e-commerce tools
- **[Elementor](https://be.elementor.com/visit/?bta=229888&brand=elementor)**: For page builder widgets

**Note**: Plugin works without optional requirements, but some features will be unavailable. See [deployment troubleshooting](getting-started/installation-setup/deployment-troubleshooting.md) for details.

### Installation (30 seconds)
```bash
# 1. Upload plugin
# 2. Activate from WordPress admin
# 3. Complete the Getting Started wizard (auto-redirects on first activation)
#    → Step 1: Welcome
#    → Step 2: Connect your AI provider (OpenAI, Gemini, NVIDIA NIM, Ollama, etc.)
#    → Step 3: Choose a use-case preset (creates a ready-to-use assistant)
#    → Step 4: You're all set — copy the [mcp_ai_chat] shortcode
```

### Developer Installation (GitHub Clone)

> **Note:** The plugin is production-ready after cloning or installing from ZIP — no `npm install` or `composer install` is required for normal use. Built assets are already included. Only run the commands below if you need to **rebuild JavaScript/CSS assets** (development workflow).

**For Cloudways (Recommended):**
```bash
# SSH into your server and clone directly into plugins directory
cd /home/master/applications/YOURAPP/public_html/wp-content/plugins/
# Use --depth 1 for a fast shallow clone (repo is very large)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
# Activate the plugin in WordPress admin — it is ready to use.
```

**For Local/VPS (Development asset rebuild only):**
```bash
# Option 1: Clone directly into WordPress (recommended)
cd /path/to/wordpress/wp-content/plugins/
# Use --depth 1 for a fast shallow clone (repo is very large)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
# Activate the plugin — pre-built assets included, no npm needed.

# Option 2 (development): Clone, rebuild assets, then copy
# Use --depth 1 for a fast shallow clone (repo is very large)
git clone --depth 1 https://github.com/nvdigitalsolutions/mcp-ai-wpoos.git
cd mcp-ai-wpoos
# Only on a machine with proper write access and Node.js installed:
npm install && npm run build
composer install --no-dev
cp -r . /path/to/wordpress/wp-content/plugins/mcp-ai-wpoos/
```

**⚠️ Important:** 
- On Cloudways: Clone directly into the plugins directory to avoid errors
- **Do NOT run `npm install` in a WordPress plugins directory on managed hosting** — npm will fail with `EACCES: permission denied` when trying to create `package-lock.json`. This is expected; the plugin does not need npm on the server.
- If you must run npm in a restricted directory: use `npm install --no-package-lock`
- **Note:** Autoloader optimization is configured by default in composer.json

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
| OpenAI API Key | Settings → NV oOS | None | **Required** |
| Default Model | Settings → NV oOS | gpt-4o-mini | Cost-effective |
| Request Timeout | Settings → NV oOS | 30s | Min 5s |
| Enable Logging | Settings → NV oOS | Off | Use for debugging |

### Optional Integration Keys
- **Gemini API Key** - For Gemini provider support
- **NVIDIA API Key** - For NVIDIA NIM provider support (get from [build.nvidia.com](https://build.nvidia.com/))
- **Crawl4AI URL** - For web crawling capabilities
- **Mailjet API** - For email automation
- **QuickBooks API** - For financial reporting
- **OpenRouter API Key** — For OpenRouter unified gateway (OpenAI/Anthropic/Google/Meta via one key)
- **DeepSeek API Key** — For DeepSeek provider (reasoning_content passthrough)

---

## 👥 Common User Tasks

### Creating an Assistant

![Create Assistant](screenshots/admin/61-create-assistant.png)
*Create Assistant page with 204 profession templates*

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

# Base plugin certification checks (excludes pro/examples/tests)
composer run lint:base
composer run lint:base:compat
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
1. Settings → NV oOS
2. Add Auth0 Domain
3. Add Auth0 Audience
4. Add Auth0 Scope
5. Generate Auth0 token
6. Test with token
```

### WordPress.com/Gravatar Bridge
```
1. Settings → NV oOS → Authentication
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
5. Review attachment settings in NV oOS
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
Settings → NV oOS → Chat Theme
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

## 🧠 LLM Harness Quick Toggle

Per-assistant opt-in: Edit Assistant → **LLM Harness** metabox → Enable → check the layers you want (A–H). All layers are off by default. Reference: [docs/llm-harness.md](llm-harness.md).

---

## ✅ HITL Approval Queue

Admin: **NV oOS → Orchestration → Approvals**. Tool: `request_user_approval`. REST: `GET/POST/PATCH /wp-json/mcp-ai/v1/approvals/*`. Pending → Publish = approved; Private = denied.

---

## 🔗 Toolkit MCP Discovery

Discovery endpoint: `GET /.well-known/mcp` (returns JSON array of all enabled toolkit server URLs). Credentials: **NV oOS → Orchestration → Toolkit MCP → {Toolkit} → Credentials**. CLI: `wp mcp-ai mcp-server token-generate {slug}`. Reference: [docs/mcp-servers.md](mcp-servers.md).

---

## 📚 Additional Resources

### Full Documentation
- [Complete README](../README.md) - 1,027 lines of comprehensive docs
- [Documentation Index](DOCUMENTATION_INDEX.md) - All 39 documentation files
- [Tool Reference](reference/tools/tool-reference.md) - All ~830 tools detailed (~195 base + ~635 Pro; live count via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative)
- [REST API Guide](reference/api/rest-api.md) - Complete API documentation
- [Orchestration Budget Enforcement](architecture/orchestration/orchestration-budget-enforcement.md) - Budget prediction and adjustment

### External Links
- [OpenAI Platform](https://platform.openai.com/)
- [WordPress Codex](https://codex.wordpress.org/)
- [JetEngine Docs](https://crocoblock.com/knowledge-base/jetengine/)
- [Elementor](https://be.elementor.com/visit/?bta=229888&brand=elementor)
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

### Quick Start Resources
- **Getting Started Wizard** ⭐ NEW — Activate and follow the 4-step setup at **NV oOS → Getting Started** to create your first assistant in under 2 minutes
- **[Use Cases & Quickstart Guides](getting-started/USE_CASES_AND_QUICKSTARTS.md) ⭐ NEW** - 7 major use cases with step-by-step guides
- **[5-Minute Quick Start](getting-started/QUICK_START_5_MINUTES.md)** - Get started immediately
- **[Documentation Index](DOCUMENTATION_INDEX.md)** - Complete documentation map

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
