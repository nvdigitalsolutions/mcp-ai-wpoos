// ---------------------------------------------------------------------------
// Proxy header + body transforms — pure helpers for scripts/serve.mjs (BFF).
//
// Kept dependency-free and side-effect-free so the unit suite can pin the
// exact admin-proxy behaviour:
//
//   - same-origin login flow: wp-login.php bodies are re-scoped to the app
//     origin, backend Locations become relative and their redirect_to query
//     params are re-scoped (industry pattern: nginx proxy_redirect +
//     sub_filter with Accept-Encoding ""; console BFF precedent)
//   - host-only auth cookies — Domain attributes are stripped, never added
//     (OWASP Session Management: cookies must not be widened to a domain)
//   - spoof-safe forwarded headers — x-forwarded-proto/host/port are
//     overwritten from the real request, never echoed from the client
//     (http.dev guidance: client-supplied X-Forwarded-* is spoofable)
//   - noindex on proxied admin responses
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
 *   its own canonical URLs; the BFF re-scopes them on the way back).
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
 * the proxy origin instead of the backend domain (same-origin login flow).
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

/**
 * Re-scope `redirect_to` query values that point at the backend origin to
 * relative paths. WordPress bakes the absolute backend URL into login
 * redirects; left untouched, the post-login redirect leaves the proxy and
 * the host-only auth cookie is never sent (the broken handshake).
 *
 * @param {string} search Query string (leading `?` or empty).
 * @param {string} targetOrigin Backend origin (`https://nvoos.pro`).
 * @returns {string} Query string with backend redirect_to values re-scoped.
 */
function rescopeRedirectTo(search, targetOrigin) {
	if (!search) {
		return search;
	}
	return search.replace(/([?&]redirect_to=)([^&#]*)/gi, (match, prefix, raw) => {
		let decoded;
		try {
			decoded = decodeURIComponent(raw);
		} catch {
			return match;
		}
		// Normalise the scheme so `HTTPS://…` variants still match.
		decoded = decoded.replace(/^https?:\/\//i, (scheme) => scheme.toLowerCase());
		if (decoded === targetOrigin) {
			return prefix + encodeURIComponent('/');
		}
		if (decoded.startsWith(`${targetOrigin}/`)) {
			return prefix + encodeURIComponent(decoded.slice(targetOrigin.length));
		}
		return match;
	});
}

/**
 * Rewrite an upstream Location header onto the proxy origin — as a RELATIVE
 * path with the redirect_to param re-scoped.
 *
 * WordPress redirects use absolute backend URLs (the login loop). Relative
 * Locations keep the browser on the app origin regardless of how the
 * platform proxy terminates TLS (no dependence on the origin string), and
 * relative `redirect_to` values keep the post-login redirect inside the
 * proxy so the host-only auth cookie round-trips. Foreign origins pass
 * through untouched (the backend's own wp_safe_redirect gate rejects them).
 *
 * @param {string | undefined} location Upstream Location value.
 * @param {string} targetSite Backend origin (NVOOS_TARGET_SITE).
 * @returns {string | undefined} Relative, re-scoped Location (or original).
 */
export function rewriteLocation(location, targetSite) {
	if (!location || !targetSite) {
		return location;
	}

	let target;
	try {
		target = new URL(targetSite);
	} catch {
		return location;
	}
	const targetHost = target.host.toLowerCase();
	const targetOrigin = target.origin;

	// Protocol-relative: //backend/path → relative path.
	if (location.startsWith('//')) {
		const rest = location.slice(2);
		if (rest === targetHost || rest.startsWith(`${targetHost}/`) || rest.startsWith(`${targetHost}:`)) {
			return rescopeRedirectTo(rest.slice(targetHost.length), targetOrigin);
		}
		return location;
	}

	// Absolute URL: strip the backend scheme+host, keep path/query/hash.
	if (/^https?:\/\//i.test(location)) {
		let parsed;
		try {
			parsed = new URL(location);
		} catch {
			return location;
		}
		if (parsed.host.toLowerCase() === targetHost) {
			return parsed.pathname + rescopeRedirectTo(parsed.search, targetOrigin) + parsed.hash;
		}
	}

	// Relative paths stay relative — the proxy serves them same-origin.
	return location;
}

/**
 * Re-scope the wp-login.php page to the app origin.
 *
 * WordPress renders the login form with an ABSOLUTE action (site_url) and
 * an absolute redirect_to value. If the browser follows them, the login
 * POST and the post-login redirect land on the WordPress origin — the auth
 * cookies are set for the WordPress host and the app origin never sees the
 * session. On localhost this is masked (cookies are scoped by host, not
 * port), but with different hosts in production it breaks cookie auth
 * entirely. Rewrite:
 *
 *   1. Every absolute URL ending in /wp-login.php (form actions,
 *      lost-password and register links) becomes an app-origin relative
 *      path, so the login POST goes through this proxy.
 *   2. Absolute redirect_to values are re-scoped to relative paths, so the
 *      post-login redirect comes back through the proxy (where the Location
 *      rewrite keeps it on the app origin).
 *
 * Callers must guarantee an identity (uncompressed) text/html body — see
 * serve.mjs (Accept-Encoding strip + content-type/size gates).
 *
 * @param {string} html Upstream login-page HTML.
 * @returns {string} Rewritten HTML.
 */
export function rewriteLoginPageHtml(html) {
	return html
		.replace(
			/(["'])https?:\/\/[^"']*?(\/[^"']*?wp-login\.php(?=[?"'\s]))/gi,
			'$1$2'
		)
		.replace(
			/(\bname=["']redirect_to["']\s+value=["'])https?:\/\/[^"']*?(\/.*?)?(["'])/gi,
			'$1$2$3'
		);
}

/**
 * Admin-proxy response headers: same-origin (relative) Location + noindex.
 *
 * The backend dashboard must be reachable (login → dashboard flows) but
 * never indexed or followed into from search results.
 *
 * @param {Record<string, string | string[] | number | undefined>} headers Upstream response headers.
 * @param {{ targetSite: string }} options Rewrite context.
 * @returns {Record<string, string | string[] | number | undefined>} Transformed headers.
 */
export function applyAdminResponseHeaders(headers, { targetSite }) {
	const out = { ...headers };

	if (out.location !== undefined) {
		out.location = rewriteLocation(String(out.location), targetSite);
	}

	const robots = out['x-robots-tag'] !== undefined ? String(out['x-robots-tag']) : '';
	out['x-robots-tag'] = robots ? `${robots}, noindex` : 'noindex, nofollow';

	return out;
}
