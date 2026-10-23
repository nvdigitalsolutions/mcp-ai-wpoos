/**
 * useProOps — Hook for the consolidated Pro ops overview.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { ProOpsClient, type ProOpsOverview } from '../api/pro-ops';

export interface UseProOpsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseProOpsReturn {
	overview: ProOpsOverview | null;
	loading: boolean;
	error: string | null;
	refresh: () => Promise< void >;
}

export function useProOps( options: UseProOpsOptions ): UseProOpsReturn {
	const client = useMemo(
		() => new ProOpsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ overview, setOverview ] = useState< ProOpsOverview | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

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

	return { overview, loading, error, refresh };
}
