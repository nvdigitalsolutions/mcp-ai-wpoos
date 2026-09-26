/**
 * Dependency pin gate: the upstream MCP server must be pinned to an exact
 * version — upstream releases are frequent and a range would let a broken
 * release reach production unattended.
 */

import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const pkg = JSON.parse(
	await readFile( join( dirname( fileURLToPath( import.meta.url ) ), '..', 'package.json' ), 'utf8' )
);

const failures = [];

for ( const name of [ 'mcp-wordpress', '@modelcontextprotocol/sdk', 'express' ] ) {
	const version = pkg.dependencies?.[ name ];
	if ( ! version ) {
		failures.push( `${ name } is missing from dependencies.` );
		continue;
	}
	if ( /[\^~]/.test( version ) ) {
		failures.push( `${ name } must be pinned to an exact version (found "${ version }").` );
	}
}

if ( failures.length > 0 ) {
	console.error( failures.join( '\n' ) );
	process.exit( 1 );
}

console.log( 'All runtime dependencies are pinned to exact versions.' );
