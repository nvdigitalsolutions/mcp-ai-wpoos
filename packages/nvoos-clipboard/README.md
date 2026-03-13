# @nvdigitalsolutions/nvoos-clipboard

Clipboard copy utilities with **Clipboard API** and **legacy `execCommand` fallback** — extracted from the [NV Open Operator System](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

**Zero external dependencies.** Uses only standard browser APIs.

## Why this package?

`navigator.clipboard.writeText()` is the modern way to copy text, but it's not universally available (older browsers, insecure contexts). This package handles both modern and legacy paths, attaches copy buttons to DOM elements, and manages button visual state — all in ~180 lines.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-clipboard
```

## Quick Start

```javascript
import { copyTextToClipboard, attachCopyButton } from '@nvdigitalsolutions/nvoos-clipboard';

// Simple programmatic copy
const success = await copyTextToClipboard('Hello, world!');
console.log(success ? 'Copied!' : 'Copy failed');

// Attach a copy button to any element
const bubble = document.querySelector('.message-bubble');
attachCopyButton(bubble, 'Text to copy');
```

## API

### `configure(options)`

Override default CSS class names or the DOM scheduler. Call once before using the service.

```javascript
import { configure } from '@nvdigitalsolutions/nvoos-clipboard';

configure({
  copyButtonClass:  'my-copy-btn',       // default: 'nvoos-copy-button'
  copyEnabledClass: 'my-copyable',       // default: 'nvoos-copy-enabled'
  copyErrorClass:   'my-copy-btn--err',  // default: 'nvoos-copy-button--error'
  // Optional custom scheduler (e.g., for React batching)
  domBatcher: { schedule: (fn) => setTimeout(fn, 0) }
});
```

### `copyTextToClipboard(text)`

Copies text using the Clipboard API, falling back to `execCommand('copy')` for older browsers.

```javascript
const success = await copyTextToClipboard(text);
```

### `fallbackCopyText(text)`

Uses only `execCommand('copy')`. Useful when the Clipboard API is intentionally unavailable.

### `attachCopyButton(bubble, text?)`

Appends a copy button to `bubble`. Manages visual state (`idle → copied → idle` or `idle → error → idle`) with a 2-second reset.

```javascript
const el = document.querySelector('.ai-response');
attachCopyButton(el);                    // copies el's textContent
attachCopyButton(el, 'explicit text');   // copies the given string
```

### `updateCopyButtonState(button, state)`

Programmatically change the button's visual state: `'idle'`, `'copied'`, or `'error'`.

### `resolveCopyText(bubble, text?)`

Resolves what text would be copied from an element — checks `data-copy-text` attribute before falling back to `textContent`.

## Styling

The copy button is a plain `<button>` element with configurable class names. Style it however you like:

```css
.nvoos-copy-button {
  background: none;
  border: 1px solid #ccc;
  border-radius: 4px;
  cursor: pointer;
  padding: 4px;
}
.nvoos-copy-button[data-state="copied"] { color: green; }
.nvoos-copy-button--error              { color: red; }
```

## TypeScript

Full TypeScript definitions included:

```typescript
import type { ClipboardConfig } from '@nvdigitalsolutions/nvoos-clipboard';
```

## License

MIT — [NV Digital Solutions](https://nvdigitalsolutions.com)
