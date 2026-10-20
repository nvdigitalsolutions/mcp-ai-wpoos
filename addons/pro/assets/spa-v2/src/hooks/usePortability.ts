/**
 * usePortability — Hook for assistant export/import flows.
 */

import { useState, useCallback, useMemo } from 'react';
import { PortabilityClient } from '../api/portability';

export interface UsePortabilityOptions {
	endpoint: string;
	nonce: string;
}

export interface UsePortabilityReturn {
	exporting: boolean;
	importing: boolean;
	error: string | null;
	/** Last import report payload (null = none yet). */
	importReport: Record< string, unknown > | null;
	exportAssistants: ( ids?: number[], includeA2a?: boolean ) => Promise< Record< string, unknown > | undefined >;
	importAssistants: ( payload: {
		json?: string;
		attachment_id?: number;
		mode?: 'skip' | 'overwrite' | 'duplicate';
		dry_run?: boolean;
		status_override?: 'draft' | 'publish' | 'private';
	} ) => Promise< void >;
}

export function usePortability( options: UsePortabilityOptions ): UsePortabilityReturn {
	const client = useMemo(
		() => new PortabilityClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ exporting, setExporting ] = useState< boolean >( false );
	const [ importing, setImporting ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ importReport, setImportReport ] = useState< Record< string, unknown > | null >( null );

	const exportAssistants = useCallback(
		async ( ids?: number[], includeA2a = true ) => {
			if ( ! options.endpoint ) {
				return undefined;
			}
			setExporting( true );
			setError( null );
			try {
				const result = await client.exportAssistants( ids, includeA2a );
				return result.data ?? {};
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
				return undefined;
			} finally {
				setExporting( false );
			}
		},
		[ client, options.endpoint ]
	);

	const importAssistants = useCallback(
		async ( payload: {
			json?: string;
			attachment_id?: number;
			mode?: 'skip' | 'overwrite' | 'duplicate';
			dry_run?: boolean;
			status_override?: 'draft' | 'publish' | 'private';
		} ) => {
			if ( ! options.endpoint ) {
				return;
			}
			setImporting( true );
			setError( null );
			try {
				const result = await client.importAssistants( payload );
				setImportReport( result.data ?? { success: result.success } );
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			} finally {
				setImporting( false );
			}
		},
		[ client, options.endpoint ]
	);

	return { exporting, importing, error, importReport, exportAssistants, importAssistants };
}
