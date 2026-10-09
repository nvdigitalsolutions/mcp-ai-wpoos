/**
 * useStorageUsage — reactive localStorage quota tests.
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';

import { readStorageUsage, useStorageUsage } from '../hooks/useStorageUsage';

afterEach( () => {
	vi.useRealTimers();
	vi.restoreAllMocks();
	localStorage.clear();
} );

describe( 'readStorageUsage', () => {
	it( 'returns 0 for empty storage', () => {
		expect( readStorageUsage() ).toBe( 0 );
	} );

	it( 'sums only plugin-owned keys', () => {
		localStorage.setItem( 'wp_mcp_ai_history', 'x'.repeat( 10 ) );
		localStorage.setItem( 'nvoos-chat-spa.dark-mode', 'true' );
		localStorage.setItem( 'nvoos-pro-spa.theme', 'dark' );
		localStorage.setItem( 'unrelated', 'y'.repeat( 100 ) );

		// 10 + 4 + 4 — the unrelated key must not count.
		expect( readStorageUsage() ).toBe( 18 );
	} );

	it( 'returns 0 when storage access throws', () => {
		vi.spyOn( Storage.prototype, 'key' ).mockImplementation( () => {
			throw new Error( 'denied' );
		} );
		expect( readStorageUsage() ).toBe( 0 );
	} );
} );

describe( 'useStorageUsage', () => {
	it( 'tracks storage growth without a refresh signal', () => {
		vi.useFakeTimers();
		const { result } = renderHook( () => useStorageUsage( null ) );

		expect( result.current ).toBe( 0 );

		localStorage.setItem( 'wp_mcp_ai_history', 'x'.repeat( 16 ) );
		act( () => {
			vi.advanceTimersByTime( 2000 );
		} );

		expect( result.current ).toBe( 16 );
	} );

	it( 'recomputes immediately when the refresh signal changes', () => {
		vi.useFakeTimers();
		localStorage.setItem( 'wp_mcp_ai_history', 'x'.repeat( 8 ) );

		const { result, rerender } = renderHook(
			( { signal }: { signal: unknown } ) => useStorageUsage( signal ),
			{ initialProps: { signal: null } as { signal: unknown } }
		);
		expect( result.current ).toBe( 8 );

		localStorage.setItem( 'nvoos-pro-spa.theme', 'dark' );
		rerender( { signal: {} } );

		expect( result.current ).toBe( 8 + 4 );
	} );

	it( 'recomputes when the tab regains focus', () => {
		vi.useFakeTimers();
		const { result } = renderHook( () => useStorageUsage( null ) );

		expect( result.current ).toBe( 0 );

		localStorage.setItem( 'wp_mcp_ai_history', 'x'.repeat( 5 ) );
		act( () => {
			window.dispatchEvent( new Event( 'focus' ) );
		} );

		expect( result.current ).toBe( 5 );
	} );
} );
