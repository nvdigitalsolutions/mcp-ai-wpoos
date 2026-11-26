// SPDX-License-Identifier: MIT
/**
 * @fileoverview Pins the build-time publication verdict and its manifest shape.
 *
 * Fixtures are synthetic bridge payloads and profiles; no network is used.
 */
// ---------------------------------------------------------------------------
// gate-publish.test.ts — the BUILT site's publication verdict (Proposal 165).
//
// No network: every case injects a GateProbe. The one fail-open test uses a
// URL the SSRF guard rejects, which is exactly the "unreachable CMS" path.
// ---------------------------------------------------------------------------

import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

import { afterEach, describe, expect, it } from 'vitest';

import {
	gateManifest,
	normalizeGateMode,
	offPublication,
	overridePublication,
	publicationFromProbe,
	resolveGateConfig,
	resolveLivePublication,
	writeGateManifest,
} from '../lib/gate-publish.ts';
import type { GateProbe } from '../lib/gate.ts';
import type { SiteProfile } from '../lib/types.ts';

const dirs: string[] = [];

afterEach( async () => {
	await Promise.all( dirs.splice( 0 ).map( ( dir ) => rm( dir, { recursive: true, force: true } ) ) );
} );

const profile = ( gatePublish?: SiteProfile[ 'gatePublish' ] ): SiteProfile =>
	( {
		url: 'https://cms.example.com',
		name: 'Example',
		gatePublish,
		collections: [],
	} as SiteProfile );

/** A probe with no bridge verdict (HTML-only evidence). */
function probe( over: Partial< GateProbe > = {} ): GateProbe {
	return {
		state: 'open',
		status: 200,
		restHealthy: true,
		visibleChars: 4000,
		evidence: [],
		remediation: [],
		...over,
	};
}

describe( 'the resolved runtime configuration', () => {
	it( 'defaults to the Elementor behaviour with the documented names', () => {
		const config = resolveGateConfig( profile() );

		expect( config.session ).toEqual( {
			bypass: true,
			path: '/wp-json/nvoos-commerce/v1/auth/me',
			cookieName: 'nvoos_forge_session',
		} );
		expect( config.bypass.cookieName ).toBe( 'nvoos_gate' );
		expect( config.bypass.ttlSeconds ).toBe( 43_200 );
		expect( config.retryAfterSeconds ).toBe( 600 );
		expect( config.policy ).toBe( 'replace' );
		expect( config.honor ).toBe( 'auto' );
		expect( config.trust ).toBe( 'bridge' );
		expect( config.comingSoonIndexable ).toBe( false );
		expect( config.managed ).toEqual( { enabled: true, pollSeconds: 60, maxStalenessSeconds: 900 } );
	} );

	it( 'honours an explicit block and clamps out-of-band numbers', () => {
		const config = resolveGateConfig(
			profile( {
				session: { bypass: false, path: '/me', cookieName: '__Host-nvoos' },
				bypass: { ttlSeconds: 10_000_000, cookieName: 'preview' },
				retryAfterSeconds: 99_999_999,
				static: { policy: 'keep' },
				honor: 'coming_soon-only',
				trust: 'probe',
				comingSoon: { indexable: true },
				managed: { enabled: false, pollSeconds: 1, maxStalenessSeconds: 1 },
			} )
		);

		expect( config.session ).toEqual( { bypass: false, path: '/me', cookieName: '__Host-nvoos' } );
		expect( config.bypass.ttlSeconds ).toBe( 86_400 );
		expect( config.retryAfterSeconds ).toBe( 86_400 );
		expect( config.policy ).toBe( 'keep' );
		expect( config.honor ).toBe( 'coming_soon-only' );
		expect( config.trust ).toBe( 'probe' );
		expect( config.comingSoonIndexable ).toBe( true );
		// Clamped to the documented bands, never zero.
		expect( config.managed.pollSeconds ).toBe( 15 );
		expect( config.managed.maxStalenessSeconds ).toBe( 60 );
	} );

	it( 'normalises a CMS mode string', () => {
		expect( normalizeGateMode( 'coming-soon' ) ).toBe( 'coming_soon' );
		expect( normalizeGateMode( ' Coming  Soon ' ) ).toBe( 'coming_soon' );
		expect( normalizeGateMode( 'MAINTENANCE' ) ).toBe( 'maintenance' );
		expect( normalizeGateMode( 'live' ) ).toBe( 'off' );
		expect( normalizeGateMode( undefined ) ).toBe( 'off' );
	} );
} );

describe( 'the verdict', () => {
	const config = resolveGateConfig( profile() );

	it( 'is off at build time and names no page', () => {
		const off = offPublication();
		expect( off.mode ).toBe( 'off' );
		expect( off.page ).toBe( null );
		expect( off.honored ).toBe( true );
		expect( off.source ).toBe( 'build' );
	} );

	it( 'takes the bridge verdict and its evidence', () => {
		const publication = publicationFromProbe(
			probe( {
				bridge: {
					maintenance: true,
					mode: 'coming_soon',
					plugin: 'elementor',
					checkedAt: '2026-11-26T00:00:00.000Z',
				},
			} ),
			config
		);

		expect( publication.mode ).toBe( 'coming_soon' );
		expect( publication.page ).toBe( 'coming-soon' );
		expect( publication.source ).toBe( 'bridge' );
		expect( publication.plugin ).toBe( 'elementor' );
		expect( publication.checkedAt ).toBe( '2026-11-26T00:00:00.000Z' );
		expect( publication.honored ).toBe( true );
		// Coming soon is a 200; Retry-After belongs to maintenance only.
		expect( publication.retryAfter ).toBeUndefined();
	} );

	it( 'carries Retry-After for maintenance, from the CMS when it says', () => {
		const publication = publicationFromProbe(
			probe( { bridge: { maintenance: true, mode: 'maintenance', plugin: 'core', retryAfter: 1234 } } ),
			config
		);

		expect( publication.mode ).toBe( 'maintenance' );
		expect( publication.page ).toBe( 'maintenance' );
		expect( publication.retryAfter ).toBe( 1234 );
	} );

	it( 'refuses to gate on HTML inference alone under the default trust', () => {
		// A cached anonymous page can look gated to a logged-out probe. Only the
		// bridge route is authoritative unless the profile opts into `probe`.
		const publication = publicationFromProbe(
			probe( { state: 'gated', status: 503, restHealthy: true } ),
			config
		);

		expect( publication.mode ).toBe( 'off' );
		expect( publication.page ).toBe( null );
		expect( publication.source ).toBe( 'probe' );
	} );

	it( 'infers the mode from the HTML when trust is "probe"', () => {
		const inferred = resolveGateConfig( profile( { trust: 'probe' } ) );

		expect(
			publicationFromProbe( probe( { state: 'gated', status: 503 } ), inferred ).mode
		).toBe( 'maintenance' );
		expect(
			publicationFromProbe( probe( { state: 'gated', status: 200 } ), inferred ).mode
		).toBe( 'coming_soon' );
		expect(
			publicationFromProbe( probe( { state: 'rate-limited', status: 429 } ), inferred ).mode
		).toBe( 'maintenance' );
		expect( publicationFromProbe( probe( { state: 'open' } ), inferred ).mode ).toBe( 'off' );
	} );

	it( 'lets the honor policy decline a mode without losing the evidence', () => {
		const comingSoonOnly = resolveGateConfig( profile( { honor: 'coming_soon-only' } ) );
		const declined = publicationFromProbe(
			probe( { bridge: { maintenance: true, mode: 'maintenance', plugin: 'core' } } ),
			comingSoonOnly
		);

		expect( declined.honored ).toBe( false );
		expect( declined.mode ).toBe( 'off' );
		expect( declined.page ).toBe( null );
		// The bridge still said maintenance — the artifact records that.
		expect( declined.source ).toBe( 'bridge' );

		// A coming-soon verdict is honoured by the same policy.
		const kept = publicationFromProbe(
			probe( { bridge: { maintenance: true, mode: 'coming_soon', plugin: 'core' } } ),
			comingSoonOnly
		);
		expect( kept.honored ).toBe( true );
		expect( kept.mode ).toBe( 'coming_soon' );
	} );

	it( 'turns honor: "off" into a declined verdict', () => {
		const off = resolveGateConfig( profile( { honor: 'off' } ) );
		const publication = publicationFromProbe(
			probe( { bridge: { maintenance: true, mode: 'maintenance', plugin: 'core' } } ),
			off
		);
		expect( publication.honored ).toBe( false );
		expect( publication.mode ).toBe( 'off' );
	} );

	it( 'lets an explicit override skip the network', () => {
		const forced = overridePublication( 'coming_soon', config );
		expect( forced.source ).toBe( 'override' );
		expect( forced.mode ).toBe( 'coming_soon' );
		expect( forced.page ).toBe( 'coming-soon' );
	} );
} );

describe( 'resolveLivePublication', () => {
	it( 'uses the override without probing', async () => {
		const publication = await resolveLivePublication( {
			profile: profile(),
			override: 'maintenance',
		} );
		expect( publication.source ).toBe( 'override' );
		expect( publication.mode ).toBe( 'maintenance' );
	} );

	it( 'uses an injected probe (the no-network seam)', async () => {
		const publication = await resolveLivePublication( {
			profile: profile(),
			override: 'auto',
			probe: probe( { bridge: { maintenance: true, mode: 'coming_soon', plugin: 'elementor' } } ),
		} );
		expect( publication.mode ).toBe( 'coming_soon' );
	} );

	it( 'fails OPEN when the source cannot be probed', async () => {
		// The SSRF guard rejects a non-HTTP URL, which is the same code path a
		// dead CMS or a timeout takes: publish the real site, never block.
		const publication = await resolveLivePublication( {
			profile: { url: 'ftp://example.com', name: 'x', collections: [] } as SiteProfile,
			override: 'auto',
		} );
		expect( publication.mode ).toBe( 'off' );
		expect( publication.source ).toBe( 'probe' );
		expect( publication.honored ).toBe( true );
	} );
} );

describe( 'the manifest', () => {
	it( 'carries the verdict AND the runtime config, but no secret', async () => {
		const dir = await mkdtemp( path.join( tmpdir(), 'forge-gate-manifest-' ) );
		dirs.push( dir );

		const written = await writeGateManifest(
			dir,
			publicationFromProbe(
				probe( { bridge: { maintenance: true, mode: 'coming_soon', plugin: 'elementor' } } ),
				resolveGateConfig( profile() )
			),
			profile()
		);

		expect( written.mode ).toBe( 'coming_soon' );
		expect( written.session.cookieName ).toBe( 'nvoos_forge_session' );
		expect( written.managed.pollSeconds ).toBe( 60 );

		const raw = await readFile( path.join( dir, 'gate.generated.json' ), 'utf8' );
		const parsed = JSON.parse( raw );
		expect( parsed.mode ).toBe( 'coming_soon' );
		expect( parsed.session ).toEqual( {
			bypass: true,
			path: '/wp-json/nvoos-commerce/v1/auth/me',
			cookieName: 'nvoos_forge_session',
		} );
		// No token, no key, no password anywhere in the artifact.
		expect( raw ).not.toMatch( /secret|token|password/i );
		// Tabs + a trailing newline, like every other generated JSON.
		expect( raw ).toContain( '\n\t"mode"' );
		expect( raw.endsWith( '\n' ) ).toBe( true );
	} );

	it( 'projects a publication into the manifest without inventing keys', () => {
		const config = resolveGateConfig( profile() );
		const manifest = gateManifest( offPublication(), config );
		expect( manifest.retryAfter ).toBeUndefined();
		expect( manifest.plugin ).toBeNull();
		expect( manifest.policy ).toBe( 'replace' );
	} );
} );
