# MCP AI REST API

The plugin exposes its REST surface at `/wp-json/mcp-ai/v1` for both chat completions and direct tool execution. This document summarises the available endpoints, request/response contracts, and common integration pitfalls.

## Authentication recap

All endpoints honour the authentication modes described in [docs/mcp-server-authentication.md](mcp-server-authentication.md):

- **Auth0 bearer tokens** – Supply `Authorization: Bearer <token>` with an access token whose issuer, audience, and scopes match the plugin settings.【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L520-L823】
- **Assistant-issued credentials** – Pass the one-time token generated in the assistant editor (`cred_xxxxx.SECRET`). The REST controller hashes and validates the credential, scopes the request to the issuing assistant, and records an audit log entry for successful usage.【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L426-L520】
- **WordPress REST nonces** – Same-origin clients (dashboard UI, shortcodes) may send the `X-WP-Nonce` header tied to the authenticated session. Capabilities are enforced after nonce verification.【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L360-L401】
- **Guest tokens** – Shortcodes and the Elementor widget mint one-hour guest tokens when `allow_guests="true"` is enabled. Include the token via the `X-WP-MCP-AI-Guest` header or `guest_token` parameter to bypass the default `edit_posts` requirement safely.【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L1288-L1336】

## GET `/assistants`

List every assistant the authenticated caller can reach. Assistant-issued credentials are scoped to their issuing post, so tokens minted from the editor return a single entry while Auth0 tokens, REST nonces, or public capability overrides surface all published assistants the caller may read.【F:includes/class-wp-mcp-ai-rest.php†L238-L462】【F:includes/class-wp-mcp-ai-rest.php†L491-L595】

### Query parameters

| Name | Type | Description |
| --- | --- | --- |
| `search` | string | Optional text search that matches post titles and content. |
| `include` | array or comma-separated list | Restrict the response to specific assistant IDs while retaining scope checks. |

Developers can adjust the underlying `WP_Query` arguments or transform the response payload via `wp_mcp_ai_rest_assistant_query_args`, `wp_mcp_ai_rest_assistant_summary`, and `wp_mcp_ai_rest_assistant_index` filters when customising the directory for downstream clients.【F:includes/class-wp-mcp-ai-rest.php†L267-L462】【F:includes/class-wp-mcp-ai-rest.php†L491-L561】【F:includes/class-wp-mcp-ai-rest.php†L575-L595】

### Response

```json
{
  "assistants": [
    {
      "id": 123,
      "title": "Customer Success",
      "status": "publish",
      "provider": "openai",
      "model": "gpt-4o-mini",
      "tool_count": 5,
      "tools": ["search_attachments", "run_openai_external_action"],
      "memory_file_count": 2,
      "has_vector_store": true,
      "description": "Handles onboarding questions and MCP workflows.",
      "updated_at": "2024-06-15T12:34:56",
      "permalink": "https://example.com/?post_type=mcp_ai_assistant&p=123",
      "is_default": true
    }
  ],
  "default_assistant": 123,
  "token_scope": {
    "type": "local_token",
    "assistant_id": 123
  },
  "rest": {
    "namespace": "mcp-ai/v1",
    "base": "https://example.com/wp-json/mcp-ai/v1",
    "chat": "https://example.com/wp-json/mcp-ai/v1/chat",
    "tools": "https://example.com/wp-json/mcp-ai/v1/tools",
    "file_download": "https://example.com/wp-json/mcp-ai/v1/files"
  }
}
```

The directory omits sensitive payloads—such as system prompts or tool shortcut bodies—while still surfacing enough context for remote clients to decide which assistant to target. The `token_scope` block is present whenever authentication happened via a bearer credential so integrators can detect assistant-specific restrictions programmatically.【F:includes/class-wp-mcp-ai-rest.php†L435-L462】

## POST `/chat`

Send a chat payload to the configured language model while inheriting assistant defaults.

### Request body

```json
{
  "assistant_id": 123,
  "messages": [
    { "role": "user", "content": [{ "type": "text", "text": "Hello" }] }
  ],
  "options": {
    "response_format": { "type": "json_schema", "json_schema": { "name": "example" } }
  }
}
```

- `assistant_id` is optional; when omitted the REST layer falls back to the default assistant configured in plugin settings.【F:includes/class-wp-mcp-ai-rest.php†L1162-L1237】
- `messages` must be an array of role/content pairs. The controller normalises text segments, validates attachments, and enforces upload safety rules before hitting the model. Default allowlists cover Markdown, CSV/TSV, HTML, JSON/JSONL/NDJSON, XML, PDFs, Microsoft Office documents, AAC/FLAC/M4A/MP3/OGG/OPUS/WAV/WEBM audio, and MP4 or QuickTime video so assistants can reason over a broad set of media formats.【F:includes/class-wp-mcp-ai-rest.php†L230-L322】【F:includes/class-wp-mcp-ai-rest.php†L931-L1031】【F:includes/class-wp-mcp-ai-message-attachments.php†L642-L703】
- `options` inherit the assistant’s stored defaults and can include overrides for model, temperature, response format, and additional attachments or memory files.【F:includes/class-wp-mcp-ai-rest.php†L931-L1095】

Whenever attachments are included, the controller temporarily adds the Submit Document Prompt tool to the assistant so uploads reach the model without requiring administrators to toggle the tool manually.【F:includes/class-wp-mcp-ai-rest.php†L931-L1007】 If JSON Lines files are permitted in the settings the plugin also registers the `.jsonl` and `.ndjson` extensions with WordPress to keep Media Library uploads compatible.【F:wp-mcp-ai.php†L236-L272】

### Response

Successful requests return the assistant ID and the raw response payload from the language model router. Failed provider calls convert into `WP_Error` objects with actionable remediation guidance and provider error codes when available.【F:includes/class-wp-mcp-ai-rest.php†L1033-L1095】【F:includes/class-wp-mcp-ai-rest.php†L1097-L1159】

### Streaming responses (Server-Sent Events)

- The chat route only registers the `CREATABLE` method, so streaming clients **must issue a POST request**—attempting a GET will return a `404` even when the path exists.【F:includes/class-wp-mcp-ai-rest.php†L238-L322】
- Supply either a `stream` flag in the body (for example, `{ "stream": true }`) or set the `Accept` header to `text/event-stream` to flip the controller into SSE mode.【F:includes/class-wp-mcp-ai-rest.php†L1588-L1667】
- When streaming is active the server replies with `Content-Type: text/event-stream`, disables caching, and flushes frames as they are generated so clients can process partial completions in real time.【F:includes/class-wp-mcp-ai-rest.php†L1668-L1695】
- Authentication is unchanged—continue sending either an Auth0 bearer token or an assistant-issued credential via `Authorization: Bearer …`, or a WordPress REST nonce when calling from the same origin.【F:docs/mcp-server-authentication.md†L11-L34】

Example `mcp.json` block for an LM Studio client:

```json
{
  "mcpServers": {
    "NV Digital": {
      "url": "https://bots.nvdigital.solutions/wp-json/mcp-ai/v1/chat",
      "method": "POST",
      "headers": {
        "Authorization": "Bearer cred_xxxxx.SECRET",
        "Content-Type": "application/json",
        "Accept": "text/event-stream"
      },
      "body": {
        "stream": true
      }
    }
  }
}
```

Replace `cred_xxxxx.SECRET` with either the Auth0 access token issued for your tenant or the assistant credential generated from the editor UI before saving the configuration.【F:docs/mcp-server-authentication.md†L11-L34】

## POST `/tools`

Execute a registered tool without generating a full chat turn.

### Request body

```json
{
  "assistant_id": 123,
  "tool": "run_openai_external_action",
  "arguments": {
    "identifier": "support/escalation",
    "payload": { "ticket_id": 42 }
  }
}
```

- `assistant_id` follows the same defaulting behaviour as the chat endpoint and is automatically scoped when assistant credentials are used.【F:includes/class-wp-mcp-ai-rest.php†L1162-L1336】
- `tool` must reference a registered tool slug that the assistant is allowed to execute. Document prompt uploads automatically enable the Submit Document Prompt tool for the current request so file attachments succeed.【F:includes/class-wp-mcp-ai-rest.php†L1162-L1250】
- `arguments` are passed through to the tool implementation after assistant defaults (such as external action identifiers) are merged in.【F:includes/class-wp-mcp-ai-rest.php†L1252-L1321】

### Response

Tool responses include the assistant ID, the tool slug, and the tool result. Errors bubble up as `WP_Error` instances so remote clients can react to permission failures, missing dependencies, or validation issues. Every execution is logged for auditing.【F:includes/class-wp-mcp-ai-rest.php†L1252-L1321】

## Troubleshooting tips

- Verify that the authenticated account retains the capability enforced by `wp_mcp_ai_get_required_chat_capability()` (defaults to `edit_posts`) when testing with WordPress nonces.【F:wp-mcp-ai.php†L21-L67】【F:includes/class-wp-mcp-ai-rest.php†L289-L343】
- For assistant-issued credentials, ensure the client does not override `assistant_id`; the REST layer rejects scope mismatches with `wp_mcp_ai_assistant_scope_mismatch` errors.【F:includes/class-wp-mcp-ai-rest.php†L1288-L1336】
- Inspect structured error responses for an `actions` array that explains the remediation steps returned by the REST controller.【F:includes/class-wp-mcp-ai-rest.php†L40-L118】【F:includes/class-wp-mcp-ai-rest.php†L360-L401】
