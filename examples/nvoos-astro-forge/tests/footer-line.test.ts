// ---------------------------------------------------------------------------
// footer-line.test.ts — a footer LINE split at the anchors it carries.
//
// A builder publishes the © statement as ONE paragraph with only the credit
// anchored, so `chrome.footerBlocks` holds one string while `chrome.footerLinks`
// records the anchor by its text. Printing the line verbatim shipped the credit
// as dead copy (virtuallysrilanka, 2026-11-20).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { footerLineSegments } from '../lib/footer-line.ts';

const CREDIT = '© 2026 VIRTUALLY SRI LANKA IS POWERED BY NV DIGITAL SOLUTIONS.';
const CREDIT_LINK = { text: 'NV DIGITAL SOLUTIONS.', href: 'http://www.nvdigitalsolutions.com/' };

describe( 'footerLineSegments', () => {
	it( 'splits a line around the anchor it carries, keeping the text order', () => {
		expect( footerLineSegments( CREDIT, [ CREDIT_LINK ] ) ).toEqual( [
			{ text: '© 2026 VIRTUALLY SRI LANKA IS POWERED BY ' },
			{ text: 'NV DIGITAL SOLUTIONS.', href: 'http://www.nvdigitalsolutions.com/' },
		] );
	} );

	it( 'anchors a link that IS the whole line', () => {
		expect( footerLineSegments( 'Privacy policy', [ { text: 'Privacy policy', href: '/privacy/' } ] ) ).toEqual( [
			{ text: 'Privacy policy', href: '/privacy/' },
		] );
	} );

	it( 'leaves a line whole when no recorded link occurs in it', () => {
		// The fact is a LOOKUP keyed by text — a link belonging to another column
		// must not rewrite this line.
		expect( footerLineSegments( CREDIT, [ { text: 'Terms', href: '/terms/' } ] ) ).toEqual( [
			{ text: CREDIT },
		] );
		expect( footerLineSegments( CREDIT, undefined ) ).toEqual( [ { text: CREDIT } ] );
	} );

	it( 'prefers the LONGEST match, so a shared suffix cannot split a longer name', () => {
		const links = [
			{ text: 'Solutions.', href: '/short/' },
			{ text: 'NV DIGITAL SOLUTIONS.', href: '/long/' },
		];
		expect( footerLineSegments( CREDIT, links ) ).toEqual( [
			{ text: '© 2026 VIRTUALLY SRI LANKA IS POWERED BY ' },
			{ text: 'NV DIGITAL SOLUTIONS.', href: '/long/' },
		] );
	} );

	it( 'anchors EVERY occurrence, not just the first', () => {
		const twice = 'NV DIGITAL SOLUTIONS. — NV DIGITAL SOLUTIONS.';
		expect( footerLineSegments( twice, [ CREDIT_LINK ] ) ).toEqual( [
			{ text: 'NV DIGITAL SOLUTIONS.', href: 'http://www.nvdigitalsolutions.com/' },
			{ text: ' — ' },
			{ text: 'NV DIGITAL SOLUTIONS.', href: 'http://www.nvdigitalsolutions.com/' },
		] );
	} );

	it( 'ignores a fact with no href (an href-less badge is not a line link)', () => {
		expect( footerLineSegments( CREDIT, [ { text: 'NV DIGITAL SOLUTIONS.', href: '' } ] ) ).toEqual( [
			{ text: CREDIT },
		] );
	} );
} );
