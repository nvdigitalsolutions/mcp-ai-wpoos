/**
 * TokenManagerPage — assistant credential + restriction management
 * (Proposal 084 Phase 3).
 *
 * Replaces the wp-admin token-manager page in the console: the same
 * credentials table (revoke/delete via the WP_MCP_AI_Credentials service)
 * and the restrictions panel (lift via WP_MCP_AI_Restriction_Registry),
 * both behind the /token-manager REST surface. Read-only until an action.
 */

import { useState, useMemo, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useTokenManager } from '../../hooks/useTokenManager';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';
import type {
	TokenCredential,
	TokenManagerOverview,
	TokenRestriction,
} from '../../api/token-manager';

function formatDate( raw: string ): string {
	if ( ! raw ) {
		return '—';
	}
	const date = new Date( raw.includes( 'T' ) ? raw : raw.replace( ' ', 'T' ) );
	return Number.isNaN( date.getTime() ) ? raw : date.toLocaleString();
}

function StatusBadge( { status }: { status: string } ): JSX.Element {
	const tone = status === 'active' ? 'ok' : 'warning';
	return (
		<span className={ `nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--${ tone }` }>
			{ status }
		</span>
	);
}

function StatsRow( { stats }: { stats: TokenManagerOverview['stats'] } ): JSX.Element {
	const items: Array< [ string, number ] > = [
		[ __( 'Total tokens', 'nvoos-pro-spa' ), stats.total ],
		[ __( 'Active', 'nvoos-pro-spa' ), stats.active ],
		[ __( 'Revoked', 'nvoos-pro-spa' ), stats.revoked ],
		[ __( 'Assistants with tokens', 'nvoos-pro-spa' ), stats.assistants ],
	];

	return (
		<ul className="nvoos-pro-spa-token-manager__stats" aria-label={ __( 'Token statistics', 'nvoos-pro-spa' ) }>
			{ items.map( ( [ label, value ] ) => (
				<li key={ label } className="nvoos-pro-spa-token-manager__stat">
					<div className="nvoos-pro-spa-token-manager__stat-label">{ label }</div>
					<div className="nvoos-pro-spa-token-manager__stat-value">{ value }</div>
				</li>
			) ) }
		</ul>
	);
}

export function TokenManagerPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const {
		overview,
		loading,
		error,
		actionError,
		refresh,
		revokeCredential,
		deleteCredential,
		liftRestriction,
	} = useTokenManager(
		runtime
			? { endpoint: runtime.endpoints.tokenManager ?? '', nonce: runtime.nonce }
			: { endpoint: '', nonce: '' }
	);

	const [ busy, setBusy ] = useState< string | null >( null );

	const credentialColumns = useMemo< DataTableColumn[] >(
		() => [
			{ key: 'assistant', label: __( 'Assistant', 'nvoos-pro-spa' ), width: 'minmax(200px, 1fr)' },
			{ key: 'status', label: __( 'Status', 'nvoos-pro-spa' ), width: '110px' },
			{
				key: 'details',
				label: __( 'Details', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(240px, 1.4fr)',
			},
			{ key: 'actions', label: __( 'Actions', 'nvoos-pro-spa' ), width: 'minmax(180px, 0.8fr)' },
		],
		[]
	);

	const restrictionColumns = useMemo< DataTableColumn[] >(
		() => [
			{ key: 'user', label: __( 'User', 'nvoos-pro-spa' ), width: 'minmax(200px, 1fr)' },
			{ key: 'type', label: __( 'Type', 'nvoos-pro-spa' ), width: '150px' },
			{
				key: 'details',
				label: __( 'Details', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(240px, 1.4fr)',
			},
			{ key: 'actions', label: __( 'Actions', 'nvoos-pro-spa' ), width: '180px' },
		],
		[]
	);

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Token Manager', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading tokens…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime || ! overview ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Token Manager', 'nvoos-pro-spa' ) }</h2>
				{ error && <p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">{ error }</p> }
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	const runCredentialAction = async (
		key: string,
		action: () => Promise< boolean >,
		confirmText: string,
	): Promise< void > => {
		// eslint-disable-next-line no-alert -- parity with the wp-admin page's confirm().
		if ( ! window.confirm( confirmText ) ) {
			return;
		}
		setBusy( key );
		await action();
		setBusy( null );
	};

	const runLift = async ( restriction: TokenRestriction ): Promise< void > => {
		// eslint-disable-next-line no-alert -- parity with the wp-admin page's confirm().
		if ( ! window.confirm(
			__( 'Lift this restriction? The user will immediately regain access to AI features.', 'nvoos-pro-spa' )
		) ) {
			return;
		}
		setBusy( `restriction-${ restriction.user_id }` );
		await liftRestriction( restriction.user_id );
		setBusy( null );
	};

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Token Manager', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Assistant credentials and user restrictions.', 'nvoos-pro-spa' ) }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Refresh', 'nvoos-pro-spa' ) }
				</button>
			</header>

			{ ( error || actionError ) && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ actionError ?? error }</p>
				</div>
			) }

			<StatsRow stats={ overview.stats } />

			<section className="nvoos-pro-spa-diagnostics__card">
				<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Credentials', 'nvoos-pro-spa' ) }</h3>
				{ overview.credentials.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No credentials issued yet. Credentials are created from the assistant edit screen.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<DataTable
						columns={ credentialColumns }
						rows={ overview.credentials.map( ( credential: TokenCredential ) => {
							const key = `${ credential.assistant_id }-${ credential.id }`;
							return {
								id: key,
								cells: [
									<>
										<strong className="nvoos-pro-spa-tools-list__name">
											{ credential.assistant_title }
										</strong>
										<code className="nvoos-pro-spa-tools-list__slug">{ credential.id }</code>
									</>,
									<StatusBadge status={ credential.status } />,
									<>
										{ __( 'Created', 'nvoos-pro-spa' ) }{ ' ' }
										{ formatDate( credential.created_at ) }
										{ credential.expires_at && (
											<>
												<br />
												{ __( 'Expires', 'nvoos-pro-spa' ) }{ ' ' }
												{ formatDate( credential.expires_at ) }
											</>
										) }
										{ credential.created_by_name && (
											<>
												<br />
												{ __( 'By', 'nvoos-pro-spa' ) }{ ' ' }
												{ credential.created_by_name }
											</>
										) }
									</>,
									<>
										{ credential.status === 'active' && (
											<button
												type="button"
												className="nvoos-pro-spa-token-manager__action"
												disabled={ busy !== null }
												onClick={ () =>
													void runCredentialAction(
														key,
														() => revokeCredential( credential.assistant_id, credential.id ),
														__( 'Are you sure you want to revoke this token? It will no longer work for authentication.', 'nvoos-pro-spa' )
													)
												}
											>
												{ __( 'Revoke', 'nvoos-pro-spa' ) }
											</button>
										) }
										<button
											type="button"
											className="nvoos-pro-spa-token-manager__action nvoos-pro-spa-token-manager__action--danger"
											disabled={ busy !== null }
											onClick={ () =>
												void runCredentialAction(
													key,
													() => deleteCredential( credential.assistant_id, credential.id ),
													__( 'Are you sure you want to permanently delete this token? This action cannot be undone.', 'nvoos-pro-spa' )
												)
											}
										>
											{ __( 'Delete', 'nvoos-pro-spa' ) }
										</button>
									</>,
								],
							};
						} ) }
						caption={ __( 'Assistant credentials', 'nvoos-pro-spa' ) }
					/>
				) }
			</section>

			<section className="nvoos-pro-spa-diagnostics__card nvoos-pro-spa-diagnostics__card--standalone">
				<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Restricted Users', 'nvoos-pro-spa' ) }</h3>
				<p className="nvoos-pro-spa-diagnostics__muted">
					{ __( 'Users blocked by rate limits, token overages, or session budgets.', 'nvoos-pro-spa' ) }
				</p>
				{ overview.restrictions.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No active restrictions.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<DataTable
						columns={ restrictionColumns }
						rows={ overview.restrictions.map( ( restriction: TokenRestriction ) => ( {
							id: `${ restriction.user_id }-${ restriction.type }`,
							cells: [
								<>
									<strong className="nvoos-pro-spa-tools-list__name">
										{ restriction.display_name }
									</strong>
									{ restriction.user_login && (
										<code className="nvoos-pro-spa-tools-list__slug">
											{ restriction.user_login }
										</code>
									) }
								</>,
								<StatusBadge
									status={ restriction.type_label || restriction.type }
								/>,
								<>
									{ restriction.scope && (
										<>
											{ restriction.scope }
											<br />
										</>
									) }
									{ restriction.reason || '—' }
									{ restriction.triggered_at > 0 && (
										<>
											<br />
											{ new Date(
												restriction.triggered_at * 1000
											).toLocaleString() }
										</>
									) }
								</>,
								<button
									type="button"
									className="nvoos-pro-spa-token-manager__action"
									disabled={ busy !== null }
									onClick={ () => void runLift( restriction ) }
								>
									{ __( 'Lift Restriction', 'nvoos-pro-spa' ) }
								</button>,
							],
						} ) ) }
						caption={ __( 'Restricted users', 'nvoos-pro-spa' ) }
					/>
				) }
			</section>
		</div>
	);
}
