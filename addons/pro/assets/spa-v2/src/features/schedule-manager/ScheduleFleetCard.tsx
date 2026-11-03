/**
 * ScheduleFleetCard — fleet-wide run aggregates for the Schedule Manager
 * landing view.
 *
 * Fed by GET /schedules/fleet-stats (computed server-side from the schedules
 * table + history ring buffers). Layout follows the healthchecks/Sidekiq ops
 * model: headline counts, the failure/overdue flags that need attention, and
 * a small outcome-split donut — nothing chart-heavy on the landing surface.
 */

import { useMemo, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import type { FleetStats } from '../../api/schedule-manager';
import { renderChartRecipe } from '../../components/shared/chartRecipes';
import { formatRunTime } from './ScheduleRunsView';

interface ScheduleFleetCardProps {
	stats: FleetStats;
	/** Optional fetch failure — the card degrades, never blocks the lists. */
	error?: string | null;
}

/** Build the outcome-split donut spec (success vs failure runs). */
function buildSplitSpec( stats: FleetStats ): string {
	return JSON.stringify( {
		type: 'donut',
		title: __( 'Fleet outcome split', 'nvoos-pro-spa' ),
		labels: [ __( 'Success', 'nvoos-pro-spa' ), __( 'Failure', 'nvoos-pro-spa' ) ],
		values: [ stats.success_runs, stats.failure_runs ],
		table: true,
	} );
}

export function ScheduleFleetCard( { stats, error }: ScheduleFleetCardProps ): JSX.Element {
	const splitSpec = useMemo( () => buildSplitSpec( stats ), [ stats.success_runs, stats.failure_runs ] );

	const hasRuns = stats.total_runs > 0;
	const needsAttention = stats.failures_24h > 0 || stats.overdue_schedules > 0;

	return (
		<section className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Fleet overview', 'nvoos-pro-spa' ) }>
			<div className="nvoos-pro-spa-tools-page__header">
				<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Fleet overview', 'nvoos-pro-spa' ) }</h3>
				{ needsAttention && (
					<span className="nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--warning">
						{ __( 'Needs attention', 'nvoos-pro-spa' ) }
					</span>
				) }
			</div>

			{ error && (
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					{ error }
				</p>
			) }

			<dl className="nvoos-pro-spa-records-detail__grid">
				<div className="nvoos-pro-spa-records-detail__item">
					<dt>{ __( 'Schedules', 'nvoos-pro-spa' ) }</dt>
					<dd>{ stats.total_schedules }</dd>
				</div>
				<div className="nvoos-pro-spa-records-detail__item">
					<dt>{ __( 'Enabled', 'nvoos-pro-spa' ) }</dt>
					<dd>{ stats.enabled_schedules }</dd>
				</div>
				<div className="nvoos-pro-spa-records-detail__item">
					<dt>{ __( 'Total runs', 'nvoos-pro-spa' ) }</dt>
					<dd>{ stats.total_runs }</dd>
				</div>
				<div className="nvoos-pro-spa-records-detail__item">
					<dt>{ __( 'Success rate', 'nvoos-pro-spa' ) }</dt>
					<dd>{ hasRuns ? `${ stats.success_rate }%` : '—' }</dd>
				</div>
				<div className="nvoos-pro-spa-records-detail__item">
					<dt>{ __( 'Failures (24 h)', 'nvoos-pro-spa' ) }</dt>
					<dd>
						{ stats.failures_24h > 0 ? (
							<span className="nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--warning">
								{ stats.failures_24h }
							</span>
						) : (
							'0'
						) }
					</dd>
				</div>
				<div className="nvoos-pro-spa-records-detail__item">
					<dt>{ __( 'Overdue', 'nvoos-pro-spa' ) }</dt>
					<dd>
						{ stats.overdue_schedules > 0 ? (
							<span className="nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--warning">
								{ stats.overdue_schedules }
							</span>
						) : (
							'0'
						) }
					</dd>
				</div>
				<div className="nvoos-pro-spa-records-detail__item">
					<dt>{ __( 'Last run', 'nvoos-pro-spa' ) }</dt>
					<dd>{ stats.last_run ? formatRunTime( stats.last_run ) : '—' }</dd>
				</div>
			</dl>

			{ hasRuns && (
				<div
					className="nvoos-pro-spa-dashboards-chart"
					// renderChartRecipe builds its HTML with textContent only —
					// values are inert by construction.
					dangerouslySetInnerHTML={ { __html: renderChartRecipe( splitSpec ) } }
				/>
			) }
		</section>
	);
}
