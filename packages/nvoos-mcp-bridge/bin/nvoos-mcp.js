#!/usr/bin/env node
/**
 * nvoos-mcp — package entry dispatcher (package-owned, NOT synced from bin/).
 *
 * npx exposes only a package's primary bin, so this dispatcher selects the
 * variant by first argument:
 *
 *   npx @nvdigitalsolutions/nvoos-mcp-bridge          → HTTPS relay
 *   npx @nvdigitalsolutions/nvoos-mcp-bridge ssh      → SSH variant
 *
 * Direct installs (`npm i -g`) also get the `nvoos-mcp-ssh` bin and can skip
 * the dispatcher entirely.
 *
 * Stdout stays untouched (stdio: inherit, diagnostics to stderr only) so the
 * child's MCP message stream remains the sole stdout writer.
 */

'use strict';

const path = require( 'path' );
const { spawn } = require( 'child_process' );

const useSsh = 'ssh' === process.argv[ 2 ];
const script = useSsh ? 'mcp-bridge-ssh.js' : 'mcp-bridge.js';

const child = spawn( process.execPath, [ path.join( __dirname, script ) ], {
	stdio: 'inherit',
	windowsHide: true,
} );

// Forward the graceful-shutdown signals Zed and other clients send.
for ( const sig of [ 'SIGINT', 'SIGTERM' ] ) {
	process.on( sig, () => {
		try {
			child.kill( sig );
		} catch ( e ) {
			// Already gone.
		}
	} );
}

child.once( 'error', ( err ) => {
	process.stderr.write( `[nvoos-mcp] could not start ${ script }: ${ err.message }\n` );
	process.exit( 1 );
} );

child.once( 'exit', ( code, signal ) => {
	process.exit( signal ? 0 : ( 'number' === typeof code ? code : 1 ) );
} );
