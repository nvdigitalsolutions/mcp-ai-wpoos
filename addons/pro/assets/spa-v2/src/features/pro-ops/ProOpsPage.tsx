/**
 * ProOpsPage — Pro ops overview (Proposal 084 Phase 3, gap P11).
 *
 * Groups the four wp-admin Pro ops surfaces (Status Page, Maintenance,
 * Incidents, Webhook Status) behind one read-only overview. Live webhook
 * set/delete and maintenance/incident creation stay wp-admin.
 */

import { type JSX, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { useProOps } from '../../hooks/useProOps';
import { useBootstrap } from '../../hooks/useBootstrap';
import type { ProOpsComponent } from '../../api/pro-ops';

function toneFor( status: string ): 'ok' | 'warning' | 'error' {
	switch ( status ) {
		case 'ok':
		case 'operational':
		case 'healthy':
		case 'pass':
		case 'scheduled':
		case 'completed':
		case 'resolved':
		case 'monitoring':
			return 'ok';
		case 'degraded':
		case 'warning':
		case 'in_progress':
		case 'investigating':
		case 'identified':
		case 'pending':
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

function Card( { title, children }: { title: string; children: ReactNode } ): JSX.Element {
	return (
		<section className="nvoos-pro-spa-diagnostics__card nvoos-pro-spa-diagnostics__card--standalone">
			<h3 className="nvoos-pro-spa-diagnostics__card-title">{ title }</h3>
			{ children }
		</section>
	);
}

export function ProOpsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { overview, loading, error, refresh } = useProOps(
		runtime
			? { endpoint: runtime.endpoints.proOps ?? '', nonce: runtime.nonce }
			: { endpoint: '', nonce: '' }
	);

	if ( bootstrapLoading || loading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Pro Ops', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Collecting ops overview…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime || ! overview ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Pro Ops', 'nvoos-pro-spa' ) }</h2>
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

	const { status, incidents, maintenance, webhooks } = overview;

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Pro Ops', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Service status, maintenance windows, incidents, and webhook connections.', 'nvoos-pro-spa' ) }
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

			<Card title={ __( 'Service Status', 'nvoos-pro-spa' ) }>
				{ status.overall && (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'Overall:', 'nvoos-pro-spa' ) } <Badge label={ status.overall } />
					</p>
				) }
				{ status.components.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No service components reported.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Service components', 'nvoos-pro-spa' ) }>
						{ status.components.map( ( component: ProOpsComponent ) => (
							<div key={ component.slug } className="nvoos-pro-spa-tools-list__row" role="listitem">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ component.name }</strong>
									<code className="nvoos-pro-spa-tools-list__slug">
										{ component.group }
										{ component.public ? ` · ${ __( 'public', 'nvoos-pro-spa' ) }` : '' }
									</code>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<Badge label={ typeof component.status === 'string' ? component.status : 'unknown' } />
								</div>
							</div>
						) ) }
					</div>
				) }
			</Card>

			<Card title={ __( 'Maintenance Windows', 'nvoos-pro-spa' ) }>
				{ maintenance.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No maintenance windows scheduled.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Maintenance windows', 'nvoos-pro-spa' ) }>
						{ maintenance.map( ( window ) => (
							<div key={ window.id } className="nvoos-pro-spa-tools-list__row" role="listitem">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ window.title }</strong>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<Badge label={ window.status } />
								</div>
								<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
									{ window.start || '—' } → { window.end || '—' }
									{ window.banner_enabled && (
										<>
											<br />
											{ __( 'Banner enabled', 'nvoos-pro-spa' ) }
										</>
									) }
								</div>
							</div>
						) ) }
					</div>
				) }
			</Card>

			<Card title={ __( 'Incidents', 'nvoos-pro-spa' ) }>
				{ incidents.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No incidents recorded.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Incidents', 'nvoos-pro-spa' ) }>
						{ incidents.map( ( incident ) => (
							<div key={ incident.id } className="nvoos-pro-spa-tools-list__row" role="listitem">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ incident.title }</strong>
									<code className="nvoos-pro-spa-tools-list__slug">{ incident.severity || '—' }</code>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<Badge label={ incident.phase || 'unknown' } />
								</div>
								<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
									{ incident.services.length > 0 ? incident.services.join( ', ' ) : '—' }
									{ incident.date && (
										<>
											<br />
											{ incident.date }
										</>
									) }
								</div>
							</div>
						) ) }
					</div>
				) }
			</Card>

			<Card title={ __( 'Webhook Connections', 'nvoos-pro-spa' ) }>
				{ webhooks.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No webhook-capable connections configured.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ __( 'Webhook connections', 'nvoos-pro-spa' ) }>
						{ webhooks.map( ( webhook ) => (
							<div key={ webhook.id } className="nvoos-pro-spa-tools-list__row" role="listitem">
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<strong className="nvoos-pro-spa-tools-list__name">{ webhook.label || webhook.id }</strong>
									<code className="nvoos-pro-spa-tools-list__slug">{ webhook.type }</code>
								</div>
								<div className="nvoos-pro-spa-tools-list__cell" role="cell">
									<Badge label={ webhook.webhook_set ? 'set' : 'unset' } />
								</div>
								<div className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc" role="cell">
									{ webhook.expected_url || '—' }
								</div>
							</div>
						) ) }
					</div>
				) }
			</Card>
		</div>
	);
}
