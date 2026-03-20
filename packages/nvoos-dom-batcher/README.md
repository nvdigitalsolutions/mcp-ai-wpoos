# @nvdigitalsolutions/nvoos-dom-batcher

`requestAnimationFrame`-based **DOM update batcher**, **scroll batcher**, and a suite of UI utilities for high-frequency streaming UIs — extracted from the [NV Open Operator System](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

**Zero external dependencies.** Uses only standard browser APIs.

## Why this package?

When an AI assistant streams tokens over SSE or WebSocket, a naïve implementation calls `element.textContent += token` on every chunk — causing a forced reflow on every message and making the UI janky under load. This package solves that by coalescing DOM writes and scroll operations into a single `requestAnimationFrame` callback per frame.

It also ships formatting helpers, status management, and a lightweight attachment library that are commonly needed alongside a streaming chat UI.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-dom-batcher
```

## Quick Start

```javascript
import { domUpdateBatcher, scrollBatcher } from '@nvdigitalsolutions/nvoos-dom-batcher';

const output = document.getElementById('output');
const container = document.getElementById('scroll-container');

// Stream tokens from an SSE endpoint
eventSource.addEventListener('token', (e) => {
  // Batched: all token appends within one animation frame are coalesced
  domUpdateBatcher.schedule(() => {
    output.textContent += e.data;
  });

  // Batched: only the last scroll-to-bottom per frame is executed
  scrollBatcher.scrollToBottom(container);
});
```

## API

### `configure(options)`

Enable/disable batching globally. Call once at startup.

```javascript
import { configure } from '@nvdigitalsolutions/nvoos-dom-batcher';

configure({ debug: true });   // disables RAF — runs synchronously (useful for testing)
configure({ optimizations: false }); // same effect
configure({ optimizations: true });  // re-enable (default)
```

### `domUpdateBatcher`

```javascript
domUpdateBatcher.schedule(updateFn: () => void): void
```

Queues `updateFn` to run in the next animation frame. Multiple calls within one frame are merged. Falls back to immediate execution when `debug` mode is on or `requestAnimationFrame` is unavailable.

### `scrollBatcher`

```javascript
scrollBatcher.scrollToBottom(element: HTMLElement): void
```

Queues a scroll-to-bottom operation. Deduplicated per element per frame — so calling it 100 times in one tick is equivalent to calling it once.

### Formatting utilities

```javascript
import { escapeHtml, formatBytes, formatDuration, formatElapsedTime } from '@nvdigitalsolutions/nvoos-dom-batcher';

escapeHtml('<script>alert(1)</script>');  // '&lt;script&gt;alert(1)&lt;/script&gt;'
formatBytes(1536);                         // '1.5 KB'
formatDuration(95);                        // '1:35'
formatElapsedTime(75);                     // '1m 15s'
```

### Status management

```javascript
import { setStatus, clearStatus } from '@nvdigitalsolutions/nvoos-dom-batcher';

setStatus(containerEl, 'Thinking…', 'loading');
clearStatus(containerEl);
```

### Button utilities

```javascript
import { toggleButtonClass, setButtonState, setButtonIcon, updateButtonLabel } from '@nvdigitalsolutions/nvoos-dom-batcher';

setButtonState(btn, 'loading');           // sets data-state="loading"
toggleButtonClass(btn, 'active', true);   // adds class
setButtonIcon(btn, svgString, '.icon');   // XSS-safe SVG injection
updateButtonLabel(btn, 'Submit');         // sets aria-label + title
```

### Cross-instance communication

```javascript
import { broadcastMessage, listenToChatEvents } from '@nvdigitalsolutions/nvoos-dom-batcher';

// Send an event to all other chat instances on the same page
broadcastMessage('chat:scroll', { target: 'bottom' });

// Listen for events from other instances
const unsubscribe = listenToChatEvents('chat:scroll', (data) => {
  console.log(data);
});
unsubscribe(); // cleanup
```

### Attachment library

```javascript
import { validateAttachment, addToAttachmentLibrary, getFromAttachmentLibrary, removeFromAttachmentLibrary } from '@nvdigitalsolutions/nvoos-dom-batcher';

const result = validateAttachment(file, { maxBytes: 10 * 1024 * 1024, allowedTypes: ['image/png'] });
if (result.valid) {
  addToAttachmentLibrary('att-1', file);
}

const stored = getFromAttachmentLibrary('att-1');
removeFromAttachmentLibrary('att-1');
```

### Recording timer

```javascript
import { displayRecordingTimer } from '@nvdigitalsolutions/nvoos-dom-batcher';

const stopTimer = displayRecordingTimer(container, Date.now());
// Later:
stopTimer();
```

## TypeScript

Full TypeScript definitions included:

```typescript
import type { DomBatcherConfig } from '@nvdigitalsolutions/nvoos-dom-batcher';
```

## License

MIT — [NV Digital Solutions](https://nvdigitalsolutions.com)
