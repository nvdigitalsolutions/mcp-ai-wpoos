/**
 * CommerceToolkitPage tests (Proposal 089).
 *
 * Covers the toolkit catalogue contract, the unknown-toolkit error state,
 * the sync-runs tab over the read-only bridge, and the settings tab
 * narrowing (guardrail fields on the toolkit tab + the enable toggle pulled
 * in from the base `tools` tab by key).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor, fireEvent } from '@testing-library/react';

import { CommerceToolkitPage, COMMERCE_TOOLKITS } from '../CommerceToolkitPage';

function setRuntime(): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/mcp-ai/v1',
		proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
		nonce: 'test-nonce',
		config: { assistantId: 1, theme: 'auto', mode: 'admin' },
		endpoints: {
			chat: 'https://example.com/wp-json/mcp-ai/v1/chat',
			chatClient: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
			transcripts: '',
			threads: '',
			tools: 'https://example.com/wp-json/mcp-ai/v1/tools',
			assistants: 'https://example.com/wp-json/mcp-ai/v1/assistants',
			settings: 'https://example.com/wp-json/mcp-ai/v1/settings',
			memory: '',
			workflows: '',
			analytics: '',
			approvals: '',
			shortcuts: '',
			slashCommands: '',
			okf: '',
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

function setFetchRoutes( routes: Record< string, unknown > ): void {
	global.fetch = vi.fn( ( input: RequestInfo | URL ) => {
		const url = String( input );
		const match = Object.keys( routes ).find( ( key ) => url.includes( key ) );
		const payload = match ? routes[ match ] : {};
		return Promise.resolve( {
			ok: true,
			status: 200,
			json: () => Promise.resolve( payload ),
		} as Response );
	} ) as unknown as typeof fetch;
}

afterEach( () => {
	vi.restoreAllMocks();
	cleanup();
	window.localStorage.removeItem( 'nvoos-console.commerce.site' );
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
} );

describe( 'COMMERCE_TOOLKITS catalogue', () => {
	it( 'defines the four toolkits with settings tab, enable key, and log slug', () => {
		expect( Object.keys( COMMERCE_TOOLKITS ) ).toEqual( [
			'ecommerce',
			'ezuite',
			'shopify_sync',
			'flowhub',
		] );
		expect( COMMERCE_TOOLKITS.ecommerce ).toMatchObject( {
			settingsTab: 'ecommerce',
			enableKey: 'enable_ecommerce_toolkit',
			logSlug: 'woocommerce',
		} );
		expect( COMMERCE_TOOLKITS.ezuite ).toMatchObject( { logSlug: 'ezuite' } );
		expect( COMMERCE_TOOLKITS.shopify_sync ).toMatchObject( { logSlug: 'shopify_sync' } );
		expect( COMMERCE_TOOLKITS.flowhub ).toMatchObject( { logSlug: 'flowhub' } );
		expect( COMMERCE_TOOLKITS.flowhub.note ).toBeTruthy();
	} );
} );

describe( 'CommerceToolkitPage', () => {
	it( 'renders the unknown-toolkit error state', () => {
		render( <CommerceToolkitPage toolkit="mystery" /> );

		expect( screen.getByText( 'Unknown commerce toolkit.' ) ).toBeInTheDocument();
	} );

	it( 'lists sync runs from the read-only bridge on the Sync Runs tab', async () => {
		setRuntime();
		setFetchRoutes( {
			'/commerce/sync-runs': {
				success: true,
				data: {
					runs: [
						{
							toolkit: 'woocommerce',
							run_id: 'run-1',
							connection_id: '',
							dry_run: false,
							started_at: '2026-10-25 09:00:00',
							finished_at: '2026-10-25 09:01:00',
							status: 'completed',
							summary: { items_updated: 12, items_skipped: 3, items_errored: 1 },
						},
					],
				},
			},
		} );
		render( <CommerceToolkitPage toolkit="ecommerce" /> );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Sync Runs' } ) );

		await waitFor( () => {
			expect( screen.getByText( 'run-1' ) ).toBeInTheDocument();
		} );
		expect( screen.getByText( 'completed' ) ).toBeInTheDocument();
		expect( global.fetch ).toHaveBeenCalledWith(
			expect.stringContaining( '/mcp-ai-pro/v1/commerce/sync-runs?toolkit=woocommerce' ),
			expect.objectContaining( {
				credentials: 'same-origin',
				headers: expect.objectContaining( { 'X-WP-Nonce': 'test-nonce' } ),
			} )
		);
	} );

	it( 'renders the settings tab narrowed to the toolkit tab plus the enable toggle by key', async () => {
		setRuntime();
		setFetchRoutes( {
			'/settings/schema': {
				success: true,
				data: {
					tabs: { ecommerce: 'E-Commerce', tools: 'Tools & Features' },
					fields: [
						{
							key: 'enable_ecommerce_toolkit',
							type: 'checkbox',
							section_id: 'tools',
							section: 'Tools & Features',
							tab: 'tools',
							label: 'Enable E-commerce Toolkit',
							description: '',
							sensitive: false,
						},
						{
							key: 'ecommerce_safety_buffer',
							type: 'number',
							section_id: 'toolkit_ecommerce',
							section: 'E-Commerce Toolkit',
							tab: 'ecommerce',
							label: 'Safety buffer (units per SKU)',
							description: '',
							sensitive: false,
							default: 2,
							min: 0,
							max: 10,
						},
						{
							key: 'unrelated_field',
							type: 'text',
							section_id: 'general',
							section: 'General',
							tab: 'general',
							label: 'Unrelated field',
							description: '',
							sensitive: false,
						},
					],
				},
			},
			'/settings/values': { success: true, data: { values: {} } },
		} );
		render( <CommerceToolkitPage toolkit="ecommerce" /> );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Settings' } ) );

		// The enable toggle (tools tab) and the guardrail field (ecommerce
		// tab) render; the unrelated field does not.
		await waitFor( () => {
			expect( screen.getByText( 'Safety buffer (units per SKU)' ) ).toBeInTheDocument();
		} );
		expect( screen.getByText( 'Enable E-commerce Toolkit' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Unrelated field' ) ).not.toBeInTheDocument();
	} );

	it( 'sends remote_site on the sync-runs fetch when a WordPress remote site is selected (Phase B)', async () => {
		setRuntime();
		setFetchRoutes( {
			'/remote-sites': {
				items: [
					{
						id: 'store-1',
						name: 'Store One',
						connection_type: 'wordpress',
						url: 'https://store-one.example.com',
						enabled: 1,
						source: 'pro',
					},
					{
						id: 'shop-9',
						name: 'Shopify Store',
						connection_type: 'shopify',
						url: 'https://shop.example.com',
						enabled: 1,
						source: 'pro',
					},
				],
			},
			'/commerce/sync-runs': { success: true, data: { runs: [] } },
		} );
		render( <CommerceToolkitPage toolkit="ecommerce" /> );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Sync Runs' } ) );

		await waitFor( () => {
			expect( screen.getByText( 'Site' ) ).toBeInTheDocument();
		} );

		// Only WordPress connections are selectable.
		const picker = screen.getByLabelText( 'Site' ) as HTMLSelectElement;
		expect( picker.options.length ).toBe( 2 ); // local + Store One.
		expect( picker.options[ 1 ].text ).toBe( 'Store One' );

		fireEvent.change( picker, { target: { value: 'store-1' } } );

		await waitFor( () => {
			expect( global.fetch ).toHaveBeenCalledWith(
				expect.stringContaining( '/commerce/sync-runs?toolkit=woocommerce' ),
				expect.any( Object )
			);
			expect( global.fetch ).toHaveBeenCalledWith(
				expect.stringContaining( 'remote_site=store-1' ),
				expect.any( Object )
			);
		} );
	} );
} );
