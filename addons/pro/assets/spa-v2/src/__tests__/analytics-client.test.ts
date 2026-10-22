/**
 * AnalyticsClient + chart spec contract tests (fetch mocked, zero network).
 *
 * Covers: the cost envelope mapping, the period query param, error
 * passthrough with the server's message, and the chart-fence builders
 * (line trend within the 40-label cap, donut slice grouping with "Other").
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import {
	AnalyticsClient,
	costTrendSpec,
	providerDonutSpec,
} from '../api/analytics';
import { renderChartRecipe } from '../components/shared/chartRecipes';

const ENDPOINT = 'https://example.com/wp-json/mcp-ai/v1/cost';

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

describe( 'AnalyticsClient', () => {
	it( 'maps the dashboard-summary envelope and forwards the period', async () => {
		mockFetch( {
			days: 30,
			summary: {
				total_cost: 12.5,
				total_tokens: 4200,
				by_provider: { openai: 10, gemini: 2.5 },
				by_model: {
					'openai|gpt-4o': {
						provider: 'openai',
						model: 'gpt-4o',
						total_cost: 10,
						total_tokens: 4000,
					},
				},
				by_tool: { search_web: 1.5 },
				period_start: '2026-09-22',
				period_end: '2026-10-22',
				top_tools: { search_web: 1.5 },
				top_users: { 7: { name: 'Admin', cost: 12.5 } },
			},
		} );

		const client = new AnalyticsClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		const summary = await client.summary( 30 );

		expect( summary.total_cost ).toBe( 12.5 );
		expect( summary.top_users[ 7 ].name ).toBe( 'Admin' );
		const calledUrl = String(
			( global.fetch as ReturnType< typeof vi.fn > ).mock.calls[ 0 ][ 0 ]
		);
		expect( calledUrl ).toContain( '/dashboard-summary?days=30' );
	} );

	it( 'maps the chart-ready trend + provider envelopes', async () => {
		mockFetch( {
			days: 7,
			data: {
				labels: [ 'Oct 1', 'Oct 2' ],
				datasets: [ { label: 'Daily Cost ($)', data: [ 0.5, 0.0 ] } ],
			},
		} );

		const client = new AnalyticsClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		const trend = await client.trend( 7 );

		expect( trend.labels ).toHaveLength( 2 );
		expect( trend.datasets[ 0 ].data ).toEqual( [ 0.5, 0.0 ] );
	} );

	it( 'surfaces the server error message on failure', async () => {
		mockFetch(
			{ message: 'You do not have permission to access site-wide cost data.' },
			false
		);

		const client = new AnalyticsClient( { endpoint: ENDPOINT, nonce: 'abc' } );
		await expect( client.summary( 7 ) ).rejects.toThrow( /permission/ );
	} );
} );

describe( 'Analytics chart specs', () => {
	it( 'caps the trend at 40 labels and renders as a line fence', () => {
		const trend = {
			labels: Array.from( { length: 60 }, ( _, i ) => `D${ i }` ),
			datasets: [ { data: Array.from( { length: 60 }, () => 0.25 ) } ],
		};
		const spec = costTrendSpec( 'Daily cost', trend );

		// The 40-label schema cap is enforced by the builder, not the renderer.
		const parsed = JSON.parse( spec ) as { labels: string[] };
		expect( parsed.labels ).toHaveLength( 40 );
		expect( parsed.labels[ 0 ] ).toBe( 'D20' );
		expect( renderChartRecipe( spec ) ).toContain( 'nvoos-pro-spa-chart-recipe' );
	} );

	it( 'groups provider slices beyond five into an Other slice', () => {
		const providers = {
			labels: [ 'openai', 'gemini', 'anthropic', 'ollama', 'lmstudio', 'mistral', 'cohere' ],
			datasets: [ { data: [ 10, 9, 8, 7, 6, 5, 4 ] } ],
		};
		const spec = providerDonutSpec( 'Cost by provider', providers );
		const parsed = JSON.parse( spec ) as {
			data: { items: Array< { label: string; value: number } > };
		};

		expect( parsed.data.items ).toHaveLength( 6 );
		expect( parsed.data.items[ 5 ].label ).toBe( 'Other' );
		expect( parsed.data.items[ 5 ].value ).toBe( 9 ); // 5 + 4 aggregated.
		expect( renderChartRecipe( spec ) ).toContain( 'nvoos-pro-spa-chart-recipe' );
	} );

	it( 'returns an empty spec for empty data', () => {
		expect( costTrendSpec( 'Daily cost', null ) ).toBe( '' );
		expect( providerDonutSpec( 'Cost by provider', null ) ).toBe( '' );
		expect(
			providerDonutSpec( 'Cost by provider', { labels: [], datasets: [] } )
		).toBe( '' );
	} );
} );
