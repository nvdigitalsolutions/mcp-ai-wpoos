// ---------------------------------------------------------------------------
// Proxy header + body transforms — pure helpers for scripts/serve.mjs (BFF).
//
// Kept dependency-free and side-effect-free so the unit suite can pin the
// exact proxy behaviour:
//
//   - host-only auth cookies — Domain attributes are stripped, never added
//     (OWASP Session Management: cookies must not be widened to a domain).
//     The commerce auth flow (POST /api/auth/login) sets WordPress auth
//     cookies through this proxy, so the session must land on the app
//     origin.
//   - spoof-safe forwarded headers — x-forwarded-proto/host/port are
//     overwritten from the real request, never echoed from the client
//     (http.dev guidance: client-supplied X-Forwarded-* is spoofable)
//
// There is deliberately NO admin/login-page surface here: the storefront
// proxies only the commerce contract (/api/*) and uploads (/media/*) —
// wp-admin and wp-login.php are not proxied at all (2026-10-29 decision).
// ---------------------------------------------------------------------------

/** First value of a possibly-comma-joined forwarded header. */
function firstForwarded(value) {
	return value ? String(value).split(',')[0].trim() : '';
}

/**
 * The origin (scheme + host) the client used for THIS request.
 *
 * Prefers x-forwarded-proto/host (the platform proxy terminates TLS), then
 * falls back to the socket + Host header (local dev, direct listeners).
 *
 * @param {import('node:http').IncomingMessage} req Request.
 * @returns {string} e.g. `https://astro.nvoos.cloud`.
 */
export function requestOrigin(req) {
	const host = firstForwarded(req.headers['x-forwarded-host']) || req.headers.host || 'localhost';
	const proto =
		firstForwarded(req.headers['x-forwarded-proto']) ||
		(req.socket && req.socket.encrypted ? 'https' : 'http');
	return `${proto}://${host}`;
}

/**
 * Hop-by-hop headers Node's HTTP client/server manages itself — passing
 * them through would conflict with its own framing/connection handling.
 */
export const HOP_BY_HOP = new Set([
	'connection',
	'keep-alive',
	'proxy-authenticate',
	'proxy-authorization',
	'proxy-connection',
	'te',
	'trailer',
	'transfer-encoding',
	'upgrade',
]);

/**
 * Build the header set sent to the WordPress upstream.
 *
 * - The client Host is replaced by the upstream host (the backend renders
 *   its own canonical URLs).
 * - `origin` and `referer` are dropped — the app origin must not leak into
 *   backend logs.
 * - x-forwarded-proto/host/port are OVERWRITTEN from the real request
 *   origin (client-supplied copies are spoofable and could poison the
 *   backend's redirect/canonical logic). x-forwarded-for passes through:
 *   the platform proxy already prepended the real client chain.
 *
 * @param {Record<string, string | string[] | undefined>} headers Client request headers.
 * @param {{ targetHost: string, origin: string }} options Upstream host + client-facing origin.
 * @returns {Record<string, string | string[] | undefined>} Upstream-safe headers.
 */
export function buildUpstreamHeaders(headers, { targetHost, origin }) {
	const out = {};
	for (const [key, value] of Object.entries(headers)) {
		const lower = key.toLowerCase();
		if (HOP_BY_HOP.has(lower)) {
			continue;
		}
		if (lower === 'host' || lower === 'origin' || lower === 'referer') {
			continue;
		}
		if (lower === 'x-forwarded-proto' || lower === 'x-forwarded-host' || lower === 'x-forwarded-port') {
			continue;
		}
		out[key] = value;
	}
	out.host = targetHost;

	try {
		const parsed = new URL(origin);
		out['x-forwarded-proto'] = parsed.protocol.replace(/:$/, '');
		out['x-forwarded-host'] = parsed.host;
		if (parsed.port) {
			out['x-forwarded-port'] = parsed.port;
		}
	} catch {
		// Unparseable origin — leave the forwarded headers unset.
	}

	return out;
}

/**
 * Strip `Domain=` from Set-Cookie values so auth cookies land host-only on
 * the app origin instead of the backend domain (same-origin REST auth flow).
 *
 * @param {string | string[] | undefined} setCookies Upstream Set-Cookie.
 * @returns {string | string[] | undefined} Host-only version.
 */
export function stripDomainFromSetCookie(setCookies) {
	if (Array.isArray(setCookies)) {
		return setCookies.map((cookie) => cookie.replace(/;\s*Domain=[^;]+/gi, ''));
	}
	if (typeof setCookies === 'string') {
		return setCookies.replace(/;\s*Domain=[^;]+/gi, '');
	}
	return setCookies;
}
