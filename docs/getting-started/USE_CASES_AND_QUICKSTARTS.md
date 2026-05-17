# NV oOS Use Cases & Quickstart Guides

**Doc revision:** 2.0
**Last updated:** May 17, 2026
**Tested against plugin version:** `1.1.18` (May 14, 2026)
**Estimated reading time:** ~45 minutes

> Counts in this document (tools, professions, toolkits, model prices) are point-in-time. The live `WP_MCP_AI_Tool_Registry::get_tools()` registry and `includes/data/model-catalog.json` are the **authoritative sources of truth**. Every number cited below is reconciled against the companion [USE_CASES Fact Sheet](_USE_CASES_FACT_SHEET.md) — start there when revising this document.

## 📑 Table of Contents

- [Overview](#overview)
- [Professional & Team Templates](#professional--team-templates)
- [1. Content Creation & Management](#1-content-creation--management)
- [2. E-Commerce Automation](#2-e-commerce-automation)
- [3. Media Generation & Processing](#3-media-generation--processing)
- [4. Business Operations](#4-business-operations)
- [5. Research & Data Analysis](#5-research--data-analysis)
- [6. Developer & Technical Integration](#6-developer--technical-integration)
- [7. Education & Knowledge Management](#7-education--knowledge-management)
- [8. Multi-Agent Orchestration](#8-multi-agent-orchestration)
- [9. Advanced Workflow Automation](#9-advanced-workflow-automation)
- [10. Video Production & Transcoding](#10-video-production--transcoding) 🔒
- [11. Site Building Automation](#11-site-building-automation) 🔒
- [12. Regulatory Compliance](#12-regulatory-compliance) 🔒
- [13. Front-End Chat Delivery (Chat SPA)](#13-front-end-chat-delivery-chat-spa)
- [14. In-WP Documentation Viewer (Docs Hub)](#14-in-wp-documentation-viewer-docs-hub)
- [Pro Features & Toolkits](#pro-features--toolkits) 🔒
- [Cost Considerations](#cost-considerations)
- [Best Practices](#best-practices)
- [Troubleshooting Common Issues](#troubleshooting-common-issues)
- [Roadmap & Upcoming Toolkits](#roadmap--upcoming-toolkits)
- [Next Steps](#next-steps)

---

## Overview

NV oOS (Open Operator System) is an AI-assistant framework for WordPress. The reconciled tool count published in `readme.txt` for plugin `1.1.18` is **~830 tools (~195 base + ~635 Pro)**; the live `WP_MCP_AI_Tool_Registry::get_tools()` registry is authoritative. This guide walks through practical scenarios and quickstarts for the most common ones.

### What's new since the previous doc revision (Jan 2026 → May 2026)

Plugin moved from `1.3.0`-era marketing terminology to a clean `1.1.x` line over the last four months. Headline additions in chronological order:

- **Multi-Agent Orchestration** — Planner / Executor / Critic / Specialist roles with weighted, consensus, sequential, parallel-merge and best-of-N aggregation. Stable since 1.1.9.
- **Workflow Coordinator** — Parallel task execution (up to 3 simultaneous), dependency graph, deadlock detection, exponential-backoff retry, state persistence. Stable since 1.1.9.
- **Orchestration Reference** consolidated as the single source of truth for the 10 workflow presets, 13 resource presets, PSO algorithm, reasoning controller, load balancer, multi-agent system, health monitoring, budget enforcement, hooks and storage keys (`docs/ORCHESTRATION_REFERENCE.md`).
- **WP.org Compliance Hardening** (1.1.17) — 33-alert Dependabot sweep, SSRF hardening, banner additions.
- **Chat SPA addon** (1.1.17, Phases 1–7, v0.6.0) — `[nvoos_chat_spa]` React frontend replacing the legacy `[mcp_ai_assistant]` shortcode. ~81 KB gzip. Memory drawer, HITL approval bar, transcripts sidebar, file attachments, regenerate/edit. Gated by the `WP_MCP_AI_LEGACY_CHAT_JS` constant.
- **Docs Hub addon** (1.1.17, v0.3.8) — In-WP documentation viewer with remote-repo picker, sitemap provider, SSRF-hardened tree-fetch, syntax highlighting, edit-on-GitHub footer.
- **Toolkit SPA Blueprint Phases 5–12** (1.1.17) — SPA shell adopted by the 10 GA Pro toolkits.
- **DigitalOcean Serverless Inference provider** (1.1.18) — OpenAI-compatible client + adapter + embedding provider; new Models tab; cataloged with zeroed placeholder prices that operators populate themselves.
- **Unix Theory Compliance P0–P6** (1.1.18) — canonical tool envelope (success array or `WP_Error`, never `array('success'=>false,...)`) plus two-gate sanitisation, enforced by two new PHPCS sniffs.
- **Async Chat Continuation, Jobs/Tasks Drawer, Toolkit MCP Servers Phase 7 Admin UI** (1.1.18) — long-form streaming becomes resumable; background jobs surface in a real admin drawer; any toolkit can be exposed as an external MCP server (ADR-002).
- **Inline-async-tick pattern** (1.1.18 and earlier slices) — fixes the "job stuck at `Status: queued, Progress: 0/1`" failure mode on hosts where `DISABLE_WP_CRON=true` or `wp-cron.php` is firewalled. Now applies to Tool Async Executor, Transcript Mining, SaaS Apply, Veo polling, Graphify reindex, Crawl4AI poller, Docs Hub rebuild, and Harness Eval Scheduler.
- **Scheduled Result widget + Gutenberg block + Elementor widget** (1.1.18) — six render modes (`summary-card`, `list`, `table`, `metric`, `timeline`, `raw`); new REST routes under `mcp-ai-pro/v1/schedules/*`; three new tools (`get_schedule_latest_result`, `render_schedule_result`, `configure_schedule_widget_defaults`).
- **UI/UX Pro Max bundled skill** (1.1.18) — first entry in the skill-pack registry (color palettes, typography scales, icon libraries, stack-specific guidelines for React / Vue / Angular / Laravel / Astro / Svelte / Flutter / SwiftUI / Jetpack Compose). Base skill count: 44 → 45.

### Prerequisites

Before starting any use case:

1. ✅ WordPress 6.0+ and PHP 7.4+ (plugin tested up to WP 6.9)
2. ✅ NV oOS plugin installed and activated
3. ✅ At least one provider API key configured (Settings → NV oOS → Providers). The active providers in the current model catalog are OpenAI, Anthropic, Azure, Cloudflare, DeepSeek, DigitalOcean, Google/Gemini, Hugging Face, Kimi/Moonshot, NVIDIA, OpenRouter, plus local options (Ollama, LM Studio, WebLLM, embedded MLC).
4. ✅ Basic familiarity with the WordPress admin

### Quick reference

| Use case | Time to setup | Cost per session | Difficulty | Templates available |
|----------|---------------|------------------|------------|---------------------|
| Content writing | 3–5 min | $0.01–$0.10 | Easy | ✅ Content Writer, Technical Writer |
| E-commerce | 5–10 min | $0.05–$0.20 | Medium | ✅ Marketing Consultant |
| Media generation | 3–5 min | $0.02–$0.50 | Easy | ✅ Graphic Designer |
| Business operations | 8–15 min | $0.10–$0.30 | Medium | ✅ Business Consultant, Project Manager |
| Research & data | 3–5 min | $0.01–$0.05 | Easy | ✅ Research Scientist, Data Scientist |
| Developer integration | 20–30 min | varies | Advanced | ✅ Software Developer, Systems Admin |
| Education | 5–10 min | $0.05–$0.15 | Medium | ✅ 13 IGCSE professions, 6 IGCSE teams |
| Multi-agent teams | 10–20 min | $0.20–$0.50 | Advanced | ✅ Research Team, Content Team |
| Workflow automation | 10–15 min | $0.10–$0.25 | Medium | ✅ Project Manager |
| Video production 🔒 | 5–10 min | $0.10–$0.30 | Medium | ✅ Video Editor |
| Site building 🔒 | 15–25 min | $0.15–$0.40 | Advanced | ✅ Web Developer |
| Chat SPA front-end | 3–5 min | n/a (front-end) | Easy | n/a |
| Docs Hub viewer | 5–10 min | n/a (front-end) | Easy | n/a |

🔒 = Requires a specific Pro toolkit.

---

## Professional & Team Templates

NV oOS includes a template system for rapid assistant deployment. ~190 pre-built profession templates ship as profession-document files under `includes/knowledge-base/profession-documents/` and are seeded into the `mcp_ai_profession` custom post type on activation.

### What are professional templates?

Pre-configured assistant blueprints that include:

- **Role descriptions** — pre-written expertise and context
- **Default tools** — curated tool selections for each profession
- **Knowledge bases** — industry-specific best practices and guidelines
- **AI model defaults** — recommended provider, model, and temperature
- **Warnings & disclaimers** — professional context and limitations

### Available categories (~190 professions across 12 categories)

| Category | Approx. count | Examples |
|----------|---------------|----------|
| 🌾 Agriculture & Natural Resources | 10 | Agronomist, Environmental Scientist, Forester |
| 🎨 Art, Media & Entertainment | 24 | Graphic Designer, Content Writer, Video Editor |
| 💼 Business & Finance | 16 | Accountant, Financial Advisor, Marketing Consultant |
| 🎓 Education | 10 | Mathematics Tutor, Science Teacher, Academic Advisor |
| 🏥 Healthcare & Medicine | 25 | Registered Nurse, Physician, Pharmacist |
| ⚖️ Law & Public Safety | 11 | Attorney, Paralegal, Mediator |
| 🔬 Science & Engineering | 17 | Software Developer, Data Scientist, Chemical Engineer |
| 🍽️ Service Industry | 12 | Chef, Event Planner, Customer Service Rep |
| 💻 Technology | 12 | Web Developer, IT Support, Systems Administrator |
| 🔧 Trades & Manual Labor | 13 | Electrician, Plumber, Carpenter |
| 🚚 Transportation | 10 | Logistics Coordinator, Transportation Manager |
| 📋 Miscellaneous | 22 | Project Manager, Technical Writer, Translator |

> Counts are derived from the profession-document directory. The exact per-category split is re-derived during each doc revision — see the [fact sheet](_USE_CASES_FACT_SHEET.md) for the live methodology.

### Quick start: creating an assistant from a template

**Time: 3 minutes**

1. **Navigate to AI Assistants → Add New**
2. **Browse the visual profession grid** — filter by category, search for specific roles, view profession descriptions
3. **Click "Create" on any profession**
4. **Customize in the modal:**
   - Assistant name (defaults to profession title)
   - AI Provider (OpenAI, Anthropic, Gemini, Ollama, etc.)
   - Model selection (auto-populated from provider)
   - Temperature (defaults to profession recommendation)
5. **Click "Deploy Assistant"**
6. **Test immediately** — click "Test Assistant" to verify

### Team deployments

Deploy entire teams of specialists with one click. Pre-built teams include Engineering, Pharmaceutical Development, Research & Data Science, Marketing & Growth, and six IGCSE teams (Mathematics, Science, Humanities, Languages & Technology, Year-Level Support, Academic Support) with 100% Cambridge IGCSE syllabus coverage.

**Deploy a team:**

1. Navigate to **Teams → Add Team** (or select a pre-built team)
2. Configure team-wide settings (provider, model, temperature)
3. Click "Deploy Team"
4. All team-member assistants are created simultaneously
5. Test from backend before exposing to users

### Backend testing

Test assistants, professions, and teams safely from the WordPress admin **before** exposing to users:

- **Test Assistant** (Admin → AI Assistants → Test Assistant) — full feature parity with frontend, all tools enabled including admin-only, file upload support, transcript saving, real-time streaming.
- **Test Profession** (Admin → Professions → Test Profession) — preview profession templates, validate role descriptions, test tool selections, verify knowledge base accuracy.
- **Test Team** (Admin → Teams → Test Team) — validate team-member coordination, verify shared settings, multi-assistant conversations.

All test pages require `manage_options` capability and are admin-only.

### Creating custom professions

**Time: 15 minutes**

1. **Professions → Add New**
2. **Set basic information:** title, description, category
3. **Define expertise:** expertise areas, role description, warnings/disclaimers
4. **Configure knowledge base:** industry-specific content, best practices, reference standards
5. **Select default tools** — browse the ~195 base tools (more if Pro toolkits are active) and choose relevant ones
6. **Set AI defaults:** recommended provider, model preference, temperature
7. **Publish**

### Customising templates

Templates are excellent starting points but generic by design. After deploying, layer in your brand voice, business processes, terminology, compliance language, and quality standards by editing the system prompt and adding to the knowledge base. The 🎓 icon throughout this guide marks use cases where a profession template will accelerate setup.

---

## 1. Content Creation & Management

Transform your content workflow with AI-powered writing, SEO optimization, and multi-language support.

### Use Case 1.1: Blog Post Generation with SEO

**Problem:** Need to create SEO-optimized blog posts quickly while maintaining quality.

**Solution:** Use NV oOS with content and SEO tools to research, write, and optimize posts.

#### Required Tools

- `search_content` — research existing content
- `web_search` — find current information
- `save_post` — create WordPress posts
- `get_rankmath_seo` — SEO analysis (requires Rank Math plugin)

#### Quickstart (10 minutes)

**Step 1: Create an SEO writer assistant.**

**Option A — using a profession template 🎓 (recommended, 3 minutes)**

1. Go to AI Assistants → Add New
2. Search for "Content Writer" or "Technical Writer" in the profession grid
3. Click "Create" on the template
4. Customize: name ("SEO Blog Writer"), provider (OpenAI or your preference), model (`gpt-4o-mini` or `gpt-4.1-nano` for cost-effective)
5. Click "Deploy Assistant"
6. Customize the deployed assistant for your business: brand voice, style guide, approved terminology, SEO requirements

**Option B — manual configuration (10 minutes)**

1. AI Assistants → Add New (skip profession selection)
2. Title: "SEO Blog Writer"
3. Enable tools: `search_content`, `web_search`, `save_post`, `get_rankmath_seo`
4. System prompt: "You are an expert SEO content writer. Create engaging, well-researched blog posts optimized for search engines. Always include proper headings, meta descriptions, and keyword optimization."
5. Publish

**Step 2: Generate content.**

```
Prompt: "Write a 1000-word blog post about [topic] targeting the keyword [keyword].
Include an introduction, 3 main sections, and a conclusion with a call-to-action."
```

**Step 3: Optimize for SEO.**

```
Prompt: "Analyze the SEO score for post ID [ID] and suggest improvements."
Then:    "Update the post with your SEO recommendations."
```

**Cost estimate:** $0.05–$0.15 per 1000-word post (using `gpt-4o-mini` or `gpt-4.1-nano`).

---

### Use Case 1.2: Multi-Language Content Translation

**Problem:** Translate content for global audiences while preserving tone and context.

**Solution:** Use NV oOS with language models that support 50+ languages.

#### Quickstart (5 minutes)

1. AI Assistants → Add New
2. Title: "Multi-Language Translator"
3. Enable tools: `search_content`, `save_post`
4. System prompt: "You are a professional translator. Maintain the original tone, style, and formatting. Adapt idioms and cultural references appropriately for the target language."
5. Temperature: 0.3 (for consistent translations)

Prompt: `"Find post ID [ID], translate it to [language], and save as a new post with the language code in the title."`

**Cost estimate:** $0.02–$0.08 per 1000 words.

---

### Use Case 1.3: Research & Content Curation

**Problem:** Gather and synthesize information from multiple sources.

**Solution:** Combine web search, Crawl4AI, and content analysis tools.

#### Required Tools
- `web_search` — quick searches (DuckDuckGo/Brave)
- `run_crawl4ai_job` — deep web scraping
- `search_content` — internal content search
- `save_post` — save research findings

#### Quickstart (15 minutes)

1. AI Assistants → Add New
2. Title: "Research Curator"
3. Enable tools: `web_search`, `run_crawl4ai_job`, `search_content`, `save_post`
4. Configure Crawl4AI URL in Settings → NV oOS (if available)

Prompts:

```
"Research [topic] and provide a comprehensive summary with citations.
 Include recent developments from the past 6 months."

"Use Crawl4AI to scrape [URL] and extract key information about [topic]."

"Compile findings into a research report and save as a draft post."
```

**Cost estimate:** $0.05–$0.20 per research session.

---

## 2. E-Commerce Automation

Streamline your online store with AI-powered product management and customer service.

### Use Case 2.1: Product Description Generation

**Enhanced with Pro E-Commerce Toolkit 🔒** — adds ~20 WooCommerce automation tools including bulk operations, inventory management, and advanced product optimization.

#### Required Tools

**Base:**
- `create_woo_product` — create WooCommerce products
- `get_woo_products` — retrieve existing products
- `generate_openai_image` — product images (optional)

**Pro E-Commerce Toolkit 🔒:** adds AI-enhanced descriptions, SEO optimization, CSV batch import, inventory management, sales analytics, and additional product tools.

#### Prerequisites
- WooCommerce plugin installed and activated
- Pro E-Commerce Toolkit (for advanced features) 🔒

#### Quickstart (15 minutes)

**Option A — profession template 🎓 (5 minutes)**

1. AI Assistants → Add New
2. Search for "Marketing Consultant" or "Content Writer"
3. Click "Create", set name to "Product Description Writer"
4. Add WooCommerce tools (`create_woo_product`, `get_woo_products`)
5. Add brand style guide + product catalog template to base knowledge
6. Customize for your brand personality and product voice, deploy

**Option B — manual (15 minutes)**

1. AI Assistants → Add New
2. Title: "Product Description Writer"
3. Enable tools: `create_woo_product`, `get_woo_products`, `generate_openai_image` (optional)
4. Add brand style guide + product catalog template to base knowledge
5. System prompt: "You are an expert e-commerce copywriter. Create compelling product descriptions that highlight features, benefits, and use cases. Maintain [brand] voice and tone."

**Generate descriptions:**

```
Single product:
"Create a WooCommerce product for [name]. Price: $[X]. Category: [cat].
 Generate an engaging description highlighting [features]."

Bulk:
"For each product in the CSV, create a WooCommerce draft with optimized
 description and appropriate categorization."
```

**Cost estimate:** Description only $0.01–$0.03 per product; with image generation $0.05–$0.10 per product.

---

### Use Case 2.2: Customer Support Automation

**Problem:** Manage customer inquiries and order status requests.

**Solution:** AI assistant that handles order lookups and common questions.

#### Required Tools
- `get_woo_recent_orders` — order information
- `get_woo_products` — product details
- `send_group_email` — customer notifications

#### Quickstart (10 minutes)

1. AI Assistants → Add New
2. Title: "Customer Support Bot"
3. Enable `get_woo_recent_orders`, `get_woo_products`
4. System prompt: "You are a helpful customer support agent. Answer questions about orders, shipping, returns, and products. Be friendly, professional, and concise. Escalate to human support when needed."
5. Add knowledge base: FAQ, return policy, shipping information

**Deploy on the frontend** using either the legacy `[mcp_ai_chat assistant="[ID]" allow_guests="true"]` shortcode or the new Chat SPA shortcode `[nvoos_chat_spa assistant_id="[ID]" guest="true"]` (see [§13 Front-End Chat Delivery](#13-front-end-chat-delivery-chat-spa)).

**Cost estimate:** $0.02–$0.05 per support conversation.

---

### Use Case 2.3: Price Comparison & Wholesale Research

**Problem:** Monitor competitor pricing and wholesale options.

**Solution:** Automated price scraping and comparison.

#### Required Tools
- `crawl4ai_price_lookup` — compare BJ's, Sam's Club, Costco

#### Quickstart (5 minutes)

1. AI Assistants → Add New
2. Title: "Price Researcher"
3. Enable `crawl4ai_price_lookup`
4. System prompt: "You help find the best wholesale prices. Compare prices across multiple wholesalers and highlight the best deals."

Prompt: `"Find wholesale prices for [product] at BJ's, Sam's Club, and Costco. Compare and recommend the best value."`

**Cost estimate:** $0.03–$0.08 per price lookup.

---

## 3. Media Generation & Processing

Create professional media assets with AI-powered tools.

**Enhanced with Pro toolkits 🔒:**
- **Image Production Toolkit** — advanced editing, batch processing, format conversion (see [§Pro Features & Toolkits](#pro-features--toolkits))
- **Video Production Toolkit** — FFmpeg transcoding, editing, compression (see [§10](#10-video-production--transcoding))

### Use Case 3.1: AI Image Generation for Marketing

**Problem:** Need custom images for blog posts, social media, and ads.

**Solution:** Generate on-brand images via OpenAI DALL-E, Gemini Imagen-4, or `gemini-2.5-flash-image` (Google).

#### Required Tools

**Base:**
- `generate_openai_image` — DALL-E 3
- `generate_gemini_image` — Gemini image generation
- `edit_openai_image` — image editing
- `create_image_variation` — variations

**Pro Image Production Toolkit 🔒:** batch operations, format conversion (WebP, AVIF, PNG, JPG), compression, filters, watermarking, and additional image tools.

> The previous revision claimed Cloudflare Workers AI exposed image models `flux-2-dev` and `leonardo-ai`. Neither model is in `includes/data/model-catalog.json` as an active entry — see the [fact sheet §5.1](_USE_CASES_FACT_SHEET.md#5-ai-provider-catalog-active-models). When/if those routes ship, this section will be updated. For now the catalogued image generators are `imagen-4` (Gemini) and `gemini-2.5-flash-image` (Google).

#### Quickstart (5 minutes)

**Option A — profession template 🎓 (3 minutes)**

1. AI Assistants → Add New
2. Search for "Graphic Designer"
3. Click "Create"
4. Customize: name ("Marketing Image Creator"), provider (OpenAI / Gemini / Google), model (`gpt-4.1` or `gemini-2.5-pro` for better instruction following on image prompts)
5. Upload brand guidelines (colors, fonts, logo usage), add aesthetic + visual identity to system prompt, deploy

**Option B — manual (10 minutes)**

1. AI Assistants → Add New
2. Title: "Marketing Image Creator"
3. Enable image tools as listed above
4. System prompt: "You create professional marketing images. Follow brand guidelines and create visually appealing images suitable for [blog/social/ads]."

**Generate images:**

```
Simple:    "Create a hero image for a blog post about [topic]. Style: [style]. Include [elements]."
Batch:     "Create 5 social media graphics for our [campaign]. Each should feature [theme] with different color schemes."
Variation: "Take attachment [ID] and create 3 variations with different backgrounds/styles."
```

**Cost estimate:** Verify current pricing in Settings → Models. As of catalog v `2026.05.04`, image-generating models in the catalog are placeholder-priced ($0/M input, $0–30/M output) — see [§Cost Considerations](#cost-considerations) for the canonical table.

---

### Use Case 3.2: Audio Transcription & Text-to-Speech

**Problem:** Convert audio to text or create voiceovers.

**Solution:** OpenAI Whisper for transcription, TTS for audio generation.

#### Required Tools
- `transcribe_openai_audio` — audio → text
- `generate_openai_speech` — text → audio

#### Quickstart (10 minutes)

1. AI Assistants → Add New
2. Title: "Audio Processor"
3. Enable `transcribe_openai_audio`, `generate_openai_speech`
4. System prompt: "You help process audio files. Provide accurate transcriptions and create natural-sounding speech audio."

Voices: `alloy`, `echo`, `fable`, `onyx`, `nova`, `shimmer`.

**Cost estimate:** Transcription $0.006/minute; TTS $0.015 per 1000 characters.

---

### Use Case 3.3: Video Analysis & Captioning

**Problem:** Analyze video content or generate captions.

**Solution:** AI-powered video analysis and caption generation.

#### Required Tools
- `analyze_video` — video content analysis
- `generate_video_caption` — auto-generate captions
- `check_video_status` — monitor video processing

(For FFmpeg-based transcoding workflows, see [§10 Video Production & Transcoding](#10-video-production--transcoding).)

#### Quickstart (15 minutes)

1. AI Assistants → Add New
2. Title: "Video Analyst"
3. Enable the tools above
4. System prompt: "You analyze video content and create accurate, engaging captions. Describe key scenes, actions, and dialogue."

**Cost estimate:** $0.10–$0.50 per video (depends on length).

---

## 4. Business Operations

Automate routine business tasks and communications.

**Enhanced with Pro toolkits 🔒** — Financial Planner, Calendar & Booking, DJ Management, Social Media, Analytics, CRM (see [§Pro Features & Toolkits](#pro-features--toolkits)).

### Use Case 4.1: Email Campaign Automation

**Problem:** Manage email campaigns and follow-ups manually.

**Solution:** AI-powered email content generation and automated sending.

#### Required Tools
- `send_mailjet_email` — email delivery (requires Mailjet)
- `send_group_email` — WordPress-native email
- `search_content` — content for campaigns

#### Quickstart (20 minutes)

1. **Configure email service** — base uses `wp_mail`; Pro can use Mailjet (Settings → NV oOS → Integrations).
2. **Create the assistant** (profession template "Marketing Consultant" or "Content Writer" recommended).
3. Enable email + content tools, add brand guidelines / previous successful campaigns / product info to knowledge base.

**Sample campaigns:**

```
Newsletter:
"Create an email newsletter featuring our 3 latest blog posts.
 Subject line should be catchy. Include product promotion at the end.
 Send to [user role/email list]."

Product launch:
"Write a product launch email for [product]. Highlight key features and
 early-bird discount. Create subject line variants for A/B testing."

Welcome sequence:
"Create a 3-email welcome sequence for new subscribers.
 Day 1: Welcome. Day 3: Share resource. Day 7: First-purchase discount."
```

**Cost estimate:** $0.02–$0.05 per email + email service costs.

**Best practice:** Always human-review AI-generated emails before sending.

---

### Use Case 4.2: Social Media Management

**Problem:** Create and schedule consistent social media content.

**Solution:** AI-powered content creation for multiple platforms (requires platform credentials).

#### Required Tools
- `post_facebook_instagram`, `post_linkedin_update`, `post_tiktok_video`, `post_google_business_update`

#### Prerequisites
- API credentials for each platform (Settings → NV oOS → Integrations)

#### Quickstart (30 minutes)

1. Configure each platform's API credentials.
2. Create "Social Media Manager" assistant, enable the platform tools.
3. System prompt: tone guidance per platform (Facebook/Instagram visual+engaging, LinkedIn professional, TikTok trendy+authentic).

Prompts:

```
Single platform:
"Create a Facebook post announcing [event/product]. Include engaging copy,
 relevant hashtags, and a call-to-action."

Multi-platform:
"Create a social media campaign for [launch] across Facebook, Instagram,
 LinkedIn, and TikTok. Adapt the message for each platform's audience."

Weekly batch:
"Create 5 days of Instagram content themed around [topic]. Include captions
 and hashtag suggestions."
```

**Cost estimate:** $0.05–$0.15 per social media batch + platform API costs.

---

### Use Case 4.2b: Advanced Social Media Analytics 🔒

**Requires: Pro Social Media toolkit** (GA, SPA-manifested as `social-media`).

**Problem:** Cross-platform social media performance tracking, competitor analysis, engagement insights.

**Solution:** A bundle of platform-specific tools covering performance tracking (Facebook / Instagram / LinkedIn / TikTok / X / YouTube insights), engagement analysis (post engagement, follower growth, peak posting times, hashtag performance, engagement rate, sentiment), competitive intelligence (competitor profile + content tracking, side-by-side comparison, industry benchmarks), and reporting (consolidated reports, dashboards, CSV export, scheduled reports).

#### Quickstart (20 minutes)

1. **Enable Pro Social Media toolkit:** Settings → NV oOS → Pro Toolkits.
2. **Configure platform API credentials** for each connected service.
3. **Create an analytics assistant** with all the toolkit's tools enabled.
4. System prompt: "You are a social media analytics expert. Track performance across all platforms, identify trends, provide actionable insights, and generate comprehensive reports. Compare against competitors and industry benchmarks. Focus on ROI and engagement metrics."
5. Suggested model: `gpt-4o-mini` (sufficient for data analysis); temperature 0.3.

**Sample analytics requests:**

```
"Generate a 30-day cross-platform social report. Aggregate metrics, identify
 top-performing posts, calculate engagement rates per platform, show follower
 growth trends, and recommend optimal posting times."

"Analyze our performance vs 3 competitors [names]: follower growth rate,
 engagement rate, content-gap analysis, recommended competitive strategies."

"Identify trending topics in our industry: best-performing hashtags, top
 content types, engagement peaks, trending competitor content."
```

**Cost estimate:** $0.10–$0.25 per comprehensive analytics report.

---

### Use Case 4.3: Meeting & Calendar Management

**Problem:** Schedule meetings and manage calendar events.

**Solution:** AI-powered calendar management with Google Calendar integration. Pro Calendar & Booking toolkit (GA, `calendar-booking`) adds appointment booking, availability rules, recurring events, automated reminders, and rescheduling/cancellation flows.

#### Required Tools

**Base:** `create_google_calendar_event`, `search_gmail` (requires Gmail credentials)

**Pro Calendar & Booking toolkit 🔒:** real-time availability lookup, appointment booking workflow, booking confirmations, recurring-event series management, rescheduling, cancellation, availability rules, manual time-slot blocking, and additional booking tools.

#### Prerequisites
- Google Cloud OAuth credentials (Settings → NV oOS → Integrations)

#### Quickstart (15 minutes)

1. Configure Google integration (OAuth credentials or service account JSON).
2. Create "Calendar Manager" assistant, enable the relevant tools.
3. System prompt: "You help manage calendars and schedule meetings. Create clear event descriptions, set appropriate reminders, and suggest optimal meeting times."

Prompts:

```
Simple meeting:
"Schedule a team meeting tomorrow at 2pm for 1 hour. Title: Weekly Standup.
 Add reminder 15 minutes before."

Recurring series:
"Create a conference event series. Every Monday at 10am for 8 weeks.
 Include video call link and invite [email addresses]. Reminders 1 day and 15 min before."

Find time:
"Check my Gmail for meeting requests this week and schedule them at appropriate
 times based on my availability."
```

**Cost estimate:** $0.01–$0.03 per calendar operation.

---

### Use Case 4.4: Report Generation & Analytics

**Problem:** Generate regular reports from various data sources.

**Solution:** Automated report generation with data visualization (Pro Analytics toolkit, GA, `analytics`).

#### Required Tools (Pro Analytics toolkit 🔒)
- `google_analytics_report` — GA4 data
- `get_facebook_instagram_insights`, `get_linkedin_insights`, `get_tiktok_insights`
- `quickbooks_report` — financial reports
- `create_chart` — data visualization

#### Quickstart (30 minutes)

1. Configure analytics APIs (Google Analytics service account, social OAuth, QuickBooks OAuth).
2. Create "Report Generator" assistant, enable the analytics tools.
3. System prompt: "You generate comprehensive reports with insights and recommendations. Present data clearly with visualizations. Highlight trends, anomalies, and actionable items."

Prompts: website analytics, social media comparison, financial report, executive dashboard — see existing examples in your current admin workflow.

**Cost estimate:** $0.10–$0.30 per comprehensive report.

---

### Use Case 4.5: Scheduled Result Widget & Block (NEW)

**Requires:** Pro Schedule toolkit (`pro-schedule-toolkit`).

**Problem:** Output of long-running Pro Schedules (research runs, periodic analytics, recurring reports) needs to be surfaced on the front-end without giving editors API access.

**Solution:** Bind any Pro Schedule to either a Gutenberg dynamic block (`mcp-ai-wpoos/scheduled-result`) or the Elementor widget `WP_MCP_AI_Elementor_Scheduled_Result_Widget`. Both bind to the same typed result envelope (`{ summary, data, render, status, error, generated_at }`) stored independently of the run-history ring buffer.

#### Six render modes

| Mode | Use |
|---|---|
| `summary-card` | Headline + key metric + timestamp |
| `list` | Bulleted/numbered list rendering |
| `table` | Tabular data with column headers |
| `metric` | Single big-number KPI |
| `timeline` | Time-series / event log |
| `raw` | Pretty-printed JSON for diagnostics |

#### REST routes

All under `mcp-ai-pro/v1/schedules`:

- `GET ?selectable=1` — schedule picker (returns IDs + labels usable by editors)
- `GET /{id}/latest-result` — current envelope with `ETag` for client-side cache
- `GET /{id}/results` — paginated history (default retention 10, per-schedule overridable via `result_retention`)
- `POST /{id}/preview` — nonce-protected preview without consuming a real run

#### Three new tools

- `get_schedule_latest_result` — fetch the latest envelope for an assistant prompt
- `render_schedule_result` — render an envelope inline using any of the six modes
- `configure_schedule_widget_defaults` — set default mode + retention per schedule

#### Quickstart (10 minutes)

1. **Enable the Pro Schedule toolkit.**
2. **Create or edit a Pro Schedule** (Pro → Schedules) and run it at least once so a result envelope exists.
3. **Place the block:** in Gutenberg, search for "Scheduled Result". In the inspector, pick the schedule, pick a render mode, and save.
4. **(Optional) Elementor:** drag in the **Scheduled Result Display** widget; same picker + mode controls.
5. **(Optional) Adjust retention** via the `wp_mcp_ai_pro_schedule_result_retention` filter or the per-schedule `result_retention` option.

#### Hooks

`wp_mcp_ai_pro_schedule_result_envelope`, `wp_mcp_ai_pro_schedule_public_result`, `wp_mcp_ai_pro_schedule_result_retention`, `wp_mcp_ai_pro_schedule_result_capability`, action `wp_mcp_ai_pro_schedule_result_recorded`.

**Cost estimate:** Free at display time; cost is whatever the underlying schedule consumed.

**Reference:** `docs/features/scheduled-result-widget.md`.

---

## 5. Research & Data Analysis

Leverage AI for research, monitoring, and data insights.

### Use Case 5.1: Competitive Intelligence & Market Research

**Problem:** Track competitor activities and market trends.

**Solution:** Automated web monitoring and data collection.

#### Required Tools
- `web_search`, `run_crawl4ai_job`, `search_content`

#### Quickstart (15 minutes)

1. AI Assistants → Add New, title "Market Intelligence", enable the tools above.
2. System prompt: "You conduct thorough market research. Identify trends, analyze competitors, and provide strategic insights. Always cite sources and note data collection date."

Sample prompts: competitor analysis, market trends, continuous monitoring.

**Cost estimate:** $0.05–$0.20 per research session.

---

### Use Case 5.2: Real-Time Monitoring (Weather, Disasters, News)

**Problem:** Monitor and respond to real-time events.

**Solution:** Specialized monitoring tools for weather, disasters, and humanitarian updates.

#### Required Tools
- `get_nhc_active_storms` — hurricane tracking
- `get_gdacs_events` — global disaster alerts
- `get_open_meteo_forecast` — weather forecasts
- `reliefweb_reports` — humanitarian updates

#### Quickstart (10 minutes)

1. Create "Event Monitor" assistant with the above tools.
2. System prompt: "You monitor critical events and provide timely alerts. Summarize key information, assess impact, and suggest actions when appropriate."
3. **Automate with cron:** use `create_cron_job` to schedule periodic checks (e.g. daily 6am summary email).

**Cost estimate:** $0.01–$0.05 per monitoring check.

---

### Use Case 5.3: Dataset Analysis with Hugging Face

**Problem:** Access and analyze machine-learning datasets.

**Solution:** Hugging Face Datasets API integration.

#### Required Tools
- `huggingface_dataset_search`, `huggingface_dataset_get_info`, `huggingface_dataset_preview_rows`, `huggingface_dataset_filter`

#### Quickstart (15 minutes)

1. Create "Dataset Analyst" assistant with the above tools.
2. System prompt: "You help find and analyze machine learning datasets. Explain dataset structure, suggest use cases, and help with data preparation."

Sample prompts: find dataset, dataset preview, data analysis (column descriptions, quality assessment, preprocessing recommendations).

**Cost estimate:** $0.02–$0.08 per dataset analysis.

---

## 6. Developer & Technical Integration

Advanced use cases for developers and technical users.

**Enhanced with Pro toolkits 🔒:**
- **Site Creator Toolkit** (`site-creator-toolkit`) — WordPress automation (see [§11](#11-site-building-automation))
- See also: [§Roadmap — AI Tool Builder Toolkit](#roadmap--upcoming-toolkits) (Phase 2.9, coming soon)

### Professional templates for developers 🎓

Technical profession templates available out of the box: Software Developer, Web Developer, Data Scientist, Systems Administrator, IT Support Specialist, Database Administrator, Computer Scientist, Cloud Architect, DevOps Engineer, Cybersecurity Specialist, and more.

---

### Use Case 6.0: Toolkit MCP Servers (NEW)

**Shipped:** 1.1.18 (Phase 7 Admin UI). **Architecture:** ADR-002.

**Problem:** You want an external MCP client (LM Studio, Claude Desktop, a custom agent) to talk to a *subset* of NV oOS rather than the full plugin surface, optionally with toolkit-scoped credentials and rate limits.

**Solution:** Expose any toolkit as a standalone MCP server with its own bearer-token namespace.

#### Quickstart (15 minutes)

1. **Admin → NV oOS → Toolkit MCP Servers**
2. **Pick a toolkit** to expose (e.g. `ecommerce`, `analytics`, `crm`, `social-media`).
3. **Generate a toolkit credential** — format `cred_xxxxx.SECRET` (shown only once; store securely).
4. **Configure the external client** with the toolkit-specific endpoint:

   ```json
   {
     "url": "https://yoursite.com/wp-json/mcp-ai/v1/toolkits/<toolkit-slug>",
     "transport": "sse",
     "auth": { "type": "bearer", "token": "cred_xxxxx.SECRET" }
   }
   ```

5. **Verify** in the admin: tool discovery should list only the chosen toolkit's tools.

**Why this matters:** A toolkit-scoped credential cannot accidentally call admin-only tools from other toolkits. Useful for vendor integrations where you want to expose `crm.*` to a sales tool without granting it the keys to `regulatory-registration.*`.

**Reference:** `docs/ADR_002_toolkit_mcp_servers.md`.

---

### Use Case 6.1: Remote MCP Client Integration

**Problem:** Connect external AI applications to the *whole* WordPress instance.

**Solution:** The MCP protocol server at `wp-json/mcp-ai/v1` (full surface).

#### Prerequisites
- Understanding of MCP protocol
- Client application (LM Studio, Claude Desktop, Codex, custom)

#### Quickstart (30 minutes)

**Step 1: Generate assistant credentials.**

1. Create an assistant: AI Assistants → Add New, title "Remote API Assistant", enable desired tools.
2. Scroll to "API Credentials" meta box, click "Generate Credential".
3. Copy the credential (`cred_xxxxx.SECRET`) — shown only once.

**Step 2: Configure LM Studio.**

```json
{
  "url": "https://yoursite.com/wp-json/mcp-ai/v1",
  "transport": "sse",
  "auth": { "type": "bearer", "token": "cred_xxxxx.SECRET" }
}
```

**Step 3: Test.** In LM Studio, load a model and try tool calls like *"Search WordPress content for posts about [topic]"* or *"Create a new post titled [title]"*.

**Alternative: Claude Desktop.** Edit `~/.config/claude/config.json`:

```json
{
  "mcpServers": {
    "wordpress": {
      "url": "https://yoursite.com/wp-json/mcp-ai/v1",
      "transport": "sse",
      "auth": { "type": "bearer", "token": "cred_xxxxx.SECRET" }
    }
  }
}
```

Restart Claude Desktop after the change.

**Reference:** `docs/reference/api/mcp-server-authentication.md`, `docs/reference/api/mcp-client-configurations.md`.

---

### Use Case 6.2: Mesh Networking & Distributed Computing

**Problem:** Distribute AI workload across multiple WordPress sites.

**Solution:** Mesh networking with intelligent routing and load balancing.

#### Required Tools
- `query_remote_site` — execute on a specific peer
- `query_mesh_intelligent` — auto-route with failover

#### Prerequisites
- Multiple WordPress sites with NV oOS installed
- Mesh networking enabled (Settings → NV oOS → Federation)
- Inter-site keys configured

#### Quickstart (45 minutes)

1. **Enable mesh networking on each site:** Settings → NV oOS → Federation → Enable Mesh Networking → generate key → register peers.
2. **Configure federation discovery** (optional directory service URL, health checks, region/capability tags).
3. **Create mesh coordinator assistant** with `query_remote_site` and `query_mesh_intelligent`.
4. **Use:** direct routing (`"Query peer site [URL]..."`), intelligent routing (`"Process this large dataset using mesh computing..."`), load balancing (`"Generate 100 product descriptions. Distribute across mesh network."`).

**Cost estimate:** Distributed across peer sites based on usage.

**Reference:** `docs/features/federation/mesh-compute-pooling.md`, `docs/features/federation/federation-discovery.md`.

---

### Use Case 6.3: Custom Tool Development

**Problem:** Need specialized tools for unique workflows.

**Solution:** Develop custom PHP tools using the tool registry, following the **Unix Theory Compliance P0–P6** rules introduced in 1.1.18.

#### The two non-negotiable rules

Both rules are enforced by PHPCS sniffs at severity 5 — your CI will fail if you skip them.

1. **Canonical return envelope.** `execute()` must return either a success array or a `WP_Error`. **Never** return `array( 'success' => false, ... )` — wrap failures in `WP_Error`. (Sniff: `WPMCPAI.Tools.CanonicalReturnEnvelope`.)
2. **Two-gate sanitisation.** Sanitise every value out of `$arguments[...]` at entry; escape every value back into output at exit. (Sniff: `WPMCPAI.Tools.SanitizeAtEntry`.)

See `CLAUDE.md` → "Tool Return Format — Canonical Envelope" and "Tool Sanitisation — Two-Gate Rule" for the rationale and examples.

#### Quickstart (60 minutes)

```php
<?php
// includes/tools/class-wp-mcp-ai-tool-my-custom-tool.php

class WP_MCP_AI_Tool_My_Custom_Tool extends WP_MCP_AI_Tool_Base {

    public function get_slug() {
        return 'my_custom_tool';
    }

    public function get_definition() {
        return array(
            'name'                => 'My Custom Tool',
            'description'         => 'Does something specific',
            'required_capability' => 'edit_posts',
            'parameters'          => array(
                'type'       => 'object',
                'properties' => array(
                    'input' => array(
                        'type'        => 'string',
                        'description' => 'Input parameter',
                    ),
                ),
                'required'   => array( 'input' ),
            ),
        );
    }

    public function execute( $arguments, $context ) {
        // Capability check.
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error(
                'permission_denied',
                __( 'Permission denied', 'mcp-ai-wpoos' )
            );
        }

        // Gate 1: sanitise at entry.
        $input = isset( $arguments['input'] ) ? sanitize_text_field( $arguments['input'] ) : '';

        if ( '' === $input ) {
            // Failures wrap in WP_Error (canonical envelope rule).
            return new WP_Error(
                'invalid_input',
                __( 'Input is required', 'mcp-ai-wpoos' )
            );
        }

        $result = $this->process_input( $input );

        // Gate 2: escape at exit.
        return array(
            'success' => true,
            'result'  => esc_html( $result ),
        );
    }

    private function process_input( $input ) {
        return 'Processed: ' . $input;
    }
}
```

**Register the tool:**

```php
add_filter( 'wp_mcp_ai_register_tools', function( $tools ) {
    require_once __DIR__ . '/tools/class-wp-mcp-ai-tool-my-custom-tool.php';
    $tools[] = new WP_MCP_AI_Tool_My_Custom_Tool();
    return $tools;
} );
```

**Test:** Settings → NV oOS → Tools → verify "My Custom Tool" appears → enable for an assistant → test in chat.

**Reference:** `docs/guides/developer/tool-development/TOOL_UPDATE_GUIDE.md`, `docs/reference/tools/tool-reference.md`.

---

### Use Case 6.4: Memory Mining from Transcripts (NEW)

**Shipped:** 1.1.18 (via the inline-async-tick pattern — works correctly on hosts with `DISABLE_WP_CRON=true` or a firewalled `wp-cron.php` loopback).

**Problem:** Past chat transcripts contain useful long-term memories (preferences, recurring tasks, domain facts), but mining them by hand is impractical.

**Solution:** The `WP_MCP_AI_Transcript_Mining_Job` background job (admin → **Mine Memories from Transcripts**) walks recent transcripts, extracts candidate memories, and stores them via the agent-memory tools so future assistants can recall them with `retrieve_agent_memory`.

#### Why this needed an inline-async-tick fix

Previously, jobs sat at `Status: queued, Progress: 0/1` indefinitely on hosts where `spawn_cron()` cannot dispatch its loopback HTTP request. As of 1.1.18 the job:

- Registers a `shutdown` action that flushes the REST response, detaches the worker (`fastcgi_finish_request()` + `ignore_user_abort()`), and runs the first tick in-process when state is still `queued`.
- Holds a two-layer cooperative lock (object cache + transient) so the shutdown worker and a delayed cron loopback cannot double-process a batch.
- Runs subsequent batches inline when `DISABLE_WP_CRON` is true, bounded by a 20s wall-clock budget per request.
- The REST poll endpoint `GET /mcp-ai/v1/transcript-mining/jobs/{id}` self-heals: if a job has been `queued` for >5s it queues an inline kick after the response is flushed.

#### Quickstart (10 minutes)

1. **Admin → AI Assistants → Memory → Mine Memories from Transcripts.**
2. **Pick the assistant(s)** whose transcripts you want to mine.
3. **Click "Start mining".** The Jobs/Tasks Drawer (also new in 1.1.18) shows live progress.
4. **Review extracted memories** in the assistant's Memory tab (Memories / Scope / Audit). Approve or reject each candidate.
5. **Verify the round-trip** by asking the assistant a question whose answer depends on a newly-approved memory; the response should cite the memory in the inline annotation pill.

#### Hooks

- `wp_mcp_ai_inline_kick_enabled` (default `true`, per-job filterable) — operator escape hatch if you want to force pure-cron behaviour.
- `wp_mcp_ai_inline_kick_completed` action — fires once per inline kick with `( $class, $job_id, $duration_ms, $success )`; Pro OTel bootstrap records `inline_kick.duration_ms` / `inline_kick.failure.count`.

**Reference:** `docs/architecture/inline-async-tick-pattern.md`, `docs/RAG-ENHANCED-MEMORY-MANAGEMENT.md`.

---

## 7. Education & Knowledge Management

Specialized assistants for education and training.

### Use Case 7.1: IGCSE Curriculum Support

**Problem:** Curriculum-specific learning assistants for students.

**Solution:** Pre-built IGCSE profession templates and six teams covering all subjects.

#### Available IGCSE teams 🎓

NV oOS includes **6 specialized IGCSE teams** aligned with Cambridge IGCSE syllabi:

- **Mathematics team** — Mathematics Tutor, Research Specialist, Teaching Assistant
- **Science team** — Science Tutor, Research Specialist, Laboratory Assistant
- **Humanities team** — History Tutor, Geography Tutor, Social Studies Tutor
- **Languages & Technology team** — English Language Tutor, Computer Science Tutor, Foreign Language Tutor, ICT Specialist
- **Year-Level Support team** — academic advisors and multi-subject tutors
- **Academic Support team** — study skills, exam prep, research assistance

#### Quickstart (10 minutes)

1. **Teams → IGCSE Teams** (or Teams → Add Team) → select team → "Deploy Team"
2. Configure team-wide settings: provider (OpenAI or Gemini), model (`gpt-4o-mini` is cost-effective for education), temperature 0.5
3. Click "Deploy" — all team members created automatically with subject expertise, curriculum alignment, appropriate tools and knowledge bases
4. **Customize for your school:** teaching philosophy, school resources, curriculum modifications, homework policy, assessment criteria

**Create a student portal** using either the legacy `[mcp_ai_chat]` shortcode or the new Chat SPA shortcode `[nvoos_chat_spa]` (see [§13](#13-front-end-chat-delivery-chat-spa)).

**Backend testing:** Teams → Test Team → select your deployed IGCSE team → test each member.

**Cost estimate:** $0.02–$0.05 per tutoring session.

**Reference:** `docs/implementation-history/2025/summaries/IGCSE_IMPLEMENTATION_SUMMARY.md`.

---

### Use Case 7.2: Corporate Training & Knowledge Base

**Problem:** Onboard new employees and provide instant access to company knowledge.

**Solution:** Knowledge-base assistant with company documentation.

#### Required Tools
- `search_content` — internal documentation search
- `search_attachments` — file retrieval

#### Quickstart (20 minutes)

1. **Upload documentation** (employee handbook, process docs, training materials, FAQs) to the Media Library.
2. **Create assistant** using "Training Coordinator" or "Technical Writer" profession template; enable `search_content` + `search_attachments`; add all docs to base knowledge.
3. **Customize for your business:** company culture, internal processes, terminology, response formats.
4. **Test in backend** before deploying: "What is our vacation policy?", "How do I submit an expense report?", "What are the steps for [process]?"
5. **Deploy** on an internal "Employee Resources" page (restrict to logged-in users; link from intranet).

**Cost estimate:** $0.01–$0.03 per employee query.

---

### Use Case 7.3: Interactive Learning & Quizzes

**Problem:** Create engaging educational content and assessments.

**Solution:** AI-generated quizzes, flashcards, and practice questions.

#### Quickstart (15 minutes)

1. Create "Quiz Generator" assistant; enable `search_content`, `save_post`.
2. System prompt: "You create educational quizzes and practice questions. Questions should be clear, age-appropriate, and aligned with learning objectives. Include answer explanations."

Sample prompts:

```
Quiz:     "Create a 10-question multiple choice quiz on [topic] for [grade level].
           Include answer key with explanations."
Flashcards:"Generate 20 flashcards for memorizing [subject]."
Problems:  "Create 5 practice problems on [math topic] with step-by-step solutions."
Study guide:"Comprehensive study guide for [subject] covering [topics]."
```

**Cost estimate:** $0.03–$0.10 per quiz/study guide.

---

### Use Case 7.4: Bundled Skill Packs (NEW)

**Shipped:** 1.1.18. Base plugin skill count went from 44 → 45 with the addition of the first big bundled skill pack.

**Problem:** Some assistants need a *deep* domain pack (color systems, typography, framework-specific guidelines) that's too large and structured to ship as a system prompt or knowledge-base file.

**Solution:** The **Skill Pack Registry** (`WP_MCP_AI_Skill_Pack_Registry`) lets the plugin distribute curated, machine-readable skill data. First bundled pack:

#### UI/UX Pro Max (`ui-ux-pro-max`)

Bundled at `includes/bundled-skills/ui-ux-pro-max/`. Data files include:

- **Color palettes** — `data/colors.csv`
- **Typography scales** — `data/typography.csv`, `data/google-fonts.csv`
- **Icon libraries** — `data/icons.csv`
- **UI components** — `data/app-interface.csv`, `data/design.csv`
- **UX guidelines + reasoning** — `data/ux-guidelines.csv`, `data/ui-reasoning.csv`
- **Stack-specific guidelines** for React, Vue, Angular, Laravel, Astro, Svelte, Flutter, SwiftUI, Jetpack Compose, and more (`data/stacks/*.csv`)

Plus Python utility scripts under `scripts/` for design-system operations (`core.py`, `design_system.py`, `search.py`).

#### Quickstart (5 minutes)

1. **Admin → AI Assistants → edit a design-oriented assistant** (e.g. one created from the "Graphic Designer" or "Web Developer" template).
2. **Skills tab → toggle on "UI/UX Pro Max"** under the `ui-ux-design` skill pack.
3. **Test:** ask the assistant for "an accessible color palette for a healthcare dashboard with WCAG AA contrast, in Tailwind tokens." The response should cite specific palette IDs from `colors.csv`.
4. **(Optional) Author your own skill pack:** add a directory under `includes/bundled-skills/<slug>/`, drop CSV/Markdown data files, register via the `wp_mcp_ai_register_skill_packs` filter. See `THIRD_PARTY_NOTICES.md` in the bundled-skills directory for attribution patterns.

**Reference:** `tests/test-skill-pack-registry.php` for the integration contract.

---

## 8. Multi-Agent Orchestration

**Stable since 1.1.9.** Create multi-agent teams that collaborate via role-based orchestration, intelligent delegation, and result aggregation.

### Use Case 8.1: Complex Research Project with Agent Teams

**Problem:** Comprehensive research requiring multiple specialized perspectives.

**Solution:** A multi-agent research team with roles (Planner, Research Specialist, Data Analyst, Critic, Writer).

#### Key orchestration tools

**Agent team management:** `create_agent_team`, `delegate_to_agent`, `aggregate_agent_results` (5 strategies), `execute_workflow`.

**Agent memory:** `store_agent_context` (10 context types, TTL 1 hour – 1 year), `retrieve_agent_memory` (semantic search).

**Roles:** Planner, Executor, Critic, Specialist.

#### Quickstart (15 minutes)

1. AI Assistants → Add New, title "Research Team Orchestrator", enable the orchestration tools above plus the two memory tools.
2. System prompt: "You are a research team orchestrator. You coordinate multiple specialist agents to conduct comprehensive research. Create teams, delegate tasks, review results, and synthesize findings into cohesive reports. Use agent memory to track progress and insights."
3. Recommended model: `gpt-4.1` or `gpt-5.1` (orchestration needs solid reasoning). Temperature 0.4.

**Sample request:**

```
"Create a research team to investigate [topic]:
- 1 Planner for research strategy
- 2 Research Specialists for data gathering
- 1 Data Analyst for quantitative analysis
- 1 Critic to review methodology
- 1 Content Writer for final synthesis
Store the team structure in agent memory for reference."
```

**Expected execution:** team created → planner develops methodology → delegates to specialists → analyst processes findings → critic validates → results aggregated (weighted or consensus) → writer synthesizes → insights stored. 15–25% faster than sequential.

**Cost estimate:** $0.20–$0.50 per complex research project.

#### Agent memory context types

10 context types with configurable TTL: `research_findings`, `task_progress`, `agent_decisions`, `quality_metrics`, `user_preferences`, `team_structure`, `delegation_history`, `aggregation_rules`, `workflow_state`, `insights`.

#### Aggregation strategies

- **Weighted** — priority-based combination (e.g. expert opinions weighted higher)
- **Consensus** — democratic voting among agents
- **Sequential** — chain agent outputs (A→B→C)
- **Parallel-merge** — combine parallel work streams
- **Best-of-N** — select highest-quality result

**Reference:** `docs/ORCHESTRATION_REFERENCE.md`.

---

### Use Case 8.2: Enterprise Content Pipeline with Role-Based Agents

**Problem:** High-volume, high-quality content with consistent editorial standards.

**Solution:** Multi-agent content team: research, writing, editing, SEO optimization, fact-checking.

#### Quickstart (20 minutes)

1. Use "Content Writer" profession template as base.
2. Add orchestration tools (`create_agent_team`, `delegate_to_agent`, `aggregate_agent_results` with `sequential`, `store_agent_context`) and content tools (`search_content`, `web_search`, `save_post`, `get_rankmath_seo`).
3. System prompt: "You orchestrate a content production team. Create specialized agents for research, writing, editing, SEO, and fact-checking. Execute in parallel where possible, sequential where needed (write→edit→SEO). Store editorial guidelines in agent memory for consistency."

**Sample:** `"Produce 3 articles on [topics A, B, C] using the content pipeline team. Writers in parallel; editor → SEO → fact-checker sequential. Publish when complete."` Expected: ~30 min vs ~45 min sequential (33% faster), $0.30–$0.40 for 3 articles.

---

## 9. Advanced Workflow Automation

**Stable since 1.1.9.** Multi-step workflows with parallel execution, dependency management, automatic retry, and state persistence.

### What's included

- **Parallel execution** — up to 3 simultaneous tasks
- **Dependency management** — task prerequisites and sequencing
- **Deadlock detection** — automatic circular-dependency resolution
- **State persistence** — resume workflows after interruption
- **Exponential-backoff retry** — automatic recovery from transient failures
- **Performance** — 15–25% faster execution vs sequential

### Use Case 9.1: Automated Blog Publishing Workflow

**Problem:** Publishing requires research, writing, image generation, SEO optimization, scheduling.

**Solution:** Workflow coordinator executes tasks in optimal order with parallel processing where possible.

#### Quickstart (15 minutes)

1. Create "Blog Publishing Workflow" assistant; enable `execute_workflow` (load balancer + 15-min result cache run automatically); enable content tools (`web_search`, `generate_image`, `save_post`, `get_rankmath_seo`).
2. Model: `gpt-4o-mini` or `gpt-4.1-nano` — sufficient for automation.

**Sample workflow:**

```
Tasks:
1. Research:  web_search for current information
2. Write:     1200-word article
3. Images:    generate_image (parallel with #2)
4. SEO:       get_rankmath_seo analysis and optimization
5. Publish:   save_post with scheduling

Dependencies:
- Write depends on Research
- SEO depends on Write
- Publish depends on SEO + Images

Execute with parallel processing where possible.
```

**Expected timeline:** Research (5s) → Write (20s) + Images (20s) [parallel] → SEO (8s) → Publish (3s) = ~36s vs ~56s sequential (36% faster). Cache hits ~30–40% on repeated topics.

**Cost estimate:** $0.08–$0.15 per automated post.

---

### Use Case 9.2: E-Commerce Product Import & Optimization 🔒

**Requires Pro E-Commerce toolkit.**

#### Quickstart (20 minutes)

1. Enable E-Commerce toolkit (Settings → NV oOS → Pro Toolkits).
2. Create "Product Import Automation" assistant; enable e-commerce tools (`create_product`, `update_product_meta`, `generate_product_description`, `optimize_product_seo`, `assign_product_category`), workflow tools, media tools.

**Sample:** *"Import 10 products from CSV. For each: create → description → images (parallel) → SEO (parallel with images) → categories → pricing & inventory. Process 3 in parallel."* Expected: ~4 min vs ~10 min sequential (60% faster), $0.20–$0.30 for 10 products.

---

## 10. Video Production & Transcoding

**🔒 Requires Pro Video Production toolkit.** FFmpeg-based; the toolkit orchestrates FFmpeg locally so there are no per-second video-processing API costs.

### What's included

**Pro Video Production toolkit:**
- `transcode_video` — format conversion (MP4, WebM, AVI, MOV)
- `extract_video_frames` — frame extraction for thumbnails
- `merge_video_clips` — combine multiple videos
- `add_video_watermark` — branding and copyright
- `extract_audio` — audio extraction from video
- `generate_video_thumbnail` — custom thumbnails
- `compress_video` — size optimization
- `apply_video_filter` — color grading, effects
- `trim_video` — precise cutting
- `add_video_subtitle` — caption integration
- `video_metadata` — format details and analysis
- `batch_video_process` — bulk operations

### Use Case 10.1: Automated Video Content Workflow

#### Quickstart (15 minutes)

1. **Enable Video Production toolkit.** Verify FFmpeg installed (`ffmpeg -version`).
2. **Create video assistant** using the "Video Editor" profession template; enable all toolkit tools and workflow tools.
3. System prompt: "You process videos for web deployment. Transcode to web-friendly formats, compress for faster loading, generate thumbnails, create social variants. Process multiple videos in parallel."
4. Model: `gpt-4o-mini` or `gpt-4.1-nano`.

**Sample workflows:**

```
Single:  "Process [filename]: transcode to MP4 H.264 for web,
          compress under 50MB, generate 3 thumbnails at different timestamps,
          extract audio track, create 1:1 social variant ≤60s."

Batch:   "Process all videos in uploads folder: transcode if needed,
          generate thumbnails, compress >100MB, extract metadata. 3 parallel."
```

**Performance:** single video 3–5 min; batch (10 videos, 3 parallel) ~15 min; metadata cached 15 min.

**Cost estimate:** Assistant coordination $0.05–$0.10 per video; server processing free (FFmpeg).

---

## 11. Site Building Automation

**🔒 Requires Pro Site Creator toolkit** (`site-creator-toolkit`). Automate WordPress site building, theme customization, plugin configuration, and content population.

### What's included

The Site Creator toolkit covers site structure (page hierarchy, navigation menu, homepage template, sidebars), theme & design (theme install, customizer automation, logo upload, color schemes, typography), content population (demo content import, blog structure, footer, contact page), plugin management (plugin packs, SEO, caching, security), and advanced setup (CPTs, WooCommerce init, multilingual, user roles, SMTP, backups, database optimization, CDN, sitemaps).

### Use Case 11.1: Rapid Site Deployment from Template

#### Quickstart (25 minutes)

1. **Enable Site Creator toolkit.**
2. **Create site builder assistant** using the "Web Developer" profession template; enable all Site Creator tools + workflow tools.
3. System prompt: "You build complete WordPress sites from specifications. Install themes, configure plugins, create page hierarchy, populate content, optimize performance. Follow WordPress best practices for security and SEO."
4. Recommended model: `gpt-4.1` or `gpt-5.1` (complex orchestration).

**Sample request:** corporate website with hero/services/portfolio/blog/contact pages, Astra theme, Rank Math + WPForms + Smush, blue branding, top menu, 3-column footer, Rank Math sitemap, caching + image optimization. Execute with parallel processing.

**Expected timeline:** ~18 min total (vs ~45 min manual): theme install (2 min) → plugin install (3 min, parallel with theme customization) → page hierarchy (3 min) → content population (5 min, 3 pages parallel) → menu/footer (2 min) → SEO/performance (3 min).

**Cost estimate:** $0.30–$0.40 for complete site build.

**Variations:** multi-site deployment, e-commerce site setup, membership site.

---

## 12. Regulatory Compliance

**🔒 Requires Pro Regulatory Registration toolkit** (`regulatory-registration`).

### What's included

Compliance documentation (SDS/MSDS, technical specs, certificates, test reports, warning labels), regulatory research (jurisdiction lookup, cert status, standards search, restrictions), standards verification (ISO, CE, FDA, RoHS, REACH), registration workflows (application submission, status tracking, supporting docs, fee calculation), and documentation management (repository, audit trail, status reports, renewal scheduling). Plus industry-specific tools for medical devices, chemicals, electronics, food & beverage.

### Use Case 12.1: Product Compliance Documentation Automation

#### Quickstart (30 minutes)

1. **Enable Regulatory Registration toolkit.**
2. **Create compliance assistant**; enable all regulatory tools + `search_content` + `save_post`.
3. System prompt: "You manage product regulatory compliance. Generate compliance documentation, verify certification requirements, track registration status, ensure standards adherence. Always cite specific regulatory standards and provide evidence trails."
4. Recommended model: `gpt-4.1` or `gpt-5.1` (accuracy critical). Temperature 0.2.

**Sample request:** SDS (OSHA GHS format), technical spec, CE marking assessment, FDA requirements, RoHS compliance, REACH pre-registration, warning labels, compliance certificate. Storage with audit trail.

**Cost estimate:** $0.30–$0.60 per comprehensive compliance workflow. 70–80% time reduction vs manual.

**⚠️ Important compliance disclaimer:** AI-generated regulatory and compliance documentation should **always** be reviewed by qualified regulatory professionals before submission or use. Regulatory requirements change frequently and vary by jurisdiction. This toolkit assists with documentation generation but does not replace professional regulatory expertise or legal counsel.

---

## 13. Front-End Chat Delivery (Chat SPA)

**Shipped:** 1.1.17 (Phases 1–7, v0.6.0). Addon path: `addons/chat-spa/`. Bundle size: ~81.3 KB gzipped (limit 350 KB).

**Problem:** The legacy `[mcp_ai_assistant]` shortcode is jQuery-based, lacks first-class memory / approvals UI, and is harder to theme.

**Solution:** A React replacement built on Vercel AI SDK UI with a custom SSE→Data Stream Protocol adapter (`src/sse-adapter.ts`). Exposes a new shortcode and an admin embed page.

### What you get out of the box

| Phase | Feature |
|---|---|
| 1 | `@ai-sdk/react` `useChat` integration with custom fetch + client-side SSE adapter |
| 2 | Collapsible tool-call cards (rendered from `message.toolInvocations`), inline annotation pills (`memory_event`), admin embed page (`WP-Admin → NV oOS Chat`, `manage_options`) |
| 3 | Transcripts sidebar (load/save/delete via `mcp-ai/v1/chat-transcripts`); `useTranscriptSession` hook; guest mounts skip sidebar |
| 4 | Memory drawer with three tabs (Memories / Scope / Audit); wing/room scope persisted in `localStorage` |
| 5 | HITL approval bar polling `/mcp-ai/v1/approvals` every 6 s during streaming; only rendered for `manage_options` users |
| 6 | File attachments via `useAttachments` (5 MB per file, 10 MB total, 10 files max) + thumbnail strip; `↺` regenerate via `reload()`; `✏` edit + re-submit via `setMessages` truncation |
| 7 | `WP_MCP_AI_LEGACY_CHAT_JS` constant in `includes/bootstrap/constants.php` (default `true`) gates the legacy shortcode |

### Quickstart (5 minutes)

1. **Build / ensure the addon is shipped** — `addons/chat-spa/` is bundled in the standard build.
2. **Place the shortcode on any page:**

   ```
   [nvoos_chat_spa assistant_id="123" theme="light" height="600px" guest="false"]
   ```

   Attributes:
   - `assistant_id` (required) — the assistant CPT ID
   - `theme` — `light` | `dark` | `auto` (default `auto`)
   - `height` — any CSS height (default `600px`)
   - `guest` — `true` enables guest tokens and hides the transcripts sidebar

3. **Admin preview:** Admin → **NV oOS Chat** (requires `manage_options`).
4. **Disable the legacy chat shortcode** when you're ready: `define( 'WP_MCP_AI_LEGACY_CHAT_JS', false );` in `wp-config.php`. The migration guide is at `addons/chat-spa/MIGRATION.md` (blueprint §20).

### Why this matters for the rest of this document

Wherever you see `[mcp_ai_chat assistant="[ID]" allow_guests="true"]` in §2.2, §7.1, §7.2, you can swap it for `[nvoos_chat_spa assistant_id="[ID]" guest="true"]` without changing any of the assistant-side configuration.

**Cost estimate:** Front-end delivery is free; cost is whatever the underlying assistant consumes.

---

## 14. In-WP Documentation Viewer (Docs Hub)

**Shipped:** 1.1.17 (v0.1.0 → v0.3.8). Addon path: `addons/docs-hub/`.

**Problem:** Operators need to read project docs (this guide, ADRs, troubleshooting) without leaving WP-Admin or remembering GitHub URLs.

**Solution:** An admin SPA that fetches Markdown from one or more remote repos, renders it with syntax highlighting and a sitemap-backed router, and exposes a "Edit on GitHub" footer for each page.

### Highlights (cumulative through v0.3.8)

- **Remote-first defaults** + tree-picker UX for choosing which repo to mount
- **Chunked rebuild** with a progress API and a `wp docs-hub rebuild` CLI subcommand (uses the inline-async-tick pattern, so rebuilds fire on shutdown of the request that calls `enqueue()` instead of waiting 5+ s for cron)
- **Same-repo GitHub blob links** routed through the SPA; other external links open in a new tab
- **Hash anchors** no longer corrupt `HashRouter`; `scrollIntoView` added for in-page links
- **Defensive `remote_repos` coercion** — non-array rows are filtered
- **SSRF hardening** — `safe_get()` resolves the destination IP via DNS A/AAAA and refuses any private/reserved record
- **a11y** — ARIA root attrs, skip-link, `prefers-reduced-motion` support
- **Syntax highlighting** via `rehype-highlight` + `lowlight`
- **PageFooter component** — last-modified timestamp + edit-on-GitHub link
- **`NV_oOS_Docs_Hub_Sitemap_Provider`** registers as a `WP_Sitemaps_Provider` so the docs surface is discoverable
- **Bundle-size CI gate** — `spa-bundle-size.yml` limit 250 KB, actual ~204 KB

### Quickstart (10 minutes)

1. **Activate the Docs Hub addon** (ships with the standard build).
2. **Admin → Docs Hub → Settings → Remote Repos** — paste the repo slug (e.g. `nvdigitalsolutions/mcp-ai-wpoos`) and choose the branch. The tree picker walks the repo and lets you mount one or more sub-directories (e.g. just `docs/`).
3. **Click "Rebuild"** — progress appears immediately because of the inline-async-tick fallback. On hosts with `DISABLE_WP_CRON` this still completes in one request rather than hanging.
4. **Browse** Admin → Docs Hub. Try a deep link with a `#section-anchor`; it should scroll, not 404.
5. **(Optional) Force-refresh a path:** `?force=1` invalidates the local cache for that file.

**Cost estimate:** Free at display time. The only consumed resource is the GitHub API quota for the rebuild fetches.

**Reference:** `addons/docs-hub/README.md`.

---

## Pro Features & Toolkits

**🔒 Enhanced capabilities for professional & enterprise use.**

NV oOS Pro extends the base system with toolkits that group related tools, REST routes, settings pages, and (for the GA ones) an SPA shell.

### What is NV oOS Pro?

**Base version:** ~195 tools (registry-authoritative; reconciled in `readme.txt` 1.1.18). Covers content, e-commerce basics, media generation, business operations, research, education, developer integration, multi-agent orchestration, workflow automation.

**Pro version (🔒):** ~635 additional tools across multiple toolkits. Adds advanced integrations, GA SPA dashboards, priority support, extended security/compliance reviews, and enterprise-grade features. Live total: **~830 tools** combined.

### GA Pro toolkits (SPA-manifested)

These 10 toolkits ship with full SPA shells and are mounted under `mcp-ai-pro/v1/<toolkit>/...`:

| Toolkit | Slug | Primary use cases | Referenced in |
|---|---|---|---|
| Analytics | `analytics` | Reports, dashboards, cross-source data | §4 |
| Calendar & Booking | `calendar-booking` | Appointments, availability, reminders | §4 |
| CRE Debt | `cre-debt` | Commercial real estate debt workflows | Industry-specific |
| CRM | `crm` | Customer relationships, contacts, deals | §2, §4 |
| E-commerce | `ecommerce` | WooCommerce automation, product mgmt, orders | §2, §9 |
| Financial Planner | `financial-planner` | Budgets, investments, tax calculations | §4 |
| Law Firm | `law-firm` | Matters, clients, time tracking | Industry-specific |
| Multilingual | `multilingual` | Translation, localization, multi-lingual content | §1 |
| Regulatory Registration | `regulatory-registration` | Compliance docs, certifications, standards | §12 |
| Social Media | `social-media` | Cross-platform metrics, engagement, competitor tracking | §4 |

### Additional Pro toolkit settings pages

The 10 GA toolkits above sit alongside ~34 additional settings-page toolkits at varying stages of SPA migration: `architect-agent`, `architectural-design`, `architectural-drawing`, `architectural-project`, `architectural-specification`, `chat-channels`, `dj-management`, `document-generation` (+ CPT variant), `eca`, `event`, `image-production` (+ CPT variant), `media`, `media-toolkit`, `member`, `nv-cloud`, `page`, `place`, `policy`, `post`, `pro-packages`, `pro-schedule-toolkit`, `product`, `project`, `project-management-toolkit`, `quiz`, `reg-product`, `registration`, `regulatory-product-cpt`, `regulatory-registration-toolkit`, `site-creator-toolkit`, `video-production`, `financial-planner-cpt`. See the [fact sheet §4.2](_USE_CASES_FACT_SHEET.md#4-pro-toolkits) for the full enumeration.

> Tool counts per toolkit fluctuate as features land. The live `WP_MCP_AI_Tool_Registry::get_tools()` is the canonical source — historical fixed counts (e.g. "Video Production 12 tools") were removed from this doc because they go stale within a release or two.

### When do I need Pro?

**Use base when:** content creation, basic e-commerce, image generation, research, general business ops, education, general developer integration.

**Upgrade to Pro when** you need WooCommerce automation, cross-platform social analytics, FFmpeg-driven video pipelines, financial modelling, regulatory documentation, multi-site building automation, CRM, or any of the industry verticals (CRE debt, law firm, architectural).

### How to activate Pro toolkits

```
1. Settings → NV oOS → Pro Toolkits
2. Enter Pro license key (if not already activated)
3. Enable desired toolkits individually
4. Click "Save Changes"
5. Toolkits activate immediately; tools appear in assistant configuration
```

**Flexible licensing:** activate only the toolkits you need.

### Pro Dashboard 🔒

Real-time monitoring of agent orchestration events, workflow execution timeline, tool load-balancing metrics, memory storage/retrieval tracking, cache hit rates, performance — auto-refresh every 30 seconds. Plus advanced analytics: 24-hour timeline charts, event filtering and CSV export, per-assistant performance metrics, cost tracking by toolkit, usage pattern analysis.

Access: Admin → NV oOS → Pro Dashboard.

### Pricing & support

Pricing details: [NV Digital Solutions](https://nvdigitalsolutions.com/wpoos). A 14-day Pro trial with full access is typically available.

Pro support: priority ticket response, dedicated channel, video call support, custom toolkit development consultation.

### Compliance posture

> **Compliance is documented separately and audit-dated.** This document does not restate percentages; consult the per-standard posture documents in `docs/` for current status:

- WP.org plugin guidelines — hardened in 1.1.17; see `docs/03-wp-org-compliance.md`, `docs/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`.
- HIPAA — `docs/HIPAA_POSTURE.md`.
- Other standards (ISO 27001, SOC 2) — search `docs/` for the relevant audit document before quoting any percentage compliance claim.

---

## Cost Considerations

Managing AI costs matters once you scale beyond personal use.

### Token pricing by provider

> **Prices below are seeded from `includes/data/model-catalog.json` v `2026.05.04`** (which is what plugin 1.1.18 ships with). Live values may differ — check **Settings → Models** or the `wp_mcp_ai_model_catalog` filter. All prices are per **1 million tokens** unless noted.

#### OpenAI

| Model | Input | Output | Use case |
|---|---|---|---|
| `gpt-5.2` | $1.75 | $14.00 | Latest flagship |
| `gpt-5.1` | $1.25 | $10.00 | Smart default for orchestration/high-quality |
| `gpt-5` | $1.25 | $10.00 | High quality |
| `gpt-5-mini` | $0.25 | $2.00 | Cheap reasoning |
| `gpt-5-nano` | $0.05 | $0.40 | Cheapest reasoning |
| `gpt-5-pro` | $21.00 | $168.00 | Premium |
| `gpt-4.1` | $6.00 | $18.00 | Long-context reasoning |
| `gpt-4.1-mini` | $1.50 | $4.50 | General tasks |
| `gpt-4.1-nano` | $0.40 | $1.20 | Cost-effective baseline |
| `gpt-4o-mini` | $0.15 | $0.60 | Cheapest OpenAI tier |

#### Anthropic

| Model | Input | Output |
|---|---|---|
| `claude-haiku-4-5` | $1.00 | $5.00 |
| `claude-sonnet-4-6` | $3.00 | $12.00 |
| `claude-opus-4-6` | $5.00 | $25.00 |
| `claude-opus-4-7` | $5.00 | $25.00 |

#### DeepSeek

| Model | Input | Output | Use case |
|---|---|---|---|
| `deepseek-chat` | $0.27 | $1.10 | Cost-effective chat |
| `deepseek-reasoner` | $0.55 | $2.19 | Reasoning |
| `deepseek-coder` | $0.27 | $1.10 | Code tasks |

#### Google / Gemini

| Model | Provider entry | Input | Output |
|---|---|---|---|
| `gemini-2.5-flash` | `google` | $0.30 | $2.50 |
| `gemini-2.5-flash-lite` | `google` | $0.10 | $0.40 |
| `gemini-2.5-pro` | `google` | $1.25 | $10.00 |
| `gemini-3.1-flash` | `gemini` | $0.075 | $0.30 |
| `gemini-3.1-flash-lite` | `gemini` | $0.015 | $0.06 |
| `gemini-3.1-pro` | `gemini` | $1.25 | $5.00 |

#### DigitalOcean Serverless Inference

| Model | Notes |
|---|---|
| `llama3.3-70b-instruct`, `llama3.1-8b-instruct`, `deepseek-r1-distill-llama-70b`, `openai-gpt-oss-120b`, `gte-large-en-v1.5` | **Catalog ships these with $0 placeholder pricing.** Operators must populate per-token costs from their Gradient Platform billing via Models admin page or the `wp_mcp_ai_model_catalog` filter. |

#### Cloudflare Workers AI (LLMs)

| Model | Input | Output |
|---|---|---|
| `@cf/meta/llama-3.2-1b-instruct` | $0.027 | $0.201 |
| `@cf/meta/llama-3.2-3b-instruct` | $0.051 | $0.335 |
| `@cf/meta/llama-3.3-70b-instruct-fp8-fast` | $0.293 | $2.253 |
| `@cf/meta/llama-4-scout-17b-16e-instruct` | $0.270 | $0.810 |
| `@cf/google/gemma-3-12b-it` | $0.150 | $0.450 |
| `@cf/mistralai/mistral-small-3.1-24b-instruct` | $0.351 | $0.555 |
| `@cf/deepseek-ai/deepseek-r1-distill-qwen-32b` | $0.497 | $4.881 |

#### Kimi / Moonshot

| Model | Input | Output |
|---|---|---|
| `moonshot-v1-8k` | $12.00 | $12.00 |
| `moonshot-v1-32k` | $24.00 | $24.00 |
| `moonshot-v1-128k` | $60.00 | $60.00 |

#### Local providers (free at the API)

`ollama` (29 active models), `lm_studio` (20), `webllm` (5), `embedded` MLC (3) — all $0 in the catalog. You only pay the compute on your own hardware.

#### Image generation

The catalogue's two active image-generating models:

| Model | Provider | Input | Output | Notes |
|---|---|---|---|---|
| `imagen-4` | `gemini` | $0 (placeholder) | $0 (placeholder) | Populate from your billing |
| `gemini-2.5-flash-image` | `google` | $0 | $30.00 | $30/M output reflects per-image pricing — verify with provider docs |

> The previous revision of this document listed `flux-2-dev` and `leonardo-ai` Cloudflare image models. Neither is in the current catalog. If/when those routes are added, update this table from the catalog rather than from memory.

#### Other operations

| Operation | Cost | Notes |
|---|---|---|
| Image generation (DALL-E 3) | $0.04–$0.12 | Size dependent (verify in OpenAI pricing) |
| Audio transcription (1 min) | $0.006 | Whisper API |
| Text-to-speech (1000 chars) | $0.015 | OpenAI TTS API |
| FFmpeg video processing 🔒 | $0.00 | Server resources only |

### Cost-optimization features

**Result caching (default behaviour):**

- 15-minute TTL on tool results
- ~30–40% cache hit rate on repeated operations
- Automatic invalidation on mutating tool calls

**Tool load balancer (default behaviour):**

- Capacity-aware tool routing
- Prevents redundant API calls in parallel workflows
- Optimizes parallel execution

**Reasoning mode (default behaviour):**

- Task-complexity detection
- Uses cheaper models when possible
- Escalates to reasoning models only when needed

> The previous revision quoted specific percentage savings (`25–35%`, `10–15%`, `20–30%`). Those numbers depend heavily on workload mix — measure on your own traffic via the Pro Dashboard cache-hit and cost-by-toolkit reports instead of relying on the headline figure.

### Cost management strategies

**1. Set token limits.**

```
Settings → NV oOS → Token Manager
- Per-session limits
- Per-user limits
- Emergency shutoff thresholds
- Per-toolkit budget allocation
```

**2. Pick cost-effective models.**

Recommended by use case (using current catalog names):

- Development/testing: `gpt-4o-mini`, `gpt-4.1-nano`, `gemini-3.1-flash-lite`
- Content creation: `gpt-4o-mini`, `deepseek-chat`, `gemini-3.1-flash`
- E-commerce descriptions: `gpt-4o-mini`, `claude-haiku-4-5`
- Complex reasoning: `gpt-5.1`, `deepseek-reasoner`, `claude-opus-4-7`
- Multi-agent orchestration: `gpt-5.1`, `gpt-4.1`, `gemini-2.5-pro`
- Local-only (zero per-token cost): Ollama / LM Studio / WebLLM models

**3. Leverage caching.** Automatic for tool results (15-min TTL), knowledge-base searches, repeated API calls, agent memory retrieval. Use assistant knowledge base for static content; reuse previous responses via agent memory.

**4. Optimize prompts.** Be specific and concise, avoid unnecessary context, use system prompts for reusable instructions, leverage prompt shortcuts and profession templates.

**5. Monitor usage in Pro Dashboard.** Real-time cost tracking by toolkit, per-assistant consumption, cache-hit rate, model usage breakdown, exportable reports, 24-hour cost timeline.

### Budget examples (rough, update after measuring your own workload)

| Profile | Components | Estimated monthly |
|---|---|---|
| Startup blog (10–20 posts/month) | Content generation + image generation + SEO optimization | ~$3–$5 |
| Small e-commerce (100 products) | Descriptions + customer support + email campaigns + workflow automation 🔒 | ~$10 |
| Medium business (full suite) | Content & media + social analytics 🔒 + customer service + analytics + video 🔒 + workflows | ~$70 |
| Enterprise with multi-agent | Multi-agent content + research projects + site building 🔒 + compliance 🔒 + video 🔒 + social analytics 🔒 | ~$115 |

These are order-of-magnitude estimates. Real spend depends on which provider you route through; running everything through `gpt-5-pro` will be ~10× more expensive than running it through `gpt-4.1-nano` or `gemini-3.1-flash-lite`.

---

## Best Practices

### 0. Customize professional templates

Templates are starting points. Always layer in:

- ✅ Brand voice and tone
- ✅ Knowledge base (style guides, processes, documentation)
- ✅ Your terminology
- ✅ Industry compliance requirements
- ✅ Quality standards and output formats
- ✅ Worked examples of successful outputs

### 1. Prompt engineering

- ❌ Bad: "Write a post"
- ✅ Good: "Write a 1000-word blog post about WordPress security best practices for beginners. Include an introduction, 5 main tips with examples, and a conclusion with a call-to-action."

Iterate: outline → expand section → add examples. Use system prompts for reusable instructions.

### 2. Security & privacy

- Always use appropriate WordPress capabilities
- Test tools with different user roles
- Restrict sensitive tools to administrators
- Don't send sensitive data to AI unless necessary
- Use guest tokens for public chat interfaces
- Enable logging for audit trails
- Store API keys encrypted in Settings → NV oOS
- Never commit keys to version control
- Rotate keys periodically
- Use separate keys for dev/prod

### 3. Performance optimization

- Use streaming for real-time responses; enable SSE for long-running operations
- Leverage automatic result caching (~30–40% hit rate on repeated ops)
- Use cron jobs for batch operations; rely on the inline-async-tick fallback for `DISABLE_WP_CRON` hosts
- Enable mesh networking for distribution
- Use parallel execution where possible (up to 3 tasks)
- Define clear task dependencies; let the coordinator handle optimization
- Monitor execution timeline in Pro Dashboard 🔒
- Check PHP memory limits and server resources; track FFmpeg resource usage for video processing 🔒

### 4. Multi-agent best practices

- Define clear agent roles (Planner, Executor, Critic, Specialist)
- Use agent memory across delegations; choose aggregation strategy by task type
- Start with smaller teams (3–4 agents) and scale up
- Use weighted aggregation when expertise varies; consensus for democratic decisions
- Monitor agent events in Pro Dashboard 🔒
- Multi-agent tasks are more expensive (multiple API calls) — use for complex tasks where collaboration adds value
- Consider `gpt-4o-mini` or `gpt-4.1-nano` for simpler delegations

### 5. Content quality

- Always review AI-generated content
- Fact-check important claims
- Adjust tone and voice as needed
- Use knowledge base effectively
- Provide style guides and worked examples in system prompts
- Refine prompts based on results; create shortcuts for consistent output

### 6. Cost management

- Begin with `gpt-4o-mini` / `gpt-4.1-nano` / `deepseek-chat` / `gemini-3.1-flash-lite`
- Test with small batches; scale up gradually
- Configure token budgets per toolkit; set usage alerts
- Monitor spending in Pro Dashboard 🔒
- Enable result caching, monitor cache hit rates
- Leverage local-only models (Ollama, LM Studio) where latency / privacy demands it

### 7. Pro toolkit selection 🔒

- Only activate toolkits you actively use; start with 1–2 and expand
- Monitor per-toolkit costs in Pro Dashboard
- Recommended combinations:
  - **Content creation business:** Social Media, Multilingual, Analytics
  - **E-commerce store:** E-commerce, Image Production, CRM
  - **Professional services:** Calendar & Booking, Document Generation, Financial Planner
  - **Web development agency:** Site Creator, Video Production, Analytics
  - **Regulated industry:** Regulatory Registration, Document Generation, Analytics

### 8. Video & media workflows 🔒

- Ensure FFmpeg installed and accessible; monitor server resources during transcoding
- Use background processing for large videos; consider a dedicated media server for high volume
- Process videos during off-peak hours
- Use parallel processing (up to 3 simultaneously)
- Cache thumbnails and metadata; compress before uploading to save bandwidth

### 9. Compliance & security

- Always human-review AI-generated compliance documents
- Cite specific regulatory standards in prompts
- Store compliance documents with audit trails
- Use low temperature (0.2) for factual accuracy
- Enable logging for all tool executions; review security events in Pro Dashboard 🔒
- Understand what data is sent to each provider; use guest tokens for public interfaces
- Comply with GDPR/CCPA for user data; consult the per-standard posture documents in `docs/` before making compliance claims

---

## Troubleshooting Common Issues

### "Tool execution failed"

**Causes:** missing dependencies (plugins), insufficient capabilities, API credentials not configured, Pro toolkit not activated.

**Solutions:**
1. Check tool requirements in `docs/reference/tools/tool-reference.md`
2. Verify user has required capability
3. Configure API keys in Settings
4. Enable Pro toolkit if tool is marked 🔒
5. Enable logging to see detailed errors

### "High costs / unexpected charges"

**Causes:** no token limits set, inefficient prompts, testing in production, unnecessarily expensive models, cache not being leveraged.

**Solutions:**
1. Set token limits (Settings → Token Manager)
2. Use `gpt-4o-mini` / `gpt-4.1-nano` / `deepseek-chat` for testing
3. Optimize prompts for clarity
4. Monitor usage in Pro Dashboard 🔒
5. Check cache hit rates
6. Enable reasoning mode selectively

### "Assistant not responding"

**Causes:** API key invalid/expired, timeout reached, server resource limits, multi-agent deadlock.

**Solutions:**
1. Verify API key in Settings
2. Check PHP error logs
3. Increase timeout (Settings → NV oOS)
4. Check server memory limits
5. Review workflow dependencies for circular refs
6. Check Pro Dashboard for error events 🔒

### "Tool not available for assistant"

**Causes:** tool requires third-party plugin, base version limitation, tool disabled globally, Pro toolkit not activated.

**Solutions:**
1. Check the "What You Lose Without Third-Party Plugins" section of the README
2. Install required plugins
3. Activate required Pro toolkit 🔒
4. Check Settings → NV oOS → Tools

### "Background job stuck at queued, Progress: 0/1"

**Cause:** Host has `DISABLE_WP_CRON=true` or the `wp-cron.php` loopback is firewalled. The job's `spawn_cron()` call returns without error but no loopback request actually fires.

**Solutions (1.1.18+):**
1. Verify the relevant subsystem is on the inline-async-tick path — Tool Async Executor, Transcript Mining, SaaS Apply, Veo polling, Graphify, Crawl4AI, Docs Hub rebuild, Harness Eval all are.
2. Confirm `wp_mcp_ai_inline_kick_enabled` filter hasn't been disabled (default `true`).
3. Watch the Jobs/Tasks Drawer — the first tick should now fire on shutdown of the request that enqueued the job.
4. If the job is on an older subsystem not yet covered, fall back to a real WP-Cron runner (server cron hitting `wp cron event run --due-now` every minute).

**Reference:** `docs/architecture/inline-async-tick-pattern.md`.

### "Multi-agent workflow not completing"

**Causes:** circular task dependencies, agent memory context expired, insufficient token budget for multiple agents, network timeout on complex orchestration.

**Solutions:**
1. Review workflow dependency graph for deadlocks
2. Increase agent memory TTL
3. Allocate larger token budget for orchestration
4. Simplify agent team (reduce from 5 to 3 agents)
5. Monitor execution in Pro Dashboard 🔒
6. Check for deadlock detection alerts

### "Video processing failing" 🔒

**Causes:** FFmpeg not installed, server resource exhaustion, unsupported video format, file size exceeds limits.

**Solutions:**
1. Verify FFmpeg installation: `ffmpeg -version`
2. Check server memory and CPU during processing
3. Convert to supported format (MP4, WebM, AVI, MOV)
4. Compress video before processing
5. Use background processing for large files
6. Review error logs for FFmpeg output

---

## Roadmap & Upcoming Toolkits

This section tracks toolkits that have a settings page already wired up but are marked **"Coming Soon — Phase 2.9"** in the admin UI. They are *not yet usable* but the slots are reserved.

### AI Tool Builder Toolkit (Phase 2.9)

> 🚧 **Status: In development.** The settings page at `addons/pro/includes/admin/class-wp-mcp-ai-ai-tool-builder-settings-page.php` currently renders:
>
> > **Coming Soon - Phase 2.9** — This toolkit is planned for implementation in Phase 2.9. Tools and features are subject to change.

**Planned scope (10 tools):** meta-toolkit for creating custom AI tools, with scaffolding, code generation, syntax validation, sandboxed test execution, dynamic registration, auto-generated documentation, dependency analysis, performance tuning, admin UI generation, and exportable tool packages.

**Why it's not in §6 yet:** §6.0 historically described this toolkit as if it were shippable. Until the "Coming Soon" banner is removed, follow [§6.3 Custom Tool Development](#use-case-63-custom-tool-development) for the supported path (hand-authored tools using the Unix Theory P0–P6 compliance rules).

**How to track:** watch `CHANGELOG.md` and `addons/pro/includes/admin/class-wp-mcp-ai-ai-tool-builder-settings-page.php` — the "Coming Soon" banner will be the canonical signal.

### Other reserved Pro slots

Settings pages exist but may not yet expose the full advertised feature set. Treat these as "in flight" and check the live registry before promising tools to a client:

- `architect-agent`, `architectural-design`, `architectural-drawing`, `architectural-project`, `architectural-specification` (architectural verticals)
- `chat-channels` (chat surface routing)
- `dj-management` (event/music workflows)
- `nv-cloud` (managed-cloud control surface)

If you depend on any of these for production workflows, open an issue requesting confirmation of GA status before building on top of them.

---

## Next Steps

Now that you understand the major use cases:

1. **[5-Minute Quick Start](QUICK_START_5_MINUTES.md)** — get started immediately.
2. **Tool Reference** — `docs/reference/tools/tool-reference.md` (the live registry via `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative for exact counts).
3. **Orchestration Reference** — `docs/ORCHESTRATION_REFERENCE.md` (single authoritative reference: 10 workflow presets, 13 resource presets, PSO, reasoning controller, multi-agent system, load balancer, health monitoring, hooks, storage keys).
4. **Inline-async-tick pattern** — `docs/architecture/inline-async-tick-pattern.md` (essential reading for anyone operating on a `DISABLE_WP_CRON` host).
5. **Token Management** — Settings → NV oOS → Token Manager for cost control.
6. **Compliance posture documents** — `docs/HIPAA_POSTURE.md`, `docs/03-wp-org-compliance.md`, `docs/WORDPRESS_ORG_COMPLIANCE_FINAL_STATUS.md`.
7. **SaaS / multi-tenant deployments** — see `docs/SAAS_SETUP_GUIDE.md` (the SaaS Controller addon under `addons/saas-controller/` is documented there end-to-end, including the Cloudflare / Stripe / OpenRouter / Worker apply pipeline and its self-healing inline-kick behaviour). Not duplicated here.
8. **Custom-tool development rules** — `CLAUDE.md` → "Tool Return Format — Canonical Envelope" and "Tool Sanitisation — Two-Gate Rule"; PHPCS sniffs at `phpcs/` enforce them at severity 5.

### Front-end shortcodes summary

| Shortcode | Source | Notes |
|---|---|---|
| `[mcp_ai_chat assistant="<ID>"]` | Base (legacy, jQuery) | Gated by `WP_MCP_AI_LEGACY_CHAT_JS` (default `true`) |
| `[nvoos_chat_spa assistant_id="<ID>"]` | `addons/chat-spa/` | React replacement; recommended for new pages |

### Profession template & team resources

- **Profession seeder** — `includes/professions/class-wp-mcp-ai-profession-seeder.php` (canonical list)
- **IGCSE Implementation** — `docs/implementation-history/2025/summaries/IGCSE_IMPLEMENTATION_SUMMARY.md`
- **Backend testing** — Admin → AI Assistants / Professions / Teams → Test page (requires `manage_options`)

---

## Need Help?

- **Documentation index:** `docs/DOCUMENTATION_INDEX.md`
- **Quick reference:** `docs/QUICK_REFERENCE.md`
- **Troubleshooting:** `docs/troubleshooting/`
- **Issues:** [GitHub Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
- **Support:** see "Getting Help" in `README.md`

---

**Doc revision:** 2.0
**Tested against plugin:** `1.1.18` (May 14, 2026)
**Tool count:** ~830 total (~195 base + ~635 Pro) — `WP_MCP_AI_Tool_Registry::get_tools()` is authoritative.
**Profession templates:** ~190 across 12 categories.
**Pro toolkits:** 10 GA SPA-manifested + ~34 additional settings-page toolkits at varying stages of SPA migration. See the [fact sheet](_USE_CASES_FACT_SHEET.md).
**Pre-built teams:** Engineering, Pharmaceutical Development, Research & Data Science, Marketing & Growth, plus 6 IGCSE teams.
**Maintained by:** [NV Digital Solutions](https://nvdigitalsolutions.com/wpoos).

---

**Document history**

- **2.0 (May 17, 2026)** — Synced with plugin 1.1.18. Adopted independent doc-revision numbering. Counts corrected against the live registry (~830 / ~195 / ~635); profession count corrected to ~190; Pro toolkit list rewritten against the 10 SPA-manifested GA toolkits plus the ~34 settings-page toolkits. AI Tool Builder moved from §6.0 to the new "Roadmap & Upcoming Toolkits" appendix. New use cases: §4.5 Scheduled Result widget/block, §6.0 Toolkit MCP Servers, §6.4 Memory Mining, §7.4 Bundled Skill Packs, §13 Front-End Chat Delivery (Chat SPA), §14 In-WP Documentation Viewer (Docs Hub). Cost-considerations table rebuilt from `includes/data/model-catalog.json` v `2026.05.04` (OpenAI gpt-4.1 / gpt-5.x families, Anthropic Claude 4.5/4.6/4.7, current Gemini 2.5+3.1 lines, DigitalOcean Serverless Inference with zeroed-price caveat, Kimi/Moonshot, Cloudflare Workers AI). Fabricated Cloudflare image-model rows (`flux-2-dev`, `leonardo-ai`) and the made-up "v1.2.0 vs v1.3.0 cost comparison" both removed. Compliance section converted from percentage claims to posture-document references. SaaS Controller documented only by cross-link to `docs/SAAS_SETUP_GUIDE.md`. Stale 🆕 markers stripped from sections that have been live ≥4 months. Companion fact sheet at `_USE_CASES_FACT_SHEET.md` captures every count/price/version with its source.
- **1.3.0 (Jan 31, 2026)** — Added 5 new use cases (multi-agent, workflows, video, site building, compliance), Pro toolkit overview, pricing update, performance feature notes. *(Superseded by 2.0 — counts and version references in that revision were already stale at publish.)*
- **1.2.0 (Jan 14, 2026)** — Professional templates, team deployments, backend testing introduced.
- **1.1.0 (Dec 2025)** — Initial comprehensive use case documentation.
