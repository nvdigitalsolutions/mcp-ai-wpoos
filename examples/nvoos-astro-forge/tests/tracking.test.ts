// ---------------------------------------------------------------------------
// tracking.test.ts — the tracker detector (Proposal 130).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import {
	cspHostsFor,
	detectTrackers,
	maskId,
	mergeTrackingDraft,
	scanTrackingHtml,
	trackingFromReport,
} from '../lib/tracking.ts';
import type { ForgeTracking } from '../lib/types.ts';

/** Scan one document and detect against it. */
function detect( html: string, probe?: Parameters< typeof detectTrackers >[ 2 ] ) {
	return detectTrackers( scanTrackingHtml( html ), 'https://example.com', probe );
}

function findTracker( html: string, vendor: string ) {
	return detect( html ).trackers.find( ( tracker ) => tracker.vendor === vendor );
}

describe( 'scanTrackingHtml', () => {
	it( 'collects script srcs, inline JS, meta tags and noscript pixels', () => {
		const signals = scanTrackingHtml(
			'<html><head><meta name="google-site-verification" content="tok">' +
				'<script src="https://example.com/a.js"></script>' +
				'<script>var x = 1;</script></head>' +
				'<body><noscript><img src="https://www.facebook.com/tr?id=9"></noscript></body></html>'
		);
		expect( signals.scriptSrcs ).toEqual( [ 'https://example.com/a.js' ] );
		expect( signals.inlineJs ).toContain( 'var x = 1;' );
		expect( signals.metas[ 'google-site-verification' ] ).toBe( 'tok' );
		expect( signals.noscriptImgSrcs ).toEqual( [ 'https://www.facebook.com/tr?id=9' ] );
	} );
} );

describe( 'detectTrackers', () => {
	it( 'detects GA4 from the gtag script with high confidence', () => {
		const ga = findTracker(
			'<script async src="https://www.googletagmanager.com/gtag/js?id=G-AB12CD34EF"></script>' +
				"<script>gtag('js', new Date());gtag('config','G-AB12CD34EF');</script>",
			'google-analytics-4'
		);
		expect( ga ).toBeDefined();
		expect( ga?.ids.measurementId ).toBe( 'G-AB12CD34EF' );
		expect( ga?.confidence ).toBe( 'high' );
		expect( ga?.detectedBy.join( ' ' ) ).toContain( 'script:' );
		expect( ga?.enabled ).toBe( false );
	} );

	it( 'detects GTM from gtm.js', () => {
		const gtm = findTracker(
			'<script src="https://www.googletagmanager.com/gtm.js?id=GTM-XY12Z3"></script>',
			'google-tag-manager'
		);
		expect( gtm?.ids.containerId ).toBe( 'GTM-XY12Z3' );
	} );

	it( 'detects the Meta pixel and its id', () => {
		const meta = findTracker(
			'<script src="https://connect.facebook.net/en_US/fbevents.js"></script>' +
				"<script>fbq('init','123456789012345');fbq('track','PageView');</script>",
			'meta-pixel'
		);
		expect( meta?.ids.pixelId ).toBe( '123456789012345' );
		expect( meta?.category ).toBe( 'advertising' );
	} );

	it( 'detects a tracker through the browser probe even with no HTML footprint', () => {
		const result = detect( '<html><body>clean</body></html>', {
			requests: [ 'https://www.facebook.com/tr?id=42&ev=PageView' ],
			globals: { fbq: true },
			cookies: [ '_fbp' ],
		} );
		const meta = result.trackers.find( ( tracker ) => tracker.vendor === 'meta-pixel' );
		expect( meta ).toBeDefined();
		expect( meta?.detectedVia ).toBe( 'probe' );
		expect( meta?.detectedBy.join( ' ' ) ).toContain( 'probe:request' );
	} );

	it( 'does not match a prose mention of gtag', () => {
		const result = detect( '<html><body><p>We use gtag and dataLayer for analytics.</p></body></html>' );
		expect( result.trackers.find( ( tracker ) => tracker.vendor === 'google-analytics-4' ) ).toBeUndefined();
	} );

	it( 'reports Universal Analytics as deprecated', () => {
		const ua = findTracker(
			'<script src="https://www.google-analytics.com/analytics.js"></script>' +
				"<script>ga('create','UA-1234-1','auto');</script>",
			'universal-analytics'
		);
		expect( ua?.deprecated ).toBe( true );
	} );

	it( 'extracts verification tokens from meta tags', () => {
		const result = detect(
			'<head><meta name="google-site-verification" content="abc123">' +
				'<meta name="msvalidate.01" content="bing456">' +
				'<meta name="facebook-domain-verification" content="fb789"></head>'
		);
		expect( result.verification ).toEqual( {
			google: 'abc123',
			bing: 'bing456',
			facebook: 'fb789',
		} );
	} );

	it( 'records a consent platform and warns that the rebuild needs one', () => {
		const result = detect(
			'<script src="https://cdn-cookieyes.com/client_data/abc/script.js"></script>' +
				'<script src="https://www.googletagmanager.com/gtag/js?id=G-AB12CD34EF"></script>'
		);
		expect( result.consent.detected ).toBe( true );
		expect( result.consent.cmp?.vendor ).toBe( 'cookieyes' );
		expect( result.warnings.join( ' ' ) ).toContain( 'consent layer' );
	} );

	it( 'warns when GTM and a hardcoded GA4 tag are both present', () => {
		const result = detect(
			'<script src="https://www.googletagmanager.com/gtm.js?id=GTM-XY12Z3"></script>' +
				'<script src="https://www.googletagmanager.com/gtag/js?id=G-AB12CD34EF"></script>'
		);
		expect( result.warnings.join( ' ' ) ).toContain( 'double-count' );
	} );

	it( 'downgrades a detected vendor with no extractable id', () => {
		const raw = findTracker( '<script src="https://analytics.tiktok.com/i18n/pixel/events.js"></script>', 'tiktok-pixel' );
		expect( raw?.confidence ).toBe( 'low' );
		expect( raw?.notes?.join( ' ' ) ).toContain( 'no public id' );
	} );
} );

describe( 'report → profile', () => {
	const html =
		'<head><meta name="google-site-verification" content="abc123">' +
		'<script src="https://www.googletagmanager.com/gtag/js?id=G-AB12CD34EF"></script>' +
		'<script src="https://connect.facebook.net/en_US/fbevents.js"></script>' +
		"<script>fbq('init','123456789012345');</script></head>";
	const detected = detect( html );

	it( 'never enables a detected tracker', () => {
		const draft = trackingFromReport( {
			url: 'https://example.com',
			pagesAnalyzed: [ 'https://example.com' ],
			...detected,
		} );
		expect( draft.trackers.length ).toBeGreaterThan( 0 );
		expect( draft.trackers.every( ( tracker ) => tracker.enabled === false ) ).toBe( true );
		expect( draft.consent.default ).toBe( 'denied' );
		expect( draft.consent.mode ).toBe( 'none' );
		expect( draft.delivery?.mode ).toBe( 'deferred' );
		expect( draft.verification?.google ).toBe( 'abc123' );
	} );

	it( 'seeds the commerce event map with a single purchase owner', () => {
		const draft = trackingFromReport(
			{ url: 'https://example.com', pagesAnalyzed: [], ...detected },
			{ hasCommerce: true }
		);
		expect( draft.ecommerce ).toEqual( { events: 'ga4', purchaseSource: 'wordpress' } );
	} );

	it( 'keeps operator decisions on a re-analysis', () => {
		const existing: ForgeTracking = {
			trackers: [
				{ vendor: 'google-analytics-4', category: 'analytics', enabled: true, ids: { measurementId: 'G-AAAAAA' }, detectedBy: [], confidence: 'high' },
			],
			consent: { mode: 'forge', default: 'denied', strategy: 'basic', gpc: true },
			delivery: { mode: 'deferred' },
		};
		const fresh = trackingFromReport( { url: 'https://example.com', pagesAnalyzed: [], ...detected } );
		const merged = mergeTrackingDraft( existing, fresh );
		const ga = merged.trackers.find( ( tracker ) => tracker.vendor === 'google-analytics-4' );
		expect( ga?.enabled ).toBe( true );
		expect( merged.trackers.find( ( tracker ) => tracker.vendor === 'meta-pixel' )?.enabled ).toBe( false );
		expect( merged.consent.mode ).toBe( 'forge' );
	} );

	it( 'derives CSP hosts from the ENABLED set only', () => {
		const disabled = trackingFromReport( { url: 'https://example.com', pagesAnalyzed: [], ...detected } );
		expect( cspHostsFor( disabled.trackers ) ).toEqual( [] );
		const enabled: ForgeTracking = {
			...disabled,
			trackers: disabled.trackers.map( ( tracker ) =>
				tracker.vendor === 'google-analytics-4' ? { ...tracker, enabled: true } : tracker
			),
		};
		expect( cspHostsFor( enabled.trackers ) ).toContain( 'https://www.googletagmanager.com' );
		expect( cspHostsFor( enabled.trackers ) ).toContain( 'https://*.google-analytics.com' );
	} );
} );

describe( 'maskId', () => {
	it( 'keeps short ids and masks the middle of long ones', () => {
		expect( maskId( 'GTM-1234' ) ).toBe( 'GTM-1234' );
		expect( maskId( 'G-AB12CD34EF' ) ).toBe( 'G-AB…34EF' );
	} );
} );
