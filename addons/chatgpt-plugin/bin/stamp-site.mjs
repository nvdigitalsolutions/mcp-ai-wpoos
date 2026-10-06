#!/usr/bin/env node
/**
 * Stamp the real site URL into the MCP configurations.
 *
 * Usage:
 *   node bin/stamp-site.mjs https://example.com
 *   SITE_URL=https://example.com node bin/stamp-site.mjs
 *
 * Replaces the YOUR-SITE.DOMAIN placeholder in mcp.json and .mcp.json with
 * the site origin. The placeholder files are tracked (the synced mirror repo
 * must always ship valid configs); stamping rewrites them in place, so the
 * change shows in `git status` — never commit the stamped URL.
 *
 * @see docs/project/proposals/053-chatgpt-plugin-addon-proposal.md
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const url = ( process.argv[2] || process.env.SITE_URL || '' ).trim().replace( /\/+$/, '' );

if ( !url ) {
	console.error( 'Usage: node bin/stamp-site.mjs https://example.com' );
	process.exit( 1 );
}
try {
	new URL( url );
} catch {
	console.error( 'Invalid site URL:', url );
	process.exit( 1 );
}

const mcpPath = url + '/wp-json/mcp-ai/v1/mcp';
for ( const file of [ 'mcp.json', '.mcp.json' ] ) {
	const path = join( root, file );
	if ( !existsSync( path ) ) {
		continue;
	}
	let raw = readFileSync( path, 'utf8' );
	if ( !raw.includes( 'YOUR-SITE.DOMAIN' ) ) {
		console.warn( 'Skipping', file, '(no placeholder, already stamped)' );
		continue;
	}
	raw = raw.replaceAll( 'https://YOUR-SITE.DOMAIN/wp-json/mcp-ai/v1/mcp', mcpPath );
	writeFileSync( path, raw );
	console.log( 'Stamped', file, '→', mcpPath );
}
console.log( 'Done. The stamp is an uncommitted local change — restore the placeholder before committing.' );
console.log( 'Set the bearer token via NVOOS_MCP_TOKEN (cred_xxx.SECRET) in your environment.' );
