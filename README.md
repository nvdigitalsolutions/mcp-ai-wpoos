# WP MCP AI (Core Plugin)

**Version:** 0.9.0 (Beta)  
**Maintained by [NV Digital](https://nvdigitalsolutions.com)**  
**License:** GPLv2 or later  
**Requires:** WordPress 6.0+, PHP 7.4+

---

## 🧩 Overview
**WP MCP AI** is a modular AI framework for WordPress and JetEngine that connects your site’s data with OpenAI’s GPT models.  
It allows you to create and manage AI Assistants that can interact with users, access WordPress data, and perform custom tool functions.

---

## 🚀 Features
- 🧠 Create AI Assistants via a custom post type (`ai_assistant`)
- 💬 Chat interface via `[mcp_ai_chat assistant="ID"]`
- 🔧 Tool Registry for registering PHP functions callable by the AI
- 🛍 WooCommerce-aware tools (fetch orders)
- ⚙️ JetEngine integration for dynamic content queries
- 🔐 Secure REST API endpoints
- 🔑 Configurable OpenAI key via settings panel
- 🧱 Ready for extension with ChatKit Add-on
- 🪵 Optional logging of chat interactions, tool executions, and API errors
- 🪝 Developer hooks and filters for integrating custom behaviours

---

## 📦 Installation
1. Upload `wp-mcp-ai.zip` to `/wp-content/plugins/`
2. Activate **WP MCP AI** from the WordPress admin
3. Go to **Settings → MCP AI**
4. Enter your OpenAI API key
5. Create a new “AI Assistant” in **AI Assistants**
6. Add `[mcp_ai_chat assistant="123"]` to a page or post

---

## 💬 Example Shortcode
```html
[mcp_ai_chat assistant="123"]
```

---

## 🪵 Logging

- Enable or disable logging from **Settings → MCP AI → Enable Logging**.
- When logging is enabled the plugin records:
  - Chat requests and responses processed by the REST API.
  - Tool executions (including permission denials).
  - Errors returned from the OpenAI API and internal validation.
- Log entries are written via PHP's `error_log()` and can be filtered with `wp_mcp_ai_log_entry` to route them elsewhere.

---

## 🧩 Hooks & Filters

Use the following hooks to extend the plugin:

| Hook | Type | Description |
| --- | --- | --- |
| `do_action( 'wp_mcp_ai_before_chat_request', $assistant_id, $messages, $options, $request )` | Action | Fires before a chat request is sent to OpenAI. |
| `do_action( 'wp_mcp_ai_after_chat_response', $assistant_id, $response, $request )` | Action | Fires after a chat response is received. |
| `apply_filters( 'wp_mcp_ai_chat_options', $options, $assistant_config, $request )` | Filter | Modify the OpenAI request options before dispatch. |
| `do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $arguments, $context )` | Action | Runs immediately before a tool executes. |
| `apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $arguments, $context )` | Filter | Inspect or transform tool output before it is returned. |
| `do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result )` | Action | Runs after a tool completes execution. |
| `apply_filters( 'wp_mcp_ai_log_entry', $entry, $type, $message, $context )` | Filter | Intercept or redirect logging output. |

Each hook receives sanitized data and respects the current user's permissions and multisite membership.
