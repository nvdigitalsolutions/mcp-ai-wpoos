/**
 * ProOpsPage render tests.
 *
 * Regression pin for React error #310: the page used to return its
 * loading/error states BEFORE the eight useMemo table builders. The
 * render-phase split (ProOpsTables mounts only with a non-null overview)
 * keeps the hook count stable across the loading → loaded transition.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';

import { ProOpsPage } from '../features/pro-ops/ProOpsPage';

const OPS_ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1/ops';

function setRuntime(): void {
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = {
		apiUrl: 'https://example.com/wp-json/mcp-ai/v1',
		proApi: 'https://example.com/wp-json/mcp-ai-pro/v1',
		nonce: 'test-nonce',
		config: { mode: 'admin' },
		endpoints: {
			chat: 'https://example.com/wp-json/mcp-ai/v1/chat',
			chatClient: 'https://example.com/wp-json/mcp-ai/v1/chat-client',
			transcripts: 'https://example.com/wp-json/mcp-ai/v1/chat-transcripts',
			threads: 'https://example.com/wp-json/mcp-ai/v1/threads',
			tools: 'https://example.com/wp-json/mcp-ai/v1/tools',
			assistants: 'https://example.com/wp-json/mcp-ai/v1/assistants',
			settings: 'https://example.com/wp-json/mcp-ai/v1/settings',
			proOps: OPS_ENDPOINT,
		},
		user: { id: 1, login: 'admin', displayName: 'Admin', capabilities: [] },
		mentionTypes: [],
	};
}

function jsonResponse( payload: unknown ): Response {
	return {
		ok: true,
		status: 200,
		json: async () => payload,
	} as Response;
}

afterEach( () => {
	vi.restoreAllMocks();
	cleanup();
	( window as unknown as Record< string, unknown > ).NVOOS_PRO_SPA = null;
} );

describe( 'ProOpsPage', () => {
	it( 'renders the service status table after the loading state resolves', async () => {
		setRuntime();

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url === `${ OPS_ENDPOINT }/overview` ) {
					return Promise.resolve(
						jsonResponse( {
							success: true,
							data: {
								status: {
									overall: 'operational',
									components: [
										{
											slug: 'core',
											name: 'Core Engine',
											group: 'Platform',
											public: true,
											status: 'operational',
										},
									],
									last_checked: 0,
								},
								incidents: [],
								maintenance: [],
								webhooks: [],
							},
						} )
					);
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <ProOpsPage /> );

		expect( screen.getByText( 'Collecting ops overview…' ) ).toBeTruthy();

		await waitFor( () => {
			expect( screen.getByText( 'Core Engine' ) ).toBeTruthy();
		} );
		expect( screen.getByText( 'Service Status' ) ).toBeTruthy();
	} );
} );
