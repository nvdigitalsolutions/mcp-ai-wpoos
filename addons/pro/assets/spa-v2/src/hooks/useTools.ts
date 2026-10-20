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
	// Start in the loading state — the first fetch kicks off in an effect
	// (after first paint); without this the page shows "No tools found" for
	// a frame (or longer on a heavy shell) before the fetch actually starts.
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	const fetchTools = useCallback( async () => {
		if ( ! options.endpoint ) {
			return;
		}
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
	}, [ client, options.endpoint ] );

	useEffect( () => {
		if ( ! options.endpoint ) {
			// Runtime not ready yet — nothing to fetch; stop blocking the page
			// so the bootstrap/runtime gate can render instead of a spinner.
			setLoading( false );
			return;
		}
		void fetchTools();
	}, [ fetchTools, options.endpoint ] );

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
