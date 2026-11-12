// ---------------------------------------------------------------------------
// anchor.test.ts — where a band's in-page anchor id lives.
//
// A builder sometimes puts the anchor INSIDE the band (Brando: `id="wifi"` on a
// half-column), and the harvested markup keeps it. Emitting the same id on the
// wrapper duplicates it, so `#wifi` resolves to the band instead of the panel —
// which is what made the colour gate compare a band's `#ededed` against the
// source's red `#dd3333` panel (virtuallyjamaica, 2026-11-12).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { wrapperAnchor } from '../templates/blog/src/lib/anchor.ts';

describe( 'wrapperAnchor', () => {
	it( 'emits the anchor when the band markup does not own it', () => {
		expect( wrapperAnchor( 'services', '<section><h2>Why</h2></section>' ) ).toBe( 'services' );
		expect( wrapperAnchor( 'services', undefined ) ).toBe( 'services' );
	} );

	it( 'yields to the id the source already placed inside the band', () => {
		expect( wrapperAnchor( 'wifi', '<div class="row"><div id="wifi" class="col">…</div></div>' ) ).toBeUndefined();
	} );

	it( 'matches single-quoted and unquoted ids', () => {
		expect( wrapperAnchor( 'wifi', "<div id='wifi'></div>" ) ).toBeUndefined();
		expect( wrapperAnchor( 'wifi', '<div id=wifi></div>' ) ).toBeUndefined();
	} );

	it( 'does not match an id that merely starts with the anchor', () => {
		expect( wrapperAnchor( 'wifi', '<div id="wifi-panel"></div>' ) ).toBe( 'wifi' );
		expect( wrapperAnchor( 'wifi', '<div id="my-wifi"></div>' ) ).toBe( 'wifi' );
	} );

	it( 'ignores an id written in text content, not as an attribute', () => {
		expect( wrapperAnchor( 'wifi', '<p>the id="wifi" is mentioned here</p>' ) ).toBe( 'wifi' );
	} );

	it( 'has nothing to emit without an anchor', () => {
		expect( wrapperAnchor( undefined, '<div></div>' ) ).toBeUndefined();
	} );
} );
