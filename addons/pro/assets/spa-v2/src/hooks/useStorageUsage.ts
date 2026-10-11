/**
 * useStorageUsage — Reactive measurement of the localStorage space consumed
 * by the plugin's keys (wp_mcp_ai_*, nvoos-chat-spa*, nvoos-pro-spa*).
 *
 * The base chat writes message history to localStorage as a conversation
 * progresses, so the status-bar "Sessions" figure must re-read storage while
 * a chat is running rather than only when the transcripts list refreshes.
 */

import { useEffect, useState } from 'react';

/** Prefixes of the localStorage keys owned by the plugin and its SPAs. */
const STORAGE_KEY_PREFIXES = [ 'wp_mcp_ai_', 'nvoos-chat-spa', 'nvoos-pro-spa' ];

/** How often the usage figure re-reads storage while mounted. */
const POLL_INTERVAL_MS = 2000;

/**
 * Sum the string length of every plugin-owned localStorage key.
 */
export function readStorageUsage(): number {
	if ( typeof window === 'undefined' ) {
		return 0;
	}
	let total = 0;
	try {
		for ( let i = 0; i < window.localStorage.length; i++ ) {
			const key = window.localStorage.key( i );
			if ( key && STORAGE_KEY_PREFIXES.some( ( prefix ) => key.startsWith( prefix ) ) ) {
				total += ( window.localStorage.getItem( key ) ?? '' ).length;
			}
		}
	} catch {
		// Ignore storage access errors (private mode, sandboxed iframes).
	}
	return total;
}

/**
 * Track plugin-owned localStorage usage. Recomputes whenever `refreshSignal`
 * changes, the tab regains focus, another tab writes storage, or on a light
 * interval — so a streaming chat's growing history is reflected live.
 */
export function useStorageUsage( refreshSignal: unknown ): number {
	const [ usage, setUsage ] = useState< number >( readStorageUsage );

	useEffect( () => {
		setUsage( readStorageUsage() );

		const recompute = () => setUsage( readStorageUsage() );
		const timer = window.setInterval( () => {
			if ( ! document.hidden ) {
				recompute();
			}
		}, POLL_INTERVAL_MS );

		window.addEventListener( 'focus', recompute );
		window.addEventListener( 'storage', recompute );

		return () => {
			window.clearInterval( timer );
			window.removeEventListener( 'focus', recompute );
			window.removeEventListener( 'storage', recompute );
		};
	}, [ refreshSignal ] );

	return usage;
}
