// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview @fileoverview Health panel — the posture an operator checks before trusting the toolkit.
 *
 * Four questions, answered from stored state rather than a live probe: can the
 * kernel run here, are the PDO drivers loaded, is every declared connection
 * reachable with a role that cannot write, and what has been refused lately.
 * The sparkline is the audit ring bucketed by hour, so it cannot disagree with
 * the Activity tab.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import { useCallback, useEffect, useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import type { DatabaseAuditBucket, DatabaseClient, DatabaseConnection, DatabaseOverview } from '../../../api/database';
import { Chips, Notice, ProbePill, formatTimestamp, probeIsReadSafe } from './shared';

const DRIVER_LABELS: Record< string, string > = {
	pgsql: 'PostgreSQL (pdo_pgsql)',
	mysql: 'MySQL / MariaDB (pdo_mysql)',
	sqlite: 'SQLite (pdo_sqlite)',
};

export function HealthPanel( {
	client,
	overview,
}: {
	client: DatabaseClient;
	overview: DatabaseOverview;
} ): JSX.Element {
	const [ snapshot, setSnapshot ] = useState( overview );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const refresh = useCallback( async (): Promise< void > => {
		setRefreshing( true );
		setError( null );

		try {
			const health = await client.health();
			setSnapshot( ( current ) => ( { ...current, ...health } ) );
		} catch ( cause ) {
			setError( cause instanceof Error ? cause.message : __( 'Unable to refresh the health snapshot.', 'nvoos-pro-spa' ) );
		} finally {
			setRefreshing( false );
		}
	}, [ client ] );

	useEffect( () => {
		setSnapshot( overview );
	}, [ overview ] );

	const { audit, pdo, runtime } = snapshot;
	const refusalCodes = Object.keys( audit.refusals ?? {} ).sort();

	return (
		<>
			{ error !== null && (
				<Notice tone="bad" title={ __( 'The snapshot could not be refreshed', 'nvoos-pro-spa' ) }>
					<p>{ error }</p>
				</Notice>
			) }

			<div className="nvoos-db-grid">
				<section className="nvoos-db-card" aria-labelledby="nvoos-db-health-runtime">
					<h3 id="nvoos-db-health-runtime" className="nvoos-db-card__title">
						{ __( 'Kernel', 'nvoos-pro-spa' ) }
					</h3>
					<dl className="nvoos-db-kv">
						<dt>{ __( 'Status', 'nvoos-pro-spa' ) }</dt>
						<dd>
							<span className={ `nvoos-db-pill nvoos-db-pill--${ runtime.available ? 'ok' : 'bad' }` }>
								{ runtime.available
									? __( 'Available', 'nvoos-pro-spa' )
									: __( 'Unavailable', 'nvoos-pro-spa' ) }
							</span>
						</dd>
						<dt>{ __( 'PHP', 'nvoos-pro-spa' ) }</dt>
						<dd>{ runtime.php || '—' }</dd>
						{ ! runtime.available && runtime.reason_label !== '' && (
							<>
								<dt>{ __( 'Reason', 'nvoos-pro-spa' ) }</dt>
								<dd>{ runtime.reason_label }</dd>
							</>
						) }
						<dt>{ __( 'Toolkit', 'nvoos-pro-spa' ) }</dt>
						<dd>
							{ snapshot.enabled ? __( 'Enabled', 'nvoos-pro-spa' ) : __( 'Disabled', 'nvoos-pro-spa' ) }
						</dd>
						<dt>{ __( 'Write path', 'nvoos-pro-spa' ) }</dt>
						<dd>
							{ snapshot.write_path ? (
								<span className="nvoos-db-pill nvoos-db-pill--warn">{ __( 'Enabled', 'nvoos-pro-spa' ) }</span>
							) : (
								__( 'Off', 'nvoos-pro-spa' )
							) }
						</dd>
					</dl>
				</section>

				<section className="nvoos-db-card" aria-labelledby="nvoos-db-health-drivers">
					<h3 id="nvoos-db-health-drivers" className="nvoos-db-card__title">
						{ __( 'PDO drivers', 'nvoos-pro-spa' ) }
					</h3>
					<ul className="nvoos-db-list">
						{ Object.entries( DRIVER_LABELS ).map( ( [ key, label ] ) => (
							<li key={ key }>
								<span className={ `nvoos-db-pill nvoos-db-pill--${ pdo[ key ] ? 'ok' : 'bad' }` }>
									{ pdo[ key ] ? __( 'Loaded', 'nvoos-pro-spa' ) : __( 'Missing', 'nvoos-pro-spa' ) }
								</span>
								{ ' ' }
								{ label }
							</li>
						) ) }
					</ul>
					<p className="nvoos-db-help">
						{ __(
							'A missing driver is a per-connection condition, not an availability failure: the toolkit lists and refuses that engine with db_extension_missing.',
							'nvoos-pro-spa'
						) }
					</p>
				</section>
			</div>

			<section className="nvoos-db-card" aria-labelledby="nvoos-db-health-audit">
				<div className="nvoos-db-card__head">
					<h3 id="nvoos-db-health-audit" className="nvoos-db-card__title">
						{ __( 'Calls and refusals, last 24 hours', 'nvoos-pro-spa' ) }
					</h3>
					<button
						type="button"
						className="nvoos-db-btn nvoos-db-btn--ghost"
						disabled={ refreshing }
						aria-busy={ refreshing || undefined }
						onClick={ () => void refresh() }
					>
						{ refreshing ? __( 'Refreshing…', 'nvoos-pro-spa' ) : __( 'Refresh', 'nvoos-pro-spa' ) }
					</button>
				</div>

				<Sparkline buckets={ audit.buckets } />

				<p className="nvoos-db-meta">
					{ sprintf(
						/* translators: 1: calls in the window, 2: audit ring capacity. */
						__( '%1$d call(s) in the window; the trail retains the latest %2$d.', 'nvoos-pro-spa' ),
						audit.total_24h,
						audit.retained
					) }
				</p>

				{ refusalCodes.length > 0 ? (
					<Chips
						label={ __( 'Refusal codes', 'nvoos-pro-spa' ) }
						values={ refusalCodes.map( ( code ) => `${ code }: ${ audit.refusals[ code ] }` ) }
						tone="bad"
					/>
				) : (
					<p className="nvoos-db-help">{ __( 'No refusal was recorded in the window.', 'nvoos-pro-spa' ) }</p>
				) }
			</section>

			<section className="nvoos-db-card" aria-labelledby="nvoos-db-health-connections">
				<h3 id="nvoos-db-health-connections" className="nvoos-db-card__title">
					{ __( 'Connections', 'nvoos-pro-spa' ) }
				</h3>

				{ snapshot.connections.length === 0 ? (
					<p className="nvoos-db-help">{ __( 'No connection is declared.', 'nvoos-pro-spa' ) }</p>
				) : (
					<div className="nvoos-db-scroll">
						<table className="nvoos-db-table">
							<caption className="nvoos-db-visually-hidden">
								{ __( 'Stored health per connection', 'nvoos-pro-spa' ) }
							</caption>
							<thead>
								<tr>
									<th scope="col">{ __( 'Id', 'nvoos-pro-spa' ) }</th>
									<th scope="col">{ __( 'Reachable', 'nvoos-pro-spa' ) }</th>
									<th scope="col">{ __( 'Role', 'nvoos-pro-spa' ) }</th>
									<th scope="col">{ __( 'Can write', 'nvoos-pro-spa' ) }</th>
									<th scope="col">{ __( 'Bypasses RLS', 'nvoos-pro-spa' ) }</th>
									<th scope="col">{ __( 'Read-safe', 'nvoos-pro-spa' ) }</th>
									<th scope="col">{ __( 'Checked', 'nvoos-pro-spa' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ snapshot.connections.map( ( connection: DatabaseConnection ) => {
									const probe = connection.probe ?? null;
									const safe = probeIsReadSafe( probe );

									return (
										<tr key={ connection.id }>
											<td>
												<code>{ connection.id }</code>
											</td>
											<td>
												<ProbePill probe={ probe } />
											</td>
											<td>
												<code>{ probe?.role || connection.role || '—' }</code>
											</td>
											<td>{ probe?.can_write === true ? __( 'Yes', 'nvoos-pro-spa' ) : __( 'No', 'nvoos-pro-spa' ) }</td>
											<td>
												{ probe?.bypasses_rls === true ? __( 'Yes', 'nvoos-pro-spa' ) : __( 'No', 'nvoos-pro-spa' ) }
											</td>
											<td>
												<span className={ `nvoos-db-pill nvoos-db-pill--${ safe ? 'ok' : 'bad' }` }>
													{ safe ? __( 'Yes', 'nvoos-pro-spa' ) : __( 'No', 'nvoos-pro-spa' ) }
												</span>
											</td>
											<td>{ formatTimestamp( probe?.checked_at ) }</td>
										</tr>
									);
								} ) }
							</tbody>
						</table>
					</div>
				) }
			</section>
		</>
	);
}

/**
 * Hourly bars, refusals in the danger colour.
 *
 * `role="img"` with a label, because the numbers are the content and a screen
 * reader should hear the summary rather than 24 empty divs.
 */
function Sparkline( { buckets }: { buckets: DatabaseAuditBucket[] } ): JSX.Element {
	const max = Math.max( 1, ...buckets.map( ( bucket ) => bucket.calls ) );
	const calls = buckets.reduce( ( sum, bucket ) => sum + bucket.calls, 0 );
	const refusals = buckets.reduce( ( sum, bucket ) => sum + bucket.refusals, 0 );

	return (
		<figure
			className="nvoos-db-spark"
			role="img"
			aria-label={ sprintf(
				/* translators: 1: calls, 2: refusals. */
				__( 'Hourly calls for the last 24 hours: %1$d calls, %2$d refusals.', 'nvoos-pro-spa' ),
				calls,
				refusals
			) }
		>
			<div className="nvoos-db-spark__bars" aria-hidden="true">
				{ buckets.map( ( bucket ) => {
					const served = Math.max( 0, bucket.calls - bucket.refusals );

					return (
						<div key={ bucket.ts } className="nvoos-db-spark__column">
							<div
								className="nvoos-db-spark__served"
								style={ { height: `${ ( served / max ) * 100 }%` } }
							/>
							<div
								className="nvoos-db-spark__refused"
								style={ { height: `${ ( bucket.refusals / max ) * 100 }%` } }
							/>
						</div>
					);
				} ) }
			</div>
			<figcaption className="nvoos-db-spark__caption">
				{ buckets.length === 0
					? __( 'No activity recorded yet.', 'nvoos-pro-spa' )
					: sprintf(
							/* translators: 1: first hour label, 2: last hour label. */
							__( '%1$s → %2$s', 'nvoos-pro-spa' ),
							new Date( buckets[ 0 ].ts * 1000 ).toLocaleTimeString( [], { hour: '2-digit' } ),
							new Date( buckets[ buckets.length - 1 ].ts * 1000 ).toLocaleTimeString( [], {
								hour: '2-digit',
							} )
					  ) }
			</figcaption>
		</figure>
	);
}
