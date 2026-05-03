# @nvdigitalsolutions/nvoos-chat-memory

Promise-based REST client for an **AI chat memory bridge** — extracted from the [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

Wraps eight memory verbs in a tiny, dependency-free module:
`wakeUp`, `recall`, `store`, `storeBeacon` (page-unload safe), `update`, `remove`, `audit`, plus per-user `getPreferences` / `setPreferences`.

**Zero runtime dependencies.** Uses only `fetch`. Bring your own endpoints, headers, and (optionally) `fetch` implementation — no WordPress globals.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-chat-memory
```

## Quick Start

```javascript
import {
  configure, wakeUp, recall, store, audit,
} from '@nvdigitalsolutions/nvoos-chat-memory';

configure({
  endpoints: {
    wakeUp:      '/api/memory/wake-up',
    recall:      '/api/memory/recall',
    store:       '/api/memory/store',
    itemBase:    '/api/memory/items/',   // MUST end with a slash
    preferences: '/api/memory/preferences',
    audit:       '/api/memory/audit',    // optional
  },
  headers: { Authorization: `Bearer ${token}` }, // or 'X-WP-Nonce': nonce
});

// Pull a wake-up system block for the active agent.
const wakeBlock = await wakeUp({ agentId: 'a1', wing: 'support' });

// Search the agent's memory.
const hits = await recall('quarterly numbers', { agentId: 'a1', limit: 5 });

// Store a verbatim memory.
await store({
  agentId: 'a1',
  title: 'Customer prefers email replies',
  content: 'Always email — they ignore phone calls.',
  importance: 'high',
});
```

## API

### `configure(options)`

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `endpoints` | `ChatMemoryEndpoints` | — | **Required.** Map of endpoint URLs (see below). |
| `headers` | `Record<string,string>` | `{}` | Extra request headers, merged into every call. Use this to inject `Authorization`, `X-WP-Nonce`, CSRF tokens, etc. |
| `fetch` | `typeof fetch` | `globalThis.fetch` | Custom fetch (e.g. `node-fetch`, `cross-fetch`, mocked in tests). |
| `credentials` | `RequestCredentials` | `'same-origin'` | Forwarded to `fetch`. |

### Endpoints shape

```ts
interface ChatMemoryEndpoints {
  wakeUp: string;
  recall: string;
  store: string;
  itemBase: string;       // MUST end with a slash. {contextId} appended URL-encoded.
  preferences: string;
  audit?: string;         // optional — audit() rejects with `chat_memory_disabled` if missing.
}
```

### Memory verbs

```ts
isAvailable(): boolean;

wakeUp(params?: { agentId, wing, room }): Promise<any>;
recall(query: string, filters?: { agentId, wing, room, limit }): Promise<any>;

store(payload: StorePayload): Promise<any>;
storeBeacon(payload: StorePayload): Promise<any|null>;   // uses keepalive:true
update(contextId: string, patch: UpdatePatch): Promise<any>;
remove(contextId: string, options?: { agentId }): Promise<any>;
// also exported as `delete_` (since `delete` is a JS reserved word).

audit(options?: { agentId, limit, actionType }): Promise<any>;
getPreferences(): Promise<{ enabled?, autosummarize? }>;
setPreferences(prefs: { enabled?, autosummarize? }): Promise<any>;
```

### Helpers

```ts
isMemoryRetrievalResult(result: unknown): boolean;
```

Detects whether an arbitrary tool-call result describes memory retrieval — `true` when `result.contexts`, `result.results`, or `result.memories` is an array. Useful for surfacing a "🧠 Memory" badge in chat UIs.

### Error shape

When `isAvailable()` returns false (no `endpoints` configured, or required keys missing), every verb rejects with `Error('Chat memory surface is not enabled.')` carrying `error.code === 'chat_memory_disabled'`.

For non-2xx HTTP responses, the rejected error carries:

- `error.status` — HTTP status code
- `error.data` — parsed JSON body (when present)
- `error.message` — `data.message ?? data.code ?? 'HTTP <status>'`

## License

MIT — see `LICENSE`.
