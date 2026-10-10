/**
 * Minimal static server for Cloudways Velocity (and `npm run serve`).
 *
 * Serves the production build from dist/ with SPA-friendly fallback to
 * index.html. Velocity injects PORT and runs Node apps behind NGINX + PM2.
 * No dependencies.
 *
 * Optional built-in reverse proxy for cookie-auth mode: when
 * NVOOS_TARGET_SITE is set, /wp-json, /wp-admin, /wp-login.php and
 * /wp-includes are proxied to that WordPress site (same-origin for the
 * browser, cookies + nonce auth just work). Mirrors the Vite dev proxy in
 * vite.config.ts — keep the two in sync. Never set NVOOS_TARGET_SITE to a
 * URL you do not control; the proxy forwards cookies for those prefixes.
 */

import { createServer } from 'node:http';
import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const PORT = Number(process.env.PORT || 4173);
const ROOT = fileURLToPath(new URL('../dist', import.meta.url));

const PROXY_TARGET = (process.env.NVOOS_TARGET_SITE || '').replace(/\/+$/, '');
const PROXY_PREFIXES = ['/wp-json', '/wp-admin', '/wp-login.php', '/wp-includes'];
// Hop-by-hop headers Node manages itself — passing them through would
// conflict with its own framing/connection handling.
const HOP_BY_HOP = new Set([
  'connection',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'te',
  'trailers',
  'upgrade',
  'transfer-encoding',
  'content-length',
]);

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

function isProxyPath(pathname) {
  return PROXY_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
}

/**
 * Keep the browser on the app origin when WordPress redirects (login flow):
 * absolute Location URLs pointing at the target become relative, the
 * redirect_to parameter is re-scoped to the app origin, and Set-Cookie
 * Domain attributes are dropped so cookies are host-only for the app
 * (mirrors keepLoginOnDevOrigin in vite.config.ts).
 */
function rewriteUpstreamHeaders(headers, targetOrigin) {
  const out = { ...headers };

  if (typeof out.location === 'string' && out.location.startsWith(targetOrigin)) {
    out.location = out.location
      .slice(targetOrigin.length)
      .replace(`redirect_to=${encodeURIComponent(targetOrigin)}`, 'redirect_to=');
  }

  const cookies = out['set-cookie'];
  if (Array.isArray(cookies)) {
    out['set-cookie'] = cookies.map((cookie) => cookie.replace(/;\s*domain=[^;]+/gi, ''));
  } else if (typeof cookies === 'string') {
    out['set-cookie'] = cookies.replace(/;\s*domain=[^;]+/gi, '');
  }

  for (const name of Object.keys(out)) {
    if (HOP_BY_HOP.has(name.toLowerCase())) delete out[name];
  }
  return out;
}

function proxyRequest(req, res) {
  const incoming = new URL(req.url ?? '/', 'http://localhost');
  const upstreamUrl = new URL(incoming.pathname + incoming.search, PROXY_TARGET);
  const transport = upstreamUrl.protocol === 'https:' ? httpsRequest : httpRequest;

  const headers = { ...req.headers };
  delete headers.host; // Replaced below.
  delete headers.origin; // Browser origin — meaningless to the upstream.
  delete headers.referer; // Would leak the app origin into WP logs.
  headers.host = upstreamUrl.host;

  const upstream = transport(
    {
      hostname: upstreamUrl.hostname,
      port: upstreamUrl.port || (upstreamUrl.protocol === 'https:' ? 443 : 80),
      path: upstreamUrl.pathname + upstreamUrl.search,
      method: req.method,
      headers,
      // Self-signed / staging certs are common for WordPress targets.
      rejectUnauthorized: false,
    },
    (upRes) => {
      // SSE (chat stream) and chunked bodies pipe through unchanged.
      res.writeHead(
        upRes.statusCode ?? 502,
        upRes.statusMessage,
        rewriteUpstreamHeaders(upRes.headers, upstreamUrl.origin),
      );
      upRes.pipe(res);
    },
  );

  upstream.on('error', (error) => {
    if (res.headersSent) {
      res.destroy();
      return;
    }
    res.writeHead(502, { 'Content-Type': 'application/json; charset=utf-8' });
    res.end(
      JSON.stringify({
        code: 'nvoos_bad_gateway',
        message: `Upstream WordPress site unreachable: ${error.message}`,
      }),
    );
  });

  req.on('error', () => upstream.destroy());
  req.pipe(upstream);
}

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url ?? '/', 'http://localhost');

    if (PROXY_TARGET && isProxyPath(url.pathname)) {
      proxyRequest(req, res);
      return;
    }

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
  if (PROXY_TARGET) {
    console.log(
      `[nvoos-pro-spa-standalone] proxying ${PROXY_PREFIXES.join(', ')} → ${PROXY_TARGET} (cookie auth enabled)`,
    );
  } else {
    console.log(
      '[nvoos-pro-spa-standalone] no NVOOS_TARGET_SITE set — cookie mode unavailable, bearer/guest only',
    );
  }
});
