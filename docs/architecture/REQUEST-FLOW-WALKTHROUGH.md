# Chat Request Flow Walkthrough

**Last Updated:** April 2026  
**Version:** 1.1.6

This document traces the complete lifecycle of a chat message from the browser through the WordPress REST API, AI provider, agentic tool execution loop, and back to the frontend via Server-Sent Events (SSE).

## Table of Contents

- [Overview](#overview)
- [1. Frontend: User Sends a Message](#1-frontend-user-sends-a-message)
- [2. WordPress REST Routing](#2-wordpress-rest-routing)
- [3. Authentication](#3-authentication)
- [4. Chat Controller Delegation](#4-chat-controller-delegation)
- [5. Main Chat Handler: Validation & Preparation](#5-main-chat-handler-validation--preparation)
- [6. Streaming SSE Setup](#6-streaming-sse-setup)
- [7. First LLM Call](#7-first-llm-call)
- [8. The Agentic Loop](#8-the-agentic-loop-tool-execution)
- [9. Tool Execution](#9-tool-execution)
- [10. Next LLM Call with Tool Results](#10-next-llm-call-with-tool-results)
- [11. Final Response Streaming](#11-final-response-streaming)
- [12. Frontend Receives & Renders](#12-frontend-receives--renders)
- [Visual Summary](#visual-summary)

---

## Overview

The NV oOS chat system uses a streaming Server-Sent Events (SSE) architecture where:
1. The browser POSTs a chat message to the REST API
2. The server opens an SSE stream and sends real-time status updates
3. The Language Model Router dispatches to the configured AI provider
4. If the AI requests tool calls, an **agentic loop** executes tools and feeds results back
5. The final response streams to the browser as SSE events

---

## 1. Frontend: User Sends a Message

**File:** `assets/js/chat.js` → `sendChat()` (~line 12916)

The browser chat UI calls `sendChat()` when the user submits a message. It:

1. **Filters messages** — removes `system` role messages (UI-only feedback)
2. **Strips metadata** — removes blob/data URLs and display-only fields via `stripMessageDisplayMetadata()`
3. **Builds payload** — constructs a JSON body with:
   - `assistant_id` — the assistant CPT post ID
   - `messages` — the full conversation array
   - `session_key` — for transcript persistence
   - `options` — provider/model/temperature overrides (optional)
   - `professional_prompt` — from professional selector (optional)
   - `additional_tools` — context-specific tools (optional)
4. **Routes by provider type:**
   - If `provider === 'embedded'` and not server-side GGUF → runs inference client-side via WebLLM (`sendChatEmbedded()`)
   - If `enableStreaming === true` → sets `payload.stream = true` and calls `sendChatStreaming()`
   - Otherwise → plain JSON POST with `postJson()`

The endpoint URL is typically `{restUrl}/mcp-ai/v1/chat-client`.

---

## 2. WordPress REST Routing

**File:** `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`

The chat controller registers two routes:

| Route | Method | Handler | Max Iterations |
|-------|--------|---------|----------------|
| `POST /mcp-ai/v1/chat` | POST | `handle_chat_request()` | 5 |
| `POST /mcp-ai/v1/chat-client` | POST | `handle_chat_client_request()` | 15 |

The `/chat` endpoint is for MCP remote clients (Claude Desktop, LM Studio). The `/chat-client` endpoint is for the browser UI and allows more agentic loop iterations.

---

## 3. Authentication

**File:** `includes/rest/class-wp-mcp-ai-rest-authenticator.php`

The `permissions_check()` callback delegates to `WP_MCP_AI_REST_Authenticator`. It checks, in priority order:

1. **WordPress Nonce** (`X-WP-Nonce` header) — standard same-origin browser requests
2. **Assistant Credentials** — plugin-issued bearer tokens (`Authorization: Bearer cred_xxxxx.SECRET`)
3. **Mesh API Keys** — federation network requests between WordPress sites
4. **Auth0 Tokens** — enterprise SSO integration (`Authorization: Bearer <Auth0-token>`)
5. **Guest Tokens** (`X-WP-MCP-AI-Guest` header) — temporary tokens for public chat surfaces

The authentication result includes `user_id`, token type, and scoped `assistant_id`.

---

## 4. Chat Controller Delegation

**File:** `includes/rest/class-wp-mcp-ai-rest-chat-controller.php`

`handle_chat_client_request()`:
1. Adds a filter to increase `max_agentic_iterations` from 5 to **15**
2. Adds a filter for Cloudflare `tool_choice` defaults
3. Delegates to the main REST controller: `$this->main_controller->handle_chat_request($request)`

---

## 5. Main Chat Handler: Validation & Preparation

**File:** `includes/class-wp-mcp-ai-rest.php` → `handle_chat_request()` (~line 2358)

This is the core orchestration method. Steps:

1. **Resolve assistant** — parses `assistant_id` (could be a team `team_XXX`, profession `prof_XXX`, or regular post ID). Loads full configuration via `WP_MCP_AI_Assistant_CPT::get_assistant_configuration()`
2. **Validate access** — checks the assistant post exists and the user has permission
3. **Sanitize messages** — `WP_MCP_AI_REST_Validator::sanitize_messages()` validates role/content pairs and extracts attachments
4. **Merge professional/profession prompts** — prepends professional prompt to system prompt if provided
5. **Merge additional tools** — context-specific tools (e.g., research page tools) are added to the assistant's tool list
6. **Sanitize options** — validates temperature, model, provider overrides
7. **Enforce limits** — `enforce_chat_request_limits()` truncates messages to fit model context window and respects max history settings
8. **Build tools payload** — `build_tools_payload()` converts the assistant's tool slug list into OpenAI-compatible function definitions by querying `WP_MCP_AI_Tool_Registry`
9. **Prepare memory documents** — loads any attached memory files
10. **Merge all options** — system prompt, temperature, model, tools, provider into `$options`

Then branches:
- **Streaming** → `handle_chat_request_with_streaming()` (SSE path)
- **Non-streaming** → returns a standard JSON `WP_REST_Response`

---

## 6. Streaming SSE Setup

**File:** `includes/class-wp-mcp-ai-rest.php` → `handle_chat_request_with_streaming()` (~line 3149)  
**File:** `includes/rest/class-wp-mcp-ai-sse-handler.php`

1. Sends SSE headers: `Content-Type: text/event-stream`, disables buffering, sets `X-Accel-Buffering: no`
2. Removes PHP time limits (`set_time_limit(0)`, `ignore_user_abort(true)`)
3. Sends initial SSE status event: `"Processing your request…"`
4. If attachments present, sends `processing_attachments` status
5. If memory documents present, sends `loading_memory` status
6. Sends `generating` status: `"Generating response…"`

---

## 7. First LLM Call

**File:** `includes/class-wp-mcp-ai-rest.php` (~line 3242)

```php
$response = $this->client->create_chat_completion( $messages, $options );
```

`$this->client` is `WP_MCP_AI_Language_Model_Router`.

**File:** `includes/class-wp-mcp-ai-language-model-router.php` → `create_chat_completion()` (~line 122)

The router:
1. Reads `$options['provider']` (e.g., `openai`, `gemini`, `anthropic`, `nvidia`, `ollama`, `lm_studio`, `huggingface`, `cloudflare`, `embedded`)
2. If provider is specified → calls `route_to_provider()` directly
3. If not specified → iterates through the `provider_priority_list` from settings, trying each until one succeeds

**`route_to_provider()`** (~line 250) dispatches to the concrete client:

| Provider Key | Client Class | Notes |
|-------------|-------------|-------|
| `openai` (default) | `WP_MCP_AI_OpenAI_Client` | GPT-4.1, GPT-5.2, o1 series |
| `gemini` | `WP_MCP_AI_Gemini_Client` | Gemini 2.0/2.5 models |
| `anthropic` | `WP_MCP_AI_Anthropic_Client` | Claude models |
| `nvidia` | `WP_MCP_AI_Nvidia_Client` | NVIDIA NIM endpoints |
| `ollama` | `WP_MCP_AI_Ollama_Client` | Local Ollama instance |
| `lm_studio` | `WP_MCP_AI_LM_Studio_Client` | Local LM Studio |
| `huggingface` | `WP_MCP_AI_Huggingface_Client` | Hugging Face Inference |
| `cloudflare` | `WP_MCP_AI_Cloudflare_Client` | Workers AI |
| `embedded` | `WP_MCP_AI_Embedded_Client` | Server-side GGUF (Pro-only) |

Each provider client makes the HTTP request to the external AI API and returns a normalized response with `choices[0].message.content` and optionally `choices[0].message.tool_calls`.

---

## 8. The Agentic Loop (Tool Execution)

**File:** `includes/class-wp-mcp-ai-rest.php` (~line 3331)

```php
while ( $iteration < $max_iterations && ! is_wp_error( $response ) ) {
```

The agentic loop runs up to `$max_iterations` times (5 for `/chat`, 15 for `/chat-client`). On each iteration:

1. **Extract tool calls** from the LLM response via `extract_tool_calls_from_response()`
2. If **no tool calls** → **break** (model gave a final text answer)
3. Send `tool_execution → start` SSE event to the browser (includes tool names and count)
4. Add the assistant's message (with tool_calls) to the conversation
5. For each tool call:
   - Send `tool_start` SSE event
   - Call `execute_tool_call_internal()` (see [§9](#9-tool-execution))
   - Normalize and sanitize the tool result
   - Send `tool_result` SSE event with the display-safe result
   - Create two versions: one for the frontend (display), one for the LLM (sanitized)
   - Append the tool result message to `$messages`
6. If any tool returned an **async pending** result (e.g., video generation) → exit loop early
7. **Validate token budget** before the next LLM call — may auto-switch to a higher-capacity model (e.g., Gemini 2.5 Flash) or truncate older messages
8. Send `"Generating response…"` SSE event
9. Call `create_chat_completion()` again with tool results appended to `$messages`
10. Increment iteration counter

---

## 9. Tool Execution

**File:** `includes/class-wp-mcp-ai-rest.php` → `execute_tool_call_internal()` (~line 9343)

1. **Parse arguments** — JSON-decode `tool_call.function.arguments`
2. **Generate slug candidates** — creates possible tool slugs from the function name (handles naming variations)
3. **Auto-enable essential tools** — for `/chat-client`, certain read-only tools (`web_search`, `get_recent_posts`, etc.) are auto-enabled
4. **Resolve and validate tool** — matches against the assistant's allowed tools list
5. **Permission check** — tool must be in the assistant's `allowed_tools`; returns 403 if not
6. **Get tool instance** from `WP_MCP_AI_Tool_Registry::get_tool($slug)`
7. **Build execution context**:
   ```php
   $context = array(
       'user_id'          => $user_id,
       'assistant_id'     => $assistant_id,
       'request'          => $request,
       'assistant_config' => $assistant_config,
       'agentic_loop'     => true,
       'iteration'        => $iteration,
       'max_iterations'   => $max_iterations,
       'endpoint'         => $request->get_route(),
   );
   ```
8. **Filter arguments** by the tool's declared schema (strips unexpected params)
9. **Async orchestration check** — `WP_MCP_AI_Async_Tool_Orchestrator::should_execute_async()` determines if the tool should run asynchronously. In the agentic loop, tools are **forced synchronous** unless marked `background-only` (e.g., video generation)
10. **Execute** — calls `$tool->execute($arguments, $context)` which runs the actual operation

---

## 10. Next LLM Call with Tool Results

**File:** `includes/class-wp-mcp-ai-rest.php` (~line 3576+)

After all tools in the iteration finish:

1. **Token budget validation** — `WP_MCP_AI_Token_Budget_Manager::validate_tpm_limit()` checks if messages fit the model's context window
2. If over budget:
   - **Auto-switch model** — if enabled, switches to `high_token_fallback_model` (default: `gemini-2.5-flash`)
   - **Truncate messages** — if switching isn't possible, truncates older messages to fit
   - Sends appropriate SSE status events (`model_switched` or `messages_truncated`)
3. Sends `"Generating response…"` SSE event
4. Calls `$this->client->create_chat_completion($messages, $options)` with the updated conversation
5. Loop continues until no more tool calls or `$max_iterations` reached

---

## 11. Final Response Streaming

**File:** `includes/class-wp-mcp-ai-rest.php` (after the while loop)

Once the agentic loop breaks (model returned text, not tool calls):

1. Extracts the final assistant message content from the response
2. Streams a `response` SSE event with:
   - Complete text content
   - Usage metadata (tokens, model, provider)
   - Tool execution messages (for frontend display)
   - Agentic tool messages (assistant's intermediate reasoning)
3. Optionally saves transcript to JetEngine CCT
4. Sends `[DONE]` SSE event
5. Calls `finish_sse()` to end the stream

---

## 12. Frontend Receives & Renders

**File:** `assets/js/chat.js` → `sendChatStreaming()` (~line 13048)

The JavaScript SSE processor uses `@microsoft/fetch-event-source`:

1. Creates a streaming placeholder bubble element in the chat UI
2. Handles SSE events by type:

| SSE Event | Frontend Action |
|-----------|----------------|
| `status` (thinking/generating) | Updates status bar ("Processing…", "Generating…") |
| `status` (model_switched) | Shows model switch notification |
| `tool_execution → start` | Shows tool execution badge |
| `tool_execution → tool_start` | Adds individual tool indicator |
| `tool_execution → tool_result` | Displays tool result in expandable section |
| `content`/`delta` | Incrementally appends text to the streaming bubble |
| `response` | Finalizes the message, renders Markdown, adds usage metadata |
| `error` | Shows error message in chat |
| `done` | Cleanup, re-enable input, save to localStorage |

3. Saves conversation to localStorage (24h TTL) and optionally to JetEngine CCT (permanent)

---

## Visual Summary

```
Browser (chat.js)
  │
  ├─ POST /wp-json/mcp-ai/v1/chat-client
  │   Accept: text/event-stream
  │
  ▼
REST_Chat_Controller::handle_chat_client_request()
  │  (sets max_iterations=15)
  ▼
REST_Chat_Controller::handle_chat_request()
  │  (delegates to main controller)
  ▼
WP_MCP_AI_REST::handle_chat_request()
  │  ├─ resolve assistant config
  │  ├─ sanitize messages
  │  ├─ enforce rate/token limits
  │  ├─ build tools payload from Tool_Registry
  │  └─ streaming? → handle_chat_request_with_streaming()
  │
  ▼
SSE_Handler::send_sse_headers()  ←── starts SSE stream
  │
  ▼
Language_Model_Router::create_chat_completion()
  │  └─ route_to_provider() → OpenAI/Gemini/Anthropic/...
  │
  ▼
┌─── AGENTIC LOOP (up to 15 iterations) ──────────┐
│                                                   │
│  extract_tool_calls_from_response()               │
│    └─ no tools? → BREAK                          │
│                                                   │
│  for each tool_call:                              │
│    SSE: tool_start                                │
│    execute_tool_call_internal()                    │
│      └─ Tool_Registry::get_tool()                │
│      └─ $tool->execute($args, $context)          │
│    SSE: tool_result                               │
│                                                   │
│  validate token budget (may switch model)         │
│  Language_Model_Router::create_chat_completion()  │
│    (with tool results appended)                   │
│                                                   │
└──────────────────────────────────────────────────┘
  │
  ▼
SSE: response (final text + usage metadata)
SSE: [DONE]
  │
  ▼
Browser: render message, save to localStorage/CCT
```

---

## Key Source Files

| Component | File | Purpose |
|-----------|------|---------|
| Chat UI | `assets/js/chat.js` | Frontend JavaScript chat interface |
| Chat Controller | `includes/rest/class-wp-mcp-ai-rest-chat-controller.php` | REST route registration and delegation |
| Main REST | `includes/class-wp-mcp-ai-rest.php` | Core chat handler, agentic loop, tool execution |
| Authenticator | `includes/rest/class-wp-mcp-ai-rest-authenticator.php` | Multi-method authentication |
| SSE Handler | `includes/rest/class-wp-mcp-ai-sse-handler.php` | SSE header/event management |
| LLM Router | `includes/class-wp-mcp-ai-language-model-router.php` | Provider routing with fallback |
| Tool Registry | `includes/class-wp-mcp-ai-tool-registry.php` | Tool registration and lookup |
| Token Manager | `includes/class-wp-mcp-ai-token-budget-manager.php` | Token budget enforcement |
| Validator | `includes/rest/class-wp-mcp-ai-rest-validator.php` | Message/option sanitization |
| Assistant CPT | `includes/assistants/class-wp-mcp-ai-assistant-cpt.php` | Assistant configuration loading |

---

**See Also:**
- [ARCHITECTURE.md](ARCHITECTURE.md) — High-level architecture overview
- [CURRENT-STATE-AGENTIC-WORKFLOW.md](core/CURRENT-STATE-AGENTIC-WORKFLOW.md) — Deep dive into agentic workflow mechanics
- [agentic-workflow-architecture.md](core/agentic-workflow-architecture.md) — Agentic workflow design patterns
