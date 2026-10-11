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
 * browser, cookies + nonce auth just work). The wp-login.php page body is
 * re-scoped to the app origin (relative form action + redirect_to) so the
 * login POST and post-login redirect stay inside the proxy — otherwise the
 * auth cookies would be set for the WordPress host and the app origin would
 * never see the session. Mirrors the Vite dev proxy in vite.config.ts — keep
 * the two in sync. Never set NVOOS_TARGET_SITE to a URL you do not control;
 * the proxy forwards cookies for those prefixes.
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

const LOGIN_PATH = '/wp-login.php';
// Cap on body size for the login-page rewrite. wp-login.php pages are a few
// tens of KB; anything larger is passed through unchanged instead of running
// the regexes over a huge body.
const MAX_REWRITE_BYTES = 4 * 1024 * 1024;

/**
 * Re-scope the wp-login.php page to the app origin.
 *
 * WordPress renders the login form with an ABSOLUTE action (site_url) and an
 * absolute redirect_to value. If the browser follows them, the login POST and
 * the post-login redirect land on the WordPress origin — the auth cookies are
 * then set for the WordPress host and the app origin never sees the session.
 * On localhost this is masked (cookies are scoped by host, not port), but with
 * different hosts in production it breaks cookie auth entirely. Rewrite:
 *
 *   1. Every absolute URL ending in /wp-login.php (form actions, lost-password
 *      and register links) becomes an app-origin relative path, so the login
 *      POST goes through this proxy.
 *   2. Absolute redirect_to values are re-scoped to the app origin, so the
 *      post-login redirect comes back through the proxy (where the Location
 *      rewrite in rewriteUpstreamHeaders keeps it on the app origin).
 *
 * Mirrors rewriteLoginPage in vite.config.ts — keep the two in sync.
 */
function rewriteLoginPage(html) {
  return html
    .replace(
      /(["'])https?:\/\/[^"']*?(\/[^"']*?wp-login\.php(?=[?"'\s]))/gi,
      '$1$2',
    )
    .replace(
      /(\bname=["']redirect_to["']\s+value=["'])https?:\/\/[^"']*?(\/.*?)?(["'])/gi,
      '$1$2$3',
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
  const isLoginPage = incoming.pathname === LOGIN_PATH;

  const headers = { ...req.headers };
  delete headers.host; // Replaced below.
  delete headers.origin; // Browser origin — meaningless to the upstream.
  delete headers.referer; // Would leak the app origin into WP logs.
  if (isLoginPage) delete headers['accept-encoding']; // Identity body, so it can be rewritten.
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
      const outHeaders = rewriteUpstreamHeaders(upRes.headers, upstreamUrl.origin);
      const contentType = String(upRes.headers['content-type'] ?? '').toLowerCase();
      const canRewrite =
        isLoginPage &&
        contentType.includes('text/html') &&
        !upRes.headers['content-encoding'];

      if (!canRewrite) {
        // SSE (chat stream), chunked bodies and non-HTML responses pipe through unchanged.
        res.writeHead(upRes.statusCode ?? 502, upRes.statusMessage, outHeaders);
        upRes.pipe(res);
        return;
      }

      // Buffer the login page so the form can be re-scoped to the app origin
      // (see rewriteLoginPage). rewriteUpstreamHeaders has already dropped
      // content-length, so the rewritten body goes out chunked.
      const chunks = [];
      let size = 0;
      upRes.on('data', (chunk) => {
        size += chunk.length;
        chunks.push(chunk);
      });
      upRes.on('end', () => {
        let body = Buffer.concat(chunks).toString('utf8');
        if (size <= MAX_REWRITE_BYTES) {
          body = rewriteLoginPage(body);
        }
        res.writeHead(upRes.statusCode ?? 502, upRes.statusMessage, outHeaders);
        res.end(body);
      });
      upRes.on('error', () => res.destroy());
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

server.on('error', (error) => {
	// Surface startup failures (EADDRINUSE, EACCES, bad PORT) with an
	// actionable line — PM2/Velocity only reports "process crashed" and
	// masks the real reason.
	console.error(
		`[nvoos-pro-spa-standalone] failed to start on :${PORT}: ${error.code || 'ERR'} ${error.message}`,
	);
	if (error.code === 'EADDRINUSE') {
		console.error(
			'[nvoos-pro-spa-standalone] port already in use — a stale app instance may still hold it; run `pm2 list` and stop duplicates.',
		);
	}
	process.exit(1);
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
