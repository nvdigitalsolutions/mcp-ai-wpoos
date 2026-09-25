/**
 * Environment configuration for the mcp-wordpress gateway.
 *
 * Fails closed: a missing or short MCP_GATEWAY_TOKEN marks the config
 * invalid and the process refuses to start.
 */

import { timingSafeEqual } from 'node:crypto';

const DEFAULT_UPSTREAM = 'http://127.0.0.1:8000/mcp';

/**
 * Load and validate configuration from the environment.
 *
 * @returns {object} Config object plus an `errors` array of fatal problems.
 */
export function loadConfig( env = process.env ) {
	const token = String( env.MCP_GATEWAY_TOKEN ?? '' );
	const previousToken = String( env.MCP_GATEWAY_TOKEN_PREVIOUS ?? '' );
	const errors = [];

	if ( token.length < 32 ) {
		errors.push( 'MCP_GATEWAY_TOKEN must be at least 32 characters.' );
	}

	return {
		valid: errors.length === 0,
		errors,
		token,
		previousToken,
		port: Number( env.PORT ?? 3000 ),
		internalPort: Number( env.GATEWAY_INTERNAL_PORT ?? 8000 ),
		upstreamUrl: String( env.GATEWAY_UPSTREAM_URL ?? DEFAULT_UPSTREAM ),
		allow: parseCsvList( env.MCP_TOOLS_ALLOW ?? '*' ),
		deny: parseCsvList( env.MCP_TOOLS_DENY ?? '' ),
		noSupervisor: env.GATEWAY_NO_SUPERVISOR === '1',
		logLevel: String( env.LOG_LEVEL ?? 'info' ),
	};
}

/**
 * Timing-safe comparison of a presented token against the configured
 * primary and (rotation-window) previous tokens.
 *
 * @param {object} config      Loaded config.
 * @param {string} presented   Raw header value.
 * @returns {boolean} True when the token matches either accepted value.
 */
export function authorize( config, presented ) {
	if ( ! config.valid || typeof presented !== 'string' || presented.length === 0 ) {
		return false;
	}

	const candidates = [ config.token, config.previousToken ].filter( ( value ) => value.length > 0 );

	return candidates.some( ( candidate ) => {
		const a = Buffer.from( candidate );
		const b = Buffer.from( presented );
		return a.length === b.length && timingSafeEqual( a, b );
	} );
}

/**
 * Splits a comma-separated policy string into trimmed, non-empty patterns.
 *
 * @param {string} value Raw env value.
 * @returns {string[]} Patterns.
 */
export function parseCsvList( value ) {
	return String( value )
		.split( ',' )
		.map( ( entry ) => entry.trim() )
		.filter( ( entry ) => entry.length > 0 );
}

export { DEFAULT_UPSTREAM };
