/**
 * Tool namespacing for the gateway.
 *
 * The MCP spec does not define aggregation semantics, so a gateway owning
 * several upstream sites must keep tool names unique. Industry practice
 * (AgentGateway, mcp-proxy, Gravitee) prefixes the source identifier:
 * `tools/list` returns `site-a_create_post`, `tools/call` reverse-maps it.
 * Prefixing by construction makes collisions impossible.
 *
 * The separator is `_` (underscore) because the MCP tool-name grammar is
 * `^[a-zA-Z0-9_-]{1,64}$` — dots are not permitted. OpenAI connectors
 * (ChatGPT/Codex) normalize illegal names, which silently renamed the old
 * `site-a.create_post` form to `site-a_create_post` client-side (live,
 * 2026-11-01). Legacy dot-prefixed names are still accepted on `tools/call`
 * for clients holding pre-0.2.1 listings.
 */

/**
 * Prefix a tool definition from one upstream site.
 *
 * @param {string} slug Site slug.
 * @param {object} tool Upstream tool object (MCP Tool shape).
 * @return {object} Tool with the namespaced name and provenance metadata.
 */
export function prefixTool( slug, tool ) {
	return {
		...tool,
		name: `${ slug }_${ tool.name }`,
		_meta: { ...( tool._meta || {} ), gateway: { site: slug } },
	};
}

/**
 * Split a possibly-prefixed tool name.
 *
 * Accepts the current underscore form (`slug_tool`) and the legacy dot form
 * (`slug.tool`, pre-0.2.1). With a `slugs` set the slug must be configured —
 * the longest configured slug wins so `demo2_x` never parses as site `demo`
 * + tool `2_x`.
 *
 * @param {string}       name  Tool name as sent by the client.
 * @param {Set<string>}  [slugs] Known site slugs (undefined = accept any
 *                              dot prefix; legacy callers).
 * @return {{slug: string, tool: string}|null} Slug + bare tool name, or null
 *                                             when the name carries no valid
 *                                             prefix.
 */
export function splitToolName( name, slugs ) {
	const str = String( name );

	// Legacy dot form — kept callable for clients with cached listings.
	const idx = str.indexOf( '.' );
	if ( idx > 0 && idx < str.length - 1 ) {
		const slug = str.slice( 0, idx );
		if ( ! slugs || slugs.has( slug ) ) {
			return { slug, tool: str.slice( idx + 1 ) };
		}
	}

	// Current underscore form: longest configured slug prefix wins.
	if ( slugs ) {
		let best = null;
		for ( const slug of slugs ) {
			const prefix = `${ slug }_`;
			if ( str.startsWith( prefix ) && ( ! best || slug.length > best.slug.length ) ) {
				best = { slug, tool: str.slice( prefix.length ) };
			}
		}
		if ( best ) {
			return best;
		}
	}

	return null;
}

/**
 * Whether a tool name is prefixed with a known site slug.
 *
 * @param {string} name  Tool name.
 * @param {Set<string>} slugs Known slugs.
 * @return {boolean}
 */
export function isNamespacedFor( name, slugs ) {
	return null !== splitToolName( name, slugs );
}

/**
 * Confirm the configured site slugs are unique (a duplicate slug would make
 * tool attribution ambiguous). The config parser guarantees uniqueness
 * already; this is the defensive re-check for direct API users.
 *
 * @param {string[]} slugs Slug list.
 * @return {string[]} Slugs, unchanged.
 * @throws {Error} On duplicates.
 */
export function assertUniqueSlugs( slugs ) {
	const seen = new Set();
	for ( const slug of slugs ) {
		if ( seen.has( slug ) ) {
			throw new Error( `[namespacer] duplicate site slug "${ slug }".` );
		}
		seen.add( slug );
	}
	return slugs;
}
