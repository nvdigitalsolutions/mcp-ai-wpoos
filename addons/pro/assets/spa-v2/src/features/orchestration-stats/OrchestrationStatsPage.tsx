/**
 * OrchestrationStatsPage — multi-agent + orchestration statistics
 * (Proposal 084 Phase 3).
 *
 * Replaces the two wp-admin AJAX stats dashboards (Multi-Agent System,
 * Orchestration Dashboard) in the console with one read-only overview:
 * agent inventory, workflow patterns, profession/role seeding, subsystem
 * status, and recent workflows. Read-only — destructive actions (agent
 * re-install, workflow execute/restart, seeders) stay wp-admin.
 */

import { type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useOrchestrationStats } from '../../hooks/useOrchestrationStats';
import { useBootstrap } from '../../hooks/useBootstrap';
import type {
	OrchestrationAgent,
	OrchestrationWorkflow,
} from '../../api/orchestration-stats';

function toneFor( status: string ): 'ok' | 'warning' | 'error' {
	switch ( status ) {
		case 'ok':
		case 'healthy':
		case 'pass':
		case 'publish':
		case 'active':
		case 'completed':
		case 'running':
			return 'ok';
		case 'degraded':
		case 'warning':
		case 'pending':
		case 'unknown':
			return 'warning';
		default:
			return 'error';
	}
}

function Badge( { label }: { label: string } ): JSX.Element {
	return (
		<span className={ `nvoos-pro-spa-diagnostics__badge nvoos-pro-spa-diagnostics__badge--${ toneFor( label ) }` }>
			{ label }
		</span>
	);
}

function StatCards( { items }: { items: Array< [ string, string | number ] > } ): JSX.Element {
	return (
		<div className="nvoos-pro-spa-token-manager__stats" role="list" aria-label={ __( 'Statistics', 'nvoos-pro-spa' ) }>
			{ items.map( ( [ label, value ] ) => (
				<div key={ label } className="nvoos-pro-spa-token-manager__stat" role="listitem">
					<div className="nvoos-pro-spa-token-manager__stat-label">{ label }</div>
					<div className="nvoos-pro-spa-token-manager__stat-value">{ value }</div>
				</div>
			) ) }
		</div>
	);
}

export function OrchestrationStatsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { overview, loading, error, refresh } = useOrchestrationStats(
		runtime
			? { endpoint: runtime.endpoints.orchestrationStats ?? '', nonce: runtime.nonce }
			: { endpoint: '', nonce: '' }
	);

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Orchestration', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Collecting orchestration stats…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime || ! overview ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Orchestration', 'nvoos-pro-spa' ) }</h2>
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

	const { multi_agent: multiAgent, orchestration, recent_workflows: workflows } = overview;
	const system = orchestration.system_status;
	const health = system?.health as Record< string, unknown > | undefined;
	const asyncStatus = system?.async as Record< string, unknown > | undefined;
	const cron = system?.cron as Record< string, unknown > | undefined;
	const sse = system?.sse as Record< string, unknown > | undefined;

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Orchestration', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Agent inventory, profession seeding, subsystem status, and recent workflows.', 'nvoos-pro-spa' ) }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					onClick={ () => {
						void refresh();
					} }
				>
					{ __( 'Refresh', 'nvoos-pro-spa' ) }
				</button>
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
				</div>
			) }

			<section className="nvoos-pro-spa-diagnostics__card">
				<h3 className="nvoos-pro-spa-diagnostics__card-title">
					{ __( 'Multi-Agent System', 'nvoos-pro-spa' ) }
					{ multiAgent.is_pro_active && <Badge label="pro" /> }
				</h3>
				{ ! multiAgent.installed ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'Default agents are not installed on this site.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<>
						<StatCards
							items={ [
								[ __( 'Agents', 'nvoos-pro-spa' ), multiAgent.total_agents ],
								[ __( 'Active', 'nvoos-pro-spa' ), multiAgent.active_agents ],
								[ __( 'Tools wired', 'nvoos-pro-spa' ), multiAgent.total_tools ],
							] }
						/>
						{ multiAgent.agents.length === 0 ? (
							<p className="nvoos-pro-spa-diagnostics__muted">
								{ __( 'No agents reported.', 'nvoos-pro-spa' ) }
							</p>
						) : (
							<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Agents', 'nvoos-pro-spa' ) }>
								{ multiAgent.agents.map( ( agent: OrchestrationAgent ) => (
									<div key={ agent.id || agent.slug } className="nvoos-pro-spa-tools-list__row" role="listitem">
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											<strong className="nvoos-pro-spa-tools-list__name">{ agent.title }</strong>
											<code className="nvoos-pro-spa-tools-list__slug">
												{ agent.provider || '—' } { agent.model ? `· ${ agent.model }` : '' }
											</code>
										</div>
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											<Badge label={ agent.status } />
										</div>
										<div className="nvoos-pro-spa-tools-list__cell" role="cell">
											<code className="nvoos-pro-spa-tools-list__slug">
												{ agent.workflow_pattern || '—' }
											</code>
										</div>
										<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
											{ __( 'Tools', 'nvoos-pro-spa' ) }: { agent.tool_count }
											{ agent.last_used && (
												<>
													<br />
													{ __( 'Last used', 'nvoos-pro-spa' ) }: { agent.last_used }
												</>
											) }
										</div>
									</div>
								) ) }
							</div>
						) }
					</>
				) }
			</section>

			<section className="nvoos-pro-spa-diagnostics__card nvoos-pro-spa-diagnostics__card--standalone">
				<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Profession Seeding', 'nvoos-pro-spa' ) }</h3>
				<StatCards
					items={ [
						[ __( 'Professions', 'nvoos-pro-spa' ), orchestration.total_professions ],
						[ __( 'Seeded', 'nvoos-pro-spa' ), orchestration.seeded_professions ],
						[ __( 'With task patterns', 'nvoos-pro-spa' ), orchestration.with_task_patterns ],
						[ __( 'Seeder version', 'nvoos-pro-spa' ), orchestration.seeder_version ],
					] }
				/>
				<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Role distribution', 'nvoos-pro-spa' ) }>
					{ Object.entries( orchestration.roles ).map( ( [ role, count ] ) => (
						<div key={ role } className="nvoos-pro-spa-tools-list__row" role="listitem">
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								<strong className="nvoos-pro-spa-tools-list__name">{ role }</strong>
							</div>
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">{ count }</div>
						</div>
					) ) }
				</div>
			</section>

			<section className="nvoos-pro-spa-diagnostics__card nvoos-pro-spa-diagnostics__card--standalone">
				<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'System Status', 'nvoos-pro-spa' ) }</h3>
				<div className="nvoos-pro-spa-token-manager__stats" role="list" aria-label={ __( 'Subsystem status', 'nvoos-pro-spa' ) }>
					{ [
						[
							__( 'Cron', 'nvoos-pro-spa' ),
							typeof cron?.total === 'number' ? String( cron.total ) : '—',
						],
						[
							__( 'Async', 'nvoos-pro-spa' ),
							typeof asyncStatus?.status === 'string' ? asyncStatus.status : '—',
						],
						[
							__( 'Orchestration health', 'nvoos-pro-spa' ),
							typeof health?.status === 'string' ? health.status : '—',
						],
						[
							__( 'SSE', 'nvoos-pro-spa' ),
							typeof sse?.available === 'boolean'
								? sse.available
									? __( 'Available', 'nvoos-pro-spa' )
									: __( 'Unavailable', 'nvoos-pro-spa' )
								: '—',
						],
					].map( ( [ label, value ] ) => (
						<div key={ label } className="nvoos-pro-spa-token-manager__stat" role="listitem">
							<div className="nvoos-pro-spa-token-manager__stat-label">{ label }</div>
							<div className="nvoos-pro-spa-token-manager__stat-value">{ value }</div>
						</div>
					) ) }
				</div>
			</section>

			<section className="nvoos-pro-spa-diagnostics__card nvoos-pro-spa-diagnostics__card--standalone">
				<h3 className="nvoos-pro-spa-diagnostics__card-title">{ __( 'Recent Workflows', 'nvoos-pro-spa' ) }</h3>
				{ workflows.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No recent workflows.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Recent workflows', 'nvoos-pro-spa' ) }>
						{ workflows.map( ( workflow: OrchestrationWorkflow ) => (
							<div key={ workflow.workflow_id } className="nvoos-pro-spa-tools-list__row" role="listitem">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ workflow.workflow_id }</strong>
									<code className="nvoos-pro-spa-tools-list__slug">{ workflow.task_type }</code>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<Badge label={ workflow.state } />
								</div>
								<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
									{ __( 'Tasks', 'nvoos-pro-spa' ) }: { workflow.tasks_done }/{ workflow.tasks_total }
									{ workflow.created_at && (
										<>
											<br />
											{ workflow.created_at }
										</>
									) }
								</div>
							</div>
						) ) }
					</div>
				) }
			</section>
		</div>
	);
}
