/**
 * chat-spa — unit tests.
 *
 * Focuses on pure-logic exports that run safely in jsdom:
 *   - transcripts.ts — generateSessionKey, activeSessionStorageKey, session helpers
 *   - memory.ts      — MemoryClient REST interactions (fetch mocked)
 *   - hitl.ts        — HitlClient REST interactions (fetch mocked)
 *   - sse-adapter.ts — createChatFetch translation pipeline
 *
 * translateFrame / parseSseBuffer are internal; their behaviour is verified
 * through createChatFetch in the adapter integration test at the bottom.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import React from 'react';

import {
	generateSessionKey,
	activeSessionStorageKey,
} from '../api/transcripts';

import {
	memoryScopeStorageKey,
	readPersistedScope,
	persistScope,
	MemoryClient,
} from '../api/memory';

import { HitlClient } from '../api/hitl';

import { createChatFetch } from '../sse-adapter';

import { MemoryDrawer, type MemoryDrawerProps } from '../components/MemoryDrawer';

vi.mock( '@wordpress/i18n', () => ( {
	__: ( text: string ) => text,
	sprintf: ( format: string ) => format,
} ) );

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Build a minimal mock fetch that returns a Response-like object. */
function mockJsonFetch( body: unknown, status = 200 ) {
	return vi.fn().mockResolvedValue( {
		ok:     status >= 200 && status < 300,
		status,
		json:   vi.fn().mockResolvedValue( body ),
		headers: { get: vi.fn().mockReturnValue( null ) },
	} );
}

function defaultDrawerProps(
	overrides: Partial< MemoryDrawerProps > = {}
): MemoryDrawerProps {
	return {
		endpoint:    'https://example.com/wp-json/mcp-ai/v1/chat-memory',
		nonce:       'test-nonce',
		assistantId: 1,
		isOpen:      true,
		activeTab:   'memories',
		onTabChange: vi.fn(),
		onClose:     vi.fn(),
		toggleRef:   { current: null },
		...overrides,
	};
}

afterEach( () => {
	vi.restoreAllMocks();
	localStorage.clear();
} );

// ---------------------------------------------------------------------------
// transcripts.ts — generateSessionKey
// ---------------------------------------------------------------------------
describe( 'generateSessionKey', () => {
	it( 'returns a string matching /^wp-mcp-ai-session-[0-9a-f]+$/', () => {
		const key = generateSessionKey();
		expect( key ).toMatch( /^wp-mcp-ai-session-[0-9a-f]+$/ );
	} );

	it( 'returns a different key on each call', () => {
		const a = generateSessionKey();
		const b = generateSessionKey();
		expect( a ).not.toBe( b );
	} );
} );

// ---------------------------------------------------------------------------
// transcripts.ts — activeSessionStorageKey
// ---------------------------------------------------------------------------
describe( 'activeSessionStorageKey', () => {
	it( 'returns the expected localStorage key for a numeric assistant id', () => {
		expect( activeSessionStorageKey( 42 ) ).toBe( 'nvoos-chat-spa.active-session.42' );
	} );

	it( 'returns the expected localStorage key for a string assistant id', () => {
		expect( activeSessionStorageKey( 'abc' ) ).toBe( 'nvoos-chat-spa.active-session.abc' );
	} );

	it( 'handles a zero assistant id', () => {
		expect( activeSessionStorageKey( 0 ) ).toBe( 'nvoos-chat-spa.active-session.0' );
	} );
} );

// ---------------------------------------------------------------------------
// memory.ts — memoryScopeStorageKey, readPersistedScope, persistScope
// ---------------------------------------------------------------------------
describe( 'memoryScopeStorageKey', () => {
	it( 'returns the expected key for a numeric assistant id', () => {
		expect( memoryScopeStorageKey( 7 ) ).toBe( 'nvoos-chat-spa.memory-scope.7' );
	} );
} );

describe( 'readPersistedScope', () => {
	it( 'returns { wing: "", room: "" } when nothing is stored', () => {
		expect( readPersistedScope( 1 ) ).toEqual( { wing: '', room: '' } );
	} );
} );

describe( 'persistScope + readPersistedScope', () => {
	it( 'round-trips wing and room through localStorage', () => {
		persistScope( 5, 'team-a', 'standup' );
		expect( readPersistedScope( 5 ) ).toEqual( { wing: 'team-a', room: 'standup' } );
	} );

	it( 'overwrites a previously persisted scope', () => {
		persistScope( 3, 'old-wing', 'old-room' );
		persistScope( 3, 'new-wing', 'new-room' );
		expect( readPersistedScope( 3 ) ).toEqual( { wing: 'new-wing', room: 'new-room' } );
	} );
} );

// ---------------------------------------------------------------------------
// memory.ts — MemoryClient
// ---------------------------------------------------------------------------
describe( 'MemoryClient', () => {
	it( 'getPreferences calls the /preferences endpoint with a GET request', async () => {
		const mockFetch = mockJsonFetch( { enabled: true, autosummarize: false } );
		vi.stubGlobal( 'fetch', mockFetch );

		const client = new MemoryClient( {
			endpoint:    'https://example.com/wp-json/mcp-ai/v1/chat-memory',
			nonce:       'nonce-abc',
			assistantId: 1,
		} );

		const prefs = await client.getPreferences();

		expect( mockFetch ).toHaveBeenCalledWith(
			'https://example.com/wp-json/mcp-ai/v1/chat-memory/preferences',
			expect.objectContaining( { method: 'GET' } )
		);
		expect( prefs.enabled ).toBe( true );
		expect( prefs.autosummarize ).toBe( false );
	} );

	it( 'getPreferences strips a trailing slash from the endpoint', async () => {
		const mockFetch = mockJsonFetch( { enabled: false, autosummarize: true } );
		vi.stubGlobal( 'fetch', mockFetch );

		const client = new MemoryClient( {
			endpoint:    'https://example.com/wp-json/mcp-ai/v1/chat-memory/',
			nonce:       'n',
			assistantId: 2,
		} );
		await client.getPreferences();

		const calledUrl: string = mockFetch.mock.calls[ 0 ][ 0 ];
		// Path portion should not have double slash (https:// scheme double-slash is expected).
		const pathPart = calledUrl.replace( /^https?:\/\//, '' );
		expect( pathPart ).not.toContain( '//' );
		expect( calledUrl ).toContain( '/preferences' );
	} );

	it( 'includes X-WP-Nonce header in requests', async () => {
		const mockFetch = mockJsonFetch( { enabled: true, autosummarize: false } );
		vi.stubGlobal( 'fetch', mockFetch );

		const client = new MemoryClient( {
			endpoint:    'https://example.com/wp-json/mcp-ai/v1/chat-memory',
			nonce:       'my-secret-nonce',
			assistantId: 1,
		} );
		await client.getPreferences();

		expect( mockFetch ).toHaveBeenCalledWith(
			expect.any( String ),
			expect.objectContaining( {
				headers: expect.objectContaining( { 'X-WP-Nonce': 'my-secret-nonce' } ),
			} )
		);
	} );
} );

// ---------------------------------------------------------------------------
// hitl.ts — HitlClient
// ---------------------------------------------------------------------------
describe( 'HitlClient', () => {
	it( 'listPending calls the approvals endpoint with method GET', async () => {
		const mockFetch = mockJsonFetch( [] );
		vi.stubGlobal( 'fetch', mockFetch );

		const client = new HitlClient( {
			endpoint: 'https://example.com/wp-json/mcp-ai/v1/approvals',
			nonce:    'test-nonce',
		} );

		const result = await client.listPending( {} );

		expect( mockFetch ).toHaveBeenCalledWith(
			expect.stringContaining( 'approvals' ),
			expect.objectContaining( { method: 'GET' } )
		);
		expect( result ).toEqual( [] );
	} );

	it( 'listPending appends assistant_id query param when provided', async () => {
		const mockFetch = mockJsonFetch( [] );
		vi.stubGlobal( 'fetch', mockFetch );

		const client = new HitlClient( {
			endpoint: 'https://example.com/wp-json/mcp-ai/v1/approvals',
			nonce:    'n',
		} );

		await client.listPending( { assistantId: 77 } );

		const calledUrl: string = mockFetch.mock.calls[ 0 ][ 0 ];
		expect( calledUrl ).toContain( 'assistant_id=77' );
	} );

	it( 'includes X-WP-Nonce in approval requests', async () => {
		const mockFetch = mockJsonFetch( [] );
		vi.stubGlobal( 'fetch', mockFetch );

		const client = new HitlClient( {
			endpoint: 'https://example.com/wp-json/mcp-ai/v1/approvals',
			nonce:    'secret-nonce',
		} );

		await client.listPending( {} );

		expect( mockFetch ).toHaveBeenCalledWith(
			expect.any( String ),
			expect.objectContaining( {
				headers: expect.objectContaining( { 'X-WP-Nonce': 'secret-nonce' } ),
			} )
		);
	} );
} );

// ---------------------------------------------------------------------------
// sse-adapter.ts — createChatFetch translation pipeline
// ---------------------------------------------------------------------------
describe( 'createChatFetch', () => {
	/**
	 * Feed a raw SSE string to createChatFetch and collect all translated
	 * chunks as a decoded string.
	 */
	async function translateSse( ssePayload: string ): Promise<string> {
		const encoder = new TextEncoder();
		const decoder = new TextDecoder();

		// Build a ReadableStream that emits the SSE payload as one chunk.
		const upstream = new ReadableStream<Uint8Array>( {
			start( controller ) {
				controller.enqueue( encoder.encode( ssePayload ) );
				controller.close();
			},
		} );

		const mockFetch = vi.fn().mockResolvedValue(
			new Response( upstream, { status: 200 } )
		);
		vi.stubGlobal( 'fetch', mockFetch );

		const chatFetch = createChatFetch( {
			endpoint:    'https://example.com/wp-json/mcp-ai/v1/chat-client',
			nonce:       'n',
			assistantId: 1,
			guest:       false,
		} );

		const response = await chatFetch( 'https://example.com/wp-json/mcp-ai/v1/chat-client', {
			method: 'POST',
			body:   JSON.stringify( { messages: [] } ),
		} );

		const chunks: string[] = [];
		const reader = response.body!.getReader();
		// eslint-disable-next-line no-constant-condition
		while ( true ) {
			const { value, done } = await reader.read();
			if ( done ) break;
			chunks.push( decoder.decode( value ) );
		}
		return chunks.join( '' );
	}

	it( 'translates a message_delta frame into a "0:" data-stream chunk', async () => {
		const ssePayload = 'data: {"type":"message_delta","delta":"hello"}\n\n';
		const output = await translateSse( ssePayload );
		expect( output ).toContain( '0:' );
		expect( output ).toContain( 'hello' );
	} );

	it( 'translates a tool_call_started frame into a "9:" data-stream chunk', async () => {
		const ssePayload =
			'data: {"type":"tool_call_started","id":"tc1","name":"search","arguments":{}}\n\n';
		const output = await translateSse( ssePayload );
		expect( output ).toContain( '9:' );
		expect( output ).toContain( 'tc1' );
	} );

	it( 'translates a tool_call_completed frame into an "a:" data-stream chunk', async () => {
		const ssePayload =
			'data: {"type":"tool_call_completed","id":"tc1","result":{"ok":true}}\n\n';
		const output = await translateSse( ssePayload );
		expect( output ).toContain( 'a:' );
		expect( output ).toContain( 'tc1' );
	} );

	it( 'translates a done frame into an "e:" data-stream chunk', async () => {
		const ssePayload = 'data: {"type":"done"}\n\n';
		const output = await translateSse( ssePayload );
		expect( output ).toContain( 'e:' );
		expect( output ).toContain( 'stop' );
	} );

	it( 'sends the correct headers for an authenticated (non-guest) session', async () => {
		const mockFetch = vi.fn().mockResolvedValue(
			new Response( new ReadableStream( { start( c ) { c.close(); } } ), { status: 200 } )
		);
		vi.stubGlobal( 'fetch', mockFetch );

		const chatFetch = createChatFetch( {
			endpoint:    'https://example.com/wp-json/mcp-ai/v1/chat-client',
			nonce:       'wp-nonce-xyz',
			assistantId: 5,
			guest:       false,
		} );

		await chatFetch( 'https://example.com/', { method: 'POST', body: '{}' } );

		const [ , init ] = mockFetch.mock.calls[ 0 ] as [ string, RequestInit ];
		const sentHeaders = new Headers( init.headers );
		expect( sentHeaders.get( 'X-WP-Nonce' ) ).toBe( 'wp-nonce-xyz' );
	} );

	it( 'sends X-WP-MCP-AI-Guest header instead of nonce for guest sessions', async () => {
		const mockFetch = vi.fn().mockResolvedValue(
			new Response( new ReadableStream( { start( c ) { c.close(); } } ), { status: 200 } )
		);
		vi.stubGlobal( 'fetch', mockFetch );

		const chatFetch = createChatFetch( {
			endpoint:    'https://example.com/wp-json/mcp-ai/v1/chat-client',
			nonce:       '',
			assistantId: 0,
			guest:       true,
		} );

		await chatFetch( 'https://example.com/', { method: 'POST', body: '{}' } );

		const [ , init ] = mockFetch.mock.calls[ 0 ] as [ string, RequestInit ];
		const sentHeaders = new Headers( init.headers );
		expect( sentHeaders.get( 'X-WP-MCP-AI-Guest' ) ).toBe( '1' );
	} );
} );

// ---------------------------------------------------------------------------
// MemoryDrawer — merged-bucket display
// ---------------------------------------------------------------------------

describe( 'MemoryDrawer', () => {
	it( 'tags memories merged from a virtual agent bucket with stored-under', async () => {
		const fetchMock = vi.fn().mockImplementation(
			async ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url.includes( '/recall' ) ) {
					return {
						ok:     true,
						status: 200,
						json:   vi.fn().mockResolvedValue( {
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
					};
				}
				return {
					ok:     true,
					status: 200,
					json:   vi.fn().mockResolvedValue( {
						enabled:       true,
						autosummarize: false,
					} ),
				};
			}
		);
		vi.stubGlobal( 'fetch', fetchMock );

		render(
			React.createElement( MemoryDrawer, defaultDrawerProps() )
		);

		const items = await screen.findAllByTestId( 'nvoos-chat-spa-memory-item' );
		expect( items ).toHaveLength( 2 );

		const chip = within( items[ 0 ] ).getByTestId(
			'nvoos-chat-spa-memory-stored-under'
		);
		expect( chip ).toHaveTextContent( 'stored under' );
		expect( chip ).toHaveTextContent( 'nvoos-pro-spa-memory-drawer' );

		expect(
			within( items[ 1 ] ).queryByTestId(
				'nvoos-chat-spa-memory-stored-under'
			)
		).toBeNull();
	} );
} );
