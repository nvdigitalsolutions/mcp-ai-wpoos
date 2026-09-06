/**
 * Pro SPA v2 — embedded mode tests.
 *
 * Covers the shortcode-facing surface:
 *   - config.ts        — applyPerInstanceConfig (data-config overlay)
 *   - sse-adapter.ts   — guest token header behavior
 *   - EmbeddedApp.tsx  — boot fallbacks + embedded mount
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';

import { readProSpaConfig, applyPerInstanceConfig } from '../api/config';
import { createChatFetch } from '../sse-adapter';
import { EmbeddedApp } from '../features/embedded/EmbeddedApp';

const VALID_RUNTIME = {
	apiUrl: 'https://example.com/wp-json/',
	proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
	nonce: 'test-nonce',
	config: {
		assistantId: 1,
		theme: 'auto',
		mode: 'embedded',
		showSidebar: true,
	},
	endpoints: {
		chat: 'https://example.com/wp-json/mcp-ai/v1/chat',
		chatClient: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
		transcripts: '',
		threads: 'https://example.com/wp-json/mcp-ai/v1/threads',
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

function setRuntime( runtime: Record< string, unknown > | null ): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = runtime;
}

afterEach( () => {
	vi.restoreAllMocks();
	cleanup();
	setRuntime( null );
	localStorage.clear();
} );

describe( 'applyPerInstanceConfig', () => {
	it( 'returns null when the global is missing', () => {
		expect( applyPerInstanceConfig( { mode: 'embedded' } ) ).toBeNull();
	} );

	it( 'returns the runtime unchanged when no per-instance config exists', () => {
		setRuntime( VALID_RUNTIME );
		const runtime = applyPerInstanceConfig( null );
		expect( runtime ).not.toBeNull();
		expect( runtime!.config.assistantId ).toBe( 1 );
	} );

	it( 'overlays data-config onto the global runtime and persists it', () => {
		setRuntime( VALID_RUNTIME );
		applyPerInstanceConfig( {
			mode: 'embedded',
			assistantId: 42,
			height: '720px',
			guest: true,
			guestToken: 'tok_123',
		} );

		const merged = readProSpaConfig();
		expect( merged ).not.toBeNull();
		expect( merged!.config.mode ).toBe( 'embedded' );
		expect( merged!.config.assistantId ).toBe( 42 );
		expect( merged!.config.height ).toBe( '720px' );
		expect( merged!.config.guest ).toBe( true );
		expect( merged!.config.guestToken ).toBe( 'tok_123' );
		// Untouched fields survive the merge.
		expect( merged!.config.showSidebar ).toBe( true );
	} );
} );

describe( 'createChatFetch guest headers', () => {
	it( 'sends the guest token when guest mode is on and a token exists', async () => {
		const upstream = vi.fn().mockResolvedValue(
			new Response( null, { status: 200 } )
		);
		vi.stubGlobal( 'fetch', upstream );

		const chatFetch = createChatFetch( {
			endpoint: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
			nonce: '',
			assistantId: 42,
			guest: true,
			guestToken: 'tok_123',
		} );

		await chatFetch(
			'https://example.com/wp-json/mcp-ai/v1/chat-client',
			{
				method: 'POST',
				headers: {},
				body: JSON.stringify( { messages: [] } ),
			}
		);

		expect( upstream ).toHaveBeenCalledTimes( 1 );
		const [ , init ] = upstream.mock.calls[ 0 ] as [ string, RequestInit ];
		const headers = new Headers( init.headers );
		expect( headers.get( 'X-WP-MCP-AI-Guest' ) ).toBe( 'tok_123' );
		expect( headers.get( 'X-WP-Nonce' ) ).toBeNull();
	} );

	it( 'falls back to the boolean convention when no token is provided', async () => {
		const upstream = vi.fn().mockResolvedValue(
			new Response( null, { status: 200 } )
		);
		vi.stubGlobal( 'fetch', upstream );

		const chatFetch = createChatFetch( {
			endpoint: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
			nonce: '',
			assistantId: 42,
			guest: true,
		} );

		await chatFetch(
			'https://example.com/wp-json/mcp-ai/v1/chat-client',
			{
				method: 'POST',
				headers: {},
				body: JSON.stringify( { messages: [] } ),
			}
		);

		const [ , init ] = upstream.mock.calls[ 0 ] as [ string, RequestInit ];
		const headers = new Headers( init.headers );
		expect( headers.get( 'X-WP-MCP-AI-Guest' ) ).toBe( '1' );
	} );
} );

describe( 'EmbeddedApp', () => {
	it( 'renders an error fallback when the runtime config is missing', () => {
		setRuntime( null );
		render( <EmbeddedApp /> );
		// useBootstrap reports the missing config; EmbeddedApp wraps it in
		// its error fallback.
		expect(
			screen.getByText( /Configuration not found/i )
		).toBeInTheDocument();
	} );

	it( 'renders the embedded layout with a valid runtime', async () => {
		setRuntime( VALID_RUNTIME );

		// The job bus opens a cron-status SSE connection — stub fetch so the
		// stream attempt fails gracefully instead of hanging.
		vi.stubGlobal(
			'fetch',
			vi.fn().mockResolvedValue( { ok: false, body: null } )
		);

		const { container } = render( <EmbeddedApp /> );

		// Both the embedded wrapper and ChatPage carry role=main; wait for the
		// boot to settle and assert on the layout root directly.
		await waitFor( () => {
			expect(
				container.querySelector( '.nvoos-pro-spa-embedded__layout' )
			).not.toBeNull();
		} );
	}, 15000 );
} );
