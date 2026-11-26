// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// forms-gate.test.ts — the enquiry contract in the BUILT page.
//
// Every case here is a shape that shipped or nearly shipped: a form band that
// renders no form (pre-bridge ECA), a form with no fillable field (Elementor
// inputs are client-rendered), a `#` action, a stale endpoint, and an Elementor
// carrier post of 0 (which can never dispatch).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { auditFormBands, describeFormProblems } from '../lib/forms-gate.ts';

const ENDPOINT = 'https://eca.directory/wp-json/nvoos-forge/v1';

function band( inner: string, id = '71dbef2b' ): string {
	return `<div data-section="form" data-section-id="${ id }" class="ds-section-wrap"><section class="ds-section">${ inner }</section></div>`;
}

function elementorForm( overrides: { action?: string; postId?: string; form?: string; fields?: boolean } = {} ): string {
	const action = overrides.action ?? `${ ENDPOINT }/forms/submit`;
	const postId = overrides.postId ?? '2';
	const form = overrides.form ?? '329253bd';
	const fields =
		overrides.fields === false
			? ''
			: `<div class="ds-field"><label for="ds-f-0">Your Name</label><input class="ds-input" id="ds-f-0" type="text" name="form_fields[name]"></div>
			   <div class="ds-field"><label for="ds-f-1">Your Email</label><input class="ds-input" id="ds-f-1" type="email" name="form_fields[email]" required></div>`;
	return `<form class="ds-form" method="post" action="${ action }" data-ds-form>
		${ fields }
		<input type="hidden" name="form" value="${ form }">
		<input type="hidden" name="post_id" value="${ postId }">
		<input type="hidden" name="honeypot" value="">
		<input type="hidden" name="timestamp" value="">
		<input type="hidden" name="_redirect" value="/">
		<button class="ds-button" type="submit">Send</button>
	</form>`;
}

function audit( html: string, options: { endpoint?: string } = {} ) {
	return auditFormBands( [ { file: 'index.html', html } ], options );
}

describe( 'auditFormBands — the healthy shapes', () => {
	it( 'passes an Elementor form that carries both identifiers and a field', () => {
		const report = audit( band( elementorForm() ), { endpoint: ENDPOINT } );
		expect( report.status ).toBe( 'pass' );
		expect( report.problems ).toEqual( [] );
		expect( report.bands ).toHaveLength( 1 );
		expect( report.bands[ 0 ].forms[ 0 ].fields ).toEqual( [ 'form_fields[name]', 'form_fields[email]' ] );
		expect( report.bands[ 0 ].forms[ 0 ].carriers ).toMatchObject( { form: '329253bd', post_id: '2' } );
	} );

	it( 'ignores a GET search form (it routes to /search/, carries no carrier)', () => {
		const html = band(
			`<form class="ds-form ds-form-search" action="/search/" method="get" role="search">
				<input class="ds-input" id="ds-search-q" type="search" name="q">
				<button type="submit">Search</button>
			</form>`
		);
		expect( audit( html, { endpoint: ENDPOINT } ).status ).toBe( 'pass' );
	} );

	it( 'reports the block-component surface as its own band', () => {
		const html = `<section id="contact" class="ds-contact">${ elementorForm() }</section>`;
		const report = audit( html, { endpoint: ENDPOINT } );
		expect( report.status ).toBe( 'pass' );
		expect( report.bands ).toHaveLength( 1 );
		expect( report.bands[ 0 ] ).toMatchObject( { sectionId: '#contact', source: 'block' } );
	} );

	it( 'is na when the build has no enquiry surface at all', () => {
		const report = audit( '<main><h1>Nothing here</h1></main>', { endpoint: ENDPOINT } );
		expect( report.status ).toBe( 'na' );
		expect( report.note ).toMatch( /no enquiry surface/ );
	} );
} );

describe( 'auditFormBands — the defects that shipped', () => {
	it( 'fails a form band that renders no form when a bridge is declared', () => {
		const report = audit( band( '<h2>Questions?</h2><p>Contact the coordinator.</p>' ), { endpoint: ENDPOINT } );
		expect( report.status ).toBe( 'fail' );
		expect( report.problems.join( ' ' ) ).toMatch( /renders no form/ );
	} );

	it( 'treats the same band as documented degradation when no bridge is declared', () => {
		const report = audit( band( '<h2>Questions?</h2><p>Contact the coordinator.</p>' ) );
		expect( report.status ).toBe( 'pass' );
		expect( report.note ).toMatch( /degraded to contacts/ );
	} );

	it( 'fails a submit form with no fillable field (carriers only)', () => {
		const report = audit( band( elementorForm( { fields: false } ) ), { endpoint: ENDPOINT } );
		expect( report.status ).toBe( 'fail' );
		expect( report.problems.join( ' ' ) ).toMatch( /no fillable field/ );
	} );

	it( 'fails an action of "#"', () => {
		const report = audit( band( elementorForm( { action: '#' } ) ), { endpoint: ENDPOINT } );
		expect( report.status ).toBe( 'fail' );
		expect( report.problems.join( ' ' ) ).toMatch( /posts to "#"/ );
	} );

	it( 'fails a stale endpoint after cutover', () => {
		const report = audit( band( elementorForm( { action: 'https://old.example.com/wp-json/nvoos-forge/v1/forms/submit' } ) ), {
			endpoint: ENDPOINT,
		} );
		expect( report.status ).toBe( 'fail' );
		expect( report.problems.join( ' ' ) ).toMatch( /outside the configured endpoint/ );
	} );

	it( 'fails an Elementor carrier post of 0 (the bridge answers 400 for it)', () => {
		const report = audit( band( elementorForm( { postId: '0' } ) ), { endpoint: ENDPOINT } );
		expect( report.status ).toBe( 'fail' );
		expect( report.problems.join( ' ' ) ).toMatch( /post_id.*empty or 0/ );
	} );

	it( 'fails an empty Elementor carrier post', () => {
		const report = audit( band( elementorForm( { postId: '' } ) ), { endpoint: ENDPOINT } );
		expect( report.status ).toBe( 'fail' );
	} );

	it( 'fails a submit form with no form identifier', () => {
		const report = audit( band( elementorForm( { form: '' } ) ), { endpoint: ENDPOINT } );
		expect( report.status ).toBe( 'fail' );
		expect( report.problems.join( ' ' ) ).toMatch( /no `form` identifier/ );
	} );
} );

describe( 'auditFormBands — reporting', () => {
	it( 'names every defect with its band so a reader can act', () => {
		const html =
			band( '<h2>Questions?</h2>' ) +
			band( elementorForm( { fields: false } ), '9c2d1fa' );
		const report = audit( html, { endpoint: ENDPOINT } );
		const lines = describeFormProblems( report );
		expect( report.status ).toBe( 'fail' );
		expect( lines ).toHaveLength( 2 );
		expect( lines[ 0 ] ).toContain( '71dbef2b' );
		expect( lines[ 1 ] ).toContain( '9c2d1fa' );
	} );

	it( 'counts surfaces across files', () => {
		const report = auditFormBands(
			[
				{ file: 'index.html', html: band( elementorForm() ) },
				{ file: 'contact-us/index.html', html: band( elementorForm( { form: '09ef7ef' } ), '9c2d1fa' ) },
			],
			{ endpoint: ENDPOINT }
		);
		expect( report.status ).toBe( 'pass' );
		expect( report.note ).toMatch( /2 form\(s\) across 2 surface\(s\)/ );
	} );
} );
