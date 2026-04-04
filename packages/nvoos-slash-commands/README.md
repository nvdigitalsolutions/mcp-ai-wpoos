# @nvdigitalsolutions/nvoos-slash-commands

Slash command system with **fuzzy-search autocomplete** and a **resilient execution engine** — extracted from the [NV Open Operator System](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

**Zero external dependencies.** Uses only standard browser APIs (Fetch, DOM, localStorage).

## Why this package?

Slash commands are the fastest way for users to trigger AI actions inside a chat UI. This package gives you:

1. **`CommandAutocomplete`** — a fuzzy-search dropdown that appears when the user types `/`, supports keyboard navigation (↑ ↓ Enter Tab Escape), and calls your API to retrieve the command list.
2. **`SlashCommandsHandler`** — intercepts form submission, executes commands via REST API with a 30-second timeout, correlation ID tracking for debugging, screen-reader announcements, and a `CustomEvent` bridge so your chat layer can react.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-slash-commands
```

## Quick Start

### Framework-agnostic (zero config in WordPress)

```javascript
import { createSlashCommands } from '@nvdigitalsolutions/nvoos-slash-commands';

// Auto-initialises when the DOM is ready. Reads window.mcpAiData for endpoints.
const handler = createSlashCommands();
```

### Explicit config (any environment)

```javascript
import { SlashCommandsHandler, CommandAutocomplete } from '@nvdigitalsolutions/nvoos-slash-commands';

// 1. Configure module-level defaults (shared by all instances)
SlashCommandsHandler.configure({
  nonce: 'your-auth-nonce',
  slashCommandEndpoint: 'https://your-api.com/slash-commands/execute',
  slashCommandListEndpoint: 'https://your-api.com/slash-commands/list',
});

// 2. Create and initialise the handler (attaches to the first matching form input)
const handler = new SlashCommandsHandler();
handler.init();

// 3. Optionally attach autocomplete to a specific input element
const input = document.querySelector('#chat-input');
const autocomplete = new CommandAutocomplete(input);
autocomplete.init();
```

## API

### `SlashCommandsHandler`

#### `static configure(config)`

Set module-level defaults before constructing any instance.

```javascript
SlashCommandsHandler.configure({
  nonce: 'wp-nonce-value',
  slashCommandEndpoint: 'https://example.com/wp-json/mcp-ai/v1/slash-commands',
  slashCommandListEndpoint: 'https://example.com/wp-json/mcp-ai/v1/slash-commands/list',
});
```

#### `new SlashCommandsHandler(config?)`

Construct an instance, optionally overriding the module config for this instance only.

#### `handler.init()`

Attach to the chat input and form found in the DOM. Safe to call multiple times (no-op after first successful init). Retries up to 50 times at 100 ms intervals if `mcpAiData` is not yet available.

#### `handler.executeCommand(command)`

Execute a slash command string programmatically (e.g. `'/summarize last 5 messages'`). Returns a Promise.

#### `handler.fetchCommands()`

Returns a Promise that resolves to the list of available commands from the API. Results are cached for 5 minutes.

#### `handler.announceToScreenReader(message)`

Inject an accessible `aria-live` announcement into the page.

---

### `CommandAutocomplete`

#### `new CommandAutocomplete(inputElement)`

Attach a fuzzy-search dropdown to a text input or textarea.

#### `autocomplete.init()`

Create the dropdown element, load the command list, and attach event listeners.

#### `autocomplete.show(inputValue)`

Filter commands matching `inputValue` and display the dropdown.

#### `autocomplete.hide()`

Hide the dropdown and clean up listeners.

#### `autocomplete.isVisible()`

Returns `true` if the dropdown is currently visible.

#### `autocomplete.handleKeyDown(e)`

Call this from your own `keydown` listener to delegate keyboard navigation. Returns `true` if the event was consumed.

#### `autocomplete.destroy()`

Remove all event listeners and detach the dropdown element from the DOM.

---

### `createSlashCommands(config?)`

Convenience factory that constructs a `SlashCommandsHandler`, wires it up to the DOM, and returns it. Handles `DOMContentLoaded` timing automatically.

```javascript
const handler = createSlashCommands({ nonce: 'abc', slashCommandEndpoint: '/api/execute' });
```

## Listening for command events

The handler dispatches a `slash-command-event` CustomEvent on `window` after each execution:

```javascript
window.addEventListener('slash-command-event', (e) => {
  const { type, data } = e.detail;
  if (type === 'command-executed') {
    console.log('Command result:', data.result);
  }
});
```

## Command list API contract

`slashCommandListEndpoint` must return:

```json
{
  "commands": [
    { "name": "summarize", "description": "Summarise the conversation", "aliases": ["sum"] },
    { "name": "translate", "description": "Translate to another language" }
  ]
}
```

## TypeScript

Full TypeScript definitions included:

```typescript
import type { SlashCommand, SlashCommandsConfig } from '@nvdigitalsolutions/nvoos-slash-commands';
```

## License

MIT — [NV Digital Solutions](https://nvdigitalsolutions.com)
