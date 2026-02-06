# @nvdigital/nvoos-storage

Async storage utilities with Web Worker optimization for handling large JSON operations without blocking the main thread.

**Extracted from:** [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress Plugin

## Why This Package?

This utility was battle-tested in production handling AI chat transcripts in the NV oOS WordPress plugin. It solves the problem of parsing large JSON data (>10KB) without causing browser performance violations.

### Real-World Use Case

In NV oOS, AI assistants generate lengthy conversation transcripts that need to be stored in localStorage. Parsing these synchronously was causing frame drops and "Long Tasks" warnings. This package solved that by:

- Automatically detecting data size
- Offloading heavy parsing to Web Workers
- Maintaining backward compatibility with small data (fast sync parsing)
- Providing graceful fallbacks when workers aren't available

## Installation

```bash
npm install @nvdigital/nvoos-storage
```

## Quick Start

```javascript
import { StorageUtil } from '@nvdigital/nvoos-storage';

// Configure once at app initialization
StorageUtil.configure({
  workerUrl: '/path/to/storage-worker.js',
  sizeThreshold: 10000 // Use worker for data >10KB
});

// Parse large JSON without blocking UI
const transcript = await StorageUtil.parseJSON(largeJsonString);

// Stringify objects asynchronously
const jsonStr = await StorageUtil.stringifyJSON(largeObject);
```

## API

### `StorageUtil.configure(options)`

Configure the utility before first use.

**Options:**
- `workerUrl` (string): Path to your Web Worker script
- `sizeThreshold` (number): Byte threshold for using workers (default: 10000)

### `StorageUtil.parseJSON(jsonString)`

Parse JSON asynchronously. Uses worker for large data, sync for small data.

**Returns:** `Promise<any>`

### `StorageUtil.stringifyJSON(obj)`

Stringify object to JSON. Uses worker for large objects.

**Returns:** `Promise<string>`

### `StorageUtil.cleanup()`

Terminate worker and cleanup resources. Call when done.

## Web Worker Setup

Create `storage-worker.js`:

```javascript
self.addEventListener('message', function(e) {
  const { action, data, id } = e.data;
  
  try {
    let result;
    if (action === 'parse') {
      result = JSON.parse(data);
    } else if (action === 'stringify') {
      result = JSON.stringify(data);
    }
    
    self.postMessage({ id, success: true, result });
  } catch (error) {
    self.postMessage({ id, success: false, error: error.message });
  }
});
```

## Performance Characteristics

- **Small data (<10KB)**: Synchronous, ~0.1ms overhead
- **Medium data (10-100KB)**: Worker, ~5-10ms total
- **Large data (>100KB)**: Worker, ~50-100ms total

Main thread remains responsive during all operations.

## Browser Support

- Chrome/Edge 113+
- Firefox 115+
- Safari 16.4+
- Any browser with Web Worker support

## From WordPress to Universal

This package is extracted from production WordPress plugin code but made framework-agnostic:

- ❌ Removed: `window.wpMcpAiChat` configuration
- ❌ Removed: WordPress-specific console prefixes
- ✅ Added: Manual configuration method
- ✅ Added: ES module exports
- ✅ Added: TypeScript definitions

## License

MIT © NV Digital Solutions

## Links

- [GitHub Repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
- [NV Digital Solutions](https://nvdigitalsolutions.com)
- [Report Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
