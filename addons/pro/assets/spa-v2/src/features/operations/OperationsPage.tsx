/**
 * OperationsPage — cron jobs + dead-letter queue (Proposal 084 Phase 3).
 *
 * Replaces the wp-admin Cron Manager and DLQ Manager pages in the console;
 * both wp-admin pages stay behind their kill-switch filters until parity is
 * verified. Read-mostly: remove/unschedule a cron job, reprocess or dismiss
 * a dead letter.
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useOperations } from '../../hooks/useOperations';
import { useBootstrap } from '../../hooks/useBootstrap';

export function OperationsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const {
		cronJobs,
		dlqItems,
		timeline,
		loading,
		mutating,
		error,
		refresh,
		removeCronJob,
		retryDlq,
		dismissDlq,
		loadTimelinePage,
	} = useOperations(
		runtime
			? { endpoint: runtime.endpoints.operations ?? '', nonce: runtime.nonce }
			: { endpoint: '', nonce: '' }
	);

	const [ activeTab, setActiveTab ] = useState< 'cron' | 'dlq' | 'timeline' >( 'cron' );

	if ( bootstrapLoading || ( loading && cronJobs.length === 0 && dlqItems.length === 0 ) ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Operations', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading operations…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Operations', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Operations', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Cron jobs scheduled by the plugin and the dead-letter queue.',
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
						onClick={ () => void refresh() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			<div className="nvoos-pro-spa-settings__tabs" role="tablist" aria-label={ __( 'Operations tabs', 'nvoos-pro-spa' ) }>
				<button
					type="button"
					role="tab"
					aria-selected={ activeTab === 'cron' }
					className={
						activeTab === 'cron'
							? 'nvoos-pro-spa-settings__tab nvoos-pro-spa-settings__tab--active'
							: 'nvoos-pro-spa-settings__tab'
					}
					onClick={ () => setActiveTab( 'cron' ) }
				>
					{ __( 'Cron Jobs', 'nvoos-pro-spa' ) } ({ cronJobs.length })
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={ activeTab === 'dlq' }
					className={
						activeTab === 'dlq'
							? 'nvoos-pro-spa-settings__tab nvoos-pro-spa-settings__tab--active'
							: 'nvoos-pro-spa-settings__tab'
					}
					onClick={ () => setActiveTab( 'dlq' ) }
				>
					{ __( 'Dead Letters', 'nvoos-pro-spa' ) } ({ dlqItems.length })
				</button>
				<button
					type="button"
					role="tab"
					aria-selected={ activeTab === 'timeline' }
					className={
						activeTab === 'timeline'
							? 'nvoos-pro-spa-settings__tab nvoos-pro-spa-settings__tab--active'
							: 'nvoos-pro-spa-settings__tab'
					}
					onClick={ () => setActiveTab( 'timeline' ) }
				>
					{ __( 'Run Timeline', 'nvoos-pro-spa' ) } ({ timeline.total })
				</button>
			</div>

			{ activeTab === 'cron' && (
				<div
					className="nvoos-pro-spa-tools-list"
					role="list"
					aria-label={ __( 'Cron jobs', 'nvoos-pro-spa' ) }
				>
					<div className="nvoos-pro-spa-tools-list__header" role="row">
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Job', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Schedule', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Status', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Actions', 'nvoos-pro-spa' ) }
						</div>
					</div>
					{ cronJobs.length === 0 ? (
						<div className="nvoos-pro-spa-tools-page__empty" role="status">
							<p>{ __( 'No tracked cron jobs.', 'nvoos-pro-spa' ) }</p>
						</div>
					) : (
						cronJobs.map( ( job ) => (
							<div key={ job.job_id } className="nvoos-pro-spa-tools-list__row" role="row">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ job.hook ?? job.job_id }</strong>
									<code className="nvoos-pro-spa-tools-list__slug">{ job.job_id }</code>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									{ job.schedule ? (
										<span className="nvoos-pro-spa-tools-list__badge">{ job.schedule }</span>
									) : (
										'—'
									) }
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<span className="nvoos-pro-spa-tools-list__badge">{ job.status ?? '—' }</span>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<button
										type="button"
										className="nvoos-pro-spa-page__retry"
										disabled={ mutating }
										onClick={ () => void removeCronJob( job.job_id ) }
									>
										{ __( 'Remove', 'nvoos-pro-spa' ) }
									</button>
								</div>
							</div>
						) )
					) }
				</div>
			) }

			{ activeTab === 'dlq' && (
				<div
					className="nvoos-pro-spa-tools-list"
					role="list"
					aria-label={ __( 'Dead-letter queue', 'nvoos-pro-spa' ) }
				>
					<div className="nvoos-pro-spa-tools-list__header" role="row">
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Item', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Type', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Failure reason', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Actions', 'nvoos-pro-spa' ) }
						</div>
					</div>
					{ dlqItems.length === 0 ? (
						<div className="nvoos-pro-spa-tools-page__empty" role="status">
							<p>{ __( 'No dead letters.', 'nvoos-pro-spa' ) }</p>
						</div>
					) : (
						dlqItems.map( ( item ) => (
							<div key={ item.item_id } className="nvoos-pro-spa-tools-list__row" role="row">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ item.identifier || item.item_id }</strong>
									<code className="nvoos-pro-spa-tools-list__slug">{ item.item_id }</code>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<span className="nvoos-pro-spa-tools-list__badge">{ item.type }</span>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
									{ item.failure_reason || '—' }
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<div className="nvoos-pro-spa-approvals__actions">
										<button
											type="button"
											className="nvoos-pro-spa-page__retry"
											disabled={ mutating }
											onClick={ () => void retryDlq( item.item_id ) }
										>
											{ __( 'Retry', 'nvoos-pro-spa' ) }
										</button>
										<button
											type="button"
											className="nvoos-pro-spa-page__retry"
											disabled={ mutating }
											onClick={ () => void dismissDlq( item.item_id ) }
										>
											{ __( 'Dismiss', 'nvoos-pro-spa' ) }
										</button>
									</div>
								</div>
							</div>
						) )
					) }
				</div>
			) }

			{ activeTab === 'timeline' && (
				<div
					className="nvoos-pro-spa-tools-list"
					role="list"
					aria-label={ __( 'Run timeline', 'nvoos-pro-spa' ) }
				>
					<div className="nvoos-pro-spa-tools-list__header" role="row">
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Run', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Started', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Tokens', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Cost', 'nvoos-pro-spa' ) }
						</div>
						<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head" role="columnheader">
							{ __( 'Latency', 'nvoos-pro-spa' ) }
						</div>
					</div>
					{ timeline.runs.length === 0 ? (
						<div className="nvoos-pro-spa-tools-page__empty" role="status">
							<p>{ __( 'No recorded runs yet.', 'nvoos-pro-spa' ) }</p>
						</div>
					) : (
						timeline.runs.map( ( run ) => (
							<div key={ run.run_id } className="nvoos-pro-spa-tools-list__row" role="row">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ run.run_id }</strong>
									{ run.assistant_id > 0 && (
										<code className="nvoos-pro-spa-tools-list__slug">
											{ __( 'Assistant', 'nvoos-pro-spa' ) } #{ run.assistant_id }
										</code>
									) }
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									{ run.started_at
										? new Date( run.started_at * 1000 ).toLocaleString()
										: '—' }
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									{ run.total_tokens ?? '—' }
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									{ typeof run.cost_usd === 'number'
										? `$${ run.cost_usd.toFixed( 4 ) }`
										: '—' }
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									{ typeof run.latency_ms === 'number' ? `${ run.latency_ms } ms` : '—' }
								</div>
							</div>
						) )
					) }
					{ ( timeline.page > 1 || timeline.total > timeline.page * timeline.per_page ) && (
						<div className="nvoos-pro-spa-tools-page__pager">
							<button
								type="button"
								className="nvoos-pro-spa-page__retry"
								disabled={ timeline.page <= 1 }
								onClick={ () => void loadTimelinePage( timeline.page - 1 ) }
							>
								{ __( 'Previous', 'nvoos-pro-spa' ) }
							</button>
							<span className="nvoos-pro-spa-tools-page__pager-label">
								{ __( 'Page', 'nvoos-pro-spa' ) } { timeline.page }
							</span>
							<button
								type="button"
								className="nvoos-pro-spa-page__retry"
								disabled={ timeline.total <= timeline.page * timeline.per_page }
								onClick={ () => void loadTimelinePage( timeline.page + 1 ) }
							>
								{ __( 'Next', 'nvoos-pro-spa' ) }
							</button>
						</div>
					) }
				</div>
			) }
		</div>
	);
}
