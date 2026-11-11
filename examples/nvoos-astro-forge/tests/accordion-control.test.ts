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
		const control = blocks( await templateCss() ).filter(
			( block ) =>
				block.sel.includes( '.panel-group' ) &&
				block.sel.includes( '.panel-heading a' ) &&
				! /\bi$/.test( block.sel )
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
