/**
 * RecordsPage tests — remote-site persistence scoping.
 *
 * Proposal 089 Phase B persists the site picker selection in localStorage
 * under a single key shared by every Records page. The picker only renders
 * on `allowRemote` surfaces (Posts & Pages, Products), so the command-center
 * pages must ignore the stored value — otherwise a site chosen on Posts &
 * Pages would retarget their list/detail fetches at a remote connection that
 * may not allow the entity's post type (a 403 on every request).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor, within, fireEvent } from '@testing-library/react';

import { RecordsPage } from '../RecordsPage';

vi.mock( '@wordpress/i18n', () => ( {
	__: ( text: string ) => text,
	sprintf: ( text: string, ...args: unknown[] ) =>
		text.replace( /%(s|d)/g, () => String( args.shift() ?? '' ) ),
} ) );

const STORAGE_KEY = 'nvoos-console.records.site';

const PROFESSION_ENTITY = {
	key: 'profession',
	postType: 'mcp_ai_profession',
	restBase: 'mcp_ai_profession',
	label: 'Professions',
	singular: 'Profession',
	icon: '👔',
	group: 'professions',
	columns: [],
};

function setRuntime(): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/mcp-ai/v1',
		proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
		nonce: 'test-nonce',
		config: { assistantId: 1, theme: 'auto', mode: 'admin' },
		endpoints: {
			chat: '',
			chatClient: '',
			transcripts: '',
			threads: '',
			tools: '',
			assistants: '',
			settings: '',
			dashboardsSchema: '',
			recordsSchema: 'https://example.com/wp-json/mcp-ai-pro/v1/records/schema',
			wpBase: 'https://example.com/wp-json/wp/v2',
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
	window.localStorage.removeItem( STORAGE_KEY );
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
} );

describe( 'RecordsPage remote-site scoping', () => {
	it( 'ignores the persisted remote site on command-center pages', async () => {
		setRuntime();
		window.localStorage.setItem( STORAGE_KEY, 'remote-1' );
		setFetchRoutes( {
			'/records/schema': { entities: { profession: PROFESSION_ENTITY } },
			'/wp/v2/mcp_ai_profession': [],
		} );

		render( <RecordsPage group="professions" /> );

		await waitFor( () => {
			expect( global.fetch ).toHaveBeenCalledWith(
				expect.stringContaining( '/wp/v2/mcp_ai_profession' ),
				expect.anything()
			);
		} );

		expect( global.fetch ).not.toHaveBeenCalledWith(
			expect.stringContaining( '/records/remote/profession' ),
			expect.anything()
		);
		expect(
			screen.queryByText( 'Browsing a remote site — read-only. Create and Research are hidden.' )
		).not.toBeInTheDocument();
	} );

	it( 'uses the persisted remote site when the picker surface opts in', async () => {
		setRuntime();
		window.localStorage.setItem( STORAGE_KEY, 'remote-1' );
		setFetchRoutes( {
			'/records/schema': { entities: { profession: PROFESSION_ENTITY } },
			'/remote-sites': { items: [] },
			'/records/remote/profession': {
				success: true,
				data: { items: [], total: 0, total_pages: 1 },
			},
		} );

		render( <RecordsPage group="professions" allowRemote /> );

		await waitFor( () => {
			expect( global.fetch ).toHaveBeenCalledWith(
				expect.stringContaining( '/records/remote/profession' ),
				expect.anything()
			);
		} );

		expect( global.fetch ).toHaveBeenCalledWith(
			expect.stringContaining( 'remote_site=remote-1' ),
			expect.anything()
		);
		expect(
			screen.getByText( 'Browsing a remote site — read-only. Create and Research are hidden.' )
		).toBeInTheDocument();
	} );

	it( 'stays local on the picker surface when no site is persisted', async () => {
		setRuntime();
		setFetchRoutes( {
			'/records/schema': { entities: { profession: PROFESSION_ENTITY } },
			'/remote-sites': { items: [] },
			'/wp/v2/mcp_ai_profession': [],
		} );

		render( <RecordsPage group="professions" allowRemote /> );

		await waitFor( () => {
			expect( global.fetch ).toHaveBeenCalledWith(
				expect.stringContaining( '/wp/v2/mcp_ai_profession' ),
				expect.anything()
			);
		} );

		expect( global.fetch ).not.toHaveBeenCalledWith(
			expect.stringContaining( '/records/remote/profession' ),
			expect.anything()
		);
	} );
} );

describe( 'RecordsPage expanded-record detail', () => {
	it( 'renders core fields when the entity ships no columns', async () => {
		setRuntime();
		setFetchRoutes( {
			'/records/schema': { entities: { profession: PROFESSION_ENTITY } },
			// Detail route first — the mock matches the first route whose key
			// is contained in the URL.
			'/wp/v2/mcp_ai_profession/7': {
				id: 7,
				date: '2026-10-30T10:00:00',
				status: 'publish',
				title: { rendered: 'Radiologist' },
				meta: {},
			},
			'/wp/v2/mcp_ai_profession': [
				{
					id: 7,
					date: '2026-10-30T10:00:00',
					status: 'publish',
					title: { rendered: 'Radiologist' },
					meta: {},
				},
			],
		} );

		const { container } = render( <RecordsPage group="professions" /> );

		const rowButton = await screen.findByRole( 'button', { name: 'Radiologist' } );
		fireEvent.click( rowButton );

		await waitFor( () => {
			const grid = container.querySelector( '.nvoos-pro-spa-records-detail__grid' );
			expect( grid?.children.length ?? 0 ).toBeGreaterThan( 0 );
		} );

		const grid = container.querySelector( '.nvoos-pro-spa-records-detail__grid' ) as HTMLElement;
		const detail = within( grid );
		expect( detail.getByText( 'Date' ) ).toBeInTheDocument();
		expect( detail.getByText( 'Status' ) ).toBeInTheDocument();
		expect( detail.getByText( 'ID' ) ).toBeInTheDocument();
	} );
} );
