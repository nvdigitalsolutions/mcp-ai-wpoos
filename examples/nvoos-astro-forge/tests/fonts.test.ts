// ---------------------------------------------------------------------------
// fonts.test.ts — canonical Google Fonts family names.
//
// The analyzer harvests families lower-case; Google's CSS API is case
// sensitive, so the verbatim name produced a 400 and every forged site fell
// back to the system stack.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { canonicalFontFamily } from '../templates/blog/src/lib/fonts.ts';

describe( 'canonicalFontFamily', () => {
	it( 'title-cases a harvested lower-case family', () => {
		expect( canonicalFontFamily( 'roboto' ) ).toBe( 'Roboto' );
		expect( canonicalFontFamily( 'rubik' ) ).toBe( 'Rubik' );
		expect( canonicalFontFamily( 'montserrat' ) ).toBe( 'Montserrat' );
		expect( canonicalFontFamily( 'open sans' ) ).toBe( 'Open Sans' );
	} );

	it( 'uses the canonical name where title case would be wrong', () => {
		expect( canonicalFontFamily( 'ibm plex sans' ) ).toBe( 'IBM Plex Sans' );
		expect( canonicalFontFamily( 'dm sans' ) ).toBe( 'DM Sans' );
		expect( canonicalFontFamily( 'pt serif' ) ).toBe( 'PT Serif' );
		expect( canonicalFontFamily( 'noto sans jp' ) ).toBe( 'Noto Sans JP' );
	} );

	it( 'collapses whitespace and keeps an already-canonical name', () => {
		expect( canonicalFontFamily( '  Open   Sans ' ) ).toBe( 'Open Sans' );
		expect( canonicalFontFamily( 'Roboto' ) ).toBe( 'Roboto' );
	} );

	it( 'returns an empty string when there is no family', () => {
		expect( canonicalFontFamily( '' ) ).toBe( '' );
		expect( canonicalFontFamily( '   ' ) ).toBe( '' );
	} );
} );
