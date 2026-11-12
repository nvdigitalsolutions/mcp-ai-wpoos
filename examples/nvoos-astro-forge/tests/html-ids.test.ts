// ---------------------------------------------------------------------------
// html-ids.test.ts — id uniqueness in the BUILT page.
//
// The band wrapper emitted an anchor id the band's own markup already owned, so
// `#wifi` resolved to the wrapper instead of the red panel: in-page links landed
// on the wrong element and the colour gate compared two different boxes
// (virtuallyjamaica, 2026-11-12). The wrapper no longer duplicates an id; this is
// the gate that proves it on the output.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { duplicateIds, idsIn, inPageTargets } from '../lib/html-ids.ts';

describe( 'idsIn', () => {
	it( 'reads every quoting style a builder emits', () => {
		const html = '<div id="a"></div><p id=\'b\'></p><span id=c></span>';
		expect( idsIn( html ) ).toEqual( [ 'a', 'b', 'c' ] );
	} );

	it( 'ignores ids written in text, comments, scripts and styles', () => {
		const html = [
			'<p>the id="fake" is prose</p>',
			'<!-- <div id="commented"></div> -->',
			'<script>document.getElementById("scripted")</script>',
			'<style>#styled{color:red}</style>',
			'<div id="real"></div>',
		].join( '' );

		expect( idsIn( html ) ).toEqual( [ 'real' ] );
	} );
} );

describe( 'duplicateIds', () => {
	it( 'counts repeats in first-appearance order', () => {
		const html = '<div id="wifi"></div><div id="about"></div><div id="wifi"></div>';
		expect( duplicateIds( html ) ).toEqual( [ { id: 'wifi', count: 2 } ] );
	} );

	it( 'reports nothing when every id is unique', () => {
		expect( duplicateIds( '<div id="a"></div><div id="b"></div>' ) ).toEqual( [] );
	} );
} );

describe( 'inPageTargets', () => {
	it( 'collects same-page hashes in every form a nav emits', () => {
		const html = [
			'<a href="#about">About</a>',
			'<a href="/#wifi">WiFi</a>',
			'<a href="https://example.com/#services">Services</a>',
			'<a href="index.html#contact">Contact</a>',
		].join( '' );

		expect( [ ...inPageTargets( html ) ].sort() ).toEqual( [ 'about', 'contact', 'services', 'wifi' ] );
	} );

	it( 'ignores links that leave the page, and the `#top` placeholder', () => {
		const html = [
			'<a href="https://example.com/">Home</a>',
			'<a href="/blog/post/">Post</a>',
			'<a href="#top">Top</a>',
			'<a href="mailto:hi@example.com">Mail</a>',
		].join( '' );

		expect( inPageTargets( html ).size ).toBe( 0 );
	} );
} );
