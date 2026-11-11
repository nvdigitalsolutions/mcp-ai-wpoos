// ---------------------------------------------------------------------------
// sticky-header.test.ts — the measured sticky header (analyze --geometry).
//
// A source header that pins on scroll and CHANGES appearance — transparent over
// the hero, solid (and often shorter) once the page moves — is the one chrome
// fact the markup cannot tell you: the theme adds the class from JavaScript and
// the resting rule is transparent. `measureStickyHeader` records the browser's
// computed styles; these tests pin how those facts reach the build.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderBehaviorsGenerated, renderMotionDoc } from '../lib/scaffold.ts';
import type { ForgeStickyHeader, SiteProfile } from '../lib/types.ts';

const base = ( stickyHeader?: ForgeStickyHeader ): SiteProfile =>
	( { url: 'https://example.com', name: 'Fixture', collections: [], ...( stickyHeader ? { stickyHeader } : {} ) } as unknown as SiteProfile );

const changed: ForgeStickyHeader = {
	sticky: true,
	pinnedBg: '#ffffff',
	pinnedInk: '#111111',
	shadow: '0 2px 8px rgba(0, 0, 0, 0.12)',
	restHeight: 96,
	pinnedHeight: 64,
	bgChanged: true,
	heightChanged: true,
};

describe( 'sticky header facts', () => {
	it( 'emits the measured pinned background, ink, shadow and both heights', () => {
		const code = renderBehaviorsGenerated( base( changed ) );

		expect( code ).toContain( 'headerSticky: true' );
		expect( code ).toContain( "headerPinnedBg: '#ffffff'" );
		expect( code ).toContain( "headerPinnedInk: '#111111'" );
		expect( code ).toContain( "headerPinnedShadow: '0 2px 8px rgba(0, 0, 0, 0.12)'" );
		expect( code ).toContain( "headerRestHeight: '96px'" );
		expect( code ).toContain( "headerPinnedHeight: '64px'" );
	} );

	it( 'marks the header sticky from the MEASUREMENT alone (no CSS class said so)', () => {
		const code = renderBehaviorsGenerated( base( { sticky: true } ) );

		expect( code ).toContain( 'headerSticky: true' );
		// Nothing was measured beyond the pin — no invented colours.
		expect( code ).not.toContain( "headerPinnedBg: '" );
		expect( code ).not.toContain( "headerPinnedHeight: '" );
	} );

	it( 'emits nothing pinned for a header that does not stick', () => {
		const code = renderBehaviorsGenerated( base( { sticky: false, pinnedBg: '#ffffff' } ) );

		expect( code ).toContain( 'headerSticky: false' );
		// A pinned colour for a non-sticky header is a contradiction: the profile
		// should never carry it, and the emitter must not publish it either. The
		// check is on the emitted VALUE — the type declaration always names the key.
		expect( code ).not.toContain( "headerPinnedBg: '" );
	} );

	it( 'describes the change in MOTION.md', () => {
		const doc = renderMotionDoc( base( changed ) );

		expect( doc ).toContain( '**Sticky header**' );
		expect( doc ).toContain( 'background transparent → #ffffff' );
		expect( doc ).toContain( 'height 96px → 64px' );
	} );

	it( 'keeps the at-rest rule when the header simply pins without changing', () => {
		const doc = renderMotionDoc( base( { sticky: true, restHeight: 80 } ) );

		expect( doc ).toContain( '**Sticky header**' );
		expect( doc ).not.toContain( 'background' );
	} );
} );
