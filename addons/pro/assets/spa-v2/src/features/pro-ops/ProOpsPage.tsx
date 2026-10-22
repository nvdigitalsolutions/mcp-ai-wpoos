/**
 * ProOpsPage — Pro ops overview (Proposal 084 Phase 3, gap P11).
 *
 * Groups the four wp-admin Pro ops surfaces (Status Page, Maintenance,
 * Incidents, Webhook Status) behind one read-only overview. Live webhook
 * set/delete and maintenance/incident creation stay wp-admin.
 */

import { useMemo, type JSX, type ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { useProOps } from '../../hooks/useProOps';
import { useBootstrap } from '../../hooks/useBootstrap';
import type { ProOpsComponent, ProOpsOverview } from '../../api/pro-ops';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';

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

	return <ProOpsTables overview={ overview } error={ error } onRefresh={ refresh } />;
}

interface ProOpsTablesProps {
	overview: ProOpsOverview;
	error: string | null;
	onRefresh: () => Promise< void >;
}

/**
 * Render-phase split: the tables component only mounts once the overview is
 * guaranteed non-null, so its memo hooks can never be skipped by the
 * parent's loading/error early returns (rules-of-hooks — React error #310).
 */
function ProOpsTables( { overview, error, onRefresh }: ProOpsTablesProps ): JSX.Element {
	const { status, incidents, maintenance, webhooks } = overview;

	const componentColumns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'component',
				label: __( 'Component', 'nvoos-pro-spa' ),
				width: 'minmax(240px, 2fr)',
			},
			{ key: 'status', label: __( 'Status', 'nvoos-pro-spa' ), width: '140px' },
		],
		[]
	);

	const componentRows = useMemo< DataTableRow[] >(
		() =>
			status.components.map( ( component: ProOpsComponent ) => ( {
				id: component.slug,
				cells: [
					<>
						<strong className="nvoos-pro-spa-tools-list__name">{ component.name }</strong>
						<code className="nvoos-pro-spa-tools-list__slug">
							{ component.group }
							{ component.public ? ` · ${ __( 'public', 'nvoos-pro-spa' ) }` : '' }
						</code>
					</>,
					<Badge
						label={ typeof component.status === 'string' ? component.status : 'unknown' }
					/>,
				],
			} ) ),
		[ status.components ]
	);

	const maintenanceColumns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'window',
				label: __( 'Window', 'nvoos-pro-spa' ),
				width: 'minmax(200px, 1fr)',
			},
			{ key: 'status', label: __( 'Status', 'nvoos-pro-spa' ), width: '130px' },
			{
				key: 'schedule',
				label: __( 'Schedule', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(220px, 1.5fr)',
			},
		],
		[]
	);

	const maintenanceRows = useMemo< DataTableRow[] >(
		() =>
			maintenance.map( ( window ) => ( {
				id: window.id,
				cells: [
					<strong className="nvoos-pro-spa-tools-list__name">{ window.title }</strong>,
					<Badge label={ window.status } />,
					<>
						{ window.start || '—' } → { window.end || '—' }
						{ window.banner_enabled && (
							<>
								<br />
								{ __( 'Banner enabled', 'nvoos-pro-spa' ) }
							</>
						) }
					</>,
				],
			} ) ),
		[ maintenance ]
	);

	const incidentColumns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'incident',
				label: __( 'Incident', 'nvoos-pro-spa' ),
				width: 'minmax(200px, 1fr)',
			},
			{ key: 'phase', label: __( 'Phase', 'nvoos-pro-spa' ), width: '130px' },
			{
				key: 'services',
				label: __( 'Affected services', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(220px, 1.5fr)',
			},
		],
		[]
	);

	const incidentRows = useMemo< DataTableRow[] >(
		() =>
			incidents.map( ( incident ) => ( {
				id: incident.id,
				cells: [
					<>
						<strong className="nvoos-pro-spa-tools-list__name">{ incident.title }</strong>
						<code className="nvoos-pro-spa-tools-list__slug">{ incident.severity || '—' }</code>
					</>,
					<Badge label={ incident.phase || 'unknown' } />,
					<>
						{ incident.services.length > 0 ? incident.services.join( ', ' ) : '—' }
						{ incident.date && (
							<>
								<br />
								{ incident.date }
							</>
						) }
					</>,
				],
			} ) ),
		[ incidents ]
	);

	const webhookColumns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'connection',
				label: __( 'Connection', 'nvoos-pro-spa' ),
				width: 'minmax(200px, 1fr)',
			},
			{ key: 'webhook', label: __( 'Webhook', 'nvoos-pro-spa' ), width: '130px' },
			{
				key: 'url',
				label: __( 'Expected URL', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(220px, 1.5fr)',
			},
		],
		[]
	);

	const webhookRows = useMemo< DataTableRow[] >(
		() =>
			webhooks.map( ( webhook ) => ( {
				id: webhook.id,
				cells: [
					<>
						<strong className="nvoos-pro-spa-tools-list__name">
							{ webhook.label || webhook.id }
						</strong>
						<code className="nvoos-pro-spa-tools-list__slug">{ webhook.type }</code>
					</>,
					<Badge label={ webhook.webhook_set ? 'set' : 'unset' } />,
					webhook.expected_url || '—',
				],
			} ) ),
		[ webhooks ]
	);

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
						void onRefresh();
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
					<DataTable
						columns={ componentColumns }
						rows={ componentRows }
						caption={ __( 'Service components', 'nvoos-pro-spa' ) }
					/>
				) }
			</Card>

			<Card title={ __( 'Maintenance Windows', 'nvoos-pro-spa' ) }>
				{ maintenance.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No maintenance windows scheduled.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<DataTable
						columns={ maintenanceColumns }
						rows={ maintenanceRows }
						caption={ __( 'Maintenance windows', 'nvoos-pro-spa' ) }
					/>
				) }
			</Card>

			<Card title={ __( 'Incidents', 'nvoos-pro-spa' ) }>
				{ incidents.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No incidents recorded.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<DataTable
						columns={ incidentColumns }
						rows={ incidentRows }
						caption={ __( 'Incidents', 'nvoos-pro-spa' ) }
					/>
				) }
			</Card>

			<Card title={ __( 'Webhook Connections', 'nvoos-pro-spa' ) }>
				{ webhooks.length === 0 ? (
					<p className="nvoos-pro-spa-diagnostics__muted">
						{ __( 'No webhook-capable connections configured.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<DataTable
						columns={ webhookColumns }
						rows={ webhookRows }
						caption={ __( 'Webhook connections', 'nvoos-pro-spa' ) }
					/>
				) }
			</Card>
		</div>
	);
}
