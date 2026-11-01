/**
 * DashboardsPage — the generic repeating-dashboard surface (Proposal 084
 * Phase 4, W13 CRE portfolio pilot).
 *
 * One page parameterized by server-driven dashboard configs
 * (GET /mcp-ai-pro/v1/dashboards/schema): dashboard picker, schema-driven
 * filter form, metric cards, and the loan summary table. W13a ships the
 * CRE Debt Portfolio; W13c adds the remaining repeating dashboards.
 *
 * Empty catalogue: every dashboard is gated by a toolkit toggle that is
 * OFF by default, so a fresh site returns an empty catalogue. The schema
 * envelope carries a `disabled` metadata map for exactly that case and the
 * page renders a guided empty state instead of a blank content area.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import { renderChartRecipe } from '../../components/shared/chartRecipes';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';
import {
	DashboardsClient,
	distributionChartSpec,
	formatMetric,
	type DashboardConfig,
	type DashboardData,
	type DisabledDashboard,
} from '../../api/dashboards';

function useDashboards( schemaEndpoint: string, nonce: string ): {
	dashboards: DashboardConfig[];
	disabled: DisabledDashboard[];
	schemaLoading: boolean;
	schemaError: string | null;
	refreshSchema: () => void;
	client: DashboardsClient;
} {
	const [ dashboards, setDashboards ] = useState< DashboardConfig[] >( [] );
	const [ disabled, setDisabled ] = useState< DisabledDashboard[] >( [] );
	// Distinct from `dashboards.length` so a genuinely empty catalogue
	// (all toolkits disabled) never renders the loading state forever.
	const [ schemaLoading, setSchemaLoading ] = useState< boolean >( true );
	const [ schemaError, setSchemaError ] = useState< string | null >( null );
	// Manual refresh (header button + empty-state retry) — bumps the effect.
	const [ refreshNonce, setRefreshNonce ] = useState< number >( 0 );

	const client = useMemo(
		() => new DashboardsClient( { schemaEndpoint, nonce } ),
		[ schemaEndpoint, nonce ]
	);

	useEffect( () => {
		if ( ! schemaEndpoint ) {
			setSchemaLoading( false );
			return;
		}
		let cancelled = false;
		const controller = new AbortController();
		setSchemaLoading( true );
		setSchemaError( null );
		client
			.catalogue( controller.signal )
			.then( ( catalogue ) => {
				if ( cancelled ) {
					return;
				}
				setDashboards( catalogue.dashboards );
				setDisabled( catalogue.disabled );
				setSchemaLoading( false );
			} )
			.catch( ( error: unknown ) => {
				if ( cancelled ) {
					return;
				}
				setSchemaError(
					error instanceof Error
						? error.message
						: __( 'Failed to load the dashboard catalogue.', 'nvoos-pro-spa' )
				);
				setSchemaLoading( false );
			} );
		return () => {
			cancelled = true;
			controller.abort();
		};
	}, [ client, schemaEndpoint, refreshNonce ] );

	const refreshSchema = useCallback( () => {
		setRefreshNonce( ( prev ) => prev + 1 );
	}, [] );

	return { dashboards, disabled, schemaLoading, schemaError, refreshSchema, client };
}

/** Human hint for a disabled dashboard — where to flip the toolkit on. */
function disabledDashboardHint( flag: string ): string {
	if ( ! flag ) {
		return __(
			'Enable its toolkit under Settings → NV oOS in wp-admin, then reload this page.',
			'nvoos-pro-spa'
		);
	}
	return (
		/* translators: %s: the settings flag that enables the toolkit. */
		sprintf( __( 'Enable the %s toolkit under Settings → NV oOS in wp-admin.', 'nvoos-pro-spa' ), flag )
	);
}

export function DashboardsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { dashboards, disabled, schemaLoading, schemaError, refreshSchema, client } = useDashboards(
		runtime?.endpoints.dashboardsSchema ?? '',
		runtime?.nonce ?? ''
	);

	const [ dashboardKey, setDashboardKey ] = useState< string >( '' );
	const [ filters, setFilters ] = useState< Record< string, string > >( {} );
	const [ data, setData ] = useState< DashboardData | null >( null );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const dashboard = useMemo(
		() => dashboards.find( ( d ) => d.id === dashboardKey ) ?? dashboards[ 0 ] ?? null,
		[ dashboards, dashboardKey ]
	);

	const refresh = useCallback(
		( current: DashboardConfig | null, currentFilters: Record< string, string > ) => {
			if ( ! current ) {
				return;
			}
			setLoading( true );
			setError( null );
			const controller = new AbortController();
			client
				.data( current.id, currentFilters, controller.signal )
				.then( ( payload ) => {
					setData( payload );
					setLoading( false );
				} )
				.catch( ( err: unknown ) => {
					setError(
						err instanceof Error
							? err.message
							: __( 'Failed to load dashboard data.', 'nvoos-pro-spa' )
					);
					setLoading( false );
				} );
			return () => controller.abort();
		},
		[ client ]
	);

	useEffect( () => {
		if ( ! dashboard ) {
			return;
		}
		setFilters( {} );
		setData( null );
		refresh( dashboard, {} );
	}, [ dashboard, refresh ] );

	if ( bootstrapLoading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Dashboards', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Dashboards', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	if ( schemaLoading && dashboards.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Dashboards', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading dashboards…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( schemaError ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Dashboards', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ schemaError }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ refreshSchema }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	const hasDashboards = dashboards.length > 0;

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Dashboards', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Filtered metric dashboards across the toolkits.', 'nvoos-pro-spa' ) }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					disabled={ schemaLoading }
					onClick={ refreshSchema }
				>
					{ __( 'Refresh', 'nvoos-pro-spa' ) }
				</button>
			</header>

			{ ! hasDashboards && (
				<section className="nvoos-pro-spa-dashboards-empty" role="status">
					<div className="nvoos-pro-spa-dashboards-empty__icon" aria-hidden="true">
						🧮
					</div>
					<h3 className="nvoos-pro-spa-dashboards-empty__title">
						{ __( 'No dashboards available yet', 'nvoos-pro-spa' ) }
					</h3>
					<p className="nvoos-pro-spa-dashboards-empty__message">
						{ __(
							'Dashboard pages are gated by their toolkits, which are all disabled on this site. Enable a toolkit to see its dashboard here.',
							'nvoos-pro-spa'
						) }
					</p>
					{ disabled.length > 0 && (
						<ul className="nvoos-pro-spa-dashboards-empty__list">
							{ disabled.map( ( entry, index ) => (
								<li key={ entry.toolkit_flag || String( index ) } className="nvoos-pro-spa-dashboards-empty__item">
									<span className="nvoos-pro-spa-dashboards-empty__item-icon" aria-hidden="true">
										{ entry.icon }
									</span>
									<div className="nvoos-pro-spa-dashboards-empty__item-body">
										<strong className="nvoos-pro-spa-dashboards-empty__item-label">
											{ entry.label }
										</strong>
										<p className="nvoos-pro-spa-dashboards-empty__item-description">
											{ entry.description }
										</p>
										<p className="nvoos-pro-spa-dashboards-empty__item-hint">
											{ disabledDashboardHint( entry.toolkit_flag ) }
										</p>
									</div>
								</li>
							) ) }
						</ul>
					) }
					<div className="nvoos-pro-spa-dashboards-empty__actions">
						<button
							type="button"
							className="nvoos-pro-spa-page__retry"
							disabled={ schemaLoading }
							onClick={ refreshSchema }
						>
							{ schemaLoading ? __( 'Checking…', 'nvoos-pro-spa' ) : __( 'Check again', 'nvoos-pro-spa' ) }
						</button>
					</div>
				</section>
			) }

			{ hasDashboards && dashboards.length > 1 && (
				<div className="nvoos-pro-spa-records-tabs" role="tablist" aria-label={ __( 'Dashboards', 'nvoos-pro-spa' ) }>
					{ dashboards.map( ( d ) => (
						<button
							key={ d.id }
							type="button"
							role="tab"
							aria-selected={ ( dashboard?.id ?? '' ) === d.id }
							className={
								'nvoos-pro-spa-records-tab' +
								( ( dashboard?.id ?? '' ) === d.id ? ' is-active' : '' )
							}
							onClick={ () => setDashboardKey( d.id ) }
						>
							{ d.icon } { d.label }
						</button>
					) ) }
				</div>
			) }

			{ dashboard && (
				<p className="nvoos-pro-spa-page__subtitle">{ dashboard.description }</p>
			) }

			{ dashboard && dashboard.filters.length > 0 && (
				<form
					className="nvoos-pro-spa-records-search"
					onSubmit={ ( event ) => {
						event.preventDefault();
						refresh( dashboard, filters );
					} }
				>
					{ dashboard.filters.map( ( filter ) => (
						<label key={ filter.key } className="nvoos-pro-spa-records-search__label">
							{ filter.label }
							{ ' ' }
							<select
								className="nvoos-pro-spa-records-search__input"
								value={ filters[ filter.key ] ?? '' }
								onChange={ ( event ) =>
									setFilters( ( prev ) => ( {
										...prev,
										[ filter.key ]: event.target.value,
									} ) )
								}
							>
								{ filter.options ? (
									Object.entries( filter.options ).map( ( [ value, label ] ) => (
										<option key={ value } value={ value }>
											{ label }
										</option>
									) )
								) : (
									<option value="">{ __( 'All', 'nvoos-pro-spa' ) }</option>
								) }
							</select>
						</label>
					) ) }
					<button type="submit" className="nvoos-pro-spa-page__retry" disabled={ loading }>
						{ loading ? __( 'Loading…', 'nvoos-pro-spa' ) : __( 'Apply', 'nvoos-pro-spa' ) }
					</button>
				</form>
			) }

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => refresh( dashboard, filters ) }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ dashboard && ! data && loading && (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'Loading dashboard data…', 'nvoos-pro-spa' ) }</p>
				</div>
			) }

			{ dashboard && data && (
				<>
					{ dashboard.metrics.length > 0 && (
						<dl className="nvoos-pro-spa-records-detail__grid">
							{ dashboard.metrics.map( ( metric ) => (
								<div key={ metric.key } className="nvoos-pro-spa-records-detail__item">
									<dt>{ metric.label }</dt>
									<dd>{ formatMetric( data[ metric.key ], metric.format ) }</dd>
								</div>
							) ) }
						</dl>
					) }

					{ ( () => {
						const charts = ( dashboard.charts ?? [] )
							.map( ( chart ) =>
								distributionChartSpec(
									chart.title,
									data[ chart.key ] as Record< string, number > | undefined,
									chart.type
								)
							)
							.filter( ( spec ) => spec !== '' );
						return charts.length > 0 ? (
							<ul className="nvoos-pro-spa-records-detail__grid nvoos-pro-spa-records-detail__grid--list">
								{ charts.map( ( spec ) => (
									<li
										key={ spec }
										className="nvoos-pro-spa-records-detail__item nvoos-pro-spa-dashboards-chart"
										// renderChartRecipe builds its HTML with textContent
										// only — values are inert by construction.
										dangerouslySetInnerHTML={ { __html: renderChartRecipe( spec ) } }
									/>
								) ) }
							</ul>
						) : null;
					} )() }

					{ ( dashboard.lists ?? [] ).map( ( list ) => {
						const rows = data[ list.key ];
						if ( ! Array.isArray( rows ) || rows.length === 0 ) {
							return null;
						}
						const tableColumns: DataTableColumn[] = list.columns.map(
							( column ) => ( {
								key: column.key,
								label: column.label,
								className: column.format
									? 'nvoos-pro-spa-tools-list__cell--num'
									: 'nvoos-pro-spa-tools-list__cell--desc',
							} )
						);
						const tableRows: DataTableRow[] = (
							rows as Record< string, unknown >[]
						).map( ( row, index ) => {
							return {
								id: String( row.id ?? index ),
								cells: list.columns.map( ( column ) =>
									column.format
										? formatMetric( row[ column.key ], column.format )
										: String( row[ column.key ] ?? '—' )
								),
							};
						} );
						return (
							<section
								key={ list.key }
								className="nvoos-pro-spa-records-detail"
								aria-label={ list.title }
							>
								<h3 className="nvoos-pro-spa-records-detail__title">{ list.title }</h3>
								<DataTable
									columns={ tableColumns }
									rows={ tableRows }
									caption={ list.title }
								/>
							</section>
						);
					} ) }
				</>
			) }
		</div>
	);
}
