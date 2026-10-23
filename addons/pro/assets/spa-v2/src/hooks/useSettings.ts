/**
 * useSettings — Schema-driven settings hook (2.4.0, Proposal 084 Phase 2).
 *
 * Loads the settings catalogue (GET /settings/schema) and the values
 * (GET /settings/values, secrets masked), and persists partial updates
 * (POST /settings/values) — the server sanitizes every key against the
 * schema, so the client only tracks dirty keys.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import {
	SettingsClient,
	type SettingsSchema,
	type SettingsField,
	type SettingsValues,
} from '../api/settings';
import { useUIStore } from '../stores/uiStore';

export interface UseSettingsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseSettingsReturn {
	schema: SettingsSchema;
	fields: SettingsField[];
	values: SettingsValues;
	loading: boolean;
	saving: boolean;
	error: string | null;
	unavailable: boolean;
	fetchSettings: () => Promise< void >;
	saveSettings: ( changes: SettingsValues ) => Promise< SettingsValues | null >;
}

export function useSettings( options: UseSettingsOptions ): UseSettingsReturn {
	const client = useMemo(
		() => new SettingsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);
	const addToast = useUIStore( ( s ) => s.addToast );

	const [ schema, setSchema ] = useState< SettingsSchema >( { tabs: {}, fields: [] } );
	const [ values, setValues ] = useState< SettingsValues >( {} );
	// Start in the loading state — the first fetch kicks off in an effect
	// (after first paint); without this the form renders with blank fields
	// (and a save would overwrite them) until the fetch actually starts.
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ saving, setSaving ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ unavailable, setUnavailable ] = useState< boolean >( false );

	const fetchSettings = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const [ schemaResult, valuesResult ] = await Promise.all( [
				client.schema(),
				client.values(),
			] );
			setSchema( schemaResult );
			setValues( valuesResult );
			setUnavailable( schemaResult.fields.length === 0 );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void fetchSettings();
	}, [ fetchSettings ] );

	const saveSettings = useCallback(
		async ( changes: SettingsValues ): Promise< SettingsValues | null > => {
			if ( unavailable || ! options.endpoint ) {
				return null;
			}
			setSaving( true );
			setError( null );
			try {
				const updated = await client.updateValues( changes );
				setValues( updated );
				addToast( 'Settings saved', 'success' );
				return updated;
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
				return null;
			} finally {
				setSaving( false );
			}
		},
		[ client, options.endpoint, unavailable, addToast ]
	);

	return {
		schema,
		fields: schema.fields,
		values,
		loading,
		saving,
		error,
		unavailable,
		fetchSettings,
		saveSettings,
	};
}
