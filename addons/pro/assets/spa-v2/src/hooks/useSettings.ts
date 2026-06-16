/**
 * useSettings — Hook for plugin settings.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { SettingsClient, type PluginSettings } from '../api/settings';
import { useUIStore } from '../stores/uiStore';

export interface UseSettingsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseSettingsReturn {
	settings: PluginSettings | null;
	loading: boolean;
	error: string | null;
	fetchSettings: () => Promise< void >;
	updateSettings: ( changes: Partial< PluginSettings > ) => Promise< PluginSettings | null >;
}

export function useSettings( options: UseSettingsOptions ): UseSettingsReturn {
	const client = useMemo(
		() => new SettingsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ settings, setSettings ] = useState< PluginSettings | null >( null );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const addToast = useUIStore( ( s ) => s.addToast );

	const fetchSettings = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const result = await client.get();
			setSettings( result );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client ] );

	useEffect( () => {
		void fetchSettings();
	}, [ fetchSettings ] );

	const updateSettings = useCallback(
		async ( changes: Partial< PluginSettings > ): Promise< PluginSettings | null > => {
			try {
				const updated = await client.update( changes );
				setSettings( updated );
				addToast( 'Settings saved', 'success' );
				return updated;
			} catch ( err ) {
				const msg = err instanceof Error ? err.message : String( err );
				addToast( msg, 'error' );
				return null;
			}
		},
		[ client, addToast ]
	);

	return { settings, loading, error, fetchSettings, updateSettings };
}
