/**
 * Nav gating tests — the spa-v2 route catalogue and its visibility matrix.
 *
 * Covers: capability + feature filtering, the fail-open rule for an absent
 * feature map, and catalogue/gate consistency with the console's table.
 */

import { describe, expect, it } from 'vitest';

import { SPA_V2_NAV_ROUTES, isRouteVisible } from '../utils/navGates';

const ADMIN_CAPS = [ 'manage_options', 'edit_posts' ];
const EDITOR_CAPS = [ 'edit_posts' ];

const FEATURES_NONE: Record< string, boolean > = {
	docs_hub: false,
	knowledge_graph: false,
	media_studio: false,
	document_editor: false,
};

describe( 'isRouteVisible', () => {
	it( 'shows ungated routes for any logged-in session', () => {
		expect( isRouteVisible( {}, FEATURES_NONE, EDITOR_CAPS ) ).toBe( true );
	} );

	it( 'hides routes whose capability the session lacks', () => {
		expect( isRouteVisible( { capability: 'manage_options' }, undefined, EDITOR_CAPS ) ).toBe( false );
		expect( isRouteVisible( { capability: 'manage_options' }, undefined, ADMIN_CAPS ) ).toBe( true );
	} );

	it( 'hides routes whose feature the server reports unavailable', () => {
		expect(
			isRouteVisible( { capability: 'manage_options', feature: 'docs_hub' }, FEATURES_NONE, ADMIN_CAPS )
		).toBe( false );
	} );

	it( 'fails open when the feature map is absent or unknown', () => {
		expect(
			isRouteVisible( { capability: 'manage_options', feature: 'docs_hub' }, undefined, ADMIN_CAPS )
		).toBe( true );
		expect(
			isRouteVisible( { capability: 'manage_options', feature: 'docs_hub' }, {}, ADMIN_CAPS )
		).toBe( true );
	} );
} );

describe( 'SPA_V2_NAV_ROUTES catalogue', () => {
	it( 'declares unique command ids and hash targets', () => {
		const ids = new Set( SPA_V2_NAV_ROUTES.map( ( r ) => r.id ) );
		const hashes = new Set( SPA_V2_NAV_ROUTES.map( ( r ) => r.hash ) );
		expect( ids.size ).toBe( SPA_V2_NAV_ROUTES.length );
		expect( hashes.size ).toBe( SPA_V2_NAV_ROUTES.length );
	} );

	it( 'gates the optional surfaces and admin routes', () => {
		const gated = SPA_V2_NAV_ROUTES.filter( ( r ) => r.capability || r.feature );
		expect( gated.map( ( r ) => r.id ) ).toEqual( [
			'nav-settings',
			'nav-workflows',
			'nav-analytics',
			'nav-knowledge-graph',
			'nav-docs',
			'nav-media',
			'nav-documents',
			'nav-approvals',
			'nav-slash-commands',
			'nav-workflow-triggers',
			'nav-teams',
			'nav-portability',
			'nav-security-center',
			'nav-operations',
			'nav-diagnostics',
			'nav-token-manager',
			'nav-orchestration-stats',
			'nav-pro-ops',
			'nav-schedule-manager',
		] );
	} );
} );
