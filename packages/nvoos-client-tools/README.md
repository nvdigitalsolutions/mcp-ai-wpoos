# @nvdigitalsolutions/nvoos-client-tools

Browser-native AI **tool registry** powered by [Transformers.js](https://huggingface.co/docs/transformers.js) — extracted from the [NV Open Operator System (oOS)](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress plugin.

Ship a single, OpenAI-style tool registry with seven privacy-first AI tools that run **entirely in the browser**:

| Tool | Task | Default Model |
|------|------|---------------|
| `client_summarize` | Summarisation | `Xenova/distilbart-cnn-12-6` |
| `client_sentiment` | Sentiment analysis | default sentiment model |
| `client_translate` | Translation (NLLB-200) | `Xenova/nllb-200-distilled-600M` |
| `client_embed` | Sentence embeddings | `Xenova/all-MiniLM-L6-v2` |
| `client_describe_image` | Image captioning | `Xenova/vit-gpt2-image-captioning` |
| `client_detect_objects` | Object detection (DETR) | `Xenova/detr-resnet-50` |
| `client_transcribe_audio` | Speech-to-text (Whisper) | `Xenova/whisper-tiny.en` |

Each tool exposes a JSON-schema `parameters` block compatible with OpenAI/Anthropic-style tool calling, plus an `execute(args)` method that returns a Promise.

## Installation

```bash
npm install @nvdigitalsolutions/nvoos-client-tools
# Optional — bundle Transformers.js, or load it from the CDN
npm install @huggingface/transformers
```

## Quick Start

```javascript
import { configure, executeTool } from '@nvdigitalsolutions/nvoos-client-tools';
import { pipeline } from '@huggingface/transformers';

// Inject the pipeline factory once at boot.
configure({ pipeline });

const summary = await executeTool('client_summarize', {
  text: '... long article text ...',
  max_length: 120,
});
console.log(summary);
```

### CDN fallback

If you load Transformers.js from a CDN (so `globalThis.pipeline` is defined), you can skip the `configure()` call entirely — the package falls back to `globalThis.pipeline` automatically.

## API

### `configure(options)`

Inject a Transformers.js pipeline factory. Call once at boot.

```javascript
configure({
  pipeline, // typeof pipeline (from @huggingface/transformers)
});
```

### `getTools()`

Returns the full tool registry as an object keyed by tool name. Useful for surfacing the OpenAI-style schemas to a model.

```javascript
const tools = getTools();
// tools.client_summarize.parameters → JSON schema
```

### `getTool(name)`

Returns a single tool definition (or `null`).

### `executeTool(name, args)`

Convenience wrapper that resolves the tool and runs `execute(args)`.

### `CLIENT_TOOLS`

Direct access to the underlying registry object (the same object returned by `getTools()`).

## Why this package?

NV oOS originally embedded these seven tools as a global `WP_MCP_AI_ClientTools` object, registered automatically when the plugin was loaded. Outside WordPress that pattern doesn't compose well, so this package:

- Removes the global side-effect on import
- Makes the Transformers.js dependency injectable (CDN, ESM, or custom factory)
- Adds a small `executeTool()` helper for ergonomics
- Ships TypeScript types

## License

MIT — see `LICENSE`.
