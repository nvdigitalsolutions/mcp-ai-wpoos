/**
 * PaperStorePage — Paper Store management surface (Proposal 084 console port).
 *
 * Two-pane layout: collections on the left, records of the selected
 * collection on the right. Records expand master-detail (Teams/Records
 * pattern) and can be created, edited, and deleted through the plugin's
 * mcp-ai/v1/paper-store/* REST (the same surface the MCP paper_store tools
 * drive). All fetches gate their UI on loading state — no value pop-in.
 */

import { useCallback, useEffect, useMemo, useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import { Pagination } from '../../components/shared/Pagination';
import {
	PaperStoreClient,
	PAPER_STORE_PER_PAGE,
	type PaperRecord,
	type PaperRecordStatus,
} from '../../api/paper-store';

const STATUS_LABELS: Record< PaperRecordStatus, string > = {
	published: __( 'published', 'nvoos-pro-spa' ),
	draft: __( 'draft', 'nvoos-pro-spa' ),
	archived: __( 'archived', 'nvoos-pro-spa' ),
};

interface RecordFormState {
	id: string;
	title: string;
	description: string;
	tags: string;
	status: PaperRecordStatus;
	body: string;
}

const EMPTY_FORM: RecordFormState = {
	id: '',
	title: '',
	description: '',
	tags: '',
	status: 'published',
	body: '{}',
};

function recordToForm( record: PaperRecord ): RecordFormState {
	return {
		id: record.id,
		title: record.title,
		description: record.description ?? '',
		tags: ( record.tags ?? [] ).join( ', ' ),
		status: record.status ?? 'published',
		body: record.body !== undefined ? JSON.stringify( record.body, null, 2 ) : '{}',
	};
}

function formatDate( iso?: string ): string {
	if ( ! iso ) {
		return '—';
	}
	const date = new Date( iso );
	return Number.isNaN( date.getTime() ) ? iso : date.toLocaleString();
}

export function PaperStorePage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const client = useMemo(
		() =>
			new PaperStoreClient( {
				endpoint: runtime?.endpoints.paperStore ?? '',
				nonce: runtime?.nonce ?? '',
			} ),
		[ runtime ]
	);

	const [ collections, setCollections ] = useState< string[] >( [] );
	const [ collectionsLoading, setCollectionsLoading ] = useState< boolean >( true );
	const [ collectionsError, setCollectionsError ] = useState< string | null >( null );

	const [ activeCollection, setActiveCollection ] = useState< string >( '' );
	const [ records, setRecords ] = useState< PaperRecord[] >( [] );
	const [ recordsLoading, setRecordsLoading ] = useState< boolean >( false );
	const [ recordsError, setRecordsError ] = useState< string | null >( null );
	// Server-side pagination — the REST envelope counts the collection before
	// slicing, so the pager reports the real size instead of the page length.
	const [ page, setPage ] = useState< number >( 1 );
	const [ perPage, setPerPage ] = useState< number >( PAPER_STORE_PER_PAGE );
	const [ total, setTotal ] = useState< number >( 0 );
	const [ reloadToken, setReloadToken ] = useState< number >( 0 );

	const [ search, setSearch ] = useState( '' );
	const [ expandedId, setExpandedId ] = useState< string | null >( null );
	const [ formOpen, setFormOpen ] = useState< boolean >( false );
	const [ editingId, setEditingId ] = useState< string | null >( null );
	const [ form, setForm ] = useState< RecordFormState >( EMPTY_FORM );
	const [ formError, setFormError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState< boolean >( false );
	const [ deleteConfirm, setDeleteConfirm ] = useState< string | null >( null );

	const loadCollections = useCallback( async () => {
		if ( ! client ) {
			return;
		}
		setCollectionsLoading( true );
		setCollectionsError( null );
		try {
			const names = await client.collections();
			setCollections( names );
			setActiveCollection( ( current ) =>
				current && names.includes( current ) ? current : ( names[ 0 ] ?? '' )
			);
		} catch ( err ) {
			setCollectionsError(
				err instanceof Error ? err.message : __( 'Failed to load collections.', 'nvoos-pro-spa' )
			);
		} finally {
			setCollectionsLoading( false );
		}
	}, [ client ] );

	const loadRecords = useCallback(
		async ( collection: string, targetPage: number, targetPerPage: number ) => {
			if ( ! client || ! collection ) {
				setRecords( [] );
				setTotal( 0 );
				return;
			}
			setRecordsLoading( true );
			setRecordsError( null );
			setExpandedId( null );
			try {
				const result = await client.records( collection, {
					page: targetPage,
					perPage: targetPerPage,
				} );
				setRecords( result.items );
				setTotal( result.total );
			} catch ( err ) {
				setRecordsError(
					err instanceof Error ? err.message : __( 'Failed to load records.', 'nvoos-pro-spa' )
				);
			} finally {
				setRecordsLoading( false );
			}
		},
		[ client ]
	);

	useEffect( () => {
		void loadCollections();
	}, [ loadCollections ] );

	useEffect( () => {
		if ( activeCollection ) {
			void loadRecords( activeCollection, page, perPage );
		}
	}, [ activeCollection, page, perPage, reloadToken, loadRecords ] );

	// Deleting the last record on the final page shrinks the collection past the
	// current offset; fall back to the last page that still exists.
	useEffect( () => {
		if ( ! activeCollection ) {
			return;
		}
		const pageCount = Math.max( 1, Math.ceil( total / perPage ) );
		if ( page > pageCount ) {
			setPage( pageCount );
		}
	}, [ total, perPage, page, activeCollection ] );

	const visibleRecords = useMemo( () => {
		const q = search.trim().toLowerCase();
		if ( ! q ) {
			return records;
		}
		return records.filter(
			( r ) =>
				r.title.toLowerCase().includes( q ) ||
				( r.description ?? '' ).toLowerCase().includes( q ) ||
				( r.tags ?? [] ).some( ( tag ) => tag.toLowerCase().includes( q ) )
		);
	}, [ records, search ] );

	const openCreate = (): void => {
		setForm( EMPTY_FORM );
		setEditingId( null );
		setFormError( null );
		setFormOpen( true );
	};

	const openEdit = ( record: PaperRecord ): void => {
		setForm( recordToForm( record ) );
		setEditingId( record.id );
		setFormError( null );
		setFormOpen( true );
	};

	const closeForm = (): void => {
		setFormOpen( false );
		setEditingId( null );
		setFormError( null );
	};

	const handleSave = async (): Promise< void > => {
		if ( ! client || ! activeCollection || ! form.title.trim() ) {
			return;
		}
		let body: unknown = {};
		try {
			body = JSON.parse( form.body.trim() || '{}' );
		} catch {
			setFormError( __( 'The body must be valid JSON.', 'nvoos-pro-spa' ) );
			return;
		}
		setSaving( true );
		setFormError( null );
		try {
			const payload = {
				title: form.title.trim(),
				description: form.description.trim() || undefined,
				tags: form.tags
					.split( ',' )
					.map( ( t ) => t.trim() )
					.filter( ( t ) => t.length > 0 ),
				status: form.status,
				body,
			};
			if ( editingId !== null ) {
				await client.update( activeCollection, editingId, payload );
			} else {
				await client.create( activeCollection, {
					...payload,
					id: form.id.trim().toLowerCase().replace( /\s+/g, '-' ),
				} );
			}
			closeForm();
			// Records are ordered by updated_at desc, so a new record lands on
			// page 1.
			setPage( 1 );
			setReloadToken( ( token ) => token + 1 );
		} catch ( err ) {
			setFormError( err instanceof Error ? err.message : __( 'Save failed.', 'nvoos-pro-spa' ) );
		} finally {
			setSaving( false );
		}
	};

	const handleDelete = async ( id: string ): Promise< void > => {
		setDeleteConfirm( null );
		if ( ! client || ! activeCollection ) {
			return;
		}
		try {
			await client.remove( activeCollection, id );
			setReloadToken( ( token ) => token + 1 );
		} catch ( err ) {
			setRecordsError(
				err instanceof Error ? err.message : __( 'Delete failed.', 'nvoos-pro-spa' )
			);
		}
	};

	if ( bootstrapLoading || ( collectionsLoading && collections.length === 0 ) ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Paper Store', 'nvoos-pro-spa' ) }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading collections…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Paper Store', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'Paper Store', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Curated knowledge records across collections.', 'nvoos-pro-spa' ) }
				</p>
			</header>

			{ collectionsError && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ collectionsError }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => void loadCollections() }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			<div className="nvoos-pro-spa-paper-store__layout">
				<aside className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Collections', 'nvoos-pro-spa' ) }>
					<h3 className="nvoos-pro-spa-diagnostics__card-title">
						{ __( 'Collections', 'nvoos-pro-spa' ) }
					</h3>
					{ collections.length === 0 ? (
						<p className="nvoos-pro-spa-diagnostics__muted">
							{ __( 'No collections yet.', 'nvoos-pro-spa' ) }
						</p>
					) : (
						<ul className="nvoos-pro-spa-paper-store__collections">
							{ collections.map( ( name ) => (
								<li key={ name }>
									<button
										type="button"
										className="nvoos-pro-spa-paper-store__collection"
										aria-current={ activeCollection === name ? 'true' : undefined }
										onClick={ () => {
											setActiveCollection( name );
											setPage( 1 );
											setRecords( [] );
											setTotal( 0 );
										} }
									>
										{ name }
									</button>
								</li>
							) ) }
						</ul>
					) }
					<p>
						<button
							type="button"
							className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary nvoos-pro-spa-page__retry--small"
							disabled={ ! activeCollection }
							onClick={ openCreate }
						>
							{ __( 'New record', 'nvoos-pro-spa' ) }
						</button>
					</p>
				</aside>

				<main className="nvoos-pro-spa-diagnostics__card" aria-label={ __( 'Records', 'nvoos-pro-spa' ) }>
					{ ! activeCollection ? (
						<p className="nvoos-pro-spa-diagnostics__muted">
							{ __( 'Select a collection to browse its records.', 'nvoos-pro-spa' ) }
						</p>
					) : (
						<>
							<form
								className="nvoos-pro-spa-records-search"
								onSubmit={ ( event ) => event.preventDefault() }
							>
								<label className="nvoos-pro-spa-records-search__label" htmlFor="paper-store-search">
									{ __( 'Filter this page', 'nvoos-pro-spa' ) }
								</label>
								<input
									id="paper-store-search"
									type="search"
									className="nvoos-pro-spa-records-search__input"
									value={ search }
									onChange={ ( event ) => setSearch( event.target.value ) }
									placeholder={ __( 'Title, description, tag…', 'nvoos-pro-spa' ) }
								/>
							</form>

							{ recordsError && (
								<div
									className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error"
									role="alert"
								>
									<p>{ recordsError }</p>
									<button
										type="button"
										className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
										onClick={ () => setReloadToken( ( token ) => token + 1 ) }
									>
										{ __( 'Retry', 'nvoos-pro-spa' ) }
									</button>
								</div>
							) }

							{ search.trim() && records.length > 0 && (
								<p className="nvoos-pro-spa-diagnostics__muted" role="status">
									{ sprintf(
										/* translators: %1$d: matching records on this page, %2$d: records on this page */
										__( '%1$d of %2$d records on this page match.', 'nvoos-pro-spa' ),
										visibleRecords.length,
										records.length
									) }{ ' ' }
									<button
										type="button"
										className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
										onClick={ () => setSearch( '' ) }
									>
										{ __( 'Clear filter', 'nvoos-pro-spa' ) }
									</button>
								</p>
							) }

							{ recordsLoading && records.length === 0 ? (
								<p role="status">{ __( 'Loading records…', 'nvoos-pro-spa' ) }</p>
							) : records.length === 0 ? (
								<p className="nvoos-pro-spa-diagnostics__muted">
									{ __( 'No records found.', 'nvoos-pro-spa' ) }
								</p>
							) : visibleRecords.length === 0 ? (
								<p className="nvoos-pro-spa-diagnostics__muted">
									{ __( 'No records on this page match the filter.', 'nvoos-pro-spa' ) }
								</p>
							) : (
								<ul
									className={
										'nvoos-pro-spa-paper-store__records' +
										( recordsLoading
											? ' nvoos-pro-spa-tools-list--busy'
											: '' )
									}
									aria-busy={ recordsLoading || undefined }
								>
									{ visibleRecords.map( ( record ) => (
										<li key={ record.id }>
											<div className="nvoos-pro-spa-paper-store__record-row">
												<button
													type="button"
													className="nvoos-pro-spa-tools-list__name nvoos-pro-spa-tools-list__name--button"
													aria-expanded={ expandedId === record.id }
													onClick={ () =>
														setExpandedId( expandedId === record.id ? null : record.id )
													}
												>
													{ record.title }
												</button>
												<span className="nvoos-pro-spa-tools-list__badge">
													{ STATUS_LABELS[ record.status ?? 'published' ] }
												</span>
												<span className="nvoos-pro-spa-diagnostics__muted">
													{ formatDate( record.updated_at ) }
												</span>
												<button
													type="button"
													className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
													onClick={ () => openEdit( record ) }
												>
													{ __( 'Edit', 'nvoos-pro-spa' ) }
												</button>
												{ deleteConfirm === record.id ? (
													<span className="nvoos-pro-spa-assistants-list__confirm">
														<button
															type="button"
															className="nvoos-pro-spa-assistants-list__confirm-yes"
															onClick={ () => void handleDelete( record.id ) }
														>
															{ __( 'Delete', 'nvoos-pro-spa' ) }
														</button>
														<button
															type="button"
															className="nvoos-pro-spa-assistants-list__confirm-no"
															onClick={ () => setDeleteConfirm( null ) }
														>
															{ __( 'Cancel', 'nvoos-pro-spa' ) }
														</button>
													</span>
												) : (
													<button
														type="button"
														className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--danger nvoos-pro-spa-page__retry--small"
														onClick={ () => setDeleteConfirm( record.id ) }
													>
														{ __( 'Delete', 'nvoos-pro-spa' ) }
													</button>
												) }
											</div>
											{ expandedId === record.id && (
												<div className="nvoos-pro-spa-paper-store__detail">
													{ record.description && (
														<p className="nvoos-pro-spa-page__subtitle">
															{ record.description }
														</p>
													) }
													{ ( record.tags ?? [] ).length > 0 && (
														<p className="nvoos-pro-spa-diagnostics__muted">
															{ ( record.tags ?? [] ).map( ( tag ) => (
																<span key={ tag } className="nvoos-pro-spa-tools-list__badge">
																	{ tag }
																</span>
															) ) }
														</p>
													) }
													<pre className="nvoos-pro-spa-wf-editor__json">
														{ record.body !== undefined
															? JSON.stringify( record.body, null, 2 )
															: '{}' }
													</pre>
												</div>
											) }
										</li>
									) ) }
								</ul>
							) }

							{ ! search.trim() && records.length > 0 && (
								<Pagination
									page={ page }
									perPage={ perPage }
									total={ total }
									itemLabel={ __( 'records', 'nvoos-pro-spa' ) }
									label={ __( 'Paper Store records pagination', 'nvoos-pro-spa' ) }
									busy={ recordsLoading }
									onPageChange={ setPage }
									onPerPageChange={ ( next ) => {
										setPerPage( next );
										setPage( 1 );
									} }
								/>
							) }
						</>
					) }
				</main>
			</div>

			{ formOpen && (
				<div
					className="nvoos-pro-spa-assistants-form-overlay"
					role="dialog"
					aria-labelledby="nvoos-pro-spa-paper-form-title"
					aria-modal="true"
				>
					<div className="nvoos-pro-spa-assistants-form">
						<h3 id="nvoos-pro-spa-paper-form-title" className="nvoos-pro-spa-assistants-form__title">
							{ editingId !== null
								? __( 'Edit Record', 'nvoos-pro-spa' )
								: __( 'New Record', 'nvoos-pro-spa' ) }
						</h3>

						<div className="nvoos-pro-spa-assistants-form__field">
							<label className="nvoos-pro-spa-assistants-form__label" htmlFor="nvoos-pro-spa-paper-id">
								{ __( 'Record ID (slug)', 'nvoos-pro-spa' ) }
							</label>
							<input
								id="nvoos-pro-spa-paper-id"
								type="text"
								className="nvoos-pro-spa-assistants-form__input"
								value={ form.id }
								onChange={ ( event ) => setForm( { ...form, id: event.target.value } ) }
								disabled={ editingId !== null }
								required
							/>
						</div>

						<div className="nvoos-pro-spa-assistants-form__field">
							<label className="nvoos-pro-spa-assistants-form__label" htmlFor="nvoos-pro-spa-paper-title">
								{ __( 'Title', 'nvoos-pro-spa' ) }
							</label>
							<input
								id="nvoos-pro-spa-paper-title"
								type="text"
								className="nvoos-pro-spa-assistants-form__input"
								value={ form.title }
								onChange={ ( event ) => setForm( { ...form, title: event.target.value } ) }
								required
							/>
						</div>

						<div className="nvoos-pro-spa-assistants-form__field">
							<label
								className="nvoos-pro-spa-assistants-form__label"
								htmlFor="nvoos-pro-spa-paper-description"
							>
								{ __( 'Description', 'nvoos-pro-spa' ) }
							</label>
							<textarea
								id="nvoos-pro-spa-paper-description"
								className="nvoos-pro-spa-assistants-form__textarea"
								rows={ 2 }
								value={ form.description }
								onChange={ ( event ) => setForm( { ...form, description: event.target.value } ) }
							/>
						</div>

						<div className="nvoos-pro-spa-assistants-form__row">
							<div className="nvoos-pro-spa-assistants-form__field">
								<label className="nvoos-pro-spa-assistants-form__label" htmlFor="nvoos-pro-spa-paper-tags">
									{ __( 'Tags (comma-separated)', 'nvoos-pro-spa' ) }
								</label>
								<input
									id="nvoos-pro-spa-paper-tags"
									type="text"
									className="nvoos-pro-spa-assistants-form__input"
									value={ form.tags }
									onChange={ ( event ) => setForm( { ...form, tags: event.target.value } ) }
								/>
							</div>
							<div className="nvoos-pro-spa-assistants-form__field">
								<label className="nvoos-pro-spa-assistants-form__label" htmlFor="nvoos-pro-spa-paper-status">
									{ __( 'Status', 'nvoos-pro-spa' ) }
								</label>
								<select
									id="nvoos-pro-spa-paper-status"
									className="nvoos-pro-spa-assistants-form__select"
									value={ form.status }
									onChange={ ( event ) =>
										setForm( { ...form, status: event.target.value as PaperRecordStatus } )
									}
								>
									<option value="published">{ __( 'Published', 'nvoos-pro-spa' ) }</option>
									<option value="draft">{ __( 'Draft', 'nvoos-pro-spa' ) }</option>
									<option value="archived">{ __( 'Archived', 'nvoos-pro-spa' ) }</option>
								</select>
							</div>
						</div>

						<div className="nvoos-pro-spa-assistants-form__field">
							<label className="nvoos-pro-spa-assistants-form__label" htmlFor="nvoos-pro-spa-paper-body">
								{ __( 'Body (JSON)', 'nvoos-pro-spa' ) }
							</label>
							<textarea
								id="nvoos-pro-spa-paper-body"
								className="nvoos-pro-spa-assistants-form__textarea"
								rows={ 8 }
								value={ form.body }
								onChange={ ( event ) => setForm( { ...form, body: event.target.value } ) }
								spellCheck={ false }
							/>
						</div>

						{ formError && (
							<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
								{ formError }
							</p>
						) }

						<div className="nvoos-pro-spa-assistants-form__actions">
							<button
								type="button"
								className="nvoos-pro-spa-assistants-form__save-btn"
								onClick={ () => void handleSave() }
								disabled={ saving || ! form.title.trim() }
							>
								{ saving
									? __( 'Saving…', 'nvoos-pro-spa' )
									: editingId !== null
									? __( 'Update', 'nvoos-pro-spa' )
									: __( 'Create', 'nvoos-pro-spa' ) }
							</button>
							<button
								type="button"
								className="nvoos-pro-spa-assistants-form__cancel-btn"
								onClick={ closeForm }
							>
								{ __( 'Cancel', 'nvoos-pro-spa' ) }
							</button>
						</div>
					</div>
				</div>
			) }
		</div>
	);
}
