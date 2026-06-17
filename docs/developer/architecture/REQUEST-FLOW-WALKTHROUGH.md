# Request Flow Walkthrough

**Last Updated:** April 2026  
**Version:** 1.1.6

End-to-end trace of a chat message through every layer of NV oOS — from the browser to the AI provider and back.

---

## Overview

```
Browser sendChat()
  → POST /wp-json/mcp-ai/v1/chat-client
    → Authentication (5 methods)
      → Assistant resolution
        → SSE setup
          → Language Model Router (9 providers)
            → Agentic loop (up to 15 iterations)
              → Tool calls → execute → feed back
            → Token budget validation (auto-model switch)
          → SSE response chunks
        → Frontend render (markdown + tool status)
      → localStorage transcript (24 h) + optional CCT save
```

---

## 1. Frontend — `assets/js/chat.js`

The user types a message and clicks Send (or presses Enter).

1. **`sendChat()`** collects the message text, optional file attachments, and the active `assistant_id`.
2. Messages are bundled (client-side batching reduces API calls when the user sends multiple messages quickly).
3. An `EventSource`-compatible SSE connection opens via `POST /wp-json/mcp-ai/v1/chat-client`.
4. Headers include:
   - `X-WP-Nonce` (logged-in WordPress user) **or**
   - `X-WP-MCP-AI-Guest: <guest_token>` (public chat surface) **or**
   - `Authorization: Bearer <credential>` (remote MCP client / Auth0)

**Key source:** `assets/js/chat.js` (SSE service integration ~line 10100+)

---

## 2. REST Routing

The WordPress REST API dispatches the request to:

```
Route:    /wp-json/mcp-ai/v1/chat-client  (POST)
Class:    WP_MCP_AI_REST_Chat_Controller
Method:   handle_chat_client_request()
File:     includes/rest/class-wp-mcp-ai-rest-chat-controller.php
```

The controller delegates to the main `WP_MCP_AI_REST` class (10,215 lines), which orchestrates the full lifecycle.

---

## 3. Authentication — `includes/rest/class-wp-mcp-ai-rest-authenticator.php`

`authenticate()` tries each method in order; the first success wins:

| # | Method | Header / Mechanism | Validator |
|---|--------|-------------------|-----------|
| 1 | WordPress Nonce | `X-WP-Nonce` | `wp_verify_nonce()` with `wp_rest` action |
| 2 | Assistant Credential | `Authorization: Bearer cred_xxxxx.SECRET` | `validate_local_token()` — hash lookup in post meta |
| 3 | Mesh API Key | `Authorization: Bearer <mesh_key>` | `validate_mesh_key()` — cross-site federation |
| 4 | Auth0 JWT | `Authorization: Bearer <jwt>` | `validate_bearer_token()` — JWKS verification, audience & scope |
| 5 | Guest Token | `X-WP-MCP-AI-Guest` header | `extract_guest_token()` — temporary token with TTL |

On success the authenticator populates `$auth_context` (user ID, is_guest flag, assistant_id hint, etc.).

---

## 4. Assistant Resolution

```php
$assistant_id   = $request->get_param( 'assistant_id' );
$assistant_post = get_post( $assistant_id );         // CPT: mcp_ai_assistant
$assistant_config = get_post_meta( ... );             // tools[], system_prompt, model, provider, etc.
```

The assistant configuration determines:
- Which **tools** are available (`$assistant_config['tools']` — array of slugs)
- Which **AI provider & model** to use
- The **system prompt** (personality, constraints)
- **Max agentic iterations** (default 15 for chat-client, filterable via `wp_mcp_ai_max_agentic_iterations`)
- **Required capability** (`edit_posts`, `read`, or `public` for guest access)

---

## 5. SSE Setup

Before calling the AI provider, the handler sends SSE headers:

```
Content-Type: text/event-stream; charset=UTF-8
Cache-Control: no-cache, no-store, must-revalidate, no-transform
X-Accel-Buffering: no
```

Constants:
- `RETRY_INTERVAL_MS = 3000` (client reconnect hint)
- `STREAMING_CHUNK_SIZE = 50` (characters per SSE data frame)

The first SSE event is a `status` event with `type: "thinking"`.

---

## 6. Language Model Router — `includes/class-wp-mcp-ai-language-model-router.php`

`route_to_provider()` maps the assistant's configured provider string to a client class:

| Provider string | Client class | Notes |
|----------------|-------------|-------|
| `openai` | `WP_MCP_AI_OpenAI_Client` | Default fallback |
| `gemini` | `WP_MCP_AI_Gemini_Client` | Google AI Studio / Vertex |
| `anthropic` | `WP_MCP_AI_Anthropic_Client` | Claude models |
| `nvidia` | `WP_MCP_AI_Nvidia_Client` | NVIDIA NIM endpoints |
| `huggingface` | `WP_MCP_AI_Huggingface_Client` | Inference API |
| `cloudflare` | `WP_MCP_AI_Cloudflare_Client` | Workers AI |
| `ollama` | `WP_MCP_AI_Ollama_Client` | Local models |
| `lm_studio` | `WP_MCP_AI_LM_Studio_Client` | Local models |
| `embedded` | `WP_MCP_AI_Embedded_Client` | Pro — on-device (Transformers.js) |

The client's `create_chat_completion()` method is called with:
- `$messages` — conversation history (system + user + assistant + tool messages)
- `$options` — model name, temperature, max_tokens, tool definitions, stream flag

---

## 7. Agentic Loop — `includes/class-wp-mcp-ai-rest.php`

The agentic loop is the core innovation — it allows the AI to call tools iteratively:

```
┌─────────────────────────────────────────────────┐
│              Agentic Loop                       │
│  iteration = 0                                  │
│  max_iterations = 15  (chat-client default)     │
│                                                 │
│  while (iteration < max_iterations):            │
│    response = provider.create_chat_completion() │
│    tool_calls = extract_tool_calls(response)    │
│                                                 │
│    if no tool_calls → break (final answer)      │
│                                                 │
│    for each tool_call:                          │
│      ├─ SSE: tool_execution {type: tool_start}  │
│      ├─ execute_tool_call_internal()            │
│      ├─ SSE: tool_execution {type: tool_result} │
│      └─ append role='tool' message              │
│                                                 │
│    validate_tpm_limit(messages, model)          │
│      ├─ Over limit? Try auto-model-switch       │
│      ├─ Still over? Truncate messages           │
│      └─ Still over? Break with error            │
│                                                 │
│    iteration++                                  │
│  end while                                      │
│                                                 │
│  stream final response via SSE                  │
└─────────────────────────────────────────────────┘
```

### 7a. Tool Call Extraction

`extract_tool_calls_from_response()` normalises across providers:
- OpenAI/Anthropic: `response['choices'][0]['message']['tool_calls']`
- Gemini: `response['tool_calls']` (pre-normalised by client)

Each tool call contains:
```json
{
  "id": "call_abc123",
  "type": "function",
  "function": {
    "name": "create_post",
    "arguments": "{\"title\":\"Hello World\",\"content\":\"...\"}"
  }
}
```

### 7b. Tool Execution — `execute_tool_call_internal()`

1. **Parse arguments** — JSON-decode the `function.arguments` string
2. **Generate slug candidates** — e.g. `create_post`, `create-post`, `createPost`
3. **Permission check** — Is this tool in the assistant's allowed tools list?
4. **Auto-enable exception** — The `/chat-client` endpoint auto-enables a set of safe read-only tools (`web_search`, `get_recent_posts`, `search_attachments`, etc.)
5. **Guest bypass** — If `$auth_context['is_guest']` is true and the tool allows guest execution
6. **Registry lookup** — `WP_MCP_AI_Tool_Registry::get_instance()->get_tool( $slug )`
7. **Execute** — `$tool->execute( $arguments, $context )` where `$context` includes:
   - `user_id`, `assistant_id`, `request`
   - `guest_request` flag
   - `iteration`, `max_iterations`
   - `tool_call_id`, `session_id`
8. **Normalise result** — `normalize_tool_result()` converts WP_Error to serialisable format

### 7c. Token Budget Validation

Between iterations, `WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit()` checks whether the accumulated messages fit within the model's token-per-minute limit:

1. **Within budget** → Continue normally
2. **Over budget, auto-switch enabled** → Switch to fallback model (default: `gemini-2.5-flash`), log `agentic_model_switched` event, send SSE `status` with `type: "model_switched"`
3. **Over budget, switch fails** → Truncate older messages via `truncate_messages()`, send SSE `status` with `type: "messages_truncated"`
4. **Still over budget** → Break loop, return partial response

Safety margin: `TPM_SAFETY_MARGIN = 0.9` (use only 90 % of limit).

---

## 8. SSE Events — Response Streaming

Events sent to the browser during the lifecycle:

| SSE Event | `data.type` | When |
|-----------|-------------|------|
| `status` | `thinking` | Initial processing begins |
| `status` | `processing_attachments` | File attachments being analysed |
| `status` | `loading_memory` | Memory documents being loaded |
| `status` | `generating` | AI is generating a response |
| `status` | `model_switched` | Fallback model activated (TPM) |
| `status` | `messages_truncated` | Context truncated for limits |
| `status` | `max_iterations` | Tool loop limit reached |
| `tool_execution` | `start` | Agentic tool execution begins |
| `tool_execution` | `tool_start` | Individual tool starting |
| `tool_execution` | `tool_result` | Individual tool completed |
| `message` | (response text) | Final AI response chunks |
| `error` | (error details) | Error at any stage |

All events follow the SSE specification (RFC 6202):
```
event: status
data: {"type":"thinking","message":"Processing your request..."}

event: message
data: {"content":"Here is the answer...","role":"assistant"}
```

---

## 9. Frontend Render — `assets/js/chat.js`

1. SSE events are parsed as they arrive
2. `status` events update the UI indicator (typing dots, tool badges)
3. `tool_execution` events show real-time tool progress
4. `message` events are appended to the chat, rendered as Markdown (with code highlighting)
5. On stream completion:
   - Transcript saved to `localStorage` (key: `wp_mcp_ai_chat_*`, TTL: 24 hours)
   - If JetEngine CCT is configured, transcript also saved server-side via REST
   - Cost data (if available) displayed in the chat footer

---

## Key Source Files

| File | Lines | Purpose |
|------|-------|---------|
| `includes/class-wp-mcp-ai-rest.php` | 10,215 | Main REST API: agentic loop, tool execution, SSE streaming |
| `includes/class-wp-mcp-ai-language-model-router.php` | ~300 | Provider routing (9 providers) |
| `includes/rest/class-wp-mcp-ai-rest-authenticator.php` | ~700 | 5-method authentication chain |
| `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` | ~600 | Chat-client route registration |
| `includes/rest/class-wp-mcp-ai-sse-handler.php` | ~200 | SSE event formatting & headers |
| `includes/class-wp-mcp-ai-tool-registry.php` | ~200 | Tool registry singleton |
| `includes/services/class-wp-mcp-ai-token-budget-service.php` | ~300 | Token budget management |
| `assets/js/chat.js` | ~12,000 | Frontend chat UI, SSE client, message rendering |

---

## Filters & Hooks in the Request Path

| Hook | Type | Default | Purpose |
|------|------|---------|---------|
| `wp_mcp_ai_max_agentic_iterations` | Filter | 15 (chat-client) | Max tool-call loop iterations |
| `wp_mcp_ai_before_chat_request` | Action | — | Fires before AI provider call |
| `wp_mcp_ai_after_chat_response` | Action | — | Fires after AI response received |
| `wp_mcp_ai_before_tool_execution` | Action | — | Fires before each tool runs |
| `wp_mcp_ai_after_tool_execution` | Action | — | Fires after each tool completes |
| `wp_mcp_ai_tool_response` | Filter | tool result | Filter tool output before feeding back |
| `wp_mcp_ai_authenticate_request` | Filter | user_id | Custom authentication logic |
| `wp_mcp_ai_chat_capability` | Filter | `edit_posts` | Required capability for chat |
| `wp_mcp_ai_model_config` | Filter | assistant config | Override model/provider selection |

See [hooks-reference.md](../hooks-reference.md) for the full 543+ hook catalogue.

---

**See also:**
- [ARCHITECTURE.md](ARCHITECTURE.md) — High-level architecture overview
- [ORCHESTRATION-LAYER-ARCHITECTURE.md](orchestration/ORCHESTRATION-LAYER-ARCHITECTURE.md) — Orchestration layer deep-dive
- [CURRENT-STATE-AGENTIC-WORKFLOW.md](core/CURRENT-STATE-AGENTIC-WORKFLOW.md) — Agentic workflow reference
