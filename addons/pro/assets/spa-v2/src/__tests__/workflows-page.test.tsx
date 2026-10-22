/**
 * WorkflowsPage render tests.
 *
 * Regression pin for React error #310 ("Rendered more hooks than during the
 * previous render"): the page used to return its loading/error states BEFORE
 * the six useMemo table builders, so the hook count changed when the data
 * arrived and the page crashed on every real load. Rendering through the
 * loading → loaded transition reproduces that exact sequence.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';

import { WorkflowsPage } from '../features/workflows/WorkflowsPage';

const WORKFLOWS_ENDPOINT =
	'https://example.com/wp-json/mcp-ai/v1/orchestration/workflows';
const PRESETS_ENDPOINT =
	'https://example.com/wp-json/mcp-ai-pro/v1/workflow-presets';

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
			workflowBuilder: WORKFLOWS_ENDPOINT,
			workflowNodes: 'https://example.com/wp-json/mcp-ai-pro/v1/workflow-builder',
			workflowRuns: 'https://example.com/wp-json/mcp-ai/v1/orchestration/runs',
			workflowPresets: PRESETS_ENDPOINT,
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

describe( 'WorkflowsPage', () => {
	it( 'renders the workflow list after the loading state resolves', async () => {
		setRuntime();

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url === WORKFLOWS_ENDPOINT ) {
					return Promise.resolve(
						jsonResponse( [
							{
								id: 7,
								title: 'Nightly pipeline',
								version: '1.0',
								tags: [ 'cron' ],
								modified: '2026-10-22T00:00:00Z',
							},
						] )
					);
				}
				if ( url === PRESETS_ENDPOINT ) {
					return Promise.resolve(
						jsonResponse( { ok: true, categories: {}, presets: [] } )
					);
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <WorkflowsPage /> );

		// The initial loading state renders before any data arrives.
		expect( screen.getByText( 'Loading workflows…' ) ).toBeTruthy();

		// Resolving the data must not throw (React error #310 regression) —
		// the page continues past the loading state and shows the table.
		await waitFor( () => {
			expect( screen.getByText( 'Nightly pipeline' ) ).toBeTruthy();
		} );
		expect( screen.getByText( 'Presets (0)' ) ).toBeTruthy();
	} );

	it( 'renders the empty state when no workflows exist', async () => {
		setRuntime();

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url === WORKFLOWS_ENDPOINT ) {
					return Promise.resolve( jsonResponse( [] ) );
				}
				if ( url === PRESETS_ENDPOINT ) {
					return Promise.resolve(
						jsonResponse( { ok: true, categories: {}, presets: [] } )
					);
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <WorkflowsPage /> );

		await waitFor( () => {
			expect( screen.getByText( 'No workflows yet.' ) ).toBeTruthy();
		} );
	} );
} );
