/**
 * OrgTeamsPage — the tenant's teams and their members.
 *
 * This is the *people* surface (`/api/v1/org/teams`, Proposal 090-g Phase 3),
 * not the agent-team catalogue at `/teams`. The two are deliberately separate:
 * a WordPress agent team is a collection of professions, while an org team is
 * a set of users with roles.
 *
 * The surface is Laravel-only until the WordPress runtime grows the same
 * contract, so an empty endpoint renders the standard unavailable state
 * (same pattern as ToolkitsPage) rather than an empty table.
 */

import { useMemo, useState, type FormEvent, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import { useOrgTeams } from '../../hooks/useOrgTeams';
import { ORG_TEAM_ROLES, type OrgTeamRole } from '../../api/org-teams';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';

const countLabel = ( count: number ): string =>
	count === 1
		? __( '1 team', 'nvoos-pro-spa' )
		: `${ count } ${ __( 'teams', 'nvoos-pro-spa' ) }`;

export function OrgTeamsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const org = useOrgTeams( {
		endpoint: runtime?.endpoints.orgTeams ?? '',
		nonce: runtime?.nonce ?? '',
	} );

	const [ name, setName ] = useState( '' );
	const [ description, setDescription ] = useState( '' );
	const [ memberUserId, setMemberUserId ] = useState( '' );
	const [ memberRole, setMemberRole ] = useState< OrgTeamRole >( 'member' );
	const [ memberProfession, setMemberProfession ] = useState( '' );
	const [ confirmingDelete, setConfirmingDelete ] = useState< boolean >( false );

	const columns = useMemo< DataTableColumn[] >(
		() => [
			{ key: 'team', label: __( 'Team', 'nvoos-pro-spa' ), width: 'minmax(220px, 1fr)' },
			{ key: 'role', label: __( 'Your role', 'nvoos-pro-spa' ), width: '140px' },
			{ key: 'members', label: __( 'Members', 'nvoos-pro-spa' ), width: '140px' },
		],
		[]
	);

	const rows = useMemo< DataTableRow[] >(
		() =>
			org.teams.map( ( team ) => ( {
				id: team.id,
				cells: [
					<button
						type="button"
						key="name"
						className="nvoos-pro-spa-tools-list__name nvoos-pro-spa-tools-list__name--button"
						aria-expanded={ org.selected?.id === team.id }
						onClick={ () => {
							setConfirmingDelete( false );
							void org.loadTeam( team.id );
						} }
					>
						{ team.name }
					</button>,
					<span key="role" className="nvoos-pro-spa-tools-list__badge">
						{ team.role }
					</span>,
					<span key="members" className="nvoos-pro-spa-tools-list__badge">
						{ team.member_count }
					</span>,
				],
			} ) ),
		[ org ]
	);

	const memberColumns = useMemo< DataTableColumn[] >(
		() => [
			{ key: 'member', label: __( 'Member', 'nvoos-pro-spa' ), width: 'minmax(200px, 1fr)' },
			{ key: 'role', label: __( 'Role', 'nvoos-pro-spa' ), width: '150px' },
			{ key: 'profession', label: __( 'Profession', 'nvoos-pro-spa' ), width: '180px' },
			{ key: 'actions', label: __( 'Actions', 'nvoos-pro-spa' ), width: '120px' },
		],
		[]
	);

	const memberRows = useMemo< DataTableRow[] >(
		() =>
			( org.selected?.members ?? [] ).map( ( member ) => ( {
				id: member.id,
				cells: [
					<span key="who">
						<strong>{ member.name ?? `#${ member.user_id }` }</strong>
						{ member.email ? <span> — { member.email }</span> : null }
					</span>,
					<select
						key="role"
						aria-label={ __( 'Member role', 'nvoos-pro-spa' ) }
						value={ member.role }
						disabled={ org.busy }
						onChange={ ( event ) => {
							void org.changeRole(
								member.team_id,
								member.user_id,
								event.target.value as OrgTeamRole
							);
						} }
					>
						{ ORG_TEAM_ROLES.map( ( role ) => (
							<option key={ role } value={ role }>
								{ role }
							</option>
						) ) }
					</select>,
					<span key="profession">{ member.profession_slug ?? '—' }</span>,
					<button
						key="remove"
						type="button"
						disabled={ org.busy }
						onClick={ () => {
							if ( org.selected ) {
								void org.removeMember( org.selected.id, member.user_id );
							}
						} }
					>
						{ __( 'Remove', 'nvoos-pro-spa' ) }
					</button>,
				],
			} ) ),
		[ org ]
	);

	const onCreate = async ( event: FormEvent< HTMLFormElement > ): Promise< void > => {
		event.preventDefault();
		const ok = await org.createTeam( {
			name: name.trim(),
			description: description.trim() || null,
		} );
		if ( ok ) {
			setName( '' );
			setDescription( '' );
		}
	};

	const onAddMember = async ( event: FormEvent< HTMLFormElement > ): Promise< void > => {
		event.preventDefault();
		if ( ! org.selected ) {
			return;
		}
		const userId = Number.parseInt( memberUserId, 10 );
		if ( Number.isNaN( userId ) || userId <= 0 ) {
			return;
		}
		const ok = await org.addMember( org.selected.id, {
			user_id: userId,
			role: memberRole,
			profession_slug: memberProfession.trim() || null,
		} );
		if ( ok ) {
			setMemberUserId( '' );
			setMemberProfession( '' );
		}
	};

	if ( bootstrapLoading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Org Teams', 'nvoos-pro-spa' ) }</h2>
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
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Org Teams', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	if ( ! runtime.endpoints.orgTeams ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Org Teams', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message">
					{ __( 'Org teams are not available on this runtime.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Org Teams', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Teams of people, their roles, and their professions.', 'nvoos-pro-spa' ) }
				</p>
			</header>

			{ org.error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ org.error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => void org.fetchTeams() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ org.actionError && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ org.actionError }</p>
				</div>
			) }

			<DataTable
				columns={ columns }
				rows={ rows }
				caption={ __( 'Org teams', 'nvoos-pro-spa' ) }
				busy={ org.loading }
				empty={ <p>{ __( 'No teams yet.', 'nvoos-pro-spa' ) }</p> }
				footer={
					<footer className="nvoos-pro-spa-tools-page__footer">
						<p>{ countLabel( org.teams.length ) }</p>
					</footer>
				}
			/>

			<form
				onSubmit={ ( event ) => void onCreate( event ) }
				aria-label={ __( 'Create a team', 'nvoos-pro-spa' ) }
			>
				<h3>{ __( 'Create a team', 'nvoos-pro-spa' ) }</h3>
				<p>
					<label htmlFor="org-team-name">{ __( 'Name', 'nvoos-pro-spa' ) }</label>{ ' ' }
					<input
						id="org-team-name"
						type="text"
						required
						maxLength={ 255 }
						value={ name }
						onChange={ ( event ) => setName( event.target.value ) }
					/>
				</p>
				<p>
					<label htmlFor="org-team-description">{ __( 'Description', 'nvoos-pro-spa' ) }</label>{ ' ' }
					<textarea
						id="org-team-description"
						rows={ 2 }
						value={ description }
						onChange={ ( event ) => setDescription( event.target.value ) }
					/>
				</p>
				<button type="submit" disabled={ org.busy || name.trim() === '' }>
					{ __( 'Create team', 'nvoos-pro-spa' ) }
				</button>
			</form>

			{ org.loadingTeam && <p role="status">{ __( 'Loading team…', 'nvoos-pro-spa' ) }</p> }

			{ org.teamError && (
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					{ org.teamError }
				</p>
			) }

			{ org.selected && (
				<section aria-label={ __( 'Team detail', 'nvoos-pro-spa' ) }>
					<h3>{ org.selected.name }</h3>
					{ org.selected.description && <p>{ org.selected.description }</p> }

					<DataTable
						columns={ memberColumns }
						rows={ memberRows }
						caption={ __( 'Team members', 'nvoos-pro-spa' ) }
						busy={ org.busy }
						empty={ <p>{ __( 'No members.', 'nvoos-pro-spa' ) }</p> }
					/>

					<form
						onSubmit={ ( event ) => void onAddMember( event ) }
						aria-label={ __( 'Add a member', 'nvoos-pro-spa' ) }
					>
						<h4>{ __( 'Add a member', 'nvoos-pro-spa' ) }</h4>
						<p>
							<label htmlFor="org-member-user">{ __( 'User ID', 'nvoos-pro-spa' ) }</label>{ ' ' }
							<input
								id="org-member-user"
								type="number"
								min={ 1 }
								required
								value={ memberUserId }
								onChange={ ( event ) => setMemberUserId( event.target.value ) }
							/>
						</p>
						<p>
							<label htmlFor="org-member-role">{ __( 'Role', 'nvoos-pro-spa' ) }</label>{ ' ' }
							<select
								id="org-member-role"
								value={ memberRole }
								onChange={ ( event ) => setMemberRole( event.target.value as OrgTeamRole ) }
							>
								{ ORG_TEAM_ROLES.map( ( role ) => (
									<option key={ role } value={ role }>
										{ role }
									</option>
								) ) }
							</select>
						</p>
						<p>
							<label htmlFor="org-member-profession">{ __( 'Profession slug', 'nvoos-pro-spa' ) }</label>{ ' ' }
							<input
								id="org-member-profession"
								type="text"
								maxLength={ 120 }
								placeholder="ux_designer"
								value={ memberProfession }
								onChange={ ( event ) => setMemberProfession( event.target.value ) }
							/>
						</p>
						<button type="submit" disabled={ org.busy || memberUserId === '' }>
							{ __( 'Add member', 'nvoos-pro-spa' ) }
						</button>
					</form>

					{ confirmingDelete ? (
						<p>
							<button
								type="button"
								disabled={ org.busy }
								onClick={ () => {
									if ( org.selected ) {
										void org.deleteTeam( org.selected.id ).then( () => setConfirmingDelete( false ) );
									}
								} }
							>
								{ __( 'Confirm delete', 'nvoos-pro-spa' ) }
							</button>{ ' ' }
							<button type="button" onClick={ () => setConfirmingDelete( false ) }>
								{ __( 'Cancel', 'nvoos-pro-spa' ) }
							</button>
						</p>
					) : (
						<p>
							<button type="button" disabled={ org.busy } onClick={ () => setConfirmingDelete( true ) }>
								{ __( 'Delete team', 'nvoos-pro-spa' ) }
							</button>
						</p>
					) }
				</section>
			) }
		</div>
	);
}
