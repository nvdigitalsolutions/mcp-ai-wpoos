/**
 * AnalyticsPage — site-wide usage + cost analytics (Proposal 084 Phase 5b).
 *
 * Consumes the manage_options-gated cost REST surface (mcp-ai/v1/cost) that
 * backs the wp-admin cost-breakdown / usage-overview dashboard widgets: a
 * period-scoped cost summary (total cost, tokens, per-provider/per-model
 * breakdowns), the daily cost trend line, the provider distribution donut,
 * and the top tools/users cost tables. Charts render through the shared
 * nvoos-chart recipe tier (`renderChartRecipe`) — textContent-only HTML.
 *
 * Follow-ups still tracked (widget parity, not yet ported): the usage
 * gauge, the usage forecast, and the analytics-manager anomalies surface
 * (REST is registered; the page will grow a user picker for it).
 */

import { useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useAnalytics } from '../../hooks/useAnalytics';
import { useBootstrap } from '../../hooks/useBootstrap';
import { renderChartRecipe } from '../../components/shared/chartRecipes';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';
import { costTrendSpec, providerDonutSpec } from '../../api/analytics';

/** Period windows (days). 90 is excluded until the chart tier relaxes its
 * 40-label cap — 30 days = 31 points is the current safe maximum. */
const PERIODS = [ 7, 30 ] as const;

const currency = new Intl.NumberFormat( 'en-US', {
	style: 'currency',
	currency: 'USD',
	maximumFractionDigits: 2,
} );

/** The REST trend dataset is already labelled in USD server-side. */
function formatCost( value: number ): string {
	return currency.format( value );
}

export function AnalyticsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const [ days, setDays ] = useState< number >( 30 );

	const { summary, trend, byProvider, loading, error, refresh } = useAnalytics( {
		endpoint: runtime?.endpoints.cost ?? '',
		nonce: runtime?.nonce ?? '',
		days,
	} );

	const toolColumns = useMemo< DataTableColumn[] >(
		() => [
			{ key: 'tool', label: __( 'Tool', 'nvoos-pro-spa' ) },
			{
				key: 'cost',
				label: __( 'Cost', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--num',
				width: '140px',
			},
		],
		[]
	);

	const toolRows = useMemo< DataTableRow[] >(
		() =>
			Object.entries( summary?.top_tools ?? {} )
				.sort( ( a, b ) => b[ 1 ] - a[ 1 ] )
				.map( ( [ slug, cost ] ) => ( {
					id: slug,
					cells: [
						<code className="nvoos-pro-spa-tools-list__slug">{ slug }</code>,
						formatCost( cost ),
					],
				} ) ),
		[ summary ]
	);

	const userColumns = useMemo< DataTableColumn[] >(
		() => [
			{ key: 'user', label: __( 'User', 'nvoos-pro-spa' ) },
			{
				key: 'cost',
				label: __( 'Cost', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--num',
				width: '140px',
			},
		],
		[]
	);

	const userRows = useMemo< DataTableRow[] >(
		() =>
			Object.entries( summary?.top_users ?? {} )
				.sort( ( a, b ) => b[ 1 ].cost - a[ 1 ].cost )
				.map( ( [ userId, entry ] ) => ( {
					id: userId,
					cells: [
						<strong className="nvoos-pro-spa-tools-list__name">
							{ entry.name || `#${ userId }` }
						</strong>,
						formatCost( entry.cost ),
					],
				} ) ),
		[ summary ]
	);

	const trendSpec = useMemo(
		() => costTrendSpec( __( 'Daily cost', 'nvoos-pro-spa' ), trend ),
		[ trend ]
	);
	const donutSpec = useMemo(
		() => providerDonutSpec( __( 'Cost by provider', 'nvoos-pro-spa' ), byProvider ),
		[ byProvider ]
	);

	if ( bootstrapLoading ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--loading"
				aria-busy="true"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Analytics', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--error"
				role="alert"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Analytics', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __(
						'Runtime configuration not available. Please reload the page.',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		);
	}

	if ( ! runtime.endpoints.cost ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="status">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Analytics', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message">
					{ __(
						'Cost analytics are available to administrators only.',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		);
	}

	if ( loading && ! summary ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--loading"
				aria-busy="true"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Analytics', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Collecting cost analytics…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	const hasUsage = summary !== null && summary.total_tokens > 0;
	const providerCount = summary ? Object.keys( summary.by_provider ).length : 0;

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<div>
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Analytics', 'nvoos-pro-spa' ) }
					</h2>
					<p className="nvoos-pro-spa-page__subtitle">
						{ __(
							'Token usage and AI cost across providers, tools, and users.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
				<div className="nvoos-pro-spa-tools-page__controls">
					<label
						htmlFor="nvoos-pro-spa-analytics-period"
						className="nvoos-pro-spa-sr-only"
					>
						{ __( 'Time period', 'nvoos-pro-spa' ) }
					</label>
					<select
						id="nvoos-pro-spa-analytics-period"
						className="nvoos-pro-spa-tools-page__select"
						value={ days }
						onChange={ ( e ) => setDays( Number( e.target.value ) ) }
					>
						{ PERIODS.map( ( period ) => (
							<option key={ period } value={ period }>
								{ period }{ ' ' }
								{ __( 'days', 'nvoos-pro-spa' ) }
							</option>
						) ) }
					</select>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
						disabled={ loading }
						onClick={ () => void refresh() }
					>
						{ __( 'Refresh', 'nvoos-pro-spa' ) }
					</button>
				</div>
			</header>

			{ error ? (
				<div
					className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error"
					role="alert"
				>
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => void refresh() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) : null }

			{ ! error && summary !== null && ! hasUsage ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>
						{ __(
							'No token usage recorded for this period.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			) : null }

			{ summary !== null && hasUsage ? (
				<>
					<ul
						className="nvoos-pro-spa-token-manager__stats"
						aria-label={ __( 'Cost summary', 'nvoos-pro-spa' ) }
					>
						<li className="nvoos-pro-spa-token-manager__stat">
							<div className="nvoos-pro-spa-token-manager__stat-label">
								{ __( 'Total cost', 'nvoos-pro-spa' ) }
							</div>
							<div className="nvoos-pro-spa-token-manager__stat-value">
								{ formatCost( summary.total_cost ) }
							</div>
						</li>
						<li className="nvoos-pro-spa-token-manager__stat">
							<div className="nvoos-pro-spa-token-manager__stat-label">
								{ __( 'Tokens', 'nvoos-pro-spa' ) }
							</div>
							<div className="nvoos-pro-spa-token-manager__stat-value">
								{ new Intl.NumberFormat( 'en-US' ).format( summary.total_tokens ) }
							</div>
						</li>
						<li className="nvoos-pro-spa-token-manager__stat">
							<div className="nvoos-pro-spa-token-manager__stat-label">
								{ __( 'Providers', 'nvoos-pro-spa' ) }
							</div>
							<div className="nvoos-pro-spa-token-manager__stat-value">
								{ providerCount }
							</div>
						</li>
						<li className="nvoos-pro-spa-token-manager__stat">
							<div className="nvoos-pro-spa-token-manager__stat-label">
								{ __( 'Period', 'nvoos-pro-spa' ) }
							</div>
							<div className="nvoos-pro-spa-token-manager__stat-value">
								{ summary.period_start } → { summary.period_end }
							</div>
						</li>
					</ul>

					{ trendSpec || donutSpec ? (
						<div className="nvoos-pro-spa-analytics-page__charts">
							{ trendSpec ? (
								<section
									className="nvoos-pro-spa-diagnostics__card"
									aria-label={ __( 'Daily cost trend', 'nvoos-pro-spa' ) }
								>
									<h3 className="nvoos-pro-spa-diagnostics__card-title">
										{ __( 'Daily cost', 'nvoos-pro-spa' ) }
									</h3>
									{ /* renderChartRecipe builds its HTML with
										textContent only — values are inert by
										construction. */ }
									<div
										className="nvoos-pro-spa-chart-recipe"
										dangerouslySetInnerHTML={ {
											__html: renderChartRecipe( trendSpec ),
										} }
									/>
								</section>
							) : null }
							{ donutSpec ? (
								<section
									className="nvoos-pro-spa-diagnostics__card"
									aria-label={ __( 'Cost by provider', 'nvoos-pro-spa' ) }
								>
									<h3 className="nvoos-pro-spa-diagnostics__card-title">
										{ __( 'Cost by provider', 'nvoos-pro-spa' ) }
									</h3>
									<div
										className="nvoos-pro-spa-chart-recipe"
										dangerouslySetInnerHTML={ {
											__html: renderChartRecipe( donutSpec ),
										} }
									/>
								</section>
							) : null }
						</div>
					) : null }

					<section
						className="nvoos-pro-spa-diagnostics__card nvoos-pro-spa-diagnostics__card--standalone"
						aria-label={ __( 'Top tools by cost', 'nvoos-pro-spa' ) }
					>
						<h3 className="nvoos-pro-spa-diagnostics__card-title">
							{ __( 'Top tools by cost', 'nvoos-pro-spa' ) }
						</h3>
						{ toolRows.length === 0 ? (
							<p className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'No tool costs recorded.', 'nvoos-pro-spa' ) }
							</p>
						) : (
							<DataTable
								columns={ toolColumns }
								rows={ toolRows }
								caption={ __( 'Top tools by cost', 'nvoos-pro-spa' ) }
							/>
						) }
					</section>

					<section
						className="nvoos-pro-spa-diagnostics__card nvoos-pro-spa-diagnostics__card--standalone"
						aria-label={ __( 'Top users by cost', 'nvoos-pro-spa' ) }
					>
						<h3 className="nvoos-pro-spa-diagnostics__card-title">
							{ __( 'Top users by cost', 'nvoos-pro-spa' ) }
						</h3>
						{ userRows.length === 0 ? (
							<p className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'No user costs recorded.', 'nvoos-pro-spa' ) }
							</p>
						) : (
							<DataTable
								columns={ userColumns }
								rows={ userRows }
								caption={ __( 'Top users by cost', 'nvoos-pro-spa' ) }
							/>
						) }
					</section>
				</>
			) : null }
		</div>
	);
}
