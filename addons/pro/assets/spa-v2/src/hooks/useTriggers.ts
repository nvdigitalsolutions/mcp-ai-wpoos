/**
 * useTriggers — Hook for workflow triggers (list, toggle, delete).
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { TriggersClient, type TriggerRecord } from '../api/triggers';

export interface UseTriggersOptions {
	endpoint: string;
	nonce: string;
}

export interface UseTriggersReturn {
	triggers: TriggerRecord[];
	loading: boolean;
	error: string | null;
	/** True while a toggle/delete request is in flight. */
	mutating: boolean;
	fetchTriggers: () => Promise< void >;
	toggleTrigger: ( id: number, enabled: boolean ) => Promise< void >;
	deleteTrigger: ( id: number ) => Promise< void >;
}

export function useTriggers( options: UseTriggersOptions ): UseTriggersReturn {
	const client = useMemo(
		() => new TriggersClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ triggers, setTriggers ] = useState< TriggerRecord[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ mutating, setMutating ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const fetchTriggers = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			setTriggers( await client.list() );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void fetchTriggers();
	}, [ fetchTriggers ] );

	const toggleTrigger = useCallback(
		async ( id: number, enabled: boolean ) => {
			if ( ! options.endpoint ) {
				return;
			}
			setMutating( true );
			setError( null );
			try {
				await client.setEnabled( id, enabled );
				await fetchTriggers();
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			} finally {
				setMutating( false );
			}
		},
		[ client, options.endpoint, fetchTriggers ]
	);

	const deleteTrigger = useCallback(
		async ( id: number ) => {
			if ( ! options.endpoint ) {
				return;
			}
			setMutating( true );
			setError( null );
			try {
				await client.remove( id );
				await fetchTriggers();
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			} finally {
				setMutating( false );
			}
		},
		[ client, options.endpoint, fetchTriggers ]
	);

	return { triggers, loading, error, mutating, fetchTriggers, toggleTrigger, deleteTrigger };
}
