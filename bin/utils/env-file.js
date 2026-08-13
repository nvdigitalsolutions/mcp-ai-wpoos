#!/usr/bin/env node
/**
 * Shared dotenv-style env-file support for the bin/ MCP tooling.
 *
 * Lets secrets (tokens, passwords) live in ~/.nvoos-bridge.env instead of
 * Zed's settings.json. Process env always wins over the file, so per-project
 * overrides still work.
 */

'use strict';

const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

/**
 * Parse a minimal dotenv-style file: KEY=value lines, # comments, blank lines
 * skipped, surrounding single/double quotes stripped. No interpolation.
 *
 * @param {string} content  File contents.
 * @returns {object} Map of key → value.
 */
function parseEnvFile( content ) {
	const out = {};
	for ( const line of String( content ).split( /\r?\n/ ) ) {
		const trimmed = line.trim();
		if ( ! trimmed || trimmed.startsWith( '#' ) ) {
			continue;
		}
		const eq = trimmed.indexOf( '=' );
		if ( -1 === eq ) {
			continue;
		}
		const key = trimmed.slice( 0, eq ).trim();
		let value = trimmed.slice( eq + 1 ).trim();
		if (
			( value.startsWith( '"' ) && value.endsWith( '"' ) ) ||
			( value.startsWith( "'" ) && value.endsWith( "'" ) )
		) {
			value = value.slice( 1, -1 );
		}
		if ( key ) {
			out[ key ] = value;
		}
	}
	return out;
}

/**
 * Load the env file into process.env without overwriting explicit values.
 * The file path is MCP_AI_ENV_FILE, default ~/.nvoos-bridge.env.
 *
 * @param {Function} [log]  Diagnostic sink (defaults to stderr).
 * @returns {boolean} True if a file was loaded.
 */
function loadEnvFile( log ) {
	log = log || ( ( msg ) => process.stderr.write( msg + '\n' ) );
	const file = process.env.MCP_AI_ENV_FILE || path.join( os.homedir(), '.nvoos-bridge.env' );
	if ( ! fs.existsSync( file ) ) {
		return false;
	}
	const values = parseEnvFile( fs.readFileSync( file, 'utf8' ) );
	for ( const [ key, value ] of Object.entries( values ) ) {
		if ( ! ( key in process.env ) ) {
			process.env[ key ] = value;
		}
	}
	log( `Loaded config from ${ file }` );
	return true;
}

module.exports = { parseEnvFile, loadEnvFile };
