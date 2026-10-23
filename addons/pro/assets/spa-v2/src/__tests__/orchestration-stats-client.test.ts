/**
 * OrchestrationStatsClient contract tests (fetch mocked).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { OrchestrationStatsClient } from '../api/orchestration-stats';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/orchestration/stats';

function mockFetch( payload: unknown, ok = true ): void {
	global.fetch = vi.fn( () =>
		Promise.resolve( {
			ok,
			status: ok ? 200 : 403,
			json: () => Promise.resolve( payload ),
		} as Response )
	) as unknown as typeof fetch;
}

afterEach( () => {
	vi.restoreAllMocks();
} );

describe( 'OrchestrationStatsClient', () => {
	it( 'maps the stats envelope to the typed payload', async () => {
		mockFetch( {
			success: true,
			data: {
				multi_agent: {
					installed: true,
					total_agents: 8,
					active_agents: 7,
					total_tools: 42,
					is_pro_active: true,
					patterns: { sequential: [] },
					agents: [
						{
							id: 7,
							title: 'Planner',
							slug: 'planner',
							status: 'publish',
							description: '',
							provider: 'openai',
							model: 'gpt-4o',
							tools: [ 'web_search' ],
							tool_count: 1,
							primary_roles: [ 'planner' ],
							last_used: '',
							workflow_pattern: 'sequential',
						},
					],
				},
				orchestration: {
					total_professions: 12,
					seeded_professions: 9,
					with_task_patterns: 4,
					seeder_version: '2.0',
					roles: { planner: 3, executor: 4, critic: 1, specialist: 1, generalist: 0 },
					system_status: {
						cron: { total: 2 },
						async: { status: 'ok' },
						sse: { available: true, endpoint: '' },
						health: { status: 'healthy' },
					},
				},
				recent_workflows: [
					{
						workflow_id: 'wf-1',
						state: 'completed',
						tasks_total: 4,
						tasks_done: 4,
						created_at: '2026-01-01',
						updated_at: '2026-01-01',
						started_at: '',
						completed_at: '2026-01-01',
						team_id: 0,
						task_type: 'generic',
					},
				],
			},
		} );
		const client = new OrchestrationStatsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		const overview = await client.overview();
		expect( overview!.multi_agent.total_agents ).toBe( 8 );
		expect( overview!.multi_agent.agents[ 0 ] ).toMatchObject( {
			title: 'Planner',
			workflow_pattern: 'sequential',
		} );
		expect( overview!.orchestration.roles.planner ).toBe( 3 );
		expect( overview!.orchestration.system_status.sse.available ).toBe( true );
		expect( overview!.recent_workflows ).toHaveLength( 1 );
	} );

	it( 'hits the stats route with the nonce header', async () => {
		let requestedUrl = '';
		let requestedHeaders: Record< string, string > = {};
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			requestedUrl = String( input );
			requestedHeaders = ( init?.headers as Record< string, string > ) ?? {};
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () => Promise.resolve( { success: true, data: {} } ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new OrchestrationStatsClient( { endpoint: ENDPOINT, nonce: 'abc123' } );
		await client.overview();
		expect( requestedUrl ).toContain( '/orchestration/stats' );
		expect( requestedHeaders[ 'X-WP-Nonce' ] ).toBe( 'abc123' );
	} );

	it( 'returns null when the envelope carries no data', async () => {
		mockFetch( { success: true } );
		const client = new OrchestrationStatsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		expect( await client.overview() ).toBeNull();
	} );

	it( 'throws the server message on a refused request', async () => {
		mockFetch( { message: 'Administrator access required.' }, false );
		const client = new OrchestrationStatsClient( { endpoint: ENDPOINT, nonce: 'n' } );
		await expect( client.overview() ).rejects.toThrow( 'Administrator access required.' );
	} );
} );
