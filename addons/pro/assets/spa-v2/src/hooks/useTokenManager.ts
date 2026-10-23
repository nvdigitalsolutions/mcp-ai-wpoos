/**
 * useTokenManager — Hook for the assistant token-manager overview.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import {
	TokenManagerClient,
	type TokenManagerOverview,
} from '../api/token-manager';

export interface UseTokenManagerOptions {
	endpoint: string;
	nonce: string;
}

export interface UseTokenManagerReturn {
	overview: TokenManagerOverview | null;
	loading: boolean;
	error: string | null;
	actionError: string | null;
	refresh: () => Promise< void >;
	revokeCredential: ( assistantId: number, credentialId: string ) => Promise< boolean >;
	deleteCredential: ( assistantId: number, credentialId: string ) => Promise< boolean >;
	liftRestriction: ( userId: number, type?: string ) => Promise< boolean >;
}

export function useTokenManager(
	options: UseTokenManagerOptions
): UseTokenManagerReturn {
	const client = useMemo(
		() => new TokenManagerClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ overview, setOverview ] = useState< TokenManagerOverview | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ actionError, setActionError ] = useState< string | null >( null );

	const refresh = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			setOverview( await client.overview() );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void refresh();
	}, [ refresh ] );

	const runAction = useCallback(
		async ( action: () => Promise< void > ): Promise< boolean > => {
			setActionError( null );
			try {
				await action();
				await refresh();
				return true;
			} catch ( err ) {
				setActionError( err instanceof Error ? err.message : String( err ) );
				return false;
			}
		},
		[ refresh ]
	);

	const revokeCredential = useCallback(
		( assistantId: number, credentialId: string ) =>
			runAction( () => client.revokeCredential( assistantId, credentialId ) ),
		[ client, runAction ]
	);

	const deleteCredential = useCallback(
		( assistantId: number, credentialId: string ) =>
			runAction( () => client.deleteCredential( assistantId, credentialId ) ),
		[ client, runAction ]
	);

	const liftRestriction = useCallback(
		( userId: number, type = 'all' ) =>
			runAction( () => client.liftRestriction( userId, type ) ),
		[ client, runAction ]
	);

	return {
		overview,
		loading,
		error,
		actionError,
		refresh,
		revokeCredential,
		deleteCredential,
		liftRestriction,
	};
}
