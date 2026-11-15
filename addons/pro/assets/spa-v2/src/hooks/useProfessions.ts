/**
 * useProfessions — the profession catalogue for the assistant selector.
 *
 * Loads once per endpoint; `retry()` refetches after a failure. An empty
 * endpoint (the WordPress runtime) resolves to an empty catalogue without
 * firing a request.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { ProfessionsClient, type ProfessionSummary } from '../api/professions';

export interface UseProfessionsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseProfessionsReturn {
	professions: ProfessionSummary[];
	catalogueVersion: string | null;
	loading: boolean;
	error: string | null;
	retry: () => Promise< void >;
}

export function useProfessions( options: UseProfessionsOptions ): UseProfessionsReturn {
	const client = useMemo(
		() => new ProfessionsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ professions, setProfessions ] = useState< ProfessionSummary[] >( [] );
	const [ catalogueVersion, setCatalogueVersion ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState< boolean >( Boolean( options.endpoint ) );
	const [ error, setError ] = useState< string | null >( null );

	const retry = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}

		setLoading( true );
		setError( null );

		try {
			const result = await client.list();
			setProfessions( result.professions );
			setCatalogueVersion( result.catalogue_version );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void retry();
	}, [ retry ] );

	return { professions, catalogueVersion, loading, error, retry };
}
