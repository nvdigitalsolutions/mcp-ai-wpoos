// SPDX-License-Identifier: MIT
// ---------------------------------------------------------------------------
// chrome-fact-audit.test.ts — a chrome fact that stops at the profile.
//
// The header's trailing icons were measured, typed and rendered by nothing, and
// the build was green. `fact-audit` accounts for the BAND facts; this is the
// chrome half.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { auditChromeFacts, describeChromeFacts } from '../lib/chrome-fact-audit.ts';
import type { ForgeChrome } from '../lib/types.ts';

const html = ( body: string ): string => `<html><body>${ body }</body></html>`;

describe( 'auditChromeFacts', () => {
	it( 'says nothing when the profile declares no chrome', () => {
		expect( auditChromeFacts( undefined, html( '' ) ) ).toEqual( [] );
	} );

	it( 'says nothing when there is no built page to read', () => {
		expect( auditChromeFacts( { logo: { src: '/x.svg' } } as ForgeChrome, '' ) ).toEqual( [] );
	} );

	it( 'flags a measured header action that reached no template slot', () => {
		// The exact regression: `headerActions` in the profile, nothing in the page.
		const chrome = {
			headerActions: [ { icon: 'envelop', label: 'Send us an email', href: 'mailto:a@b.co' } ],
		} as ForgeChrome;
		expect( auditChromeFacts( chrome, html( '<header></header>' ) ) ).toEqual( [
			{ fact: 'headerActions', marker: 'header-icon' },
		] );
	} );

	it( 'passes once the slot renders', () => {
		const chrome = {
			headerActions: [ { icon: 'envelop', label: 'E-mail', href: 'mailto:a@b.co' } ],
		} as ForgeChrome;
		expect( auditChromeFacts( chrome, html( '<a class="header-icon"></a>' ) ) ).toEqual( [] );
	} );

	it( 'accepts a labelled pill as the same fact (header-cta)', () => {
		// A TEXT header action renders `.header-cta`; an icon action renders
		// `.header-icon`. Demanding the icon class reported a rendered pill as a
		// missing fact (eca.directory).
		const chrome = {
			headerActions: [ { label: 'Try Demo', text: 'Try Demo', href: '#', fill: '#8764a0' } ],
		} as ForgeChrome;
		expect( auditChromeFacts( chrome, html( '<a class="header-cta">Try Demo</a>' ) ) ).toEqual( [] );
		expect( auditChromeFacts( chrome, html( '<header></header>' ) ) ).toEqual( [
			{ fact: 'headerActions', marker: 'header-icon' },
		] );
	} );

	it( 'flags the brand mark when the template kept its default', () => {
		expect( auditChromeFacts( { logo: { src: '/logo.svg' } } as ForgeChrome, html( '<header></header>' ) ) ).toEqual( [
			{ fact: 'logo', marker: 'brand-logo' },
		] );
	} );

	it( 'audits only what the profile declares', () => {
		// No nav, no topbar, no call: nothing to account for.
		expect( auditChromeFacts( { tagline: undefined } as ForgeChrome, html( '<header></header>' ) ) ).toEqual( [] );
	} );

	it( 'flags a centred mark whose centring never engaged', () => {
		const chrome = {
			geometry: { desktop: { logoAlign: 'center', logoHeight: '74px' } },
		} as unknown as ForgeChrome;
		const missing = auditChromeFacts( chrome, html( '<img class="brand-logo">' ) );
		expect( missing ).toEqual( [
			{ fact: 'geometry.logoAlign', marker: 'site-header--brand-center' },
		] );
	} );

	it( 'reads custom-property facts too', () => {
		const chrome = { footerBg: '#000000' } as ForgeChrome;
		expect( auditChromeFacts( chrome, html( '<body style="--forge-footer-bg:#000000">' ) ) ).toEqual( [] );
		expect( auditChromeFacts( chrome, html( '<body>' ) ) ).toEqual( [
			{ fact: 'footerBg', marker: '--forge-footer-bg' },
		] );
	} );

	it( 'collects every miss in one pass', () => {
		const chrome = {
			logo: { src: '/logo.svg' },
			topbar: [ 'hello' ],
			headerActions: [ { icon: 'envelop' } ],
		} as ForgeChrome;
		expect( auditChromeFacts( chrome, html( '' ) ).map( ( fact ) => fact.fact ) ).toEqual( [
			'logo',
			'topbar',
			'headerActions',
		] );
	} );
} );

describe( 'describeChromeFacts', () => {
	it( 'stays quiet on a clean run', () => {
		expect( describeChromeFacts( [] ) ).toBeUndefined();
	} );

	it( 'names the fact and the marker it expected', () => {
		const line = describeChromeFacts( [ { fact: 'headerActions', marker: 'header-icon' } ] );
		expect( line ).toContain( 'headerActions' );
		expect( line ).toContain( 'header-icon' );
	} );
} );
