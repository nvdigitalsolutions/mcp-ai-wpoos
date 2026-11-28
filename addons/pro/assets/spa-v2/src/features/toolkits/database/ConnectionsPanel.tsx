// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview @fileoverview Connections panel — what the toolkit can reach, and whether it is safe.
 *
 * Read-only: the wp-admin page owns creating and editing a connection. What
 * this panel adds is the operator's answer at a glance — is it reachable, and
 * can its role write — plus a Test button that probes on demand and records the
 * result exactly as wp-admin does.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import { useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import type { DatabaseClient, DatabaseConnection, DatabaseOverview, DatabaseProbe } from '../../../api/database';
import { EmptyState, Notice, ProbePill, formatTimestamp, probeIsReadSafe } from './shared';

export function ConnectionsPanel( {
	client,
	overview,
}: {
	client: DatabaseClient;
	overview: DatabaseOverview;
} ): JSX.Element {
	const [ probes, setProbes ] = useState< Record< string, DatabaseProbe > >( {} );
	const [ testing, setTesting ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	if ( ! overview.runtime.available ) {
		return <Notice tone="bad" title={ __( 'The database kernel is not available', 'nvoos-pro-spa' ) }>{ overview.runtime.reason_label }</Notice>;
	}

	if ( ! overview.enabled ) {
		return (
			<Notice tone="warn" title={ __( 'The database toolkit is switched off', 'nvoos-pro-spa' ) }>
				<p>
					{ __(
						'No connection can be served until the toolkit is enabled in the plugin settings.',
						'nvoos-pro-spa'
					) }
				</p>
				{ overview.admin_url !== '' && (
					<p>
						<a className="nvoos-db-link" href={ overview.admin_url } target="_blank" rel="noreferrer">
							{ __( 'Open the toolkit settings in wp-admin', 'nvoos-pro-spa' ) }
						</a>
					</p>
				) }
			</Notice>
		);
	}

	const test = async ( id: string ): Promise< void > => {
		setTesting( id );
		setError( null );

		try {
			const probe = await client.testConnection( id );
			setProbes( ( current ) => ( { ...current, [ id ]: probe } ) );
		} catch ( cause ) {
			setError( cause instanceof Error ? cause.message : __( 'The probe failed.', 'nvoos-pro-spa' ) );
		} finally {
			setTesting( null );
		}
	};

	if ( overview.connections.length === 0 ) {
		return (
			<EmptyState icon="🛢️" title={ __( 'No connections yet', 'nvoos-pro-spa' ) }>
				<p>
					{ __(
						'A connection is an endpoint, never a grant. Add one in wp-admin, then point a scope at it here.',
						'nvoos-pro-spa'
					) }
				</p>
				{ overview.admin_url !== '' && (
					<p>
						<a className="nvoos-db-link" href={ overview.admin_url } target="_blank" rel="noreferrer">
							{ __( 'Add a connection in wp-admin', 'nvoos-pro-spa' ) }
						</a>
					</p>
				) }
			</EmptyState>
		);
	}

	return (
		<>
			{ error !== null && (
				<Notice tone="bad" title={ __( 'The probe could not run', 'nvoos-pro-spa' ) }>
					<p>{ error }</p>
				</Notice>
			) }

			<p className="nvoos-db-lede">
				{ __(
					'Reads run inside a read-only transaction against the role below. A role that can write is not read-safe, whatever the scope says.',
					'nvoos-pro-spa'
				) }
			</p>

			<div className="nvoos-db-scroll">
				<table className="nvoos-db-table">
					<caption className="nvoos-db-visually-hidden">
						{ __( 'Declared database connections', 'nvoos-pro-spa' ) }
					</caption>
					<thead>
						<tr>
							<th scope="col">{ __( 'Id', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Label', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Engine', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Endpoint', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Health', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Read-safe', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Checked', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Actions', 'nvoos-pro-spa' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ overview.connections.map( ( connection: DatabaseConnection ) => {
							const probe = probes[ connection.id ] ?? connection.probe ?? null;
							const safe = probeIsReadSafe( probe );

							return (
								<tr key={ connection.id }>
									<td>
										<code>{ connection.id }</code>
									</td>
									<td>{ connection.label || connection.id }</td>
									<td>{ connection.engine }</td>
									<td>
										{ connection.engine === 'sqlite'
											? connection.database || '—'
											: `${ connection.host || '—' }:${ connection.port || '—' } / ${
													connection.database || '—'
											  }` }
									</td>
									<td>
										<ProbePill probe={ probe } />
									</td>
									<td>
										<span className={ `nvoos-db-pill nvoos-db-pill--${ safe ? 'ok' : 'bad' }` }>
											{ safe ? __( 'Yes', 'nvoos-pro-spa' ) : __( 'No', 'nvoos-pro-spa' ) }
										</span>
									</td>
									<td>{ formatTimestamp( probe?.checked_at ) }</td>
									<td>
										<button
											type="button"
											className="nvoos-db-btn"
											disabled={ testing !== null }
											aria-busy={ testing === connection.id || undefined }
											onClick={ () => void test( connection.id ) }
										>
											{ testing === connection.id
												? __( 'Testing…', 'nvoos-pro-spa' )
												: __( 'Test', 'nvoos-pro-spa' ) }
										</button>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>

			{ overview.connections.some( ( connection ) => connection.error ) && (
				<Notice tone="warn" title={ __( 'A stored record could not be validated', 'nvoos-pro-spa' ) }>
					<ul className="nvoos-db-list">
						{ overview.connections
							.filter( ( connection ) => connection.error )
							.map( ( connection ) => (
								<li key={ connection.id }>
									<code>{ connection.id }</code>: <code>{ connection.error }</code>
								</li>
							) ) }
					</ul>
				</Notice>
			) }
		</>
	);
}
