/**
 * useDiagnostics — Hook for the consolidated diagnostics overview.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { DiagnosticsClient, type DiagnosticsOverview } from '../api/diagnostics';

export interface UseDiagnosticsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseDiagnosticsReturn {
	overview: DiagnosticsOverview | null;
	loading: boolean;
	error: string | null;
	refresh: () => Promise< void >;
}

export function useDiagnostics( options: UseDiagnosticsOptions ): UseDiagnosticsReturn {
	const client = useMemo(
		() => new DiagnosticsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ overview, setOverview ] = useState< DiagnosticsOverview | null >( null );
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
