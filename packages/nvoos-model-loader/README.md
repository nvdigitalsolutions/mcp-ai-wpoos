# @nvdigitalsolutions/nvoos-model-loader

Progressive AI model loading UI with a 4-stage progress indicator (checking → downloading → initializing → ready). Generic over any engine factory that accepts an `initProgressCallback` (WebLLM, Transformers.js, custom).

**Extracted from:** [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress Plugin

## Why This Package?

Loading a multi-gigabyte model in the browser is a long, opaque process. Users need to know:

- "Is something happening?"
- "How far along is it?"
- "Was the model already cached?"
- "If it failed, what failed?"

This package renders all of that with zero dependencies and a small, themable DOM tree.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-model-loader
```

## Quick Start

```javascript
import { ProgressiveModelLoader } from '@nvdigitalsolutions/nvoos-model-loader';
import { CreateMLCEngine } from '@mlc-ai/web-llm';

const loader = new ProgressiveModelLoader({
  engineFactory: (modelId, opts) => CreateMLCEngine(modelId, opts)
});

const engine = await loader.loadWithUI(
  'Llama-3-8B-Instruct-q4f32_1-MLC',
  document.getElementById('model-loader-slot')
);
```

## API

### `new ProgressiveModelLoader(options?)`

- `options.engineFactory` (function): `(modelId, { initProgressCallback }) => Promise<engine>`
- `options.classNames` (object): override default CSS class names. Defaults:
  - `container: 'nvoos-model-loading'`
  - `stage: 'loading-stage'`
  - `progressBar: 'progress-bar'`
  - `progressFill: 'progress-fill'`
  - `progressText: 'progress-text'`
  - `details: 'loading-details'`
  - `error: 'loading-error'`
- `options.stages` (array): override the 4 default stages.

### `.configure(options)`

Update `engineFactory` and/or `classNames` after construction.

### `.loadWithUI(modelId, container)` → `Promise<engine>`

Renders the loading UI inside `container`, calls the engine factory, returns the initialised engine, and removes the UI 1 second after success. Throws (and shows an error UI) on failure.

### `.checkModelCache(modelId)` → `Promise<boolean>`

Inspects the `webllm-models` Cache Storage bucket for any entry whose URL contains the model id.

## Styling

Override the class names via `classNames` and bring your own CSS:

```css
.nvoos-model-loading { padding: 1rem; border-radius: 8px; }
.nvoos-model-loading .progress-bar { background: #eee; }
.nvoos-model-loading .progress-fill { background: #2563eb; transition: width .2s; }
.nvoos-model-loading .loading-error { color: #b91c1c; }
```

## Browser Support

- Any browser with `caches` support for the cache check (others fall back to "not cached").
- DOM APIs only — no jQuery, no framework lock-in.

## From WordPress to Universal

- ❌ Removed: hard dependency on global `CreateMLCEngine`
- ❌ Removed: hard-coded `wp-mcp-ai-model-loading` class names
- ✅ Added: injectable `engineFactory`
- ✅ Added: configurable `classNames` and `stages`
- ✅ Added: `configure()` method
- ✅ Added: ES module exports + TypeScript definitions

## License

MIT © NV Digital Solutions

## Links

- [GitHub Repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
- [NV Digital Solutions](https://nvdigitalsolutions.com)
- [Report Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
