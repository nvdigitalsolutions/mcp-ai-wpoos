# @nvdigitalsolutions/nvoos-transformers-client

Browser-native AI tasks via [HuggingFace Transformers.js v3](https://github.com/huggingface/transformers.js): summarization, sentiment analysis, named entity recognition, translation, question answering, and embeddings — with WebGPU acceleration and automatic WASM fallback.

**Extracted from:** [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress Plugin

## Why This Package?

Transformers.js is powerful but boilerplate-heavy. You need to:

- Detect WebGPU vs WASM
- Pick correct quantization (`dtype`)
- Lazy-load a pipeline per task
- Cache pipelines across calls
- Handle progress callbacks
- Group sub-token NER outputs into entities

This package handles all of that and exposes one method per task. Pipelines are cached per `(task, model)` pair, so repeat calls cost only inference.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-transformers-client
```

The `@huggingface/transformers` package is loaded **at runtime** (default: from a CDN) so the dependency is optional. If you want to bundle it yourself, install it as a peer:

```bash
npm install @huggingface/transformers
```

…and pass an importer (see [Bundled Transformers.js](#bundled-transformersjs) below).

## Quick Start

```javascript
import { TransformersTasksClient } from '@nvdigitalsolutions/nvoos-transformers-client';

const client = new TransformersTasksClient();

// Summarization
const sum = await client.summarize('Your long article text here…');
console.log(sum.summary);

// Sentiment
const sent = await client.sentiment('I love this product!');
console.log(sent.label, sent.confidence);

// Named entities
const ents = await client.extractEntities('Barack Obama visited Paris.');
console.log(ents.entities);

// Translation
const tr = await client.translate('Hello world', {
  sourceLang: 'eng_Latn',
  targetLang: 'fra_Latn'
});
console.log(tr.translatedText);

// QA
const ans = await client.questionAnswering(
  'Where is Paris?',
  'Paris is the capital of France.'
);
console.log(ans.answer, ans.confidence);

// Embeddings (semantic search)
const embed = await client.embed(['hello', 'goodbye']);
console.log(embed.embeddings.length, embed.dimensions);
```

## API

### `new TransformersTasksClient(options?)`

- `options.transformersUrl` (string): URL of the `@huggingface/transformers` ESM build. Defaults to the v3.8.1 jsdelivr CDN.
- `options.transformersImporter` (function): `() => Promise<module>`. Lets you supply a bundled import — overrides `transformersUrl` when set.
- `options.device` (`'webgpu' | 'wasm' | null`): force a backend. `null` (default) auto-detects.
- `options.dtype` (string): quantization dtype. Default `'q8'`.
- `options.models` (object): override any of the default model identifiers.

### `.configure(options)`

Update any of the constructor options after construction.

### Task methods

All return `{ success: true, ... }` and lazy-load their pipeline on first call.

| Method | Returns |
|--------|---------|
| `summarize(text, { maxLength?, minLength? })` | `{ summary, originalLength, summaryLength }` |
| `sentiment(text)` | `{ label, score, confidence }` |
| `extractEntities(text)` | `{ entities: [{text, type, score}], count }` |
| `translate(text, { sourceLang?, targetLang? })` | `{ translatedText, sourceLang, targetLang }` |
| `questionAnswering(question, context)` | `{ answer, score, confidence, start, end }` |
| `embed(text \| string[])` | `{ embeddings: number[][], dimensions }` |

### Utility

- `.detectDevice()` → `'webgpu' \| 'wasm'`
- `.getPipeline(task, model)` → `Promise<pipeline>`
- `.isTaskAvailable(task)` → `boolean`
- `.getAvailableTasks()` → `string[]`
- `.clearCache()` → void

## Default Models

| Task | Model |
|------|-------|
| `summarization` | `Xenova/distilbart-cnn-6-6` |
| `sentiment` | `Xenova/distilbert-base-uncased-finetuned-sst-2-english` |
| `ner` | `Xenova/bert-base-NER` |
| `translation` | `Xenova/nllb-200-distilled-600M` |
| `qa` | `Xenova/distilbert-base-uncased-distilled-squad` |
| `embedding` | `Xenova/all-MiniLM-L6-v2` |

Override any of these via `new TransformersTasksClient({ models: { sentiment: 'your/model' } })`.

## Bundled Transformers.js

If you'd rather bundle Transformers.js with your app:

```javascript
import * as transformers from '@huggingface/transformers';
import { TransformersTasksClient } from '@nvdigitalsolutions/nvoos-transformers-client';

const client = new TransformersTasksClient({
  transformersImporter: async () => transformers
});
```

## Browser Support

- WebGPU acceleration on Chrome/Edge 113+
- WASM fallback in any modern browser
- Requires `import()` (dynamic imports)

## From WordPress to Universal

- ❌ Removed: `window.WP_MCP_AI_TransformersTasksClient` global initialisation
- ❌ Removed: hard-coded jsdelivr import URL (still default, now configurable)
- ✅ Added: `transformersImporter` for bundled installs
- ✅ Added: configurable `device`, `dtype`, and per-task `models`
- ✅ Added: `configure()` method
- ✅ Added: ES module exports + TypeScript definitions

## License

MIT © NV Digital Solutions

## Links

- [GitHub Repository](https://github.com/nvdigitalsolutions/mcp-ai-wpoos)
- [NV Digital Solutions](https://nvdigitalsolutions.com)
- [Report Issues](https://github.com/nvdigitalsolutions/mcp-ai-wpoos/issues)
