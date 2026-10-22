/**
 * TriggersPage — workflow trigger management.
 *
 * Lists workflow triggers (GET /mcp-ai/v1/triggers) with enable/disable and
 * delete actions. Replaces the wp-admin Workflow Triggers page in the
 * console (Proposal 084 Phase 1); the wp-admin page stays behind the
 * wp_mcp_ai_admin_page_enabled_workflow_triggers filter until parity is
 * verified.
 */

import { useMemo, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useTriggers } from '../../hooks/useTriggers';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';

export function TriggersPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { triggers, loading, error, mutating, fetchTriggers, toggleTrigger, deleteTrigger } =
		useTriggers(
			runtime
				? { endpoint: runtime.endpoints.triggers, nonce: runtime.nonce }
				: { endpoint: '', nonce: '' }
		);

const columns = useMemo< DataTableColumn[] >(
	() => [
		{ key: 'trigger', label: __( 'Trigger', 'nvoos-pro-spa' ), width: 'minmax(220px, 1.4fr)' },
		{ key: 'type', label: __( 'Type', 'nvoos-pro-spa' ), width: '130px' },
		{ key: 'workflow', label: __( 'Workflow', 'nvoos-pro-spa' ), width: '110px' },
		{ key: 'last', label: __( 'Last fired', 'nvoos-pro-spa' ), width: '190px' },
		{ key: 'actions', label: __( 'Actions', 'nvoos-pro-spa' ), width: 'minmax(200px, 0.8fr)' },
	],
	[]
);

const rows = useMemo< DataTableRow[] >(
	() =>
		triggers.map( ( trigger ) => ( {
			id: trigger.id,
			cells: [
				<>
					<strong className="nvoos-pro-spa-tools-list__name">
						{ trigger.name }
					</strong>
					{ trigger.webhook_url ? (
						<code className="nvoos-pro-spa-tools-list__slug">
							{ trigger.webhook_url }
						</code>
					) : null }
				</>,
				<span className="nvoos-pro-spa-tools-list__badge">
					{ trigger.type }
				</span>,
				<code className="nvoos-pro-spa-tools-list__capability">
					#{ trigger.workflow_id }
				</code>,
				trigger.last_fired_at
					? new Date( trigger.last_fired_at * 1000 ).toLocaleString()
					: '—',
				<div className="nvoos-pro-spa-approvals__actions">
					<button
						type="button"
						className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
						disabled={ mutating }
						aria-pressed={ trigger.enabled }
						onClick={ () => void toggleTrigger( trigger.id, ! trigger.enabled ) }
					>
						{ trigger.enabled
							? __( 'Disable', 'nvoos-pro-spa' )
							: __( 'Enable', 'nvoos-pro-spa' ) }
					</button>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--danger nvoos-pro-spa-page__retry--small"
						disabled={ mutating }
						onClick={ () => void deleteTrigger( trigger.id ) }
					>
						{ __( 'Delete', 'nvoos-pro-spa' ) }
					</button>
				</div>,
			],
		} ) ),
	[ triggers, mutating, toggleTrigger, deleteTrigger ]
);

	if ( bootstrapLoading || ( loading && triggers.length === 0 ) ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Workflow Triggers', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading triggers…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Workflow Triggers', 'nvoos-pro-spa' ) }
				</h2>
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
					{ __( 'Workflow Triggers', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Automation entry points bound to workflows.',
						'nvoos-pro-spa'
					) }
				</p>
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => void fetchTriggers() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ ! error && triggers.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'No workflow triggers registered.', 'nvoos-pro-spa' ) }</p>
				</div>
			) : (
				<DataTable
					columns={ columns }
					rows={ rows }
					caption={ __( 'Workflow triggers', 'nvoos-pro-spa' ) }
				/>
			) }

			<footer className="nvoos-pro-spa-tools-page__footer">
				<p>
					{ __( 'Showing', 'nvoos-pro-spa' ) } { triggers.length }{ ' ' }
					{ __( 'triggers', 'nvoos-pro-spa' ) }
				</p>
			</footer>
		</div>
	);
}
