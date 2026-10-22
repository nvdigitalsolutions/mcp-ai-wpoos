/**
 * SecurityCenterPage — security posture overview.
 *
 * Shows the posture report, the security-header preview, saved snapshots and
 * the action runners (snapshot, restore, self-test). Replaces the wp-admin
 * Security Audit surface in the console (Proposal 084 Phase 1); the wp-admin
 * page stays behind the wp_mcp_ai_admin_page_enabled_security_center filter
 * until parity is verified.
 */

import { useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useSecurityCenter } from '../../hooks/useSecurityCenter';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';

export function SecurityCenterPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const {
		posture,
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

	const columns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'snapshot',
				label: __( 'Snapshot', 'nvoos-pro-spa' ),
				width: 'minmax(240px, 2fr)',
			},
			{
				key: 'actions',
				label: __( 'Actions', 'nvoos-pro-spa' ),
				width: '140px',
			},
		],
		[]
	);

	const rows = useMemo< DataTableRow[] >(
		() =>
			snapshots.map( ( snapshot ) => {
				const key = String( snapshot.snapshot_id ?? snapshot.id ?? '' );
				return {
					id: key,
					cells: [
						<strong className="nvoos-pro-spa-tools-list__name">
							{ String( snapshot.label ?? key ) }
						</strong>,
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

	if ( bootstrapLoading || ( loading && ! posture ) ) {
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

			<div className="nvoos-pro-spa-tools-page__controls" aria-label={ __( 'Security actions', 'nvoos-pro-spa' ) }>
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

			{ lastAction && (
				<pre className="nvoos-pro-spa-slash__result" role="status">
					{ JSON.stringify( lastAction, null, 2 ) }
				</pre>
			) }

			<section className="nvoos-pro-spa-tools-page__controls" aria-label={ __( 'Posture report', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-tools-page__label">{ __( 'Posture report', 'nvoos-pro-spa' ) }</h3>
				<pre className="nvoos-pro-spa-slash__result">
					{ posture ? JSON.stringify( posture, null, 2 ) : '—' }
				</pre>
			</section>

			<section className="nvoos-pro-spa-tools-page__controls" aria-label={ __( 'Security headers', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-tools-page__label">{ __( 'Security headers', 'nvoos-pro-spa' ) }</h3>
				<p>
					{ headers?.security_headers_enabled
						? __( 'Security headers enabled', 'nvoos-pro-spa' )
						: __( 'Security headers disabled', 'nvoos-pro-spa' ) }
					{ headers?.hsts_enabled ? ` — ${ __( 'HSTS on', 'nvoos-pro-spa' ) }` : '' }
				</p>
				{ headers?.headers && (
					<pre className="nvoos-pro-spa-slash__result">
						{ JSON.stringify( headers.headers, null, 2 ) }
					</pre>
				) }
			</section>

			<section className="nvoos-pro-spa-tools-page__controls" aria-label={ __( 'Snapshots', 'nvoos-pro-spa' ) }>
				<h3 className="nvoos-pro-spa-tools-page__label">{ __( 'Snapshots', 'nvoos-pro-spa' ) }</h3>
				{ snapshots.length === 0 ? (
					<p>{ __( 'No snapshots saved.', 'nvoos-pro-spa' ) }</p>
				) : (
					<DataTable
						columns={ columns }
						rows={ rows }
						caption={ __( 'Saved security snapshots', 'nvoos-pro-spa' ) }
					/>
				) }
			</section>
		</div>
	);
}
