/**
 * ScheduleRunsView tests — the Runs & Reports surface.
 *
 * Covers the pure report math (median/p95/overdue), the report strip, the
 * status + action-log table (disclosure rows), the chart emission, result
 * envelope rendering by capture type, the CSV export trigger, and the
 * empty/error states. The ScheduleManagerClient is mocked — wire correctness
 * lives in the REST controllers.
 */
import { describe, it, expect, vi, afterEach, beforeEach } from 'vitest';
import { render, screen, cleanup, fireEvent } from '@testing-library/react';

import {
	ScheduleRunsView,
	median,
	p95,
	isOverdue,
	formatRunTime,
} from '../ScheduleRunsView';
import type { ScheduleItem } from '../../../api/schedule-manager';

const { historyMock, resultsMock } = vi.hoisted( () => ( {
	historyMock: vi.fn(),
	resultsMock: vi.fn(),
} ) );

vi.mock( '../../../api/schedule-manager', () => ( {
	ScheduleManagerClient: class {
		history = historyMock;
		results = resultsMock;
	},
} ) );

const SCHEDULE: ScheduleItem = {
	id: 'sched-1',
	name: 'Daily Product Sync',
	schedule_type: 'assistant_run',
};

function run( overrides: Record< string, unknown > = {} ): Record< string, unknown > {
	return {
		status: 'success',
		start_time: Math.floor( Date.now() / 1000 ) - 60,
		duration: 4.2,
		error: '',
		action_log: [],
		...overrides,
	};
}

beforeEach( () => {
	historyMock.mockReset();
	resultsMock.mockReset();
	historyMock.mockResolvedValue( [] );
	resultsMock.mockResolvedValue( [] );
} );

afterEach( () => {
	cleanup();
	vi.restoreAllMocks();
} );

describe( 'report math', () => {
	it( 'computes median and p95', () => {
		expect( median( [ 3, 1, 2 ] ) ).toBe( 2 );
		expect( median( [ 4, 1, 3, 2 ] ) ).toBe( 2.5 );
		expect( p95( [ 1, 2, 3, 4, 5, 6, 7, 8, 9, 10 ] ) ).toBe( 10 );
		expect( p95( [] ) ).toBe( 0 );
	} );

	it( 'flags overdue runs at 2x the median gap', () => {
		const now = Math.floor( Date.now() / 1000 );
		// Gap 10s, last run 100s ago → overdue.
		expect( isOverdue( [ now - 110, now - 100 ], now ) ).toBe( true );
		// Gap 10s, last run 5s ago → healthy.
		expect( isOverdue( [ now - 15, now - 5 ], now ) ).toBe( false );
		// Not enough data → never flagged.
		expect( isOverdue( [ now - 5 ], now ) ).toBe( false );
	} );

	it( 'formats epoch seconds as a short local timestamp', () => {
		expect( formatRunTime( 0 ) ).toBe( '—' );
		expect( formatRunTime( 1730000000 ) ).toMatch( /^\d+\/\d+ \d{2}:\d{2}$/ );
	} );
} );

describe( 'ScheduleRunsView', () => {
	it( 'renders the report strip from the run history', async () => {
		historyMock.mockResolvedValue( [
			run(),
			run( { status: 'failure', duration: 6.0, error: 'Boom' } ),
		] );
		render(
			<ScheduleRunsView
				schedule={ SCHEDULE }
				endpoint="https://example.com/wp-json/mcp-ai-pro/v1"
				nonce="test-nonce"
				onBack={ () => {} }
			/>
		);
		await screen.findByText( 'Run history' );
		expect( screen.getAllByText( '2' ).length ).toBeGreaterThanOrEqual( 1 ); // Runs count.
		expect( screen.getByText( '50%' ) ).toBeTruthy(); // Success rate.
		expect( screen.getByText( '5.1s' ) ).toBeTruthy(); // Avg duration.
	} );

	it( 'shows the overdue badge when the last run missed its window', async () => {
		const now = Math.floor( Date.now() / 1000 );
		historyMock.mockResolvedValue( [
			run( { start_time: now - 100 } ),
			run( { start_time: now - 110 } ),
		] );
		render(
			<ScheduleRunsView
				schedule={ SCHEDULE }
				endpoint="https://example.com/wp-json/mcp-ai-pro/v1"
				nonce="test-nonce"
				onBack={ () => {} }
			/>
		);
		await screen.findByText( 'Run history' );
		expect( screen.getByText( 'overdue' ) ).toBeTruthy();
	} );

	it( 'renders the runs table with an expandable action log', async () => {
		historyMock.mockResolvedValue( [
			run( {
				action_log: [
					{ tool_slug: 'search_upwork_jobs', label: 'Search', duration: 1.2, result: 'Found 3' },
				],
			} ),
		] );
		render(
			<ScheduleRunsView
				schedule={ SCHEDULE }
				endpoint="https://example.com/wp-json/mcp-ai-pro/v1"
				nonce="test-nonce"
				onBack={ () => {} }
			/>
		);
		await screen.findByText( 'Run history' );
		expect( screen.getByText( 'success' ) ).toBeTruthy();
		expect( screen.getAllByText( '4.2s' ).length ).toBeGreaterThanOrEqual( 1 );
		fireEvent.click( screen.getByText( /Show log/ ) );
		expect( screen.getByText( /Search/ ) ).toBeTruthy();
		expect( screen.getByText( /search_upwork_jobs/ ) ).toBeTruthy();
	} );

	it( 'emits the three chart specs', async () => {
		historyMock.mockResolvedValue( [ run(), run( { status: 'failure' } ) ] );
		const { container } = render(
			<ScheduleRunsView
				schedule={ SCHEDULE }
				endpoint="https://example.com/wp-json/mcp-ai-pro/v1"
				nonce="test-nonce"
				onBack={ () => {} }
			/>
		);
		await screen.findByText( 'Run history' );
		expect( container.querySelectorAll( '.nvoos-pro-spa-dashboards-chart' ).length ).toBe( 3 );
		expect( container.textContent ).toContain( 'Outcomes over time' );
		expect( container.textContent ).toContain( 'Duration per run' );
		expect( container.textContent ).toContain( 'Outcome split' );
	} );

	it( 'renders the latest captured result by its render type', async () => {
		resultsMock.mockResolvedValue( [
			{
				render: 'table',
				summary: 'rows',
				data: [ { product: 'Aurora', price: 1299 } ],
				status: 'success',
				generated_at: 1730000000,
			},
		] );
		render(
			<ScheduleRunsView
				schedule={ SCHEDULE }
				endpoint="https://example.com/wp-json/mcp-ai-pro/v1"
				nonce="test-nonce"
				onBack={ () => {} }
			/>
		);
		await screen.findByText( 'Latest captured result' );
		expect( screen.getByText( 'Aurora' ) ).toBeTruthy();
		expect( screen.getByText( '1299' ) ).toBeTruthy();
	} );

	it( 'downloads a CSV of the visible history', async () => {
		historyMock.mockResolvedValue( [ run() ] );
		Object.defineProperty( URL, 'createObjectURL', {
			configurable: true,
			value: () => 'blob:mock',
		} );
		Object.defineProperty( URL, 'revokeObjectURL', {
			configurable: true,
			value: () => {},
		} );
		const clickSpy = vi.spyOn( HTMLAnchorElement.prototype, 'click' ).mockImplementation( () => {} );
		render(
			<ScheduleRunsView
				schedule={ SCHEDULE }
				endpoint="https://example.com/wp-json/mcp-ai-pro/v1"
				nonce="test-nonce"
				onBack={ () => {} }
			/>
		);
		await screen.findByText( 'Run history' );
		fireEvent.click( screen.getByText( 'Export CSV' ) );
		expect( clickSpy ).toHaveBeenCalled();
	} );

	it( 'renders the empty state and surfaces fetch failures', async () => {
		historyMock.mockResolvedValue( [] );
		const { unmount } = render(
			<ScheduleRunsView
				schedule={ SCHEDULE }
				endpoint="https://example.com/wp-json/mcp-ai-pro/v1"
				nonce="test-nonce"
				onBack={ () => {} }
			/>
		);
		await screen.findByText( 'No runs recorded yet.' );
		unmount();

		historyMock.mockRejectedValue( new Error( 'Server exploded' ) );
		render(
			<ScheduleRunsView
				schedule={ SCHEDULE }
				endpoint="https://example.com/wp-json/mcp-ai-pro/v1"
				nonce="test-nonce"
				onBack={ () => {} }
			/>
		);
		await screen.findByText( 'Server exploded' );
	} );
} );
