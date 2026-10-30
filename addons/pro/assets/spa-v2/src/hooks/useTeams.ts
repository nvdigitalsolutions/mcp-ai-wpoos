/**
 * useTeams — Hook for the teams catalogue + per-team members.
 */

import { useState, useCallback, useEffect, useMemo, useRef } from 'react';
import { TeamsClient, type TeamSummary, type TeamMember } from '../api/teams';

export interface UseTeamsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseTeamsReturn {
	teams: TeamSummary[];
	loading: boolean;
	error: string | null;
	/** Members of the team expanded in the UI (empty = none expanded). */
	members: TeamMember[];
	loadingMembers: boolean;
	/** Failure reason for the members request — distinct from `members`
	 *  being legitimately empty, so the UI can tell the two apart. */
	membersError: string | null;
	fetchTeams: () => Promise< void >;
	loadMembers: ( teamId: number ) => Promise< void >;
}

export function useTeams( options: UseTeamsOptions ): UseTeamsReturn {
	const client = useMemo(
		() => new TeamsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ teams, setTeams ] = useState< TeamSummary[] >( [] );
	const [ members, setMembers ] = useState< TeamMember[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ loadingMembers, setLoadingMembers ] = useState< boolean >( false );
	const [ membersError, setMembersError ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	// Aborts an in-flight members request when the user expands another
	// team before the previous response lands (stale-response guard).
	const membersAbort = useRef< AbortController | null >( null );

	const fetchTeams = useCallback( async () => {
		if ( ! options.endpoint ) {
			setLoading( false );
			return;
		}
		setLoading( true );
		setError( null );
		try {
			setTeams( await client.list() );
		} catch ( err ) {
			setError( err instanceof Error ? err.message : String( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void fetchTeams();
	}, [ fetchTeams ] );

	const loadMembers = useCallback(
		async ( teamId: number ) => {
			if ( ! options.endpoint ) {
				return;
			}
			membersAbort.current?.abort();
			const controller = new AbortController();
			membersAbort.current = controller;

			setLoadingMembers( true );
			setMembersError( null );
			// Clear the previous team's members so a failed load never shows
			// the wrong team's list.
			setMembers( [] );
			try {
				const result = await client.members( teamId, controller.signal );
				setMembers( Array.isArray( result.members ) ? result.members : [] );
			} catch ( err ) {
				if ( err instanceof Error && err.name === 'AbortError' ) {
					return;
				}
				setMembersError( err instanceof Error ? err.message : String( err ) );
			} finally {
				if ( membersAbort.current === controller ) {
					membersAbort.current = null;
				}
				setLoadingMembers( false );
			}
		},
		[ client, options.endpoint ]
	);

	return { teams, loading, error, members, loadingMembers, membersError, fetchTeams, loadMembers };
}
