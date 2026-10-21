/**
 * MediaOpsClient contract tests (fetch mocked) — Phase 4 W14.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';

import { MediaOpsClient, formatBytes, formatPercent } from '../api/media-ops';

const BASE = 'https://example.com/wp-json/mcp-ai-pro/v1/media-cc';

const SNAPSHOT = {
	attachments: {
		total: 120,
		image_count: 90,
		with_alt: 63,
		descriptive_filename_count: 40,
	},
	storage: 10485760,
	alt_pct: 70,
	nextgen_pct: 25,
	compression: 524288,
	queue: {
		pending: 3,
		completed_today: 12,
		failed: 1,
		total_processed: 300,
		recent_failures: [],
	},
	schedule: {
		active: 2,
		ran_today: 4,
		overdue: 0,
		next_run: '',
	},
};

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

describe( 'MediaOpsClient', () => {
	it( 'fetches the health snapshot', async () => {
		mockFetch( SNAPSHOT );
		const client = new MediaOpsClient( { endpoint: BASE, nonce: 'n' } );
		const health = await client.health();
		expect( health.attachments.total ).toBe( 120 );
		expect( health.queue.pending ).toBe( 3 );
		expect( health.schedule.active ).toBe( 2 );
	} );

	it( 'posts the compression sweep and parses the result', async () => {
		const calls: { url: string; method?: string }[] = [];
		global.fetch = vi.fn( ( input: RequestInfo | URL, init?: RequestInit ) => {
			calls.push( { url: String( input ), method: init?.method } );
			return Promise.resolve( {
				ok: true,
				status: 200,
				json: () =>
					Promise.resolve( {
						queued: true,
						message: 'Compression sweep queued.',
					} ),
			} as Response );
		} ) as unknown as typeof fetch;
		const client = new MediaOpsClient( { endpoint: BASE, nonce: 'n' } );
		const result = await client.runCompressionSweep();
		expect( calls[ 0 ].url ).toContain( '/media-cc/compression-sweep' );
		expect( calls[ 0 ].method ).toBe( 'POST' );
		expect( result.queued ).toBe( true );
	} );

	it( 'throws the server message on a failed request', async () => {
		mockFetch(
			{ code: 'media_cc_provider_unavailable', message: 'Provider missing.' },
			false
		);
		const client = new MediaOpsClient( { endpoint: BASE, nonce: 'n' } );
		await expect( client.health() ).rejects.toThrow( 'Provider missing.' );
	} );
} );

describe( 'formatBytes', () => {
	it( 'formats byte, KB, MB and GB values', () => {
		expect( formatBytes( 512 ) ).toBe( '512 B' );
		expect( formatBytes( 2048 ) ).toBe( '2.0 KB' );
		expect( formatBytes( 10485760 ) ).toBe( '10.0 MB' );
		expect( formatBytes( 3 * 1024 * 1024 * 1024 ) ).toBe( '3.0 GB' );
	} );

	it( 'falls back to zero for non-numeric values', () => {
		expect( formatBytes( undefined ) ).toBe( '0 B' );
		expect( formatBytes( 'nope' ) ).toBe( '0 B' );
	} );
} );

describe( 'formatPercent', () => {
	it( 'formats percentages and falls back to zero', () => {
		expect( formatPercent( 70 ) ).toBe( '70%' );
		expect( formatPercent( undefined ) ).toBe( '0%' );
	} );
} );
