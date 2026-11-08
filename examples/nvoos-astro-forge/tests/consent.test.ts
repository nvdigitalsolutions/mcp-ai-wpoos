// ---------------------------------------------------------------------------
// consent.test.ts — the generated project's pure consent model (130 §2.5).
// The inline bootstrap + banner script mirror this module; the constants are
// pinned here so the copies cannot drift silently.
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	CONSENT_CATEGORIES,
	CONSENT_KEY,
	CONSENT_VERSION,
	decisionFromCategories,
	defaultDecision,
	gtagConsentMap,
	isGranted,
	needsDecision,
	parseDecision,
	serializeDecision,
	vendorConsentCategory,
} from '../templates/blog/src/lib/consent.ts';

describe( 'storage contract', () => {
	it( 'pins the key and version the inline scripts hardcode', () => {
		expect( CONSENT_KEY ).toBe( 'nvoos-consent' );
		expect( CONSENT_VERSION ).toBe( 1 );
	} );

	it( 'round-trips a decision', () => {
		const decision = decisionFromCategories( { analytics: true, advertising: false } );
		const parsed = parseDecision( serializeDecision( decision ) );
		expect( parsed?.categories.analytics ).toBe( true );
		expect( parsed?.categories.advertising ).toBe( false );
		expect( parsed?.categories.necessary ).toBe( true );
	} );

	it( 'rejects corrupt JSON and an older schema version', () => {
		expect( parseDecision( '{not json' ) ).toBeNull();
		expect( parseDecision( null ) ).toBeNull();
		expect( parseDecision( JSON.stringify( { v: 0, categories: {} } ) ) ).toBeNull();
		expect( parseDecision( JSON.stringify( { v: 1 } ) ) ).toBeNull();
	} );

	it( 'normalizes a stored decision to every category', () => {
		const parsed = parseDecision( JSON.stringify( { v: 1, categories: { analytics: true } } ) );
		for ( const category of CONSENT_CATEGORIES ) {
			expect( parsed?.categories[ category ] ).toBe( category === 'necessary' || category === 'analytics' );
		}
	} );
} );

describe( 'defaults', () => {
	it( 'denies every non-essential category by default', () => {
		const decision = defaultDecision();
		expect( decision.categories ).toEqual( {
			necessary: true,
			analytics: false,
			advertising: false,
			functional: false,
			personalization: false,
		} );
		expect( needsDecision( null ) ).toBe( true );
		expect( needsDecision( decision ) ).toBe( false );
	} );

	it( 'necessary is always granted', () => {
		expect( isGranted( null, 'necessary' ) ).toBe( true );
		expect( isGranted( null, 'analytics' ) ).toBe( false );
	} );
} );

describe( 'Consent Mode v2 signal map', () => {
	it( 'denies the four v2 signals with no decision', () => {
		expect( gtagConsentMap( null ) ).toEqual( {
			ad_storage: 'denied',
			ad_user_data: 'denied',
			ad_personalization: 'denied',
			analytics_storage: 'denied',
			functionality_storage: 'denied',
			personalization_storage: 'denied',
			security_storage: 'granted',
		} );
	} );

	it( 'grants what the visitor allowed', () => {
		const decision = decisionFromCategories( { analytics: true, advertising: true, functional: true } );
		const map = gtagConsentMap( decision );
		expect( map.analytics_storage ).toBe( 'granted' );
		expect( map.ad_storage ).toBe( 'granted' );
		expect( map.ad_user_data ).toBe( 'granted' );
		expect( map.ad_personalization ).toBe( 'granted' );
		expect( map.functionality_storage ).toBe( 'granted' );
		expect( map.personalization_storage ).toBe( 'denied' );
	} );

	it( 'Global Privacy Control outranks a stored advertising grant', () => {
		const decision = decisionFromCategories( { analytics: true, advertising: true } );
		const map = gtagConsentMap( decision, true );
		expect( map.ad_storage ).toBe( 'denied' );
		expect( map.ad_user_data ).toBe( 'denied' );
		expect( map.ad_personalization ).toBe( 'denied' );
		// Analytics is not a sale/share signal — GPC does not touch it.
		expect( map.analytics_storage ).toBe( 'granted' );
	} );

	it( 'a decision recorded under GPC stays opted out', () => {
		const decision = { ...decisionFromCategories( { advertising: true } ), gpc: true };
		expect( gtagConsentMap( decision ).ad_storage ).toBe( 'denied' );
	} );
} );

describe( 'vendor category mapping', () => {
	it( 'maps advertising to advertising and everything else to analytics', () => {
		expect( vendorConsentCategory( 'advertising' ) ).toBe( 'advertising' );
		expect( vendorConsentCategory( 'session' ) ).toBe( 'analytics' );
		expect( vendorConsentCategory( 'analytics' ) ).toBe( 'analytics' );
	} );
} );
