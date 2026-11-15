/**
 * EntitiesScreen — the entity workbench.
 *
 * One table per manifest entity with server-side pagination, search and
 * column filters, plus a generated create/edit form and a two-step delete.
 * Numeric columns carry the shared right-aligned tabular class so figures
 * line up with the rest of the console.
 *
 * @package NV_oOS_Pro_Spa
 * @since   2.5.0
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __ } from '@wordpress/i18n';

import {
	ToolkitsApiError,
	type ToolkitEntity,
	type ToolkitRecord,
} from '../../../api/toolkits';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../../components/shared/DataTable';
import { Pagination } from '../../../components/shared/Pagination';
import { EntityForm } from '../components/EntityForm';
import { ImportDialog } from '../components/ImportDialog';
import { formatCellValue, isNumericColumn } from '../format';
import type { ToolkitScreenProps } from '../ToolkitPage';

const SEARCH_DEBOUNCE_MS = 300;

export function EntitiesScreen( { client, manifest }: ToolkitScreenProps ): JSX.Element {
	const entities = manifest.entities;
	const [ entityKey, setEntityKey ] = useState< string >( entities[ 0 ]?.key ?? '' );
	const entity = useMemo< ToolkitEntity | undefined >(
		() => entities.find( ( candidate ) => candidate.key === entityKey ),
		[ entities, entityKey ],
	);

	const [ records, setRecords ] = useState< ToolkitRecord[] >( [] );
	const [ total, setTotal ] = useState< number >( 0 );
	const [ page, setPage ] = useState< number >( 1 );
	const [ perPage, setPerPage ] = useState< number >( 25 );
	const [ searchInput, setSearchInput ] = useState< string >( '' );
	const [ search, setSearch ] = useState< string >( '' );
	const [ loading, setLoading ] = useState< boolean >( true );
	const [ error, setError ] = useState< string | null >( null );

	const [ mode, setMode ] = useState< 'create' | null >( null );
	const [ importing, setImporting ] = useState< boolean >( false );
	const [ editing, setEditing ] = useState< ToolkitRecord | null >( null );
	const [ fieldErrors, setFieldErrors ] = useState< Record< string, string > >( {} );
	const [ busy, setBusy ] = useState< boolean >( false );
	const [ confirmDelete, setConfirmDelete ] = useState< string | null >( null );
	const [ currency, setCurrency ] = useState< string >( 'USD' );

	// Currency comes from the toolkit's own settings when it declares one.
	useEffect( () => {
		let cancelled = false;

		client
			.settings( manifest.slug )
			.then( ( state ) => {
				const value = state.values.currency;
				if ( ! cancelled && typeof value === 'string' && value.trim() !== '' ) {
					setCurrency( value.trim().toUpperCase() );
				}
			} )
			.catch( () => {
				// Settings are optional for the workbench; keep the default.
			} );

		return () => {
			cancelled = true;
		};
	}, [ client, manifest.slug ] );

	// Debounce the search box so typing does not fire a request per keystroke.
	useEffect( () => {
		const handle = window.setTimeout( () => {
			setSearch( searchInput.trim() );
			setPage( 1 );
		}, SEARCH_DEBOUNCE_MS );

		return () => window.clearTimeout( handle );
	}, [ searchInput ] );

	const load = useCallback(
		async ( signal?: AbortSignal ): Promise< void > => {
			if ( ! entity ) {
				return;
			}

			setLoading( true );
			setError( null );

			try {
				const result = await client.records( manifest.slug, entity.key, {
					page,
					perPage,
					search,
					signal,
				} );
				setRecords( result.items );
				setTotal( result.total );
			} catch ( cause ) {
				if ( cause instanceof DOMException && cause.name === 'AbortError' ) {
					return;
				}

				setError(
					cause instanceof ToolkitsApiError
						? cause.message
						: __( 'Unable to load records.', 'nvoos-pro-spa' ),
				);
			} finally {
				setLoading( false );
			}
		},
		[ client, entity, manifest.slug, page, perPage, search ],
	);

	useEffect( () => {
		const controller = new AbortController();
		void load( controller.signal );

		return () => controller.abort();
	}, [ load ] );

	const submitForm = useCallback(
		async ( payload: Record< string, unknown > ): Promise< void > => {
			if ( ! entity ) {
				return;
			}

			setBusy( true );
			setFieldErrors( {} );

			try {
				if ( editing ) {
					await client.updateRecord( manifest.slug, entity.key, editing.id, payload );
				} else {
					await client.createRecord( manifest.slug, entity.key, payload );
				}

				setMode( null );
				setEditing( null );
				await load();
			} catch ( cause ) {
				if ( cause instanceof ToolkitsApiError ) {
					setFieldErrors( cause.fields );
					setError( cause.fields && Object.keys( cause.fields ).length > 0 ? null : cause.message );
				} else {
					setError( __( 'Unable to save the record.', 'nvoos-pro-spa' ) );
				}
			} finally {
				setBusy( false );
			}
		},
		[ client, editing, entity, load, manifest.slug ],
	);

	const remove = useCallback(
		async ( record: ToolkitRecord ): Promise<void> => {
			if ( ! entity ) {
				return;
			}

			setBusy( true );
			setError( null );

			try {
				await client.deleteRecord( manifest.slug, entity.key, record.id );
				setConfirmDelete( null );
				await load();
			} catch ( cause ) {
				setError(
					cause instanceof ToolkitsApiError
						? cause.message
						: __( 'Unable to delete the record.', 'nvoos-pro-spa' ),
				);
			} finally {
				setBusy( false );
			}
		},
		[ client, entity, load, manifest.slug ],
	);

	const columns = useMemo< DataTableColumn[] >( () => {
		if ( ! entity ) {
			return [];
		}

		const mapped: DataTableColumn[] = entity.columns.map( ( column ) => ( {
			key: column.key,
			label: column.label,
			className: isNumericColumn( column ) ? 'nvoos-pro-spa-tools-list__cell--num' : undefined,
			width: column.key === entity.id_field ? 'minmax(140px, 1fr)' : undefined,
		} ) );

		mapped.push( {
			key: 'actions',
			label: __( 'Actions', 'nvoos-pro-spa' ),
			className: 'nvoos-pro-spa-toolkits__actions-col',
			width: 'minmax(160px, 0.7fr)',
		} );

		return mapped;
	}, [ entity ] );

	const rows = useMemo< DataTableRow[] >( () => {
		if ( ! entity ) {
			return [];
		}

		return records.map( ( record ) => ( {
			id: record.id,
			cells: [
				...entity.columns.map( ( column ) => {
					const value = formatCellValue( column, record.data[ column.key ], currency );

					if ( column.key === entity.id_field ) {
						return (
							<code className="nvoos-pro-spa-toolkits__key" key={ column.key }>
								{ value }
							</code>
						);
					}

					if ( column.type === 'enum' && value !== '—' ) {
						return (
							<span key={ column.key } className={ `nvoos-pro-spa-toolkits__badge nvoos-pro-spa-toolkits__badge--${ slugify(
								value,
							) }` }>
								{ value }
							</span>
						);
					}

					return <span key={ column.key }>{ value }</span>;
				} ),
				<div className="nvoos-pro-spa-toolkits__row-actions">
					<button
						type="button"
						className="nvoos-pro-spa-toolkits__link-btn"
						onClick={ () => {
							setFieldErrors( {} );
							setEditing( record );
							setMode( null );
						} }
					>
						{ __( 'Edit', 'nvoos-pro-spa' ) }
					</button>

					{ confirmDelete === record.id ? (
						<>
							<button
								type="button"
								className="nvoos-pro-spa-toolkits__link-btn nvoos-pro-spa-toolkits__link-btn--danger"
								disabled={ busy }
								onClick={ () => void remove( record ) }
							>
								{ __( 'Confirm', 'nvoos-pro-spa' ) }
							</button>
							<button
								type="button"
								className="nvoos-pro-spa-toolkits__link-btn"
								onClick={ () => setConfirmDelete( null ) }
							>
								{ __( 'Cancel', 'nvoos-pro-spa' ) }
							</button>
						</>
					) : (
						<button
							type="button"
							className="nvoos-pro-spa-toolkits__link-btn nvoos-pro-spa-toolkits__link-btn--danger"
							onClick={ () => setConfirmDelete( record.id ) }
						>
							{ __( 'Delete', 'nvoos-pro-spa' ) }
						</button>
					) }
				</div>,
			],
		} ) );
	}, [ records, entity, currency, confirmDelete, busy, remove ] );

	if ( ! entity ) {
		return (
			<div className="nvoos-pro-spa-toolkits__empty" role="status">
				<p>{ __( 'This toolkit declares no entities.', 'nvoos-pro-spa' ) }</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-toolkits__workbench">
			<div className="nvoos-pro-spa-toolkits__toolbar">
				<div className="nvoos-pro-spa-toolkits__toolbar-start">
					{ entities.length > 1 && (
						<label className="nvoos-pro-spa-toolkits__entity-picker">
							<span className="screen-reader-text">{ __( 'Entity', 'nvoos-pro-spa' ) }</span>
							<select
								className="nvoos-pro-spa-toolkits__input"
								value={ entityKey }
								onChange={ ( event ) => {
									setEntityKey( event.target.value );
									setPage( 1 );
								} }
							>
								{ entities.map( ( candidate ) => (
									<option key={ candidate.key } value={ candidate.key }>
										{ candidate.label }
									</option>
								) ) }
							</select>
						</label>
					) }

					<label className="nvoos-pro-spa-toolkits__search">
						<span className="screen-reader-text">
							{ __( 'Search', 'nvoos-pro-spa' ) } { entity.label }
						</span>
						<span className="nvoos-pro-spa-toolkits__search-icon" aria-hidden="true">
							🔍
						</span>
						<input
							type="search"
							className="nvoos-pro-spa-toolkits__input nvoos-pro-spa-toolkits__input--search"
							placeholder={ __( 'Search', 'nvoos-pro-spa' ) + ' ' + entity.label.toLowerCase() }
							value={ searchInput }
							onChange={ ( event ) => setSearchInput( event.target.value ) }
						/>
					</label>
				</div>

				<div className="nvoos-pro-spa-toolkits__toolbar-end">
					<button
						type="button"
						className="nvoos-pro-spa-toolkits__btn nvoos-pro-spa-toolkits__btn--ghost"
						onClick={ () => setImporting( true ) }
					>
						<span aria-hidden="true">⇪</span> { __( 'Import', 'nvoos-pro-spa' ) }
					</button>

					<button
						type="button"
						className="nvoos-pro-spa-toolkits__btn"
						onClick={ () => {
							setFieldErrors( {} );
							setEditing( null );
							setMode( 'create' );
						} }
					>
						<span aria-hidden="true">＋</span> { __( 'New', 'nvoos-pro-spa' ) }{ ' ' }
						{ entity.singular.toLowerCase() }
					</button>
				</div>
			</div>

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button type="button" className="nvoos-pro-spa-page__retry" onClick={ () => void load() }>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			<DataTable
				columns={ columns }
				rows={ rows }
				caption={ entity.label }
				busy={ loading }
				empty={
					<div className="nvoos-pro-spa-toolkits__table-empty">
						<span aria-hidden="true">{ entity.icon || '🗂️' }</span>
						<p>
							{ search !== ''
								? __( 'No records match that search.', 'nvoos-pro-spa' )
								: __( 'No records yet.', 'nvoos-pro-spa' ) }
						</p>
					</div>
				}
				footer={
					<Pagination
						page={ page }
						perPage={ perPage }
						total={ total }
						onPageChange={ setPage }
						onPerPageChange={ ( next ) => {
							setPerPage( next );
							setPage( 1 );
						} }
						itemLabel={ entity.label.toLowerCase() }
						busy={ loading }
					/>
				}
			/>

			{ importing && (
				<ImportDialog
					slug={ manifest.slug }
					entity={ entity }
					client={ client }
					onCancel={ () => setImporting( false ) }
					onImported={ () => {
						setImporting( false );
						setPage( 1 );
						void load();
					} }
				/>
			) }

			{ ( mode === 'create' || editing !== null ) && (
				<EntityForm
					entity={ entity }
					mode={ editing !== null ? 'update' : 'create' }
					initial={ editing?.data }
					fieldErrors={ fieldErrors }
					busy={ busy }
					onSubmit={ ( payload ) => void submitForm( payload ) }
					onCancel={ () => {
						setMode( null );
						setEditing( null );
						setFieldErrors( {} );
					} }
				/>
			) }
		</div>
	);
}

/** Turn an enum value into a stable badge modifier. */
function slugify( value: string ): string {
	return value
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-+|-+$/g, '' );
}
