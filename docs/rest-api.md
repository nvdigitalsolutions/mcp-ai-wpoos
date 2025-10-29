# MCP AI REST API

The plugin exposes its REST surface at `/wp-json/mcp-ai/v1` for both chat completions and direct tool execution. This document summarises the available endpoints, request/response contracts, and common integration pitfalls.

## Authentication recap

All endpoints honour the authentication modes described in [docs/mcp-server-authentication.md](mcp-server-authentication.md):

- **Auth0 bearer tokens** – Supply `Authorization: Bearer <token>` with an access token whose issuer, audience, and scopes match the plugin settings.【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L520-L823】
- **Assistant-issued credentials** – Pass the one-time token generated in the assistant editor (`cred_xxxxx.SECRET`). The REST controller hashes and validates the credential, scopes the request to the issuing assistant, and records an audit log entry for successful usage.【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L426-L520】
- **WordPress REST nonces** – Same-origin clients (dashboard UI, shortcodes) may send the `X-WP-Nonce` header tied to the authenticated session. Capabilities are enforced after nonce verification.【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L360-L401】
- **Guest tokens** – Shortcodes and the Elementor widget mint one-hour guest tokens when `allow_guests="true"` is enabled. Include the token via the `X-WP-MCP-AI-Guest` header or `guest_token` parameter to bypass the default `edit_posts` requirement safely.【F:includes/class-wp-mcp-ai-rest.php†L289-L343】【F:includes/class-wp-mcp-ai-rest.php†L1288-L1336】

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
