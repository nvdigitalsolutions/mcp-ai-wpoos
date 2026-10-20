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
	unavailable: boolean;
	fetchSettings: () => Promise< void >;
	updateSettings: ( changes: Partial< PluginSettings > ) => Promise< PluginSettings | null >;
}

export function useSettings( options: UseSettingsOptions ): UseSettingsReturn {
	const client = useMemo(
		() => new SettingsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ settings, setSettings ] = useState< PluginSettings | null >( null );
	// Start in the loading state — the first fetch kicks off in an effect
	// (after first paint); without this the form renders with blank fields
	// (and a save would overwrite them) until the fetch actually starts.
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const addToast = useUIStore( ( s ) => s.addToast );

	// The base plugin does not expose a REST settings route yet — when the
	// endpoint is absent the page degrades to an unavailable state instead of
	// firing a guaranteed-404 request.
	const unavailable = useMemo(
		() => typeof options.endpoint !== 'string' || options.endpoint.trim() === '',
		[ options.endpoint ]
	);

	const fetchSettings = useCallback( async () => {
		if ( unavailable ) {
			return;
		}
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
	}, [ client, unavailable ] );

	useEffect( () => {
		if ( unavailable ) {
			// Runtime not ready yet — nothing to fetch; stop blocking the page
			// so the bootstrap/runtime gate can render instead of a spinner.
			setLoading( false );
			return;
		}
		void fetchSettings();
	}, [ fetchSettings, unavailable ] );

	const updateSettings = useCallback(
		async ( changes: Partial< PluginSettings > ): Promise< PluginSettings | null > => {
			if ( unavailable ) {
				return null;
			}
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
		[ client, addToast, unavailable ]
	);

	return { settings, loading, error, unavailable, fetchSettings, updateSettings };
}
