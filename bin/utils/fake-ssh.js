#!/usr/bin/env node
/**
 * Test double for OpenSSH used by bin/test-mcp-bridge-ssh.js.
 *
 * Parses the `-L 127.0.0.1:LPORT:RHOST:RPORT` argument (as produced by
 * bin/mcp-bridge-ssh.js) and forwards TCP traffic, mimicking the parts of
 * `ssh -N -L` that the bridge relies on:
 *
 *   - the forward only accepts connections after listen() succeeds
 *     (the bridge waits for this before starting the relay)
 *   - the forward exits when stdin reaches EOF — this mirrors ssh's own
 *     behaviour and is the orphan-protection contract the bridge tests
 *
 * All other arguments (ports, options, user@host) are accepted and ignored.
 */

'use strict';

const net = require( 'net' );

const args = process.argv.slice( 2 );
const flagIndex = args.indexOf( '-L' );
if ( -1 === flagIndex || ! args[ flagIndex + 1 ] ) {
	process.stderr.write( '[fake-ssh] missing -L <bind:port:host:port>\n' );
	process.exit( 2 );
}

const spec = String( args[ flagIndex + 1 ] ).split( ':' );
if ( spec.length < 4 ) {
	process.stderr.write( `[fake-ssh] malformed -L spec: ${ args[ flagIndex + 1 ] }\n` );
	process.exit( 2 );
}

const localPort = parseInt( spec[ 1 ], 10 );
const remoteHost = spec[ 2 ];
const remotePort = parseInt( spec[ 3 ], 10 );

const server = net.createServer( ( client ) => {
	const upstream = net.connect( remotePort, remoteHost );
	upstream.once( 'error', () => client.destroy() );
	client.once( 'error', () => upstream.destroy() );
	client.pipe( upstream ).pipe( client );
} );

server.once( 'error', ( err ) => {
	process.stderr.write( `[fake-ssh] listen failed: ${ err.message }\n` );
	process.exit( 2 );
} );

server.listen( localPort, '127.0.0.1', () => {
	process.stderr.write( `[fake-ssh] forwarding 127.0.0.1:${ localPort } -> ${ remoteHost }:${ remotePort }\n` );
} );

// `ssh -N` exits when its stdin reaches EOF; the orchestrator keeps the
// write end open for exactly this reason, so a hard-killed bridge cannot
// orphan the tunnel. Mirror that contract.
process.stdin.resume();
process.stdin.on( 'end', () => {
	server.close( () => process.exit( 0 ) );
} );
