/**
 * Connected-app allowlist and path rules for the `/connected/<key>/*` proxy
 * in `scripts/serve.mjs` (Proposal 090-p W6).
 *
 * The console islands (Content Graph, Docs Hub, Media Studio, Document
 * Editor) fetch their REST base from the browser. A remote product is a
 * different origin, so either the product must send CORS headers with
 * credentials, or the console needs a same-origin proxy. This module is the
 * proxy's *policy*: which apps may be reached and which paths may be asked
 * for. It has no I/O so the rules can be unit-tested without a server.
 *
 * Threat model — the proxy must never become an open relay:
 *   1. A request can only name a KEY, never a host. Every upstream origin is
 *      resolved here from operator config, so a client cannot point the
 *      proxy at an arbitrary host (SSRF).
 *   2. The path after the key is joined onto the configured base. Absolute
 *      URLs, protocol-relative paths, traversal segments, encoded separators
 *      and backslashes are rejected before the join, because upstream
 *      servers disagree about how they normalise those (a `%2f` may be read
 *      as a separator by one server and as data by another).
 *   3. Credentials flow one way only: the browser's cookies, WordPress
 *      nonce and Authorization header are dropped, and a configured
 *      per-app token is attached server-side. Response `Set-Cookie` is
 *      stripped so a product cannot set a cookie on the console origin.
 */

/**
 * The four product keys the console knows how to mount. Anything else is
 * refused — deliberately not "any key present in the env var", so a typo in
 * config cannot silently expose a new surface.
 */
export const CONNECTED_KEYS = Object.freeze([
  'contentGraph',
  'docsHub',
  'mediaStudio',
  'documentEditor',
]);

/** Upper bound for a product-opted public cache TTL, in seconds. */
export const CONNECTED_TTL_CAP = 900;

/** A percent-encoded dot or path separator — see normaliseConnectedPath(). */
const ENCODED_PATH_SYNTAX = /%2e|%2f|%5c/i;

/** Hop-by-hop headers Node manages itself. */
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

/**
 * Request headers that must never reach a connected product.
 *
 * `cookie` + `authorization` + `x-wp-nonce` are console-origin credentials.
 * `origin` + `referer` would name the console origin to a third party, and a
 * product that validates them as a CSRF check would reject the call.
 */
const STRIPPED_REQUEST_HEADERS = new Set([...HOP_BY_HOP, ...[
  'cookie',
  'authorization',
  'origin',
  'referer',
  'host',
  'x-wp-nonce',
  'x-wp-mcp-ai-guest',
  'forwarded',
  'x-forwarded-for',
  'x-forwarded-host',
  'x-forwarded-proto',
  'x-real-ip',
]]);

/**
 * Response headers that must not reach the browser.
 *
 * `set-cookie` would land on the console origin. `strict-transport-security`,
 * `alt-svc` and the framing/CSP headers are assertions about the PRODUCT's
 * origin that would be misapplied to ours.
 */
const STRIPPED_RESPONSE_HEADERS = new Set([...HOP_BY_HOP, ...[
  'set-cookie',
  'strict-transport-security',
  'alt-svc',
  'content-security-policy',
  'content-security-policy-report-only',
  'x-frame-options',
  'clear-site-data',
]]);

/**
 * Parse `NVOOS_CONNECTED_APPS` into the allowlist.
 *
 * Shape:
 *   {"contentGraph":{"base":"https://cg.example.com/api","token":"…","cacheTtl":60}}
 *
 * Unknown keys are dropped with a warning rather than throwing: a typo in
 * one entry must not take the whole console down, and the allowlist is what
 * protects the proxy, so dropping is the safe direction. A malformed base
 * drops that entry for the same reason.
 *
 * @param {string|undefined} raw Raw env value.
 * @returns {Map<string, {key: string, base: string, token: string, cacheTtl: number}>}
 */
export function parseConnectedApps(raw) {
  /** @type {Map<string, {key: string, base: string, token: string, cacheTtl: number}>} */
  const apps = new Map();
  if (typeof raw !== 'string' || raw.trim() === '') {
    return apps;
  }

  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (error) {
    console.warn(
      `[nvoos-complete-console] NVOOS_CONNECTED_APPS is not valid JSON — connected-app proxy disabled (${error.message})`,
    );
    return apps;
  }

  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    console.warn(
      '[nvoos-complete-console] NVOOS_CONNECTED_APPS must be an object — connected-app proxy disabled',
    );
    return apps;
  }

  for (const [key, value] of Object.entries(parsed)) {
    if (!CONNECTED_KEYS.includes(key)) {
      console.warn(
        `[nvoos-complete-console] NVOOS_CONNECTED_APPS key "${key}" is not a known connected app — ignored`,
      );
      continue;
    }
    if (!value || typeof value !== 'object') {
      console.warn(
        `[nvoos-complete-console] NVOOS_CONNECTED_APPS["${key}"] must be an object — ignored`,
      );
      continue;
    }

    let base;
    try {
      base = new URL(String(value.base ?? ''));
    } catch {
      console.warn(
        `[nvoos-complete-console] NVOOS_CONNECTED_APPS["${key}"].base is not an absolute URL — ignored`,
      );
      continue;
    }
    if (base.protocol !== 'https:' && base.protocol !== 'http:') {
      console.warn(
        `[nvoos-complete-console] NVOOS_CONNECTED_APPS["${key}"].base must be http(s) — ignored`,
      );
      continue;
    }

    const ttl = Number(value.cacheTtl ?? 0);
    apps.set(key, {
      key,
      base: base.origin + base.pathname.replace(/\/+$/, ''),
      token: typeof value.token === 'string' ? value.token : '',
      cacheTtl:
        Number.isFinite(ttl) && ttl > 0 ? Math.min(Math.floor(ttl), CONNECTED_TTL_CAP) : 0,
    });
  }

  return apps;
}

/**
 * Normalise the path that follows `/connected/<key>`.
 *
 * Returns `'/'` for the bare key, the decoded path otherwise, or `null` when
 * the path must be refused. The caller answers `400` on `null` — refusing
 * loudly beats letting the upstream's parser decide what `..%2f` means.
 *
 * @param {string|undefined} rest Path after the key, e.g. `/api/v1/pages`.
 * @returns {string|null}
 */
export function normaliseConnectedPath(rest) {
  const raw = typeof rest === 'string' && rest.length > 0 ? rest : '/';

  // A percent-encoded dot or separator is ambiguous: one decode turns it into
  // real path syntax, and upstream servers disagree about whether they decode
  // before or after routing. Refuse the raw form, then refuse it again after
  // decoding to catch a double-encoded value.
  if (ENCODED_PATH_SYNTAX.test(raw)) {
    return null;
  }

  let decoded;
  try {
    decoded = decodeURIComponent(raw);
  } catch {
    return null; // Malformed percent-encoding.
  }

  if (decoded.includes('\u0000') || decoded.includes('\\')) {
    return null;
  }
  if (!decoded.startsWith('/') || decoded.startsWith('//')) {
    return null; // Not a path, or protocol-relative (would swap the host).
  }
  if (ENCODED_PATH_SYNTAX.test(decoded)) {
    return null;
  }

  for (const segment of decoded.split('/')) {
    if (segment === '.' || segment === '..') {
      return null;
    }
  }

  return decoded;
}

/**
 * Build the upstream request headers.
 *
 * @param {Record<string, string|string[]|undefined>} incoming Browser headers.
 * @param {{base: string, token: string}} app Allowlist entry.
 * @returns {Record<string, string|string[]|undefined>}
 */
export function buildConnectedRequestHeaders(incoming, app) {
  /** @type {Record<string, string|string[]|undefined>} */
  const headers = {};
  for (const [name, value] of Object.entries(incoming ?? {})) {
    const lower = name.toLowerCase();
    if (STRIPPED_REQUEST_HEADERS.has(lower)) {
      continue;
    }
    headers[lower] = value;
  }

  headers.host = new URL(app.base).host;
  if (app.token) {
    headers.authorization = `Bearer ${app.token}`;
  }

  return headers;
}

/**
 * Build the response headers sent back to the browser.
 *
 * Default is `no-store`: a connected product's payload is as session-bound as
 * anything else proxied here. A product opts into shared caching explicitly
 * with `cacheTtl`, which is capped so a bad config cannot pin a stale body at
 * the CDN indefinitely.
 *
 * @param {Record<string, string|string[]|undefined>} upstream Upstream headers.
 * @param {{cacheTtl: number, key: string}} app Allowlist entry.
 * @returns {Record<string, string|string[]|undefined>}
 */
export function buildConnectedResponseHeaders(upstream, app) {
  /** @type {Record<string, string|string[]|undefined>} */
  const headers = {};
  for (const [name, value] of Object.entries(upstream ?? {})) {
    const lower = name.toLowerCase();
    if (STRIPPED_RESPONSE_HEADERS.has(lower)) {
      continue;
    }
    headers[lower] = value;
  }

  headers['cache-control'] =
    app.cacheTtl > 0 ? `public, max-age=${app.cacheTtl}` : 'no-store';
  headers['x-nvoos-connected'] = app.key;

  return headers;
}
