# @nvdigitalsolutions/nvoos-sse-client

TypeScript-native SSE connection manager with lifecycle tracking, per-connection status, and automatic cleanup. **Companion to `@nvdigitalsolutions/nvoos-events`** — this package focuses on connection-level management while `nvoos-events` provides the higher-level event bus and job-watching patterns.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-sse-client @microsoft/fetch-event-source
```

## Quick Start

```javascript
import { connect, closeAll, getConnectionStatus } from '@nvdigitalsolutions/nvoos-sse-client';

// Open an SSE connection with typed event handlers
const handle = connect('https://mysite.com/wp-json/mcp-ai/v1/sse', {
  method: 'POST',
  headers: { 'X-WP-Nonce': 'abc123' },
  body: { assistant_id: 42 },
  onOpen(response) {
    console.log('SSE connection established');
  },
  onMessage(data, event) {
    console.log('Received:', data);
  },
  onError(error) {
    console.error('SSE error:', error);
  },
  eventHandlers: {
    tool_call_started(data) {
      console.log('Tool call started:', data);
    },
    tool_call_completed(data) {
      console.log('Tool call completed:', data);
    },
  },
});

// Check status
const status = handle.getStatus(); // 'connecting' | 'open' | 'closed'

// Close cleanly
handle.close();

// Close all connections (e.g., on page unload)
closeAll();
```

## API

### `connect(url, options?) → SseHandle | null`

Open an SSE connection. Returns a handle object or `null` if SSE is unsupported.

**Options:**

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `method` | `string` | `'GET'` | HTTP method |
| `headers` | `Record<string, string>` | `{}` | Request headers |
| `body` | `string \| object` | — | Body for POST/PUT |
| `onMessage` | `(data, event) => void` | — | Generic message handler |
| `onError` | `(error) => void` | — | Error handler |
| `onOpen` | `(response) => void` | — | Connection-open handler |
| `eventHandlers` | `Record<string, fn>` | — | Named event handlers |
| `openWhenHidden` | `boolean` | `false` | Stay open when tab hidden |

**Handle methods:**

| Method | Description |
|--------|-------------|
| `close()` | Gracefully close (removes from registry) |
| `abort()` | Force abort immediately |
| `getStatus()` | Returns `'connecting'`, `'open'`, or `'closed'` |

### Connection Registry

| Function | Description |
|----------|-------------|
| `closeConnection(key)` | Close by registry key |
| `closeAll()` | Close all tracked connections |
| `getConnectionCount()` | Number of active connections |
| `getConnectionStatus(url)` | Status of connection by URL |
| `isSseSupported()` | Check browser support |
| `getReadyStateName(readyState)` | Human-readable ready state |

### Constants

```javascript
import { READY_STATE } from '@nvdigitalsolutions/nvoos-sse-client';

READY_STATE.CONNECTING; // 0
READY_STATE.OPEN;       // 1
READY_STATE.CLOSED;     // 2
```

## Relationship to `nvoos-events`

| Package | Focus |
|---------|-------|
| `nvoos-sse-client` | Low-level connection lifecycle, per-connection status, typed handlers |
| `nvoos-events` | High-level event bus, job watcher, SSE + job coordination |

Use `nvoos-sse-client` when you need fine-grained control over individual SSE connections. Use `nvoos-events` when you need pub/sub event coordination across chat instances.

## TypeScript

Full type definitions included:

```typescript
import type { SseConnectionOptions, SseHandle, ConnectionStatus } from '@nvdigitalsolutions/nvoos-sse-client';
```

## License

MIT — [NV Digital Solutions](https://nvdigitalsolutions.com)
