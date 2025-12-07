# WP oOS REST API

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
  "capabilities": {
    "tools": { "listChanged": false },
    "resources": { "subscribe": false, "listChanged": false }
  },
  "implementation": {
    "name": "WP oOS",
    "version": "1.0.0"
  },
  "rest": {
    "namespace": "mcp-ai/v1",
    "base": "https://example.com/wp-json/mcp-ai/v1",
    "chat": "https://example.com/wp-json/mcp-ai/v1/chat",
    "tools": "https://example.com/wp-json/mcp-ai/v1/tools",
    "file_download": "https://example.com/wp-json/mcp-ai/v1/files",
    "sse": "https://example.com/wp-json/mcp-ai/v1/sse"
  }
}
```

The directory omits sensitive payloads—such as system prompts or tool shortcut bodies—while still surfacing enough context for remote clients to decide which assistant to target. `capabilities` advertises MCP-compatible features (tools and downloadable resources) so clients like LM Studio and Claude Desktop can enable the right UI affordances, while `implementation` identifies the server name/version for debugging. The `token_scope` block is present whenever authentication happened via a bearer credential so integrators can detect assistant-specific restrictions programmatically.【F:includes/class-wp-mcp-ai-rest.php†L635-L666】【F:includes/class-wp-mcp-ai-rest.php†L771-L801】

### Streaming directory responses

- Clients that expect a dedicated streaming endpoint can call `GET /wp-json/mcp-ai/v1/sse`. The controller forces streaming mode and emits the same `directory` payload as the standard `/assistants` route, making LM Studio’s MCP handshake succeed even when it probes `/sse` directly.【F:includes/class-wp-mcp-ai-rest.php†L398-L409】【F:includes/class-wp-mcp-ai-rest.php†L706-L715】

- Add an `Accept: text/event-stream` header (or a `stream=true` flag) when calling the directory to receive the payload as a Server-Sent Events frame. The controller emits a single `directory` event containing the JSON response before closing the stream so MCP clients that expect SSE handshakes can connect successfully.【F:includes/class-wp-mcp-ai-rest.php†L520-L666】【F:includes/class-wp-mcp-ai-rest.php†L1690-L1827】
- Mixed `Accept` headers are supported—the transport scans the entire header for `text/event-stream`, even when other MIME types or quality hints are present—so desktop MCP clients that append `application/json` still negotiate streaming. Responses advertise `Content-Type: text/event-stream; charset=UTF-8`, `Cache-Control: ... no-transform`, `Access-Control-Allow-Origin: *`, `Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce`, and `Access-Control-Allow-Methods: GET, POST, OPTIONS` while marking the response as `Vary: Accept, Authorization` so caches and WAFs honour the streaming handshake.【F:includes/class-wp-mcp-ai-rest.php†L1704-L1778】
- Some edge networks (including Cloudflare and Sucuri) challenge unusual MCP user agents. If LM Studio or Claude Desktop receive timeouts, configure a WAF exception for `Accept: text/event-stream` requests or ensure the client sends a mainstream `User-Agent` string so the challenge is skipped. Once the request passes the edge, the plugin immediately returns the SSE payload described above.【9bafa2†L1-L33】

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

Whenever attachments are included, the controller temporarily adds the Submit Document Prompt tool to the assistant so uploads reach the model without requiring administrators to toggle the tool manually.【F:includes/class-wp-mcp-ai-rest.php†L931-L1007】 If JSON Lines files are permitted in the settings the plugin also registers the `.jsonl` and `.ndjson` extensions with WordPress to keep Media Library uploads compatible.【F:mcp-ai-wpoos.php†L236-L272】

### Response

Successful requests return the assistant ID and the raw response payload from the language model router. Failed provider calls convert into `WP_Error` objects with actionable remediation guidance and provider error codes when available.【F:includes/class-wp-mcp-ai-rest.php†L1033-L1095】【F:includes/class-wp-mcp-ai-rest.php†L1097-L1159】

### Streaming responses (Server-Sent Events)

- The chat route only registers the `CREATABLE` method, so streaming clients **must issue a POST request**—attempting a GET will return a `404` even when the path exists.【F:includes/class-wp-mcp-ai-rest.php†L238-L322】
- Supply either a `stream` flag in the body (for example, `{ "stream": true }`) or set the `Accept` header to `text/event-stream` to flip the controller into SSE mode.【F:includes/class-wp-mcp-ai-rest.php†L1588-L1667】
- When streaming is active the server replies with `Content-Type: text/event-stream; charset=UTF-8`, disables caching with `no-transform`, and flushes frames as they are generated so clients can process partial completions in real time.【F:includes/class-wp-mcp-ai-rest.php†L1668-L1772】
- Authentication is unchanged—continue sending either an Auth0 bearer token or an assistant-issued credential via `Authorization: Bearer …`, or a WordPress REST nonce when calling from the same origin.【F:docs/mcp-server-authentication.md†L11-L34】

### Connect LM Studio to WP oOS

LM Studio can act as an MCP client by pointing its `mcp.json` configuration at the plugin’s REST endpoints. Follow this checklist to make the connection reliable:

1. **Verify the REST base URL.** Open `https://your-site.example/wp-json/mcp-ai/v1/assistants` in a browser or via `curl` to confirm the endpoint responds and that your assistant is visible to the credential you plan to use. The route is registered under the `mcp-ai/v1` namespace and requires authentication for anything beyond public assistants. 【F:includes/class-wp-mcp-ai-rest.php†L234-L355】
2. **Generate a bearer credential.** Use one of the supported flows—Auth0 access tokens, assistant-issued credentials, WordPress REST nonces, guest tokens, or Simple JWT Login tokens—to authorise the client. JWT-based setups should follow the steps in [docs/authentication.md](authentication.md) to mint and verify tokens before wiring them into LM Studio. 【F:docs/authentication.md†L1-L123】【F:includes/class-wp-mcp-ai-rest.php†L289-L343】
3. **Create a `chat` request entry.** In LM Studio’s `mcp.json`, configure a POST request that targets `https://your-site.example/wp-json/mcp-ai/v1/chat`, sets `Content-Type: application/json`, and includes the `Authorization: Bearer …` header. The chat route only supports POST and runs the same permission callback as the assistants index, so failing to include the header or using the wrong method returns a 401/404. 【F:includes/class-wp-mcp-ai-rest.php†L264-L337】
4. **Enable streaming when desired.** Add `"Accept": "text/event-stream"` or a `"stream": true` flag in the request body to opt into Server-Sent Events. The REST controller inspects both signals before invoking its streaming response helper. 【F:includes/class-wp-mcp-ai-rest.php†L1633-L1719】
5. **Test the credential.** Use LM Studio’s MCP test panel (or run a standalone `curl` command) to issue a simple prompt. Successful responses return the assistant ID alongside the provider payload; errors bubble up with actionable WP error codes that mirror the REST controller’s logs. 【F:includes/class-wp-mcp-ai-rest.php†L1573-L1638】

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

> **Note:** LM Studio 0.3.x sometimes drops the `"method": "POST"` line after editing the configuration in its UI. If that happens the client silently falls back to GET and WordPress returns a `404` because the chat endpoint only accepts POST. Re-add the method property before saving to avoid the regression and to stay compatible with other MCP servers.

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

- Verify that the authenticated account retains the capability enforced by `wp_mcp_ai_get_required_chat_capability()` (defaults to `edit_posts`) when testing with WordPress nonces.【F:mcp-ai-wpoos.php†L21-L67】【F:includes/class-wp-mcp-ai-rest.php†L289-L343】
- For assistant-issued credentials, ensure the client does not override `assistant_id`; the REST layer rejects scope mismatches with `wp_mcp_ai_assistant_scope_mismatch` errors.【F:includes/class-wp-mcp-ai-rest.php†L1288-L1336】
- Inspect structured error responses for an `actions` array that explains the remediation steps returned by the REST controller.【F:includes/class-wp-mcp-ai-rest.php†L40-L118】【F:includes/class-wp-mcp-ai-rest.php†L360-L401】
