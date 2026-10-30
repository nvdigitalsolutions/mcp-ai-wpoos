/**
 * useApprovals — Hook for the HITL approval queue (list + resolve).
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
import { ApprovalsClient, type ApprovalRecord } from '../api/approvals';

export interface UseApprovalsOptions {
	endpoint: string;
	nonce: string;
	/** 1-based page to fetch (default 1). */
	page?: number;
	/** Rows per page (default 25). */
	perPage?: number;
}

export interface UseApprovalsReturn {
	approvals: ApprovalRecord[];
	total: number;
	loading: boolean;
	error: string | null;
	/** True while an approve/deny request is in flight. */
	resolving: boolean;
	fetchApprovals: () => Promise< void >;
	resolveApproval: ( id: number, action: 'approve' | 'deny', note?: string ) => Promise< void >;
}

export function useApprovals( options: UseApprovalsOptions ): UseApprovalsReturn {
	const client = useMemo(
		() => new ApprovalsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ approvals, setApprovals ] = useState< ApprovalRecord[] >( [] );
	const [ total, setTotal ] = useState< number >( 0 );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ resolving, setResolving ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const fetchApprovals = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const result = await client.list( {
				page: options.page ?? 1,
				perPage: options.perPage,
			} );
			setApprovals( result.approvals );
			setTotal( result.total );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint, options.page, options.perPage ] );

	useEffect( () => {
		void fetchApprovals();
	}, [ fetchApprovals ] );

	const resolveApproval = useCallback(
		async ( id: number, action: 'approve' | 'deny', note = '' ) => {
			if ( ! options.endpoint ) {
				return;
			}
			setResolving( true );
			setError( null );
			try {
				await client.resolve( id, action, note );
				await fetchApprovals();
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			} finally {
				setResolving( false );
			}
		},
		[ client, options.endpoint, fetchApprovals ]
	);

	return { approvals, total, loading, error, resolving, fetchApprovals, resolveApproval };
}
