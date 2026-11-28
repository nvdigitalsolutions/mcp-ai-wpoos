// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview @fileoverview Activity panel — the audit trail, filtered and exportable.
 *
 * This is the console's version of `db_audit_tail`, plus the two things a table
 * alone cannot do: refusal codes are highlighted so an incident is visible
 * without reading every row, and the current page exports to CSV client-side.
 *
 * The trail records the statement **template** and a fingerprint, never a bound
 * value — so the file is safe to hand to an auditor.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import { useCallback, useEffect, useMemo, useRef, useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import type {
	DatabaseAuditPage,
	DatabaseAuditRecord,
	DatabaseClient,
	DatabaseConnection,
	DatabaseOverview,
} from '../../../api/database';
import { Notice, downloadCsv, formatTimestamp } from './shared';

interface Filters {
	connection: string;
	action: string;
	tool: string;
	refusal_code: string;
}

const EMPTY_FILTERS: Filters = { connection: '', action: '', tool: '', refusal_code: '' };

/** Whether every filter is at its default. */
function isDefault( filters: Filters ): boolean {
	return (
		filters.connection === '' &&
		filters.action === '' &&
		filters.tool === '' &&
		filters.refusal_code === ''
	);
}

export function ActivityPanel( {
	client,
	overview,
}: {
	client: DatabaseClient;
	overview: DatabaseOverview;
} ): JSX.Element {
	const [ draft, setDraft ] = useState< Filters >( EMPTY_FILTERS );
	const [ applied, setApplied ] = useState< Filters >( EMPTY_FILTERS );
	const [ page, setPage ] = useState< DatabaseAuditPage | null >( null );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );
	const abort = useRef< AbortController | null >( null );

	const load = useCallback(
		async ( filters: Filters ): Promise< void > => {
			abort.current?.abort();

			const controller = new AbortController();
			abort.current = controller;

			setLoading( true );
			setError( null );

			try {
				const next = await client.audit( { ...filters, signal: controller.signal } );
				setPage( next );
			} catch ( cause ) {
				if ( cause instanceof DOMException && cause.name === 'AbortError' ) {
					return;
				}

				setError( cause instanceof Error ? cause.message : __( 'Unable to load the audit trail.', 'nvoos-pro-spa' ) );
			} finally {
				if ( abort.current === controller ) {
					setLoading( false );
				}
			}
		},
		[ client ]
	);

	useEffect( () => {
		void load( EMPTY_FILTERS );

		return () => abort.current?.abort();
	}, [ load ] );

	const refusalCodes = useMemo(
		() => Object.keys( page?.summary ?? overview.audit.refusals ?? {} ).filter( ( code ) => code !== 'total' ).sort(),
		[ page, overview.audit.refusals ]
	);

	const records = page?.records ?? [];

	if ( ! overview.runtime.available ) {
		return <Notice tone="bad" title={ __( 'The database kernel is not available', 'nvoos-pro-spa' ) }>{ overview.runtime.reason_label }</Notice>;
	}

	const exportCsv = (): void => {
		downloadCsv(
			`nvoos-database-audit-${ new Date().toISOString().slice( 0, 10 ) }.csv`,
			[
				__( 'Audit id', 'nvoos-pro-spa' ),
				__( 'Time', 'nvoos-pro-spa' ),
				__( 'Connection', 'nvoos-pro-spa' ),
				__( 'Scope', 'nvoos-pro-spa' ),
				__( 'Scope version', 'nvoos-pro-spa' ),
				__( 'Principal', 'nvoos-pro-spa' ),
				__( 'Action', 'nvoos-pro-spa' ),
				__( 'Tool', 'nvoos-pro-spa' ),
				__( 'Relation', 'nvoos-pro-spa' ),
				__( 'Refusal', 'nvoos-pro-spa' ),
				__( 'Rows', 'nvoos-pro-spa' ),
				__( 'Elapsed ms', 'nvoos-pro-spa' ),
				__( 'Truncated', 'nvoos-pro-spa' ),
				__( 'Statement template', 'nvoos-pro-spa' ),
				__( 'Fingerprint', 'nvoos-pro-spa' ),
				__( 'Detail', 'nvoos-pro-spa' ),
			],
			records.map( ( record: DatabaseAuditRecord ) => [
				record.id,
				new Date( record.ts * 1000 ).toISOString(),
				record.connection,
				record.scope,
				record.scope_version,
				record.principal,
				record.action,
				record.tool,
				record.relation,
				record.refusal_code ?? '',
				record.row_count,
				record.elapsed_ms,
				record.truncated,
				record.sql_template,
				record.fingerprint,
				record.detail,
			] )
		);
	};

	return (
		<>
			{ error !== null && (
				<Notice tone="bad" title={ __( 'The audit trail could not be read', 'nvoos-pro-spa' ) }>
					<p>{ error }</p>
				</Notice>
			) }

			<form
				className="nvoos-db-filters"
				onSubmit={ ( event ) => {
					event.preventDefault();
					setApplied( draft );
					void load( draft );
				} }
			>
				<label className="nvoos-db-field">
					<span>{ __( 'Connection', 'nvoos-pro-spa' ) }</span>
					<select
						value={ draft.connection }
						onChange={ ( event ) => setDraft( { ...draft, connection: event.target.value } ) }
					>
						<option value="">{ __( 'Any', 'nvoos-pro-spa' ) }</option>
						{ overview.connections.map( ( connection: DatabaseConnection ) => (
							<option key={ connection.id } value={ connection.id }>
								{ connection.id }
							</option>
						) ) }
					</select>
				</label>

				<label className="nvoos-db-field">
					<span>{ __( 'Refusal', 'nvoos-pro-spa' ) }</span>
					<select
						value={ draft.refusal_code }
						onChange={ ( event ) => setDraft( { ...draft, refusal_code: event.target.value } ) }
					>
						<option value="">{ __( 'Any', 'nvoos-pro-spa' ) }</option>
						{ refusalCodes.map( ( code ) => (
							<option key={ code } value={ code }>
								{ code }
							</option>
						) ) }
					</select>
				</label>

				<label className="nvoos-db-field">
					<span>{ __( 'Tool', 'nvoos-pro-spa' ) }</span>
					<input
						type="text"
						value={ draft.tool }
						placeholder="db_select"
						onChange={ ( event ) => setDraft( { ...draft, tool: event.target.value } ) }
					/>
				</label>

				<label className="nvoos-db-field">
					<span>{ __( 'Action', 'nvoos-pro-spa' ) }</span>
					<input
						type="text"
						value={ draft.action }
						placeholder="select"
						onChange={ ( event ) => setDraft( { ...draft, action: event.target.value } ) }
					/>
				</label>

				<div className="nvoos-db-filters__actions">
					<button type="submit" className="nvoos-db-btn" disabled={ loading }>
						{ loading ? __( 'Loading…', 'nvoos-pro-spa' ) : __( 'Apply filters', 'nvoos-pro-spa' ) }
					</button>
					<button
						type="button"
						className="nvoos-db-btn nvoos-db-btn--ghost"
						disabled={ loading || isDefault( applied ) }
						onClick={ () => {
							setDraft( EMPTY_FILTERS );
							setApplied( EMPTY_FILTERS );
							void load( EMPTY_FILTERS );
						} }
					>
						{ __( 'Reset', 'nvoos-pro-spa' ) }
					</button>
					<button
						type="button"
						className="nvoos-db-btn nvoos-db-btn--ghost"
						disabled={ loading || records.length === 0 }
						onClick={ exportCsv }
					>
						{ __( 'Export CSV', 'nvoos-pro-spa' ) }
					</button>
				</div>
			</form>

			<p className="nvoos-db-meta" role="status">
				{ loading
					? __( 'Reading the audit trail…', 'nvoos-pro-spa' )
					: sprintf(
							/* translators: 1: number of records shown, 2: ring capacity */
							__( 'Showing the latest %1$d record(s) of a %2$d-record ring.', 'nvoos-pro-spa' ),
							records.length,
							page?.retained ?? overview.audit.retained
					  ) }
				{ ( page?.summary?.total ?? 0 ) > 0 && (
					<>
						{ ' ' }
						{ sprintf(
							/* translators: %d: number of calls in the last 24 hours. */
							__( '%d call(s) in the last 24 hours.', 'nvoos-pro-spa' ),
							page?.summary?.total ?? 0
						) }
					</>
				) }
			</p>

			{ refusalCodes.length > 0 && (
				<div className="nvoos-db-chips">
					<span className="nvoos-db-chips__label">{ __( 'Refusals (24 h)', 'nvoos-pro-spa' ) }</span>
					{ refusalCodes.map( ( code ) => (
						<span key={ code } className="nvoos-db-chip nvoos-db-chip--bad">
							{ code }: { String( page?.summary?.[ code ] ?? overview.audit.refusals[ code ] ?? 0 ) }
						</span>
					) ) }
				</div>
			) }

			<div className={ `nvoos-db-scroll${ loading ? ' is-busy' : '' }` } aria-busy={ loading || undefined }>
				<table className="nvoos-db-table nvoos-db-table--audit">
					<caption className="nvoos-db-visually-hidden">
						{ __( 'Audit trail, newest first', 'nvoos-pro-spa' ) }
					</caption>
					<thead>
						<tr>
							<th scope="col">{ __( 'Time', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Connection / scope', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Tool', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Relation', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Rows', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'ms', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Refusal', 'nvoos-pro-spa' ) }</th>
							<th scope="col">{ __( 'Template', 'nvoos-pro-spa' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ records.length === 0 && ! loading && (
							<tr>
								<td colSpan={ 8 } className="nvoos-db-table__empty">
									{ __( 'No audit records match these filters.', 'nvoos-pro-spa' ) }
								</td>
							</tr>
						) }

						{ records.map( ( record: DatabaseAuditRecord ) => (
							<tr key={ record.id }>
								<td className="nvoos-db-nowrap">{ formatTimestamp( record.ts ) }</td>
								<td>
									<code>{ record.connection || '—' }</code>
									<span className="nvoos-db-sep" aria-hidden="true">/</span>
									<code>{ record.scope || '—' }</code>
									{ record.scope_version > 0 && <span className="nvoos-db-sub">v{ record.scope_version }</span> }
								</td>
								<td>
									<code>{ record.tool || '—' }</code>
									{ record.action !== '' && <span className="nvoos-db-sub">{ record.action }</span> }
								</td>
								<td>
									<code>{ record.relation || '—' }</code>
								</td>
								<td>{ record.row_count }</td>
								<td>{ record.elapsed_ms }</td>
								<td>
									{ record.refusal_code ? (
										<span className="nvoos-db-pill nvoos-db-pill--bad">{ record.refusal_code }</span>
									) : (
										<span className="nvoos-db-pill nvoos-db-pill--ok">{ __( 'served', 'nvoos-pro-spa' ) }</span>
									) }
								</td>
								<td className="nvoos-db-template" title={ record.sql_template }>
									{ record.sql_template || '—' }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</>
	);
}
