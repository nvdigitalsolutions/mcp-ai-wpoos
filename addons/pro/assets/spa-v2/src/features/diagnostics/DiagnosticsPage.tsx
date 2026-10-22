/**
 * DiagnosticsPage — consolidated diagnostics overview (Proposal 084 Phase 3).
 *
 * Replaces the five wp-admin diagnostic pages (settings dashboard, MCP
 * server, provider connectivity, REST context, Pro charts) in the console;
 * the wp-admin pages stay behind their kill-switch filters until parity is
 * verified. Read-only: one GET /diagnostics/overview payload.
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useDiagnostics } from '../../hooks/useDiagnostics';
import { useBootstrap } from '../../hooks/useBootstrap';
import type {
	DiagnosticsHealth,
	DiagnosticsSystem,
	DiagnosticsSettingsDashboard,
	DiagnosticsMcpServer,
	ProviderDiagnostic,
} from '../../api/diagnostics';

function statusTone( status: string ): string {
	switch ( status ) {
		case 'ok':
		case 'healthy':
		case 'pass':
			return 'ok';
		case 'degraded':
		case 'warning':
		case 'info':
			return 'warning';
		default:
			return 'error';
	}
}

function StatusBadge( { status }: { status: string } ): JSX.Element {
	return (
		<span className={ `nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--${ statusTone( status ) }` }>
			{ status }
		</span>
	);
}

function BooleanBadge( { value }: { value: boolean } ): JSX.Element {
	return (
		<span
			className={
				value
					? 'nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--ok'
					: 'nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--error'
			}
		>
			{ value ? __( 'Yes', 'nvoos-pro-spa' ) : __( 'No', 'nvoos-pro-spa' ) }
		</span>
	);
}

function HealthCard( { health }: { health: DiagnosticsHealth } ): JSX.Element {
	const checks = health.checks ?? {};
	const entries = Object.entries( checks );

	return (
		<section className="nvoos-pro-spa-diagnostics__card">
			<h3 className="nvoos-pro-spa-diagnostics__card-title">
				{ __( 'Subsystem Health', 'nvoos-pro-spa' ) } <StatusBadge status={ health.status ?? 'unknown' } />
			</h3>
			{ entries.length === 0 ? (
				<p className="nvoos-pro-spa-diagnostics__muted">
					{ __( 'No subsystem checks reported.', 'nvoos-pro-spa' ) }
				</p>
			) : (
				<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Health checks', 'nvoos-pro-spa' ) }>
					{ entries.map( ( [ key, check ] ) => {
						const value = ( check ?? {} ) as Record< string, unknown >;
						const status = typeof value.status === 'string' ? value.status : 'unknown';
						const message = typeof value.message === 'string' ? value.message : '';
						return (
							<div key={ key } className="nvoos-pro-spa-tools-list__row" role="listitem">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ key }</strong>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<StatusBadge status={ status } />
								</div>
								<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
									{ message || '—' }
								</div>
							</div>
						);
					} ) }
				</div>
			) }
		</section>
	);
}

function SystemCard( { system }: { system: DiagnosticsSystem } ): JSX.Element {
	const rows: Array< [ string, string ] > = [
		[ __( 'WordPress', 'nvoos-pro-spa' ), system.wp_version ],
		[ __( 'PHP', 'nvoos-pro-spa' ), system.php_version ],
		[ __( 'Plugin', 'nvoos-pro-spa' ), system.plugin_version ],
		[ __( 'Memory limit', 'nvoos-pro-spa' ), system.memory_limit ],
	];

	return (
		<section className="nvoos-pro-spa-diagnostics__card">
			<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'System', 'nvoos-pro-spa' ) }</h3>
			<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'System facts', 'nvoos-pro-spa' ) }>
				{ rows.map( ( [ label, value ] ) => (
					<div key={ label } className="nvoos-pro-spa-tools-list__row" role="listitem">
						<div className="nvoos-pro-spa-tools-list__cell" role="cell">
							<strong className="nvoos-pro-spa-tools-list__name">{ label }</strong>
						</div>
						<div className="nvoos-pro-spa-tools-list__cell" role="cell">
							<code className="nvoos-pro-spa-tools-list__slug">{ value || '—' }</code>
						</div>
					</div>
				) ) }
				<div className="nvoos-pro-spa-tools-list__row" role="listitem">
					<div className="nvoos-pro-spa-tools-list__cell" role="cell">
						<strong className="nvoos-pro-spa-tools-list__name">{ __( 'Multisite', 'nvoos-pro-spa' ) }</strong>
					</div>
					<div className="nvoos-pro-spa-tools-list__cell" role="cell">
						<BooleanBadge value={ system.is_multisite } />
					</div>
				</div>
			</div>
		</section>
	);
}

function McpServerCard( { mcp }: { mcp: DiagnosticsMcpServer } ): JSX.Element {
	const rows: Array< [ string, boolean ] > = [
		[ __( 'Auth0 configured', 'nvoos-pro-spa' ), mcp.auth0_configured ],
		[ __( 'Federation enabled', 'nvoos-pro-spa' ), mcp.federation_enabled ],
		[ __( 'Federation directory enabled', 'nvoos-pro-spa' ), mcp.federation_directory_enabled ],
		[ __( 'Mesh enabled', 'nvoos-pro-spa' ), mcp.mesh_enabled ],
		[ __( 'MCP REST routes present', 'nvoos-pro-spa' ), mcp.mcp_routes_present ],
	];

	return (
		<section className="nvoos-pro-spa-diagnostics__card">
			<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'MCP Server', 'nvoos-pro-spa' ) }</h3>
			<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'MCP server facts', 'nvoos-pro-spa' ) }>
				{ rows.map( ( [ label, value ] ) => (
					<div key={ label } className="nvoos-pro-spa-tools-list__row" role="listitem">
						<div className="nvoos-pro-spa-tools-list__cell" role="cell">
							<strong className="nvoos-pro-spa-tools-list__name">{ label }</strong>
						</div>
						<div className="nvoos-pro-spa-tools-list__cell" role="cell">
							<BooleanBadge value={ value } />
						</div>
					</div>
				) ) }
				<div className="nvoos-pro-spa-tools-list__row" role="listitem">
					<div className="nvoos-pro-spa-tools-list__cell" role="cell">
						<strong className="nvoos-pro-spa-tools-list__name">{ __( 'Registered tools', 'nvoos-pro-spa' ) }</strong>
					</div>
					<div className="nvoos-pro-spa-tools-list__cell" role="cell">
						<code className="nvoos-pro-spa-tools-list__slug">
							{ mcp.tool_count >= 0 ? mcp.tool_count : '—' }
						</code>
					</div>
				</div>
			</div>
		</section>
	);
}

function ProvidersCard( { providers }: { providers: ProviderDiagnostic[] } ): JSX.Element {
	return (
		<section className="nvoos-pro-spa-diagnostics__card">
			<h3 className="nvoos-pro-spa-diagnostics__card-title">
				{ __( 'Providers', 'nvoos-pro-spa' ) } ({ providers.length })
			</h3>
			{ providers.length === 0 ? (
				<p className="nvoos-pro-spa-diagnostics__muted">
					{ __( 'No enabled providers reported.', 'nvoos-pro-spa' ) }
				</p>
			) : (
				<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Provider status', 'nvoos-pro-spa' ) }>
					{ providers.map( ( provider ) => (
						<div key={ provider.slug } className="nvoos-pro-spa-tools-list__row" role="listitem">
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								<strong className="nvoos-pro-spa-tools-list__name">{ provider.label }</strong>
								<code className="nvoos-pro-spa-tools-list__slug">{ provider.slug }</code>
							</div>
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								<BooleanBadge value={ provider.has_credentials } />
							</div>
							<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
								{ provider.key_source }
							</div>
						</div>
					) ) }
				</div>
			) }
		</section>
	);
}

function SettingsDashboardCard( { state }: { state: DiagnosticsSettingsDashboard } ): JSX.Element {
	const failing = Object.keys( state.classes ).filter( ( slug ) => ! state.classes[ slug ] );

	return (
		<section className="nvoos-pro-spa-diagnostics__card">
			<h3 className="nvoos-pro-spa-diagnostics__card-title">
				{ __( 'Settings Dashboard', 'nvoos-pro-spa' ) }
			</h3>
			<p className="nvoos-pro-spa-diagnostics__muted">
				{ __( 'Registered sections:', 'nvoos-pro-spa' ) } <strong>{ state.sections_total }</strong>
			</p>
			{ failing.length > 0 ? (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>
						{ __( 'Missing classes:', 'nvoos-pro-spa' ) } <code>{ failing.join( ', ' ) }</code>
					</p>
				</div>
			) : (
				<p className="nvoos-pro-spa-diagnostics__muted">
					{ __( 'All required settings classes are present.', 'nvoos-pro-spa' ) }
				</p>
			) }
		</section>
	);
}

export function DiagnosticsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { overview, loading, error, refresh } = useDiagnostics(
		runtime
			? { endpoint: runtime.endpoints.diagnostics ?? '', nonce: runtime.nonce }
			: { endpoint: '', nonce: '' }
	);

	const [ lastRefresh, setLastRefresh ] = useState< number | null >( null );

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Diagnostics', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Collecting diagnostics…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Diagnostics', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	if ( ! overview ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Diagnostics', 'nvoos-pro-spa' ) }</h2>
				{ error && <p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">{ error }</p> }
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Diagnostics', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Consolidated health, system, provider, and settings-dashboard state.', 'nvoos-pro-spa' ) }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						setLastRefresh( overview.generated_at );
						void refresh();
					} }
				>
					{ __( 'Refresh', 'nvoos-pro-spa' ) }
				</button>
				{ lastRefresh !== null && (
					<span className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'Last refresh at', 'nvoos-pro-spa' ) }{ ' ' }
						{ new Date( lastRefresh * 1000 ).toLocaleTimeString() }
					</span>
				) }
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
				</div>
			) }

			<div className="nvoos-pro-spa-diagnostics__grid">
				<HealthCard health={ overview.health } />
				<SystemCard system={ overview.system } />
				<McpServerCard mcp={ overview.mcp_server } />
				<ProvidersCard providers={ overview.providers } />
				<SettingsDashboardCard state={ overview.settings_dashboard } />
			</div>

			<section className="nvoos-pro-spa-diagnostics__card nvoos-pro-spa-diagnostics__card--standalone">
				<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'REST Context', 'nvoos-pro-spa' ) }</h3>
				{ Object.keys( overview.rest_context ).length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'REST context diagnostics not available.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'REST context facts', 'nvoos-pro-spa' ) }>
						{ Object.entries( overview.rest_context ).map( ( [ key, value ] ) => (
							<div key={ key } className="nvoos-pro-spa-tools-list__row" role="listitem">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ key }</strong>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
									{ typeof value === 'boolean' ? (
										<BooleanBadge value={ value } />
									) : Array.isArray( value ) ? (
										value.join( ', ' )
									) : (
										String( value )
									) }
								</div>
							</div>
						) ) }
					</div>
				) }
			</section>

			<section className="nvoos-pro-spa-diagnostics__card nvoos-pro-spa-diagnostics__card--standalone">
				<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Pro Dashboard', 'nvoos-pro-spa' ) }</h3>
				{ Object.keys( overview.pro_dashboard ).length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'Pro dashboard chart diagnostics not available.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'Overall status:', 'nvoos-pro-spa' ) }{ ' ' }
						{ typeof overview.pro_dashboard.overall_status === 'string' ? (
							<StatusBadge status={ overview.pro_dashboard.overall_status } />
						) : (
							'—'
						) }
					</p>
				) }
			</section>
		</div>
	);
}
