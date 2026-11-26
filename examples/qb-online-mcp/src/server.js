// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * Entry point.
 *
 * WARNING: run exactly ONE instance against a given Intuit grant. The refresh
 * token rotates, so a second replica (or a concurrent external refresher)
 * invalidates the first one's token with invalid_grant.
 */

import { loadConfig } from './config.js';
import { createApp } from './app.js';

const config = loadConfig();
const { server } = await createApp( config );

server.listen( config.port, () => {
	console.log(
		`[qbo] ${ config.serverName } v${ config.serverVersion } listening on :${ config.port } (${ config.environment })`
	);
	console.log( `[qbo] health: GET /health · MCP: POST /` );

	if ( config.allowAnonymous ) {
		console.warn( '[qbo] QBO_MCP_ALLOW_ANONYMOUS=1 — this endpoint is unauthenticated.' );
	}
} );

for ( const signal of [ 'SIGINT', 'SIGTERM' ] ) {
	process.on( signal, () => {
		console.log( `[qbo] ${ signal } received, shutting down.` );
		server.close( () => process.exit( 0 ) );
	} );
}
