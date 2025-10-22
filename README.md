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
