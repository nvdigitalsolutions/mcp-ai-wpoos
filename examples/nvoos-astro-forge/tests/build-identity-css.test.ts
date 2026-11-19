// ---------------------------------------------------------------------------
// build-identity-css.test.ts — the stylesheet half of deploy identity.
//
// `forge-build` proves WHICH build is live. It could not prove the STYLESHEET
// came with it: a hand-composed `global.css` shipped without the committed
// `.banner-layer[class~='x50']` block and every gate still passed (2026-11-20).
// `postbuild` now stamps `forge-css`; these pin the comparison.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { parseStyleMarker, styleIdentityVerdict } from '../lib/build-identity.ts';

describe( 'parseStyleMarker', () => {
	it( 'reads the marker in either attribute order', () => {
		expect( parseStyleMarker( '<meta name="forge-css" content="abc123">' ) ).toBe( 'abc123' );
		expect( parseStyleMarker( '<meta content="abc123" name="forge-css">' ) ).toBe( 'abc123' );
	} );

	it( 'is undefined when the document is unstamped', () => {
		expect( parseStyleMarker( '<meta name="forge-build" content="x">' ) ).toBeUndefined();
	} );
} );

describe( 'styleIdentityVerdict', () => {
	it( 'passes when the live stylesheet is the artifact s', () => {
		expect( styleIdentityVerdict( 'abc', 'abc' ).status ).toBe( 'pass' );
	} );

	it( 'warns when the live page serves a different stylesheet', () => {
		const verdict = styleIdentityVerdict( 'abc', 'def' );
		expect( verdict.status ).toBe( 'warn' );
		expect( verdict.live ).toBe( 'def' );
		expect( verdict.expected ).toBe( 'abc' );
	} );

	it( 'warns when the artifact is stamped but the live page is not', () => {
		// Exactly the hand-composed-CSS symptom: the document does not name the
		// stylesheet this build produced.
		expect( styleIdentityVerdict( 'abc', undefined ).status ).toBe( 'warn' );
	} );

	it( 'skips, never silently passes, when nothing is stamped', () => {
		expect( styleIdentityVerdict( undefined, undefined ).status ).toBe( 'skip' );
		expect( styleIdentityVerdict( undefined, 'abc' ).status ).toBe( 'skip' );
	} );
} );
