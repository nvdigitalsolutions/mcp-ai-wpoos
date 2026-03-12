# @nvdigitalsolutions/nvoos-http-client

Resilient HTTP client with **automatic retry**, **exponential backoff**, and **request/response hooks** — extracted from the [NV Open Operator System](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

Built on [`ky`](https://github.com/sindresorhus/ky) — a lightweight, modern fetch wrapper.

## Why this package?

Raw `fetch` has no retry logic. Network hiccups, temporary server errors (502, 503), and rate limits (429) all silently fail. This package wraps `ky` with production-proven defaults so you get resilience out of the box.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-http-client ky
```

`ky` is a peer dependency — install it alongside this package.

## Quick Start

```javascript
import { postJson, get } from '@nvdigitalsolutions/nvoos-http-client';

// POST with automatic retry (3 attempts, exponential backoff)
const response = await postJson(
  'https://api.example.com/chat',
  { message: 'Hello AI' },
  { 'Authorization': 'Bearer token' }
);
const data = await response.json();

// GET with abort signal for cancellation
const controller = new AbortController();
const response = await get(
  'https://api.example.com/status',
  {},
  { signal: controller.signal, timeout: 5000 }
);
```

## API

### `createHttpClient(options?)`

Creates a configured `ky` instance with retry and hooks.

```javascript
import { createHttpClient } from '@nvdigitalsolutions/nvoos-http-client';

const client = createHttpClient({
  timeout: 15000,         // 15 second timeout
  retryLimit: 5,          // retry up to 5 times
  onRetry: ({ url, retryCount }) => {
    console.log(`Retrying ${url} (attempt ${retryCount})`);
  },
  onAuthFailure: ({ url, status }) => {
    // Called on 401 — refresh token and retry
    refreshAuthToken();
  }
});

const response = await client.post('https://api.example.com/data', { json: payload });
```

### `postJson(url, data, headers?, options?)`

POST JSON with retry. Returns a `Response` promise.

```javascript
const response = await postJson(url, { key: 'value' }, headers, {
  timeout: 30000,
  retryLimit: 3,
  credentials: 'include',  // for cross-origin requests with cookies
  signal: abortController.signal
});
```

### `uploadFile(url, file, headers?, options?)`

Upload a `File` or `Blob` with retry.

```javascript
const response = await uploadFile(url, fileBlob, headers, { timeout: 60000 });
```

### `get(url, headers?, options?)`

GET request with retry.

### `delete(url, headers?, options?)`

DELETE request with retry.

### `parseError(error)`

Parse a `ky` error into a structured object with `status`, `statusText`, and `data`.

```javascript
import { postJson, parseError } from '@nvdigitalsolutions/nvoos-http-client';

try {
  const response = await postJson(url, data, headers);
} catch (error) {
  const details = await parseError(error);
  console.error(`HTTP ${details.status}: ${details.message}`, details.data);
}
```

## Defaults

| Setting | Default |
|---------|---------|
| Timeout | 30 seconds |
| Retry limit | 3 attempts |
| Retry status codes | 408, 413, 429, 500, 502, 503, 504 |
| Retry methods | GET, POST, PUT, PATCH, DELETE |
| Max backoff | 10 seconds |

## TypeScript

Full TypeScript definitions are included:

```typescript
import type { HttpClientOptions, RequestOptions, ParsedError } from '@nvdigitalsolutions/nvoos-http-client';
```

## License

MIT — [NV Digital Solutions](https://nvdigitalsolutions.com)
