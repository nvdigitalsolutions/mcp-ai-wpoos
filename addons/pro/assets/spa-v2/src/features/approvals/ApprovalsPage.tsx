/**
 * ApprovalsPage — HITL approval queue browser.
 *
 * Lists the pending approval queue (GET /mcp-ai/v1/approvals) and resolves
 * items via approve/deny. Replaces the wp-admin Approvals submenu page in
 * the console (Proposal 084 Phase 1); the wp-admin page stays behind the
 * wp_mcp_ai_admin_page_enabled_approvals filter until parity is verified.
 */

import { useState, useMemo, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useApprovals } from '../../hooks/useApprovals';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';

export function ApprovalsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { approvals, total, loading, error, resolving, fetchApprovals, resolveApproval } =
		useApprovals(
			runtime
				? { endpoint: runtime.endpoints.approvals, nonce: runtime.nonce }
				: { endpoint: '', nonce: '' }
		);

	const [ noteMap, setNoteMap ] = useState< Record< number, string > >( {} );

	const setNote = ( id: number, note: string ): void => {
		setNoteMap( ( prev ) => ( { ...prev, [ id ]: note } ) );
	};

	const columns = useMemo< DataTableColumn[] >(
		() => [
			{ key: 'tool', label: __( 'Tool', 'nvoos-pro-spa' ), width: 'minmax(180px, 1fr)' },
			{
				key: 'reason',
				label: __( 'Reason', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(220px, 1.4fr)',
			},
			{ key: 'requested', label: __( 'Requested', 'nvoos-pro-spa' ), width: '190px' },
			{
				key: 'actions',
				label: __( 'Note / action', 'nvoos-pro-spa' ),
				width: 'minmax(320px, 1.6fr)',
			},
		],
		[]
	);

	const rows = useMemo< DataTableRow[] >(
		() =>
			approvals.map( ( approval ) => ( {
				id: approval.id,
				cells: [
					<>
						<strong className="nvoos-pro-spa-tools-list__name">
							{ approval.tool }
						</strong>
						{ approval.session_id ? (
							<code className="nvoos-pro-spa-tools-list__slug">
								{ approval.session_id }
							</code>
						) : null }
					</>,
					approval.reason || '—',
					approval.created_at
						? new Date( approval.created_at * 1000 ).toLocaleString()
						: '—',
					<div className="nvoos-pro-spa-approvals__actions">
						<input
							type="text"
							className="nvoos-pro-spa-tools-page__input"
							value={ noteMap[ approval.id ] ?? '' }
							onChange={ ( e ) => setNote( approval.id, e.target.value ) }
							placeholder={ __( 'Resolution note (optional)', 'nvoos-pro-spa' ) }
							aria-label={ __( 'Resolution note', 'nvoos-pro-spa' ) }
						/>
						<button
							type="button"
							className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary nvoos-pro-spa-page__retry--small"
							disabled={ resolving }
							onClick={ () =>
								void resolveApproval(
									approval.id,
									'approve',
									noteMap[ approval.id ] ?? ''
								)
							}
						>
							{ __( 'Approve', 'nvoos-pro-spa' ) }
						</button>
						<button
							type="button"
							className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--danger nvoos-pro-spa-page__retry--small"
							disabled={ resolving }
							onClick={ () =>
								void resolveApproval(
									approval.id,
									'deny',
									noteMap[ approval.id ] ?? ''
								)
							}
						>
							{ __( 'Deny', 'nvoos-pro-spa' ) }
						</button>
					</div>,
				],
			} ) ),
		[ approvals, noteMap, resolving, resolveApproval ]
	);

	if ( bootstrapLoading || ( loading && approvals.length === 0 ) ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Approvals', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading approvals…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Approvals', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Approvals', 'nvoos-pro-spa' ) }
					{ total > 0 && (
						<span
							className="nvoos-pro-spa-approvals__count"
							aria-label={ __( 'Pending count', 'nvoos-pro-spa' ) }
						>
							{ total }
						</span>
					) }
				</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Review and resolve tool executions waiting for human approval.',
						'nvoos-pro-spa'
					) }
				</p>
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary"
						onClick={ () => void fetchApprovals() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ ! error && approvals.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'No pending approvals.', 'nvoos-pro-spa' ) }</p>
				</div>
			) : (
				<DataTable
					columns={ columns }
					rows={ rows }
					caption={ __( 'Pending approvals', 'nvoos-pro-spa' ) }
				/>
			) }

			<footer className="nvoos-pro-spa-tools-page__footer">
				<p>
					{ __( 'Showing', 'nvoos-pro-spa' ) } { approvals.length }{ ' ' }
					{ __( 'pending approvals', 'nvoos-pro-spa' ) }
				</p>
			</footer>
		</div>
	);
}
