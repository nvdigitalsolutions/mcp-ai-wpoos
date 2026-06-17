/**
 * UI Store — Zustand store for sidebar, modals, toasts, and theme.
 */

import { create } from 'zustand';

export interface Toast {
	id: number;
	message: string;
	variant: 'info' | 'success' | 'error' | 'warning';
}

export interface UIState {
	sidebarOpen: boolean;
	rightPanelOpen: boolean;
	toasts: Toast[];
	theme: 'light' | 'dark' | 'auto';

	toggleSidebar: () => void;
	toggleRightPanel: () => void;
	setSidebarOpen: ( open: boolean ) => void;
	addToast: ( message: string, variant?: Toast[ 'variant' ] ) => void;
	removeToast: ( id: number ) => void;
	setTheme: ( theme: UIState[ 'theme' ] ) => void;
}

const THEME_STORAGE_KEY = 'nvoos-pro-spa.theme';

function getInitialTheme(): UIState[ 'theme' ] {
	try {
		const stored = localStorage.getItem( THEME_STORAGE_KEY );
		if ( stored === 'light' || stored === 'dark' || stored === 'auto' ) {
			return stored;
		}
	} catch ( _ ) {
		// localStorage unavailable (SSR, privacy mode, etc.)
	}
	return 'auto';
}

function persistTheme( theme: UIState[ 'theme' ] ): void {
	try {
		localStorage.setItem( THEME_STORAGE_KEY, theme );
	} catch ( _ ) {
		// Silently ignore storage failures
	}
}

export const useUIStore = create< UIState >( ( set ) => ( {
	sidebarOpen: true,
	rightPanelOpen: false,
	toasts: [],
	theme: getInitialTheme(),

	toggleSidebar: () => set( ( s ) => ( { sidebarOpen: ! s.sidebarOpen } ) ),
	toggleRightPanel: () => set( ( s ) => ( { rightPanelOpen: ! s.rightPanelOpen } ) ),
	setSidebarOpen: ( open ) => set( { sidebarOpen: open } ),

	addToast: ( message, variant = 'info' ) => {
		const id = Date.now();
		set( ( s ) => ( { toasts: [ ...s.toasts, { id, message, variant } ] } ) );
		setTimeout( () => {
			set( ( s ) => ( { toasts: s.toasts.filter( ( t ) => t.id !== id ) } ) );
		}, 6000 );
	},

	removeToast: ( id ) =>
		set( ( s ) => ( { toasts: s.toasts.filter( ( t ) => t.id !== id ) } ) ),

	setTheme: ( theme ) => {
		persistTheme( theme );
		set( { theme } );
	},
} ) );
