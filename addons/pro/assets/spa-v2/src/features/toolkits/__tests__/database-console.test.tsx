// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview @fileoverview Database console tests (Proposal 167 P3).
 *
 * The console is read-only and endpoint-gated, so the tests cover the three
 * ways it can mislead: a grant rendered without its mask, an audit row rendered
 * without its refusal, and a runtime claiming a surface it does not serve.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';

import { DatabaseConsole } from '../database/DatabaseConsole';

function setRuntime( databaseEndpoint = 'https://example.com/wp-json/mcp-ai-pro/v1/database' ): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/mcp-ai/v1',
		proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
		nonce: 'test-nonce',
		config: { assistantId: 1, theme: 'auto', mode: 'admin' },
		endpoints: {
			chat: 'https://example.com/wp-json/mcp-ai/v1/chat',
			chatClient: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
			transcripts: '',
			tools: 'https://example.com/wp-json/mcp-ai/v1/tools',
			assistants: 'https://example.com/wp-json/mcp-ai/v1/assistants',
			settings: 'https://example.com/wp-json/mcp-ai/v1/settings',
			database: databaseEndpoint,
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [ 'manage_options' ] },
		mentionTypes: [],
	};
}

const OVERVIEW = {
	success: true,
	data: {
		runtime: { available: true, reason: '', reason_label: 'The database toolkit is available.', php: '8.3.0' },
		enabled: true,
		write_path: false,
		connections: [
			{
				id: 'warehouse',
				label: 'Warehouse',
				engine: 'pgsql',
				kind: 'database',
				queryable: true,
				probe: {
					reachable: true,
					role: 'nvoos_reader',
					can_write: true,
					bypasses_rls: false,
					checked_at: 1764000000,
				},
				host: 'db.internal',
				port: 5432,
				database: 'analytics',
				sslmode: 'require',
				role: 'nvoos_reader',
				has_credential: true,
			},
		],
		scopes: [
			{
				id: 'analyst',
				label: 'Analyst',
				connection: 'warehouse',
				schemas: [ 'public' ],
				tables: [ 'public.orders' ],
				columns: { 'public.customers': { sensitive: [ 'email' ], searchable: [ 'name' ] } },
				row_filter: [ { column: 'tenant_id', op: 'eq', value_from: 'scope_tenant', relation: 'public.orders' } ],
				tiers: [ 'metadata', 'read' ],
				limits: { max_rows: 500 },
				principals: [ 'user:*' ],
				version: 3,
			},
		],
		pdo: { pgsql: true, mysql: false, sqlite: true },
		limits: { max_rows: 500, max_bytes: 2097152, timeout_ms: 5000, cell_bytes: 16384 },
		audit: {
			retained: 500,
			total_24h: 3,
			refusals: { db_timeout: 1 },
			buckets: [
				{ ts: 1764000000, calls: 2, refusals: 1 },
				{ ts: 1764003600, calls: 1, refusals: 0 },
			],
		},
		admin_url: 'https://example.com/wp-admin/admin.php?page=wp-mcp-ai-database',
	},
};

const AUDIT = {
	success: true,
	data: {
		records: [
			{
				id: 'aud_1',
				ts: 1764003600,
				connection: 'warehouse',
				scope: 'analyst',
				scope_version: 3,
				principal: 'user:7',
				action: 'select',
				tool: 'db_select',
				relation: 'public.orders',
				refusal_code: 'db_timeout',
				sql_template: 'SELECT "id" FROM "public"."orders" WHERE "status" = ?',
				fingerprint: 'sha256:abc',
				row_count: 0,
				elapsed_ms: 5100,
				truncated: '',
				detail: '',
			},
		],
		filters: {},
		retained: 500,
		summary: { total: 1, db_timeout: 1 },
	},
};

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

interface MockRoute {
	status?: number;
	body: unknown;
}

/**
 * A fetch mock that can fail, and that records each request body.
 *
 * `setFetchRoutes` always answers 200, which cannot express a refusal; the
 * editor and preview tests need to see one.
 */
function setFetchMocks( routes: Record< string, MockRoute >, seen: Array< { url: string; body: string } > = [] ): void {
	global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
		const url = String( input );
		const match = Object.keys( routes ).find( ( key ) => url.includes( key ) );
		const route = match ? routes[ match ] : { body: {} };
		const status = route.status ?? 200;

		seen.push( { url, body: typeof init?.body === 'string' ? init.body : '' } );

		return Promise.resolve( {
			ok: status < 400,
			status,
			json: () => Promise.resolve( route.body ),
		} as Response );
	} ) as unknown as typeof fetch;
}

const SAVED_SCOPE = {
	success: true,
	data: {
		scope: {
			id: 'editor-analyst',
			label: 'Editor analyst',
			connection: 'warehouse',
			schemas: [ 'public' ],
			tables: [ 'public.orders' ],
			columns: {},
			row_filter: [],
			tiers: [ 'metadata', 'read' ],
			limits: {},
			principals: [ 'user:*' ],
			version: 1,
		},
	},
};

const PREVIEW = {
	success: true,
	data: {
		preview: {
			connection: 'warehouse',
			scope: 'analyst',
			relation: 'public.orders',
			engine: 'pgsql',
			statement:
				'SELECT "id", "email", "tenant_id" FROM "public"."orders" WHERE "tenant_id" = :p1 LIMIT 500',
			bindings: 1,
			projected: [ 'id', 'email', 'tenant_id' ],
			masked: [ 'email' ],
			excluded: [ 'card_number' ],
			searchable: [ 'name' ],
			row_limit: 500,
			limits: { max_rows: 500, timeout_ms: 5000 },
			tenant_bound: true,
		},
	},
};

afterEach( () => {
	vi.restoreAllMocks();
	cleanup();
	delete ( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA;
} );

describe( 'DatabaseConsole', () => {
	it( 'renders a connection and refuses to call a writable role read-safe', async () => {
		setRuntime();
		setFetchRoutes( { '/database/overview': OVERVIEW } );

		render( <DatabaseConsole /> );

		const row = await screen.findByRole( 'row', { name: /warehouse/i } );

		expect( within( row ).getByText( 'db.internal:5432 / analytics' ) ).toBeInTheDocument();
		expect( within( row ).getByText( 'Reachable' ) ).toBeInTheDocument();
		expect( within( row ).getByText( 'No' ) ).toBeInTheDocument();

		expect( screen.getAllByRole( 'tab' ) ).toHaveLength( 4 );
	} );

	it( 'renders masks, tiers and row filters exactly as the scope stores them', async () => {
		setRuntime();
		setFetchRoutes( { '/database/overview': OVERVIEW } );

		render( <DatabaseConsole /> );

		await screen.findByRole( 'row', { name: /warehouse/i } );
		fireEvent.click( screen.getByRole( 'tab', { name: 'Scopes' } ) );

		expect( screen.getByText( 'Analyst' ) ).toBeInTheDocument();

		const relations = screen.getByText( 'Relations' ).parentElement;
		expect( relations ).not.toBeNull();
		expect( within( relations as HTMLElement ).getByText( 'public.orders' ) ).toBeInTheDocument();

		expect( screen.getByText( 'email' ) ).toBeInTheDocument();
		expect( screen.getByText( 'metadata' ) ).toBeInTheDocument();
		expect( screen.getByText( 'tenant_id eq scope_tenant' ) ).toBeInTheDocument();
	} );

	it( 'shows the audit tail with its refusal code and exports it as CSV', async () => {
		setRuntime();
		setFetchRoutes( { '/database/overview': OVERVIEW, '/database/audit': AUDIT } );

		const createObjectURL = vi.fn( () => 'blob:mock' );
		Object.defineProperty( URL, 'createObjectURL', { configurable: true, value: createObjectURL } );
		Object.defineProperty( URL, 'revokeObjectURL', { configurable: true, value: vi.fn() } );
		const click = vi.spyOn( HTMLAnchorElement.prototype, 'click' ).mockImplementation( () => undefined );

		render( <DatabaseConsole /> );

		await screen.findByRole( 'row', { name: /warehouse/i } );
		fireEvent.click( screen.getByRole( 'tab', { name: 'Activity' } ) );

		const row = await screen.findByRole( 'row', { name: /db_select/i } );

		expect( within( row ).getByText( 'db_timeout' ) ).toBeInTheDocument();
		expect( within( row ).getByText( /SELECT "id" FROM "public"."orders"/ ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Export CSV' } ) );

		expect( createObjectURL ).toHaveBeenCalled();
		expect( click ).toHaveBeenCalled();
	} );

	it( 'reports driver status and the refusal series on the health tab', async () => {
		setRuntime();
		setFetchRoutes( { '/database/overview': OVERVIEW } );

		render( <DatabaseConsole /> );

		await screen.findByRole( 'row', { name: /warehouse/i } );
		fireEvent.click( screen.getByRole( 'tab', { name: 'Health' } ) );

		expect( screen.getByText( 'PostgreSQL (pdo_pgsql)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'MySQL / MariaDB (pdo_mysql)' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'Missing' ) ).toHaveLength( 1 );

		const chart = screen.getByRole( 'img', { name: /Hourly calls for the last 24 hours/ } );
		expect( chart ).toBeInTheDocument();
	} );

	it( 'says so when the runtime does not serve the console', async () => {
		setRuntime( '' );
		setFetchRoutes( {} );

		render( <DatabaseConsole /> );

		await waitFor( () => {
			expect( screen.getByText( 'The database console is not served on this runtime' ) ).toBeInTheDocument();
		} );
	} );

	it( 'refuses to save a scope without a connection, without a round trip', async () => {
		const seen: Array< { url: string; body: string } > = [];

		setRuntime();
		setFetchMocks( { '/database/overview': { body: OVERVIEW }, '/database/scopes': { body: SAVED_SCOPE } }, seen );

		render( <DatabaseConsole /> );

		await screen.findByRole( 'row', { name: /warehouse/i } );
		fireEvent.click( screen.getByRole( 'tab', { name: 'Scopes' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'New scope' } ) );

		fireEvent.change( screen.getByLabelText( 'Id' ), { target: { value: 'editor-analyst' } } );
		fireEvent.change( screen.getByLabelText( /^Schemas/ ), { target: { value: 'public' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save scope' } ) );

		expect( await screen.findByText( 'Choose a connection.' ) ).toBeInTheDocument();
		expect( seen.filter( ( request ) => request.url.includes( '/scopes' ) ) ).toHaveLength( 0 );
	} );

	it( 'saves a draft through the registry and reports the new grant version', async () => {
		const seen: Array< { url: string; body: string } > = [];

		setRuntime();
		setFetchMocks( { '/database/overview': { body: OVERVIEW }, '/database/scopes': { body: SAVED_SCOPE } }, seen );

		render( <DatabaseConsole /> );

		await screen.findByRole( 'row', { name: /warehouse/i } );
		fireEvent.click( screen.getByRole( 'tab', { name: 'Scopes' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'New scope' } ) );

		fireEvent.change( screen.getByLabelText( 'Id' ), { target: { value: 'editor-analyst' } } );
		fireEvent.change( screen.getByLabelText( 'Label' ), { target: { value: 'Editor analyst' } } );
		fireEvent.change( screen.getByLabelText( 'Connection' ), { target: { value: 'warehouse' } } );
		fireEvent.change( screen.getByLabelText( /^Schemas/ ), { target: { value: 'public' } } );
		fireEvent.change( screen.getByLabelText( /^Relations/ ), { target: { value: 'public.orders' } } );
		fireEvent.change( screen.getByLabelText( /^Principals/ ), { target: { value: 'user:*' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save scope' } ) );

		expect( await screen.findByText( /at version 1/ ) ).toBeInTheDocument();

		const post = seen.find( ( request ) => request.url.includes( '/database/scopes' ) );

		expect( post ).toBeDefined();

		const body = JSON.parse( post?.body ?? '{}' ) as { scope: Record< string, unknown > };

		expect( body.scope.id ).toBe( 'editor-analyst' );
		expect( body.scope.connection ).toBe( 'warehouse' );
		expect( body.scope.schemas ).toEqual( [ 'public' ] );
		expect( body.scope.tables ).toEqual( [ 'public.orders' ] );
		expect( body.scope.tiers ).toEqual( [ 'metadata', 'read' ] );
	} );

	it( 'compiles a saved scope and renders masks without a bound value', async () => {
		setRuntime();
		setFetchMocks( { '/database/overview': { body: OVERVIEW }, '/database/preview': { body: PREVIEW } } );

		render( <DatabaseConsole /> );

		await screen.findByRole( 'row', { name: /warehouse/i } );
		fireEvent.click( screen.getByRole( 'tab', { name: 'Scopes' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Preview' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Compile' } ) );

		const statement = await screen.findByLabelText( 'Compiled statement' );

		expect( statement ).toHaveTextContent( 'FROM "public"."orders"' );
		expect( statement ).toHaveTextContent( ':p1' );

		const masked = screen.getByText( 'Masked' ).parentElement as HTMLElement;

		expect( within( masked ).getByText( 'email' ) ).toBeInTheDocument();
		expect( screen.getByText( 'tenant predicate applied' ) ).toBeInTheDocument();
		expect( screen.getByText( '1 bound value(s)' ) ).toBeInTheDocument();
		expect( screen.getByText( 'row cap 500' ) ).toBeInTheDocument();
	} );

	it( 'surfaces a preview refusal rather than swallowing it', async () => {
		setRuntime();
		setFetchMocks( {
			'/database/overview': { body: OVERVIEW },
			'/database/preview': {
				status: 403,
				body: {
					success: false,
					error: { code: 'db_tier_required', message: 'Scope analyst does not hold the read tier.' },
				},
			},
		} );

		render( <DatabaseConsole /> );

		await screen.findByRole( 'row', { name: /warehouse/i } );
		fireEvent.click( screen.getByRole( 'tab', { name: 'Scopes' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Preview' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: 'Compile' } ) );

		expect( await screen.findByText( 'The preview was refused' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Scope analyst does not hold the read tier.' ) ).toBeInTheDocument();
	} );
} );
