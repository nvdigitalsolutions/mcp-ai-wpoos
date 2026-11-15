/**
 * ScheduleRunsView — run history, reports, and captured results for one
 * schedule.
 *
 * Consumes the existing mcp-ai-pro/v1 schedule surface
 * (GET /schedules/{id}/history + /schedules/{id}/results) — no new
 * controller. Rendering follows the healthchecks/Sidekiq model: show what
 * ACTUALLY ran (status + duration per execution), flag missed windows, and
 * expose the failure mode — then the observability trio: success-rate stat,
 * duration distribution, outcomes timeline. Charts reuse the in-repo
 * nvoos-chart recipe renderer (HTML/CSS slats — no chart library).
 *
 * Plan: docs/project/plans/nvoos-pro-schedule-runs-reports.md
 */

import { useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import {
	ScheduleManagerClient,
	type ScheduleHistoryRow,
	type ScheduleItem,
	type ScheduleResultEnvelope,
} from '../../api/schedule-manager';
import { DataTable, type DataTableColumn, type DataTableRow } from '../../components/shared/DataTable';
import { renderChartRecipe } from '../../components/shared/chartRecipes';

interface ScheduleRunsViewProps {
	schedule: ScheduleItem;
	endpoint: string;
	nonce: string;
	onBack: () => void;
}

/** Render a run status as a diagnostics badge. */
function StatusBadge( { status }: { status: string } ): JSX.Element {
	const ok = status === 'success';
	return (
		<span
			className={ `nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--${ ok ? 'ok' : 'warning' }` }
		>
			{ status }
		</span>
	);
}

/** Format an epoch-seconds run time for labels and cells. */
export function formatRunTime( ts: number ): string {
	if ( ! ts ) {
		return '—';
	}
	const d = new Date( ts * 1000 );
	const pad = ( n: number ): string => String( n ).padStart( 2, '0' );
	return `${ d.getMonth() + 1 }/${ d.getDate() } ${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
}

/** Median of a non-empty numeric array. */
export function median( values: number[] ): number {
	if ( values.length === 0 ) {
		return 0;
	}
	const sorted = [ ...values ].sort( ( a, b ) => a - b );
	const mid = Math.floor( sorted.length / 2 );
	return sorted.length % 2 ? sorted[ mid ] : ( sorted[ mid - 1 ] + sorted[ mid ] ) / 2;
}

/** p95 of a non-empty numeric array (nearest-rank). */
export function p95( values: number[] ): number {
	if ( values.length === 0 ) {
		return 0;
	}
	const sorted = [ ...values ].sort( ( a, b ) => a - b );
	return sorted[ Math.min( sorted.length - 1, Math.floor( 0.95 * sorted.length ) ) ];
}

/** True when the latest run is older than 2x the median inter-run gap. */
export function isOverdue( startTimes: number[], now: number ): boolean {
	if ( startTimes.length < 2 ) {
		return false;
	}
	const gaps: number[] = [];
	for ( let i = 1; i < startTimes.length; i++ ) {
		gaps.push( Math.abs( startTimes[ i ] - startTimes[ i - 1 ] ) );
	}
	const med = median( gaps );
	if ( med <= 0 ) {
		return false;
	}
	return now - startTimes[ startTimes.length - 1 ] > med * 2;
}

/** One entry of the type-specific action log, rendered for humans. */
function renderLogEntry( entry: unknown ): JSX.Element | string {
	if ( ! entry || typeof entry !== 'object' ) {
		return String( entry );
	}
	const e = entry as Record< string, unknown >;
	if ( typeof e.tool_slug === 'string' ) {
		const label = typeof e.label === 'string' && e.label ? `${ e.label } — ` : '';
		const dur = typeof e.duration === 'number' ? ` (${ e.duration }s)` : '';
		return (
			<>
				<strong>{ label }{ e.tool_slug }</strong>
				{ dur }
				{ typeof e.result === 'string' && e.result ? `: ${ e.result }` : '' }
			</>
		);
	}
	if ( typeof e.channel === 'string' ) {
		return `${ e.channel }: ${ String( e.status ?? '' ) }`;
	}
	return JSON.stringify( entry );
}

function buildOutcomeSpec( chronological: ScheduleHistoryRow[] ): string {
	const labels = chronological.map( ( r ) => formatRunTime( r.start_time ?? 0 ) );
	const success = chronological.map( ( r ) => ( r.status === 'success' ? 1 : 0 ) );
	const failure = chronological.map( ( r ) => ( r.status === 'success' ? 0 : 1 ) );
	return JSON.stringify( {
		type: 'column',
		title: __( 'Outcomes over time', 'nvoos-pro-spa' ),
		labels,
		series: [
			{ name: __( 'Success', 'nvoos-pro-spa' ), values: success },
			{ name: __( 'Failure', 'nvoos-pro-spa' ), values: failure },
		],
		mode: 'stacked',
		table: true,
	} );
}

function buildDurationSpec( chronological: ScheduleHistoryRow[] ): string {
	return JSON.stringify( {
		type: 'column',
		title: __( 'Duration per run', 'nvoos-pro-spa' ),
		labels: chronological.map( ( r ) => formatRunTime( r.start_time ?? 0 ) ),
		values: chronological.map( ( r ) => ( typeof r.duration === 'number' ? r.duration : 0 ) ),
		unit: 's',
		table: true,
	} );
}

function buildSplitSpec( successCount: number, failureCount: number ): string {
	return JSON.stringify( {
		type: 'donut',
		title: __( 'Outcome split', 'nvoos-pro-spa' ),
		labels: [ __( 'Success', 'nvoos-pro-spa' ), __( 'Failure', 'nvoos-pro-spa' ) ],
		values: [ successCount, failureCount ],
		table: true,
	} );
}

/** Build and download a CSV of the visible run history (client-side). */
export function downloadRunsCsv( runs: ScheduleHistoryRow[], scheduleName: string ): void {
	const header = [ 'Schedule', 'Start time', 'Status', 'Duration (s)', 'Error', 'Action log' ];
	const rows = runs.map( ( r ) => [
		scheduleName,
		formatRunTime( r.start_time ?? 0 ),
		r.status ?? '',
		typeof r.duration === 'number' ? String( r.duration ) : '',
		r.error ?? '',
		JSON.stringify( r.action_log ?? [] ),
	] );
	const csv = [ header, ...rows ]
		.map( ( row ) => row.map( ( cell ) => `"${ String( cell ).replace( /"/g, '""' ) }"` ).join( ',' ) )
		.join( '\n' );
	const url = URL.createObjectURL( new Blob( [ csv ], { type: 'text/csv;charset=utf-8' } ) );
	const anchor = document.createElement( 'a' );
	anchor.href = url;
	anchor.download = `schedule-runs-${ scheduleName.replace( /[^a-z0-9]+/gi, '-' ).toLowerCase() }.csv`;
	document.body.appendChild( anchor );
	anchor.click();
	anchor.remove();
	URL.revokeObjectURL( url );
}

/** Render one captured result envelope by its render type. */
function EnvelopeView( { envelope }: { envelope: ScheduleResultEnvelope } ): JSX.Element {
	if ( envelope.render === 'text' ) {
		return <p className="nvoos-pro-spa-diagnostics__muted">{ envelope.summary || '—' }</p>;
	}
	if ( envelope.render === 'table' && Array.isArray( envelope.data ) ) {
		const rows = envelope.data as Array< Record< string, unknown > >;
		if ( rows.length === 0 ) {
			return <p className="nvoos-pro-spa-diagnostics__muted">{ __( 'No rows.', 'nvoos-pro-spa' ) }</p>;
		}
		const cols = Object.keys( rows[ 0 ] ?? {} );
		const columns: DataTableColumn[] = cols.map( ( key ) => ( { key, label: key } ) );
		const tableRows: DataTableRow[] = rows.map( ( row, i ) => ( {
			id: `envelope-row-${ i }`,
			cells: cols.map( ( key ) => (
				<span key={ key }>{ row[ key ] === null || row[ key ] === undefined ? '—' : String( row[ key ] ) }</span>
			) ),
		} ) );
		return (
			<DataTable
				columns={ columns }
				rows={ tableRows }
				caption={ __( 'Captured result', 'nvoos-pro-spa' ) }
			/>
		);
	}
	return <pre className="nvoos-pro-spa-diagnostics__muted">{ JSON.stringify( envelope, null, 2 ) }</pre>;
}

export function ScheduleRunsView( {
	schedule,
	endpoint,
	nonce,
	onBack,
}: ScheduleRunsViewProps ): JSX.Element {
	const [ runs, setRuns ] = useState< ScheduleHistoryRow[] >( [] );
	const [ results, setResults ] = useState< ScheduleResultEnvelope[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ reloadKey, setReloadKey ] = useState< number >( 0 );

	useEffect( () => {
		if ( ! endpoint || ! schedule.id ) {
			setLoading( false );
			return;
		}
		const controller = new AbortController();
		const client = new ScheduleManagerClient( { endpoint, nonce } );
		setLoading( true );
		setError( null );
		Promise.allSettled( [
			client.history( schedule.id, controller.signal ),
			client.results( schedule.id, 10, controller.signal ),
		] )
			.then( ( [ historyResult, resultsResult ] ) => {
				if ( controller.signal.aborted ) {
					return;
				}
				if ( historyResult.status === 'fulfilled' ) {
					setRuns( historyResult.value );
				} else {
					setError(
						historyResult.reason instanceof Error
							? historyResult.reason.message
							: String( historyResult.reason )
					);
				}
				if ( resultsResult.status === 'fulfilled' ) {
					setResults( resultsResult.value );
				}
				setLoading( false );
			} );
		return () => controller.abort();
	}, [ endpoint, nonce, schedule.id, reloadKey ] );

	// The server returns newest-first; charts and the overdue math want
	// chronological order.
	const chronological = useMemo( () => [ ...runs ].reverse(), [ runs ] );

	const stats = useMemo( () => {
		const durations = runs
			.map( ( r ) => r.duration )
			.filter( ( d ): d is number => typeof d === 'number' );
		const successCount = runs.filter( ( r ) => r.status === 'success' ).length;
		const startTimes = chronological
			.map( ( r ) => r.start_time )
			.filter( ( t ): t is number => typeof t === 'number' );
		const lastRun = startTimes.length ? startTimes[ startTimes.length - 1 ] : 0;
		const avg = durations.length ? durations.reduce( ( a, b ) => a + b, 0 ) / durations.length : 0;
		return {
			total: runs.length,
			successCount,
			failureCount: runs.length - successCount,
			successRate: runs.length ? Math.round( ( successCount / runs.length ) * 100 ) : 0,
			avg,
			p95: p95( durations ),
			lastRun,
			overdue: isOverdue( startTimes, Date.now() / 1000 ),
		};
	}, [ runs, chronological ] );

	const chartSpecs = useMemo( () => {
		if ( chronological.length === 0 ) {
			return [];
		}
		return [
			buildOutcomeSpec( chronological ),
			buildDurationSpec( chronological ),
			buildSplitSpec( stats.successCount, stats.failureCount ),
		];
	}, [ chronological, stats.successCount, stats.failureCount ] );

	if ( loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading run history…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	const columns: DataTableColumn[] = [
		{ key: 'time', label: __( 'Start time', 'nvoos-pro-spa' ), width: '150px' },
		{ key: 'status', label: __( 'Status', 'nvoos-pro-spa' ), width: '110px' },
		{ key: 'duration', label: __( 'Duration', 'nvoos-pro-spa' ), width: '100px' },
		{ key: 'error', label: __( 'Error', 'nvoos-pro-spa' ), width: 'minmax(200px, 1.4fr)' },
		{ key: 'log', label: __( 'Action log', 'nvoos-pro-spa' ), width: 'minmax(220px, 1fr)' },
	];

	const rows: DataTableRow[] = runs.map( ( run, index ) => {
		const logEntries = Array.isArray( run.action_log ) ? run.action_log : [];
		return {
			id: `run-${ index }`,
			cells: [
				<span>{ formatRunTime( run.start_time ?? 0 ) }</span>,
				<StatusBadge status={ run.status ?? 'unknown' } />,
				<span>{ typeof run.duration === 'number' ? `${ run.duration.toFixed( 1 ) }s` : '—' }</span>,
				<span title={ run.error || '' }>{ run.error || '—' }</span>,
				<details>
					<summary>{ __( 'Show log', 'nvoos-pro-spa' ) } ({ logEntries.length })</summary>
					{ logEntries.length === 0 ? (
						<p className="nvoos-pro-spa-diagnostics__muted">{ __( 'No action log.', 'nvoos-pro-spa' ) }</p>
					) : (
						<ul>
							{ logEntries.map( ( entry, i ) => (
								<li key={ i }>{ renderLogEntry( entry ) }</li>
							) ) }
						</ul>
					) }
				</details>,
			],
		};
	} );

	const latestEnvelope = results[ 0 ];

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<button
					type="button"
					className="nvoos-pro-spa-token-manager__action"
					onClick={ onBack }
				>
					← { __( 'Back to schedules', 'nvoos-pro-spa' ) }
				</button>
				<h2 className="nvoos-pro-spa-page__title">{ schedule.name }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Run history and captured results', 'nvoos-pro-spa' ) }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => setReloadKey( ( key ) => key + 1 ) }
				>
					{ __( 'Refresh', 'nvoos-pro-spa' ) }
				</button>
				{ runs.length > 0 && (
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => downloadRunsCsv( runs, schedule.name ) }
					>
						{ __( 'Export CSV', 'nvoos-pro-spa' ) }
					</button>
				) }
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
				</div>
			) }

			<section className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Run summary', 'nvoos-pro-spa' ) }>
				<dl className="nvoos-pro-spa-records-detail__grid">
					<div className="nvoos-pro-spa-records-detail__item">
						<dt>{ __( 'Runs', 'nvoos-pro-spa' ) }</dt>
						<dd>{ stats.total }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item">
						<dt>{ __( 'Success rate', 'nvoos-pro-spa' ) }</dt>
						<dd>{ stats.total ? `${ stats.successRate }%` : '—' }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item">
						<dt>{ __( 'Avg duration', 'nvoos-pro-spa' ) }</dt>
						<dd>{ stats.total ? `${ stats.avg.toFixed( 1 ) }s` : '—' }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item">
						<dt>{ __( 'p95 duration', 'nvoos-pro-spa' ) }</dt>
						<dd>{ stats.total ? `${ stats.p95.toFixed( 1 ) }s` : '—' }</dd>
					</div>
					<div className="nvoos-pro-spa-records-detail__item">
						<dt>{ __( 'Last run', 'nvoos-pro-spa' ) }</dt>
						<dd>
							{ stats.lastRun ? formatRunTime( stats.lastRun ) : '—' }
							{ stats.overdue && (
								<StatusBadge status={ __( 'overdue', 'nvoos-pro-spa' ) } />
							) }
						</dd>
					</div>
				</dl>
			</section>

			{ chartSpecs.length > 0 && (
				<section className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Charts', 'nvoos-pro-spa' ) }>
					<ul className="nvoos-pro-spa-records-detail__grid nvoos-pro-spa-records-detail__grid--list">
						{ chartSpecs.map( ( spec ) => (
							<li
								key={ spec }
								className="nvoos-pro-spa-records-detail__item nvoos-pro-spa-dashboards-chart"
								// renderChartRecipe builds its HTML with textContent
								// only — values are inert by construction.
								dangerouslySetInnerHTML={ { __html: renderChartRecipe( spec ) } }
							/>
						) ) }
					</ul>
				</section>
			) }

			<section className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Run history', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Run history', 'nvoos-pro-spa' ) }</h3>
				{ runs.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No runs recorded yet.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<DataTable
						columns={ columns }
						rows={ rows }
						caption={ __( 'Schedule run history', 'nvoos-pro-spa' ) }
					/>
				) }
			</section>

			{ latestEnvelope && (
				<section className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Captured results', 'nvoos-pro-spa' ) }>
					<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Latest captured result', 'nvoos-pro-spa' ) }</h3>
					<EnvelopeView envelope={ latestEnvelope } />
					{ results.length > 1 && (
						<details>
							<summary>{ __( 'Earlier results', 'nvoos-pro-spa' ) } ({ results.length - 1 })</summary>
							{ results.slice( 1 ).map( ( envelope, index ) => (
								<EnvelopeView envelope={ envelope } key={ `envelope-${ index }` } />
							) ) }
						</details>
					) }
				</section>
			) }
		</div>
	);
}
