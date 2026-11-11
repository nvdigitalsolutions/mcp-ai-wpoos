// ---------------------------------------------------------------------------
// bands.test.ts — the vendor-agnostic sectioner.
//
// Each fixture is the REAL front-end markup shape the builder emits (taken
// from the builders' documented output), so a regression here means the
// extractor stopped recognising that tech — the failure that made
// virtuallyjamaica.com (WPBakery + Brando) produce zero design bands.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';
import {
	BUILDER_ADAPTERS,
	extractBandsFromHtml,
	inlineBackground,
	stableBandId,
} from '../lib/bands.ts';

const page = ( body: string ) => `<!doctype html><html><head><title>t</title></head><body>${ body }</body></html>`;

const LONG =
	'We install and manage cloud WiFi networks and social hotspots across the island, with support.';

describe( 'adapter registry', () => {
	it( 'declares the builders the forge must support', () => {
		const ids = BUILDER_ADAPTERS.map( ( adapter ) => adapter.id );
		expect( ids ).toEqual(
			expect.arrayContaining( [
				'elementor',
				'wpbakery',
				'divi',
				'beaver',
				'bricks',
				'gutenberg',
				'oxygen',
				'revolution-slider',
			] )
		);
	});

	it( 'every adapter has a detect fingerprint and at least one band pattern', () => {
		for ( const adapter of BUILDER_ADAPTERS ) {
			expect( adapter.detect.length, `${ adapter.id } detect` ).toBeGreaterThan( 0 );
			expect( adapter.band.length, `${ adapter.id } band` ).toBeGreaterThan( 0 );
		}
	});
} );

describe( 'WPBakery (virtuallyjamaica.com)', () => {
	const html = page( `
		<div class="vc_row wpb_row vc_row-fluid" id="hero" style="background-image:url(https://x.test/a.jpg);padding:100px 30px">
			<div class="vc_col-sm-6 wpb_column vc_column_container"><h1>Cloud managed WiFi</h1><p>${ LONG }</p></div>
			<div class="vc_col-sm-6 wpb_column vc_column_container"><img src="/b.jpg" alt=""></div>
		</div>
		<div class="vc_row wpb_row vc_row-fluid" id="about" style="background-color:#ffffff">
			<div class="vc_col-sm-4 wpb_column vc_column_container"><p>${ LONG }</p></div>
			<div class="vc_col-sm-4 wpb_column vc_column_container"><p>${ LONG }</p></div>
			<div class="vc_col-sm-4 wpb_column vc_column_container"><p>${ LONG }</p></div>
		</div>` );

	it( 'finds both rows as bands', () => {
		const { bands, adapter } = extractBandsFromHtml( html );
		expect( adapter ).toBe( 'wpbakery' );
		expect( bands ).toHaveLength( 2 );
	} );

	it( 'reads the anchors, background and 12-column widths', () => {
		const { bands } = extractBandsFromHtml( html );
		expect( bands[ 0 ].anchor ).toBe( 'hero' );
		expect( bands[ 0 ].bgImage ).toBe( 'https://x.test/a.jpg' );
		expect( bands[ 0 ].paddingX ).toBe( '30px' );
		expect( bands[ 0 ].columns ).toEqual( [ { width: 50 }, { width: 50 } ] );
		expect( bands[ 1 ].columns?.map( ( column ) => column.width ) ).toEqual( [ 33.33, 33.33, 33.33 ] );
	} );
} );

describe( 'a column-carried anchor is promoted to its band', () => {
	// virtuallyjamaica's `#wifi` sits on the inner `wpb_column`, not the row. The
	// BOX pass has always promoted it (band-geometry.md §3); the band extractor
	// did not, so `analyze --design` dropped the anchor while the nav still
	// linked `#wifi` (2026-11-11).
	const html = page( `
		<div class="vc_row wpb_row vc_row-fluid" style="background-color:#ededed">
			<div class="vc_col-sm-6 wpb_column vc_column_container" id="wifi"><h2>WiFi Marketing</h2><p>${ LONG }</p></div>
			<div class="vc_col-sm-6 wpb_column vc_column_container"><img src="/b.jpg" alt=""></div>
		</div>
		<div class="vc_row wpb_row vc_row-fluid" style="background-color:#ededed">
			<div class="vc_col-sm-12 wpb_column vc_column_container">
				<ul><li id="accordion-one-link-1"><a href="#">Panel</a></li></ul>
				<p>${ LONG }</p>
			</div>
		</div>` );

	it( 'promotes an id on a wpb_column to the band', () => {
		const { bands } = extractBandsFromHtml( html );
		expect( bands[ 0 ].anchor ).toBe( 'wifi' );
	} );

	it( 'never promotes a panel-internal id', () => {
		// `accordion-one-link-1` passes the slug shape but is not a column, so it
		// must not become a nav target.
		const { bands } = extractBandsFromHtml( html );
		expect( bands[ 1 ].anchor ).toBeUndefined();
	} );

	it( 'keeps the band id stable whether the anchor rides the row or a column', () => {
		// The id seeds on the node, never the anchor, and bandFacts/geometry are
		// keyed by it — promoting an anchor must not renumber the band.
		const rowCarried = page(
			`<div class="vc_row wpb_row vc_row-fluid" id="wifi"><div class="wpb_column"><p>${ LONG }</p></div></div>`
		);
		const columnCarried = page(
			`<div class="vc_row wpb_row vc_row-fluid"><div class="wpb_column" id="wifi"><p>${ LONG }</p></div></div>`
		);
		expect( extractBandsFromHtml( rowCarried ).bands[ 0 ].id ).toBe(
			extractBandsFromHtml( columnCarried ).bands[ 0 ].id
		);
	} );
} );

describe( 'Divi', () => {
	const html = page( `
		<div class="et_pb_section et_pb_section_0 et_pb_with_background" style="background-image:url(/hero.jpg)">
			<div class="et_pb_row et_pb_row_0">
				<div class="et_pb_column et_pb_column_2_3"><h2>Enterprise WiFi</h2><p>${ LONG }</p></div>
				<div class="et_pb_column et_pb_column_1_3"><p>${ LONG }</p></div>
			</div>
		</div>
		<div class="et_pb_section et_pb_section_1" style="background-color:#f5f5f5">
			<div class="et_pb_row">
				<div class="et_pb_column et_pb_column_1_2"><p>${ LONG }</p></div>
				<div class="et_pb_column et_pb_column_1_2"><p>${ LONG }</p></div>
			</div>
		</div>` );

	it( 'finds sections (not rows) as bands', () => {
		const { bands, adapter } = extractBandsFromHtml( html );
		expect( adapter ).toBe( 'divi' );
		expect( bands ).toHaveLength( 2 );
	} );

	it( 'reads Divi fraction widths', () => {
		const { bands } = extractBandsFromHtml( html );
		expect( bands[ 0 ].columns?.map( ( column ) => column.width ) ).toEqual( [ 66.67, 33.33 ] );
		expect( bands[ 1 ].bgColor ).toBe( '#f5f5f5' );
	} );
} );

describe( 'columns survive a theme that removes the row wrapper (Brando)', () => {
	// virtuallyjamaica.com: 12 `wpb_column`s, ZERO `vc_row`s. The band comes
	// from the visual layer; the columns must still be measured, and the LG
	// breakpoint must win over the SM one in the same class list.
	const html = page( `
		<div class="parent-section">
			<div class="band" style="background-color:#ededed" id="about">
				<div class="wpb_column vc_col-sm-12 vc_col-lg-6"><h2>About</h2><p>${ LONG }</p></div>
				<div class="wpb_column vc_col-sm-12 vc_col-lg-6"><img src="/a.jpg" alt=""></div>
			</div>
		</div>` );

	it( 'derives 50/50 from vc_col-lg-6, not 12/12 from vc_col-sm-12', () => {
		const { bands } = extractBandsFromHtml( html );
		const about = bands.find( ( band ) => band.anchor === 'about' );
		expect( about ).toBeDefined();
		expect( about?.columns?.map( ( column ) => column.width ) ).toEqual( [ 50, 50 ] );
	} );
} );

describe( 'Flatsome (UX Builder)', () => {
	const html = page( `
		<div class="section section-content" id="hero" style="background-color:#1a1a1a">
			<div class="row">
				<div class="col large-6 medium-6 small-12"><h1>Cloud WiFi</h1><p>${ LONG }</p></div>
				<div class="col large-6 medium-6 small-12"><img src="/a.jpg" alt=""></div>
			</div>
		</div>
		<div class="section" id="about">
			<div class="row">
				<div class="col large-4 medium-12 small-12"><p>${ LONG }</p></div>
				<div class="col large-4 medium-12 small-12"><p>${ LONG }</p></div>
				<div class="col large-4 medium-12 small-12"><p>${ LONG }</p></div>
			</div>
		</div>` );

	it( 'finds sections and reads the large-N column widths', () => {
		const { bands, adapter } = extractBandsFromHtml( html );
		expect( adapter ).toBe( 'flatsome' );
		expect( bands ).toHaveLength( 2 );
		expect( bands[ 0 ].anchor ).toBe( 'hero' );
		expect( bands[ 0 ].columns?.map( ( column ) => column.width ) ).toEqual( [ 50, 50 ] );
		expect( bands[ 1 ].columns?.map( ( column ) => column.width ) ).toEqual( [ 33.33, 33.33, 33.33 ] );
	} );

	it( 'stays inert without a Flatsome fingerprint (generic .section markup)', () => {
		// Same class names, no ux-builder/section-content/flatsome marker: the
		// adapter must NOT claim the page — semantic landmarks get it instead.
		const generic = page( `<main><section id="a"><p>${ LONG }</p></section><section id="b"><p>${ LONG }</p></section></main>` );
		const { adapter } = extractBandsFromHtml( generic );
		expect( adapter ).toBe( 'semantic' );
	} );
} );

describe( 'Slider Revolution', () => {
	const html = page( `
		<div class="rev_slider_wrapper fullwidthbanner-container" style="background-color:#04132b">
			<div id="rev_slider_1_1" class="rev_slider fullwidthabanner" data-version="6.6">
				<ul><li data-transition="fade"><h1>Social hotspots</h1></li></ul>
			</div>
		</div>
		<section><h2>Services</h2><p>${ LONG }</p></section>` );

	it( 'treats a slider as ONE opaque band and does not descend for columns', () => {
		const { bands, adapter } = extractBandsFromHtml( html, { only: [ 'revolution-slider' ] } );
		expect( adapter ).toBe( 'revolution-slider' );
		expect( bands.length ).toBe( 1 );
		expect( bands[ 0 ].columns ).toBeUndefined();
		expect( bands[ 0 ].bgColor ).toBe( '#04132b' );
	} );

	it( 'wins over a layout adapter when both fingerprints are present', () => {
		// A Divi site with a Rev Slider hero: the slider adapter is declared later,
		// so the section adapter wins the whole page (documented behaviour), but
		// `only` lets an operator prefer the slider layer for hero extraction.
		const mixed = page( `
			<div class="rev_slider_wrapper"><div class="rev_slider"><p>${ LONG }</p></div></div>
			<div class="et_pb_section"><div class="et_pb_row"><div class="et_pb_column et_pb_column_1_1"><p>${ LONG }</p></div></div></div>` );
		const { adapter } = extractBandsFromHtml( mixed );
		expect( adapter ).toBe( 'divi' );
	} );
} );

describe( 'other builders', () => {
	it( 'Gutenberg columns', () => {
		const { bands, adapter } = extractBandsFromHtml(
			page( `
			<div class="wp-block-group is-layout-constrained" style="background-color:#ffffff">
				<div class="wp-block-columns">
					<div class="wp-block-column"><p>${ LONG }</p></div>
					<div class="wp-block-column"><p>${ LONG }</p></div>
				</div>
			</div>` )
		);
		expect( adapter ).toBe( 'gutenberg' );
		expect( bands ).toHaveLength( 1 );
		expect( bands[ 0 ].columns ).toHaveLength( 2 );
	} );

	it( 'Bricks sections with inferred even columns', () => {
		const { bands, adapter } = extractBandsFromHtml(
			page( `
			<section class="brxe-section">
				<div class="brxe-container">
					<div class="brxe-block"><p>${ LONG }</p></div>
					<div class="brxe-block"><p>${ LONG }</p></div>
				</div>
			</section>` )
		);
		expect( adapter ).toBe( 'bricks' );
		expect( bands ).toHaveLength( 1 );
		expect( bands[ 0 ].columns?.map( ( column ) => column.width ) ).toEqual( [ 50, 50 ] );
	} );
} );

describe( 'fallbacks', () => {
	it( 'uses semantic landmarks when no builder matches', () => {
		const { bands, adapter } = extractBandsFromHtml(
			page( `<main><section id="services"><h2>Services</h2><p>${ LONG }</p></section>
			<section id="contact"><h2>Contact</h2><p>${ LONG }</p></section></main>` )
		);
		expect( adapter ).toBe( 'semantic' );
		expect( bands.map( ( band ) => band.anchor ) ).toEqual( [ 'services', 'contact' ] );
	} );

	it( 'falls back to VIPS-lite visual boundaries (background changes)', () => {
		const { bands, adapter } = extractBandsFromHtml(
			page( `<div><div style="background-color:#000"><p>${ LONG }</p></div>
			<div style="background-color:#fff"><p>${ LONG }</p></div></div>` )
		);
		expect( adapter ).toBe( 'visual' );
		expect( bands ).toHaveLength( 2 );
	} );

	it( 'returns nothing for a genuinely structureless page', () => {
		const { bands } = extractBandsFromHtml( page( `<div><p>hello</p></div>` ) );
		expect( bands ).toEqual( [] );
	} );

	it( 'strict mode skips the fallbacks', () => {
		const { bands } = extractBandsFromHtml(
			page( `<main><section><p>${ LONG }</p></section><section><p>${ LONG }</p></section></main>` ),
			{ strict: true }
		);
		expect( bands ).toEqual( [] );
	} );
} );

describe( 'determinism and ids', () => {
	it( 'produces the same ids for the same document', () => {
		const html = page(
			`<section class="vc_row" style="background:#000"><div class="vc_col-sm-6"><p>${ LONG }</p></div><div class="vc_col-sm-6"><p>${ LONG }</p></div></section>`
		);
		const a = extractBandsFromHtml( html ).bands;
		const b = extractBandsFromHtml( html ).bands;
		expect( a.map( ( band ) => band.id ) ).toEqual( b.map( ( band ) => band.id ) );
	} );

	it( 'ids are 7 lowercase hex chars (never a vendor id)', () => {
		expect( stableBandId( 'anything at all' ) ).toMatch( /^[0-9a-f]{7}$/ );
	} );

	it( 'reads gradients and plain backgrounds', () => {
		const node = { type: 'tag', name: 'div', attribs: { style: 'background-image:linear-gradient(180deg,#fff 0%,#000 100%)' } };
		expect( inlineBackground( node ).bgGradient ).toContain( 'linear-gradient' );

		const plain = { type: 'tag', name: 'div', attribs: { style: 'background:#04132b url(/x.jpg)' } };
		expect( inlineBackground( plain ).bgColor ).toBe( '#04132b' );
	} );

	it( 'reads an image-only shorthand as a band background', () => {
		// `background: url(...)` carries no colour — before this, the band read as
		// "no background" and dropped out of the visual band set.
		const node = {
			type: 'tag',
			name: 'section',
			attribs: { id: 'contact', style: 'background: url(/contact-us.jpg) no-repeat;' },
		};
		const bg = inlineBackground( node );
		expect( bg.bgImage ).toBe( '/contact-us.jpg' );
		expect( bg.bgColor ).toBeUndefined();
	} );

	it( 'keeps an image-backed section in the band set (virtuallyjamaica #contact)', () => {
		const html = page(
			`<section id="contact" style="background: url(/c.jpg) no-repeat;"><h3>Contact Us</h3><p>${ LONG }</p></section>` +
				`<section id="about" style="background-color:#ededed"><p>${ LONG }</p></section>`
		);
		const contact = extractBandsFromHtml( html ).bands.find( ( band ) => band.anchor === 'contact' );
		expect( contact?.bgImage ).toBe( '/c.jpg' );
	} );
} );
