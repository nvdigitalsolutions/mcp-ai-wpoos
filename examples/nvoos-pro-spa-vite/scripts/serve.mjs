/**
 * Minimal static server for Cloudways Velocity (and `npm run serve`).
 *
 * Serves the production build from dist/ with SPA-friendly fallback to
 * index.html. Velocity injects PORT and runs Node apps behind NGINX + PM2.
 * No dependencies.
 */

import { createServer } from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = Number(process.env.PORT || 4173);
const ROOT = fileURLToPath(new URL('../dist', import.meta.url));

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
};

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url ?? '/', 'http://localhost');
    let pathname = normalize(decodeURIComponent(url.pathname)).replace(/^([/\\])+/, '');
    if (!pathname || pathname === '.') pathname = 'index.html';

    let file = join(ROOT, pathname);
    try {
      await stat(file);
      if ((await stat(file)).isDirectory()) file = join(file, 'index.html');
    } catch {
      // SPA fallback for client-side (hash) routes.
      file = join(ROOT, 'index.html');
    }

    const body = await readFile(file);
    res.writeHead(200, {
      'Content-Type': MIME[extname(file)] ?? 'application/octet-stream',
      'Cache-Control': file.endsWith('index.html') ? 'no-cache' : 'public, max-age=31536000, immutable',
    });
    res.end(body);
  } catch {
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('Not found');
  }
});

server.listen(PORT, () => {
  console.log(`[nvoos-pro-spa-standalone] serving dist/ on :${PORT}`);
});
