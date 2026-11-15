#!/usr/bin/env node
/**
 * Read-only Cloudways API probe (Phase 2 verification spike).
 *
 * Sends unauthenticated requests to candidate endpoints and classifies the
 * response codes:
 *   401/403 → route exists, auth required (expected)
 *   404     → route does not exist
 *   405     → route exists, wrong verb
 *   2xx/4xx(non-404) → public/validation response (route exists)
 *   000     → network/TLS failure
 *
 * Safe by construction: no credentials are sent unless CLOUDWAYS_EMAIL and
 * CLOUDWAYS_API_KEY are present, and only GET/POST probes with dummy bodies
 * are used. POST /oauth with dummy credentials is a validation probe.
 *
 * Usage: node scripts/cloudways-api-probe.mjs
 */

const V1 = 'https://api.cloudways.com/api/v1';
const V2 = 'https://api.cloudways.com/api/v2';

const probes = [
	{ api: 'v1', method: 'POST', path: '/oauth/access_token', body: { email: 'probe@example.invalid', api_key: 'probe' }, form: true },
	{ api: 'v1', method: 'GET', path: '/server' },
	{ api: 'v1', method: 'GET', path: '/app/1' },
	{ api: 'v2', method: 'POST', path: '/oauth/access_token', body: { email: 'probe@example.invalid', api_key: 'probe' }, form: true },
	{ api: 'v2', method: 'GET', path: '/server' },
	{ api: 'v2', method: 'GET', path: '/servers' },
	{ api: 'v2', method: 'GET', path: '/projects' },
	{ api: 'v2', method: 'GET', path: '/server/1/apps' },
	{ api: 'v2', method: 'GET', path: '/app/1' },
	{ api: 'v2', method: 'GET', path: '/app/1/credentials' },
	{ api: 'v2', method: 'GET', path: '/app/1/app_credentials' },
	{ api: 'v2', method: 'POST', path: '/app', body: { server_id: '1', application: 'wordpress', app_label: 'probe' } },
	{ api: 'v2', method: 'POST', path: '/app/cache/purge', body: { server_id: '1', app_id: '1' } },
	{ api: 'v2', method: 'GET', path: '/monitoring/server' },
];

function classify( status ) {
	if ( status === 0 ) {
		return 'network-failure';
	}
	if ( status === 401 || status === 403 ) {
		return 'route-exists-auth-required';
	}
	if ( status === 404 ) {
		return 'route-not-found';
	}
	if ( status === 405 ) {
		return 'route-exists-wrong-verb';
	}
	return 'route-exists';
}

async function probe( entry ) {
	const base = entry.api === 'v1' ? V1 : V2;
	const url = `${ base }${ entry.path }`;
	const headers = { Accept: 'application/json' };
	let body;
	if ( entry.body ) {
		if ( entry.form ) {
			body = new URLSearchParams( entry.body ).toString();
			headers[ 'Content-Type' ] = 'application/x-www-form-urlencoded';
		} else {
			body = JSON.stringify( entry.body );
			headers[ 'Content-Type' ] = 'application/json';
		}
	}

	const started = Date.now();
	try {
		const response = await fetch( url, {
			method: entry.method,
			headers,
			body,
			signal: AbortSignal.timeout( 10_000 ),
		} );
		const text = await response.text();
		return {
			...entry,
			status: response.status,
			classification: classify( response.status ),
			contentType: response.headers.get( 'content-type' ) ?? '',
			ms: Date.now() - started,
			sample: text.slice( 0, 120 ).replace( /\s+/g, ' ' ),
		};
	} catch ( error ) {
		return {
			...entry,
			status: 0,
			classification: classify( 0 ),
			contentType: '',
			ms: Date.now() - started,
			sample: error.message,
		};
	}
}

const results = [];
for ( const entry of probes ) {
	// Sequential: be gentle with the vendor API.
	results.push( await probe( entry ) ); // eslint-disable-line no-await-in-loop
}

for ( const result of results ) {
	console.log(
		[
			result.api.toUpperCase(),
			result.method.padEnd( 4 ),
			result.path.padEnd( 24 ),
			String( result.status ).padEnd( 4 ),
			result.classification.padEnd( 28 ),
			result.contentType.split( ';' )[ 0 ],
		].join( ' ' )
	);
}

console.log( '\nSamples:' );
for ( const result of results ) {
	if ( result.sample ) {
		console.log( `  ${ result.api } ${ result.method } ${ result.path } → ${ result.sample }` );
	}
}

/*
 * Optional authenticated, READ-ONLY section. Runs only when
 * CLOUDWAYS_EMAIL + CLOUDWAYS_API_KEY are present. Token values and response
 * bodies are never printed — only status codes, top-level keys, and counts.
 */
function summarise( data ) {
	if ( Array.isArray( data ) ) {
		return `array(${ data.length })${ data[ 0 ] ? ` keys=${ Object.keys( data[ 0 ] ).join( ',' ) }` : '' }`;
	}
	if ( data && typeof data === 'object' ) {
		return `object keys=${ Object.keys( data ).join( ',' ) }`;
	}
	return typeof data;
}

async function authenticatedChecks() {
	const email = process.env.CLOUDWAYS_EMAIL;
	const apiKey = process.env.CLOUDWAYS_API_KEY;
	if ( ! email || ! apiKey ) {
		console.log( '\nAuthenticated checks skipped (CLOUDWAYS_EMAIL / CLOUDWAYS_API_KEY not set).' );
		return;
	}

	console.log( '\nAuthenticated read-only checks (v2):' );
	const tokenResponse = await fetch( `${ V2 }/oauth/access_token`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
		body: new URLSearchParams( { email, api_key: apiKey } ).toString(),
		signal: AbortSignal.timeout( 10_000 ),
	} );
	const tokenBody = await tokenResponse.json().catch( () => null );
	const token = tokenBody && tokenBody.access_token;
	console.log( `  POST /oauth/access_token → ${ tokenResponse.status } token=${ token ? 'present' : 'missing' } expires_in=${ tokenBody?.expires_in ?? '?' }` );
	if ( ! token ) {
		return;
	}

	const checks = [ '/servers', '/projects' ];
	const serverId = process.env.CLOUDWAYS_DEFAULT_SERVER_ID;
	if ( serverId ) {
		checks.push( `/server/${ serverId }/apps` );
	}

	for ( const path of checks ) {
		const response = await fetch( `${ V2 }${ path }`, {
			headers: { Authorization: `Bearer ${ token }`, Accept: 'application/json' },
			signal: AbortSignal.timeout( 10_000 ),
		} );
		const body = await response.json().catch( () => null );
		console.log( `  GET ${ path } → ${ response.status } ${ summarise( body ) }` );
	}
}

await authenticatedChecks();
