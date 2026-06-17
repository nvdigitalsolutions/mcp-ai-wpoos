# @nvdigitalsolutions/nvoos-cron-status

SSE-first **cron / job status monitor** with REST polling fallback and exponential back-off — extracted from the [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

Pairs naturally with [`@nvdigitalsolutions/nvoos-events`](../nvoos-events) (its `SSEService` and `JobEventBus` plug straight in) but is **dependency-free** — bring your own SSE adapter and event bus, or just use the REST polling path.

## What it does

- **SSE-first.** When an SSE adapter is provided and supported, opens a stream against your endpoint and forwards `cron_status` and `cron_job_status` events through your callback + an optional job event bus.
- **REST polling fallback.** When SSE is unavailable, unauthenticated, or stalls for 30 s without data, switches to REST polling with exponential back-off (30 s → 5 min cap, capped at 60 attempts).
- **Auth-flexible.** Accepts either a WordPress-style nonce or a guest token; sends both as a header *and* a querystring parameter so it survives header-stripping CDNs and EventSource (which can't send custom headers).
- **Safe fall-through.** Errors silently degrade to polling — no console noise on expected auth failures.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-cron-status
# Optional — pairs perfectly with the SSE service & job bus from nvoos-events
npm install @nvdigitalsolutions/nvoos-events
```

## Quick Start

```javascript
import CronStatus, { configure } from '@nvdigitalsolutions/nvoos-cron-status';
import { SSEService, JobEventBus } from '@nvdigitalsolutions/nvoos-events';

// Wire up adapters once at boot.
configure({
  sseAdapter: SSEService,    // any object with isSupported() + connect()
  jobBus:     JobEventBus,   // any object with handleJobUpdate(jobId, payload)
});

// Begin monitoring a chat container.
CronStatus.startMonitoring(
  'chat-container-1',
  '/wp-json/mcp-ai/v1/cron-status',
  myNonce,                    // or null
  (data) => renderJobs(data.jobs),
  myAssistantId,              // optional
  myGuestToken                // optional, takes priority over nonce
);

// Tear down on unmount.
CronStatus.stopMonitoring('chat-container-1');
```

## API

### `configure(options)`

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `sseAdapter` | `{ isSupported(), connect(url, opts) }` | `null` | When omitted, `startMonitoring` skips SSE and uses REST polling immediately. |
| `jobBus` | `{ handleJobUpdate(id, payload) }` | `null` | Optional. When provided, individual job events are forwarded for downstream subscribers. |
| `jobClickableClass` | `string` | `'nvoos-job-clickable'` | CSS class applied by `attachClickHandlers()` to make job mentions clickable. |

### `CronStatusService.startMonitoring(containerId, endpoint, nonce, callback, assistantId?, guestToken?)`

Opens the best-available stream and invokes `callback(data)` whenever a status payload arrives. Idempotent — safe to call multiple times for the same `containerId`.

### `CronStatusService.stopMonitoring(containerId)`

Closes both the SSE connection and the polling timer for `containerId`.

### `CronStatusService.fetchStatusREST(endpoint, nonce, limit?, assistantId?, guestToken?)`

One-shot REST fetch. Returns a Promise resolving to the parsed JSON, or `null` on error.

### `CronStatusService.emitJobUpdates(data)`

Iterates `data.jobs` and dispatches each through the configured `jobBus`. Useful when you receive a payload through some other channel and want to fan it out.

### Tunables

```js
import CronStatus from '@nvdigitalsolutions/nvoos-cron-status';

CronStatus.fallbackPollingInterval = 15000;  // default 30 000
CronStatus.maxPollingInterval      = 60000;  // default 300 000
CronStatus.backoffMultiplier       = 1.25;   // default 1.5
CronStatus.maxPollingAttempts      = 30;     // default 60
```

## SSE adapter contract

Any object satisfying this contract works as the `sseAdapter`:

```ts
interface SSEAdapter {
  isSupported(): boolean;
  connect(url: string, options: {
    eventHandlers?: Record<string, (data: any) => void>;
    onError?: (err?: any) => void;
    onOpen?: () => void;
  }): { close: () => void } | null;
}
```

The `SSEService` exported from `@nvdigitalsolutions/nvoos-events` already implements this — pass it in directly.

## License

MIT — see `LICENSE`.
