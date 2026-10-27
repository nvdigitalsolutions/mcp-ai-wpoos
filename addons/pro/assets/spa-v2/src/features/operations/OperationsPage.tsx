/**
 * OperationsPage — cron jobs + dead-letter queue (Proposal 084 Phase 3).
 *
 * Replaces the wp-admin Cron Manager and DLQ Manager pages in the console;
 * both wp-admin pages stay behind their kill-switch filters until parity is
 * verified. Read-mostly: remove/unschedule a cron job, reprocess or dismiss
 * a dead letter.
 */

import { useState, useMemo, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useOperations } from '../../hooks/useOperations';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';
import { Pagination } from '../../components/shared/Pagination';

const CRON_COLUMNS: DataTableColumn[] = [
	{ key: 'job', label: __( 'Job', 'nvoos-pro-spa' ), width: 'minmax(220px, 1.2fr)' },
	{ key: 'schedule', label: __( 'Schedule', 'nvoos-pro-spa' ), width: '140px' },
	{ key: 'status', label: __( 'Status', 'nvoos-pro-spa' ), width: '120px' },
	{ key: 'actions', label: __( 'Actions', 'nvoos-pro-spa' ), width: '130px' },
];

const DLQ_COLUMNS: DataTableColumn[] = [
	{ key: 'item', label: __( 'Item', 'nvoos-pro-spa' ), width: 'minmax(220px, 1fr)' },
	{ key: 'type', label: __( 'Type', 'nvoos-pro-spa' ), width: '140px' },
	{
		key: 'reason',
		label: __( 'Failure reason', 'nvoos-pro-spa' ),
		className: 'nvoos-pro-spa-tools-list__cell--desc',
		width: 'minmax(240px, 1.4fr)',
	},
	{ key: 'actions', label: __( 'Actions', 'nvoos-pro-spa' ), width: 'minmax(180px, 0.8fr)' },
];

const TIMELINE_COLUMNS: DataTableColumn[] = [
	{ key: 'run', label: __( 'Run', 'nvoos-pro-spa' ), width: 'minmax(180px, 0.8fr)' },
	{ key: 'started', label: __( 'Started', 'nvoos-pro-spa' ), width: '200px' },
	{
		key: 'tokens',
		label: __( 'Tokens', 'nvoos-pro-spa' ),
		className: 'nvoos-pro-spa-tools-list__cell--num',
		width: '100px',
	},
	{
		key: 'cost',
		label: __( 'Cost', 'nvoos-pro-spa' ),
		className: 'nvoos-pro-spa-tools-list__cell--num',
		width: '110px',
	},
	{
		key: 'latency',
		label: __( 'Latency', 'nvoos-pro-spa' ),
		className: 'nvoos-pro-spa-tools-list__cell--num',
		width: '110px',
	},
];

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
	// `loading` from the hook covers the initial cron/DLQ/timeline load only;
	// timeline page turns need their own busy flag so the pager locks while
	// the next page is in flight.
	const [ timelineBusy, setTimelineBusy ] = useState< boolean >( false );

	const goToTimelinePage = ( next: number ): void => {
		setTimelineBusy( true );
		void loadTimelinePage( next ).finally( () => setTimelineBusy( false ) );
	};

	const cronRows = useMemo< DataTableRow[] >(
		() =>
			cronJobs.map( ( job ) => ( {
				id: job.job_id,
				cells: [
					<>
						<strong className="nvoos-pro-spa-tools-list__name">
							{ job.hook ?? job.job_id }
						</strong>
						<code className="nvoos-pro-spa-tools-list__slug">{ job.job_id }</code>
					</>,
					job.schedule ? (
						<span className="nvoos-pro-spa-tools-list__badge">{ job.schedule }</span>
					) : (
						'—'
					),
					<span className="nvoos-pro-spa-tools-list__badge">{ job.status ?? '—' }</span>,
					<button
						type="button"
						className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--danger nvoos-pro-spa-page__retry--small"
						disabled={ mutating }
						onClick={ () => void removeCronJob( job.job_id ) }
					>
						{ __( 'Remove', 'nvoos-pro-spa' ) }
					</button>,
				],
			} ) ),
		[ cronJobs, mutating, removeCronJob ]
	);

	const dlqRows = useMemo< DataTableRow[] >(
		() =>
			dlqItems.map( ( item ) => ( {
				id: item.item_id,
				cells: [
					<>
						<strong className="nvoos-pro-spa-tools-list__name">
							{ item.identifier || item.item_id }
						</strong>
						<code className="nvoos-pro-spa-tools-list__slug">{ item.item_id }</code>
					</>,
					<span className="nvoos-pro-spa-tools-list__badge">{ item.type }</span>,
					item.failure_reason || '—',
					<div className="nvoos-pro-spa-approvals__actions">
						<button
							type="button"
							className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary nvoos-pro-spa-page__retry--small"
							disabled={ mutating }
							onClick={ () => void retryDlq( item.item_id ) }
						>
							{ __( 'Retry', 'nvoos-pro-spa' ) }
						</button>
						<button
							type="button"
							className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
							disabled={ mutating }
							onClick={ () => void dismissDlq( item.item_id ) }
						>
							{ __( 'Dismiss', 'nvoos-pro-spa' ) }
						</button>
					</div>,
				],
			} ) ),
		[ dlqItems, mutating, retryDlq, dismissDlq ]
	);

	const timelineRows = useMemo< DataTableRow[] >(
		() =>
			timeline.runs.map( ( run ) => ( {
				id: run.run_id,
				cells: [
					<>
						<strong className="nvoos-pro-spa-tools-list__name">{ run.run_id }</strong>
						{ run.assistant_id > 0 && (
							<code className="nvoos-pro-spa-tools-list__slug">
								{ __( 'Assistant', 'nvoos-pro-spa' ) } #{ run.assistant_id }
							</code>
						) }
					</>,
					run.started_at
						? new Date( run.started_at * 1000 ).toLocaleString()
						: '—',
					run.total_tokens ?? '—',
					typeof run.cost_usd === 'number'
						? `$${ run.cost_usd.toFixed( 4 ) }`
						: '—',
					typeof run.latency_ms === 'number' ? `${ run.latency_ms } ms` : '—',
				],
			} ) ),
		[ timeline.runs ]
	);

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
						className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary"
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
				<DataTable
					columns={ CRON_COLUMNS }
					rows={ cronRows }
					caption={ __( 'Cron jobs', 'nvoos-pro-spa' ) }
					empty={
						<p>{ __( 'No tracked cron jobs.', 'nvoos-pro-spa' ) }</p>
					}
				/>
			) }

			{ activeTab === 'dlq' && (
				<DataTable
					columns={ DLQ_COLUMNS }
					rows={ dlqRows }
					caption={ __( 'Dead-letter queue', 'nvoos-pro-spa' ) }
					empty={
						<p>{ __( 'No dead letters.', 'nvoos-pro-spa' ) }</p>
					}
				/>
			) }

			{ activeTab === 'timeline' && (
				<DataTable
					columns={ TIMELINE_COLUMNS }
					rows={ timelineRows }
					caption={ __( 'Run timeline', 'nvoos-pro-spa' ) }
					empty={
						<p>{ __( 'No recorded runs yet.', 'nvoos-pro-spa' ) }</p>
					}
					busy={ timelineBusy }
					footer={
						<Pagination
							page={ timeline.page }
							perPage={ timeline.per_page }
							total={ timeline.total }
							itemLabel={ __( 'runs', 'nvoos-pro-spa' ) }
							label={ __( 'Run timeline pagination', 'nvoos-pro-spa' ) }
							busy={ timelineBusy }
							onPageChange={ goToTimelinePage }
						/>
					}
				/>
			) }
		</div>
	);
}
