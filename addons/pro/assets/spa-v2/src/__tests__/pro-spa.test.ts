/**
 * Pro SPA v2 — unit tests.
 *
 * Focuses on pure-logic exports that run safely in jsdom:
 *   - config.ts     — readProSpaConfig shape validation
 *   - transcripts.ts — generateSessionKey, activeSessionStorageKey
 *   - sse-adapter.ts — createChatFetch translation pipeline
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { readProSpaConfig } from '../api/config';
import {
	generateSessionKey,
	activeSessionStorageKey,
} from '../api/transcripts';
import { createChatFetch } from '../sse-adapter';

afterEach( () => {
	vi.restoreAllMocks();
	localStorage.clear();
} );

describe( 'readProSpaConfig', () => {
	it( 'returns null when the global is missing', () => {
		expect( readProSpaConfig() ).toBeNull();
	} );

	it( 'returns null when apiUrl is missing', () => {
		( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = { endpoints: {} };
		expect( readProSpaConfig() ).toBeNull();
	} );

	it( 'returns runtime when all required fields are present', () => {
		( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
			apiUrl: 'https://example.com/wp-json/',
			proApi: '',
			nonce: 'test-nonce',
			config: { assistantId: 1 },
			endpoints: {
				chat: '/mcp-ai/v1/chat',
				chatClient: '/mcp-ai/v1/chat-client',
				transcripts: '/mcp-ai/v1/chat-transcripts',
				threads: '/mcp-ai/v1/threads',
				tools: '/mcp-ai/v1/tools',
				assistants: '/mcp-ai/v1/assistants',
				settings: '/mcp-ai/v1/settings',
				memory: '',
				workflows: '',
				analytics: '',
				approvals: '',
			},
			user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
			mentionTypes: [],
		};
		const config = readProSpaConfig();
		expect( config ).not.toBeNull();
		expect( config!.apiUrl ).toBe( 'https://example.com/wp-json/' );
		expect( config!.nonce ).toBe( 'test-nonce' );
	} );
} );

describe( 'generateSessionKey', () => {
	it( 'returns a string matching the expected format', () => {
		const key = generateSessionKey();
		expect( key ).toMatch( /^wp-mcp-ai-session-[0-9a-f]+$/ );
	} );

	it( 'returns different keys on successive calls', () => {
		const a = generateSessionKey();
		const b = generateSessionKey();
		expect( a ).not.toBe( b );
	} );
} );

describe( 'activeSessionStorageKey', () => {
	it( 'returns expected key for numeric assistant id', () => {
		expect( activeSessionStorageKey( 42 ) ).toBe( 'nvoos-pro-spa.active-session.42' );
	} );

	it( 'handles zero', () => {
		expect( activeSessionStorageKey( 0 ) ).toBe( 'nvoos-pro-spa.active-session.0' );
	} );
} );

describe( 'createChatFetch', () => {
	async function translateSse( sse: string ): Promise< string > {
		const encoder = new TextEncoder();
		const decoder = new TextDecoder();

		const stream = new ReadableStream< Uint8Array >( {
			start( c ) { c.enqueue( encoder.encode( sse ) ); c.close(); },
		} );

		vi.stubGlobal( 'fetch', vi.fn().mockResolvedValue(
			new Response( stream, { status: 200 } )
		) );

		const cf = createChatFetch( {
			endpoint: 'https://ex.com/wp-json/mcp-ai/v1/chat-client',
			nonce: 'n',
			assistantId: 1,
			guest: false,
		} );

		const resp = await cf( 'https://ex.com/', { method: 'POST', body: '{}' } );
		const chunks: string[] = [];
		const reader = resp.body!.getReader();
		while ( true ) {
			const { value, done } = await reader.read();
			if ( done ) break;
			chunks.push( decoder.decode( value ) );
		}
		return chunks.join( '' );
	}

	it( 'translates message_delta into "0:" chunk', async () => {
		const out = await translateSse( 'data: {"type":"message_delta","delta":"hello"}\n\n' );
		expect( out ).toContain( '0:' );
		expect( out ).toContain( 'hello' );
	} );

	it( 'translates done frame into "e:" chunk', async () => {
		const out = await translateSse( 'data: {"type":"done"}\n\n' );
		expect( out ).toContain( 'e:' );
	} );

	it( 'sends X-WP-Nonce header for authenticated sessions', async () => {
		const mockFetch = vi.fn().mockResolvedValue(
			new Response( new ReadableStream( { start( c ) { c.close(); } } ), { status: 200 } )
		);
		vi.stubGlobal( 'fetch', mockFetch );

		const cf = createChatFetch( {
			endpoint: 'https://ex.com/',
			nonce: 'wp-nonce-xyz',
			assistantId: 5,
			guest: false,
		} );

		await cf( 'https://ex.com/', { method: 'POST', body: '{}' } );

		const [ , init ] = mockFetch.mock.calls[ 0 ] as [ string, RequestInit ];
		const h = new Headers( init.headers );
		expect( h.get( 'X-WP-Nonce' ) ).toBe( 'wp-nonce-xyz' );
	} );
} );
