// ---------------------------------------------------------------------------
// mobile-nav.test.ts — the rebuilt mobile navigation disclosure.
//
// The source hides its nav behind a hamburger at narrow widths. A rebuild
// without one wrapped six links over three rows at 390px — neither the source's
// layout nor a menu. The facts decide WHERE it collapses (Brando at 991px,
// Bootstrap's default at 768px), and the template implements the WAI-ARIA APG
// disclosure pattern: a real button with `aria-expanded` + `aria-controls`,
// Escape closing it and returning focus, and focus leaving the region closing
// it (WCAG 1.4.13).
//
// The stylesheet must also stay progressive: with JS off nothing may hide the
// nav, so every hide rule is gated on `data-collapsed`, which only JS sets.
// ---------------------------------------------------------------------------

import { readFile } from 'node:fs/promises';
import path from 'node:path';

import { describe, expect, it } from 'vitest';

import { FORGE_ROOT } from '../lib/scaffold.ts';

const read = ( ...parts: string[] ): Promise< string > =>
	readFile( path.join( FORGE_ROOT, 'templates', 'blog', 'src', ...parts ), 'utf8' );

describe( 'mobile nav disclosure — markup', () => {
	it( 'renders a disclosure button wired to the nav', async () => {
		const base = await read( 'layouts', 'Base.astro' );

		expect( base ).toContain( 'data-nav-shell' );
		expect( base ).toContain( '<nav id="site-nav"' );
		expect( base ).toContain( 'aria-controls="site-nav"' );
		expect( base ).toContain( 'aria-expanded="false"' );
		// The disclosure is an ADDITION: it renders only when the source had one.
		expect( base ).toContain( '{navToggle && (' );
	} );

	it( 'takes its accessible name from the source, never a hardcoded string', async () => {
		const base = await read( 'layouts', 'Base.astro' );
		expect( base ).toContain( 'navToggle.label ?? copy.nav.menu' );
	} );

	it( 'carries the measured collapse width into the shell', async () => {
		const base = await read( 'layouts', 'Base.astro' );
		// The mapping is measured, not assumed: a source that collapses on a
		// tablet must collapse there in the rebuild.
		expect( base ).toMatch( /navToggle\.visible\?\.tablet\s*\? 'tablet'/ );
		expect( base ).toMatch( /navToggle\.visible\?\.desktop\s*\? 'all'/ );
	} );
} );

describe( 'mobile nav disclosure — behaviour', () => {
	it( 'closes on Escape and returns focus to the controlling button', async () => {
		const base = await read( 'layouts', 'Base.astro' );

		expect( base ).toContain( "event.key !== 'Escape'" );
		expect( base ).toContain( 'toggle.focus()' );
	} );

	it( 'closes when focus leaves the region (WCAG 1.4.13)', async () => {
		const base = await read( 'layouts', 'Base.astro' );
		expect( base ).toContain( "'focusout'" );
		expect( base ).toMatch( /shell\.contains\(\s*next\s*\)/ );
	} );

	it( 'follows the viewport, so a resize cannot strand the menu open', async () => {
		const base = await read( 'layouts', 'Base.astro' );
		expect( base ).toMatch( /mq\.addEventListener\(\s*'change',\s*sync\s*\)/ );
	} );
} );

describe( 'mobile nav disclosure — progressive enhancement', () => {
	it( 'hides the nav only for the JS-set collapsed state', async () => {
		const css = await read( 'styles', 'global.css' );

		expect( css ).toMatch( /\.site-nav-shell\s*\{[^}]*display:\s*contents/ );
		expect( css ).toMatch( /\.site-nav-shell\[data-collapsed='true'\]\s*>\s*nav\s*\{[^}]*display:\s*none/ );
		expect( css ).toMatch( /\[data-collapsed='true'\]\[data-open='true'\]\s*>\s*nav\s*\{[^}]*display:\s*flex/ );
	} );

	it( 'never hides the nav inside a media query', async () => {
		// JS-off must keep the nav reachable at every width. A media-query hide
		// would make the nav unreachable when the script does not run.
		const css = await read( 'styles', 'global.css' );
		const mediaBlocks = [ ...css.matchAll( /@media[^{]*\{([\s\S]*?)\n\}/g ) ].map( ( match ) => match[ 1 ] );
		const navHide = mediaBlocks.filter( ( block ) => /\.site-nav-shell[^{]*nav[^{]*\{[^}]*display:\s*none/.test( block ) );

		expect( navHide ).toEqual( [] );
	} );

	it( 'gives the control a comfortable pointer target', async () => {
		const css = await read( 'styles', 'global.css' );
		// WCAG 2.5.8 needs 24×24 as a floor; the source's own toggle is 44×44.
		expect( css ).toMatch( /\.site-nav-shell\[data-collapsed='true'\]\s*>\s*\.site-nav-toggle\s*\{[^}]*min-width:\s*2\.75rem[^}]*min-height:\s*2\.75rem/s );
	} );
} );
