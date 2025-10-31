# ChatKit Add-on

## Overview
WP MCP AI exposes a ChatKit add-on so ChatKit operators can trigger the same assistant conversations, tool runs, and attachment downloads that are available through the shortcode or REST API. The add-on now ships directly with the core plugin, bootstraps automatically, registers itself through every ChatKit add-on hook variant, and keeps the bootstrap idempotent so repeated checks do not duplicate registrations.【F:includes/class-wp-mcp-ai-chatkit-addon.php†L30-L204】

When ChatKit is present the add-on definition advertises the `mcp-ai/v1` REST namespace, chat/tool/attachment routes, attachment and guest token support, and the add-on icon/version metadata that ChatKit surfaces in its dashboard.【F:includes/class-wp-mcp-ai-chatkit-addon.php†L156-L185】

## Requirements
- **WP MCP AI** 1.0.0 or later.
- The standalone [ChatKit](https://github.com/nvdigitalsolutions/chatkit) plugin installed and activated on the same WordPress site.
- WordPress users must satisfy the capability returned by `wp_mcp_ai_chat_capability` for the `chatkit` context (defaults to `edit_posts`).【F:includes/class-wp-mcp-ai-chatkit-addon.php†L156-L226】【F:wp-mcp-ai.php†L25-L63】 Override the capability via the `wp_mcp_ai_chat_capability` filter if ChatKit access should be available to broader roles or public visitors.

## Enabling the integration
1. Activate WP MCP AI and ChatKit.
2. Visit **ChatKit → Add-ons** and confirm that **WP MCP AI** appears in the catalog. The add-on self-registers once ChatKit fires `plugins_loaded`. Return `false` from the `wp_mcp_ai_chatkit_is_available` filter if you need to disable the registration for bespoke bootstrap flows or automated tests.【F:includes/class-wp-mcp-ai-chatkit-addon.php†L32-L109】
3. Enable the add-on inside ChatKit if the UI requires confirmation.

## Configuring the connection
Opening the **WP MCP AI** add-on in ChatKit reveals three fields sourced from the add-on definition.【F:includes/class-wp-mcp-ai-chatkit-addon.php†L186-L220】 Use them as follows:

- **Assistant ID** (required): supply the numeric post ID of the `ai_assistant` entry that should handle ChatKit conversations.
- **System Prompt Override** (optional): provide an alternate system message for ChatKit-initiated sessions when you need different guardrails from the default assistant prompt.
- **Shortcut Presets** (optional): define an array of reusable tool shortcut payloads that ChatKit surfaces to operators. Each preset accepts a `label`, raw JSON `payload`, and optional `description` to clarify when to use it.

### Shortcut preset example
Paste JSON similar to the snippet below into ChatKit’s shortcut field to preload quick actions for operators:

```json
[
  {
    "label": "Summarise latest orders",
    "payload": "{\"tool\":\"get_woo_recent_orders\",\"arguments\":{\"limit\":5}}",
    "description": "Fetch five recent WooCommerce orders and summarise them for the merchant."
  },
  {
    "label": "QA site health",
    "payload": "{\"tool\":\"get_system_logs\",\"arguments\":{\"lines\":200}}"
  }
]
```

The payload string is passed directly to the MCP AI tool registry, so it should contain whatever JSON the target tool expects.

## REST endpoints exposed to ChatKit
ChatKit calls WP MCP AI through the `mcp-ai/v1` namespace using the following routes declared in the add-on definition：【F:includes/class-wp-mcp-ai-chatkit-addon.php†L166-L180】

| Route | Method | Purpose |
| --- | --- | --- |
| `/chat` | `POST` | Stream chat completions between ChatKit and the selected assistant. |
| `/tools` | `POST` | Invoke MCP AI tools directly from ChatKit workflows. |
| `/files/{file_id}/download` | `GET` | Proxy Media Library downloads and enforce attachment permissions. |

These map to the same REST controller endpoints that power the shortcode and Elementor widgets, so existing authentication and guest token behaviour carry across.【F:includes/class-wp-mcp-ai-rest.php†L16-L2104】

## Extending the add-on
- Use the `wp_mcp_ai_chatkit_addon_definition` filter to inject additional metadata or custom fields into the ChatKit definition before it is exported.【F:includes/class-wp-mcp-ai-chatkit-addon.php†L228-L235】
- Trigger your own listeners when the add-on registers by hooking `wp_mcp_ai_chatkit_addon_registered`, which fires after the definition is handed to ChatKit’s registration callbacks.【F:includes/class-wp-mcp-ai-chatkit-addon.php†L121-L149】
- Call `WP_MCP_AI_ChatKit_Addon::reset_state_for_testing()` in automated tests to clear prior hook registrations between scenarios.【F:includes/class-wp-mcp-ai-chatkit-addon.php†L238-L251】

With these hooks you can tailor the ChatKit integration to bespoke governance policies, add environment-specific shortcuts, or wire custom logging around add-on registration events.
