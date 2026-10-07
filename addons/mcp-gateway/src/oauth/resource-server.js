/**
 * OAuth 2.1 resource-server contract for the gateway (RFC 9728).
 *
 * The gateway is the protected resource; the authorization server (Auth0
 * tenant, per the platform convention) is advertised only when
 * GATEWAY_OAUTH_ISSUER is configured — everything here stays inert
 * otherwise, so static-key deployments are unaffected.
 *
 * Scope model (Phase 1): `site:<slug>` scopes grant access to one bound
 * site each (mirroring GATEWAY_PUBLIC_KEYS key → slugs semantics); the
 * read-only `site:read` scope is advertised for future fine-grained
 * enforcement.
 */

/** Shared read scope advertised in metadata (future read/write split). */
const SCOPE_SITE_READ = 'site:read';

/**
 * Metadata document URL for a resource identifier (RFC 9728 §3): the
 * well-known suffix inserts between the host and any path component.
 *
 * @param {string} resource Resource identifier.
 * @return {string} Absolute metadata URL.
 */
export function resourceMetadataUrl( resource ) {
	const parsed = new URL( resource );
	const base = `${ parsed.protocol }//${ parsed.host }`;
	const path = parsed.pathname.replace( /\/$/, '' );
	return path ? `${ base }/.well-known/oauth-protected-resource${ path }` : `${ base }/.well-known/oauth-protected-resource`;
}

/**
 * Path-insertion variant of the metadata URL for the /mcp endpoint — some
 * clients (and ChatGPT) resolve the resource as the full endpoint URL and
 * fetch the document from the inserted path.
 *
 * @param {string} resource Resource identifier.
 * @return {string} Metadata URL for the /mcp variant.
 */
export function mcpMetadataUrl( resource ) {
	const parsed = new URL( resource );
	return `${ parsed.protocol }//${ parsed.host }/.well-known/oauth-protected-resource/mcp`;
}

/**
 * The scopes this resource advertises: the shared read scope plus one
 * `site:<slug>` scope per configured site.
 *
 * @param {object} cfg Gateway config.
 * @return {string[]}
 */
export function supportedScopes( cfg ) {
	return [ SCOPE_SITE_READ, ...Array.from( cfg.sites.keys() ).map( ( slug ) => `site:${ slug }` ) ];
}

/**
 * Map token scopes to the site slugs they grant.
 *
 * @param {string[]} scopes Token scopes.
 * @param {object}   cfg    Gateway config.
 * @return {string[]} Bound slugs (configured sites only).
 */
export function sitesFromScopes( scopes, cfg ) {
	const bound = new Set();
	for ( const scope of scopes ) {
		if ( ! scope.startsWith( 'site:' ) ) {
			continue;
		}
		const slug = scope.slice( 'site:'.length );
		if ( cfg.sites.has( slug ) ) {
			bound.add( slug );
		}
	}
	return Array.from( bound );
}

/**
 * Build the RFC 9728 protected-resource metadata document.
 *
 * @param {object} cfg Gateway config.
 * @param {string} [resource] Override the `resource` identifier (path variant).
 * @return {object} Metadata document.
 */
export function buildProtectedResourceDocument( cfg, resource = cfg.oauth.resource ) {
	return {
		resource,
		authorization_servers: [ cfg.oauth.issuer ],
		scopes_supported: supportedScopes( cfg ),
		bearer_methods_supported: [ 'header' ],
		resource_name: 'NV oOS MCP Gateway',
		resource_documentation: 'https://mcp.nvoos.pro/',
	};
}

/**
 * Build the WWW-Authenticate challenge value (RFC 9728 §5.1 + RFC 6750).
 *
 * The supported-scope list is included by default (the site-side contract
 * does the same) so clients can request the right scopes up front.
 *
 * @param {object} cfg         Gateway config.
 * @param {string} [error]     Optional RFC 6750 error code (e.g. insufficient_scope).
 * @param {string} [scope]     Required-scope list (defaults to all supported scopes).
 * @return {string} Challenge value.
 */
export function buildWwwAuthenticate( cfg, error = '', scope = '' ) {
	const parts = [ 'Bearer', `resource_metadata="${ resourceMetadataUrl( cfg.oauth.resource ) }"` ];
	if ( error ) {
		parts.push( `error="${ error }"` );
	}
	const scopeValue = scope || supportedScopes( cfg ).join( ' ' );
	if ( scopeValue ) {
		parts.push( `scope="${ scopeValue }"` );
	}
	return parts.join( ', ' );
}

/**
 * Whether OAuth is active for this gateway config (undefined-safe).
 *
 * @param {object} cfg Gateway config.
 * @return {boolean}
 */
export function oauthEnabled( cfg ) {
	return Boolean( cfg && cfg.oauth && cfg.oauth.enabled );
}
