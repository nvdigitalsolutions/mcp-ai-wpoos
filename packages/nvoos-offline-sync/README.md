# @nvdigitalsolutions/nvoos-offline-sync

IndexedDB-backed **offline-first sync manager** with automatic server sync on reconnect — extracted from the [NV Open Operator System](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

**Zero external dependencies.** Uses only IndexedDB, `fetch`, and standard browser events.

## Why this package?

Building offline-capable web apps requires more than just checking `navigator.onLine`. This package manages a local IndexedDB store for immediate persistence, queues failed sync requests, and automatically drains the queue when connectivity is restored — so your users never lose data.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-offline-sync
```

## Quick Start

```javascript
import OfflineChatManager from '@nvdigitalsolutions/nvoos-offline-sync';

const manager = new OfflineChatManager({
  syncUrl: 'https://api.example.com/messages',
  syncHeaders: { 'Authorization': 'Bearer token' }
});

await manager.initialize();  // opens IndexedDB

// Save a message — persists locally immediately, syncs when online
await manager.saveMessage({ text: 'Hello', role: 'user' });
```

## How it works

```
saveMessage()
    ↓ always
saveToLocal()          ← IndexedDB store (instant, offline-safe)
    ↓ if online
syncToServer()         ← POST to your API
    ↓ if offline
syncQueue.push()       ← queued for later

navigator 'online' event
    ↓
handleOnline()         ← drains sync queue automatically
```

## API

### `new OfflineChatManager(options?)`

```javascript
const manager = new OfflineChatManager({
  // Server endpoint that receives POST with message JSON
  syncUrl: 'https://api.example.com/chat/save',

  // Extra headers sent on every sync request
  syncHeaders: {
    'Authorization': 'Bearer my-token',
    'X-CSRF-Token':  'abc123'
  },

  // IndexedDB settings (change dbVersion when schema changes)
  dbName:    'my-app-offline',   // default: 'nvoos-offline'
  dbVersion: 1,                  // default: 1

  // Set false to suppress the built-in offline banner
  showOfflineUI: true            // default: true
});
```

### `initialize()`

Opens (or upgrades) the IndexedDB database. Call this once at app startup.

```javascript
await manager.initialize();
```

### `saveMessage(message)`

Saves the message to IndexedDB immediately. If the device is online, also posts to `syncUrl`. If offline, queues for automatic sync on reconnect.

```javascript
await manager.saveMessage({
  text: 'Hello AI',
  role: 'user',
  timestamp: Date.now()
});
```

### `getAllMessages()`

Returns all locally stored messages.

```javascript
const messages = await manager.getAllMessages();
```

### `clearAllData()`

Clears all local messages and conversations from IndexedDB.

### `handleOnline()` / `handleOffline()`

Called automatically by the built-in `online` / `offline` event listeners. You can also call them manually (e.g., in a PWA with a service worker).

## Offline UI

When `showOfflineUI: true` (default), a `.nvoos-offline-notice` banner is injected into the DOM when the device goes offline and removed when it comes back online. Style it with CSS:

```css
.nvoos-offline-notice {
  position: fixed;
  bottom: 16px;
  left: 50%;
  transform: translateX(-50%);
  background: #333;
  color: #fff;
  padding: 8px 16px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 8px;
}
```

## TypeScript

Full TypeScript definitions included:

```typescript
import type { OfflineSyncOptions, OfflineMessage } from '@nvdigitalsolutions/nvoos-offline-sync';
```

## License

MIT — [NV Digital Solutions](https://nvdigitalsolutions.com)
