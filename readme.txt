=== WP Open Operator System (WP oOS) ===
Contributors: nvdigitalsolutions
Donate link: https://nvdigitalsolutions.com/openwp-operator-system
Tags: ai, assistant, jetengine, openai, gpt, chatbot
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Core AI Assistant framework for WordPress and JetEngine, using OpenAI GPT models.

This plugin was originally developed by NV Digital Solutions.
Please retain credit in derivative works.

== Description ==

This plugin provides a robust framework for integrating AI-powered assistants into WordPress. It works perfectly with vanilla WordPress and optionally integrates with third-party plugins like JetEngine, WooCommerce, and Elementor for enhanced functionality.

**System Requirements:**
*   WordPress 6.0 or higher
*   PHP 7.4 or higher (PHP 8.0+ recommended)

**Key Features:**
*   Create and manage AI assistants with OpenAI, Gemini, or Ollama
*   35+ built-in tools for content, media, research, and site operations
*   Works with vanilla WordPress (no third-party plugins required)
*   Optional integrations with JetEngine, WooCommerce, Elementor, Rank Math, and WPCode
*   MCP (Model Context Protocol) server for Claude Desktop, LM Studio, and other clients
*   Chat interface via shortcode or Elementor widgets
*   Extensible with custom tools and capabilities

**Third-Party Plugin Support (Optional):**
*   **JetEngine** (paid) - Server-side chat transcript storage, custom content access
*   **WooCommerce** (free) - E-commerce automation, product/order management
*   **Elementor** (freemium) - Template management, pre-built widgets
*   **Rank Math SEO** (freemium) - SEO analysis and optimization
*   **WPCode** (freemium) - Code snippet automation

All third-party plugins are completely optional. WP oOS provides full AI assistant functionality without them.

Copyright (c) 2025 NV Digital Solutions (https://nvdigitalsolutions.com)
This plugin is licensed under the GNU General Public License v3 or later.

== Screenshots ==

1. AI Assistant editor - Configure assistants with tools, models, and settings
2. Chat interface - User-friendly chat widget via shortcode or Elementor
3. Tools Manager - Enable/disable tools and configure permissions
4. Settings Dashboard - Configure API keys, rate limits, and orchestration options
5. Performance Monitoring - Track API usage, token consumption, and costs

== Frequently Asked Questions ==

= Do I need JetEngine to use this plugin? =

No. WP oOS works perfectly with vanilla WordPress. JetEngine is completely optional and only adds server-side chat transcript storage and access to custom content types.

= Which AI providers are supported? =

WP oOS supports OpenAI (GPT-4, GPT-4o, etc.), Google Gemini (Pro, Flash), Ollama (local AI), and LM Studio (local AI). You can switch between providers without changing your assistant configuration.

= How do I get an OpenAI API key? =

Visit https://platform.openai.com/api-keys to create an account and generate an API key. You'll need to add billing information and credits to use the API.

= Is my data secure? =

Yes. The plugin follows WordPress security best practices with input sanitization, output escaping, capability checks, and nonce verification. API keys are stored securely in WordPress options. Chat transcripts are stored locally in your WordPress database (or optionally in JetEngine CCTs).

= Can I use this on a multisite installation? =

Yes. WP oOS fully supports WordPress multisite with network activation or individual site activation. Settings are configured per-site, allowing each site to have its own API keys and assistants.

= How much does it cost to use? =

The plugin itself is free and open source. However, you'll need API credits with your chosen AI provider (OpenAI, Google, etc.). Costs vary by model - see the provider's pricing page. The plugin includes usage tracking to help monitor costs.

= Can I create custom tools? =

Yes. WP oOS has an extensible tool registry system. You can create custom tools by implementing the tool interface and registering them via the `wp_mcp_ai_register_tools` action. See CONTRIBUTING.md for details.

= Does this work with the Classic Editor? =

Yes. The plugin works with both the Classic Editor and Block Editor (Gutenberg). Use the `[mcp_ai_chat]` shortcode in either editor.

= What is MCP? =

MCP (Model Context Protocol) is a standard for connecting AI applications to data sources and tools. WP oOS implements an MCP server, allowing Claude Desktop, LM Studio, and other MCP clients to access your WordPress data.

= How do I get support? =

For bug reports and feature requests, use the GitHub Issues page. For general questions, use GitHub Discussions. For security issues, email security@nvdigitalsolutions.com.

== Installation ==

1.  Upload the `wp-mcp-ai` folder to the `/wp-content/plugins/` directory.
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to Settings → WP oOS to configure your OpenAI API key.
4.  Create a new AI Assistant under the AI Assistants menu.
5.  Add the chat shortcode `[mcp_ai_chat assistant="123"]` to any page.

**Optional Third-Party Plugins:**

Install these plugins separately if you need their features:
*   JetEngine (paid) - For server-side chat transcript storage
*   WooCommerce (free) - For e-commerce automation tools
*   Elementor (freemium) - For template management and widgets
*   Rank Math SEO (freemium) - For SEO analysis tools
*   WPCode (freemium) - For code snippet automation

The plugin works perfectly without any of these.

**Multisite Installation:**

This plugin supports WordPress multisite installations and can be activated network-wide or on individual sites:

*   **Network Activation:** Activate the plugin network-wide from the Network Admin > Plugins menu. This will activate the plugin on all sites in the network and automatically activate it on any new sites created in the future.
*   **Individual Site Activation:** Alternatively, you can activate the plugin on individual sites as needed through each site's Plugins menu.

Note: Plugin settings are configured per-site, allowing each site in the network to have its own OpenAI API keys, assistants, and configuration.

== Changelog ==

= 1.0.0 =
*   Initial release by NV Digital Solutions.
