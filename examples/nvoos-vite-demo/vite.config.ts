import { defineConfig, type Plugin, type Connect } from 'vite';
import type { ServerResponse } from 'node:http';
import { fileURLToPath, URL } from 'node:url';

/**
 * The npm-published 0.1.0-alpha.3 dists of nvoos-slash-commands and
 * nvoos-dom-batcher are broken (their adapt-for-npm.js scripts left the
 * IIFE wrapper unclosed, placing the ES export statements inside the IIFE —
 * syntactically invalid ESM that Rollup/esbuild reject).
 *
 * The generator scripts are fixed in this repo (see the adapt-for-npm.js
 * files under packages/) and the rebuilt dists are vendored in ./vendor/.
 * Remove these aliases once fixed versions are republished to npm.
 */

/**
 * Canned markdown "AI" answer streamed token-by-token from a mock SSE endpoint.
 * This keeps the demo fully self-contained: no WordPress backend required.
 *
 * Swap `SSEService.connect('/api/chat', …)` in src/sections/stream.ts for
 * `sseEndpoint(config, …)` from @nvdigitalsolutions/nvoos-api to go live.
 */
const CANNED_RESPONSE = [
  '# Hello from NV oOS 👋',
  '',
  'This answer was **streamed** over Server-Sent Events by',
  '`@nvdigitalsolutions/nvoos-events`, batched into the DOM with',
  '`@nvdigitalsolutions/nvoos-dom-batcher`, and rendered safely with',
  '`@nvdigitalsolutions/nvoos-markdown` (marked + DOMPurify).',
  '',
  'The transcript is persisted through `@nvdigitalsolutions/nvoos-storage`',
  '(Web-Worker JSON) and the copy button comes from',
  '`@nvdigitalsolutions/nvoos-clipboard`.',
  '',
  '```ts',
  "const stream = SSEService.connect('/api/chat', {",
  "  method: 'POST',",
  '  body: { messages },',
  '  onMessage: (data) => renderToken(data.token),',
  '});',
  '```',
  '',
  '## What else is in the packages?',
  '',
  '- **nvoos-api** — typed REST endpoint builders for the NV oOS WP API',
  '- **nvoos-attachments** — file type detection & segment builders',
  '- **nvoos-slash-commands** — fuzzy `/` command autocomplete',
  '- **nvoos-audio** — TTS / STT / voice chat with VAD',
  '- **nvoos-llm-worker** + **nvoos-model-loader** — in-browser AI runtimes',
  '',
  '> Powered by the packages in `packages/` of the mcp-ai-wpoos repo.',
].join('\n');

/** SSE stream of the canned response, ending with a `{ done: true }` message. */
function handleMockChat(req: Parameters<Connect.NextHandleFunction>[0], res: ServerResponse): void {
  if (req.method !== 'POST') {
    res.statusCode = 405;
    res.end('Method Not Allowed');
    return;
  }
  req.resume(); // Drain the POST body (the demo ignores it).

  res.writeHead(200, {
    'Content-Type': 'text/event-stream; charset=utf-8',
    'Cache-Control': 'no-cache',
    Connection: 'keep-alive',
  });

  const tokens = CANNED_RESPONSE.split(/(\s+)/);
  let index = 0;

  const timer = setInterval(() => {
    if (index >= tokens.length) {
      clearInterval(timer);
      res.write('data: {"done":true}\n\n');
      res.end();
      return;
    }
    res.write(`data: ${JSON.stringify({ token: tokens[index] })}\n\n`);
    index += 1;
  }, 16);

  // Clean up when the client disconnects. NB: listen on the *response* —
  // the request's 'close' event fires as soon as the request body has been
  // read (req.resume() above), which would kill the stream immediately.
  res.on('close', () => {
    clearInterval(timer);
    if (!res.writableEnded) {
      res.end();
    }
  });
}

/** Mock slash-command list for @nvdigitalsolutions/nvoos-slash-commands. */
function handleMockSlashCommands(_req: Parameters<Connect.NextHandleFunction>[0], res: ServerResponse): void {
  res.writeHead(200, { 'Content-Type': 'application/json' });
  res.end(
    JSON.stringify({
      commands: [
        { name: 'help', description: 'Show available commands', category: 'utility' },
        { name: 'summarise', description: 'Summarise the current page', category: 'ai' },
        { name: 'translate', description: 'Translate the selected text', category: 'ai' },
        { name: 'image', description: 'Generate an image', category: 'media' },
        { name: 'clear', description: 'Clear the conversation', category: 'utility' },
      ],
    }),
  );
}

/**
 * Dev-only mock endpoints. `configureServer` covers `vite dev`, and
 * `configurePreviewServer` covers `vite preview` so `npm run preview`
 * also works after a build.
 */
function nvoosMockEndpoints(): Plugin {
  const attach = (middlewares: Connect.Server): void => {
    middlewares.use('/api/chat', handleMockChat);
    middlewares.use('/api/slash-commands', handleMockSlashCommands);
  };

  return {
    name: 'nvoos-mock-endpoints',
    configureServer(server) {
      attach(server.middlewares);
    },
    configurePreviewServer(server) {
      attach(server.middlewares);
    },
  };
}

export default defineConfig({
  plugins: [nvoosMockEndpoints()],
  resolve: {
    alias: {
      '@nvdigitalsolutions/nvoos-slash-commands': fileURLToPath(new URL('./vendor/nvoos-slash-commands.js', import.meta.url)),
      '@nvdigitalsolutions/nvoos-dom-batcher': fileURLToPath(new URL('./vendor/nvoos-dom-batcher.js', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    open: false,
  },
});
