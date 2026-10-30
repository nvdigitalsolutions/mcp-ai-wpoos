/**
 * GatewayPage tests — catalog + policy rendering and the unconfigured state.
 */

import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';

import { GatewayPage } from '../GatewayPage';

function stubGateway( overrides: Partial<{ adminUrl: string; adminToken: string }> = {} ) {
	( window as unknown as { NVOOS_GATEWAY: unknown } ).NVOOS_GATEWAY = {
		adminUrl: 'https://mcp.nvoos.pro',
		adminToken: 'op-admin-token',
		...overrides,
	};
}

function clearGateway() {
	delete ( window as unknown as { NVOOS_GATEWAY?: unknown } ).NVOOS_GATEWAY;
}

beforeEach( () => {
	clearGateway();
	vi.restoreAllMocks();
} );

describe( 'GatewayPage', () => {
	it( 'shows the unconfigured state when the host injected no gateway config', () => {
		render( <GatewayPage /> );
		expect( screen.getByText( /fleet gateway is not connected/i ) ).toBeTruthy();
	} );

	it( 'renders the catalog and policy from the admin endpoints', async () => {
		stubGateway();
		vi.stubGlobal(
			'fetch',
			vi.fn( async ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url.endsWith( '/admin/catalog' ) ) {
					return new Response(
						JSON.stringify( {
							gateway: {
								name: 'NV oOS Gateway',
								version: '0.2.0',
								registryId: 'io.github.nvdigitalsolutions/nvoos-mcp-gateway',
								lifecycle: 'published',
							},
							sites: [
								{
									slug: 'site-a',
									url: 'https://example.com',
									lifecycle: 'draft',
									health: 'ok',
									lastOkAt: null,
									toolsAreNamespacedAs: 'site-a.<tool>',
								},
							],
						} ),
						{ status: 200 }
					);
				}
				if ( url.endsWith( '/admin/policy' ) ) {
					return new Response(
						JSON.stringify( {
							policy: { rules: [ { id: 'no-deletes', effect: 'deny' } ] },
							shadowPolicy: null,
							quotas: [ { pattern: 'delete_post', rate: 5, window_seconds: 3600, burst: 2 } ],
						} ),
						{ status: 200 }
					);
				}
				return new Response( '{}', { status: 404 } );
			} )
		);

		render( <GatewayPage /> );

		expect( await screen.findByText( /NV oOS Gateway/ ) ).toBeTruthy();
		expect( screen.getByText( 'site-a' ) ).toBeTruthy();
		expect( screen.getByText( 'draft' ) ).toBeTruthy();
		expect( await screen.findByText( /no-deletes/ ) ).toBeTruthy();
		expect( screen.getByText( 'delete_post' ) ).toBeTruthy();
		expect( screen.getByText( '5' ) ).toBeTruthy();
	} );

	it( 'surfaces gateway errors instead of crashing', async () => {
		stubGateway();
		vi.stubGlobal(
			'fetch',
			vi.fn( async () => new Response( '{}', { status: 401 } ) )
		);

		render( <GatewayPage /> );
		expect( await screen.findByText( /Gateway responded 401/ ) ).toBeTruthy();
	} );
} );
