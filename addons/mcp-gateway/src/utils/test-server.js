/**
 * Test-only helper: start an Express app on an ephemeral port.
 *
 * Not matched by the `*.test.js` test glob; used by the colocated suites.
 */

import { once } from 'events';

/**
 * Start an app on port 0 and resolve the base URL.
 *
 * @param {import('express').Express} app App to bind.
 * @return {Promise<{baseUrl: string, close: Function}>}
 */
export async function startTestServer( app ) {
	const server = app.listen( 0, '127.0.0.1' );
	await once( server, 'listening' );
	const { port } = server.address();
	return {
		baseUrl: `http://127.0.0.1:${ port }`,
		close: () => new Promise( ( resolve ) => server.close( resolve ) ),
	};
}
