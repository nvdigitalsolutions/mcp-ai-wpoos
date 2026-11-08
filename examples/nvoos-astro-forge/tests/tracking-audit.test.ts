// ---------------------------------------------------------------------------
// tracking-audit.test.ts — the `verify --tracking` gate (Proposal 130).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { cspHostsFor } from '../lib/tracking.ts';
import { auditTracking } from '../lib/tracking-audit.ts';
import type { ForgeTracker, ForgeTracking } from '../lib/types.ts';

const GA: ForgeTracker = {
	vendor: 'google-analytics-4',
	category: 'analytics',
	enabled: true,
	ids: { measurementId: 'G-AB12CD34EF' },
	detectedBy: [ 'script:www.googletagmanager.com' ],
	confidence: 'high',
};

function tracking( overrides: Partial< ForgeTracking > = {} ): ForgeTracking {
	const trackers = overrides.trackers ?? [ GA ];
	return {
		trackers,
		consent: { mode: 'forge', default: 'denied', strategy: 'basic', gpc: true },
		verification: { google: 'abc123' },
		delivery: { mode: 'deferred' },
		csp: { hosts: cspHostsFor( trackers ) },
		ecommerce: { events: 'ga4', purchaseSource: 'wordpress' },
		...overrides,
	};
}

const HEAD = [
	'<head>',
	'<meta name="google-site-verification" content="abc123">',
	'<script id="nvoos-tracking-config" type="application/json">{"vendors":[]}</script>',
	'<script id="nvoos-consent-default">gtag("consent","default",{});</script>',
	'<script id="nvoos-tracking-events" type="application/json">{"purchase":false}</script>',
	'<script id="nvoos-tracking-loader">/* loader */</script>',
	'</head>',
].join( '\n' );

const codes = ( findings: ReturnType< typeof auditTracking > ) => findings.map( ( finding ) => finding.code );

describe( 'auditTracking — clean build', () => {
	it( 'returns no findings for a deferred, consent-first build', () => {
		expect( auditTracking( tracking(), HEAD ) ).toEqual( [] );
	} );

	it( 'returns nothing when the profile has no tracking block', () => {
		expect( auditTracking( undefined, HEAD ) ).toEqual( [] );
	} );
} );

describe( 'auditTracking — consent ordering', () => {
	it( 'fails when the consent marker is missing', () => {
		const findings = auditTracking( tracking(), '<head></head>' );
		expect( codes( findings ) ).toContain( 'consent-default-missing' );
	} );

	it( 'fails when a vendor script precedes the consent defaults', () => {
		const html =
			'<head><script src="https://www.googletagmanager.com/gtag/js?id=G-AB12CD34EF"></script>' +
			'<script id="nvoos-consent-default">x</script></head>';
		const findings = codes( auditTracking( tracking(), html ) );
		expect( findings ).toContain( 'tag-before-consent' );
		expect( findings ).toContain( 'tracker-in-static-html' );
	} );

	it( 'warns when trackers are enabled with consent mode "none"', () => {
		const findings = codes( auditTracking( tracking( { consent: { mode: 'none', default: 'denied' } } ), HEAD ) );
		expect( findings ).toContain( 'trackers-never-fire' );
	} );

	it( 'warns when external-cmp has no script', () => {
		const findings = codes(
			auditTracking( tracking( { consent: { mode: 'external-cmp', default: 'denied' } } ), HEAD )
		);
		expect( findings ).toContain( 'cmp-script-missing' );
	} );
} );

describe( 'auditTracking — verification', () => {
	it( 'fails when a configured meta token is absent', () => {
		const html = HEAD.replace( /<meta name="google-site-verification"[^>]*>\n?/, '' );
		expect( codes( auditTracking( tracking(), html ) ) ).toContain( 'verification-meta-missing' );
	} );

	it( 'fails when a configured proof file is missing from dist', () => {
		const t = tracking( { verification: { files: [ { path: '/googleabc.html', content: 'x' } ] } } );
		const findings = codes( auditTracking( t, HEAD, { hasDistFile: () => false } ) );
		expect( findings ).toContain( 'verification-file-missing' );
		expect( auditTracking( t, HEAD, { hasDistFile: () => true } ) ).toEqual( [] );
	} );
} );

describe( 'auditTracking — ownership', () => {
	it( 'fails on a purchase event owned by WordPress', () => {
		const html = HEAD.replace( '{"purchase":false}', '{"purchase":true}' );
		expect( codes( auditTracking( tracking(), html ) ) ).toContain( 'purchase-owned-by-wordpress' );
	} );

	it( 'fails on an enabled deprecated tracker', () => {
		const findings = codes(
			auditTracking(
				tracking( {
					trackers: [
						{
							vendor: 'universal-analytics',
							category: 'analytics',
							enabled: true,
							ids: { trackingId: 'UA-1-1' },
							detectedBy: [],
							confidence: 'high',
							deprecated: true,
						},
					],
				} ),
				HEAD
			)
		);
		expect( findings ).toContain( 'deprecated-tracker' );
	} );

	it( 'warns when GTM and GA4 are both enabled', () => {
		const findings = codes(
			auditTracking(
				tracking( {
					trackers: [ GA, { ...GA, vendor: 'google-tag-manager', ids: { containerId: 'GTM-XY12Z3' } } ],
				} ),
				HEAD
			)
		);
		expect( findings ).toContain( 'gtm-gtag-double' );
	} );
} );

describe( 'auditTracking — CSP', () => {
	it( 'fails when a required host is missing', () => {
		expect( codes( auditTracking( tracking( { csp: { hosts: [] } } ), HEAD ) ) ).toContain( 'csp-host-missing' );
	} );

	it( 'warns when the allowlist declares an unused host', () => {
		const t = tracking();
		const findings = codes(
			auditTracking( tracking( { csp: { hosts: [ ...( t.csp?.hosts ?? [] ), 'https://stale.example.com' ] } } ), HEAD )
		);
		expect( findings ).toContain( 'csp-host-stale' );
	} );
} );
