# @nvdigitalsolutions/nvoos-api

Typed REST API client for the [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos).

Provides endpoint URL builders, typed payload constructors, auth header helpers, and fetch wrappers for WordPress REST APIs. **Zero external dependencies** — pure `fetch` with typed responses.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-api
```

## Quick Start

```javascript
import {
  chatEndpoint,
  toolExecuteEndpoint,
  buildAuthHeaders,
  wpPost,
} from '@nvdigitalsolutions/nvoos-api';

const config = {
  restUrl: 'https://mysite.com/wp-json/mcp-ai/v1',
  nonce: 'abc123',
};

// Post a chat message
const response = await wpPost(
  chatEndpoint(config),
  { assistant_id: 42, messages: [{ role: 'user', content: 'Hello' }] },
  buildAuthHeaders(config)
);

// Execute a tool
const result = await wpPost(
  toolExecuteEndpoint(config),
  {
    tool: 'get_post',
    arguments: { post_id: 1 },
    assistant_id: 42,
  },
  buildAuthHeaders(config)
);
```

## API

### Endpoint Builders

| Function | Returns | Description |
|----------|---------|-------------|
| `chatEndpoint(config)` | `string` | Chat POST URL |
| `chatClientEndpoint(config)` | `string` | SPA/SSE chat URL |
| `toolsListEndpoint(config)` | `string` | Tools GET URL |
| `toolExecuteEndpoint(config)` | `string` | Tool execution POST URL |
| `uploadEndpoint(config)` | `string` | File upload POST URL |
| `transcriptsEndpoint(config, sessionKey?)` | `string` | Transcript CRUD URL |
| `historyEndpoint(config, params?)` | `string` | History GET URL |
| `sseEndpoint(config, params?)` | `string` | SSE EventSource URL |

### Payload Builders

| Function | Returns | Description |
|----------|---------|-------------|
| `buildChatPayload(assistantId, messages)` | `object` | Typed chat request body |
| `buildToolExecutionPayload(payload)` | `object` | Typed tool execution body |

### Auth Helpers

| Function | Returns | Description |
|----------|---------|-------------|
| `buildAuthHeaders(config)` | `object` | WP nonce + content-type |
| `buildGuestHeaders()` | `object` | Guest token headers |

### Fetch Helpers

| Function | Description |
|----------|-------------|
| `wpGet<T>(url, headers, signal?)` | Typed GET with error handling |
| `wpPost<T>(url, body, headers, signal?)` | Typed POST with error handling |
| `wpUpload<T>(url, file, headers, signal?)` | Typed multipart upload |

### Utilities

| Function | Description |
|----------|-------------|
| `sanitizeSessionKey(raw)` | Strip unsafe chars for storage keys |
| `formatBytes(bytes, decimals?)` | Human-readable byte formatting |

## TypeScript

Full type definitions included:

```typescript
import type { ApiConfig, ToolExecutionPayload } from '@nvdigitalsolutions/nvoos-api';

const config: ApiConfig = {
  restUrl: 'https://mysite.com/wp-json/mcp-ai/v1',
  nonce: 'abc123',
};
```

## License

MIT — [NV Digital Solutions](https://nvdigitalsolutions.com)
