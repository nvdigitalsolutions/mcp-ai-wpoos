// ---------------------------------------------------------------------------
// fetch-proxy.test.ts — the build-time proxy seam: no-op when unset,
// ProxyAgent when set, fail-closed on misconfiguration.
// ---------------------------------------------------------------------------

import { afterEach, describe, expect, it } from 'vitest';
import { getGlobalDispatcher, ProxyAgent, setGlobalDispatcher } from 'undici';

import { configureFetchProxy, PROXY_ENV } from '../lib/fetch-proxy.ts';

const original = getGlobalDispatcher();

afterEach( () => {
	setGlobalDispatcher( original );
} );

describe( 'configureFetchProxy', () => {
	it( 'is a no-op without the env', () => {
		configureFetchProxy( {} );
		expect( getGlobalDispatcher() ).toBe( original );
	} );

	it( 'installs a ProxyAgent for an http URI', () => {
		configureFetchProxy( { [ PROXY_ENV ]: 'http://proxy.example:8080' } );
		expect( getGlobalDispatcher() ).toBeInstanceOf( ProxyAgent );
	} );

	it( 'accepts an https proxy with userinfo credentials', () => {
		configureFetchProxy( { [ PROXY_ENV ]: 'https://user:pass@proxy.example:8443' } );
		expect( getGlobalDispatcher() ).toBeInstanceOf( ProxyAgent );
	} );

	it( 'fails closed on an invalid URI', () => {
		expect( () => configureFetchProxy( { [ PROXY_ENV ]: 'not a url' } ) ).toThrow( /refusing to build/ );
	} );

	it( 'fails closed on a non-http scheme', () => {
		expect( () => configureFetchProxy( { [ PROXY_ENV ]: 'ftp://proxy.example' } ) ).toThrow( /must be http/ );
	} );

	it( 'treats a whitespace-only value as unset', () => {
		configureFetchProxy( { [ PROXY_ENV ]: '   ' } );
		expect( getGlobalDispatcher() ).toBe( original );
	} );
} );
