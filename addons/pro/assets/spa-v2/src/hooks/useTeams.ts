/**
 * useTeams — Hook for the teams catalogue + per-team members.
 */

import { useState, useCallback, useEffect, useMemo } from 'react';
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
	const [ error, setError ] = useState< string | null >( null );

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
			setLoadingMembers( true );
			setError( null );
			try {
				const result = await client.members( teamId );
				setMembers( Array.isArray( result.members ) ? result.members : [] );
			} catch ( err ) {
				setError( err instanceof Error ? err.message : String( err ) );
			} finally {
				setLoadingMembers( false );
			}
		},
		[ client, options.endpoint ]
	);

	return { teams, loading, error, members, loadingMembers, fetchTeams, loadMembers };
}
