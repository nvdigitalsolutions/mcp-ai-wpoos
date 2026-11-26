// SPDX-License-Identifier: MIT
/**
 * @fileoverview Pins the two doors into /manage: an Application Password (REST
 * Basic) and the WordPress ACCOUNT password (wp-login.php).
 *
 * Invariant: WordPress core accepts Basic auth only for Application Passwords,
 * so a normal account password must be tried against wp-login.php before the
 * handler refuses — and only an account that may edit posts gets a session.
 * Never contacts a real WordPress: fetch is stubbed per request.
 */

import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

import { afterEach, describe, expect, it } from 'vitest';

import { renderManageLoginTs, renderManageSessionTs } from '../lib/manage-runtime.ts';

const TARGET = 'https://cms.example.com';
const OPTIONS = { cookieName: 'nvoos_forge_session', ttlSeconds: 3600, targetSite: TARGET };

const dirs: string[] = [];
const realFetch = globalThis.fetch;

afterEach( async () => {
	delete process.env.NVOOS_MANAGE_SECRET;
	delete process.env.NVOOS_FORGE_GATE_SECRET;
	delete process.env.NVOOS_TARGET_SITE;
	globalThis.fetch = realFetch;
	await Promise.all( dirs.splice( 0 ).map( ( dir ) => rm( dir, { recursive: true, force: true } ) ) );
} );

async function loadLogin() {
	const dir = await mkdtemp( path.join( tmpdir(), 'forge-manage-login-' ) );
	dirs.push( dir );

	const sessionFile = path.join( dir, 'src', 'lib', 'manage-session.ts' );
	const loginFile = path.join( dir, 'src', 'pages', 'api', 'manage', 'login.ts' );
	await mkdir( path.dirname( sessionFile ), { recursive: true } );
	await mkdir( path.dirname( loginFile ), { recursive: true } );
	await writeFile( sessionFile, renderManageSessionTs( OPTIONS ), 'utf8' );
	await writeFile( loginFile, renderManageLoginTs(), 'utf8' );

	process.env.NVOOS_TARGET_SITE = TARGET;
	process.env.NVOOS_MANAGE_SECRET = 'login-test-secret';
	process.env.ASTRO_SITE = 'https://app.example';

	const login = ( await import( pathToFileURL( loginFile ).href ) ) as {
		POST( context: Record< string, unknown > ): Promise< Response >;
	};
	const session = ( await import( pathToFileURL( sessionFile ).href ) ) as {
		verifyManageSession( value: string ): { uid: string; name: string } | null;
	};
	return { login, session };
}

/** A fetch stand-in: Basic is always refused, wp-login admits `good`. */
function stubWp( options: { password: boolean; mayEdit: boolean } ) {
	const calls: string[] = [];
	globalThis.fetch = ( async ( input: string | URL | Request ) => {
		const url = String( typeof input === 'string' ? input : input instanceof URL ? input : input.url );
		calls.push( url );

		if ( url.includes( '/wp-json/wp/v2/users/me' ) ) {
			// An ACCOUNT password is not an Application Password.
			return new Response( JSON.stringify( { code: 'invalid_username' } ), {
				status: 401,
				headers: { 'content-type': 'application/json' },
			} );
		}
		if ( url.includes( '/wp-login.php' ) ) {
			if ( ! options.password ) {
				return new Response( 'login failed', { status: 200 } );
			}
			return new Response( null, {
				status: 302,
				headers: {
					location: TARGET + '/wp-admin/',
					'set-cookie': 'wordpress_logged_in_abc=editor%7C4102444800%7Ctok%7Chmac; path=/',
				},
			} );
		}
		if ( url.includes( '/wp-admin/profile.php' ) ) {
			return new Response( '<input type="hidden" name="checkuser_id" value="7" />', { status: 200 } );
		}
		if ( url.includes( '/wp-admin/edit.php' ) ) {
			return new Response( options.mayEdit ? '<html>posts</html>' : '<html>denied</html>', {
				status: options.mayEdit ? 200 : 403,
			} );
		}
		return new Response( 'unexpected', { status: 500 } );
	} ) as typeof fetch;
	return calls;
}

/** The contract door: one call, one answer, no probing. */
function stubContract( options: { canManage?: boolean; status?: number } = {} ) {
	const calls: string[] = [];
	globalThis.fetch = ( async ( input: string | URL | Request ) => {
		const url = String( typeof input === 'string' ? input : input instanceof URL ? input : input.url );
		calls.push( url );
		if ( url.includes( '/nvoos-commerce/v1/auth/login' ) ) {
			if ( options.status && options.status !== 200 ) {
				return new Response( JSON.stringify( { code: 'nvoos_invalid_credentials' } ), {
					status: options.status,
					headers: { 'content-type': 'application/json' },
				} );
			}
			return new Response(
				JSON.stringify( {
					id: 7,
					name: 'editor',
					canManage: options.canManage === true,
					capabilities: [ 'edit_posts' ],
				} ),
				{ status: 200, headers: { 'content-type': 'application/json' } }
			);
		}
		return new Response( 'unexpected', { status: 500 } );
	} ) as typeof fetch;
	return calls;
}

async function post(
	login: { POST( c: Record< string, unknown > ): Promise< Response > },
	body: string,
	origin?: string,
	secFetchSite?: string
) {
	const form = new URLSearchParams( body );
	const cookies: Array< { name: string; value: string } > = [];
	const headers: Record< string, string > = {
		'content-type': 'application/x-www-form-urlencoded',
	};
	if ( origin ) {
		headers.origin = origin;
	}
	if ( secFetchSite ) {
		headers[ 'sec-fetch-site' ] = secFetchSite;
	}
	const response = await login.POST( {
		request: new Request( 'https://eca.directory/api/manage/login', {
			method: 'POST',
			headers,
			body: form.toString(),
		} ),
		cookies: {
			set: ( name: string, value: string ) => {
				cookies.push( { name, value } );
			},
		},
		url: new URL( 'https://eca.directory/api/manage/login' ),
		clientAddress: '127.0.0.1',
	} );
	return { response, cookies };
}

describe( 'manage sign-in — the WordPress password path', () => {
	it( 'accepts the ACCOUNT password via wp-login.php and mints a verifiable session', async () => {
		const calls = stubWp( { password: true, mayEdit: true } );
		const { login, session } = await loadLogin();

		const { response, cookies } = await post( login, 'username=editor&password=correct-horse' );

		expect( calls.some( ( url ) => url.includes( '/wp-login.php' ) ) ).toBe( true );
		expect( response.status ).toBe( 303 );
		expect( response.headers.get( 'location' ) ?? '' ).not.toContain( 'error=' );

		const managed = cookies.find( ( cookie ) => cookie.name === 'nvoos_forge_session' );
		expect( managed ).toBeTruthy();
		const verified = session.verifyManageSession( managed?.value ?? '' );
		expect( verified?.uid ).toBe( '7' );
		expect( verified?.name ).toBe( 'editor' );
	} );

	it( 'accepts the configured public origin and refuses a foreign one', async () => {
		stubWp( { password: false, mayEdit: true } );
		const { login } = await loadLogin();

		const same = await post( login, 'username=editor&password=wrong', 'https://app.example' );
		expect( same.response.headers.get( 'location' ) ?? '' ).toContain( 'error=credentials' );

		const foreign = await post( login, 'username=editor&password=wrong', 'https://evil.example' );
		expect( foreign.response.headers.get( 'location' ) ?? '' ).toContain( 'error=origin' );
	} );

	it( 'accepts a same-origin form submission that serialises Origin as null', async () => {
		// Chrome sends 'Origin: null' for a same-origin FORM POST (fetch sends the
		// real origin), so sign-in failed for every browser submitting the form
		// while an API probe from the same page passed.
		stubWp( { password: false, mayEdit: true } );
		const { login } = await loadLogin();

		const formPost = await post( login, 'username=editor&password=wrong', 'null', 'same-origin' );
		expect( formPost.response.headers.get( 'location' ) ?? '' ).toContain( 'error=credentials' );

		// A null origin that reports cross-site is still refused.
		const crossSite = await post( login, 'username=editor&password=wrong', 'null', 'cross-site' );
		expect( crossSite.response.headers.get( 'location' ) ?? '' ).toContain( 'error=origin' );
	} );

	it( 'signs in from ONE contract call and never probes WordPress', async () => {
		const calls = stubContract( { canManage: true } );
		const { login, session } = await loadLogin();

		const { response, cookies } = await post( login, 'username=editor&password=correct-horse' );

		expect( response.status ).toBe( 303 );
		expect( response.headers.get( 'location' ) ?? '' ).not.toContain( 'error=' );
		expect( calls ).toHaveLength( 1 );
		expect( calls[ 0 ] ).toContain( '/nvoos-commerce/v1/auth/login' );

		const managed = cookies.find( ( cookie ) => cookie.name === 'nvoos_forge_session' );
		expect( session.verifyManageSession( managed?.value ?? '' )?.uid ).toBe( '7' );
	} );

	it( 'takes a contract 401 as final instead of probing again', async () => {
		const calls = stubContract( { status: 401 } );
		const { login } = await loadLogin();

		const { response } = await post( login, 'username=editor&password=wrong' );

		expect( response.headers.get( 'location' ) ?? '' ).toContain( 'error=credentials' );
		expect( calls ).toHaveLength( 1 );
	} );

	it( 'reads canManage=false from the contract as a permission refusal', async () => {
		stubContract( { canManage: false } );
		const { login } = await loadLogin();

		const { response, cookies } = await post( login, 'username=editor&password=correct-horse' );

		expect( response.headers.get( 'location' ) ?? '' ).toContain( 'error=permission' );
		expect( cookies ).toHaveLength( 0 );
	} );

	it( 'refuses a wrong password with error=credentials', async () => {
		stubWp( { password: false, mayEdit: true } );
		const { login } = await loadLogin();

		const { response, cookies } = await post( login, 'username=editor&password=wrong' );

		expect( response.status ).toBe( 303 );
		expect( response.headers.get( 'location' ) ?? '' ).toContain( 'error=credentials' );
		expect( cookies ).toHaveLength( 0 );
		// The refusal names the layer that answered: WordPress said no, and it
		// said why (invalid_username vs incorrect_password).
		expect( response.headers.get( 'x-nvoos-cred' ) ?? '' ).toContain( 'basic=401' );
		expect( response.headers.get( 'x-nvoos-cred' ) ?? '' ).toContain( 'wp_login=no' );
	} );

	it( 'refuses an account that may sign in but not edit posts', async () => {
		stubWp( { password: true, mayEdit: false } );
		const { login } = await loadLogin();

		const { response, cookies } = await post( login, 'username=subscriber&password=correct-horse' );

		expect( response.status ).toBe( 303 );
		expect( response.headers.get( 'location' ) ?? '' ).toContain( 'error=permission' );
		expect( cookies ).toHaveLength( 0 );
	} );
} );
