# @nvdigitalsolutions/nvoos-events

Real-time event coordination with enhanced SSE client and job event bus for tracking async operations.

**Extracted from:** [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress Plugin

## Why This Package?

This package powers real-time AI assistant responses and background job tracking in NV oOS. It combines:

1. **Enhanced SSE Client**: Built on @microsoft/fetch-event-source with automatic retry logic
2. **Job Event Bus**: Coordinate async operations across components with promise-based watching

### Real-World Use Case

In NV oOS, AI assistants:
- Stream responses via Server-Sent Events
- Trigger background jobs (image generation, web scraping, etc.)
- Need to coordinate between UI and background workers

This package handles all that communication with automatic reconnection, event caching, and promise-based job completion.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-events @microsoft/fetch-event-source
```

## Quick Start

### SSE Streaming

```javascript
import { SSEService } from '@nvdigitalsolutions/nvoos-events';

const connection = SSEService.connect('https://api.example.com/stream', {
  method: 'POST',
  headers: { 'Authorization': 'Bearer token' },
  body: { prompt: 'Hello AI' },
  
  onMessage: (data) => {
    console.log('Received:', data);
  },
  
  onError: (error) => {
    console.error('Stream error:', error);
  }
});

// Close when done
connection.close();
```

### Job Event Bus

```javascript
import { JobEventBus } from '@nvdigitalsolutions/nvoos-events';

// Listen for job completion
JobEventBus.on('job:completed', (event) => {
  console.log(`Job ${event.jobId} completed:`, event.data);
});

// Update job status (from backend webhook/polling)
JobEventBus.handleJobUpdate('job-123', {
  status: 'completed',
  result: { imageUrl: 'https://...' }
});

// Watch a specific job until completion
try {
  const result = await JobEventBus.watchJob('job-456', {
    onProgress: (data) => console.log('Progress:', data),
    timeout: 60000 // 1 minute
  });
  console.log('Job completed:', result);
} catch (error) {
  console.error('Job failed:', error);
}
```

## API Reference

### SSEService

#### `SSEService.connect(url, options)`

Create an SSE connection with automatic reconnection.

**Options:**
- `method` ('GET' | 'POST'): HTTP method
- `headers` (object): Request headers
- `body` (string | object): Request body (for POST)
- `onMessage` (function): Called on each message
- `onError` (function): Called on errors
- `onOpen` (function): Called when connection opens
- `eventHandlers` (object): Map of event types to handlers
- `openWhenHidden` (boolean): Keep connection open when page hidden

**Returns:** `{ ctrl, close, abort }` - Connection controller

#### `SSEService.closeAll()`

Close all active SSE connections.

#### `SSEService.enableDebug()`

Enable debug logging for troubleshooting.

### JobEventBus

#### `JobEventBus.on(type, handler)`

Register an event handler.

**Event Types:**
- `'job:started'` - Job started
- `'job:progress'` - Job progress update
- `'job:completed'` - Job completed successfully
- `'job:failed'` - Job failed with error
- `'*'` - Wildcard (receives all events)

#### `JobEventBus.emit(type, event)`

Emit an event to all registered handlers.

#### `JobEventBus.handleJobUpdate(jobId, data)`

Process a job status update. Normalizes status and emits typed events.

#### `JobEventBus.watchJob(jobId, options)`

Watch a job until completion or failure.

**Returns:** `Promise` - Resolves with result or rejects with error

### createEventBus()

Create an isolated event bus instance (for advanced use cases).

**Returns:** `EventBus` - New event bus with on/off/emit methods

## Features

### SSE Client

✅ Automatic reconnection with exponential backoff  
✅ POST request support (unlike native EventSource)  
✅ Custom headers and authentication  
✅ Error recovery and retry logic  
✅ Connection state management

### Event Bus

✅ Lightweight (~290 lines)  
✅ mitt-compatible API  
✅ Wildcard event handling  
✅ Job-specific event normalization  
✅ Promise-based job watching  
✅ Event caching and replay

## Examples

### Streaming AI Responses

```javascript
const stream = SSEService.connect('/api/chat', {
  method: 'POST',
  body: { message: 'Tell me a story' },
  
  eventHandlers: {
    'token': (token) => {
      document.getElementById('output').textContent += token;
    },
    'done': () => {
      console.log('Stream complete');
    }
  }
});
```

### Background Job Coordination

```javascript
// Start a job
const jobId = await fetch('/api/jobs', {
  method: 'POST',
  body: JSON.stringify({ task: 'generate-image' })
}).then(r => r.json()).then(d => d.jobId);

// Watch for completion
const result = await JobEventBus.watchJob(jobId, {
  onProgress: (data) => {
    updateProgressBar(data.progress);
  },
  timeout: 120000 // 2 minutes
});

displayImage(result.imageUrl);
```

## From WordPress to Universal

Extracted from production WordPress code:

- ❌ Removed: `window.wpMcpAiSSE` and `window.wpMcpAiJobBus` globals
- ❌ Removed: `window.wpMcpAiDebug` configuration
- ✅ Added: ES module exports
- ✅ Added: `enableDebug()` method for logging
- ✅ Added: TypeScript definitions
- ✅ Added: Combined SSE + Event Bus in one package

## Performance

- **SSE Connection**: ~5-10ms overhead per message
- **Event Bus**: <1ms per emit/on/off operation
- **Memory**: Minimal (only stores active jobs and handlers)

## Browser Support

- Chrome/Edge 113+
- Firefox 115+
- Safari 16.4+
- Any browser with fetch and AbortController

## License

MIT © NV Digital Solutions

## Links

- [GitHub Repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
- [NV Digital Solutions](https://nvdigitalsolutions.com)
- [Report Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
