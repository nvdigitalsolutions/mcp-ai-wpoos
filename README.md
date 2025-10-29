# WP MCP AI (WPOS)

**Version:** 1.0.0 (Beta)
**Maintained by [NV Digital](https://nvdigitalsolutions.com)**
**License:** GPLv2 or later
**Requires:** WordPress 6.0+, PHP 7.4+

## 📑 Table of Contents

- [🧩 Overview](#-overview)
- [🔐 JetEngine Capability Reference](#-jetengine-capability-reference)
- [🚀 Features](#-features)
- [🗨️ Front-end chat surfaces](#-front-end-chat-surfaces)
- [📦 Installation](#-installation)
- [⚙️ Configuration Checklist (Action Items)](#-configuration-checklist-action-items)
- [🧊 Elementor Widget](#-elementor-widget)
- [🧮 Usage Tracking](#-usage-tracking)
- [🧷 Attachment MIME Controls](#-attachment-mime-controls)
- [🕵️ Code Review](#-code-review)
- [🔒 MCP Server Authentication](#-mcp-server-authentication)
- [🛰 REST API Endpoints](#-rest-api-endpoints)
- [🛠 Assistant Editor Overview](#-assistant-editor-overview)
- [🔑 Assistant API credentials](#-assistant-api-credentials)
- [🐳 Local Development with Docker](#-local-development-with-docker)
  - [🔁 Codex environment startup script](#-codex-environment-startup-script)
- [🧑‍💻 Development Tooling](#-development-tooling)

---

## 🧩 Overview
**WP MCP AI** is a modular AI framework for WordPress and JetEngine that connects your site’s data with OpenAI’s GPT models.
It allows you to create and manage AI Assistants that can interact with users, access WordPress data, and perform custom tool functions.

---

## 🚀 Features
- 🧠 Create AI Assistants via a custom post type (`ai_assistant`)
- 💬 Chat interface via `[mcp_ai_chat assistant="ID"]`
- 🔁 Route conversations through OpenAI or Gemini using a provider-aware language model router
- 🔧 Tool Registry for registering PHP functions callable by the AI
- 🛍 WooCommerce-aware tools (fetch orders, requires WooCommerce)
- ⚙️ JetEngine integration for dynamic content queries (requires JetEngine)
- 📚 JetEngine REST route reference tool for surfacing endpoint metadata inside AI workflows
- 🌐 Crawl4AI job runner tool for large-scale content gathering workflows
- 🧊 Elementor widgets for embedding chat surfaces, onboarding content, and MCP dashboards inside Elementor
- 🔐 Secure REST API endpoints
- 🔑 Configurable API credentials and defaults for OpenAI and Gemini
- 🧱 Ready for extension with ChatKit Add-on
- 🧾 Optional logging of chat interactions, tool executions, and API errors
- 🧮 Built-in per-user usage tracking for provider/model billing summaries
- 🧩 Developer hooks and filters for integrating custom behaviours
- 🧠 Assistant knowledge base management with Media Library files and optional vector store IDs
- 🧷 Granular control over allowed attachment MIME types for chat uploads
- ⏱ Per-site request timeout control with sensible minimum enforcement
- 🧰 Per-assistant defaults for model, temperature, and system prompt baked into every chat request
- 🔊 Generate speech audio via OpenAI's Text-to-Speech API and save the result to the Media Library
- 🎨 Generate on-brand imagery with OpenAI's Images API, honouring the configured response format (including GPT-Image-1's `url` responses) and storing the files as WordPress attachments
- 🎧 Transcribe or translate uploaded audio with OpenAI's speech-to-text endpoints
- 🔎 Perform lightweight DuckDuckGo searches without leaving the assistant conversation
- 🗑 Toggleable uninstall cleanup to purge stored assistants and settings automatically

---

## 🗨️ Front-end chat surfaces

WP MCP AI ships multiple ways to embed assistants on the front end:

- **Classic chat shortcode** – `[mcp_ai_chat]` renders the bundled interface with attachment uploads, tool invocation feedback, and optional guest access via `allow_guests="true"`. When guest mode is enabled, the shortcode provisions a temporary token and injects it into the JavaScript bootstrap so visitors without WordPress accounts can continue chatting while still respecting capability checks and attachment safety limits.【F:includes/class-wp-mcp-ai-shortcode.php†L132-L258】【F:includes/class-wp-mcp-ai-shortcode.php†L188-L226】
- **Elementor widgets** – Drop the chat UI anywhere Elementor is active, pair it with intro/FAQ blocks, and surface dashboard telemetry without custom code. The chat widget mirrors the shortcode controls (including `allow_guests`), and companion widgets expose onboarding content, usage timers, provider quick links, and activity feeds for operational views.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L79-L138】【F:includes/class-wp-mcp-ai-elementor-integration.php†L48-L98】【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L47-L140】【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L48-L226】【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L48-L167】

Guest tokens are honoured by the REST endpoints through the `X-WP-MCP-AI-Guest` header or `guest_token` parameter, allowing the chat shortcode and Elementor widget to make authenticated requests on behalf of public visitors without exposing persistent credentials.【F:includes/class-wp-mcp-ai-rest.php†L289-L307】【F:includes/class-wp-mcp-ai-rest.php†L2088-L2104】

---

## 📦 Installation
1. Upload `wp-mcp-ai.zip` to `/wp-content/plugins/`
2. Activate **WP MCP AI** from the WordPress admin
   - Ensure [JetEngine](https://crocoblock.com/plugins/jetengine/) is active with the **Custom Content Types** module enabled before switching the plugin on. WP MCP AI automatically provisions the `ai_chat_transcripts` CCT on JetEngine init, so no manual setup is required beyond enabling the module; existing CCT definitions are left untouched if you have already created one manually.
3. Go to **Settings → MCP AI**
4. Enter your OpenAI API key
5. Create a new “AI Assistant” in **AI Assistants**
6. Add `[mcp_ai_chat assistant="123"]` to a page or post

---

## ⚙️ Configuration Checklist (Action Items)

Complete these after installation to unlock every integration point:

- [ ] **Add your OpenAI API key** in **Settings → MCP AI → OpenAI API Key** so API calls are authorised.
- [ ] **Add your Gemini API key** in **Settings → MCP AI → Gemini API Key** if you plan to route assistants through Gemini.
- [ ] **Confirm or override the default model** via **Settings → MCP AI → Default Model** (`gpt-4o-mini` ships as the default).
- [ ] **Set a default Gemini model** under **Settings → MCP AI → Default Gemini Model** when Gemini is enabled.
- [ ] **Choose the default provider** from **Settings → MCP AI → Default Provider** so new assistants know whether to use OpenAI or Gemini by default.
- [ ] **Adjust the request timeout** under **Settings → MCP AI → Request Timeout** (minimum 5 s, default 30 s) to match your hosting environment.
- [ ] **Select a default assistant** with **Settings → MCP AI → Default Assistant** so REST and shortcode requests have a fallback.
- [ ] **Decide on logging** with **Settings → MCP AI → Enable Logging** when you need verbose diagnostics.
- [ ] **Choose your uninstall behaviour** via **Settings → MCP AI → Remove Data on Uninstall** if this site should purge assistants and settings during cleanup.
- [ ] **Configure Crawl4AI access** in **Settings → MCP AI → Tools** when you want the Crawl4AI tool to be available to assistants.
- [ ] **Review attachment MIME overrides** in **Settings → MCP AI → Attachments** before enabling file uploads for end users.
- [ ] **Review Send Group Email permissions** in **Settings → MCP AI → Tools** to choose the capability and recipient cap for the group email automation.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L348-L359】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L938-L953】

## 🧠 Language Model Providers (OpenAI & Gemini)

A dedicated router transparently forwards chat completions to the active provider, allowing each request to target OpenAI or Gemini while sharing the same assistant UX.【F:includes/class-wp-mcp-ai-language-model-router.php†L12-L63】 Configure the required API keys, default models, and the global default provider in **Settings → MCP AI** so new assistants inherit sensible defaults and administrators can switch providers without code changes.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L124-L333】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L505-L530】 Assistants can still override provider, model, and generation parameters on a per-post basis.

## 🌐 Crawl4AI Integration

Administrators with `manage_options` capabilities can run the **Run Crawl4AI Job** tool without any external service: when no Crawl4AI endpoint is configured the plugin performs the crawl directly on the WordPress server using the built-in HTTP client, extracts headings and text as Markdown, and records the raw HTML and response metadata for the assistant.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L32-L745】 Errors for individual URLs are captured in the response metadata so partial crawls still return useful context. When a remote Crawl4AI endpoint is configured the request now returns immediately with a task token while WP-Cron powered background polling captures the final payload and makes it available to the assistant UI once the crawl finishes.【F:includes/crawler/class-wp-mcp-ai-crawler.php†L1-L214】【F:assets/js/chat.js†L1-L2200】

Configure remote endpoints or API keys under **Settings → MCP AI → Tools** to tailor how the Crawl4AI integration runs across environments.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L248-L521】

Supplying a Crawl4AI base URL (and optional API key) switches the tool back to proxying crawl jobs to the remote Crawl4AI REST API, preserving backwards compatibility with existing deployments.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L206-L339】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L248-L521】 Local environments can still feed a custom endpoint to the integration through the `WP_MCP_AI_CRAWL4AI_BASE_URL` or `CRAWL4AI_BASE_URL` environment variable when you want to test against a dedicated Crawl4AI service.【F:wp-mcp-ai.php†L54-L96】

## 🧊 Elementor Widgets

Sites running Elementor automatically register a suite of MCP blocks so you can assemble onboarding pages, operational dashboards, and standalone chat layouts without writing markup.【F:includes/class-wp-mcp-ai-elementor-integration.php†L12-L98】 The integration only boots when Elementor is present, so non-Elementor installs avoid any overhead.【F:includes/class-wp-mcp-ai-elementor-integration.php†L29-L46】

### Chat surfaces and companion blocks
- **MCP AI Chat** – Renders the assistant interface with the same controls exposed by the `[mcp_ai_chat]` shortcode, including the `allow_guests` toggle for minting temporary visitor tokens.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L17-L138】
- **MCP AI Chat Intro** – Adds a configurable hero block above the conversation with headings, talking points, and an optional call-to-action button to guide visitors before they engage the model.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-intro-widget.php†L47-L190】
- **MCP AI Chat FAQ** – Surfaces a repeater-driven FAQ list alongside the chat so product teams can document policies and best practices in context.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-faq-widget.php†L47-L150】
- **MCP AI Usage & Timer** – Combines a focus timer with per-user token totals, gracefully handling logged-out visitors, disabled tracking, and empty usage histories.【F:includes/elementor/class-wp-mcp-ai-elementor-chat-usage-timer-widget.php†L48-L340】

### Operations dashboards
- **MCP AI Tool Matrix** – Pulls the tool registry, groups integrations by focus area, and highlights the required capability for each assistant tool so administrators can plan enablement safely.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-tool-matrix-widget.php†L48-L210】
- **MCP AI User Capability Snapshot** – Summarises the signed-in operator’s profile, common capabilities, JetEngine access, and multisite memberships to support governance reviews.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-user-capability-widget.php†L48-L262】
- **MCP AI Theme Preview** – Renders a mock conversation using the saved chat color tokens and optionally displays a legend of every branding token for quick QA during rollouts.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-theme-preview-widget.php†L48-L198】
- **MCP AI Provider Quick Links** – Reuses the OpenAI usage/log tools to populate external billing and telemetry shortcuts that open in new tabs for rapid debugging.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-provider-links-widget.php†L48-L166】
- **MCP AI Activity Feed** – Streams the latest MCP log entries (tool runs, chat interactions, and optional provider requests), collapsing raw context into expandable JSON blocks for deeper analysis.【F:includes/elementor/class-wp-mcp-ai-elementor-dashboard-activity-feed-widget.php†L48-L210】

## 🧮 Usage Tracking

The plugin records aggregate token usage per user, provider, and model whenever responses include usage metadata, simplifying internal reconciliation or billing workflows. Usage data is stored as user meta and automatically purged when accounts are deleted, and hooks are exposed for custom reporting pipelines.【F:includes/class-wp-mcp-ai-usage-tracker.php†L12-L119】

## 🧷 Attachment MIME Controls

Administrators can override the default image and file MIME allowlists used by the chat uploader. The settings screen accepts one MIME type per line, and the attachment helper merges the overrides with its defaults before enforcing them on upload and shortcode configuration.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L225-L669】【F:includes/class-wp-mcp-ai-message-attachments.php†L503-L559】 Leave the fields empty to fall back to the bundled safe defaults.

## 🕵️ Code Review

The latest internal code review captures high-priority hardening tasks and positive architectural notes. Highlights include tightening the group email tool’s custom header handling, guarding against oversized recipient definition files, and preserving case-sensitive variable names when triggering OpenAI workflows.【F:docs/code-review-report.md†L3-L33】【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L348-L351】【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L582-L589】【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L288-L301】

➡️ See [docs/code-review-report.md](docs/code-review-report.md) for the complete findings, recommendations, and follow-up notes.【F:docs/code-review-report.md†L1-L33】

## 🔒 MCP Server Authentication

Remote MCP assistants should authenticate with Auth0-issued bearer tokens (`Authorization: Bearer YOUR_TOKEN`) whose audience and scope align with the values configured under **Settings → WP MCP AI**. Same-origin experiences (the dashboard editor and shortcode UI) continue to rely on the `X-WP-Nonce` header tied to the logged-in WordPress session. Review [docs/mcp-server-authentication.md](docs/mcp-server-authentication.md) for a complete setup guide plus a breakdown of the structured error responses returned on failure, and keep the [deployment troubleshooting checklist](docs/deployment-troubleshooting.md) handy when diagnosing capability or credential regressions.

## 🛰 REST API Endpoints

All front-end chat surfaces ultimately call the MCP REST namespace at `/wp-json/mcp-ai/v1`, which exposes dedicated endpoints for chat completions and direct tool execution. Both routes share the same authentication rules described above: supply an Auth0 bearer token, a plugin-issued assistant credential, or a WordPress REST nonce for same-origin requests. Guest tokens issued by the shortcode or Elementor widget continue to be honoured when `allow_guests="true"` is enabled.【F:includes/class-wp-mcp-ai-rest.php†L230-L322】【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L1288-L1336】

- **`POST /chat`** – Normalises structured `messages`, injects assistant defaults, auto-enables the Submit Document Prompt tool when uploads are present, and forwards the request through the language model router. Responses include the assistant ID and the raw provider payload so clients can stream or render messages as needed.【F:includes/class-wp-mcp-ai-rest.php†L230-L322】【F:includes/class-wp-mcp-ai-rest.php†L931-L1095】
- **`POST /tools`** – Executes a specific registered tool outside of a chat turn. The endpoint enforces assistant tool allowlists, scopes credential-based requests to the issuing assistant, merges assistant defaults (such as external action identifiers), and returns the tool result with execution metadata.【F:includes/class-wp-mcp-ai-rest.php†L264-L322】【F:includes/class-wp-mcp-ai-rest.php†L1162-L1321】

See [docs/rest-api.md](docs/rest-api.md) for payload examples, attachment handling rules, and troubleshooting tips when integrating custom clients.

---

## 🛠 Assistant Editor Overview

Assistant posts ship with dedicated controls that map directly to runtime behaviour:

- **Available Tools** – Choose which registered tools (core, WooCommerce, JetEngine, or custom) the model may invoke. Dependency-aware notices explain why certain tools are unavailable.
- **Model Defaults** – Provide assistant-specific overrides for the OpenAI model, temperature (0–2), and system prompt applied to every conversation.
- **Base Knowledge** – Attach Media Library items that are chunked, truncated, and streamed as memory context, and optionally store an external **Vector Store ID** to coordinate retrieval workflows.

If an API or shortcode request omits the `assistant` parameter, the plugin automatically uses the default assistant configured in the global settings.

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
- The plugin source in this repository is mounted to `/var/www/html/wp-content/plugins/wp-mcp-ai` inside the container, so edits on your machine are reflected immediately.
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
- Symlinks this repository into the new install's `wp-content/plugins/wp-mcp-ai` directory.
- Installs Composer development dependencies (when available) and provisions the WordPress test suite so `composer run test` works immediately.
- Runs `wp core install`, activates the **WP MCP AI** plugin, enables pretty permalinks, and sets a default site tagline.
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
- An OpenAI API key and default model must be configured in **Settings → MCP AI**.

### Tips
- Omit the `assistant` attribute to fall back to the default assistant configured in the settings screen.
- Multiple shortcodes can be added to the same page; each chat instance maintains its own conversation context on the client.
- Use `allow_guests="true"` to expose the chat UI to logged-out visitors. Each render issues a short-lived guest token that authorises REST requests without a WordPress login.
- REST interactions rely on the `[wp_rest]` nonce, so caching plugins should avoid caching pages for logged-in editors running the chat.

### Elementor widget
- Elementor sites automatically gain an **MCP AI Chat** widget that mirrors the shortcode controls, including the optional assistant selector and the guest access toggle.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L17-L109】
- Leaving the assistant control blank falls back to the default assistant configured in the plugin settings, and enabling **Allow Guests** injects the same temporary tokens used by the shortcode flow.【F:includes/elementor/class-wp-mcp-ai-elementor-widget.php†L45-L110】【F:includes/class-wp-mcp-ai-shortcode.php†L132-L224】

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
  hints (`low`, `auto`, `high`) and `caption` fields are preserved.
- `input_file` – Reference an uploaded attachment that should be streamed to the model.

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

Need to relax or tighten the allowed file types? Administrators can override the image and file MIME lists directly in **Settings → MCP AI → Attachments**, and the same values are used by shortcode-driven chat surfaces (including the Elementor widget) when building upload restrictions.【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L225-L267】【F:includes/class-wp-mcp-ai-message-attachments.php†L456-L565】【F:includes/class-wp-mcp-ai-shortcode.php†L197-L218】 When JSON Lines support is enabled in the allowlist the plugin also registers `.jsonl` and `.ndjson` extensions with WordPress so uploads succeed without additional filters.【F:wp-mcp-ai.php†L236-L272】

---

## 🛠 Built-in tools & automations

The core tool registry loads several assistant-ready utilities that cover editorial, operational, and support workflows out of the box. Highlights include:

- **Submit Document Prompt** – Sends a user-supplied prompt alongside uploaded WordPress attachments or existing OpenAI file IDs, ensuring each request includes at least one file segment before streaming the conversation to OpenAI.【F:includes/tools/class-wp-mcp-ai-tool-submit-document-prompt.php†L20-L214】
- **Generate OpenAI Image** – Calls the Images API with the site’s configured defaults, stores the binary response as a Media Library attachment, and supports optional size, quality, timeout, and filename overrides.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-image.php†L17-L218】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L906-L1177】
- **Generate OpenAI Speech** – Converts text into audio using the Text-to-Speech endpoint, honours the default model/voice/format, and persists the result to the Media Library for reuse in content workflows.【F:includes/tools/class-wp-mcp-ai-tool-generate-openai-speech.php†L17-L199】【F:includes/admin/class-wp-mcp-ai-admin-settings.php†L983-L1110】
- **Transcribe OpenAI Audio** – Accepts uploaded audio attachments, forwards them to OpenAI for transcription or translation, and returns structured metadata, language hints, segments, and duration details.【F:includes/tools/class-wp-mcp-ai-tool-transcribe-openai-audio.php†L17-L195】
- **Run OpenAI External Action** – Lets administrators trigger pre-built OpenAI workflows or assistants through the Responses API, including payload validation, timeout overrides, and structured error reporting when API keys are missing.【F:includes/tools/class-wp-mcp-ai-tool-run-openai-external-action.php†L17-L211】
- **Crawl4AI Job Runner** – Executes Crawl4AI harvesting jobs locally or against a remote endpoint, capturing HTML, Markdown, and per-URL errors so assistants can reason over long-form content collections.【F:includes/tools/class-wp-mcp-ai-tool-run-crawl4ai-job.php†L32-L745】
- **Web Search** – Queries DuckDuckGo’s Instant Answer API to return lightweight web research snippets, honouring per-user permissions and result caps.【F:includes/tools/class-wp-mcp-ai-tool-web-search.php†L12-L164】
- **Site & content summaries** – Pull recent posts, JetEngine entries, WooCommerce orders, or a high-level site snapshot so assistants can respond with current editorial, catalog, or analytics context.【F:includes/tools/class-wp-mcp-ai-tool-get-recent-posts.php†L12-L104】【F:includes/tools/class-wp-mcp-ai-tool-get-jetengine-items.php†L12-L118】【F:includes/tools/class-wp-mcp-ai-tool-get-woo-recent-orders.php†L12-L117】【F:includes/tools/class-wp-mcp-ai-tool-get-site-summary.php†L12-L66】
- **User insight tools** – Inspect the active user or a specific account (respecting capabilities), expose JetEngine REST route metadata, or proxy route invocations through the authenticated MCP context.【F:includes/tools/class-wp-mcp-ai-tool-get-user-info.php†L12-L89】【F:includes/tools/class-wp-mcp-ai-tool-list-jetengine-routes.php†L12-L151】【F:includes/tools/class-wp-mcp-ai-tool-invoke-jetengine-route.php†L12-L133】
- **Operational helpers** – Schedule WP-Cron jobs, orchestrate group emails with capability and recipient limits, or surface OpenAI dashboard shortcuts for administrators.【F:includes/tools/class-wp-mcp-ai-tool-create-cron-job.php†L16-L142】【F:includes/tools/class-wp-mcp-ai-tool-send-group-email.php†L16-L234】【F:includes/tools/class-wp-mcp-ai-tool-open-openai-logs.php†L12-L66】【F:includes/tools/class-wp-mcp-ai-tool-open-openai-usage.php†L12-L66】

Each tool inherits the assistant context and authenticated user from the REST layer, making it easy to layer custom permissions or extend behaviour via the documented filters and actions.【F:includes/class-wp-mcp-ai-rest.php†L236-L360】【F:includes/class-wp-mcp-ai-rest.php†L1124-L1198】

Need per-tool prerequisites or capability callouts? Consult [`docs/tool-reference.md`](docs/tool-reference.md) for a detailed matrix of every bundled integration.


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

- Enable or disable logging from **Settings → MCP AI → Enable Logging**.
- When logging is enabled the plugin records:
  - Chat requests and responses processed by the REST API.
  - Tool executions (including permission denials).
  - Errors returned from the OpenAI API and internal validation.
- Log entries are written via PHP's `error_log()` and can be filtered with `wp_mcp_ai_log_entry` to route them elsewhere.

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

The plugin registers several tools automatically. Tools that rely on third-party plugins only load when their dependency is active:

- **WooCommerce Orders Tool** – Visible only when WooCommerce is active. If WooCommerce is missing, an informational notice is shown to administrators and the tool will not be listed for assistants.
- **JetEngine Items Tool** – Visible only when JetEngine is active. Administrators are informed when JetEngine is not detected and the tool remains unavailable to assistants.

Each tool description in the admin UI reiterates the dependency so editors understand why a tool might be unavailable.

---

## ✅ Manual QA Scenarios

The project currently relies on manual verification. Run these checks after updating the plugin:

1. **Baseline (no optional plugins)**
   - Deactivate WooCommerce and JetEngine.
   - Load the AI Assistant edit screen and confirm only core tools appear. No PHP notices or fatal errors should occur.
   - Visit the WordPress dashboard to confirm the informational notices explain why optional tools are disabled.
2. **WooCommerce enabled**
   - Activate WooCommerce.
   - Reload the Assistant editor and ensure the WooCommerce Orders tool appears and can be selected.
   - Trigger the tool (e.g., via an assistant conversation) and confirm recent orders return without errors.
3. **JetEngine enabled**
   - Activate JetEngine.
   - Confirm the JetEngine Items tool appears for assistants and returns data for a configured JetEngine post type.
4. **Tool call retry resilience**
   - Initiate a chat conversation that triggers a tool call (for example, request an operation that requires the WooCommerce Orders tool).
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

Each hook receives sanitized data and respects the current user's permissions and multisite membership.
