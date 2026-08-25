/**
 * Pro SPA v2 — useTranscripts hook tests.
 *
 * Covers the conversation ⇄ assistant coupling:
 *   - a session key restored from localStorage hydrates its messages
 *   - an empty / unreadable stored session rotates to a fresh key so the
 *     sidebar shows "New Conversation" instead of a stale selection
 *   - opening a conversation reports the assistant that owns it
 *   - switching assistants starts a fresh conversation, except when the
 *     switch was triggered by opening one of that assistant's conversations
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, waitFor, act } from '@testing-library/react';

import { useTranscripts } from '../hooks/useTranscripts';
import { activeSessionStorageKey } from '../api/transcripts';

const ENDPOINT = 'https://ex.com/wp-json/mcp-ai/v1/chat-transcripts';
const STORED_KEY = 'wp-mcp-ai-session-restored';

const mockFetch = vi.fn();

interface RouteOptions {
	sessions?: Array< Record< string, unknown > >;
	detail?: Record< string, unknown > | null;
	detailStatus?: number;
}

/**
 * Route list vs. detail requests: only detail requests carry the session key
 * in the path (`/chat-transcripts/<key>`).
 */
function routeFetch( options: RouteOptions = {} ): void {
	const { sessions = [], detail = null, detailStatus = 200 } = options;
	mockFetch.mockImplementation( ( input: unknown ) => {
		const url = String( input );
		if ( url.includes( '/chat-transcripts/' ) ) {
			return Promise.resolve(
				new Response( JSON.stringify( { session: detail } ), {
					status: detailStatus,
				} )
			);
		}
		return Promise.resolve(
			new Response(
				JSON.stringify( {
					sessions,
					total: sessions.length,
					per_page: 50,
					page: 1,
				} ),
				{ status: 200 }
			)
		);
	} );
}

function detailRequests(): string[] {
	return mockFetch.mock.calls
		.map( ( call ) => String( call[ 0 ] ) )
		.filter( ( url ) => url.includes( '/chat-transcripts/' ) );
}

beforeEach( () => {
	localStorage.clear();
	vi.stubGlobal( 'fetch', mockFetch );
	mockFetch.mockReset();
} );

afterEach( () => {
	vi.restoreAllMocks();
	localStorage.clear();
} );

// ═══════════════════════════════════════════════════════════════════════════════
// Restoring the active conversation on mount
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'useTranscripts — restored session', () => {
	it( 'hydrates the stored session messages and reports its assistant', async () => {
		localStorage.setItem( activeSessionStorageKey( 7 ), STORED_KEY );
		routeFetch( {
			sessions: [ { session_key: STORED_KEY, assistant_id: 9, turn_count: 1 } ],
			detail: {
				session_key: STORED_KEY,
				assistant_id: 9,
				messages: [
					{ role: 'user', content: 'hello' },
					{ role: 'assistant', content: 'hi there' },
				],
			},
		} );
		const onSessionAssistantChange = vi.fn();

		const { result } = renderHook( () =>
			useTranscripts( {
				endpoint: ENDPOINT,
				nonce: 'n',
				assistantId: 7,
				onSessionAssistantChange,
			} )
		);

		await waitFor( () =>
			expect( result.current.initialMessages ).toHaveLength( 2 )
		);
		expect( result.current.sessionKey ).toBe( STORED_KEY );
		expect( onSessionAssistantChange ).toHaveBeenCalledWith( 9 );
	} );

	it( 'starts a fresh session when the stored session has no messages', async () => {
		localStorage.setItem( activeSessionStorageKey( 7 ), STORED_KEY );
		routeFetch( {
			detail: { session_key: STORED_KEY, assistant_id: 7, messages: [] },
		} );

		const { result } = renderHook( () =>
			useTranscripts( { endpoint: ENDPOINT, nonce: 'n', assistantId: 7 } )
		);

		await waitFor( () =>
			expect( result.current.sessionKey ).not.toBe( STORED_KEY )
		);
		expect( result.current.sessionKey ).toMatch( /^wp-mcp-ai-session-/ );
		expect( result.current.initialMessages ).toHaveLength( 0 );
	} );

	it( 'starts a fresh session when the stored session cannot be read', async () => {
		localStorage.setItem( activeSessionStorageKey( 7 ), STORED_KEY );
		routeFetch( { detail: null, detailStatus: 404 } );

		const { result } = renderHook( () =>
			useTranscripts( { endpoint: ENDPOINT, nonce: 'n', assistantId: 7 } )
		);

		await waitFor( () =>
			expect( result.current.sessionKey ).not.toBe( STORED_KEY )
		);
		expect( result.current.initialMessages ).toHaveLength( 0 );
	} );

	it( 'does not request a transcript when no session was stored', async () => {
		routeFetch( { sessions: [] } );

		const { result } = renderHook( () =>
			useTranscripts( { endpoint: ENDPOINT, nonce: 'n', assistantId: 7 } )
		);

		await waitFor( () => expect( result.current.sessions ).toEqual( [] ) );
		expect( detailRequests() ).toHaveLength( 0 );
	} );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// Selecting a conversation
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'useTranscripts — selectSession', () => {
	const OTHER_KEY = 'wp-mcp-ai-session-other';

	it( 'reports the assistant that owns the selected conversation', async () => {
		routeFetch( {
			sessions: [ { session_key: OTHER_KEY, assistant_id: 4, turn_count: 1 } ],
			detail: {
				session_key: OTHER_KEY,
				assistant_id: 4,
				messages: [ { role: 'user', content: 'question' } ],
			},
		} );
		const onSessionAssistantChange = vi.fn();

		const { result } = renderHook( () =>
			useTranscripts( {
				endpoint: ENDPOINT,
				nonce: 'n',
				assistantId: 7,
				onSessionAssistantChange,
			} )
		);

		await waitFor( () =>
			expect( result.current.sessions ).toHaveLength( 1 )
		);
		await act( async () => {
			await result.current.selectSession( OTHER_KEY );
		} );

		expect( result.current.sessionKey ).toBe( OTHER_KEY );
		expect( result.current.initialMessages ).toHaveLength( 1 );
		expect( onSessionAssistantChange ).toHaveBeenCalledWith( 4 );
	} );

	it( 'stays quiet when the conversation belongs to the active assistant', async () => {
		routeFetch( {
			sessions: [ { session_key: OTHER_KEY, assistant_id: 7, turn_count: 1 } ],
			detail: {
				session_key: OTHER_KEY,
				assistant_id: 7,
				messages: [ { role: 'user', content: 'question' } ],
			},
		} );
		const onSessionAssistantChange = vi.fn();

		const { result } = renderHook( () =>
			useTranscripts( {
				endpoint: ENDPOINT,
				nonce: 'n',
				assistantId: 7,
				onSessionAssistantChange,
			} )
		);

		await waitFor( () =>
			expect( result.current.sessions ).toHaveLength( 1 )
		);
		await act( async () => {
			await result.current.selectSession( OTHER_KEY );
		} );

		expect( onSessionAssistantChange ).not.toHaveBeenCalled();
	} );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// Switching assistants
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'useTranscripts — assistant switch', () => {
	it( 'starts a fresh conversation when the assistant changes', async () => {
		routeFetch( { sessions: [] } );

		const { result, rerender } = renderHook(
			( props: { assistantId: number } ) =>
				useTranscripts( {
					endpoint: ENDPOINT,
					nonce: 'n',
					assistantId: props.assistantId,
				} ),
			{ initialProps: { assistantId: 7 } }
		);

		const firstKey = result.current.sessionKey;
		rerender( { assistantId: 8 } );

		await waitFor( () =>
			expect( result.current.sessionKey ).not.toBe( firstKey )
		);
	} );

	it( 'keeps the conversation opened from another assistant', async () => {
		const CROSS_KEY = 'wp-mcp-ai-session-cross';
		routeFetch( {
			sessions: [ { session_key: CROSS_KEY, assistant_id: 8, turn_count: 1 } ],
			detail: {
				session_key: CROSS_KEY,
				assistant_id: 8,
				messages: [ { role: 'user', content: 'cross-assistant' } ],
			},
		} );

		// Mirrors the Layout wiring: the callback moves the active assistant,
		// which re-renders the hook with the new id.
		let assistantId = 7;
		const { result, rerender } = renderHook( () =>
			useTranscripts( {
				endpoint: ENDPOINT,
				nonce: 'n',
				assistantId,
				onSessionAssistantChange: ( next: number ) => {
					assistantId = next;
				},
			} )
		);

		await waitFor( () =>
			expect( result.current.sessions ).toHaveLength( 1 )
		);
		await act( async () => {
			await result.current.selectSession( CROSS_KEY );
		} );
		expect( assistantId ).toBe( 8 );

		rerender();

		await waitFor( () =>
			expect( result.current.sessionKey ).toBe( CROSS_KEY )
		);
		expect( result.current.initialMessages ).toHaveLength( 1 );
	} );
} );
