/**
 * Upstream NV oOS site client.
 *
 * Each bound site already exposes a streamable-HTTP MCP endpoint at
 * /wp-json/mcp-ai/v1/mcp (verified 2026-10-05: GET discovery + JSON-RPC
 * POST, MCP protocol 2026-07-28). This client posts JSON-RPC payloads with
 * the site's Fleet Operator bearer token. Tokens are used header-only and
 * are never logged or returned.
 */

/**
 * POST a JSON-RPC payload to one upstream site.
 *
 * @param {object} site     Site config: `{ slug, url, token }`.
 * @param {object} payload  JSON-RPC request/notification object.
 * @param {number} timeoutMs Timeout budget.
 * @return {Promise<object>} `{ ok, status, data }` — `data` is the parsed
 *                           JSON body (or null when empty), `ok` is false on
 *                           transport failure or non-2xx status.
 */
export async function callUpstream( site, payload, timeoutMs = 20000 ) {
	const started = Date.now();
	try {
		const res = await fetch( site.url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				Authorization: `Bearer ${ site.token }`,
			},
			body: JSON.stringify( payload ),
			signal: AbortSignal.timeout( timeoutMs ),
		} );

		const text = await res.text();
		let data = null;
		if ( text.trim() ) {
			try {
				data = JSON.parse( text );
			} catch {
				return {
					ok: false,
					status: res.status,
					error: `non-json upstream response (${ res.status })`,
					ms: Date.now() - started,
				};
			}
		}
		return {
			ok: res.ok,
			status: res.status,
			data,
			ms: Date.now() - started,
		};
	} catch ( err ) {
		return {
			ok: false,
			status: 0,
			error: err.name === 'TimeoutError' ? 'upstream timeout' : `upstream unreachable: ${ err.message }`,
			ms: Date.now() - started,
		};
	}
}
