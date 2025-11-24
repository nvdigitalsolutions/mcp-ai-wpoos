# WP OOS (Core Plugin)

**Version:** 1.0.0 (Beta)
**Maintained by [NV Digital](https://nvdigitalsolutions.com)**  
**License:** GPLv2 or later  
**Requires:** WordPress 6.0+, PHP 7.4+

---

## 🧩 Overview
**WP OOS** is a modular AI framework for WordPress and JetEngine that connects your site’s data with OpenAI’s GPT models.
It allows you to create and manage AI Assistants that can interact with users, access WordPress data, and perform custom tool functions.

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
- 🧾 Optional logging of chat interactions, tool executions, and API errors
- 🧩 Developer hooks and filters for integrating custom behaviours
- 🧠 Assistant knowledge base management with Media Library files and optional vector store IDs
- 📎 Front-end chat uploads that normalise Media Library attachments for multimodal conversations
- ⏱ Per-site request timeout control with sensible minimum enforcement
- 🧰 Per-assistant defaults for model, temperature, and system prompt baked into every chat request
- 🗑 Toggleable uninstall cleanup to purge stored assistants and settings automatically
- 🏗️ **Design Professional Tools** – Comprehensive suite for construction, interior design, and branding (see [Design Tools Documentation](docs/design-tools.md)):
  - 📐 CAD Drawing Generator (DWG/DXF export for AutoCAD, SketchUp, Revit)
  - 🎨 AI Rendering Assistant (photorealistic rendering with lighting/texture suggestions)
  - 🎭 Material & Color Recommendations (AI-powered design suggestions)
  - 🧊 3D Model Generator (2D to 3D conversion, OBJ/FBX/STL export)
  - 💰 Cost Estimation Tool (budget projections and detailed breakdowns)
  - 🏷️ Logo Generator (AI-driven professional logo creation, SVG/EPS/AI export)
  - ✏️ Vector Design Assistant (SVG creation and manipulation)
  - 🎯 Brand Identity Generator (complete brand packages with guidelines)
  - 🔲 Icon Set Generator (cohesive scalable icon sets)

---

## 📦 Installation
1. Upload `wp-mcp-ai.zip` to `/wp-content/plugins/`
2. Activate **WP OOS** from the WordPress admin
3. Go to **Settings → WP OOS**
4. Enter your OpenAI API key
5. Create a new “AI Assistant” in **AI Assistants**
6. Add `[mcp_ai_chat assistant="123"]` to a page or post

---

## ⚙️ Configuration Checklist (Action Items)

Complete these after installation to unlock every integration point:

- [ ] **Add your OpenAI API key** in **Settings → WP OOS → OpenAI API Key** so API calls are authorised.
- [ ] **Confirm or override the default model** via **Settings → WP OOS → Default Model** (`gpt-4o-mini` ships as the default).
- [ ] **Adjust the request timeout** under **Settings → WP OOS → Request Timeout** (minimum 5 s, default 30 s) to match your hosting environment.
- [ ] **Select a default assistant** with **Settings → WP OOS → Default Assistant** so REST and shortcode requests have a fallback.
- [ ] **Decide on logging** with **Settings → WP OOS → Enable Logging** when you need verbose diagnostics.
- [ ] **Choose your uninstall behaviour** via **Settings → WP OOS → Remove Data on Uninstall** if this site should purge assistants and settings during cleanup.

## 🔒 MCP Server Authentication

Remote MCP assistants should authenticate with Auth0-issued bearer tokens (`Authorization: Bearer YOUR_TOKEN`) whose audience and scope align with the values configured under **Settings → WP OOS**. Same-origin experiences (the dashboard editor and shortcode UI) continue to rely on the `X-WP-Nonce` header tied to the logged-in WordPress session. Review [docs/mcp-server-authentication.md](docs/mcp-server-authentication.md) for a complete setup guide plus a breakdown of the structured error responses returned on failure, and keep the [deployment troubleshooting checklist](docs/deployment-troubleshooting.md) handy when diagnosing capability or credential regressions.

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
- Runs `wp core install`, activates the **WP OOS** plugin, enables pretty permalinks, and sets a default site tagline.
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
- The assistant post must be **published** and the current user must have the `edit_posts` capability (matching the REST permission check).
- An OpenAI API key and default model must be configured in **Settings → WP OOS**.

### Tips
- Omit the `assistant` attribute to fall back to the default assistant configured in the settings screen.
- Multiple shortcodes can be added to the same page; each chat instance maintains its own conversation context on the client.
- REST interactions rely on the `[wp_rest]` nonce, so caching plugins should avoid caching pages for logged-in editors running the chat.
- Editors can upload attachments directly from the chat UI when their role grants the `upload_files` capability. Upload progress is surfaced inline and completed files appear above the composer.
- Uploaded files are stored in the Media Library and included in the next request as structured segments so the assistant can reference images and documents without extra API calls.

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
`wp_mcp_ai_max_attachment_bytes`), and only allows safe MIME types by default (`image/*` formats, `text/plain`, `text/markdown`,
`text/csv`, `application/pdf`, `application/json`).

Whenever attachments are present, the plugin automatically includes an `attachments` block in the upstream OpenAI payload with
base64-encoded blobs, file names, captions, and generated `file_id` values. Message segments that reference the attachment will
use these `file_id` handles so integrators do not need to upload assets manually.

Assistant memory files configured on the post (`memory_files`) are also promoted to structured `text` segments on the
system channel, retaining the existing chunking/truncation safeguards.

### Attachment controls & safeguards

- Front-end chats use the WordPress Media REST API (`/wp/v2/media`) to upload files, adopting the same permission checks and storage rules as the dashboard uploader.
- Files larger than 5 MB are rejected by default; adjust the ceiling via the `wp_mcp_ai_max_attachment_bytes` filter.
- Allow-list MIME types for each usage (image vs. generic file) can be extended with the `wp_mcp_ai_allowed_image_mimes` and `wp_mcp_ai_allowed_file_mimes` filters.
- Hook `wp_mcp_ai_can_use_attachment` when additional business logic is required before an attachment is exposed to the model (for example, approving files uploaded by subscribers).

---

## 🛰 JetEngine REST API Reference

- 📄 Review the full endpoint catalogue in [`docs/jet-engine-rest-routes.md`](docs/jet-engine-rest-routes.md) for route paths, callbacks, and required parameters.
- 🤖 When JetEngine is active, assistants can invoke the **List JetEngine REST Routes** tool to retrieve the same metadata directly inside a conversation (requires a user with the `manage_options` capability).

---

## 🪵 Logging

- Enable or disable logging from **Settings → WP OOS → Enable Logging**.
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
| `apply_filters( 'wp_mcp_ai_allowed_message_roles', $roles )` | Filter | Extend or replace the permitted chat message roles (`user`, `assistant`, `system`, `tool` by default). |
| `apply_filters( 'wp_mcp_ai_pre_validate_bearer_token', $result, $token, $request )` | Filter | Short-circuit Auth0 bearer validation to integrate alternate credential stores. |
| `apply_filters( 'wp_mcp_ai_bearer_token_payload', $payload, $request )` | Filter | Inspect or transform the decoded bearer token payload before capability checks. |
| `apply_filters( 'wp_mcp_ai_map_bearer_to_user_id', $user_id, $payload, $request )` | Filter | Associate a validated bearer token with a WordPress user so REST calls inherit that account's permissions. |
| `do_action( 'wp_mcp_ai_before_tool_execution', $tool_slug, $arguments, $context )` | Action | Runs immediately before a tool executes. |
| `apply_filters( 'wp_mcp_ai_tool_output', $result, $tool_slug, $arguments, $context )` | Filter | Inspect or transform tool output before it is returned. |
| `do_action( 'wp_mcp_ai_after_tool_execution', $tool_slug, $arguments, $context, $result )` | Action | Runs after a tool completes execution. |
| `apply_filters( 'wp_mcp_ai_max_attachment_bytes', $bytes, $attachment_id, $usage )` | Filter | Increase or lower the attachment size ceiling enforced during chat validation. |
| `apply_filters( 'wp_mcp_ai_allowed_image_mimes', $mimes )` | Filter | Expand the allowed image MIME types for multimodal messages. |
| `apply_filters( 'wp_mcp_ai_allowed_file_mimes', $mimes )` | Filter | Expand the generic file MIME allow list for chat uploads. |
| `apply_filters( 'wp_mcp_ai_can_use_attachment', $allowed, $attachment_id )` | Filter | Enforce custom business rules before an attachment is exposed to the model. |
| `apply_filters( 'wp_mcp_ai_log_entry', $entry, $type, $message, $context )` | Filter | Intercept or redirect logging output. |

Each hook receives sanitized data and respects the current user's permissions and multisite membership.
