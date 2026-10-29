// ---------------------------------------------------------------------------
// Proxy header transforms — pure helpers for scripts/serve.mjs (the BFF).
//
// Kept dependency-free and side-effect-free so the unit suite can pin the
// exact admin-proxy behaviour: same-origin Location rewriting (no backend
// origin leaks on login redirects), host-only auth cookies, and noindex on
// proxied admin responses.
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
 * Strip `Domain=` from Set-Cookie values so auth cookies land host-only on
 * the proxy origin instead of the backend domain (same-origin login flow).
 *
 * @param {string | string[] | undefined} setCookies Upstream Set-Cookie.
 * @returns {string | string[] | undefined} Host-only version.
 */
export function stripDomainFromSetCookie(setCookies) {
	if (!Array.isArray(setCookies)) {
		return setCookies;
	}
	return setCookies.map((cookie) => cookie.replace(/;\s*Domain=[^;]+/gi, ''));
}

/**
 * Rewrite an upstream Location header onto the proxy origin.
 *
 * WordPress redirects use absolute backend URLs (e.g. the login loop). The
 * storefront must keep those redirects same-origin, or the browser leaves
 * the storefront and the cookie handshake breaks. Only the scheme+host of
 * {@link targetSite} is swapped; the path/query stay untouched. Relative
 * locations and foreign origins pass through unchanged.
 *
 * @param {string | undefined} location Upstream Location value.
 * @param {string} targetSite Backend origin (NVOOS_TARGET_SITE).
 * @param {string} origin The client-facing origin to rewrite to.
 * @returns {string | undefined} Rewritten (or original) Location.
 */
export function rewriteLocation(location, targetSite, origin) {
	if (!location || !targetSite || !origin) {
		return location;
	}

	let targetHost;
	try {
		targetHost = new URL(targetSite).host.toLowerCase();
	} catch {
		return location;
	}

	// Protocol-relative: //backend/path → origin + /path.
	if (location.startsWith('//')) {
		const rest = location.slice(2);
		const prefix = targetHost;
		if (rest === prefix || rest.startsWith(`${prefix}/`) || rest.startsWith(`${prefix}:`)) {
			return origin + rest.slice(prefix.length);
		}
		return location;
	}

	// Absolute URL: swap the scheme+host, keep path/query/hash.
	if (/^https?:\/\//i.test(location)) {
		let parsed;
		try {
			parsed = new URL(location);
		} catch {
			return location;
		}
		if (parsed.host.toLowerCase() === targetHost) {
			return origin + parsed.pathname + parsed.search + parsed.hash;
		}
	}

	// Relative paths stay relative — the proxy serves them same-origin.
	return location;
}

/**
 * Admin-proxy response headers: same-origin Location + noindex.
 *
 * The backend dashboard must be reachable (login → dashboard flows) but
 * never indexed or followed into from search results.
 *
 * @param {Record<string, string | string[] | number | undefined>} headers Upstream response headers.
 * @param {{ targetSite: string, origin: string }} options Rewrite context.
 * @returns {Record<string, string | string[] | number | undefined>} Transformed headers.
 */
export function applyAdminResponseHeaders(headers, { targetSite, origin }) {
	const out = { ...headers };

	if (out.location !== undefined) {
		out.location = rewriteLocation(String(out.location), targetSite, origin);
	}

	const robots = out['x-robots-tag'] !== undefined ? String(out['x-robots-tag']) : '';
	out['x-robots-tag'] = robots ? `${robots}, noindex` : 'noindex, nofollow';

	return out;
}
