import { describe, expect, it } from 'vitest';

import { auditBands, builtBandBlocks, formatBandReport } from '../lib/band-audit.ts';
import type { ForgeSection } from '../lib/types.ts';

const WRAP = ( id: string, style: string, inner: string, kind = 'text' ) =>
	`<div data-section="${ kind }" data-section-id="${ id }" class="ds-section-wrap" style="${ style }"><div class="post-body ds-section">${ inner }</div></div>`;

const SECTIONS: ForgeSection[] = [
	{ id: 'aaa111', anchor: 'home', bgColor: '#dd3333' },
	{ id: 'bbb222', anchor: 'about', bgColor: '#ededed' },
	{ id: 'ccc333', anchor: 'contact', bgImage: 'https://cms.test/c.jpg' },
];

describe( 'builtBandBlocks', () => {
	it( 'reads wrappers in document order with their own markup', () => {
		const html =
			WRAP( 'aaa111', 'background:#dd3333', '<h1>Hero</h1>' ) +
			WRAP( 'bbb222', 'background:#ededed', '<h2>About</h2>' );
		const bands = builtBandBlocks( html );
		expect( bands ).toHaveLength( 2 );
		expect( bands[ 0 ].id ).toBe( 'aaa111' );
		expect( bands[ 0 ].slice ).toContain( 'Hero' );
		// The first band's slice ends at the second wrapper.
		expect( bands[ 0 ].slice ).not.toContain( 'About' );
	} );
} );

describe( 'auditBands — per-band gate', () => {
	it( 'passes every band when each renders its surface and content', () => {
		const html =
			WRAP( 'aaa111', 'background:#dd3333', '<h1>A real hero headline</h1>' ) +
			WRAP( 'bbb222', 'background:#ededed', '<h2>About this business</h2>' ) +
			WRAP( 'ccc333', 'background-image:url(https://cms.test/c.jpg)', '' );
		const report = auditBands( { sections: SECTIONS, builtHtml: html } );
		expect( report.summary.fail ).toBe( 0 );
		expect( report.bands.map( ( band ) => band.status ) ).toEqual( [ 'pass', 'pass', 'pass' ] );
	} );

	it( 'fails a dropped band and skips the checks that need it', () => {
		const html = WRAP( 'aaa111', 'background:#dd3333', '<h1>A real hero headline</h1>' );
		const report = auditBands( { sections: SECTIONS, builtHtml: html } );
		const about = report.bands[ 1 ];
		expect( about.presence.status ).toBe( 'fail' );
		expect( about.surface.status ).toBe( 'skip' );
		expect( about.status ).toBe( 'fail' );
		expect( about.notes[ 0 ] ).toContain( 'missing from the build' );
	} );

	it( 'fails a band whose surface does not match the baked fact', () => {
		const html =
			WRAP( 'aaa111', 'background:#ffffff', '<h1>A real hero headline</h1>' ) +
			WRAP( 'bbb222', 'background:#ededed', '<h2>About this business</h2>' ) +
			WRAP( 'ccc333', 'background-image:url(/media/c.jpg)', '' );
		const report = auditBands( { sections: SECTIONS, builtHtml: html } );
		expect( report.bands[ 0 ].surface.status ).toBe( 'fail' );
		expect( report.bands[ 0 ].surface.detail ).toContain( '#dd3333' );
	} );

	it( 'accepts a localized photo in place of the source URL', () => {
		const html = WRAP( 'ccc333', 'background-image:url(/media/hash-c.jpg)', '' );
		const report = auditBands( {
			sections: [ SECTIONS[ 2 ] ],
			builtHtml: html,
			localizedMedia: new Map( [ [ 'https://cms.test/c.jpg', '/media/hash-c.jpg' ] ] ),
		} );
		expect( report.bands[ 0 ].surface.status ).toBe( 'pass' );
	} );

	it( 'fails an empty band that has no photo surface', () => {
		const html =
			WRAP( 'aaa111', 'background:#dd3333', '' ) +
			WRAP( 'bbb222', 'background:#ededed', '<h2>About this business</h2>' ) +
			WRAP( 'ccc333', 'background-image:url(https://cms.test/c.jpg)', '' );
		const report = auditBands( { sections: SECTIONS, builtHtml: html } );
		expect( report.bands[ 0 ].content.status ).toBe( 'fail' );
		// A photo-only band is legitimately empty.
		expect( report.bands[ 2 ].content.status ).toBe( 'pass' );
	} );

	it( 'maps bands POSITIONALLY for an outline source (no ids in the DOM)', () => {
		const html =
			WRAP( 'band-0', 'background:#dd3333', '<h1>A real hero headline</h1>' ) +
			WRAP( 'band-1', 'background:#ededed', '<h2>About this business</h2>' ) +
			WRAP( 'band-2', 'background-image:url(https://cms.test/c.jpg)', '' );
		const outline: ForgeSection[] = [
			{ id: 'srconly-1', bgColor: '#dd3333' },
			{ id: 'srconly-2', bgColor: '#ededed' },
			{ id: 'srconly-3', bgImage: 'https://cms.test/c.jpg' },
		];
		const report = auditBands( { sections: outline, builtHtml: html, positional: true } );
		expect( report.positional ).toBe( true );
		expect( report.summary.fail ).toBe( 0 );
	} );

	it( 'compares a band against its own text baseline', () => {
		const html =
			WRAP( 'aaa111', 'background:#dd3333', '<h1>We specialize in custom solutions</h1>' ) +
			WRAP( 'bbb222', 'background:#ededed', '<h2>Unrelated replacement copy</h2>' ) +
			WRAP( 'ccc333', 'background-image:url(https://cms.test/c.jpg)', '' );
		const report = auditBands( {
			sections: SECTIONS,
			builtHtml: html,
			text: {
				capturedAt: 'now',
				url: 'https://src.test',
				page: '',
				sections: [
					{ id: 'aaa111', text: 'we specialize in custom solutions' },
					{ id: 'bbb222', text: 'the best way to promote your business' },
				],
				skipped: [],
			},
		} );
		expect( report.bands[ 0 ].text.status ).toBe( 'pass' );
		expect( report.bands[ 1 ].text.status ).toBe( 'fail' );
		expect( report.bands[ 2 ].text.status ).toBe( 'skip' );
	} );

	it( 'skips (never passes) a check it cannot evaluate', () => {
		const html = WRAP( 'aaa111', 'background:#dd3333', '<h1>A real hero headline</h1>' );
		const report = auditBands( { sections: [ { id: 'aaa111' } ], builtHtml: html } );
		expect( report.bands[ 0 ].surface.status ).toBe( 'skip' );
		expect( report.bands[ 0 ].text.status ).toBe( 'skip' );
		expect( report.bands[ 0 ].geometry.status ).toBe( 'skip' );
		expect( report.bands[ 0 ].status ).toBe( 'pass' );
	} );
} );

describe( 'formatBandReport', () => {
	it( 'prints one row per band plus a totals line', () => {
		const html = WRAP( 'aaa111', 'background:#dd3333', '<h1>A real hero headline</h1>' );
		const text = formatBandReport( auditBands( { sections: SECTIONS, builtHtml: html } ) );
		expect( text.split( '\n' ) ).toHaveLength( SECTIONS.length + 2 );
		expect( text ).toContain( '#home' );
		expect( text ).toMatch( /2 fail/ );
	} );
} );

describe( 'surface facts emitted in scoped CSS', () => {
	// A per-device value cannot be inlined (an inline declaration outranks the
	// media queries carrying the tablet/mobile values), so the build emits
	// `[data-section-id="<id>"]{padding:…}` instead. jayainc's `059421c` failed
	// the surface check on exactly this: the rule was in the page, the audit
	// only read the wrap's `style` attribute.
	const PER_DEVICE: ForgeSection[] = [
		{
			id: 'ddd444',
			bgGradient: 'linear-gradient(137deg, #081a43 0%, #071230 100%)',
			padding: { desktop: '125px 0px 125px 0px', tablet: '100px 0px 100px 0px', mobile: '100px 0px 100px 0px' },
		},
	];

	it( 'passes when the per-device padding reaches the page as scoped CSS', () => {
		const html =
			`<style>[data-section-id="ddd444"]{padding:125px 0px 125px 0px}` +
			`@media (max-width: 1024px){[data-section-id="ddd444"]{padding:100px 0px 100px 0px}}</style>` +
			WRAP(
				'ddd444',
				'background:linear-gradient(137deg, #081a43 0%, #071230 100%)',
				'<h2>A real headline for the band</h2>'
			);
		const report = auditBands( { sections: PER_DEVICE, builtHtml: html } );
		expect( report.bands[ 0 ].surface.status ).toBe( 'pass' );
		expect( report.summary.fail ).toBe( 0 );
	} );

	it( 'still fails when neither channel carries the padding', () => {
		const html = WRAP(
			'ddd444',
			'background:linear-gradient(137deg, #081a43 0%, #071230 100%)',
			'<h2>A real headline for the band</h2>'
		);
		const report = auditBands( { sections: PER_DEVICE, builtHtml: html } );
		expect( report.bands[ 0 ].surface.status ).toBe( 'fail' );
		expect( report.bands[ 0 ].surface.detail ).toContain( 'padding none' );
	} );

	it( 'takes the desktop value, not a media-scoped override', () => {
		// First occurrence per (id, property) wins — the emitter writes desktop
		// first, and the mobile value must never be read as the desktop one.
		const html =
			`<style>@media (max-width: 767px){[data-section-id="ddd444"]{padding:1px 1px 1px 1px}}` +
			`[data-section-id="ddd444"]{padding:125px 0px 125px 0px}</style>` +
			WRAP(
				'ddd444',
				'background:linear-gradient(137deg, #081a43 0%, #071230 100%)',
				'<h2>A real headline for the band</h2>'
			);
		const report = auditBands( { sections: PER_DEVICE, builtHtml: html } );
		expect( report.bands[ 0 ].surface.status ).toBe( 'pass' );
	} );
} );

describe( 'spacer bands', () => {
	// jayainc's `dff3f1a` is a genuinely empty grey strip in the source
	// (verified: 0 text, 0 images), so an empty rebuild is CORRECT. Without the
	// pinned fact the audit cannot tell that from lost copy and fails it.
	const SPACER: ForgeSection[] = [ { id: 'eee555', bgColor: '#e5e5e5', spacer: true } ];
	const NOT_SPACER: ForgeSection[] = [ { id: 'eee555', bgColor: '#e5e5e5' } ];
	const html = WRAP( 'eee555', 'background:#e5e5e5', '' );

	it( 'passes an empty band the profile declares a spacer', () => {
		const report = auditBands( { sections: SPACER, builtHtml: html } );
		expect( report.bands[ 0 ].content.status ).toBe( 'pass' );
		expect( report.bands[ 0 ].content.detail ).toContain( 'spacer' );
		expect( report.summary.fail ).toBe( 0 );
	} );

	it( 'still fails an empty band with no spacer fact', () => {
		const report = auditBands( { sections: NOT_SPACER, builtHtml: html } );
		expect( report.bands[ 0 ].content.status ).toBe( 'fail' );
		expect( report.bands[ 0 ].content.detail ).toContain( 'empty band' );
	} );

	it( 'does not suppress a real content failure on a spacer', () => {
		// Presence and surface still gate: a spacer that loses its background
		// must fail, it just is not asked for copy.
		const report = auditBands( {
			sections: SPACER,
			builtHtml: WRAP( 'eee555', 'background:#ffffff', '' ),
		} );
		expect( report.bands[ 0 ].surface.status ).toBe( 'fail' );
	} );
} );
