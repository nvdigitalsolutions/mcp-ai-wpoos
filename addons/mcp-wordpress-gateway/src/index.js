/**
 * mcp-wordpress-gateway entry point.
 *
 * Boot order: load config (fail closed on invalid token) → build the MCP
 * server from pinned mcp-wordpress internals (policy-filtered) → listen.
 */

import { loadConfig } from './config.js';
import { createApp } from './app.js';
import { buildGatewayServer, handleMcpRequest } from './gateway-server.js';

const config = loadConfig();

if ( ! config.valid ) {
	console.error( JSON.stringify( { event: 'config_invalid', errors: config.errors } ) );
	process.exit( 1 );
}

let server;
try {
	( { server } = await buildGatewayServer( config ) );
} catch ( error ) {
	console.error( JSON.stringify( { event: 'gateway_build_failed', error: String( error?.message ?? error ) } ) );
	process.exit( 1 );
}

const app = createApp( config, {
	handleMcpRequest: ( req, res, parsedBody ) => handleMcpRequest( server, req, res, parsedBody ),
} );

app.listen( config.port, () => {
	if ( config.logLevel !== 'none' ) {
		console.log( JSON.stringify( { event: 'listening', port: config.port } ) );
	}
} );

for ( const signal of [ 'SIGTERM', 'SIGINT' ] ) {
	process.on( signal, () => {
		if ( config.logLevel !== 'none' ) {
			console.log( JSON.stringify( { event: 'shutdown', signal } ) );
		}
		setTimeout( () => process.exit( 0 ), 2000 ).unref();
	} );
}
