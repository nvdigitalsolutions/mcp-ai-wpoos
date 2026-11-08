// ---------------------------------------------------------------------------
// tracking-audit.test.ts — the `verify --tracking` gate (Proposal 130).
// ---------------------------------------------------------------------------

import { describe, expect, it } from 'vitest';

import { cspHostsFor } from '../lib/tracking.ts';
import { auditTracking, parseCspHosts } from '../lib/tracking-audit.ts';
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
	// `_headers` as scaffold emits it (the parser unions script/img/connect, so a
	// single directive is enough to exercise it).
	const gaHosts = cspHostsFor( [ GA ] );
	const emittedHeaders = ( hosts: string[] = gaHosts ) =>
		[
			'/*',
			`  Content-Security-Policy-Report-Only: script-src 'self' 'unsafe-inline' ${ hosts.join( ' ' ) }; img-src 'self' data:; connect-src 'self'`,
		].join( '\n' );

	it( 'does not fail when the profile list is absent (the analyze draft) and no header is supplied', () => {
		// Regression: analyze --tracking drafts csp.hosts: [] because detection
		// never enables a tracker. Enabling one must not fail the gate.
		expect( codes( auditTracking( tracking( { csp: { hosts: [] } } ), HEAD ) ) ).not.toContain( 'csp-host-missing' );
	} );

	it( 'fails when a DECLARED allowlist is incomplete', () => {
		expect(
			codes( auditTracking( tracking( { csp: { hosts: [ 'https://www.googletagmanager.com' ] } } ), HEAD ) )
		).toContain( 'csp-host-missing' );
	} );

	it( 'fails when the emitted deploy header is missing an enabled vendor host', () => {
		const findings = auditTracking( tracking( { csp: { hosts: [] } } ), HEAD, {
			headers: emittedHeaders( [ 'https://www.googletagmanager.com' ] ),
		} );
		const missing = findings.find( ( finding ) => finding.code === 'csp-host-missing' );
		expect( missing?.severity ).toBe( 'fail' );
		expect( missing?.message ).toContain( 'dist/_headers' );
		expect( missing?.message ).toContain( 'google-analytics.com' );
	} );

	it( 'passes when the emitted deploy header carries every required host', () => {
		const findings = auditTracking( tracking( { csp: { hosts: [] } } ), HEAD, {
			headers: emittedHeaders(),
		} );
		expect( codes( findings ) ).not.toContain( 'csp-host-missing' );
	} );

	it( 'fails when no CSP header is emitted at all', () => {
		const findings = auditTracking( tracking( { csp: { hosts: [] } } ), HEAD, { headers: '/*\n' } );
		expect( codes( findings ) ).toContain( 'csp-host-missing' );
	} );

	it( 'warns when the allowlist declares an unused host', () => {
		const t = tracking();
		const findings = codes(
			auditTracking( tracking( { csp: { hosts: [ ...( t.csp?.hosts ?? [] ), 'https://stale.example.com' ] } } ), HEAD )
		);
		expect( findings ).toContain( 'csp-host-stale' );
	} );
} );

describe( 'parseCspHosts', () => {
	it( 'collects http hosts from script-src, img-src and connect-src only', () => {
		const parsed = parseCspHosts(
			[
				'/*',
				"  Content-Security-Policy: default-src 'none'; script-src 'self' 'unsafe-inline' https://a.example; img-src data: https://b.example; connect-src 'self' https://c.example; font-src https://ignored.example",
			].join( '\n' )
		);
		expect( parsed ).toEqual( [ 'https://a.example', 'https://b.example', 'https://c.example' ] );
	} );

	it( 'unions multiple CSP lines and de-duplicates', () => {
		const parsed = parseCspHosts(
			[
				"  Content-Security-Policy-Report-Only: script-src 'self' https://a.example",
				"  Content-Security-Policy: script-src 'self' https://a.example https://b.example",
			].join( '\n' )
		);
		expect( parsed ).toEqual( [ 'https://a.example', 'https://b.example' ] );
	} );

	it( 'returns an empty list when there is no CSP line', () => {
		expect( parseCspHosts( '/*\n  X-Frame-Options: DENY\n' ) ).toEqual( [] );
	} );
} );
