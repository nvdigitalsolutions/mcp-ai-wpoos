/**
 * DashboardsPage — the generic repeating-dashboard surface (Proposal 084
 * Phase 4, W13 CRE portfolio pilot).
 *
 * One page parameterized by server-driven dashboard configs
 * (GET /mcp-ai-pro/v1/dashboards/schema): dashboard picker, schema-driven
 * filter form, metric cards, and the loan summary table. W13a ships the
 * CRE Debt Portfolio; W13c adds the remaining repeating dashboards.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	DashboardsClient,
	formatMetric,
	type DashboardConfig,
	type DashboardData,
	type DashboardLoanRow,
} from '../../api/dashboards';

function useDashboards( schemaEndpoint: string, nonce: string ): {
	dashboards: DashboardConfig[];
	schemaError: string | null;
	client: DashboardsClient;
} {
	const [ dashboards, setDashboards ] = useState< DashboardConfig[] >( [] );
	const [ schemaError, setSchemaError ] = useState< string | null >( null );

	const client = useMemo(
		() => new DashboardsClient( { schemaEndpoint, nonce } ),
		[ schemaEndpoint, nonce ]
	);

	useEffect( () => {
		if ( ! schemaEndpoint ) {
			return;
		}
		let cancelled = false;
		const controller = new AbortController();
		client
			.schema( controller.signal )
			.then( ( list ) => {
				if ( cancelled ) {
					return;
				}
				setDashboards( list );
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
			} );
		return () => {
			cancelled = true;
			controller.abort();
		};
	}, [ client, schemaEndpoint ] );

	return { dashboards, schemaError, client };
}

export function DashboardsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { dashboards, schemaError, client } = useDashboards(
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

	if ( schemaError ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Dashboards', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ schemaError }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Dashboards', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Filtered metric dashboards across the toolkits.', 'nvoos-pro-spa' ) }
				</p>
			</header>

			{ dashboards.length > 1 && (
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

			{ dashboard && data && (
				<>
					<div className="nvoos-pro-spa-records-detail__grid" role="list">
						{ dashboard.metrics.map( ( metric ) => (
							<div key={ metric.key } className="nvoos-pro-spa-records-detail__item" role="listitem">
								<dt>{ metric.label }</dt>
								<dd>{ formatMetric( data[ metric.key ], metric.format ) }</dd>
							</div>
						) ) }
					</div>

					{ Array.isArray( data.loan_list ) && data.loan_list.length > 0 && (
						<section className="nvoos-pro-spa-records-detail" aria-label={ __( 'Loan summary', 'nvoos-pro-spa' ) }>
							<h3 className="nvoos-pro-spa-records-detail__title">
								{ __( 'Loan summary', 'nvoos-pro-spa' ) }
							</h3>
							<div className="nvoos-pro-spa-tools-list" role="table" aria-label={ __( 'Loan summary', 'nvoos-pro-spa' ) }>
								{ ( data.loan_list as DashboardLoanRow[] ).map( ( row ) => (
									<div key={ row.id } className="nvoos-pro-spa-tools-list__row" role="row">
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											{ row.title }
										</div>
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											{ formatMetric( row.balance, 'money' ) }
										</div>
										<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
											{ row.type } · { row.status }
										</div>
									</div>
								) ) }
							</div>
						</section>
					) }
				</>
			) }
		</div>
	);
}
