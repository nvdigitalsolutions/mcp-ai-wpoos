=== NV oOS Page Agent ===
Contributors: nvdigitalsolutions
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

AI-powered page control copilot for WordPress. Give any page its own AI agent that can click, type, and navigate via natural language.

== Description ==

NV oOS Page Agent integrates Alibaba's Page Agent — a MIT-licensed, client-side JavaScript GUI agent — as a self-contained addon for the NV oOS (Open Operator System) platform.

Page Agent runs entirely in the browser, uses text-based DOM extraction with any OpenAI-compatible LLM, and requires no headless browser, Python, or Chrome extension.

**Key Features:**

* **Natural language page control** — Type "Click Posts → Add New" and the agent does it
* **Seamless NV oOS integration** — Works with the existing chat widget, tool registry, and model router
* **No headless browser** — Runs entirely in the user's browser
* **Any OpenAI-compatible LLM** — Use GPT-4o, Claude, Gemini, DeepSeek, Ollama, or local models
* **Security first** — WordPress nonce verification, capability checks, and configurable confirmation gates for destructive actions
* **Shortcode support** — `[mcp_ai_page_agent]` places the Page Agent UI anywhere

= Requirements =

* **NV oOS (mcp-ai-wpoos)** base plugin must be installed and active
* An OpenAI-compatible API key (OpenAI, DeepSeek, Ollama, OpenRouter, etc.)

= How It Works =

1. Install and activate the addon
2. Go to **NV oOS → Page Agent** to configure your model and settings
3. Enable Page Agent on your site
4. The Page Agent UI appears alongside the NV oOS chat widget
5. Type natural language instructions and watch the agent interact with the page

= Pro Features (requires NV oOS Pro) =

* **Admin Dashboard Copilot** — Floating copilot button in the WordPress admin bar
* **Workflow Recording** — Record and replay Page Agent sessions
* **Usage Analytics** — Track agent performance and LLM costs
* **Chrome Extension Companion** — Cross-tab page control

== Installation ==

1. Upload the `page-agent` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure the NV oOS base plugin is active
4. Go to **NV oOS → Page Agent** to configure

== Frequently Asked Questions ==

= What LLMs does Page Agent support? =

Any OpenAI-compatible API with function calling support. Recommended: gpt-4o-mini (fast, cheap), gpt-4o (complex workflows), claude-3.5-sonnet, or qwen2.5 via Ollama (local, free).

= Does this require a headless browser? =

No. Page Agent runs entirely in the user's browser — the same tab that displays the WordPress page. No Chrome extensions, Python, or headless browsers needed.

= How is security handled? =

All REST endpoints require WordPress nonce verification and user capability checks. The Page Agent only has access to what the logged-in user can see and do. Destructive actions (delete, publish, trash) can be gated behind a confirmation step.

= Does this work on the WordPress admin? =

Yes, when combined with NV oOS Pro. The admin copilot feature adds a floating button to the admin bar for controlling the WordPress dashboard via natural language.

== Changelog ==

= 0.1.0 =
* Initial release
* Frontend Page Agent bridge with NV oOS chat integration
* REST API endpoints for tool dispatch and DOM snapshots
* `page_agent_execute` tool for LLM-to-browser delegation
* WordPress settings page (model, language, max steps)
* `[mcp_ai_page_agent]` shortcode
* PHPUnit test suite

== Credits ==

* **Alibaba Page Agent** (MIT) — https://github.com/alibaba/page-agent
* Built on the NV oOS (Open Operator System) platform by NV Digital Solutions
