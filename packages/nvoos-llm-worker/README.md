# @nvdigitalsolutions/nvoos-llm-worker

Web Worker manager for non-blocking LLM operations in the browser. Wraps a Web Worker that hosts a WebLLM (or compatible) engine and exposes a clean Promise-based API for model loading, streaming generation, stats, and lifecycle.

**Extracted from:** [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress Plugin

## Why This Package?

Running an in-browser LLM (WebLLM, MLC, etc.) on the main thread freezes the UI during model load and inference. This package provides a battle-tested manager that:

- Spins up the worker, awaits a `worker_ready` handshake with timeout
- Streams generation chunks back via callbacks
- Cleans up listeners on completion / error
- Surfaces errors in a single, normalised shape
- Lets you point at any compatible worker script via `configure({ workerUrl })`

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-llm-worker
```

## Quick Start

```javascript
import { LLMWorkerManager } from '@nvdigitalsolutions/nvoos-llm-worker';

const manager = new LLMWorkerManager({
  workerUrl: '/workers/llm-worker.js'
});

await manager.createWorker();

await manager.loadModel('Llama-3-8B-Instruct-q4f32_1-MLC', (progress) => {
  console.log('Loading:', progress);
});

const reply = await manager.generate(
  [{ role: 'user', content: 'Hello!' }],
  { temperature: 0.7 },
  (chunk) => process.stdout.write(chunk.content || '')
);

await manager.unloadModel();
manager.terminate();
```

## API

### `new LLMWorkerManager(options?)`

- `options.workerUrl` (string): URL to the worker script.
- `options.workerOptions` (object): forwarded to the `Worker` constructor (default `{ type: 'module' }`).

### `.configure(options)`

Update `workerUrl` / `workerOptions` after construction.

### `.isSupported()` → `boolean`

True if `Worker` is available in the current environment.

### `.createWorker()` → `Promise<void>`

Creates the worker and resolves once it posts `{ type: 'worker_ready' }` (10s timeout).

### `.loadModel(modelId, onProgress?)` → `Promise<void>`

Sends `{ type: 'init', data: { modelId } }` to the worker and resolves on `{ type: 'ready' }` (5 min timeout). Calls `onProgress` for each `{ type: 'progress' }` message.

### `.generate(messages, options, onChunk?)` → `Promise<string>`

Sends `{ type: 'generate', data: { messages, options } }` and resolves with concatenated `chunk.content` once `{ type: 'done' }` arrives.

### `.unloadModel()` → `Promise<void>`

Sends `{ type: 'unload' }`. Resolves on `{ type: 'unloaded' }` or after 5s.

### `.getStats()` → `Promise<string>`

Sends `{ type: 'stats' }`. Resolves with the stats string.

### `.terminate()`

Calls `worker.terminate()` and clears all listeners.

### `.isReady()` → `boolean`

True when the worker is created and ready.

## Worker Protocol

This package is transport-only — you supply your own worker script. The expected message protocol is:

| Direction | `type` | `data` |
|-----------|--------|--------|
| → worker | `init` | `{ modelId }` |
| → worker | `generate` | `{ messages, options }` |
| → worker | `unload` | `{}` |
| → worker | `stats` | `{}` |
| ← worker | `worker_ready` | — |
| ← worker | `progress` | `{ progress, text, ... }` |
| ← worker | `ready` | `{ modelId }` |
| ← worker | `chunk` | `{ content }` |
| ← worker | `done` | `{}` |
| ← worker | `unloaded` | `{}` |
| ← worker | `stats` | `{ stats }` |
| ← worker | `error` | `{ message, ... }` |

A reference worker for WebLLM is shipped with NV oOS at [`assets/js/workers/llm-worker.js`](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/assets/js/workers/llm-worker.js).

## Browser Support

- Chrome/Edge 113+
- Firefox 115+
- Safari 16.4+
- Any browser with `Worker` and `type: 'module'` workers.

## From WordPress to Universal

- ❌ Removed: `window.wpMcpAiChat.pluginUrl` global lookup
- ✅ Added: `configure({ workerUrl })` and constructor option
- ✅ Added: ES module exports
- ✅ Added: TypeScript definitions

## License

MIT © NV Digital Solutions

## Links

- [GitHub Repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
- [NV Digital Solutions](https://nvdigitalsolutions.com)
- [Report Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
