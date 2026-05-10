/**
 * toolkit-shell — unit tests.
 *
 * Tests manifest-client, resource-client, and the App component's
 * error / loading states. Heavy sub-components (TableView, etc.) are mocked
 * so no external DOM library is needed for rendering them.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Stub heavy sub-components so App renders without real CRUD views.
// ---------------------------------------------------------------------------
vi.mock( '../components/TableView', () => ( { TableView: () => null } ) );
vi.mock( '../components/KanbanView', () => ( { KanbanView: () => null } ) );
vi.mock( '../components/DetailView', () => ( { DetailView: () => null } ) );
vi.mock( '../components/FormView', () => ( { FormView: () => null } ) );

import { App } from '../App';
import { fetchManifest } from '../api/manifest-client';
import { listResource, createResource } from '../api/resource-client';
import type { Resource } from '../api/types';

// ---------------------------------------------------------------------------
// Shared bootstrap fixture (matches the shape read by getBootstrap()).
// ---------------------------------------------------------------------------
const BOOTSTRAP = {
	apiUrl:  'https://example.com/wp-json/nvoos-toolkit-shell/v1',
	nonce:   'test-nonce-123',
	proApi:  'https://example.com/wp-json/mcp-ai-pro/v1',
	baseApi: 'https://example.com/wp-json/mcp-ai/v1',
};

const RESOURCE: Resource = {
	name:        'posts',
	label:       'Posts',
	endpoint:    '/posts',
	primary_key: 'id',
	fields:      [],
};

beforeEach( () => {
	( window as any ).NVOOS_TOOLKIT_SHELL = BOOTSTRAP;
} );

afterEach( () => {
	vi.restoreAllMocks();
	delete ( window as any ).NVOOS_TOOLKIT_SHELL;
} );

// ---------------------------------------------------------------------------
// manifest-client
// ---------------------------------------------------------------------------
describe( 'fetchManifest', () => {
	it( 'calls the manifests endpoint with the correct URL and X-WP-Nonce header', async () => {
		const manifest = { version: '1', toolkit: 'my-tk', label: 'My Toolkit', views: [], resources: [] };
		const mockFetch = vi.fn().mockResolvedValue( {
			ok:   true,
			json: vi.fn().mockResolvedValue( manifest ),
		} );
		vi.stubGlobal( 'fetch', mockFetch );

		const result = await fetchManifest( 'my-tk' );

		expect( mockFetch ).toHaveBeenCalledWith(
			'https://example.com/wp-json/nvoos-toolkit-shell/v1/manifests/my-tk',
			expect.objectContaining( {
				headers: expect.objectContaining( { 'X-WP-Nonce': 'test-nonce-123' } ),
			} )
		);
		expect( result ).toEqual( manifest );
	} );

	it( 'throws when the server responds with a non-OK status', async () => {
		vi.stubGlobal( 'fetch', vi.fn().mockResolvedValue( { ok: false, status: 404 } ) );

		await expect( fetchManifest( 'missing' ) ).rejects.toThrow( 'HTTP 404' );
	} );
} );

// ---------------------------------------------------------------------------
// resource-client — listResource
// ---------------------------------------------------------------------------
describe( 'listResource', () => {
	it( 'passes page and per_page as query-string parameters', async () => {
		const mockFetch = vi.fn().mockResolvedValue( {
			ok:      true,
			json:    vi.fn().mockResolvedValue( [ { id: 1 } ] ),
			headers: { get: vi.fn().mockReturnValue( null ) },
		} );
		vi.stubGlobal( 'fetch', mockFetch );

		await listResource( 'mcp-ai/v1', RESOURCE, { page: 2, per_page: 10 } );

		const calledUrl: string = mockFetch.mock.calls[ 0 ][ 0 ];
		expect( calledUrl ).toContain( 'page=2' );
		expect( calledUrl ).toContain( 'per_page=10' );
	} );

	it( 'includes search in the query string when provided', async () => {
		const mockFetch = vi.fn().mockResolvedValue( {
			ok:      true,
			json:    vi.fn().mockResolvedValue( [] ),
			headers: { get: vi.fn().mockReturnValue( null ) },
		} );
		vi.stubGlobal( 'fetch', mockFetch );

		await listResource( 'mcp-ai/v1', RESOURCE, { search: 'hello' } );

		const calledUrl: string = mockFetch.mock.calls[ 0 ][ 0 ];
		expect( calledUrl ).toContain( 'search=hello' );
	} );
} );

// ---------------------------------------------------------------------------
// resource-client — createResource
// ---------------------------------------------------------------------------
describe( 'createResource', () => {
	it( 'sends a POST request with a JSON-encoded body', async () => {
		const mockFetch = vi.fn().mockResolvedValue( {
			ok:   true,
			json: vi.fn().mockResolvedValue( { id: 99, title: 'New Post' } ),
		} );
		vi.stubGlobal( 'fetch', mockFetch );

		const values = { title: 'New Post', status: 'publish' };
		await createResource( 'mcp-ai/v1', RESOURCE, values );

		expect( mockFetch ).toHaveBeenCalledWith(
			expect.any( String ),
			expect.objectContaining( {
				method: 'POST',
				body:   JSON.stringify( values ),
			} )
		);
	} );
} );

// ---------------------------------------------------------------------------
// App component — rendered states
// ---------------------------------------------------------------------------
describe( 'App', () => {
	it( 'shows an error when no toolkit prop is given', async () => {
		render( <App config={ {} } /> );
		// The error is set inside a useEffect — findByText waits for the update.
		expect( await screen.findByText( /No toolkit specified/i ) ).toBeInTheDocument();
	} );

	it( 'shows a loading indicator while the manifest is being fetched', async () => {
		// fetchManifest never resolves → state stays "loading".
		vi.stubGlobal( 'fetch', vi.fn().mockReturnValue( new Promise( () => {} ) ) );
		render( <App config={ { toolkit: 'test-toolkit' } } /> );
		expect( await screen.findByText( /Loading manifest/i ) ).toBeInTheDocument();
	} );
} );
