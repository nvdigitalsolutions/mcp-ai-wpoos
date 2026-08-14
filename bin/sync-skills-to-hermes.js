#!/usr/bin/env node
/**
 * Sync .agents/skills/ to the remote Hermes agent via its WebUI API.
 *
 * Standalone CLI wrapper around bin/hermes-skill-sync.js + the HTTP/auth
 * helpers exported by bin/hermes-mcp-server.js. Use it manually, from cron,
 * or from a git hook so every pull refreshes the agent's skills:
 *
 *   .git/hooks/post-merge:
 *     #!/bin/sh
 *     node bin/sync-skills-to-hermes.js >> .hermes-skill-sync.log 2>&1 || true
 *
 * Usage:
 *   node bin/sync-skills-to-hermes.js [--remove-missing] [--names=a,b,c]
 *                                     [--dir=.agents/skills] [--json]
 *
 * Config comes from the environment or ~/.nvoos-bridge.env (see
 * bin/utils/env-file.js): HERMES_WEBUI_URL + HERMES_WEBUI_PASSWORD are
 * required, HERMES_WEBUI_INSECURE=1 skips TLS verification. HERMES_SYNC_SKILLS_DIR
 * overrides the skills directory (same as --dir).
 *
 * Exit code 1 when any skill failed to sync.
 *
 * @package WP_MCP_AI
 */

'use strict';

const { loadEnvFile } = require( './utils/env-file.js' );
const server = require( './hermes-mcp-server.js' );
const { syncSkillsToWebui, DEFAULT_SKILLS_DIR } = require( './hermes-skill-sync.js' );

const log = ( msg ) => process.stderr.write( `[hermes-skill-sync] ${ msg }\n` );

/**
 * Parse CLI flags. Unknown flags abort with usage.
 *
 * @param {string[]} argv  process.argv.slice(2).
 * @returns {object} {dir, names, removeMissing, json}.
 */
function parseArgs( argv ) {
	const out = { dir: null, names: null, removeMissing: false, json: false };
	for ( const arg of argv ) {
		if ( '--remove-missing' === arg ) {
			out.removeMissing = true;
		} else if ( '--json' === arg ) {
			out.json = true;
		} else if ( arg.startsWith( '--dir=' ) ) {
			out.dir = arg.slice( 6 ).replace( /^["']|["']$/g, '' );
		} else if ( arg.startsWith( '--names=' ) ) {
			out.names = arg.slice( 8 ).split( ',' ).map( ( n ) => n.trim() ).filter( Boolean );
		} else {
			log( `Unknown argument: ${ arg }` );
			log( 'Usage: node bin/sync-skills-to-hermes.js [--remove-missing] [--names=a,b] [--dir=DIR] [--json]' );
			process.exit( 2 );
		}
	}
	return out;
}

/**
 * CLI entry: load env, diff .agents/skills/ against the WebUI, print summary.
 */
async function main() {
	loadEnvFile( log );
	server.readConfig();

	const config = server.getConfig();
	if ( ! config.baseUrl ) {
		log( 'ERROR: HERMES_WEBUI_URL is not set.' );
		process.exit( 1 );
	}
	if ( ! config.password ) {
		log( 'ERROR: HERMES_WEBUI_PASSWORD is not set.' );
		process.exit( 1 );
	}

	const args = parseArgs( process.argv.slice( 2 ) );
	const skillsDir = args.dir || process.env.HERMES_SYNC_SKILLS_DIR || DEFAULT_SKILLS_DIR;

	log( `Syncing ${ skillsDir } → ${ config.baseUrl }` );
	const summary = await syncSkillsToWebui( {
		authedRequest: server.authedRequest,
		skillsDir,
		names: args.names,
		removeMissing: args.removeMissing,
		log,
	} );

	if ( args.json ) {
		process.stdout.write( JSON.stringify( summary, null, 2 ) + '\n' );
	} else {
		const lines = [
			`Skills sync: ${ summary.synced.length } uploaded, ${ summary.unchanged.length } unchanged,`,
			`  ${ summary.removed.length } removed, ${ summary.failed.length } failed`,
			`  (local: ${ summary.local_count }, remote: ${ summary.remote_count })`,
		];
		if ( summary.synced.length ) {
			lines.push( `  uploaded: ${ summary.synced.join( ', ' ) }` );
		}
		if ( summary.skipped_files.length ) {
			lines.push( `  skipped extra files: ${ summary.skipped_files.join( '; ' ) }` );
		}
		if ( summary.failed.length ) {
			lines.push( `  FAILED: ${ summary.failed.join( '; ' ) }` );
		}
		process.stdout.write( lines.join( '\n' ) + '\n' );
	}

	process.exit( summary.failed.length ? 1 : 0 );
}

main().catch( ( e ) => {
	log( `FATAL: ${ e.message }` );
	process.exit( 1 );
} );
