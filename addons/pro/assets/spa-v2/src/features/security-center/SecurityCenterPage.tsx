/**
 * SecurityCenterPage — security posture overview.
 *
 * Shows the posture report as a score gauge + pass/fail check tables, the
 * security-header preview as a key/value table, saved snapshots and the
 * action runners (snapshot, restore, self-test). Replaces the wp-admin
 * Security Audit surface in the console (Proposal 084 Phase 1); the wp-admin
 * page stays behind the wp_mcp_ai_admin_page_enabled_security_center filter
 * until parity is verified.
 *
 * Everything renders as structured UI — no raw JSON dumps (the early port
 * rendered JSON.stringify() into <pre> blocks).
 */

import { useMemo, useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useSecurityCenter } from '../../hooks/useSecurityCenter';
import { useBootstrap } from '../../hooks/useBootstrap';
import { renderChartRecipe } from '../../components/shared/chartRecipes';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';
import {
	formatTimestamp,
	gradeTone,
	groupSignals,
	parsePosture,
	summarizeAction,
	type PostureSignal,
} from './posture-utils';

function PassBadge( { passed }: { passed: boolean } ): JSX.Element {
	return (
		<span
			className={
				'nvoos-pro-spa-security__badge ' +
				( passed
					? 'nvoos-pro-spa-security__badge--ok'
					: 'nvoos-pro-spa-security__badge--error' )
			}
		>
			{ passed ? __( 'Pass', 'nvoos-pro-spa' ) : __( 'Fail', 'nvoos-pro-spa' ) }
		</span>
	);
}

function GradeBadge( { grade }: { grade: string } ): JSX.Element {
	if ( ! grade ) {
		return <></>;
	}
	return (
		<span
			className={
				'nvoos-pro-spa-security__badge nvoos-pro-spa-security__badge--' + gradeTone( grade )
			}
		>
			{ grade }
		</span>
	);
}

function OnOffBadge( { on, labelOn, labelOff }: { on: boolean; labelOn: string; labelOff: string } ): JSX.Element {
	return (
		<span
			className={
				'nvoos-pro-spa-security__badge ' +
				( on
					? 'nvoos-pro-spa-security__badge--ok'
					: 'nvoos-pro-spa-security__badge--error' )
			}
		>
			{ on ? labelOn : labelOff }
		</span>
	);
}

/** The nvoos-chart dial spec for the score gauge (textContent-built HTML). */
function scoreDialSpec( score: number ): string {
	return JSON.stringify( {
		type: 'dial',
		title: __( 'Security posture score', 'nvoos-pro-spa' ),
		values: [ score ],
		max: 100,
		table: false,
	} );
}

const SIGNAL_COLUMNS: DataTableColumn[] = [
	{ key: 'status', label: __( 'Status', 'nvoos-pro-spa' ), width: '96px' },
	{ key: 'check', label: __( 'Check', 'nvoos-pro-spa' ), width: 'minmax(220px, 1.6fr)' },
	{ key: 'weight', label: __( 'Weight', 'nvoos-pro-spa' ), width: '84px' },
	{
		key: 'guidance',
		label: __( 'Guidance', 'nvoos-pro-spa' ),
		className: 'nvoos-pro-spa-tools-list__cell--desc',
		width: 'minmax(240px, 2fr)',
	},
];

function signalRows( signals: PostureSignal[] ): DataTableRow[] {
	return signals.map( ( signal ) => ( {
		id: signal.id,
		cells: [
			<PassBadge passed={ signal.passed } />,
			<strong className="nvoos-pro-spa-tools-list__name">{ signal.label }</strong>,
			<code className="nvoos-pro-spa-tools-list__slug">{ signal.weight }</code>,
			signal.detail || '—',
		],
	} ) );
}

export function SecurityCenterPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const {
		posture: rawPosture,
		headers,
		snapshots,
		loading,
		busy,
		error,
		lastAction,
		refresh,
		saveSnapshot,
		restoreSnapshot,
		runSelfTest,
	} = useSecurityCenter(
		runtime
			? { endpoint: runtime.endpoints.security ?? '', nonce: runtime.nonce }
			: { endpoint: '', nonce: '' }
	);

	const [ saveBusy, setSaveBusy ] = useState< boolean >( false );
	const [ testBusy, setTestBusy ] = useState< boolean >( false );

	const posture = useMemo( () => parsePosture( rawPosture ), [ rawPosture ] );
	const groups = useMemo( () => groupSignals( posture?.signals ?? [] ), [ posture ] );
	const passedCount = useMemo(
		() => ( posture?.signals ?? [] ).filter( ( s ) => s.passed ).length,
		[ posture ]
	);
	const actionSummary = useMemo( () => summarizeAction( lastAction ), [ lastAction ] );
	const dialSpec = useMemo( () => scoreDialSpec( posture?.score ?? 0 ), [ posture ] );

	const snapshotColumns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'snapshot',
				label: __( 'Snapshot', 'nvoos-pro-spa' ),
				width: 'minmax(240px, 2fr)',
			},
			{ key: 'created', label: __( 'Created', 'nvoos-pro-spa' ), width: '190px' },
			{ key: 'actions', label: __( 'Actions', 'nvoos-pro-spa' ), width: '140px' },
		],
		[]
	);

	const snapshotRows = useMemo< DataTableRow[] >(
		() =>
			snapshots.map( ( snapshot ) => {
				const key = String( snapshot.snapshot_id ?? snapshot.id ?? '' );
				const created = formatTimestamp(
					typeof snapshot.created_at === 'string' ? snapshot.created_at : ''
				);
				return {
					id: key,
					cells: [
						<strong className="nvoos-pro-spa-tools-list__name">
							{ String( snapshot.label ?? key ) }
						</strong>,
						created || '—',
						<button
							type="button"
							className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
							disabled={ busy }
							onClick={ () => void restoreSnapshot( key ) }
						>
							{ __( 'Restore', 'nvoos-pro-spa' ) }
						</button>,
					],
				};
			} ),
		[ snapshots, busy, restoreSnapshot ]
	);

	const headerRows = useMemo< DataTableRow[] >(
		() =>
			Object.entries( headers?.headers ?? {} ).map( ( [ name, value ] ) => ( {
				id: name,
				cells: [
					<strong className="nvoos-pro-spa-tools-list__name">{ name }</strong>,
					<code className="nvoos-pro-spa-tools-list__slug">{ value }</code>,
				],
			} ) ),
		[ headers ]
	);

	if ( bootstrapLoading || ( loading && ! rawPosture ) ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Security Center', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading security posture…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Security Center', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Security Center', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Security posture, emitted headers, and settings snapshots.',
						'nvoos-pro-spa'
					) }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					disabled={ loading || busy }
					onClick={ () => void refresh() }
				>
					{ loading ? __( 'Refreshing…', 'nvoos-pro-spa' ) : __( 'Refresh', 'nvoos-pro-spa' ) }
				</button>
			</header>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => void refresh() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ posture && (
				<section
					className="nvoos-pro-spa-security__score-card"
					aria-label={ __( 'Security posture score', 'nvoos-pro-spa' ) }
				>
					<div
						className="nvoos-pro-spa-security__score-gauge"
						// renderChartRecipe builds its HTML with textContent
						// only — values are inert by construction.
						dangerouslySetInnerHTML={ { __html: renderChartRecipe( dialSpec ) } }
					/>
					<div className="nvoos-pro-spa-security__score-body">
						<div className="nvoos-pro-spa-security__score-line">
							<span className="nvoos-pro-spa-security__score-number">
								{ posture.score }
							</span>
							<GradeBadge grade={ posture.grade } />
						</div>
						<p className="nvoos-pro-spa-security__score-meta">
							{ sprintf(
								/* translators: 1: passing count, 2: total count. */
								__( '%1$d of %2$d signals passing', 'nvoos-pro-spa' ),
								passedCount,
								posture.signals.length
							) }
							{ posture.computedAt &&
								` · ${ __( 'computed', 'nvoos-pro-spa' ) } ${ formatTimestamp( posture.computedAt ) }` }
						</p>
					</div>
				</section>
			) }

			{ posture && posture.quickWins.length > 0 && (
				<section
					className="nvoos-pro-spa-security__card"
					aria-label={ __( 'Quick wins', 'nvoos-pro-spa' ) }
				>
					<h3 className="nvoos-pro-spa-security__card-title">
						{ __( 'Quick wins', 'nvoos-pro-spa' ) }
					</h3>
					<ul className="nvoos-pro-spa-security__quick-wins">
						{ posture.quickWins.map( ( win ) => (
							<li key={ win.id } className="nvoos-pro-spa-security__quick-win">
								<span className="nvoos-pro-spa-security__quick-win-icon" aria-hidden="true">
									⚠️
								</span>
								<div>
									<strong className="nvoos-pro-spa-security__quick-win-label">
										{ win.label }
									</strong>
									{ win.detail && (
										<p className="nvoos-pro-spa-security__quick-win-detail">
											{ win.detail }
										</p>
									) }
								</div>
							</li>
						) ) }
					</ul>
				</section>
			) }

			{ posture && (
				<section
					className="nvoos-pro-spa-security__card"
					aria-label={ __( 'Posture checks', 'nvoos-pro-spa' ) }
				>
					<h3 className="nvoos-pro-spa-security__card-title">
						{ __( 'Posture checks', 'nvoos-pro-spa' ) } ({ passedCount }/{ posture.signals.length })
					</h3>
					{ groups.length === 0 ? (
						<p className="nvoos-pro-spa-security__muted">
							{ __( 'No posture checks reported.', 'nvoos-pro-spa' ) }
						</p>
					) : (
						groups.map( ( group ) => (
							<div key={ group.label } className="nvoos-pro-spa-security__group">
								<h4 className="nvoos-pro-spa-security__group-title">{ group.label }</h4>
								<DataTable
									columns={ SIGNAL_COLUMNS }
									rows={ signalRows( group.signals ) }
									caption={ sprintf(
										/* translators: %s: group label. */
										__( 'Posture checks — %s', 'nvoos-pro-spa' ),
										group.label
									) }
								/>
							</div>
						) )
					) }
				</section>
			) }

			<section
				className="nvoos-pro-spa-security__card"
				aria-label={ __( 'Security headers', 'nvoos-pro-spa' ) }
			>
				<h3 className="nvoos-pro-spa-security__card-title">
					{ __( 'Security headers', 'nvoos-pro-spa' ) }
				</h3>
				<p className="nvoos-pro-spa-security__header-status">
					<OnOffBadge
						on={ headers?.security_headers_enabled === true }
						labelOn={ __( 'Security headers enabled', 'nvoos-pro-spa' ) }
						labelOff={ __( 'Security headers disabled', 'nvoos-pro-spa' ) }
					/>{ ' ' }
					<OnOffBadge
						on={ headers?.hsts_enabled === true }
						labelOn={ __( 'HSTS on', 'nvoos-pro-spa' ) }
						labelOff={ __( 'HSTS off', 'nvoos-pro-spa' ) }
					/>
				</p>
				{ headerRows.length === 0 ? (
					<p className="nvoos-pro-spa-security__muted">
						{ headers?.security_headers_enabled
							? __( 'No headers would be emitted.', 'nvoos-pro-spa' )
							: __(
								'Enable security headers under Settings → NV oOS → Network to see the preview.',
								'nvoos-pro-spa'
							) }
					</p>
				) : (
					<DataTable
						columns={ [
							{
								key: 'header',
								label: __( 'Header', 'nvoos-pro-spa' ),
								width: 'minmax(220px, 1fr)',
							},
							{
								key: 'value',
								label: __( 'Value', 'nvoos-pro-spa' ),
								className: 'nvoos-pro-spa-tools-list__cell--desc',
								width: 'minmax(260px, 2fr)',
							},
						] }
						rows={ headerRows }
						caption={ __( 'Security headers that would be emitted', 'nvoos-pro-spa' ) }
					/>
				) }
			</section>

			<section
				className="nvoos-pro-spa-security__card"
				aria-label={ __( 'Snapshots', 'nvoos-pro-spa' ) }
			>
				<h3 className="nvoos-pro-spa-security__card-title">
					{ __( 'Snapshots', 'nvoos-pro-spa' ) }
				</h3>
				{ snapshots.length === 0 ? (
					<p className="nvoos-pro-spa-security__muted">
						{ __( 'No snapshots saved.', 'nvoos-pro-spa' ) }
					</p>
				) : (
					<DataTable
						columns={ snapshotColumns }
						rows={ snapshotRows }
						caption={ __( 'Saved security snapshots', 'nvoos-pro-spa' ) }
					/>
				) }
			</section>

			{ actionSummary && (
				<section
					className={
						'nvoos-pro-spa-security__card nvoos-pro-spa-security__action nvoos-pro-spa-security__action--' +
						actionSummary.tone
					}
					role="status"
					aria-label={ actionSummary.title }
				>
					<h3 className="nvoos-pro-spa-security__card-title">{ actionSummary.title }</h3>
					<p className="nvoos-pro-spa-security__action-detail">{ actionSummary.detail }</p>
					{ actionSummary.checks && actionSummary.checks.length > 0 && (
						<ul className="nvoos-pro-spa-security__checks">
							{ actionSummary.checks.map( ( check ) => (
								<li key={ check.id } className="nvoos-pro-spa-security__check">
									<PassBadge passed={ check.passed } />{' '}
									<strong className="nvoos-pro-spa-security__check-label">
										{ check.label }
									</strong>
									{ check.message && (
										<span className="nvoos-pro-spa-security__check-message">
											{ check.message }
										</span>
									) }
								</li>
							) ) }
						</ul>
					) }
				</section>
			) }

			<div
				className="nvoos-pro-spa-tools-page__controls"
				aria-label={ __( 'Security actions', 'nvoos-pro-spa' ) }
			>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary"
					disabled={ busy || saveBusy }
					onClick={ () => {
						setSaveBusy( true );
						void saveSnapshot().finally( () => setSaveBusy( false ) );
					} }
				>
					{ saveBusy
						? __( 'Saving…', 'nvoos-pro-spa' )
						: __( 'Save snapshot', 'nvoos-pro-spa' ) }
				</button>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry"
					disabled={ busy || testBusy }
					onClick={ () => {
						setTestBusy( true );
						void runSelfTest().finally( () => setTestBusy( false ) );
					} }
				>
					{ testBusy
						? __( 'Testing…', 'nvoos-pro-spa' )
						: __( 'Run self-test', 'nvoos-pro-spa' ) }
				</button>
			</div>
		</div>
	);
}
