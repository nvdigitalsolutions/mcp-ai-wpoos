/**
 * useSlashCommands — Hook for the slash-command catalogue + executor.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { SlashCommandsClient, type SlashCommandSummary } from '../api/slash-commands';

export interface UseSlashCommandsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseSlashCommandsReturn {
	commands: SlashCommandSummary[];
	loading: boolean;
	error: string | null;
	/** Last execution result payload (null = none yet). */
	lastResult: Record< string, unknown > | null;
	/** True while an execute request is in flight. */
	executing: boolean;
	fetchCommands: () => Promise< void >;
	executeCommand: ( command: string ) => Promise< void >;
}

export function useSlashCommands( options: UseSlashCommandsOptions ): UseSlashCommandsReturn {
	const client = useMemo(
		() => new SlashCommandsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ commands, setCommands ] = useState< SlashCommandSummary[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ executing, setExecuting ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ lastResult, setLastResult ] = useState< Record< string, unknown > | null >( null );

	const fetchCommands = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			setCommands( await client.list() );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void fetchCommands();
	}, [ fetchCommands ] );

	const executeCommand = useCallback(
		async ( command: string ) => {
			if ( ! options.endpoint || ! command.trim() ) {
				return;
			}
			setExecuting( true );
			setError( null );
			try {
				setLastResult( await client.execute( command.trim() ) );
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			} finally {
				setExecuting( false );
			}
		},
		[ client, options.endpoint ]
	);

	return {
		commands,
		loading,
		error,
		lastResult,
		executing,
		fetchCommands,
		executeCommand,
	};
}
