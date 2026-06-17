/**
 * Pro SPA v2 — HITL API client + HitlApprovalBar component tests.
 *
 * Covers:
 *   - HitlClient listPending, approve, deny
 *   - HitlApprovalBar rendering, polling, approve/deny button clicks
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';
import React from 'react';

import { HitlClient, type HitlClientOptions, type ApprovalRecord } from '../api/hitl';
import { HitlApprovalBar, type HitlApprovalBarProps } from '../components/shared/HitlApprovalBar';

// ── Mocks ──────────────────────────────────────────────────────────────────────

vi.mock( '@wordpress/i18n', () => ( {
	__: ( text: string ) => text,
	sprintf: ( fmt: string, ...args: ( string | number )[] ) => {
		let result = fmt;
		for ( const arg of args ) {
			result = result.replace( /%[ds]/, String( arg ) );
		}
		return result;
	},
} ) );

// ── Helpers ────────────────────────────────────────────────────────────────────

const mockFetch = vi.fn();

function mockFetchOk( body: unknown, status = 200 ): void {
	mockFetch.mockResolvedValueOnce(
		new Response( JSON.stringify( body ), { status } )
	);
}

function makeApproval( overrides: Partial< ApprovalRecord > = {} ): ApprovalRecord {
	return {
		id: 1,
		status: 'pending',
		tool: 'test_tool',
		arguments: { key: 'value' },
		assistant_id: 1,
		requester_id: 1,
		session_id: 'sess-abc',
		reason: 'Testing',
		created_at: Date.now(),
		expires_at: Date.now() + 3_600_000,
		resolved_by: 0,
		resolved_at: 0,
		note: '',
		...overrides,
	};
}

function defaultProps(
	overrides: Partial< HitlApprovalBarProps > = {}
): HitlApprovalBarProps {
	return {
		endpoint: '/wp-json/mcp-ai/v1/approvals',
		nonce: 'test-nonce',
		assistantId: 1,
		isStreaming: false,
		...overrides,
	};
}

beforeEach( () => {
	vi.stubGlobal( 'fetch', mockFetch );
	mockFetch.mockReset();
} );

afterEach( () => {
	vi.restoreAllMocks();
} );

// ═══════════════════════════════════════════════════════════════════════════════
// HitlClient
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'HitlClient', () => {
	const baseOpts: HitlClientOptions = {
		endpoint: '/wp-json/mcp-ai/v1/approvals',
		nonce: 'wp-nonce-abc',
	};

	describe( 'listPending', () => {
		it( 'returns array on direct response', async () => {
			const records = [ makeApproval( { id: 1 } ), makeApproval( { id: 2 } ) ];
			mockFetchOk( records );

			const client = new HitlClient( baseOpts );
			const result = await client.listPending( {} );

			expect( result ).toEqual( records );
		} );

		it( 'returns array from wrapped envelope', async () => {
			const records = [ makeApproval( { id: 10 } ) ];
			mockFetchOk( { approvals: records } );

			const client = new HitlClient( baseOpts );
			const result = await client.listPending( {} );

			expect( result ).toEqual( records );
		} );

		it( 'returns empty on unexpected response shape', async () => {
			mockFetchOk( { unexpected: 'format' } );

			const client = new HitlClient( baseOpts );
			const result = await client.listPending( {} );

			expect( result ).toEqual( [] );
		} );

		it( 'includes assistantId and sessionId in query params', async () => {
			mockFetchOk( [] );

			const client = new HitlClient( baseOpts );
			await client.listPending( {
				assistantId: 42,
				sessionId: 'sess-xyz',
			} );

			const [ urlStr ] = mockFetch.mock.calls[ 0 ] as [ string ];
			const url = new URL( urlStr );
			expect( url.searchParams.get( 'assistant_id' ) ).toBe( '42' );
			expect( url.searchParams.get( 'session_id' ) ).toBe( 'sess-xyz' );
		} );

		it( 'omits params when undefined', async () => {
			mockFetchOk( [] );

			const client = new HitlClient( baseOpts );
			await client.listPending( {} );

			const [ urlStr ] = mockFetch.mock.calls[ 0 ] as [ string ];
			const url = new URL( urlStr );
			expect( url.searchParams.has( 'assistant_id' ) ).toBe( false );
			expect( url.searchParams.has( 'session_id' ) ).toBe( false );
		} );

		it( 'sends X-WP-Nonce header', async () => {
			mockFetchOk( [] );

			const client = new HitlClient( baseOpts );
			await client.listPending( {} );

			const [ , init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit,
			];
			const h = new Headers( init.headers );
			expect( h.get( 'X-WP-Nonce' ) ).toBe( 'wp-nonce-abc' );
		} );
	} );

	describe( 'approve', () => {
		it( 'calls the approve URL with POST', async () => {
			mockFetchOk( {} );

			const client = new HitlClient( baseOpts );
			await client.approve( 42 );

			const [ urlStr, init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit,
			];
			expect( init.method ).toBe( 'POST' );
			expect( urlStr ).toContain( '/approvals/42/approve' );
		} );

		it( 'sends optional note in the body', async () => {
			mockFetchOk( {} );

			const client = new HitlClient( baseOpts );
			await client.approve( 7, 'Looks good' );

			const [ , init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit,
			];
			const body = JSON.parse( init.body as string );
			expect( body ).toEqual( { note: 'Looks good' } );
		} );

		it( 'sends an empty body when no note is provided', async () => {
			mockFetchOk( {} );

			const client = new HitlClient( baseOpts );
			await client.approve( 3 );

			const [ , init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit,
			];
			const body = JSON.parse( init.body as string );
			expect( body ).toEqual( {} );
		} );
	} );

	describe( 'deny', () => {
		it( 'calls the deny URL with POST', async () => {
			mockFetchOk( {} );

			const client = new HitlClient( baseOpts );
			await client.deny( 99 );

			const [ urlStr, init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit,
			];
			expect( init.method ).toBe( 'POST' );
			expect( urlStr ).toContain( '/approvals/99/deny' );
		} );

		it( 'sends optional note in the body', async () => {
			mockFetchOk( {} );

			const client = new HitlClient( baseOpts );
			await client.deny( 5, 'Not needed' );

			const [ , init ] = mockFetch.mock.calls[ 0 ] as [
				string,
				RequestInit,
			];
			const body = JSON.parse( init.body as string );
			expect( body ).toEqual( { note: 'Not needed' } );
		} );
	} );
} );

// ═══════════════════════════════════════════════════════════════════════════════
// HitlApprovalBar
// ═══════════════════════════════════════════════════════════════════════════════

describe( 'HitlApprovalBar', () => {
	it( 'renders nothing when pending list is empty', async () => {
		mockFetchOk( [] );

		const { container } = render(
			<HitlApprovalBar { ...defaultProps() } />
		);

		await waitFor( () => {
			expect(
				container.querySelector( '.nvoos-pro-spa-hitl-bar' )
			).toBeNull();
		} );
	} );

	it( 'renders approval cards when pending items exist', async () => {
		const items = [
			makeApproval( {
				id: 1,
				tool: 'write_file',
				reason: 'Need review',
			} ),
			makeApproval( {
				id: 2,
				tool: 'delete_post',
				reason: 'Are you sure?',
			} ),
		];
		mockFetchOk( items );

		render( <HitlApprovalBar { ...defaultProps() } /> );

		await waitFor( () => {
			expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
		} );

		expect( screen.getByText( 'write_file' ) ).toBeInTheDocument();
		expect( screen.getByText( 'delete_post' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Need review' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Are you sure?' ) ).toBeInTheDocument();
	} );

	it( 'filters out non-pending items from the response', async () => {
		const items = [
			makeApproval( { id: 1, status: 'pending' as const } ),
			makeApproval( {
				id: 2,
				status: 'approved' as const,
				tool: 'already_ok',
			} ),
			makeApproval( {
				id: 3,
				status: 'denied' as const,
				tool: 'already_no',
			} ),
		];
		mockFetchOk( items );

		render( <HitlApprovalBar { ...defaultProps() } /> );

		await waitFor( () => {
			const cards = document.querySelectorAll(
				'.nvoos-pro-spa-hitl-card'
			);
			expect( cards ).toHaveLength( 1 );
		} );
	} );

	it( 'shows the pending count in the heading', async () => {
		const items = [
			makeApproval( { id: 1 } ),
			makeApproval( { id: 2 } ),
			makeApproval( { id: 3 } ),
		];
		mockFetchOk( items );

		render( <HitlApprovalBar { ...defaultProps() } /> );

		await waitFor( () => {
			expect( screen.getByText( /3 action/ ) ).toBeInTheDocument();
		} );
	} );

	describe( 'approve button', () => {
		it( 'calls approve on click and removes the card', async () => {
			const items = [
				makeApproval( { id: 42, tool: 'approve_me' } ),
			];
			mockFetchOk( items ); // listPending

			render( <HitlApprovalBar { ...defaultProps() } /> );

			await waitFor( () => {
				expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
			} );

			// The approve POST
			mockFetchOk( {} );

			fireEvent.click(
				screen.getByRole( 'button', {
					name: /Approve approve_me/,
				} )
			);

			await waitFor( () => {
				// The last fetch call should be the approve POST.
				const calls = mockFetch.mock.calls;
				const [ urlStr ] = calls[ calls.length - 1 ] as [
					string,
					RequestInit,
				];
				expect( urlStr ).toContain( '/approvals/42/approve' );
			} );

			// After approval, the card is removed and the bar hides.
			await waitFor( () => {
				expect(
					document.querySelector( '.nvoos-pro-spa-hitl-bar' )
				).toBeNull();
			} );
		} );
	} );

	describe( 'deny button', () => {
		it( 'calls deny on click and removes the card', async () => {
			const items = [
				makeApproval( { id: 7, tool: 'deny_me' } ),
			];
			mockFetchOk( items ); // listPending

			render( <HitlApprovalBar { ...defaultProps() } /> );

			await waitFor( () => {
				expect( screen.getByRole( 'alert' ) ).toBeInTheDocument();
			} );

			// The deny POST
			mockFetchOk( {} );

			fireEvent.click(
				screen.getByRole( 'button', {
					name: /Deny deny_me/,
				} )
			);

			await waitFor( () => {
				const calls = mockFetch.mock.calls;
				const [ urlStr ] = calls[ calls.length - 1 ] as [
					string,
					RequestInit,
				];
				expect( urlStr ).toContain( '/approvals/7/deny' );
			} );

			// After deny, the card is removed and the bar hides.
			await waitFor( () => {
				expect(
					document.querySelector( '.nvoos-pro-spa-hitl-bar' )
				).toBeNull();
			} );
		} );
	} );

	describe( 'polling', () => {
		it( 'polls while isStreaming is true', async () => {
			vi.useFakeTimers();

			// First fetch returns empty → nothing rendered.
			mockFetchOk( [] );
			render(
				<HitlApprovalBar
					{ ...defaultProps( { isStreaming: true } ) }
				/>
			);

			// Flush initial effect and fetch via advancing timers by 0
			// to drain the microtask queue.
			await act( async () => {
				await vi.advanceTimersByTimeAsync( 0 );
			} );
			expect( mockFetch ).toHaveBeenCalledTimes( 1 );

			// Second poll returns an item.
			mockFetchOk( [
				makeApproval( { id: 5, tool: 'polled_tool' } ),
			] );

			// Advance past the poll interval. The async version drains
			// timers *and* the microtask queue so the entire
			// setTimeout → fetchPending → fetch → json chain completes.
			await act( async () => {
				await vi.advanceTimersByTimeAsync( 6_000 );
			} );

			expect( mockFetch ).toHaveBeenCalledTimes( 2 );
			expect(
				screen.getByText( 'polled_tool' )
			).toBeInTheDocument();

			vi.useRealTimers();
		} );

		it( 'does not poll when isStreaming is false', async () => {
			vi.useFakeTimers();

			mockFetchOk( [] );
			render(
				<HitlApprovalBar
					{ ...defaultProps( { isStreaming: false } ) }
				/>
			);

			await act( async () => {
				await Promise.resolve();
			} );
			expect( mockFetch ).toHaveBeenCalledTimes( 1 );

			// Advance past the poll interval.
			await act( async () => {
				vi.advanceTimersByTime( 6_000 );
				await Promise.resolve();
			} );

			// No additional fetch calls.
			expect( mockFetch ).toHaveBeenCalledTimes( 1 );

			vi.useRealTimers();
		} );
	} );

	describe( 'accessibility', () => {
		it( 'renders with role="alert"', async () => {
			mockFetchOk( [ makeApproval() ] );

			render( <HitlApprovalBar { ...defaultProps() } /> );

			const alert = await screen.findByRole( 'alert' );
			expect( alert ).toBeInTheDocument();
			expect( alert ).toHaveAttribute(
				'aria-label',
				'Pending approvals'
			);
		} );

		it( 'approve / deny buttons have aria-labels with tool name', async () => {
			mockFetchOk( [
				makeApproval( { id: 1, tool: 'access_check' } ),
			] );

			render( <HitlApprovalBar { ...defaultProps() } /> );

			await waitFor( () => {
				expect(
					screen.getByRole( 'button', {
						name: /Approve access_check/,
					} )
				).toBeInTheDocument();
				expect(
					screen.getByRole( 'button', {
						name: /Deny access_check/,
					} )
				).toBeInTheDocument();
			} );
		} );
	} );
} );
