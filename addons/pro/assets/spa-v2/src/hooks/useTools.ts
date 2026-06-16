/**
 * useTools — Hook for fetching and executing tools.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { ToolsClient, type ToolDefinition } from '../api/tools';

export interface UseToolsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseToolsReturn {
	tools: ToolDefinition[];
	total: number;
	loading: boolean;
	error: string | null;
	fetchTools: () => Promise< void >;
	executeTool: ( slug: string, args: Record< string, unknown > ) => Promise< unknown >;
}

export function useTools( options: UseToolsOptions ): UseToolsReturn {
	const client = useMemo(
		() => new ToolsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ tools, setTools ] = useState< ToolDefinition[] >( [] );
	const [ total, setTotal ] = useState< number >( 0 );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const fetchTools = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const result = await client.list();
			setTools( result.tools );
			setTotal( result.total );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client ] );

	useEffect( () => {
		void fetchTools();
	}, [ fetchTools ] );

	const executeTool = useCallback(
		async ( slug: string, args: Record< string, unknown > ): Promise< unknown > => {
			try {
				return await client.execute( slug, args );
			} catch ( err ) {
				throw err;
			}
		},
		[ client ]
	);

	return { tools, total, loading, error, fetchTools, executeTool };
}
