/**
 * RecordsPage — the generic entity-records surface (Proposal 084 Phase 4).
 *
 * One page parameterized by server-driven entity configs
 * (GET /mcp-ai-pro/v1/records/schema): entity switcher, search, list with
 * schema columns, a detail panel, and the Research & Add card. Data lives on
 * core REST (wp/v2/{post_type}) — the audit's wp-core storage strategy.
 * The optional `group` prop partitions the page per command center
 * (W11 CRM: /crm; W12 PM: /pm; professions: /professions).
 */

import { useCallback, useEffect, useMemo, useRef, useState, type JSX } from 'react';
import { __, sprintf } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	DataTable,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';
import { Pagination } from '../../components/shared/Pagination';
import {
	RecordsClient,
	RECORDS_PER_PAGE,
	type RecordEntityConfig,
	type RecordSummary,
} from '../../api/records';
import { SitePicker } from '../commerce/SitePicker';

const REMOTE_SITE_KEY = 'nvoos-console.records.site';

function useRecords(
	schemaEndpoint: string,
	wpBase: string,
	nonce: string
): {
	entities: RecordEntityConfig[];
	schemaLoading: boolean;
	schemaError: string | null;
	client: RecordsClient;
} {
	const [ entities, setEntities ] = useState< RecordEntityConfig[] >( [] );
	// Distinct from `entities.length` so a genuinely empty schema (no
	// entities for this group) never renders the loading state forever.
	const [ schemaLoading, setSchemaLoading ] = useState< boolean >( true );
	const [ schemaError, setSchemaError ] = useState< string | null >( null );

	const client = useMemo(
		() => new RecordsClient( { schemaEndpoint, wpBase, nonce } ),
		[ schemaEndpoint, wpBase, nonce ]
	);

	useEffect( () => {
		if ( ! schemaEndpoint ) {
			setSchemaLoading( false );
			return;
		}
		let cancelled = false;
		const controller = new AbortController();
		client
			.schema( controller.signal )
			.then( ( map ) => {
				if ( cancelled ) {
					return;
				}
				setEntities( Object.values( map ) );
				setSchemaLoading( false );
			} )
			.catch( ( error: unknown ) => {
				if ( cancelled ) {
					return;
				}
				setSchemaError(
					error instanceof Error ? error.message : __( 'Failed to load the record schema.', 'nvoos-pro-spa' )
				);
				setSchemaLoading( false );
			} );
		return () => {
			cancelled = true;
			controller.abort();
		};
	}, [ client, schemaEndpoint ] );

	return { entities, schemaLoading, schemaError, client };
}

/** Format a meta value for the list/detail cells. */
function formatValue( value: unknown ): string {
	if ( value === null || value === undefined || value === '' ) {
		return '—';
	}
	if ( Array.isArray( value ) ) {
		return value.map( formatValue ).join( ', ' );
	}
	if ( typeof value === 'object' ) {
		return JSON.stringify( value );
	}
	return String( value );
}

/** Keep only the entities of one command-center group (undefined = all). */
export function filterEntitiesByGroup(
	entities: RecordEntityConfig[],
	group?: string
): RecordEntityConfig[] {
	if ( ! group ) {
		return entities;
	}
	return entities.filter( ( e ) => ( e.group ?? 'records' ) === group );
}

/** Page titles + subtitles per command-center group. */
export const RECORDS_GROUP_COPY: Record< string, { title: string; subtitle: string } > = {
	crm: {
		title: __( 'CRM', 'nvoos-pro-spa' ),
		subtitle: __( 'Companies, customers, deals, leads and tickets.', 'nvoos-pro-spa' ),
	},
	pm: {
		title: __( 'Projects', 'nvoos-pro-spa' ),
		subtitle: __( 'Projects, tasks and events.', 'nvoos-pro-spa' ),
	},
	professions: {
		title: __( 'Professions', 'nvoos-pro-spa' ),
		subtitle: __( 'Profession definitions and agent roles.', 'nvoos-pro-spa' ),
	},
	calendar: {
		title: __( 'Calendar', 'nvoos-pro-spa' ),
		subtitle: __( 'Appointments and bookings.', 'nvoos-pro-spa' ),
	},
	fnb: {
		title: __( 'Food & Beverage', 'nvoos-pro-spa' ),
		subtitle: __( 'Sales, purchases, stock counts, waste and bookings.', 'nvoos-pro-spa' ),
	},
	// Proposal 089 Phase 4e — commerce products and core content records.
	commerce: {
		title: __( 'Products', 'nvoos-pro-spa' ),
		subtitle: __( 'WooCommerce product catalogue (read-mostly; edits stay in wp-admin).', 'nvoos-pro-spa' ),
	},
	content: {
		title: __( 'Content', 'nvoos-pro-spa' ),
		subtitle: __( 'Posts and pages — browse here, edit in wp-admin.', 'nvoos-pro-spa' ),
	},
	records: {
		title: __( 'Records', 'nvoos-pro-spa' ),
		subtitle: __( 'Entity records across the toolkits.', 'nvoos-pro-spa' ),
	},
};

export interface RecordsPageProps {
	/** Command-center partition to render (undefined = every entity). */
	group?: string;
	/**
	 * Enable Phase B remote browsing: a site picker switches the list and
	 * detail fetches to the read-only remote proxy. Research/create are
	 * hidden while a remote site is selected.
	 */
	allowRemote?: boolean;
}

export function RecordsPage( { group, allowRemote = false }: RecordsPageProps ): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { entities: allEntities, schemaLoading, schemaError, client } = useRecords(
		runtime?.endpoints.recordsSchema ?? '',
		runtime?.endpoints.wpBase ?? '',
		runtime?.nonce ?? ''
	);

	// Phase B — the selected remote site (empty = local records).
	const [ remoteSite, setRemoteSite ] = useState< string >( () => {
		try {
			return window.localStorage.getItem( REMOTE_SITE_KEY ) ?? '';
		} catch {
			return '';
		}
	} );

	const entities = useMemo(
		() => filterEntitiesByGroup( allEntities, group ),
		[ allEntities, group ]
	);

	const pageCopy = RECORDS_GROUP_COPY[ group ?? 'records' ] ?? RECORDS_GROUP_COPY.records;

	const [ entityKey, setEntityKey ] = useState< string >( '' );
	const [ search, setSearch ] = useState( '' );
	// The search term the list was actually fetched with — typing does not
	// refetch, submitting the form does (see the search form below).
	const [ appliedSearch, setAppliedSearch ] = useState( '' );
	const [ records, setRecords ] = useState< RecordSummary[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	// Server-side pagination (wp/v2 reports the true counts through the
	// X-WP-Total / X-WP-TotalPages headers). The console must never present a
	// truncated first page as if it were the whole collection.
	const [ page, setPage ] = useState< number >( 1 );
	const [ perPage, setPerPage ] = useState< number >( RECORDS_PER_PAGE );
	const [ total, setTotal ] = useState< number >( 0 );
	// Bumped to force a refetch when the query has not changed (retry, or a
	// record was just created).
	const [ reloadToken, setReloadToken ] = useState< number >( 0 );

	/**
	 * Clear the visible page while a new query (entity, connection or search)
	 * is in flight — otherwise stale rows would render under the new columns,
	 * and the pager would advertise the previous collection's totals.
	 * `loading` is set here so the loading state shows on the very next render
	 * instead of flashing “No records found.” before the effect fires.
	 */
	const resetList = (): void => {
		setRecords( [] );
		setTotal( 0 );
		setLoading( true );
	};
	// Master-detail (Teams/Blueprints pattern): the expanded row's full
	// record is fetched on expand — the bottom detail panel is gone, and
	// values render once, already populated, instead of popping in.
	const [ expandedId, setExpandedId ] = useState< number | null >( null );
	const [ selected, setSelected ] = useState< RecordSummary | null >( null );
	const [ detailLoading, setDetailLoading ] = useState< boolean >( false );
	const [ detailError, setDetailError ] = useState< string | null >( null );
	const detailAbort = useRef< AbortController | null >( null );

	// Research & Add (4a-b): query → suggested fields → reviewed create.
	const [ researchQuery, setResearchQuery ] = useState( '' );
	const [ researchBusy, setResearchBusy ] = useState< boolean >( false );
	const [ researchError, setResearchError ] = useState< string | null >( null );
	const [ suggestion, setSuggestion ] = useState< {
		title: string;
		fields: Record< string, string >;
	} | null >( null );
	const [ formTitle, setFormTitle ] = useState( '' );
	const [ formFields, setFormFields ] = useState< Record< string, string > >( {} );
	const [ createBusy, setCreateBusy ] = useState< boolean >( false );
	const [ notice, setNotice ] = useState< string | null >( null );

	const entity = useMemo(
		() => entities.find( ( e ) => e.key === entityKey ) ?? entities[ 0 ] ?? null,
		[ entities, entityKey ]
	);

	const refresh = useCallback(
		(
			currentEntity: RecordEntityConfig | null,
			currentSearch: string,
			currentPage: number,
			currentPerPage: number
		) => {
			if ( ! currentEntity ) {
				return;
			}
			setLoading( true );
			setError( null );
			const controller = new AbortController();
			const options = {
				search: currentSearch,
				page: currentPage,
				perPage: currentPerPage,
				signal: controller.signal,
			};
			const request = remoteSite
				? client.listRemote( currentEntity, options, remoteSite )
				: client.list( currentEntity, options );
			request
				.then( ( result ) => {
					setRecords( result.items );
					setTotal( result.total );
					setLoading( false );
				} )
				.catch( ( err: unknown ) => {
					// WordPress answers 400 (rest_post_invalid_page_number) once a
					// page scrolls past the end — e.g. after deleting the last row
					// of the last page. Fall back to the first page instead of
					// dead-ending the console.
					if ( ( err as { status?: number } | null )?.status === 400 && currentPage > 1 ) {
						setLoading( false );
						setPage( 1 );
						return;
					}
					setError(
						err instanceof Error ? err.message : __( 'Failed to load records.', 'nvoos-pro-spa' )
					);
					setLoading( false );
				} );
			return () => controller.abort();
		},
		[ client, remoteSite ]
	);

	useEffect( () => {
		if ( ! entity ) {
			return;
		}
		detailAbort.current?.abort();
		detailAbort.current = null;
		setExpandedId( null );
		setSelected( null );
		setDetailError( null );
		setDetailLoading( false );
		return refresh( entity, appliedSearch, page, perPage );
	}, [ entity, appliedSearch, page, perPage, reloadToken, refresh ] );

	/**
	 * Expand/collapse a row, fetching the full record on expand (the list
	 * summary carries only the first columns' meta). The detail row shows a
	 * loading state while the fetch is in flight — no field-by-field pop-in.
	 */
	const openRecord = useCallback(
		( record: RecordSummary ): void => {
			if ( expandedId === record.id ) {
				detailAbort.current?.abort();
				detailAbort.current = null;
				setExpandedId( null );
				setDetailLoading( false );
				return;
			}
			setExpandedId( record.id );
			setSelected( null );
			setDetailError( null );
			if ( ! entity ) {
				return;
			}

			detailAbort.current?.abort();
			const controller = new AbortController();
			detailAbort.current = controller;
			setDetailLoading( true );
			const request = remoteSite
				? client.getRemote( entity, record.id, remoteSite, controller.signal )
				: client.get( entity, record.id, controller.signal );
			request
				.then( ( full ) => {
					setSelected( full ?? record );
					setDetailLoading( false );
				} )
				.catch( ( err: unknown ) => {
					if ( err instanceof Error && err.name === 'AbortError' ) {
						return;
					}
					setDetailError(
						err instanceof Error ? err.message : __( 'Failed to load the record.', 'nvoos-pro-spa' )
					);
					setDetailLoading( false );
				} );
		},
		[ client, entity, expandedId, remoteSite ]
	);

	// Abort an in-flight detail fetch when the page unmounts. The list fetch is
	// already aborted by its own effect cleanup; this covers the detail request,
	// which lives in a ref rather than an effect.
	useEffect( () => () => detailAbort.current?.abort(), [] );

	const runResearch = (): void => {
		if ( ! entity || ! researchQuery.trim() ) {
			return;
		}
		setResearchBusy( true );
		setResearchError( null );
		setNotice( null );
		client
			.research( entity, researchQuery.trim() )
			.then( ( result ) => {
				setSuggestion( result );
				setFormTitle( result.title );
				setFormFields( { ...result.fields } );
				setResearchBusy( false );
			} )
			.catch( ( err: unknown ) => {
				setResearchError(
					err instanceof Error ? err.message : __( 'Research failed.', 'nvoos-pro-spa' )
				);
				setResearchBusy( false );
			} );
	};

	const createFromSuggestion = (): void => {
		if ( ! entity || ! formTitle.trim() ) {
			return;
		}
		setCreateBusy( true );
		client
			.create( entity, formTitle.trim(), formFields )
			.then( () => {
				setCreateBusy( false );
				setNotice( __( 'Record created.', 'nvoos-pro-spa' ) );
				setSuggestion( null );
				setResearchQuery( '' );
				// New records are ordered by date desc, so they land on page 1.
				setPage( 1 );
				setReloadToken( ( token ) => token + 1 );
			} )
			.catch( ( err: unknown ) => {
				setCreateBusy( false );
				setResearchError(
					err instanceof Error ? err.message : __( 'Failed to create the record.', 'nvoos-pro-spa' )
				);
			} );
	};

	const listColumns = useMemo< DataTableColumn[] >( () => {
		if ( ! entity ) {
			return [];
		}
		return [
			{
				key: 'record',
				label: __( 'Record', 'nvoos-pro-spa' ),
				width: 'minmax(180px, 1fr)',
			},
			...entity.columns.slice( 0, 2 ).map( ( column ) => ( {
				key: column.key,
				label: column.label,
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(160px, 1fr)',
			} ) ),
			{ key: 'status', label: __( 'Status', 'nvoos-pro-spa' ), width: '130px' },
		];
	}, [ entity ] );

	const listRows = useMemo< DataTableRow[] >(
		() =>
			records.map( ( record ) => ( {
				id: record.id,
				cells: [
					<button
						type="button"
						className="nvoos-pro-spa-tools-list__name nvoos-pro-spa-tools-list__name--button"
						aria-expanded={ expandedId === record.id }
						onClick={ () => openRecord( record ) }
					>
						{ record.title }
					</button>,
					...( entity?.columns ?? [] )
						.slice( 0, 2 )
						.map( ( column ) => formatValue( record.meta[ column.key ] ) ),
					<span className="nvoos-pro-spa-tools-list__badge">{ record.status }</span>,
				],
				detail:
					expandedId === record.id ? (
						detailLoading ? (
							<p role="status">{ __( 'Loading record…', 'nvoos-pro-spa' ) }</p>
						) : detailError ? (
							<p
								className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error"
								role="alert"
							>
								{ detailError }
							</p>
						) : selected ? (
							<dl className="nvoos-pro-spa-records-detail__grid">
								{ entity?.columns.map( ( column ) => (
									<div key={ column.key } className="nvoos-pro-spa-records-detail__item">
										<dt>{ column.label }</dt>
										<dd>{ formatValue( selected.meta[ column.key ] ) }</dd>
									</div>
								) ) }
							</dl>
						) : null
					) : undefined,
			} ) ),
		[ records, entity, expandedId, selected, detailLoading, detailError, openRecord ]
	);

	if ( bootstrapLoading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ pageCopy.title }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ pageCopy.title }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	if ( schemaLoading && allEntities.length === 0 ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ pageCopy.title }</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading records…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( schemaError ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ pageCopy.title }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ schemaError }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ pageCopy.title }</h2>
				<p className="nvoos-pro-spa-page__subtitle">{ pageCopy.subtitle }</p>
			</header>

			{ allowRemote && (
				<SitePicker
					value={ remoteSite }
					onChange={ ( siteId ) => {
						try {
							window.localStorage.setItem( REMOTE_SITE_KEY, siteId );
						} catch {
							// localStorage unavailable — session-only.
						}
						setRemoteSite( siteId );
						setExpandedId( null );
						setSelected( null );
						// The connection changes the dataset entirely — start over.
						setPage( 1 );
						resetList();
					} }
				/>
			) }

			{ remoteSite && (
				<p className="nvoos-pro-spa-settings__hint" role="status">
					{ __( 'Browsing a remote site — read-only. Create and Research are hidden.', 'nvoos-pro-spa' ) }
				</p>
			) }

			{ entities.length > 1 && (
				<div className="nvoos-pro-spa-records-tabs" role="tablist" aria-label={ __( 'Entities', 'nvoos-pro-spa' ) }>
					{ entities.map( ( e ) => (
						<button
							key={ e.key }
							type="button"
							role="tab"
							aria-selected={ ( entity?.key ?? '' ) === e.key }
							className={
								'nvoos-pro-spa-records-tab' +
								( ( entity?.key ?? '' ) === e.key ? ' is-active' : '' )
							}
							onClick={ () => {
								setEntityKey( e.key );
								setPage( 1 );
								// Columns come from the schema, so rows from the previous
								// entity must not linger under the new headers.
								resetList();
							} }
						>
							{ e.icon } { e.label }
						</button>
					) ) }
				</div>
			) }

			{ entity && (
				<form
					className="nvoos-pro-spa-records-search"
					onSubmit={ ( event ) => {
						event.preventDefault();
						setAppliedSearch( search );
						setPage( 1 );
						resetList();
					} }
				>
					<label className="nvoos-pro-spa-records-search__label" htmlFor="records-search">
						{ __( 'Search', 'nvoos-pro-spa' ) } { entity.label.toLowerCase() }
					</label>
					<input
						id="records-search"
						type="search"
						className="nvoos-pro-spa-records-search__input"
						value={ search }
						onChange={ ( event ) => setSearch( event.target.value ) }
					/>
					<button type="submit" className="nvoos-pro-spa-page__retry">
						{ __( 'Go', 'nvoos-pro-spa' ) }
					</button>
				</form>
			) }

			{ entity && ! remoteSite && (
				<section className="nvoos-pro-spa-records-research" aria-label={ __( 'Research & Add', 'nvoos-pro-spa' ) }>
					<h3 className="nvoos-pro-spa-records-detail__title">
						{ __( 'Research & Add', 'nvoos-pro-spa' ) } { entity.singular.toLowerCase() }
					</h3>
					<form
						className="nvoos-pro-spa-records-search"
						onSubmit={ ( event ) => {
							event.preventDefault();
							runResearch();
						} }
					>
						<label className="nvoos-pro-spa-records-search__label" htmlFor="records-research-query">
							{ __( 'Research', 'nvoos-pro-spa' ) }
						</label>
						<input
							id="records-research-query"
							type="search"
							className="nvoos-pro-spa-records-search__input"
							value={ researchQuery }
							onChange={ ( event ) => setResearchQuery( event.target.value ) }
							placeholder={ __( 'e.g. Acme Ltd, London', 'nvoos-pro-spa' ) }
						/>
						<button type="submit" className="nvoos-pro-spa-page__retry" disabled={ researchBusy }>
							{ researchBusy ? __( 'Researching…', 'nvoos-pro-spa' ) : __( 'Research', 'nvoos-pro-spa' ) }
						</button>
					</form>

					{ researchError && (
						<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
							{ researchError }
						</p>
					) }

					{ notice && (
						<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--success" role="status">
							{ notice }
						</p>
					) }

					{ suggestion && (
						<form
							className="nvoos-pro-spa-records-detail"
							onSubmit={ ( event ) => {
								event.preventDefault();
								createFromSuggestion();
							} }
						>
							<label className="nvoos-pro-spa-records-search__label" htmlFor="records-form-title">
								{ __( 'Title', 'nvoos-pro-spa' ) }
							</label>
							<input
								id="records-form-title"
								type="text"
								className="nvoos-pro-spa-records-search__input"
								value={ formTitle }
								onChange={ ( event ) => setFormTitle( event.target.value ) }
							/>
							<dl className="nvoos-pro-spa-records-detail__grid">
								{ entity.columns.map( ( column ) => (
									<div key={ column.key } className="nvoos-pro-spa-records-detail__item">
										<dt>
											<label htmlFor={ `records-form-${ column.key }` }>{ column.label }</label>
										</dt>
										<dd>
											<input
												id={ `records-form-${ column.key }` }
												type="text"
												className="nvoos-pro-spa-records-search__input"
												value={ formFields[ column.key ] ?? '' }
												onChange={ ( event ) =>
													setFormFields( ( prev ) => ( {
														...prev,
														[ column.key ]: event.target.value,
													} ) )
												}
											/>
										</dd>
									</div>
								) ) }
							</dl>
							<div className="nvoos-pro-spa-records-search">
								<button type="submit" className="nvoos-pro-spa-page__retry" disabled={ createBusy }>
									{ createBusy ? __( 'Creating…', 'nvoos-pro-spa' ) : __( 'Create record', 'nvoos-pro-spa' ) }
								</button>
								<button
									type="button"
									className="nvoos-pro-spa-page__retry"
									onClick={ () => setSuggestion( null ) }
								>
									{ __( 'Discard', 'nvoos-pro-spa' ) }
								</button>
							</div>
						</form>
					) }
				</section>
			) }

			{ error && (
				<div className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error" role="alert">
					<p>{ error }</p>
					<button
						type="button"
						className="nvoos-pro-spa-page__retry"
						onClick={ () => setReloadToken( ( token ) => token + 1 ) }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ ! entity ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>
						{ __(
							'No record types are available for this command center yet.',
							'nvoos-pro-spa'
						) }
					</p>
				</div>
			) : loading && records.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'Loading records…', 'nvoos-pro-spa' ) }</p>
				</div>
			) : ! error && records.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>
						{ appliedSearch
							? sprintf(
									/* translators: %s: the search term */
									__( 'No records match “%s”.', 'nvoos-pro-spa' ),
									appliedSearch
							  )
							: __( 'No records found.', 'nvoos-pro-spa' ) }
					</p>
					{ appliedSearch ? (
						<button
							type="button"
							className="nvoos-pro-spa-page__retry"
							onClick={ () => {
								setSearch( '' );
								setAppliedSearch( '' );
								setPage( 1 );
								resetList();
							} }
						>
							{ __( 'Clear search', 'nvoos-pro-spa' ) }
						</button>
					) : null }
				</div>
			) : (
				<DataTable
					columns={ listColumns }
					rows={ listRows }
					caption={ entity?.label ?? __( 'Records', 'nvoos-pro-spa' ) }
					footer={
						<Pagination
							page={ page }
							perPage={ perPage }
							total={ total }
							itemLabel={ ( entity?.label ?? __( 'Records', 'nvoos-pro-spa' ) ).toLowerCase() }
							label={ sprintf(
								/* translators: %s: entity plural label (e.g. "Professions") */
								__( '%s pagination', 'nvoos-pro-spa' ),
								entity?.label ?? __( 'Records', 'nvoos-pro-spa' )
							) }
							busy={ loading }
							onPageChange={ setPage }
							onPerPageChange={ ( next ) => {
								setPerPage( next );
								setPage( 1 );
							} }
						/>
					}
				/>
			) }
		</div>
	);
}
