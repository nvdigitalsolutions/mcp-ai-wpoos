// ---------------------------------------------------------------------------
// gate.test.ts — the page gate (Proposal 152): probe classification, the
// wp-login session, host scoping, redirect safety and no-store.
//
// The fixture server grows `WP_FIXTURE_GATE` modes (503 / holding / edge), so
// every port of the ladder is exercised with no live site.
// ---------------------------------------------------------------------------

import type { AddressInfo } from 'node:net';
import type { Server } from 'node:http';

import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest';

import { createWpFixtureServer } from '../scripts/wp-fixture-server.mjs';

import {
	gateContextOptions,
	installGateFetch,
	openGate,
	parseRetryAfter,
	probeGate,
	setActiveGateCookies,
	visibleTextLength,
} from '../lib/gate.ts';
import type { ForgeGateCookie, GateProbe } from '../lib/gate.ts';
import type { SiteProfile } from '../lib/types.ts';

const GATE_ENV = [
	'WP_FIXTURE_GATE',
	'WP_FIXTURE_GATE_USER',
	'WP_FIXTURE_GATE_PASSWORD',
	'WP_FIXTURE_BRIDGE_GATE',
	'WP_GATE_MODE',
	'WP_GATE_USERNAME',
	'WP_GATE_PASSWORD',
	'WP_GATE_COOKIE',
	'WP_GATE_STORAGE_STATE',
	'WP_GATE_QUERY',
	'WP_GATE_HEADERS_JSON',
	'WP_GATE_HTTP_AUTH',
	'WP_GATE_NO_STORE',
	'CF_ACCESS_CLIENT_ID',
	'CF_ACCESS_CLIENT_SECRET',
	'WP_GATE_LOGIN_URL',
] as const;

const saved = new Map< string, string | undefined >();

function startServer(): Promise< { server: Server; base: string } > {
	const server = createWpFixtureServer( 0 ) as unknown as Server;
	return new Promise( ( resolve ) => {
		server.listen( 0, '127.0.0.1', () => {
			const { port } = server.address() as AddressInfo;
			resolve( { server, base: `http://127.0.0.1:${ port }` } );
		} );
	} );
}

let primary: { server: Server; base: string };
let secondary: { server: Server; base: string };

function profileFor( url: string ): SiteProfile {
	return { url, collections: [ { slug: 'pages', restBase: 'wp/v2/pages', fields: [] } ] };
}

async function withEnv< T >( values: Record< string, string >, run: () => Promise< T > ): Promise< T > {
	for ( const [ key, value ] of Object.entries( values ) ) {
		process.env[ key ] = value;
	}
	try {
		return await run();
	} finally {
		for ( const key of Object.keys( values ) ) {
			if ( saved.get( key ) === undefined ) {
				delete process.env[ key ];
			} else {
				process.env[ key ] = saved.get( key );
			}
		}
	}
}

beforeAll( async () => {
	for ( const key of GATE_ENV ) {
		saved.set( key, process.env[ key ] );
	}
	primary = await startServer();
	secondary = await startServer();
} );

afterEach( () => {
	for ( const key of GATE_ENV ) {
		const value = saved.get( key );
		if ( value === undefined ) {
			delete process.env[ key ];
		} else {
			process.env[ key ] = value;
		}
	}
	setActiveGateCookies( [] );
} );

afterAll( async () => {
	await new Promise< void >( ( resolve ) => primary.server.close( () => resolve() ) );
	await new Promise< void >( ( resolve ) => secondary.server.close( () => resolve() ) );
} );

describe( 'parseRetryAfter', () => {
	it( 'accepts delta-seconds', () => {
		expect( parseRetryAfter( '600' ) ).toBe( 600 );
	} );

	it( 'accepts an HTTP-date relative to now', () => {
		const at = new Date( Date.now() + 90_000 ).toUTCString();
		const seconds = parseRetryAfter( at );
		expect( seconds ).toBeGreaterThan( 80 );
		expect( seconds ).toBeLessThanOrEqual( 90 );
	} );

	it( 'rejects garbage', () => {
		expect( parseRetryAfter( 'soon' ) ).toBeUndefined();
		expect( parseRetryAfter( null ) ).toBeUndefined();
	} );
} );

describe( 'visibleTextLength', () => {
	it( 'ignores markup, scripts, comments and entities', () => {
		const html = '<div class="x"><!-- hidden --><script>var a = 1;</script><style>.a{}</style><p>Hello &amp; welcome</p></div>';
		expect( visibleTextLength( html ) ).toBe( 'Hello welcome'.length );
	} );
} );

describe( 'probeGate classification', () => {
	it( 'reports a plain fixture as open', async () => {
		const probe = await probeGate( profileFor( primary.base ), { allowLoopback: true } );
		expect( probe.state ).toBe( 'open' );
		expect( probe.status ).toBe( 200 );
		expect( probe.restHealthy ).toBe( true );
	} );

	it( 'reads 503 + Retry-After with a healthy /wp-json/ as the site\'s own gate', async () => {
		await withEnv( { WP_FIXTURE_GATE: '503' }, async () => {
			const probe = await probeGate( profileFor( primary.base ), { allowLoopback: true } );
			expect( probe.state ).toBe( 'gated' );
			expect( probe.status ).toBe( 503 );
			expect( probe.retryAfter ).toBe( 600 );
			expect( probe.restHealthy ).toBe( true );
			expect( probe.remediation.join( ' ' ) ).toMatch( /Role|role|bypass/i );
		} );
	} );

	it( 'reads a 200 coming-soon page as gated (no status signal)', async () => {
		await withEnv( { WP_FIXTURE_GATE: 'holding' }, async () => {
			const probe = await probeGate( profileFor( primary.base ), { allowLoopback: true } );
			expect( probe.state ).toBe( 'gated' );
			expect( probe.status ).toBe( 200 );
			expect( probe.fingerprint ).toBe( 'generic-holding-page' );
		} );
	} );

	it( 'reads a WWW-Authenticate: Basic challenge as edge auth', async () => {
		await withEnv( { WP_FIXTURE_GATE: 'edge' }, async () => {
			const probe = await probeGate( profileFor( primary.base ), { allowLoopback: true } );
			expect( probe.state ).toBe( 'edge-auth' );
			expect( probe.status ).toBe( 401 );
			expect( probe.remediation.join( ' ' ) ).toMatch( /CF_ACCESS_CLIENT_ID|WP_GATE_HTTP_AUTH/ );
		} );
	} );

	it( 'honours an operator-pinned reject marker', async () => {
		await withEnv( { WP_FIXTURE_GATE: 'holding' }, async () => {
			const profile = profileFor( primary.base );
			profile.gate = { verify: { rejectMarkers: [ 'Launching shortly' ] } };
			const probe = await probeGate( profile, { allowLoopback: true } );
			expect( probe.state ).toBe( 'gated' );
			expect( probe.evidence.join( ' ' ) ).toContain( 'Launching shortly' );
		} );
	} );
} );

describe( 'openGate', () => {
	it( 'logs in with a username/password and unlocks a 503 gate', async () => {
		await withEnv(
			{ WP_FIXTURE_GATE: '503', WP_GATE_USERNAME: 'forge', WP_GATE_PASSWORD: 'secret' },
			async () => {
				const gate = await openGate( profileFor( primary.base ), { allowLoopback: true } );
				try {
					expect( gate.blocked ).toBe( false );
					expect( gate.probe.state ).toBe( 'open' );
					expect( gate.cookies.some( ( cookie ) => cookie.name.startsWith( 'wordpress_logged_in_' ) ) ).toBe( true );
					expect( gate.describe().mechanism ).toContain( 'session' );
				} finally {
					gate.close();
				}
			}
		);
	} );

	it( 'fails closed with no credentials', async () => {
		await withEnv( { WP_FIXTURE_GATE: '503' }, async () => {
			const gate = await openGate( profileFor( primary.base ), { allowLoopback: true } );
			try {
				expect( gate.blocked ).toBe( true );
				expect( gate.cookies ).toHaveLength( 0 );
			} finally {
				gate.close();
			}
		} );
	} );

	it( 'reports the rejected login in the evidence', async () => {
		await withEnv(
			{ WP_FIXTURE_GATE: '503', WP_GATE_USERNAME: 'forge', WP_GATE_PASSWORD: 'wrong' },
			async () => {
				const gate = await openGate( profileFor( primary.base ), { allowLoopback: true } );
				try {
					expect( gate.blocked ).toBe( true );
					expect( gate.probe.evidence.join( ' ' ) ).toMatch( /session failed/i );
				} finally {
					gate.close();
				}
			}
		);
	} );

	it( 'accepts a supplied session cookie instead of logging in', async () => {
		await withEnv(
			{ WP_FIXTURE_GATE: '503', WP_GATE_COOKIE: 'wordpress_logged_in_fixture=1' },
			async () => {
				const gate = await openGate( profileFor( primary.base ), { allowLoopback: true } );
				try {
					expect( gate.blocked ).toBe( false );
					expect( gate.cookies[ 0 ]?.name ).toBe( 'wordpress_logged_in_fixture' );
				} finally {
					gate.close();
				}
			}
		);
	} );

	it( 'unlocks through the bypass query', async () => {
		await withEnv( { WP_FIXTURE_GATE: 'holding', WP_GATE_QUERY: 'forge_bypass=1' }, async () => {
			const gate = await openGate( profileFor( primary.base ), { allowLoopback: true } );
			try {
				expect( gate.blocked ).toBe( false );
				expect( gate.describe().mechanism ).toContain( 'bypass-query' );
			} finally {
				gate.close();
			}
		} );
	} );

	it( 'sends the session to the CMS host only', async () => {
		await withEnv(
			{ WP_FIXTURE_GATE: '503', WP_GATE_USERNAME: 'forge', WP_GATE_PASSWORD: 'secret' },
			async () => {
				const gate = await openGate( profileFor( primary.base ), { allowLoopback: true } );
				try {
					const here = ( await ( await fetch( `${ primary.base }/echo-headers` ) ).json() ) as {
						cookie: string;
						cacheControl: string;
					};
					expect( here.cookie ).toContain( 'wordpress_logged_in_' );
					expect( here.cacheControl ).toBe( 'no-store' );

					const elsewhere = ( await ( await fetch( `${ secondary.base }/echo-headers` ) ).json() ) as {
						cookie: string;
					};
					expect( elsewhere.cookie ).toBe( '' );
				} finally {
					gate.close();
				}
			}
		);
	} );

	it( 'does nothing when the mode is off', async () => {
		await withEnv( { WP_FIXTURE_GATE: '503', WP_GATE_MODE: 'off' }, async () => {
			const gate = await openGate( profileFor( primary.base ), { allowLoopback: true } );
			try {
				expect( gate.blocked ).toBe( false );
				expect( gate.probe.status ).toBe( 0 );
			} finally {
				gate.close();
			}
		} );
	} );
} );

describe( 'bridge corroboration (Proposal 152 P4)', () => {
	it( 'turns an open HTML read into gated when the bridge reports a gate', async () => {
		await withEnv( { WP_FIXTURE_GATE: 'off', WP_FIXTURE_BRIDGE_GATE: 'coming_soon' }, async () => {
			const probe = await probeGate( profileFor( primary.base ), { allowLoopback: true } );
			expect( probe.state ).toBe( 'gated' );
			expect( probe.fingerprint ).toBe( 'elementor-maintenance' );
			expect( probe.bridge ).toEqual( { maintenance: true, mode: 'coming_soon', plugin: 'elementor' } );
			expect( probe.evidence.join( ' ' ) ).toContain( 'bridge' );
		} );
	} );

	it( 'leaves an open site open when the bridge route is absent', async () => {
		await withEnv( { WP_FIXTURE_GATE: 'off', WP_FIXTURE_BRIDGE_GATE: 'absent' }, async () => {
			const probe = await probeGate( profileFor( primary.base ), { allowLoopback: true } );
			expect( probe.state ).toBe( 'open' );
			expect( probe.bridge ).toBeUndefined();
		} );
	} );

	it( 'records the disagreement when HTML is gated but the bridge says off', async () => {
		await withEnv( { WP_FIXTURE_GATE: '503', WP_FIXTURE_BRIDGE_GATE: 'off' }, async () => {
			const probe = await probeGate( profileFor( primary.base ), { allowLoopback: true } );
			expect( probe.state ).toBe( 'gated' );
			expect( probe.bridge ).toEqual( { maintenance: false, mode: 'off', plugin: null } );
			expect( probe.evidence.join( ' ' ) ).toContain( 'NOT gated' );
		} );
	} );
} );

describe( 'installGateFetch', () => {
	const cookie: ForgeGateCookie = {
		name: 'wordpress_logged_in_test',
		value: '1',
		domain: '127.0.0.1',
		path: '/',
	};

	it( 'follows a same-host redirect with the session and no-store', async () => {
		const calls: Array< { url: string; cookie: string; cacheControl: string } > = [];
		const fake = ( async ( input: RequestInfo | URL, init: RequestInit = {} ) => {
			const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
			const headers = new Headers( init.headers );
			calls.push( { url, cookie: headers.get( 'cookie' ) ?? '', cacheControl: headers.get( 'cache-control' ) ?? '' } );
			if ( calls.length === 1 ) {
				return new Response( '', { status: 302, headers: { location: 'http://127.0.0.1:1234/next' } } );
			}
			return new Response( 'ok', { status: 200 } );
		} ) as typeof fetch;

		const uninstall = installGateFetch( '127.0.0.1:1234', { cookies: [ cookie ], headers: {}, query: {} }, fake );
		try {
			const response = await fetch( 'http://127.0.0.1:1234/start' );
			expect( response.status ).toBe( 200 );
			expect( calls ).toHaveLength( 2 );
			expect( calls[ 1 ].url ).toBe( 'http://127.0.0.1:1234/next' );
			expect( calls[ 1 ].cookie ).toContain( 'wordpress_logged_in_test=1' );
			expect( calls[ 1 ].cacheControl ).toBe( 'no-store' );
		} finally {
			uninstall();
		}
	} );

	it( 'never forwards the session across a redirect to another host', async () => {
		const calls: Array< { url: string; cookie: string } > = [];
		const fake = ( async ( input: RequestInfo | URL, init: RequestInit = {} ) => {
			const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
			calls.push( { url, cookie: new Headers( init.headers ).get( 'cookie' ) ?? '' } );
			return new Response( '', { status: 302, headers: { location: 'https://sso.example.com/login' } } );
		} ) as typeof fetch;

		const uninstall = installGateFetch( '127.0.0.1:1234', { cookies: [ cookie ], headers: {}, query: {} }, fake );
		try {
			const response = await fetch( 'http://127.0.0.1:1234/start' );
			expect( response.status ).toBe( 302 );
			expect( calls ).toHaveLength( 1 );
			expect( calls[ 0 ].cookie ).toContain( 'wordpress_logged_in_test=1' );
		} finally {
			uninstall();
		}
	} );

	it( 'appends the bypass query to GET requests only', async () => {
		const seen: string[] = [];
		const fake = ( async ( input: RequestInfo | URL ) => {
			seen.push( typeof input === 'string' ? input : input instanceof URL ? input.href : input.url );
			return new Response( 'ok', { status: 200 } );
		} ) as typeof fetch;

		const uninstall = installGateFetch( '127.0.0.1:1234', {
			cookies: [],
			headers: {},
			query: { forge_bypass: '1' },
		}, fake );
		try {
			await fetch( 'http://127.0.0.1:1234/page' );
			await fetch( 'http://127.0.0.1:1234/submit', { method: 'POST', body: 'x' } );
			expect( seen[ 0 ] ).toBe( 'http://127.0.0.1:1234/page?forge_bypass=1' );
			expect( seen[ 1 ] ).toBe( 'http://127.0.0.1:1234/submit' );
		} finally {
			uninstall();
		}
	} );
} );

describe( 'gateContextOptions', () => {
	it( 'passes options through untouched when no gate is active', () => {
		setActiveGateCookies( [] );
		const options = { deviceScaleFactor: 1 };
		expect( gateContextOptions( options ) ).toBe( options );
	} );

	it( 'merges the active cookies into the context options', () => {
		const cookies: ForgeGateCookie[] = [ { name: 'a', value: 'b', domain: 'example.com', path: '/' } ];
		setActiveGateCookies( cookies );
		expect( gateContextOptions( { deviceScaleFactor: 1 } ) ).toEqual( { deviceScaleFactor: 1, cookies } );
		setActiveGateCookies( [] );
	} );
} );

describe( 'probe typing', () => {
	it( 'exposes a probe shape with evidence and no secret', async () => {
		const probe: GateProbe = await probeGate( profileFor( secondary.base ), { allowLoopback: true } );
		expect( Array.isArray( probe.evidence ) ).toBe( true );
		expect( JSON.stringify( probe ) ).not.toContain( 'secret' );
	} );
} );
