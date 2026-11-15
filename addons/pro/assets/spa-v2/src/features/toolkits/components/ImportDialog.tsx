/**
 * ImportDialog — bulk create from a pasted sheet.
 *
 * Parsing is client-side (pure, unit-tested); validation is server-side. The
 * dialog never writes on the first click: "Check rows" runs the same endpoint
 * in preview mode, so the operator sees exactly which rows would be created,
 * skipped as duplicates, or rejected before anything is stored.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.1
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';

import {
	ToolkitsApiError,
	type ToolkitEntity,
	type ToolkitImportResult,
} from '../../../api/toolkits';
import { parseDelimitedRows } from '../format';
import type { ToolkitsClient } from '../../../api/toolkits';

interface ImportDialogProps {
	slug: string;
	entity: ToolkitEntity;
	client: ToolkitsClient;
	onImported: () => void;
	onCancel: () => void;
}

const PREVIEW_ROWS = 6;

export function ImportDialog( {
	slug,
	entity,
	client,
	onImported,
	onCancel,
}: ImportDialogProps ): JSX.Element {
	const [ text, setText ] = useState< string >( '' );
	const [ preview, setPreview ] = useState< ToolkitImportResult | null >( null );
	const [ busy, setBusy ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );

	const parsed = useMemo(
		() => parseDelimitedRows( text, entity.columns ),
		[ text, entity.columns ],
	);

	// Any edit invalidates the dry run.
	useEffect( () => {
		setPreview( null );
		setError( null );
	}, [ text ] );

	const check = useCallback( async (): Promise< void > => {
		setBusy( true );
		setError( null );

		try {
			const result = await client.importRows( slug, entity.key, parsed.rows, {
				preview: true,
			} );
			setPreview( result );
		} catch ( cause ) {
			setError(
				cause instanceof ToolkitsApiError
					? cause.message
					: __( 'Unable to check these rows.', 'nvoos-pro-spa' ),
			);
		} finally {
			setBusy( false );
		}
	}, [ client, entity.key, parsed.rows, slug ] );

	const commit = useCallback( async (): Promise< void > => {
		setBusy( true );
		setError( null );

		try {
			await client.importRows( slug, entity.key, parsed.rows, { preview: false } );
			onImported();
		} catch ( cause ) {
			setError(
				cause instanceof ToolkitsApiError
					? cause.message
					: __( 'Unable to import these rows.', 'nvoos-pro-spa' ),
			);
		} finally {
			setBusy( false );
		}
	}, [ client, entity.key, onImported, parsed.rows, slug ] );

	const title = sprintf(
		/* translators: %s: entity singular, e.g. "product". */
		__( 'Import %s', 'nvoos-pro-spa' ),
		entity.singular.toLowerCase(),
	);

	const valid = preview?.valid ?? 0;
	const columnHint = entity.columns.map( ( column ) => column.key ).join( ' · ' );

	return (
		<div className="nvoos-pro-spa-toolkits__drawer" role="dialog" aria-modal="true" aria-label={ title }>
			<div className="nvoos-pro-spa-toolkits__drawer-card nvoos-pro-spa-toolkits__drawer-card--wide">
				<header className="nvoos-pro-spa-toolkits__drawer-header">
					<h3 className="nvoos-pro-spa-toolkits__drawer-title">{ title }</h3>
					<button
						type="button"
						className="nvoos-pro-spa-toolkits__drawer-close"
						onClick={ onCancel }
						aria-label={ __( 'Close', 'nvoos-pro-spa' ) }
					>
						×
					</button>
				</header>

				<div className="nvoos-pro-spa-toolkits__drawer-body">
					<p className="nvoos-pro-spa-toolkits__import-hint">
					{ __(
						'Paste rows from a spreadsheet. A first row of column names is optional — the order above is used when it is missing.',
						'nvoos-pro-spa',
					) }
				</p>
				<p className="nvoos-pro-spa-toolkits__import-columns">
					<code>{ columnHint }</code>
				</p>

				<label className="nvoos-pro-spa-toolkits__field">
					<span className="nvoos-pro-spa-toolkits__label">
						{ __( 'Rows', 'nvoos-pro-spa' ) }
					</span>
					<textarea
						className="nvoos-pro-spa-toolkits__input nvoos-pro-spa-toolkits__textarea"
						rows={ 8 }
						spellCheck={ false }
						value={ text }
						onChange={ ( event ) => setText( event.target.value ) }
					/>
				</label>

				{ text.trim() !== '' && (
					<ul className="nvoos-pro-spa-toolkits__import-stats" role="status">
						<li>
							{ sprintf(
								/* translators: %d: number of parsed rows. */
								__( '%d rows', 'nvoos-pro-spa' ),
								parsed.rows.length,
							) }
						</li>
						{ parsed.skipped > 0 && (
							<li className="nvoos-pro-spa-toolkits__import-stat--warn">
								{ sprintf(
									/* translators: %d: number of skipped rows. */
									__( '%d skipped', 'nvoos-pro-spa' ),
									parsed.skipped,
								) }
							</li>
						) }
						{ parsed.unknownHeaders.length > 0 && (
							<li className="nvoos-pro-spa-toolkits__import-stat--warn">
								{ sprintf(
									/* translators: %s: comma-separated unknown header names. */
									__( 'Ignored columns: %s', 'nvoos-pro-spa' ),
									parsed.unknownHeaders.join( ', ' ),
								) }
							</li>
						) }
					</ul>
				) }

				{ parsed.rows.length > 0 && (
					<table className="nvoos-pro-spa-toolkits__import-preview">
						<thead>
							<tr>
								{ entity.columns.map( ( column ) => (
									<th key={ column.key } scope="col">
										{ column.label }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ parsed.rows.slice( 0, PREVIEW_ROWS ).map( ( row, index ) => (
								<tr key={ index }>
									{ entity.columns.map( ( column ) => (
										<td key={ column.key }>{ row[ column.key ] ?? '—' }</td>
									) ) }
								</tr>
							) ) }
						</tbody>
					</table>
				) }

				{ parsed.rows.length > PREVIEW_ROWS && (
					<p className="nvoos-pro-spa-toolkits__import-hint">
						{ sprintf(
							/* translators: %d: number of additional rows not shown. */
							__( '…and %d more.', 'nvoos-pro-spa' ),
							parsed.rows.length - PREVIEW_ROWS,
						) }
					</p>
				) }

				{ error && (
					<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
						<p>{ error }</p>
					</div>
				) }

				{ preview && (
					<div className="nvoos-pro-spa-toolkits__notice" role="status">
						<p>
							{ sprintf(
								/* translators: 1: valid rows, 2: duplicate rows, 3: rejected rows. */
								__( '%1$d ready · %2$d duplicate(s) · %3$d rejected', 'nvoos-pro-spa' ),
								valid,
								preview.duplicates,
								preview.failed.length,
							) }
						</p>

						{ preview.failed.length > 0 && (
							<ul className="nvoos-pro-spa-toolkits__import-failures">
								{ preview.failed.slice( 0, 5 ).map( ( failure ) => (
									<li key={ failure.index }>
										<strong>
											{ sprintf(
												/* translators: %d: 1-based row number. */
												__( 'Row %d', 'nvoos-pro-spa' ),
												failure.index + 1,
											) }
										</strong>
										{ ': ' }
										{ Object.entries( failure.fields )
											.map( ( [ field, message ] ) => `${ field } — ${ message }` )
											.join( '; ' ) }
									</li>
								) ) }
							</ul>
						) }
					</div>
				) }

				<div className="nvoos-pro-spa-toolkits__form-actions">
					<button
						type="button"
						className="nvoos-pro-spa-toolkits__btn nvoos-pro-spa-toolkits__btn--ghost"
						onClick={ onCancel }
					>
						{ __( 'Cancel', 'nvoos-pro-spa' ) }
					</button>

					<button
						type="button"
						className="nvoos-pro-spa-toolkits__btn nvoos-pro-spa-toolkits__btn--ghost"
						disabled={ busy || parsed.rows.length === 0 }
						onClick={ () => void check() }
					>
						{ busy ? __( 'Checking…', 'nvoos-pro-spa' ) : __( 'Check rows', 'nvoos-pro-spa' ) }
					</button>

						<button
							type="button"
							className="nvoos-pro-spa-toolkits__btn"
							disabled={ busy || preview === null || valid === 0 }
							onClick={ () => void commit() }
						>
							{ sprintf(
								/* translators: %d: number of rows that would be created. */
								__( 'Import %d', 'nvoos-pro-spa' ),
								valid,
							) }
						</button>
					</div>
				</div>
			</div>
		</div>
	);
}
