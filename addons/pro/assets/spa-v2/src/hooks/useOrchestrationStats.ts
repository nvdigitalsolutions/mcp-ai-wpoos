/**
 * useOrchestrationStats — Hook for the consolidated orchestration overview.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import {
	OrchestrationStatsClient,
	type OrchestrationStatsOverview,
} from '../api/orchestration-stats';

export interface UseOrchestrationStatsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseOrchestrationStatsReturn {
	overview: OrchestrationStatsOverview | null;
	loading: boolean;
	error: string | null;
	refresh: () => Promise< void >;
}

export function useOrchestrationStats(
	options: UseOrchestrationStatsOptions
): UseOrchestrationStatsReturn {
	const client = useMemo(
		() => new OrchestrationStatsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ overview, setOverview ] = useState< OrchestrationStatsOverview | null >( null );
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
