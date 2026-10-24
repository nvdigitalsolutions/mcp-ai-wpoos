/**
 * TeamsPage — team catalogue with per-team profession members.
 *
 * Lists teams (GET /mcp-ai/v1/teams, added 2.4.0) and expands a team to show
 * its profession members (GET /mcp-ai/v1/teams/{id}/members). Replaces the
 * wp-admin Team Settings surface in the console (Proposal 084 Phase 1); the
 * wp-admin page stays behind the wp_mcp_ai_admin_page_enabled_teams filter
 * until parity is verified.
 */

import { useState, useMemo, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useTeams } from '../../hooks/useTeams';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';

export function TeamsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { teams, loading, error, members, loadingMembers, membersError, fetchTeams, loadMembers } =
		useTeams(
			runtime
				? { endpoint: runtime.endpoints.teams ?? '', nonce: runtime.nonce }
				: { endpoint: '', nonce: '' }
		);

	const [ expandedId, setExpandedId ] = useState< number | null >( null );

	const toggleTeam = ( teamId: number ): void => {
		if ( expandedId === teamId ) {
			setExpandedId( null );
			return;
		}
		setExpandedId( teamId );
		void loadMembers( teamId );
	};

	const columns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'team',
				label: __( 'Team', 'nvoos-pro-spa' ),
				width: 'minmax(220px, 1fr)',
			},
			{
				key: 'members',
				label: __( 'Members', 'nvoos-pro-spa' ),
				width: '180px',
			},
		],
		[]
	);

	const rows = useMemo< DataTableRow[] >(
		() =>
			teams.map( ( team ) => ( {
				id: team.id,
				cells: [
					<button
						type="button"
						className="nvoos-pro-spa-tools-list__name nvoos-pro-spa-tools-list__name--button"
						aria-expanded={ expandedId === team.id }
						onClick={ () => toggleTeam( team.id ) }
					>
						{ team.title }
					</button>,
					<span className="nvoos-pro-spa-tools-list__badge">
						{ team.member_count }{ ' ' }
						{ team.member_count === 1
							? __( 'member', 'nvoos-pro-spa' )
							: __( 'members', 'nvoos-pro-spa' ) }
					</span>,
				],
				detail:
					expandedId === team.id ? (
						loadingMembers ? (
							<p className="nvoos-pro-spa-slash__result" role="status">
								{ __( 'Loading members…', 'nvoos-pro-spa' ) }
							</p>
						) : membersError ? (
							<p
								className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error"
								role="alert"
							>
								{ membersError }
							</p>
						) : members.length === 0 ? (
							<p>{ __( 'No profession members.', 'nvoos-pro-spa' ) }</p>
						) : (
							<ul className="nvoos-pro-spa-teams__members">
								{ members.map( ( member, index ) => (
									<li key={ member.id ?? index }>
										<strong>{ member.title ?? `#${ member.id }` }</strong>
										{ member.category ? ` — ${ member.category }` : '' }
										{ member.description ? `: ${ member.description }` : '' }
									</li>
								) ) }
							</ul>
						)
					) : undefined,
			} ) ),
		[ teams, expandedId, loadingMembers, membersError, members ]
	);

	if ( bootstrapLoading || ( loading && teams.length === 0 ) ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Teams', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading teams…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Teams', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Teams', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Multi-agent teams and their profession members.', 'nvoos-pro-spa' ) }
				</p>
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => void fetchTeams() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ ! error && teams.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'No teams configured.', 'nvoos-pro-spa' ) }</p>
				</div>
			) : (
				<DataTable
					columns={ columns }
					rows={ rows }
					caption={ __( 'Teams', 'nvoos-pro-spa' ) }
					footer={
						<footer className="nvoos-pro-spa-tools-page__footer">
							<p>
								{ __( 'Showing', 'nvoos-pro-spa' ) } { teams.length }{ ' ' }
								{ __( 'teams', 'nvoos-pro-spa' ) }
							</p>
						</footer>
					}
				/>
			) }
		</div>
	);
}
