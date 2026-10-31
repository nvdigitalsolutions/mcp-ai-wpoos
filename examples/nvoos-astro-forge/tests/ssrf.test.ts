// ---------------------------------------------------------------------------
// ssrf.test.ts — the URL guard: private/literal ranges, credentials, bad
// protocols, and the registry-vs-open-analyze fetch policy.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { assertSafeHttpUrl, hostnameOf, isFetchAllowed, SsrfError } from '../lib/ssrf.ts';

describe( 'assertSafeHttpUrl', () => {
	it( 'accepts plain http(s) hostname URLs', () => {
		expect( assertSafeHttpUrl( 'https://example.com/' ).hostname ).toBe( 'example.com' );
		expect( assertSafeHttpUrl( 'http://sub.example.com:8080/path' ).port ).toBe( '8080' );
	} );

	it( 'refuses non-http(s) protocols, junk, and embedded credentials', () => {
		expect( () => assertSafeHttpUrl( 'ftp://example.com' ) ).toThrow( SsrfError );
		expect( () => assertSafeHttpUrl( 'file:///etc/passwd' ) ).toThrow( SsrfError );
		expect( () => assertSafeHttpUrl( 'not a url' ) ).toThrow( SsrfError );
		expect( () => assertSafeHttpUrl( 'https://user:pass@example.com' ) ).toThrow( /credentials/ );
	} );

	it( 'refuses private and reserved IPv4 literals', () => {
		for ( const host of [ '127.0.0.1', '10.1.2.3', '192.168.0.1', '172.16.0.9', '169.254.169.254', '100.64.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255' ] ) {
			expect( () => assertSafeHttpUrl( `http://${ host }/` ), host ).toThrow( SsrfError );
		}
	} );

	it( 'refuses loopback/ULA/link-local IPv6 literals', () => {
		for ( const host of [ '::1', '[::1]', 'fc00::1', 'fd12::9', 'fe80::1', '::ffff:127.0.0.1' ] ) {
			expect( () => assertSafeHttpUrl( `http://${ host }/` ), host ).toThrow( SsrfError );
		}
	} );

	it( 'normalizes hostnames for comparison', () => {
		expect( hostnameOf( 'https://WWW.Example.COM/path' ) ).toBe( 'www.example.com' );
	} );

	it( 'the loopback opt-in admits ONLY loopback ranges', () => {
		// Refused by default…
		expect( () => assertSafeHttpUrl( 'http://127.0.0.1:8473/' ) ).toThrow( SsrfError );
		// …admitted with the opt-in…
		expect( assertSafeHttpUrl( 'http://127.0.0.1:8473/', { allowLoopback: true } ).hostname ).toBe( '127.0.0.1' );
		expect( assertSafeHttpUrl( 'http://[::1]:8473/', { allowLoopback: true } ).hostname ).toBe( '[::1]' );
		// …but every other private range stays refused.
		expect( () => assertSafeHttpUrl( 'http://192.168.0.1/', { allowLoopback: true } ) ).toThrow( SsrfError );
		expect( () => assertSafeHttpUrl( 'http://169.254.169.254/', { allowLoopback: true } ) ).toThrow( SsrfError );
	} );
} );

describe( 'isFetchAllowed', () => {
	it( 'allows registered hosts without the opt-in and blocks strangers', () => {
		const registry = [ 'https://shop.example.com' ];
		expect( isFetchAllowed( 'https://shop.example.com/path', registry, false ) ).toBe( true );
		expect( isFetchAllowed( 'https://other.example.com', registry, false ) ).toBe( false );
	} );

	it( 'the open-analyze opt-in admits non-registry hosts', () => {
		expect( isFetchAllowed( 'https://other.example.com', [ 'https://shop.example.com' ], true ) ).toBe( true );
	} );
} );
