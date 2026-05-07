# NV oOS Chat SPA

Modern React chat surface for NV oOS, built on top of the
[Vercel AI SDK UI layer](https://sdk.vercel.ai/docs/ai-sdk-ui) (`@ai-sdk/react`'s
`useChat` hook). Drop-in replacement for the legacy jQuery-era `assets/js/chat.js`
UI.

The SPA talks **only** to the existing NV oOS REST chat endpoints
(`mcp-ai/v1/chat-client`, `/chat-transcripts`, `/chat-memory`) — **no Node
server is introduced**. The WordPress PHP layer remains the orchestrator and
the AI provider gateway, so every existing capability (HITL, harness layers,
memory bridge, guest tokens, JetEngine transcripts, providers, tool registry)
keeps working.

This addon was scaffolded from the
[Toolkit SPA Blueprint](../../docs/addons/toolkit-spa-blueprint.md).

## Quick start

```bash
cd addons/chat-spa
npm ci
npm run build       # produces assets/dist/chat-spa.{js,css}
```

Add the shortcode to any post or page:

```text
[nvoos_chat_spa assistant_id="123" theme="auto" height="600px" guest="0"]
```

Or use the Gutenberg block **NV oOS Chat** (`nvoos/chat-spa`).

## Architecture

```
React (useChat)
   │
   │  custom fetch + SSE → AI SDK Data Stream Protocol adapter
   │  (src/sse-adapter.ts)
   │
   ▼
mcp-ai/v1/chat-client  (existing NV oOS REST route — emits NV oOS SSE)
   │
   ▼
PHP orchestrator → providers (OpenAI, Gemini, Ollama, ...)
```

The SPA uses the Vercel AI SDK **only on the React side**:

- ✅ AI SDK UI (`@ai-sdk/react`) — `useChat` hook, message state, regenerate/stop, etc.
- ❌ AI SDK Core (`ai` package) — **not** used. The PHP `WP_MCP_AI_Language_Model_Router` is the source of truth for provider calls.

The `streamProtocol: 'data'` mode of `useChat` consumes the AI SDK Data Stream
Protocol. NV oOS today emits a custom SSE format, so a **client-side adapter**
([`src/sse-adapter.ts`](src/sse-adapter.ts)) translates each NV oOS frame
(`message_delta`, `tool_call_started`, `tool_call_completed`, `memory_event`,
`done`) into the matching Data Stream chunk (`0:`, `2:`, `8:`, `e:`). This
keeps the PHP layer unchanged for v1; v2 may graduate to native Data Stream
emission via content negotiation.

## Version bump rule

When the SPA bundle changes, bump **all three** in the same commit:

1. `Version:` header in `nvoos-chat-spa.php`
2. `define( 'NVOOS_CHAT_SPA_VERSION', '…' );`
3. `"version"` in `package.json`

This forces `?ver=` query strings to invalidate browser caches.

## REST namespace

`/wp-json/nvoos-chat-spa/v1/*` — addon-specific concerns only:

- `GET /health` — admin-only liveness probe
- `GET /manifest` — addon metadata + bundle URLs
- `GET /config` — endpoint URLs + feature flags for non-shortcode mounts

Domain data flows through the existing `mcp-ai/v1/*` chat routes.

See [`includes/rest/class-nvoos-chat-spa-rest.php`](includes/rest/class-nvoos-chat-spa-rest.php).

## Credits

- **Vercel AI SDK** (`@ai-sdk/react`) — Apache-2.0 — <https://github.com/vercel/ai>
- **React** — MIT — <https://react.dev>

When adding upstream packages, update:

- [`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md)
- The root [`CREDITS.md`](../../CREDITS.md)
- This Credits section
