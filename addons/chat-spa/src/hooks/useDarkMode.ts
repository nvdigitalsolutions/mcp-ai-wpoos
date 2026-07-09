/**
 * NV oOS Chat SPA — Dark mode hook.
 *
 * Manages light/dark theme state with:
 *   - localStorage persistence (per-browser)
 *   - System preference detection (prefers-color-scheme)
 *   - Shortcode config fallback (auto/light/dark)
 *
 * Applies the theme via a `data-theme` attribute on the app root element,
 * matching the CSS selector pattern already used by `chat-spa.css`.
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.8.0
 */

import { useCallback, useEffect, useState } from 'react';

const STORAGE_KEY = 'nvoos-chat-spa.dark-mode';

function readSystemPreference(): boolean {
	if ( typeof window === 'undefined' ) {
		return false;
	}
	try {
		return window.matchMedia( '(prefers-color-scheme: dark)' ).matches;
	} catch {
		return false;
	}
}

function readStored(): boolean | null {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	try {
		const raw = window.localStorage.getItem( STORAGE_KEY );
		if ( raw === 'true' ) return true;
		if ( raw === 'false' ) return false;
		return null;
	} catch {
		return null;
	}
}

function persistStored( isDark: boolean ): void {
	if ( typeof window === 'undefined' ) return;
	try {
		window.localStorage.setItem( STORAGE_KEY, isDark ? 'true' : 'false' );
	} catch {
		// Ignore quota / private-mode failures.
	}
}

/**
 * Compute the resolved theme from config + stored + system preference.
 *
 * Priority: stored > config (when config is 'auto') > system.
 */
function resolveIsDark( configTheme: string, stored: boolean | null ): boolean {
	// Explicit stored value always wins.
	if ( stored !== null ) {
		return stored;
	}
	// Config overrides.
	if ( configTheme === 'dark' ) return true;
	if ( configTheme === 'light' ) return false;
	// 'auto': fall back to system preference.
	return readSystemPreference();
}

export interface UseDarkModeResult {
	/** Whether dark mode is currently active. */
	isDark: boolean;
	/** Toggle between dark and light. */
	toggle: () => void;
}

/**
 * Hook: dark mode state management.
 *
 * Call once per app mount.  Applies the `data-theme` attribute on the
 * document root so CSS selectors pick it up.
 */
export function useDarkMode( configTheme: string ): UseDarkModeResult {
	const [ stored, setStored ] = useState< boolean | null >( readStored );
	const [ systemPrefersDark, setSystemPrefersDark ] = useState< boolean >(
		readSystemPreference
	);

	// Listen for system preference changes.
	useEffect( () => {
		if ( typeof window === 'undefined' ) return;
		let mql: MediaQueryList | null = null;
		try {
			mql = window.matchMedia( '(prefers-color-scheme: dark)' );
			const handler = ( e: MediaQueryListEvent ) => {
				setSystemPrefersDark( e.matches );
			};
			mql.addEventListener( 'change', handler );
			return () => mql?.removeEventListener( 'change', handler );
		} catch {
			return undefined;
		}
	}, [] );

	const isDark = resolveIsDark( configTheme, stored );

	// Apply the theme to the document.
	useEffect( () => {
		if ( typeof document === 'undefined' ) return;
		// Set data-theme on the root element.  The SPA root already has
		// data-theme from the shortcode; we update it to match runtime choice.
		const root = document.querySelector( '.nvoos-chat-spa-app' );
		if ( root ) {
			root.setAttribute( 'data-theme', isDark ? 'dark' : 'light' );
		}
	}, [ isDark ] );

	const toggle = useCallback( () => {
		setStored( ( prev ) => {
			// If stored is null, the current value came from config/system —
			// toggle away from the resolved value.
			const current = prev !== null ? prev : isDark;
			const next = ! current;
			persistStored( next );
			return next;
		} );
	}, [ isDark ] );

	return { isDark, toggle };
}
