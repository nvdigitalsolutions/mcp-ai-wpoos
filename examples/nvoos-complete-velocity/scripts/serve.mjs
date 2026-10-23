#!/usr/bin/env node
/**
 * Minimal static server for the NV oOS Complete Console on Cloudways
 * Velocity (and `npm run serve`).
 *
 * Serves the production build from dist/ with SPA-friendly fallback to
 * index.html. Velocity injects PORT and runs Node apps behind NGINX + PM2.
 * No dependencies.
 *
 * Built-in reverse proxy for cookie-auth mode: when NVOOS_TARGET_SITE is set
 * (the WordPress backend running the NV oOS Complete bundle — base + Pro +
 * addons), /wp-json, /wp-admin, /wp-login.php and /wp-includes are proxied
 * to that site — same-origin for the browser, so WordPress cookies + the
 * wp_rest nonce handshake (wp-admin page scrape with the
 * mcp-ai/v1/session/nonce fallback — see src/runtime-config.ts) just work.
 * The wp-login.php page body is re-scoped to the app origin (relative form
 * action + redirect_to) so the login POST and post-login redirect stay
 * inside the proxy — otherwise the auth cookies would be set for the
 * WordPress host and the app origin would never see the session.
 *
 * Caching contract (keeps browser + Velocity CDN load off WordPress):
 *   - index.html → `Cache-Control: no-cache` + weak ETag → 304 revalidation
 *     instead of a full-body re-download on every navigation.
 *   - Hashed /assets/* → `public, max-age=31536000, immutable` (Vite
 *     content-hashes every chunk; the Velocity CDN caches these at the
 *     edge, so WordPress never sees them).
 *   - Unversioned root files (favicon, …) → `public, max-age=86400` + ETag
 *     (they are NOT hashed, so they must not get the immutable treatment).
 *   - gzip/brotli served from an in-memory file cache for compressible
 *     types with `Vary: Accept-Encoding` (the entry chunk is ~90 KB gzip
 *     vs ~300 KB raw).
 *   - Proxied GET responses are micro-cached ONLY when the WordPress
 *     backend explicitly marks them cacheable (`Cache-Control: public` /
 *     `s-maxage` / `max-age`, no Set-Cookie, `Vary` ≠ Cookie/Origin/*).
 *     The nonce endpoint and SSE already send `no-store` and are never
 *     cached. Default-deny: unmarked responses stream through uncached.
 *     Env knobs: `NVOOS_PROXY_CACHE=0` (disable),
 *     `NVOOS_PROXY_CACHE_MAX_TTL` (TTL cap in seconds, default 300).
 *
 * Ported from examples/schedule-anything-velocity/scripts/serve.mjs (the
 * proven Schedule Anything Velocity entry). The proxy core is intentionally
 * identical; the caching layer, port and log prefix were changed. The
 * schedule-anything-platform plugin is NOT part of this app's backend —
 * that plugin serves the Schedule Anything tenant SPA only.
 * Never set NVOOS_TARGET_SITE to a URL you do not control; the proxy
 * forwards cookies for those prefixes.
 */

import { createServer } from 'node:http';
import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';
import { gzipSync, brotliCompressSync } from 'node:zlib';

const PORT = Number(process.env.PORT || 4181);
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
  '.map': 'application/json; charset=utf-8',
  '.txt': 'text/plain; charset=utf-8',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.svg': 'image/svg+xml',
  '.webp': 'image/webp',
  '.avif': 'image/avif',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
};

/**
 * Security headers for static SPA responses only — the proxied WordPress
 * branch (proxyRequest) passes upstream headers through untouched.
 *
 * HSTS rollout: start at 1 day, verify no mixed-content reports, then
 * raise to 31536000 (1 year). Do NOT add includeSubDomains yet —
 * nvoos.cloud hosts several subdomains and it commits all of them to
 * HTTPS permanently. Skip preload until the team opts in.
 */
const SECURITY_HEADERS = {
  'X-Content-Type-Options': 'nosniff',
  // Ramp: max-age=86400 → 31536000 after a clean week.
  'Strict-Transport-Security': 'max-age=86400',
};

/*
 * CSP is intentionally NOT enforced yet — roll it out in Report-Only mode
 * first, and apply it ONLY to the static branch below (never to proxied
 * WordPress responses — wp-admin breaks under a global policy). Template:
 *
 * const CSP_REPORT_ONLY = {
 *   'Content-Security-Policy-Report-Only':
 *     "default-src 'self'; " +
 *     "connect-src 'self'; " +
 *     "script-src 'self' 'unsafe-inline'; " +
 *     'report-to csp-endpoint',
 * };
 */

// --- Static file cache (in-memory) ------------------------------------
// dist/ ships with the app and never changes during a process lifetime
// (deploys start a fresh PM2 process), so entries are validated by
// mtime+size and never need invalidation.
const COMPRESSIBLE_TYPES = new Set(['.html', '.js', '.css', '.json', '.map', '.svg', '.txt']);
// Skip compression below 1 KB — the overhead outweighs the win.
const MIN_COMPRESS_BYTES = 1024;
// Files larger than this are read from disk per request instead of being
// pinned in memory (the console bundle is far below this).
const STATIC_CACHE_MAX_BYTES = 4 * 1024 * 1024;

const staticCache = new Map(); // path -> { size, mtimeMs, etag, identity, gzip, br }

function etagMatches(headerValue, etag) {
  if (!headerValue) return false;
  const wanted = etag.replace(/^W\//, '').trim();
  return headerValue
    .split(',')
    .some((candidate) => candidate.trim().replace(/^W\//, '') === wanted);
}

function compressedVariant(entry, encoding) {
  // Lazy per-encoding build — an encoding a client never asks for costs
  // no CPU.
  if (encoding === 'br') {
    if (entry.br === null) entry.br = brotliCompressSync(entry.identity);
    return entry.br;
  }
  if (entry.gzip === null) entry.gzip = gzipSync(entry.identity);
  return entry.gzip;
}

function pickContentEncoding(req, entry) {
  if (!entry.compressible || entry.size < MIN_COMPRESS_BYTES) return null;
  const accept = String(req.headers['accept-encoding'] ?? '').toLowerCase();
  if (accept.includes('br')) return 'br';
  if (accept.includes('gzip')) return 'gzip';
  return null;
}

async function loadStaticFile(file) {
  const cached = staticCache.get(file);
  const st = await stat(file);
  if (cached && cached.size === st.size && cached.mtimeMs === st.mtimeMs) {
    return cached;
  }
  const identity = await readFile(file);
  const entry = {
    size: identity.length,
    mtimeMs: st.mtimeMs,
    // Weak ETag: the body varies with Accept-Encoding (see Vary below).
    etag: `W/"${identity.length.toString(16)}-${Math.floor(st.mtimeMs).toString(16)}"`,
    compressible: COMPRESSIBLE_TYPES.has(extname(file)),
    identity,
    gzip: null,
    br: null,
  };
  if (identity.length <= STATIC_CACHE_MAX_BYTES) staticCache.set(file, entry);
  return entry;
}

// --- Proxy response micro-cache ----------------------------------------
// Default-deny: only responses WordPress itself marks as publicly
// cacheable are stored, and only for the TTL the backend declared (capped
// by NVOOS_PROXY_CACHE_MAX_TTL). Session-bound payloads (Set-Cookie,
// Cache-Control: private/no-store/no-cache, Vary: Cookie/Origin/*) and
// non-GET methods are never cached, so no user's response can be replayed
// to another. This absorbs console polling/navigation bursts (dashboard
// stats, lists refetched on route change) that would otherwise hit
// WordPress every time.
const proxyCache = new Map(); // key -> { headers, body, storedAt, expiresAt }
const PROXY_CACHE_MAX_ENTRIES = 512;
const PROXY_CACHE_MAX_BYTES = 2 * 1024 * 1024; // per response
const PROXY_CACHE_TTL_CAP = Number(process.env.NVOOS_PROXY_CACHE_MAX_TTL || 300);
const PROXY_CACHE_ENABLED = process.env.NVOOS_PROXY_CACHE !== '0';

function proxyCacheKey(req, incoming) {
  // The stored bytes depend on the negotiated Content-Encoding, so the
  // encoding bucket is part of the key (clients are consistent per URL).
  const accept = String(req.headers['accept-encoding'] ?? '').toLowerCase();
  const encoding = accept.includes('br') ? 'br' : accept.includes('gzip') ? 'gzip' : 'identity';
  return `${req.method} ${incoming.pathname}${incoming.search} ${encoding}`;
}

/**
 * TTL in seconds the upstream response may be cached, or 0 when it must
 * not be. Only explicit backend signals count — no heuristic caching.
 */
function proxyCacheableTtl(upRes) {
  if (upRes.statusCode !== 200) return 0;
  if (upRes.headers['set-cookie']) return 0;
  const contentType = String(upRes.headers['content-type'] ?? '').toLowerCase();
  if (contentType.includes('text/event-stream')) return 0;
  const vary = String(upRes.headers.vary ?? '').toLowerCase();
  if (vary.split(',').some((v) => ['*', 'cookie', 'origin'].includes(v.trim()))) return 0;
  const tokens = String(upRes.headers['cache-control'] ?? '')
    .toLowerCase()
    .split(',')
    .map((token) => token.trim());
  if (tokens.includes('private') || tokens.includes('no-store') || tokens.includes('no-cache')) {
    return 0;
  }
  let ttl = 0;
  for (const token of tokens) {
    const match = token.match(/^(s-maxage|max-age)=(\d+)$/);
    if (match) ttl = Math.max(ttl, Number(match[2]));
  }
  if (!ttl) {
    const expires = Date.parse(String(upRes.headers.expires ?? ''));
    if (!Number.isNaN(expires)) ttl = Math.floor((expires - Date.now()) / 1000);
  }
  if (ttl <= 0) return 0;
  return Math.min(ttl, PROXY_CACHE_TTL_CAP);
}

function proxyCacheStore(key, ttl, headers, body) {
  if (proxyCache.size >= PROXY_CACHE_MAX_ENTRIES) {
    // Map iteration is insertion order — evict the oldest entry.
    proxyCache.delete(proxyCache.keys().next().value);
  }
  const stored = { ...headers }; // Already hop-by-hop-stripped by the caller.
  // Session-bound response headers must never be replayed to another
  // user. Set-Cookie already disqualifies a response from caching;
  // WordPress also mints a fresh X-WP-Nonce per authenticated response,
  // which is dropped here for the same reason.
  delete stored['x-wp-nonce'];
  const now = Date.now();
  proxyCache.set(key, {
    headers: stored,
    body,
    storedAt: now,
    expiresAt: now + ttl * 1000,
  });
}

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
 * Domain attributes are dropped so cookies are host-only for the app.
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

  // Micro-cache lookup. GET/HEAD only; the HTML surfaces (wp-admin,
  // wp-login) are per-user and never cached; an explicit client opt-out or
  // Authorization header also bypasses.
  const method = String(req.method ?? 'GET').toUpperCase();
  const clientCacheControl = String(req.headers['cache-control'] ?? '').toLowerCase();
  const bypassCache =
    !PROXY_CACHE_ENABLED ||
    (method !== 'GET' && method !== 'HEAD') ||
    Boolean(req.headers.authorization) ||
    clientCacheControl.includes('no-cache') ||
    clientCacheControl.includes('no-store') ||
    String(req.headers.pragma ?? '').toLowerCase().includes('no-cache') ||
    isLoginPage ||
    incoming.pathname === '/wp-admin' ||
    incoming.pathname.startsWith('/wp-admin/');
  const cacheKey = bypassCache ? null : proxyCacheKey(req, incoming);

  if (cacheKey) {
    const hit = proxyCache.get(cacheKey);
    if (hit && hit.expiresAt > Date.now()) {
      res.writeHead(200, {
        ...hit.headers,
        'Content-Length': hit.body.length,
        Age: Math.floor((Date.now() - hit.storedAt) / 1000),
        'X-Cache-Status': 'HIT',
      });
      res.end(method === 'HEAD' ? undefined : hit.body);
      return;
    }
    if (hit) proxyCache.delete(cacheKey); // Expired.
  }

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
        const ttl = cacheKey ? proxyCacheableTtl(upRes) : 0;
        const declaredLength = Number(upRes.headers['content-length'] ?? NaN);
        if (!ttl || (Number.isFinite(declaredLength) && declaredLength > PROXY_CACHE_MAX_BYTES)) {
          // SSE, chunked bodies and non-HTML responses pipe through unchanged.
          res.writeHead(upRes.statusCode ?? 502, upRes.statusMessage, {
            ...outHeaders,
            'X-Cache-Status': 'BYPASS',
          });
          upRes.pipe(res);
          return;
        }

        // Buffer (bounded) so the response can be replayed from the
        // micro-cache. Over-cap responses degrade to streaming mid-flight.
        const chunks = [];
        let size = 0;
        let flushed = false;
        upRes.on('data', (chunk) => {
          if (flushed) return;
          size += chunk.length;
          if (size > PROXY_CACHE_MAX_BYTES) {
            flushed = true;
            res.writeHead(upRes.statusCode ?? 502, upRes.statusMessage, {
              ...outHeaders,
              'X-Cache-Status': 'BYPASS',
            });
            for (const buffered of chunks) res.write(buffered);
            res.write(chunk);
            upRes.pipe(res);
            return;
          }
          chunks.push(chunk);
        });
        upRes.on('end', () => {
          if (flushed) return; // pipe() ends the response.
          const body = Buffer.concat(chunks);
          proxyCacheStore(cacheKey, ttl, outHeaders, body);
          res.writeHead(upRes.statusCode ?? 502, upRes.statusMessage, {
            ...outHeaders,
            'Content-Length': body.length,
            'X-Cache-Status': 'MISS',
          });
          res.end(method === 'HEAD' ? undefined : body);
        });
        upRes.on('error', () => res.destroy());
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

    // Only extensionless navigation paths may fall back to the SPA entry.
    // A file-like path that is missing (e.g. /assets/index-<hash>.css after
    // a stale or partial dist deploy) means the bundle is broken — serving
    // index.html for it answers text/html where the browser expected a
    // stylesheet/script, which strict MIME checking rejects with a
    // confusing console error. 404 it instead so the broken deploy is loud.
    const hasExtension = /\.[^/\\]+$/.test(pathname);

    let file = join(ROOT, pathname);
    try {
      const st = await stat(file);
      if (st.isDirectory()) file = join(file, 'index.html');
    } catch {
      if (hasExtension) {
        res.writeHead(404, {
          'Content-Type': 'text/plain',
          'Cache-Control': 'no-cache',
          ...SECURITY_HEADERS,
        });
        res.end('Not found');
        return;
      }
      // SPA fallback for client-side (hash) routes.
      file = join(ROOT, 'index.html');
    }

    const entry = await loadStaticFile(file);

    // Industry SPA caching contract: the entry HTML is never cached
    // (revalidated via ETag → 304); hashed bundle assets are immutable for
    // a year; unversioned root files (favicon) get a bounded day + ETag
    // because their URL cannot rotate. Split on both separators — win32's
    // normalize() uses backslashes, so a plain 'assets/' prefix check
    // silently downgrades the immutable contract when run on Windows.
    const isHashedAsset = pathname.split(/[\\/]/)[0] === 'assets';
    const cacheControl = file.endsWith('index.html')
      ? 'no-cache'
      : isHashedAsset
        ? 'public, max-age=31536000, immutable'
        : 'public, max-age=86400';

    const responseHeaders = {
      'Content-Type': MIME[extname(file)] ?? 'application/octet-stream',
      'Cache-Control': cacheControl,
      ETag: entry.etag,
      ...SECURITY_HEADERS,
    };
    if (entry.compressible) responseHeaders.Vary = 'Accept-Encoding';

    // Conditional request — revalidate without resending the body.
    if (etagMatches(req.headers['if-none-match'], entry.etag)) {
      res.writeHead(304, responseHeaders);
      res.end();
      return;
    }

    let body = entry.identity;
    const encoding = pickContentEncoding(req, entry);
    if (encoding) {
      body = compressedVariant(entry, encoding);
      responseHeaders['Content-Encoding'] = encoding;
    }
    responseHeaders['Content-Length'] = body.length;
    res.writeHead(200, responseHeaders);
    res.end(body);
  } catch {
    res.writeHead(404, {
      'Content-Type': 'text/plain',
      'Cache-Control': 'no-store',
      ...SECURITY_HEADERS,
    });
    res.end('Not found');
  }
});

server.on('error', (error) => {
  // Surface startup failures (EADDRINUSE, EACCES, bad PORT) with an
  // actionable line — PM2/Velocity only reports "process crashed" and
  // masks the real reason.
  console.error(
    `[nvoos-complete-console] failed to start on :${PORT}: ${error.code || 'ERR'} ${error.message}`,
  );
  if (error.code === 'EADDRINUSE') {
    console.error(
      '[nvoos-complete-console] port already in use — a stale app instance may still hold it; run `pm2 list` and stop duplicates.',
    );
  }
  process.exit(1);
});

server.listen(PORT, () => {
  console.log(`[nvoos-complete-console] serving dist/ on :${PORT}`);
  if (PROXY_TARGET) {
    console.log(
      `[nvoos-complete-console] proxying ${PROXY_PREFIXES.join(', ')} → ${PROXY_TARGET} (cookie auth enabled)`,
    );
    console.log(
      `[nvoos-complete-console] proxy micro-cache ${PROXY_CACHE_ENABLED ? `on (TTL cap ${PROXY_CACHE_TTL_CAP}s)` : 'off (NVOOS_PROXY_CACHE=0)'}`,
    );
  } else {
    console.log(
      '[nvoos-complete-console] no NVOOS_TARGET_SITE set — the SPA loads, but REST/auth calls have no backend (set it to your WordPress site URL)',
    );
  }
});
