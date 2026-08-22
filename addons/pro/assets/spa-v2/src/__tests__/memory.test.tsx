/**
 * Pro SPA v2 — MemoryClient API + MemoryDrawer component tests.
 *
 * Covers:
 *   - memoryScopeStorageKey / readPersistedScope / persistScope  (pure logic)
 *   - MemoryClient constructor, recall, store, delete, getPreferences
 *   - MemoryDrawer open/close, tab rendering, ESC-dismiss
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import React from 'react';

import {
	memoryScopeStorageKey,
	readPersistedScope,
	persistScope,
	MemoryClient,
	type MemoryClientOptions,
} from '../api/memory';
import { MemoryDrawer, type MemoryDrawerProps } from '../components/shared/MemoryDrawer';

// ── Mocks ──────────────────────────────────────────────────────────────────────

vi.mock( '@wordpress/i18n', () => ( {
	__: ( text: string ) => text,
} ) );

// ── Test helpers ───────────────────────────────────────────────────────────────

function defaultDrawerProps(
	overrides: Partial< MemoryDrawerProps > = {}
): MemoryDrawerProps {
	return {
		endpoint: 'https://ex.com/wp-json/mcp-ai/v1/chat-memory',
		nonce: 'test-nonce',
		assistantId: 1,
		isOpen: true,
		activeTab: 'memories',
		onTabChange: vi.fn(),
		onClose: vi.fn(),
		toggleRef: { current: null },
		...overrides,
	};
}

const mockFetch = vi.fn();

function mockFetchOk( body: unknown, status = 200 ): void {
	mockFetch.mockResolvedValueOnce(
		new Response( JSON.stringify( body ), { status } )
	);
}

// ── Hooks ──────────────────────────────────────────────────────────────────────

beforeEach( () => {
	localStorage.clear();
	vi.stubGlobal( 'fetch', mockFetch );
	mockFetch.mockReset();
} );

afterEach( () => {
	vi.restoreAllMocks();
} );

// ═══════════════════════════════════════════════════════════════════════════════
// localStorage helpers
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'memoryScopeStorageKey', () => {
	it( 'returns the expected key for a numeric assistant id', () => {
		expect( memoryScopeStorageKey( 42 ) ).toBe(
			'nvoos-pro-spa.memory-scope.42'
		);
	} );

	it( 'handles zero', () => {
		expect( memoryScopeStorageKey( 0 ) ).toBe(
			'nvoos-pro-spa.memory-scope.0'
		);
	} );

	it( 'handles string assistant ids', () => {
		expect( memoryScopeStorageKey( 'abc-123' ) ).toBe(
			'nvoos-pro-spa.memory-scope.abc-123'
		);
	} );

	it( 'falls back to zero for falsy assistant ids', () => {
		expect( memoryScopeStorageKey( '' ) ).toBe(
			'nvoos-pro-spa.memory-scope.0'
		);
	} );
} );

describe( 'readPersistedScope', () => {
	it( 'returns default {wing:"", room:""} when nothing is stored', () => {
		expect( readPersistedScope( 1 ) ).toEqual( { wing: '', room: '' } );
	} );

	it( 'returns parsed wing and room from localStorage', () => {
		localStorage.setItem(
			'nvoos-pro-spa.memory-scope.1',
			JSON.stringify( { wing: 'lobby', room: 'main' } )
		);
		expect( readPersistedScope( 1 ) ).toEqual( {
			wing: 'lobby',
			room: 'main',
		} );
	} );

	it( 'tolerates missing wing/room keys', () => {
		localStorage.setItem(
			'nvoos-pro-spa.memory-scope.1',
			JSON.stringify( { other: 1 } )
		);
		expect( readPersistedScope( 1 ) ).toEqual( { wing: '', room: '' } );
	} );

	it( 'tolerates corrupt JSON', () => {
		localStorage.setItem( 'nvoos-pro-spa.memory-scope.1', '{bad' );
		expect( readPersistedScope( 1 ) ).toEqual( { wing: '', room: '' } );
	} );
} );

describe( 'persistScope', () => {
	it( 'stores JSON-serialised wing and room under the correct key', () => {
		persistScope( 7, 'east', 'kitchen' );
		const raw = localStorage.getItem( 'nvoos-pro-spa.memory-scope.7' );
		expect( raw ).not.toBeNull();
		expect( JSON.parse( raw! ) ).toEqual( { wing: 'east', room: 'kitchen' } );
	} );

	it( 'round-trips with readPersistedScope', () => {
		persistScope( 7, 'north', 'garage' );
		expect( readPersistedScope( 7 ) ).toEqual( {
			wing: 'north',
			room: 'garage',
		} );
	} );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// MemoryClient
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'MemoryClient', () => {
	const baseOpts: MemoryClientOptions = {
		endpoint: 'https://ex.com/wp-json/mcp-ai/v1/chat-memory/',
		nonce: 'wp-nonce-abc',
		assistantId: 99,
	};

	describe( 'constructor', () => {
		it( 'stores base, nonce, and assistantId', () => {
			const client = new MemoryClient( baseOpts );
			// Reach into private fields via bracket-access for assertions.
			expect( ( client as unknown as Record< string, unknown > ).base ).toBe(
				'https://ex.com/wp-json/mcp-ai/v1/chat-memory'
			);
			expect(
				( client as unknown as Record< string, unknown > ).nonce
			).toBe( 'wp-nonce-abc' );
			expect(
				( client as unknown as Record< string, unknown > ).assistantId
			).toBe( 99 );
		} );

		it( 'strips trailing slashes from the endpoint', () => {
			const client = new MemoryClient( {
				...baseOpts,
				endpoint: 'https://ex.com/api///',
			} );
			expect(
				( client as unknown as Record< string, unknown > ).base
			).toBe( 'https://ex.com/api' );
		} );
	} );

	describe( 'getPreferences()', () => {
		it( 'returns defaults when response is empty success', async () => {
			mockFetchOk( { success: true } );
			const client = new MemoryClient( baseOpts );
			const prefs = await client.getPreferences();
			expect( prefs ).toEqual( { enabled: true, autosummarize: false } );
		} );

		it( 'returns server values when present', async () => {
			mockFetchOk( { enabled: false, autosummarize: true } );
			const client = new MemoryClient( baseOpts );
			const prefs = await client.getPreferences();
			expect( prefs ).toEqual( { enabled: false, autosummarize: true } );
		} );

		it( 'includes X-WP-Nonce header', async () => {
			mockFetchOk( {} );
			const client = new MemoryClient( baseOpts );
			await client.getPreferences();

			const [ , init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit
			];
			const h = new Headers( init.headers );
			expect( h.get( 'X-WP-Nonce' ) ).toBe( 'wp-nonce-abc' );
		} );
	} );

	describe( 'recall()', () => {
		it( 'constructs the correct URL with all params', async () => {
			mockFetchOk( { contexts: [] } );
			const client = new MemoryClient( baseOpts );
			await client.recall( {
				wing: 'west',
				room: 'lounge',
				query: 'hello',
				limit: 10,
			} );

			const [ urlStr ] = mockFetch.mock.calls[ 0 ] as [ string ];
			const url = new URL( urlStr );
			expect( url.pathname ).toBe(
				'/wp-json/mcp-ai/v1/chat-memory/recall'
			);
			expect( url.searchParams.get( 'agent_id' ) ).toBe( '99' );
			expect( url.searchParams.get( 'wing' ) ).toBe( 'west' );
			expect( url.searchParams.get( 'room' ) ).toBe( 'lounge' );
			expect( url.searchParams.get( 'query' ) ).toBe( 'hello' );
			expect( url.searchParams.get( 'limit' ) ).toBe( '10' );
		} );

		it( 'omits undefined params from the URL', async () => {
			mockFetchOk( { contexts: [] } );
			const client = new MemoryClient( baseOpts );
			await client.recall( {} );

			const [ urlStr ] = mockFetch.mock.calls[ 0 ] as [ string ];
			const url = new URL( urlStr );
			expect( url.searchParams.has( 'wing' ) ).toBe( false );
			expect( url.searchParams.has( 'room' ) ).toBe( false );
			expect( url.searchParams.has( 'query' ) ).toBe( false );
			expect( url.searchParams.has( 'limit' ) ).toBe( false );
		} );

		it( 'extracts contexts from the "contexts" key', async () => {
			const items = [
				{ context_id: 'c1', title: 'One' },
				{ context_id: 'c2', title: 'Two' },
			];
			mockFetchOk( { contexts: items } );
			const client = new MemoryClient( baseOpts );
			const result = await client.recall( {} );
			expect( result ).toEqual( items );
		} );

		it( 'falls back to the "results" key', async () => {
			const items = [ { id: 'r1' } ];
			mockFetchOk( { results: items } );
			const client = new MemoryClient( baseOpts );
			const result = await client.recall( {} );
			expect( result ).toEqual( items );
		} );

		it( 'returns an empty array for unknown response shapes', async () => {
			mockFetchOk( { something: 'else' } );
			const client = new MemoryClient( baseOpts );
			const result = await client.recall( {} );
			expect( result ).toEqual( [] );
		} );
	} );

	describe( 'store()', () => {
		it( 'sends a POST with the correct body', async () => {
			mockFetchOk( { context_id: 'new-ctx' } );
			const client = new MemoryClient( baseOpts );
			await client.store( {
				content: 'Hello world',
				title: 'Greeting',
				importance: 'high',
				tags: [ 'tag1' ],
				wing: 'east',
				room: 'hall',
			} );

			const [ , init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit
			];
			const body = JSON.parse( init.body as string );
			expect( body ).toEqual( {
				agent_id: 99,
				content: 'Hello world',
				title: 'Greeting',
				importance: 'high',
				tags: [ 'tag1' ],
				wing: 'east',
				room: 'hall',
			} );
		} );

		it( 'omits optional fields when not provided', async () => {
			mockFetchOk( { context_id: 'minimal' } );
			const client = new MemoryClient( baseOpts );
			await client.store( { content: 'Minimal' } );

			const [ , init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit
			];
			const body = JSON.parse( init.body as string );
			expect( body ).toEqual( { agent_id: 99, content: 'Minimal' } );
		} );
	} );

	describe( 'delete()', () => {
		it( 'constructs a DELETE URL with the encoded context id and agent_id', async () => {
			mockFetch.mockResolvedValueOnce(
				new Response( null, { status: 204 } )
			);
			const client = new MemoryClient( baseOpts );
			await client.delete( 'ctx-abc/123' );

			const [ urlStr, init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit
			];
			expect( init.method ).toBe( 'DELETE' );
			const url = new URL( urlStr );
			expect( url.pathname ).toBe(
				'/wp-json/mcp-ai/v1/chat-memory/ctx-abc%2F123'
			);
			expect( url.searchParams.get( 'agent_id' ) ).toBe( '99' );
		} );
	} );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// MemoryDrawer component
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'MemoryDrawer', () => {
	it( 'renders nothing when isOpen is false', () => {
		const props = defaultDrawerProps( { isOpen: false } );
		const { container } = render( <MemoryDrawer { ...props } /> );
		expect( container.innerHTML ).toBe( '' );
	} );

	it( 'renders the drawer when isOpen is true', () => {
		const props = defaultDrawerProps( { isOpen: true } );
		render( <MemoryDrawer { ...props } /> );
		expect(
			screen.getByRole( 'dialog', { name: 'Memory' } )
		).toBeInTheDocument();
	} );

	it( 'tags memories merged from a virtual agent bucket with stored-under', async () => {
		// The recall response (server-side alias merge) tags records that
		// were stored under a different agent key.
		mockFetch.mockImplementation( async ( input: RequestInfo | URL ) => {
			const url = String( input );
			if ( url.includes( '/recall' ) ) {
				return new Response(
					JSON.stringify( {
						contexts: [
							{
								context_id: 'm-1',
								title: 'Alias memory',
								content: 'From the virtual bucket',
								importance: 'medium',
								stored_under: 'nvoos-pro-spa-memory-drawer',
							},
							{
								context_id: 'm-2',
								title: 'Canonical memory',
								content: 'Stored directly',
								importance: 'medium',
							},
						],
					} ),
					{ status: 200 }
				);
			}
			return new Response(
				JSON.stringify( { enabled: true, autosummarize: false } ),
				{ status: 200 }
			);
		} );

		render( <MemoryDrawer { ...defaultDrawerProps() } /> );

		const items = await screen.findAllByTestId( 'nvoos-pro-spa-memory-item' );
		expect( items ).toHaveLength( 2 );

		const chip = within( items[ 0 ] ).getByTestId(
			'nvoos-pro-spa-memory-stored-under'
		);
		expect( chip ).toHaveTextContent( 'stored under' );
		expect( chip ).toHaveTextContent( 'nvoos-pro-spa-memory-drawer' );

		expect(
			within( items[ 1 ] ).queryByTestId( 'nvoos-pro-spa-memory-stored-under' )
		).toBeNull();
	} );

	it( 'renders three tabs', () => {
		const props = defaultDrawerProps( { isOpen: true } );
		render( <MemoryDrawer { ...props } /> );
		const tabs = screen.getAllByRole( 'tab' );
		expect( tabs ).toHaveLength( 3 );
		expect( tabs[ 0 ] ).toHaveTextContent( 'Memories' );
		expect( tabs[ 1 ] ).toHaveTextContent( 'Scope' );
		expect( tabs[ 2 ] ).toHaveTextContent( 'Audit' );
	} );

	it( 'calls onClose when Escape is pressed', () => {
		const onClose = vi.fn();
		const props = defaultDrawerProps( { isOpen: true, onClose } );
		render( <MemoryDrawer { ...props } /> );

		fireEvent.keyDown( document, { key: 'Escape' } );
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not call onClose on Escape when drawer is closed', () => {
		const onClose = vi.fn();
		const props = defaultDrawerProps( { isOpen: false, onClose } );
		render( <MemoryDrawer { ...props } /> );

		fireEvent.keyDown( document, { key: 'Escape' } );
		expect( onClose ).not.toHaveBeenCalled();
	} );

	it( 'calls onClose when the close button is clicked', () => {
		const onClose = vi.fn();
		const props = defaultDrawerProps( { isOpen: true, onClose } );
		render( <MemoryDrawer { ...props } /> );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Close memory drawer' } )
		);
		expect( onClose ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'marks the active tab as selected', () => {
		const props = defaultDrawerProps( {
			isOpen: true,
			activeTab: 'scope',
		} );
		render( <MemoryDrawer { ...props } /> );
		const scopeTab = screen.getByRole( 'tab', { name: 'Scope' } );
		expect( scopeTab ).toHaveAttribute( 'aria-selected', 'true' );
	} );

	it( 'calls onTabChange when a tab is clicked', () => {
		const onTabChange = vi.fn();
		const props = defaultDrawerProps( {
			isOpen: true,
			activeTab: 'memories',
			onTabChange,
		} );
		render( <MemoryDrawer { ...props } /> );

		fireEvent.click( screen.getByRole( 'tab', { name: 'Audit' } ) );
		expect( onTabChange ).toHaveBeenCalledWith( 'audit' );
	} );
} );
