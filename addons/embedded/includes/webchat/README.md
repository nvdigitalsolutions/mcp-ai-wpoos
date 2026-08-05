# WebChat

## Purpose

Manages decentralized P2P WebChat rooms — custom post type, WebRTC signaling, JetEngine CCT message storage, and the tool classes that AI assistants use to interact with rooms.

## Tier

| | |
|---|---|
| **Distribution** | Pro addon (`addons/embedded/`) |
| **PHP target** | 7.4+ |
| **Loaded by** | `NV_oOS_Embedded::maybe_load_webchat()` (gated on `enable_webchat_integration` setting) |
| **Optional dependencies** | JetEngine (for CCT message storage; CPT always available) |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_WebChat_CPT` | `class-wp-mcp-ai-webchat-cpt.php` | `NV_oOS_Embedded::maybe_load_webchat()` |
| `WP_MCP_AI_WebChat_Signaling_REST_Controller` | `class-wp-mcp-ai-webchat-signaling-rest-controller.php` | REST API (`rest_api_init`) |
| `WP_MCP_AI_JetEngine_WebChat_Messages_CCT` | `class-wp-mcp-ai-jetengine-webchat-messages-cct.php` | JetEngine bootstrap |
| `WP_MCP_AI_WebChat_Settings_Page` | `class-wp-mcp-ai-webchat-settings-page.php` | Admin UI |
| `WP_MCP_AI_Tool_Create_WebChat_Room` | `tools/class-wp-mcp-ai-tool-create-webchat-room.php` | Tool registry |
| `WP_MCP_AI_Tool_Get_WebChat_Room` | `tools/class-wp-mcp-ai-tool-get-webchat-room.php` | Tool registry |
| `WP_MCP_AI_Tool_List_WebChat_Rooms` | `tools/class-wp-mcp-ai-tool-list-webchat-rooms.php` | Tool registry |
| `WP_MCP_AI_Tool_Get_WebChat_Status` | `tools/class-wp-mcp-ai-tool-get-webchat-status.php` | Tool registry |
| `WP_MCP_AI_Tool_Get_WebChat_Messages` | `tools/class-wp-mcp-ai-tool-get-webchat-messages.php` | Tool registry |
| `WP_MCP_AI_Tool_Save_WebChat_Message` | `tools/class-wp-mcp-ai-tool-save-webchat-message.php` | Tool registry |
| `WP_MCP_AI_Pro_Tool_Send_WebChat_Message` | `tools/class-wp-mcp-ai-tool-send-webchat-message.php` | Tool registry |

## Inputs / Outputs / Neighbors

- **Reads from:** `wp_mcp_ai_settings` (`enable_webchat_integration`), post meta (`mcp_ai_webchat` CPT)
- **Writes to:** `mcp_ai_webchat` CPT posts, JetEngine CCT (`webchat_messages`), `wp_mcp_ai_settings`
- **Upstream callers:** `NV_oOS_Embedded`, Tool registry (`wp_mcp_ai_register_tools`), REST API
- **Downstream collaborators:** JetEngine (CCT storage), WordPress REST API infrastructure
- **Events listened to:** `rest_api_init` (signaling controller), `wp_mcp_ai_register_tools`

## Conventions

- All 7 tool classes implement `WP_MCP_AI_Tool_Interface`. Every `execute()` returns the canonical envelope.
- REST signaling endpoints use `permission_callback` — never `__return_true` on state-changing routes.
- WebChat CPT slug is `mcp_ai_webchat` (registered once in `WP_MCP_AI_WebChat_CPT::init()`).
- Metabox classes follow the base class pattern (`WP_MCP_AI_WebChat_Metabox_Base`).

## Tests

```bash
vendor/bin/phpunit tests/php/test-webchat-assistant-assignment.php
vendor/bin/phpunit tests/php/test-jetengine-webchat-cct-module-access.php
```

## Also Load

- `.context/conventions.md` — naming + style
- `.context/security-checklist.md` — security
- `CLAUDE.md` — PHP compat, tool patterns, canonical envelope
