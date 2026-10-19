#!/usr/bin/env node
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
 * first, because the HTML shell contains an inline Cloudflare challenge
 * script that a strict `script-src 'self'` policy can break. Apply it ONLY
 * to the static branch below (never to proxied WordPress responses —
 * wp-admin breaks under a global policy). Template:
 *
 * const CSP_REPORT_ONLY = {
 *   'Content-Security-Policy-Report-Only':
 *     "default-src 'self'; " +
 *     "connect-src 'self' https://nvoos.pro <media-worker-origin>; " +
 *     "script-src 'self' 'unsafe-inline'; " +
 *     'report-to csp-endpoint',
 * };
 */

function isProxyPath(pathname) {
  return PROXY_PREFIXES.some(
    (prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`),
  );
}

/**
 * Runtime login-preset injection (production / Velocity).
 *
 * The bundle bakes its login-preset env at BUILD time (vite.config.ts
 * `define` → `import.meta.env.NVOOS_*`); this server layer adds a RUNTIME
 * override: any of the keys below set in the app's runtime environment are
 * injected into the served HTML shell as `window.__NVOOS_RUNTIME__`, which
 * src/presets.tsx prefers over the baked values. Operators can switch login
 * pages or rotate credentials by changing runtime env + restarting PM2 — no
 * redeploy.
 *
 * Keep RUNTIME_ENV_KEYS in sync with src/env.d.ts and the
 * RuntimeEnvOverrides interface in src/presets.tsx. Only non-empty values
 * are injected, so an unset runtime key never clobbers a baked build-time
 * value. NVOOS_ASSISTANT_CREDENTIAL / NVOOS_WP_APP_PASSWORD /
 * NVOOS_WORKER_TOKEN are browser-visible by design (the connection screen
 * already stores them in localStorage) but are never logged.
 */
const RUNTIME_ENV_KEYS = [
  'NVOOS_HOME',
  'NVOOS_SITE_URL',
  'NVOOS_ASSISTANT_CREDENTIAL',
  'NVOOS_WP_USERNAME',
  'NVOOS_WP_APP_PASSWORD',
  'NVOOS_WORKER_URL',
  'NVOOS_WORKER_TOKEN',
  'NVOOS_AUTO_CONNECT',
];

/** Build the <script> that exposes the runtime env — '' when nothing is set. */
function buildRuntimeScript() {
  const runtime = {};
  for (const key of RUNTIME_ENV_KEYS) {
    const value = (process.env[key] ?? '').trim();
    if (value) runtime[key] = value;
  }
  if (Object.keys(runtime).length === 0) return '';
  // Escape '<' so an env value can never break out of the script tag.
  // (A future strict CSP must allow this inline script — the existing
  // Report-Only template already lists 'unsafe-inline'.)
  const json = JSON.stringify(runtime).replace(/</g, '\\u003c');
  return `<script>window.__NVOOS_RUNTIME__=${json};</script>`;
}

/** Inject the runtime script into the HTML shell, when there is one. */
function injectRuntimeEnv(html) {
  const script = buildRuntimeScript();
  if (!script) return html;
  return html.replace(/<head[^>]*>/i, (match) => `${match}${script}`);
}

/** Secret-free one-line summary of the active runtime preset for startup logs. */
function runtimeEnvSummary() {
  const parts = [];
  if (process.env.NVOOS_HOME) parts.push(`HOME=${process.env.NVOOS_HOME}`);
  if (process.env.NVOOS_SITE_URL) parts.push(`site=${process.env.NVOOS_SITE_URL}`);
  if (process.env.NVOOS_AUTO_CONNECT) parts.push('autoConnect=1');
  if (process.env.NVOOS_WORKER_URL) parts.push(`worker=${process.env.NVOOS_WORKER_URL}`);
  return parts.join(', ');
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

    let body = await readFile(file);
    // Login-preset runtime env: inject window.__NVOOS_RUNTIME__ into the HTML
    // shell (build-time baked values stay the fallback — see buildRuntimeScript).
    if (file.endsWith('index.html')) {
      body = Buffer.from(injectRuntimeEnv(body.toString('utf8')));
    }
    res.writeHead(200, {
      'Content-Type': MIME[extname(file)] ?? 'application/octet-stream',
      'Cache-Control': file.endsWith('index.html') ? 'no-cache' : 'public, max-age=31536000, immutable',
      ...SECURITY_HEADERS,
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
  const runtimeSummary = runtimeEnvSummary();
  if (runtimeSummary) {
    console.log(
      `[nvoos-pro-spa-standalone] runtime login env: ${runtimeSummary} (credentials omitted)`,
    );
  }
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
