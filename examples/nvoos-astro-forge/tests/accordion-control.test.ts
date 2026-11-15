// ---------------------------------------------------------------------------
// accordion-control.test.ts — the rebuilt builder accordion's row styling.
//
// Two defects shipped on virtuallyjamaica's features band, both visible only
// on interaction:
//
//   1. Hovering a row moved the ICON onto its own line. The control rule shared
//      one declaration block with `a:hover i`, so `display:block` landed on the
//      <i> as soon as the pointer entered: a block icon takes the content
//      width and the title grew from 50px to 64px mid-hover.
//   2. The title rendered in the template accent (#fe6c61) where the source is
//      #262626. The colour had been hard-wired because the builder's inline
//      `style` does not survive sanitisation; the measured heading fact now
//      carries the source's own ink, so the control must inherit instead.
//
// These are CSS invariants, checked against the shipped template sheet.
// ---------------------------------------------------------------------------

import { readFile } from 'node:fs/promises';
import path from 'node:path';

import { describe, expect, it } from 'vitest';

import { FORGE_ROOT } from '../lib/scaffold.ts';

async function templateCss(): Promise<string> {
	return readFile( path.join( FORGE_ROOT, 'templates', 'blog', 'src', 'styles', 'global.css' ), 'utf8' );
}

/** Every `selector { declarations }` pair, including rules nested in at-rules. */
function blocks( css: string ): Array< { sel: string; decl: string } > {
	return [ ...css.matchAll( /([^{}]*)\{([^{}]*)\}/g ) ]
		.map( ( match ) => ( { sel: match[ 1 ].replace( /\s+/g, ' ' ).trim(), decl: match[ 2 ] } ) )
		// An at-rule's opening brace reads as a selector with no declarations of
		// its own; its children are matched separately.
		.filter( ( block ) => ! block.sel.startsWith( '@' ) && block.sel.length > 0 );
}

describe( 'builder accordion — control row', () => {
	it( 'never flips the ICON to a block, in any state', async () => {
		const rows = blocks( await templateCss() ).filter(
			( block ) => block.sel.includes( '.panel-group' ) && /\bi$/.test( block.sel )
		);

		expect( rows.length ).toBeGreaterThan( 0 );
		for ( const row of rows ) {
			expect( row.sel + ' ⟶ ' + row.decl ).not.toMatch( /display:\s*block/ );
		}
	} );

	it( 'states the icon display in every state, so hover cannot change it', async () => {
		const rows = blocks( await templateCss() ).filter(
			( block ) => block.sel.includes( '.panel-group' ) && /\bi$/.test( block.sel )
		);

		// The hover variant must be named explicitly — a rule that covers only the
		// resting state leaves the pointer state free to re-introduce the flip.
		const selectors = rows.map( ( row ) => row.sel ).join( ',' );
		expect( selectors ).toMatch( /a:hover i/ );
		expect( rows.some( ( row ) => /display:\s*inline-block/.test( row.decl ) ) ).toBe( true );
	} );

	it( 'inherits its ink instead of hard-wiring the accent', async () => {
		// The rule ON the anchor — not a rule on its icon. `! /\bi$/.test()` used to
		// stand in for that, but it also let the marker's `i[class*='fa-']:empty`
		// through; the selector must end at the control itself.
		const control = blocks( await templateCss() ).filter(
			( block ) =>
				block.sel.includes( '.panel-group' ) &&
				block.sel.includes( '.panel-heading a' ) &&
				/\ba(?::[-a-z]+)?$/.test( block.sel )
		);

		expect( control.length ).toBeGreaterThan( 0 );
		for ( const block of control ) {
			expect( block.decl ).not.toContain( '--forge-accent' );
			expect( block.decl ).toMatch( /color:\s*inherit/ );
		}
	} );
} );

describe( 'measured ink plumbing', () => {
	it( 'lets a band title wear the colour its heading fact carries', async () => {
		const css = await templateCss();

		expect( css ).toMatch( /\.ds-title\s*\{[^}]*color:\s*var\(--ds-title-color/s );
	} );
} );

// ---------------------------------------------------------------------------
// The MARKER: one glyph, not two.
//
// The vendor's expander is an EMPTY `<i class="fa-solid fa-plus">` and this
// sheet draws the +/− itself, because the builder's JS (which swapped fa-plus
// for fa-minus) is never shipped. A site whose chrome loads Font Awesome —
// virtuallyjamaica's does, for the footer socials — resolves that empty `i` to
// a REAL glyph, so the row painted the font's plus AND the drawn marker beside
// it: "+ +" on all 8 rows. The generic `i[class*='fa-']:empty` guard was meant
// to stop a bare box but is (0,4,1) and loses to the control's own
// `display:inline-block` (0,4,2), which is stated for rest AND hover.
//
// This is a SPECIFICITY invariant, not a marker-text one: whatever hides the
// empty marker must out-rank every rule that paints the row's icon.
// ---------------------------------------------------------------------------

/** The cascade weight of one selector, as the [ id, class, element ] triple. */
function specificity( selector: string ): number[] {
	const ids = ( selector.match( /#[-\w]+/g ) ?? [] ).length;
	const rest = selector.replace( /#[-\w]+/g, '' );
	const classes =
		( rest.match( /\.[-\w]+/g ) ?? [] ).length +
		( rest.match( /\[[^\]]*\]/g ) ?? [] ).length +
		( rest.match( /(?<!:):[-a-z]+/gi ) ?? [] ).length;
	const elements =
		( rest.match( /::[-a-z]+/gi ) ?? [] ).length +
		( rest.replace( /\.[-\w]+|\[[^\]]*\]|::?[-a-z]+/gi, ' ' ).match( /[a-z][-\w]*/gi ) ?? [] ).length;
	return [ ids, classes, elements ];
}

/** True when `a` wins the cascade against `b` from any source position. */
function outranks( a: number[], b: number[] ): boolean {
	for ( let i = 0; i < a.length; i += 1 ) {
		if ( a[ i ] !== b[ i ] ) return a[ i ] > b[ i ];
	}
	return true;
}

function worst( block: { sel: string } ): number[] {
	return block.sel
		.split( ',' )
		.map( ( one ) => specificity( one.trim() ) )
		.reduce( ( a, b ) => ( outranks( b, a ) ? b : a ) );
}

describe( 'builder accordion — the expander marker', () => {
	it( "hides the vendor's empty fa-* icon, and only that icon", async () => {
		const rows = blocks( await templateCss() );
		const hide = rows.filter(
			( block ) =>
				block.sel.includes( '.panel-group' ) &&
				block.sel.includes( '[class*=\'fa-\']' ) &&
				block.sel.includes( ':empty' ) &&
				/display:\s*none/.test( block.decl )
		);

		expect( hide.length ).toBeGreaterThan( 0 );
		// The LEFT glyph is `<i class="icon-profile-male">` — empty too, but its
		// font IS shipped, so a bare `:empty` hide would delete the source's icon.
		for ( const block of hide ) {
			expect( block.sel ).not.toMatch( /\[class\*='icon-'\]/ );
		}
	} );

	it( 'out-ranks every rule that paints the row icon, in every state', async () => {
		const rows = blocks( await templateCss() );
		const hide = rows.filter(
			( block ) =>
				block.sel.includes( '.panel-group' ) &&
				block.sel.includes( '[class*=\'fa-\']' ) &&
				block.sel.includes( ':empty' )
		);
		const paints = rows.filter(
			( block ) =>
				block.sel.includes( '.panel-group' ) &&
				/\bi$/.test( block.sel ) &&
				/display:\s*(?!none)\S+/.test( block.decl )
		);

		expect( hide.length ).toBeGreaterThan( 0 );
		expect( paints.length ).toBeGreaterThan( 0 );
		for ( const paint of paints ) {
			expect(
				outranks( worst( hide[ 0 ] ), worst( paint ) ),
				`${ hide[ 0 ].sel } must win against ${ paint.sel }`
			).toBe( true );
		}
	} );
} );
