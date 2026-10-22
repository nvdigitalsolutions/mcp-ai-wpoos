/**
 * TriggersPage — workflow trigger management.
 *
 * Lists workflow triggers (GET /mcp-ai/v1/triggers) with enable/disable and
 * delete actions. Replaces the wp-admin Workflow Triggers page in the
 * console (Proposal 084 Phase 1); the wp-admin page stays behind the
 * wp_mcp_ai_admin_page_enabled_workflow_triggers filter until parity is
 * verified.
 */

import { type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useTriggers } from '../../hooks/useTriggers';
import { useBootstrap } from '../../hooks/useBootstrap';

export function TriggersPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { triggers, loading, error, mutating, fetchTriggers, toggleTrigger, deleteTrigger } =
		useTriggers(
			runtime
				? { endpoint: runtime.endpoints.triggers, nonce: runtime.nonce }
				: { endpoint: '', nonce: '' }
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
				<div
					className="nvoos-pro-spa-tools-list"
					role="list"
					aria-label={ __( 'Workflow triggers', 'nvoos-pro-spa' ) }
				>
					<div className="nvoos-pro-spa-tools-list__header" role="row">
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Trigger', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Type', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Workflow', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Last fired', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Actions', 'nvoos-pro-spa' ) }
						</div>
					</div>
					{ triggers.map( ( trigger ) => (
						<div key={ trigger.id } className="nvoos-pro-spa-tools-list__row" role="row">
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								<strong className="nvoos-pro-spa-tools-list__name">{ trigger.name }</strong>
								{ trigger.webhook_url ? (
									<code className="nvoos-pro-spa-tools-list__slug">{ trigger.webhook_url }</code>
								) : null }
							</div>
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								<span className="nvoos-pro-spa-tools-list__badge">{ trigger.type }</span>
							</div>
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								<code className="nvoos-pro-spa-tools-list__capability">
									#{ trigger.workflow_id }
								</code>
							</div>
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								{ trigger.last_fired_at
									? new Date( trigger.last_fired_at * 1000 ).toLocaleString()
									: '—' }
							</div>
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								<div className="nvoos-pro-spa-approvals__actions">
									<button
										type="button"
										className="nvoos-pro-spa-page__retry"
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
										className="nvoos-pro-spa-page__retry"
										disabled={ mutating }
										onClick={ () => void deleteTrigger( trigger.id ) }
									>
										{ __( 'Delete', 'nvoos-pro-spa' ) }
									</button>
								</div>
							</div>
						</div>
					) ) }
				</div>
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
