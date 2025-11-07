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
