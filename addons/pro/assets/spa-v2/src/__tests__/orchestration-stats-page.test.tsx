/**
 * OrchestrationStatsPage render tests.
 *
 * Regression pin for React error #310: the page used to return its
 * loading/error states BEFORE the six useMemo table builders. The
 * render-phase split (OrchestrationStatsTables mounts only with a non-null
 * overview) keeps the hook count stable across the loading → loaded
 * transition.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, cleanup, waitFor } from '@testing-library/react';

import { OrchestrationStatsPage } from '../features/orchestration-stats/OrchestrationStatsPage';

const STATS_ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/orchestration/stats';

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
			orchestrationStats: STATS_ENDPOINT,
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

describe( 'OrchestrationStatsPage', () => {
	it( 'renders the agent table after the loading state resolves', async () => {
		setRuntime();

		vi.stubGlobal(
			'fetch',
			vi.fn( ( input: RequestInfo | URL ) => {
				const url = String( input );
				if ( url === STATS_ENDPOINT ) {
					return Promise.resolve(
						jsonResponse( {
							success: true,
							data: {
								multi_agent: {
									installed: true,
									total_agents: 1,
									active_agents: 1,
									total_tools: 2,
									is_pro_active: true,
									patterns: {},
									agents: [
										{
											id: 1,
											title: 'Writer',
											slug: 'writer',
											status: 'active',
											description: '',
											provider: 'openai',
											model: 'gpt-4',
											tools: [],
											tool_count: 2,
											primary_roles: [],
											last_used: '',
											workflow_pattern: 'writer',
										},
									],
								},
								orchestration: {
									total_professions: 1,
									seeded_professions: 1,
									with_task_patterns: 0,
									seeder_version: '1.0',
									roles: { writer: 1 },
									system_status: {
										cron: { total: 1 },
										async: { status: 'ok' },
										sse: { available: true },
										health: { status: 'healthy' },
									},
								},
								recent_workflows: [],
							},
						} )
					);
				}
				return Promise.reject( new Error( `Unexpected fetch: ${ url }` ) );
			} )
		);

		render( <OrchestrationStatsPage /> );

		expect( screen.getByText( 'Collecting orchestration stats…' ) ).toBeTruthy();

		await waitFor( () => {
			expect( screen.getByText( 'Writer' ) ).toBeTruthy();
		} );
		expect( screen.getByText( 'Multi-Agent System' ) ).toBeTruthy();
	} );
} );
