// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Environment parsing for the QuickBooks Online MCP server.
 *
 * Fails closed: a missing inbound token aborts boot rather than serving an
 * unauthenticated MCP endpoint.
 */

/**
 * Read a required environment variable.
 *
 * @param {string} name  Variable name.
 * @return {string} Trimmed value.
 * @throws {Error} When the variable is empty.
 */
function required( name ) {
	const value = ( process.env[ name ] || '' ).trim();

	if ( '' === value ) {
		throw new Error( `${ name } is required.` );
	}

	return value;
}

/**
 * Read an optional environment variable with a default.
 *
 * @param {string} name         Variable name.
 * @param {string} defaultValue Fallback value.
 * @return {string} Trimmed value or the default.
 */
function optional( name, defaultValue ) {
	const value = ( process.env[ name ] || '' ).trim();
	return '' === value ? defaultValue : value;
}

/**
 * Build the runtime configuration.
 *
 * @return {Object} Configuration object.
 */
export function loadConfig() {
	const environment = optional( 'QBO_ENVIRONMENT', 'production' ).toLowerCase();

	if ( ! [ 'sandbox', 'production' ].includes( environment ) ) {
		throw new Error( 'QBO_ENVIRONMENT must be "sandbox" or "production".' );
	}

	const allowAnonymous = '1' === optional( 'QBO_MCP_ALLOW_ANONYMOUS', '' );
	const inboundToken = optional( 'QBO_MCP_TOKEN', '' );

	if ( '' === inboundToken && ! allowAnonymous ) {
		throw new Error(
			'QBO_MCP_TOKEN is required. Set it to the bearer token this server should accept from callers, or set QBO_MCP_ALLOW_ANONYMOUS=1 to run without authentication (never on a public host).'
		);
	}

	return {
		clientId: required( 'QBO_CLIENT_ID' ),
		clientSecret: required( 'QBO_CLIENT_SECRET' ),
		realmId: required( 'QBO_REALM_ID' ),
		refreshToken: required( 'QBO_REFRESH_TOKEN' ),
		environment,
		baseUrl:
			'sandbox' === environment
				? 'https://sandbox-quickbooks.api.intuit.com/v3'
				: 'https://quickbooks.api.intuit.com/v3',
		stateFile: optional( 'QBO_STATE_FILE', '.qbo-state.json' ),
		// Overridable so tests can point at a local fake; never set this in production.
		tokenUrl: optional( 'QBO_TOKEN_URL', 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' ),
		inboundToken,
		allowAnonymous,
		port: Number( optional( 'PORT', '8787' ) ),
		serverName: optional( 'QBO_MCP_NAME', 'quickbooks-online-mcp' ),
		serverVersion: '0.1.0',
	};
}
