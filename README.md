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
- 🛍 WooCommerce-aware tools (fetch orders, requires WooCommerce)
- ⚙️ JetEngine integration for dynamic content queries (requires JetEngine)
- 📚 JetEngine REST route reference tool for surfacing endpoint metadata inside AI workflows
- 🔐 Secure REST API endpoints
- 🔑 Configurable OpenAI key via settings panel
- 🧱 Ready for extension with ChatKit Add-on
- 🪵 Optional logging of chat interactions, tool executions, and API errors
- 🪝 Developer hooks and filters for integrating custom behaviours
- 🧠 Assistant knowledge base management with Media Library files and optional vector store IDs
- ⏱ Per-site request timeout control with sensible minimum enforcement
- 🧰 Per-assistant defaults for model, temperature, and system prompt baked into every chat request
- 🗑 Toggleable uninstall cleanup to purge stored assistants and settings automatically

---

## 📦 Installation
1. Upload `wp-mcp-ai.zip` to `/wp-content/plugins/`
2. Activate **WP MCP AI** from the WordPress admin
3. Go to **Settings → MCP AI**
4. Enter your OpenAI API key
5. Create a new “AI Assistant” in **AI Assistants**
6. Add `[mcp_ai_chat assistant="123"]` to a page or post

---

## ⚙️ Configuration Checklist (Action Items)

Complete these after installation to unlock every integration point:

- [ ] **Add your OpenAI API key** in **Settings → MCP AI → OpenAI API Key** so API calls are authorised.
- [ ] **Confirm or override the default model** via **Settings → MCP AI → Default Model** (`gpt-4o-mini` ships as the default).
- [ ] **Adjust the request timeout** under **Settings → MCP AI → Request Timeout** (minimum 5 s, default 30 s) to match your hosting environment.
- [ ] **Select a default assistant** with **Settings → MCP AI → Default Assistant** so REST and shortcode requests have a fallback.
- [ ] **Decide on logging** with **Settings → MCP AI → Enable Logging** when you need verbose diagnostics.
- [ ] **Choose your uninstall behaviour** via **Settings → MCP AI → Remove Data on Uninstall** if this site should purge assistants and settings during cleanup.

---

## 🛠 Assistant Editor Overview

Assistant posts ship with dedicated controls that map directly to runtime behaviour:

- **Available Tools** – Choose which registered tools (core, WooCommerce, JetEngine, or custom) the model may invoke. Dependency-aware notices explain why certain tools are unavailable.
- **Model Defaults** – Provide assistant-specific overrides for the OpenAI model, temperature (0–2), and system prompt applied to every conversation.
- **Base Knowledge** – Attach Media Library items that are chunked, truncated, and streamed as memory context, and optionally store an external **Vector Store ID** to coordinate retrieval workflows.

If an API or shortcode request omits the `assistant` parameter, the plugin automatically uses the default assistant configured in the global settings.

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
- The assistant post must be **published** and the current user must have the `edit_posts` capability (matching the REST permission check).
- An OpenAI API key and default model must be configured in **Settings → MCP AI**.

### Tips
- Omit the `assistant` attribute to fall back to the default assistant configured in the settings screen.
- Multiple shortcodes can be added to the same page; each chat instance maintains its own conversation context on the client.
- REST interactions rely on the `[wp_rest]` nonce, so caching plugins should avoid caching pages for logged-in editors running the chat.

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
        { "type": "input_text", "text": "Describe this photo" },
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

- `input_text` – Free-form text (`text` property). Strings supplied directly to `content` are automatically wrapped in this format.
- `input_image` – Reference an uploaded WordPress attachment (`attachment_id`) or provide a remote `url`. Optional `detail`
  hints (`low`, `auto`, `high`) and `caption` fields are preserved.
- `input_file` – Reference an uploaded attachment that should be streamed to the model.

The REST controller validates attachment ownership/permissions, enforces a default 5 MB size cap (filterable via
`wp_mcp_ai_max_attachment_bytes`), and only allows safe MIME types by default (`image/*` formats, `text/plain`, `text/markdown`,
`text/csv`, `application/pdf`, `application/json`).

Whenever attachments are present, the plugin automatically includes an `attachments` block in the upstream OpenAI payload with
base64-encoded blobs, file names, captions, and generated `file_id` values. Message segments that reference the attachment will
use these `file_id` handles so integrators do not need to upload assets manually.

Assistant memory files configured on the post (`memory_files`) are also promoted to structured `input_text` segments on the
system channel, retaining the existing chunking/truncation safeguards.

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

Document the results of each scenario when preparing releases to ensure optional integrations remain stable.

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
