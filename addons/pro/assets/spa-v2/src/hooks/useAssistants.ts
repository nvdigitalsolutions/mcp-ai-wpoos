/**
 * useAssistants — Hook for managing assistant records.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { AssistantsClient, type AssistantRecord } from '../api/assistants';
import { useUIStore } from '../stores/uiStore';

export interface UseAssistantsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseAssistantsReturn {
	assistants: AssistantRecord[];
	loading: boolean;
	error: string | null;
	fetchAssistants: () => Promise< void >;
	createAssistant: ( fields: Partial< AssistantRecord > ) => Promise< AssistantRecord | null >;
	updateAssistant: ( id: number, changes: Partial< AssistantRecord > ) => Promise< AssistantRecord | null >;
	deleteAssistant: ( id: number ) => Promise< void >;
}

export function useAssistants( options: UseAssistantsOptions ): UseAssistantsReturn {
	const client = useMemo(
		() => new AssistantsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ assistants, setAssistants ] = useState< AssistantRecord[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const addToast = useUIStore( ( s ) => s.addToast );

	const fetchAssistants = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const result = await client.list();
			setAssistants( result.assistants );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client ] );

	useEffect( () => {
		void fetchAssistants();
	}, [ fetchAssistants ] );

	const createAssistant = useCallback(
		async ( fields: Partial< AssistantRecord > ): Promise< AssistantRecord | null > => {
			try {
				const created = await client.create( fields );
				setAssistants( ( prev ) => [ created, ...prev ] );
				addToast( 'Assistant created', 'success' );
				return created;
			} catch ( err ) {
				const msg = err instanceof Error ? err.message : String( err );
				addToast( msg, 'error' );
				return null;
			}
		},
		[ client, addToast ]
	);

	const updateAssistant = useCallback(
		async ( id: number, changes: Partial< AssistantRecord > ): Promise< AssistantRecord | null > => {
			try {
				const updated = await client.update( id, changes );
				setAssistants( ( prev ) =>
					prev.map( ( a ) => ( a.id === id ? { ...a, ...updated } : a ) )
				);
				addToast( 'Assistant updated', 'success' );
				return updated;
			} catch ( err ) {
				const msg = err instanceof Error ? err.message : String( err );
				addToast( msg, 'error' );
				return null;
			}
		},
		[ client, addToast ]
	);

	const deleteAssistant = useCallback(
		async ( id: number ) => {
			try {
				await client.delete( id );
				setAssistants( ( prev ) => prev.filter( ( a ) => a.id !== id ) );
				addToast( 'Assistant deleted', 'success' );
			} catch ( err ) {
				addToast( err instanceof Error ? err.message : String( err ), 'error' );
			}
		},
		[ client, addToast ]
	);

	return {
		assistants,
		loading,
		error,
		fetchAssistants,
		createAssistant,
		updateAssistant,
		deleteAssistant,
	};
}
