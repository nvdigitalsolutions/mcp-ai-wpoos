// ---------------------------------------------------------------------------
// title-tag.test.ts — the heading level a band title renders at.
//
// The level comes from the measured band facts (`SectionTheme.type.titleLevel`,
// emitted by the scaffold from `bandFacts[id].headings`). Components render
// `<h2>` only as the fallback: virtuallyjamaica's contact band carries a single
// `<h3>` in the source and was promoted to `<h2>` by every title component.
// An `h1` maps DOWN to `h2` — the page article owns the single h1.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { titleTag } from '../templates/blog/src/lib/title-tag.ts';

describe( 'titleTag', () => {
	it( 'keeps the source level for h3–h6', () => {
		expect( titleTag( { titleLevel: 'h3' } ) ).toBe( 'h3' );
		expect( titleTag( { titleLevel: 'h4' } ) ).toBe( 'h4' );
		expect( titleTag( { titleLevel: 'h5' } ) ).toBe( 'h5' );
		expect( titleTag( { titleLevel: 'h6' } ) ).toBe( 'h6' );
	} );

	it( 'maps a source h1 down to h2 (the article owns the single h1)', () => {
		expect( titleTag( { titleLevel: 'h1' } ) ).toBe( 'h2' );
	} );

	it( 'falls back to h2 without a fact', () => {
		expect( titleTag() ).toBe( 'h2' );
		expect( titleTag( {} ) ).toBe( 'h2' );
		expect( titleTag( { titleLevel: 'bogus' } ) ).toBe( 'h2' );
	} );
} );
