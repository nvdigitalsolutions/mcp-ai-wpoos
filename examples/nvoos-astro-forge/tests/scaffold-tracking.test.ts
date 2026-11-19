// ---------------------------------------------------------------------------
// scaffold-tracking.test.ts — the generated tracking module + env lines
// (Proposal 130 §3.4).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { renderTrackingEnvLines, renderTrackingGenerated, renderTrackingHeaders, trackingVerificationFiles } from '../lib/scaffold.ts';
import type { SiteProfile } from '../lib/types.ts';

function profile(): SiteProfile {
	return {
		url: 'https://example.com',
		collections: [],
		tracking: {
			trackers: [
				{
					vendor: 'google-analytics-4',
					category: 'analytics',
					enabled: true,
					ids: { measurementId: 'G-TEST123' },
					detectedBy: [ 'script:www.googletagmanager.com' ],
					confidence: 'high',
				},
				{
					vendor: 'meta-pixel',
					category: 'advertising',
					enabled: false,
					ids: { pixelId: '123456789012345' },
					detectedBy: [ 'script:connect.facebook.net' ],
					confidence: 'high',
				},
			],
			consent: {
				mode: 'forge',
				default: 'denied',
				strategy: 'basic',
				gpc: true,
				policy: { privacy: '/privacy/' },
			},
			verification: {
				google: 'abc123',
				files: [
					{ path: 'googleabc.html', content: 'google-site-verification: googleabc.html' },
					{ path: '/../evil.html', content: 'nope' },
				],
			},
			delivery: { mode: 'deferred' },
			ecommerce: { events: 'ga4', purchaseSource: 'wordpress' },
			csp: { hosts: [] },
		},
	};
}

describe( 'renderTrackingGenerated', () => {
	it( 'bakes only the ENABLED vendors with their public ids', () => {
		const out = renderTrackingGenerated( profile() );
		expect( out ).toContain( 'enabled: true,' );
		expect( out ).toContain( "'google-analytics-4'" );
		expect( out ).toContain( "measurementId: 'G-TEST123'" );
		expect( out ).toContain( "consentCategory: 'analytics'" );
		expect( out ).not.toContain( "'meta-pixel'" );
	} );

	it( 'bakes the consent block, verification, delivery and commerce rule', () => {
		const out = renderTrackingGenerated( profile() );
		expect( out ).toContain( "mode: 'forge'" );
		expect( out ).toContain( "default: 'denied'" );
		expect( out ).toContain( "strategy: 'basic'" );
		expect( out ).toContain( "policy: { privacy: '/privacy/' }" );
		expect( out ).toContain( "verification: { google: 'abc123' }" );
		expect( out ).toContain( "delivery: { mode: 'deferred' }" );
		expect( out ).toContain( "purchaseSource: 'wordpress'" );
	} );

	it( 'is deterministic', () => {
		expect( renderTrackingGenerated( profile() ) ).toBe( renderTrackingGenerated( profile() ) );
	} );

	it( 'bakes an inert config when the profile has no tracking block', () => {
		const out = renderTrackingGenerated( { url: 'https://example.com', collections: [] } );
		expect( out ).toContain( 'enabled: false,' );
		expect( out ).toContain( 'vendors: [],' );
		expect( out ).toContain( "mode: 'none'" );
	} );
} );

describe( 'renderTrackingGenerated — consent-exempt beacons', () => {
	function withTracker( vendor: string, consentExempt: boolean, spa?: boolean ): SiteProfile {
		return {
			url: 'https://example.com',
			collections: [],
			tracking: {
				trackers: [
					{
						vendor,
						category: 'analytics',
						enabled: true,
						ids: { token: '39a0cc5c9636420d99495209b1e494a8' },
						detectedBy: [ 'operator' ],
						confidence: 'high',
						...( consentExempt ? { consentExempt: true } : {} ),
					},
				],
				consent: { mode: 'none', default: 'denied', strategy: 'basic', gpc: true },
				...( spa === undefined ? {} : { spa } ),
				delivery: { mode: 'deferred' },
				csp: { hosts: [] },
			},
		};
	}

	it( 'marks a cookieless vendor and carries the operator exemption', () => {
		const out = renderTrackingGenerated( withTracker( 'cloudflare-web-analytics', true ) );
		expect( out ).toContain( "'cloudflare-web-analytics'" );
		expect( out ).toContain( 'cookieless: true' );
		expect( out ).toContain( 'consentExempt: true' );
	} );

	it( 'never exempts a vendor that sets cookies', () => {
		// A GA4 tag behind no decision is a compliance bug — the flag is dropped
		// at generation time, so nothing downstream can re-enable it.
		const out = renderTrackingGenerated( withTracker( 'google-analytics-4', true ) );
		expect( out ).not.toContain( 'consentExempt: true' );
		expect( out ).not.toContain( 'cookieless: true' );
	} );

	it( 'pins SPA measurement off unless the profile asks for it', () => {
		// The beacon's default SPA mode patches the History API; a static MPA
		// gains nothing from it and would double-count if a router were added.
		expect( renderTrackingGenerated( withTracker( 'cloudflare-web-analytics', true ) ) ).toContain( 'spa: false,' );
		expect( renderTrackingGenerated( withTracker( 'cloudflare-web-analytics', true, true ) ) ).toContain( 'spa: true,' );
	} );
} );

describe( 'renderTrackingEnvLines', () => {
	it( 'emits a PUBLIC_ override per enabled id (ids are not secrets)', () => {
		const lines = renderTrackingEnvLines( profile() );
		expect( lines.join( '\n' ) ).toContain( 'PUBLIC_GOOGLE_ANALYTICS_4_MEASUREMENT_ID=G-TEST123' );
		expect( lines.join( '\n' ) ).not.toContain( 'META_PIXEL' );
	} );

	it( 'emits nothing without enabled trackers', () => {
		expect( renderTrackingEnvLines( { url: 'https://example.com', collections: [] } ) ).toEqual( [] );
	} );
} );

describe( 'trackingVerificationFiles', () => {
	it( 'normalizes the leading slash and drops traversal paths', () => {
		const files = trackingVerificationFiles( profile() );
		expect( files ).toHaveLength( 1 );
		expect( files[ 0 ].path ).toBe( '/googleabc.html' );
		expect( files[ 0 ].content ).toContain( 'google-site-verification' );
	} );
} );

describe( 'renderTrackingHeaders', () => {
	it( 'emits a Report-Only CSP with the enabled vendor hosts and the WP origin', () => {
		const headers = renderTrackingHeaders( profile() );
		expect( headers ).toBeDefined();
		expect( headers ).toContain( 'Content-Security-Policy-Report-Only:' );
		expect( headers ).toContain( 'https://www.googletagmanager.com' );
		expect( headers ).toContain( 'https://*.google-analytics.com' );
		// The WordPress origin covers the forms bridge POST + CDN images.
		expect( headers ).toContain( 'https://example.com' );
		// Only ENABLED vendors: the disabled Meta pixel contributes no host.
		expect( headers ).not.toContain( 'https://connect.facebook.net' );
	} );

	it( 'switches to an enforcing header only when the profile opts in', () => {
		const p = profile();
		if ( ! p.tracking ) {
			throw new Error( 'fixture lost its tracking block' );
		}
		p.tracking.consent.enforceCsp = true;
		const headers = renderTrackingHeaders( p ) ?? '';
		expect( headers ).toContain( 'Content-Security-Policy:' );
		expect( headers ).not.toContain( 'Content-Security-Policy-Report-Only' );
	} );

	it( 'emits nothing when no tracker is enabled', () => {
		expect( renderTrackingHeaders( { url: 'https://example.com', collections: [] } ) ).toBeUndefined();
		const p = profile();
		p.tracking?.trackers.forEach( ( tracker ) => {
			tracker.enabled = false;
		} );
		expect( renderTrackingHeaders( p ) ).toBeUndefined();
	} );

	it( 'allows the Turnstile origin — script, iframe and siteverify — when a widget renders', () => {
		// A Turnstile-only site has no analytics, so the header must exist for the
		// widget alone; otherwise the first enforcing policy kills the form.
		const headers =
			renderTrackingHeaders( {
				url: 'https://virtuallyjamaica.com',
				collections: [],
				forms: { delivery: 'direct', turnstile: { siteKey: '1x00000000000000000000AA', action: 'contact' } },
			} ) ?? '';
		expect( headers ).toContain( 'Content-Security-Policy-Report-Only:' );
		expect( headers ).toMatch( /script-src[^;]*https:\/\/challenges\.cloudflare\.com/ );
		expect( headers ).toContain( "frame-src 'self' https://challenges.cloudflare.com" );
		expect( headers ).toMatch( /connect-src[^;]*https:\/\/challenges\.cloudflare\.com/ );
	} );

	it( 'leaves frame-src out when no widget needs it', () => {
		// An absent frame-src leaves frames unrestricted; narrowing it for every
		// site would break embeds the forge cannot enumerate.
		expect( renderTrackingHeaders( profile() ) ).not.toContain( 'frame-src' );
	} );

	it( 'still emits nothing without a tracker or a widget', () => {
		expect(
			renderTrackingHeaders( { url: 'https://example.com', collections: [], forms: { delivery: 'direct' } } )
		).toBeUndefined();
	} );
} );
