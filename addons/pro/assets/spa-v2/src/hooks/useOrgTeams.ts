/**
 * useOrgTeams — hook for the tenant's org teams and their members.
 *
 * Mutations refresh what they changed (the list after create/delete, the
 * selected team after a membership change) so the UI never shows a stale
 * count. Action failures are reported separately from load failures: a bad
 * write must not blank the table.
 */

import { useState, useCallback, useEffect, useMemo, useRef } from 'react';
import {
	OrgTeamsClient,
	type OrgTeamCreateInput,
	type OrgTeamDetail,
	type OrgTeamMemberInput,
	type OrgTeamRole,
	type OrgTeamSummary,
	type OrgTeamUpdateInput,
} from '../api/org-teams';

export interface UseOrgTeamsOptions {
	endpoint: string;
	nonce: string;
}

export interface UseOrgTeamsReturn {
	teams: OrgTeamSummary[];
	loading: boolean;
	error: string | null;
	selected: OrgTeamDetail | null;
	loadingTeam: boolean;
	teamError: string | null;
	actionError: string | null;
	busy: boolean;
	fetchTeams: () => Promise< void >;
	loadTeam: ( teamId: number ) => Promise< void >;
	clearSelection: () => void;
	createTeam: ( input: OrgTeamCreateInput ) => Promise< boolean >;
	updateTeam: ( teamId: number, input: OrgTeamUpdateInput ) => Promise< boolean >;
	deleteTeam: ( teamId: number ) => Promise< boolean >;
	addMember: ( teamId: number, input: OrgTeamMemberInput ) => Promise< boolean >;
	changeRole: ( teamId: number, userId: number, role: OrgTeamRole ) => Promise< boolean >;
	removeMember: ( teamId: number, userId: number ) => Promise< boolean >;
}

function message( err: unknown ): string {
	return err instanceof Error ? err.message : String( err );
}

export function useOrgTeams( options: UseOrgTeamsOptions ): UseOrgTeamsReturn {
	const client = useMemo(
		() => new OrgTeamsClient( { endpoint: options.endpoint, nonce: options.nonce } ),
		[ options.endpoint, options.nonce ]
	);

	const [ teams, setTeams ] = useState< OrgTeamSummary[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ selected, setSelected ] = useState< OrgTeamDetail | null >( null );
	const [ loadingTeam, setLoadingTeam ] = useState< boolean >( false );
	const [ teamError, setTeamError ] = useState< string | null >( null );
	const [ actionError, setActionError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState< boolean >( false );
	const teamAbort = useRef< AbortController | null >( null );

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
			setError( message( err ) );
		} finally {
			setLoading( false );
		}
	}, [ client, options.endpoint ] );

	useEffect( () => {
		void fetchTeams();
	}, [ fetchTeams ] );

	const loadTeam = useCallback(
		async ( teamId: number ) => {
			if ( ! options.endpoint ) {
				return;
			}
			teamAbort.current?.abort();
			const controller = new AbortController();
			teamAbort.current = controller;

			setLoadingTeam( true );
			setTeamError( null );
			setSelected( null );
			try {
				setSelected( await client.get( teamId, controller.signal ) );
			} catch ( err ) {
				if ( ! controller.signal.aborted ) {
					setTeamError( message( err ) );
				}
			} finally {
				if ( ! controller.signal.aborted ) {
					setLoadingTeam( false );
				}
			}
		},
		[ client, options.endpoint ]
	);

	useEffect( () => () => teamAbort.current?.abort(), [] );

	const clearSelection = useCallback( () => {
		teamAbort.current?.abort();
		setSelected( null );
		setTeamError( null );
	}, [] );

	const run = useCallback(
		async ( action: () => Promise< unknown > ): Promise< boolean > => {
			setBusy( true );
			setActionError( null );
			try {
				await action();
				return true;
			} catch ( err ) {
				setActionError( message( err ) );
				return false;
			} finally {
				setBusy( false );
			}
		},
		[]
	);

	const createTeam = useCallback(
		( input: OrgTeamCreateInput ) =>
			run( async () => {
				await client.create( input );
				await fetchTeams();
			} ),
		[ client, fetchTeams, run ]
	);

	const updateTeam = useCallback(
		( teamId: number, input: OrgTeamUpdateInput ) =>
			run( async () => {
				setSelected( await client.update( teamId, input ) );
				await fetchTeams();
			} ),
		[ client, fetchTeams, run ]
	);

	const deleteTeam = useCallback(
		( teamId: number ) =>
			run( async () => {
				await client.remove( teamId );
				if ( selected?.id === teamId ) {
					setSelected( null );
				}
				await fetchTeams();
			} ),
		[ client, fetchTeams, run, selected ]
	);

	const addMember = useCallback(
		( teamId: number, input: OrgTeamMemberInput ) =>
			run( async () => {
				await client.addMember( teamId, input );
				await loadTeam( teamId );
				await fetchTeams();
			} ),
		[ client, fetchTeams, loadTeam, run ]
	);

	const changeRole = useCallback(
		( teamId: number, userId: number, role: OrgTeamRole ) =>
			run( async () => {
				await client.changeRole( teamId, userId, role );
				await loadTeam( teamId );
			} ),
		[ client, loadTeam, run ]
	);

	const removeMember = useCallback(
		( teamId: number, userId: number ) =>
			run( async () => {
				await client.removeMember( teamId, userId );
				await loadTeam( teamId );
				await fetchTeams();
			} ),
		[ client, fetchTeams, loadTeam, run ]
	);

	return {
		teams,
		loading,
		error,
		selected,
		loadingTeam,
		teamError,
		actionError,
		busy,
		fetchTeams,
		loadTeam,
		clearSelection,
		createTeam,
		updateTeam,
		deleteTeam,
		addMember,
		changeRole,
		removeMember,
	};
}
