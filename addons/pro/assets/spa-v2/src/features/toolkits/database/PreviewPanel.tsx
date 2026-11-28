// SPDX-License-Identifier: LicenseRef-Proprietary
/**
 * @fileoverview @fileoverview Preview panel — compile a grant against a relation without reading a row.
 *
 * The console's answer to "what will this actually do?". A preview is not a
 * query: the executor resolves the connection, checks the tier, reads the
 * catalogue, resolves the relation, compiles the statement and admits it, then
 * stops — no transaction, no `prepare()`, no rows, no audit row. What comes back
 * is the statement template (`:pN` placeholders only), the columns that will be
 * returned, which of them are masked or excluded, and the effective caps.
 *
 * Bound values are counted, never carried, so the rendered statement can be
 * pasted into a ticket without leaking a row value.
 *
 * @package NV_oOS_Pro_Spa
 * @since   3.2.0
 */

import { Fragment, useMemo, useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import type { DatabaseClient, DatabasePreview, DatabaseScope } from '../../../api/database';
import { Chips, Notice } from './shared';

export function PreviewPanel( {
	client,
	scope,
	initialRelation = '',
}: {
	client: DatabaseClient;
	scope: DatabaseScope;
	initialRelation?: string;
} ): JSX.Element {
	const [ relation, setRelation ] = useState< string >( initialRelation );
	const [ columns, setColumns ] = useState< string >( '' );
	const [ limit, setLimit ] = useState< string >( '' );
	const [ preview, setPreview ] = useState< DatabasePreview | null >( null );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const suggestions = useMemo(
		() => ( scope.tables ?? [] ).filter( ( table ) => table !== '*' ),
		[ scope.tables ]
	);

	const compile = async (): Promise< void > => {
		if ( relation.trim() === '' ) {
			setError( __( 'Choose a relation to preview.', 'nvoos-pro-spa' ) );
			return;
		}

		setLoading( true );
		setError( null );

		try {
			const parsedLimit = Number.parseInt( limit, 10 );

			setPreview(
				await client.previewScope( ( scope.connection ?? '' ).trim(), scope, {
					relation: relation.trim().toLowerCase(),
					columns: columns
						.split( /[\r\n,]+/ )
						.map( ( value ) => value.trim().toLowerCase() )
						.filter( ( value ) => value !== '' ),
					limit: Number.isFinite( parsedLimit ) && parsedLimit > 0 ? parsedLimit : undefined,
				} )
			);
		} catch ( cause ) {
			setPreview( null );
			setError( cause instanceof Error ? cause.message : __( 'The preview could not be compiled.', 'nvoos-pro-spa' ) );
		} finally {
			setLoading( false );
		}
	};

	return (
		<div className="nvoos-db-preview">
			<div className="nvoos-db-filters">
				<label className="nvoos-db-field">
					{ __( 'Relation', 'nvoos-pro-spa' ) }
					<input
						type="text"
						list="nvoos-db-preview-relations"
						value={ relation }
						placeholder="public.orders"
						onChange={ ( event ) => setRelation( event.target.value ) }
					/>
				</label>

				<label className="nvoos-db-field">
					{ __( 'Columns (optional)', 'nvoos-pro-spa' ) }
					<input
						type="text"
						value={ columns }
						placeholder={ __( 'Every column the scope allows', 'nvoos-pro-spa' ) }
						onChange={ ( event ) => setColumns( event.target.value ) }
					/>
				</label>

				<label className="nvoos-db-field nvoos-db-field--narrow">
					{ __( 'Limit', 'nvoos-pro-spa' ) }
					<input
						type="number"
						min={ 1 }
						value={ limit }
						placeholder={ __( 'Scope cap', 'nvoos-pro-spa' ) }
						onChange={ ( event ) => setLimit( event.target.value ) }
					/>
				</label>

				<div className="nvoos-db-filters__actions">
					<button
						type="button"
						className="nvoos-db-btn"
						disabled={ loading }
						aria-busy={ loading || undefined }
						onClick={ () => void compile() }
					>
						{ loading ? __( 'Compiling…', 'nvoos-pro-spa' ) : __( 'Compile', 'nvoos-pro-spa' ) }
					</button>
				</div>
			</div>

			<datalist id="nvoos-db-preview-relations">
				{ suggestions.map( ( table ) => (
					<option key={ table } value={ table } />
				) ) }
			</datalist>

			{ error !== null && (
				<Notice tone="bad" title={ __( 'The preview was refused', 'nvoos-pro-spa' ) }>
					<p>{ error }</p>
				</Notice>
			) }

			{ preview !== null && <PreviewResult preview={ preview } /> }

			{ preview === null && error === null && ! loading && (
				<p className="nvoos-db-help">
					{ __(
						'Compiling executes nothing: the statement is built, checked against the admission policy and shown here. No row is read and no audit record is written.',
						'nvoos-pro-spa'
					) }
				</p>
			) }
		</div>
	);
}

function PreviewResult( { preview }: { preview: DatabasePreview } ): JSX.Element {
	return (
		<div className="nvoos-db-preview__result">
			<div className="nvoos-db-preview__head">
				<span className="nvoos-db-pill nvoos-db-pill--ok">{ preview.engine }</span>
				<span className="nvoos-db-pill nvoos-db-pill--muted">
					{ sprintf(
						/* translators: %d: number of bound values. */
						__( '%d bound value(s)', 'nvoos-pro-spa' ),
						preview.bindings
					) }
				</span>
				<span className="nvoos-db-pill nvoos-db-pill--muted">
					{ sprintf(
						/* translators: %d: maximum rows the statement may return. */
						__( 'row cap %d', 'nvoos-pro-spa' ),
						preview.row_limit
					) }
				</span>
				{ preview.tenant_bound && (
					<span className="nvoos-db-pill nvoos-db-pill--ok">
						{ __( 'tenant predicate applied', 'nvoos-pro-spa' ) }
					</span>
				) }
			</div>

			<pre className="nvoos-db-statement" aria-label={ __( 'Compiled statement', 'nvoos-pro-spa' ) }>
				<code>{ preview.statement }</code>
			</pre>

			<Chips label={ __( 'Returned', 'nvoos-pro-spa' ) } values={ preview.projected } />
			<Chips label={ __( 'Masked', 'nvoos-pro-spa' ) } values={ preview.masked } tone="warn" />
			<Chips label={ __( 'Excluded', 'nvoos-pro-spa' ) } values={ preview.excluded } tone="bad" />
			<Chips label={ __( 'Searchable', 'nvoos-pro-spa' ) } values={ preview.searchable } />

			<dl className="nvoos-db-kv">
				{ Object.entries( preview.limits ).map( ( [ key, value ] ) => (
					<Fragment key={ key }>
						<dt>{ key }</dt>
						<dd>{ String( value ) }</dd>
					</Fragment>
				) ) }
			</dl>
		</div>
	);
}
