/**
 * useBootstrap — Fetches initial data when the SPA mounts.
 */

import { useEffect, useState, useCallback } from 'react';
import { readProSpaConfig, type ProSpaRuntime } from '../api/config';
import { useCommandStore } from '../stores/commandStore';
import { useModelStore } from '../stores/modelStore';
import { useUIStore } from '../stores/uiStore';

export interface BootstrapResult {
	loading: boolean;
	error: string | null;
	runtime: ProSpaRuntime | null;
}

export function useBootstrap(): BootstrapResult {
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ runtime, setRuntime ] = useState< ProSpaRuntime | null >( null );

	const setCommands = useCommandStore( ( s ) => s.setCommands );
	const setAvailableModels = useModelStore( ( s ) => s.setAvailableModels );
	const setAvailableProfiles = useModelStore( ( s ) => s.setAvailableProfiles );

	const bootstrap = useCallback( () => {
		const config = readProSpaConfig();
		if ( ! config ) {
			setError( 'Configuration not found. Ensure the admin page is correctly enqueued.' );
			setLoading( false );
			return;
		}
		setRuntime( config );

		// ---- seed theme from server config (only when localStorage is empty) ----
		const serverTheme = config.config?.theme;
		if (
			serverTheme &&
			( serverTheme === 'light' || serverTheme === 'dark' || serverTheme === 'auto' )
		) {
			try {
				if ( ! localStorage.getItem( 'nvoos-pro-spa.theme' ) ) {
					useUIStore.getState().setTheme( serverTheme );
				}
			} catch ( _ ) {
				// localStorage unavailable — store is already initialised with 'auto'
			}
		}

		// Register navigation commands.
		setCommands( [
			{
				id: 'nav-chat',
				label: 'Go to Chat',
				description: 'Open the agent chat panel',
				category: 'navigation',
				handler: () => {
					window.location.hash = '#/chat';
				},
			},
			{
				id: 'nav-settings',
				label: 'Go to Settings',
				description: 'Open plugin settings',
				category: 'navigation',
				handler: () => {
					window.location.hash = '#/settings';
				},
			},
			{
				id: 'nav-tools',
				label: 'Go to Tools',
				description: 'Browse tool registry',
				category: 'navigation',
				handler: () => {
					window.location.hash = '#/tools';
				},
			},
			{
				id: 'nav-assistants',
				label: 'Go to Assistants',
				description: 'Manage assistants',
				category: 'navigation',
				handler: () => {
					window.location.hash = '#/assistants';
				},
			},
			{
				id: 'nav-workflows',
				label: 'Go to Workflows',
				description: 'Build workflows',
				category: 'navigation',
				handler: () => {
					window.location.hash = '#/workflows';
				},
			},
			{
				id: 'nav-analytics',
				label: 'Go to Analytics',
				description: 'View usage dashboard',
				category: 'navigation',
				handler: () => {
					window.location.hash = '#/analytics';
				},
			},
			{
				id: 'action-toggle-sidebar',
				label: 'Toggle Sidebar',
				category: 'action',
				handler: () => {
					useUIStore.getState().toggleSidebar();
				},
			},
		] );

		setLoading( false );
	}, [ setCommands ] );

	useEffect( () => {
		bootstrap();
	}, [ bootstrap ] );

	return { loading, error, runtime };
}
