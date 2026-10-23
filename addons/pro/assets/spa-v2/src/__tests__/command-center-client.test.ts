/**
 * CommandCenterClient contract tests (fetch mocked, zero network).
 *
 * Covers the overview envelope mapping and the fail-soft contract (null on
 * a non-success envelope — the dashboard hides the section).
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { CommandCenterClient } from '../api/command-center';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai-pro/v1/command-center';

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

describe( 'CommandCenterClient', () => {
	it( 'maps the overview envelope', async () => {
		mockFetch( {
			success: true,
			data: {
				kpis: {
					total_agents: 3,
					agents_online: 1,
					active_tasks: 5,
					pending_approvals: 2,
					tokens_today: 12345,
					uptime: 99.9,
				},
				restriction_count: 1,
				agent_statuses: {
					7: { status: 'online', last_active: 123456 },
					8: { status: 'offline', last_active: 0 },
				},
				recent_events: [
					{
						type: 'chat_response',
						agent_id: 7,
						agent_name: 'Ops Agent',
						message: 'Chat response generated (120 tokens)',
						timestamp: 123456,
					},
				],
			},
		} );

		const client = new CommandCenterClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		const overview = await client.overview();

		expect( overview?.kpis.total_agents ).toBe( 3 );
		expect( overview?.agent_statuses[ 7 ].status ).toBe( 'online' );
		expect( overview?.recent_events ).toHaveLength( 1 );
		expect( overview?.restriction_count ).toBe( 1 );
	} );

	it( 'returns null on a non-success envelope (fail-soft contract)', async () => {
		mockFetch( { success: false, data: { message: 'Command Center unavailable.' } } );

		const client = new CommandCenterClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		await expect( client.overview() ).resolves.toBeNull();
	} );

	it( 'throws on HTTP failure', async () => {
		mockFetch( { message: 'Administrator access required.' }, false );

		const client = new CommandCenterClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		await expect( client.overview() ).rejects.toThrow();
	} );
} );
