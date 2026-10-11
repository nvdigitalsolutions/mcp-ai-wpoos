# NV oOS Packages — Vite Demo

A sample [Vite](https://vitejs.dev) app (vanilla TypeScript) that consumes the
`@nvdigitalsolutions/*` packages extracted from the
[mcp-ai-wpoos](https://github.com/nvdigitalsolutions/mcp-ai-wpoos) WordPress
plugin. Every demo on the page runs **without a WordPress backend** — a dev-only
mock SSE server (see `vite.config.ts`) provides the stream and the slash-command
list.

## Run it

```bash
cd examples/nvoos-vite-demo
npm install
npm run dev        # http://localhost:5173
```

![Demo screenshot](demo-screenshot.png)

Validate:

```bash
npm run typecheck  # tsc --noEmit
npm run build      # tsc --noEmit && vite build
npm run preview    # serve the production build (mock endpoints still work)
```

## What the demo exercises

| Section | Packages | What it shows |
|---|---|---|
| Streaming chat | `nvoos-events` · `nvoos-dom-batcher` · `nvoos-markdown` · `nvoos-storage` · `nvoos-clipboard` | POST SSE stream → RAF-batched token rendering → sanitised markdown → Web-Worker JSON persistence → copy buttons |
| Markdown renderer | `nvoos-markdown` | `marked` + `DOMPurify` live preview |
| Slash commands | `nvoos-slash-commands` | `/` fuzzy autocomplete fed by a mock endpoint |
| Attachment helpers | `nvoos-attachments` | MIME type detection, iconography, segment builders, header helpers |
| Typed API client | `nvoos-api` | Endpoint builders, payload constructors, auth headers |

## Going live against a real NV oOS site

The mock `/api/chat` endpoint exists only in the Vite dev/preview server. To
talk to a real WordPress site running the plugin:

1. In `src/sections/stream.ts`, replace the `SSEService.connect('/api/chat', …)`
   URL with the endpoint built by `nvoos-api`:

   ```ts
   import { sseEndpoint } from '@nvdigitalsolutions/nvoos-api';

   const url = sseEndpoint(
     { restUrl: 'https://your-site.com/wp-json/mcp-ai/v1', nonce: '…' },
     { assistant_id: 7, stream: 'true' },
   );
   ```

2. Add the real `slashCommandListEndpoint` / `slashCommandEndpoint` values in
   `src/sections/slash.ts` (or set `window.mcpAiData` from your theme).
3. Handle CORS / nonce rules from the plugin's REST documentation
   (`docs/rest-api.md` in the main repo).

## Why the extra dependencies?

Some packages declare peer dependencies you must install alongside them:

| Package | Peer dependency |
|---|---|
| `@nvdigitalsolutions/nvoos-events` | `@microsoft/fetch-event-source` |
| `@nvdigitalsolutions/nvoos-markdown` | `marked` (^9), `dompurify` (^3) |
| `@nvdigitalsolutions/nvoos-http-client` | `ky` |
| `@nvdigitalsolutions/nvoos-transformers-client` / `nvoos-client-tools` | `@huggingface/transformers` (optional) |

## Vendored fixes for two broken published dists

The npm-published `0.1.0-alpha.3` dists of `nvoos-slash-commands` and
`nvoos-dom-batcher` are syntactically invalid ESM (their `adapt-for-npm.cjs`
scripts failed to strip the IIFE wrapper, so the `export` statements ended up
inside the IIFE). The generator scripts are fixed in this repo and the rebuilt
dists are copied into `vendor/`; `vite.config.ts` aliases the two package names
to those fixed copies. Once fixed versions are republished to npm
(`npm run build` inside `packages/nvoos-slash-commands` and
`packages/nvoos-dom-batcher`, then publish), the aliases and the `vendor/`
folder can be deleted.

## All available packages

See [`packages/README.md`](../../packages/README.md) for the full catalogue —
23 published packages across 6 tiers, including browser-AI runtimes
(`nvoos-llm-worker`, `nvoos-model-loader`), voice (`nvoos-audio`, `nvoos-vad`,
`nvoos-transcription`), and the TypeScript-native SDK (`nvoos-types`,
`nvoos-api`, `nvoos-sse-client`).
