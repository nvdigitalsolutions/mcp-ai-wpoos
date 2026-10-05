/**
 * Tool namespacing for the gateway.
 *
 * The MCP spec does not define aggregation semantics, so a gateway owning
 * several upstream sites must keep tool names unique. Industry practice
 * (AgentGateway, mcp-proxy, Gravitee) prefixes the source identifier:
 * `tools/list` returns `site-a.create_post`, `tools/call` reverse-maps it.
 * Prefixing by construction makes collisions impossible.
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
		name: `${ slug }.${ tool.name }`,
		_meta: { ...( tool._meta || {} ), gateway: { site: slug } },
	};
}

/**
 * Split a possibly-prefixed tool name.
 *
 * @param {string} name Tool name as sent by the client.
 * @return {{slug: string, tool: string}|null} Slug + bare tool name, or null
 *                                             when the name carries no dot.
 */
export function splitToolName( name ) {
	const idx = String( name ).indexOf( '.' );
	if ( idx <= 0 || idx === name.length - 1 ) {
		return null;
	}
	return { slug: name.slice( 0, idx ), tool: name.slice( idx + 1 ) };
}

/**
 * Whether a tool name is prefixed with a known site slug.
 *
 * @param {string} name  Tool name.
 * @param {Set<string>} slugs Known slugs.
 * @return {boolean}
 */
export function isNamespacedFor( name, slugs ) {
	const parts = splitToolName( name );
	return null !== parts && slugs.has( parts.slug );
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
