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
import { __ } from '@wordpress/i18n';
import { useBootstrap } from '../../hooks/useBootstrap';
import {
	RecordsClient,
	type RecordEntityConfig,
	type RecordSummary,
} from '../../api/records';

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
	records: {
		title: __( 'Records', 'nvoos-pro-spa' ),
		subtitle: __( 'Entity records across the toolkits.', 'nvoos-pro-spa' ),
	},
};

export interface RecordsPageProps {
	/** Command-center partition to render (undefined = every entity). */
	group?: string;
}

export function RecordsPage( { group }: RecordsPageProps ): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { entities: allEntities, schemaLoading, schemaError, client } = useRecords(
		runtime?.endpoints.recordsSchema ?? '',
		runtime?.endpoints.wpBase ?? '',
		runtime?.nonce ?? ''
	);

	const entities = useMemo(
		() => filterEntitiesByGroup( allEntities, group ),
		[ allEntities, group ]
	);

	const pageCopy = RECORDS_GROUP_COPY[ group ?? 'records' ] ?? RECORDS_GROUP_COPY.records;

	const [ entityKey, setEntityKey ] = useState< string >( '' );
	const [ search, setSearch ] = useState( '' );
	const [ records, setRecords ] = useState< RecordSummary[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ selected, setSelected ] = useState< RecordSummary | null >( null );

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

	// The entity-change effect applies the current search without
	// subscribing to every keystroke (the form submit triggers re-fetch).
	const searchRef = useRef( search );
	searchRef.current = search;

	const entity = useMemo(
		() => entities.find( ( e ) => e.key === entityKey ) ?? entities[ 0 ] ?? null,
		[ entities, entityKey ]
	);

	const refresh = useCallback(
		( currentEntity: RecordEntityConfig | null, currentSearch: string ) => {
			if ( ! currentEntity ) {
				return;
			}
			setLoading( true );
			setError( null );
			const controller = new AbortController();
			client
				.list( currentEntity, currentSearch, controller.signal )
				.then( ( rows ) => {
					setRecords( rows );
					setLoading( false );
				} )
				.catch( ( err: unknown ) => {
					setError(
						err instanceof Error ? err.message : __( 'Failed to load records.', 'nvoos-pro-spa' )
					);
					setLoading( false );
				} );
			return () => controller.abort();
		},
		[ client ]
	);

	useEffect( () => {
		if ( ! entity ) {
			return;
		}
		setSelected( null );
		refresh( entity, searchRef.current );
	}, [ entity, refresh ] );

	const openRecord = ( record: RecordSummary ): void => {
		setSelected( record );
	};

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
				refresh( entity, searchRef.current );
			} )
			.catch( ( err: unknown ) => {
				setCreateBusy( false );
				setResearchError(
					err instanceof Error ? err.message : __( 'Failed to create the record.', 'nvoos-pro-spa' )
				);
			} );
	};

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
							onClick={ () => setEntityKey( e.key ) }
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
						refresh( entity, search );
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

			{ entity && (
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
						onClick={ () => refresh( entity, search ) }
					>
						{ __( 'Retry', 'nvoos-pro-spa' ) }
					</button>
				</div>
			) }

			{ entity && loading && records.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'Loading records…', 'nvoos-pro-spa' ) }</p>
				</div>
			) : entity && ! error && records.length === 0 ? (
				<div className="nvoos-pro-spa-tools-page__empty" role="status">
					<p>{ __( 'No records found.', 'nvoos-pro-spa' ) }</p>
				</div>
			) : (
				<div className="nvoos-pro-spa-tools-list" role="list" aria-label={ entity?.label ?? '' }>
					{ records.map( ( record ) => (
						<div key={ record.id } className="nvoos-pro-spa-tools-list__row" role="row">
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								<button
									type="button"
									className="nvoos-pro-spa-tools-list__name nvoos-pro-spa-tools-list__name--button"
									aria-expanded={ selected?.id === record.id }
									onClick={ () => openRecord( record ) }
								>
									{ record.title }
								</button>
							</div>
							{ ( entity?.columns ?? [] ).slice( 0, 2 ).map( ( column ) => (
								<div
									key={ column.key }
									className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc"
									role="cell"
								>
									{ formatValue( record.meta[ column.key ] ) }
								</div>
							) ) }
							<div className="nvoos-pro-spa-tools-list__cell" role="cell">
								<span className="nvoos-pro-spa-tools-list__badge">{ record.status }</span>
							</div>
						</div>
					) ) }
				</div>
			) }

			{ selected && entity && (
				<section className="nvoos-pro-spa-records-detail" aria-label={ __( 'Record detail', 'nvoos-pro-spa' ) }>
					<h3 className="nvoos-pro-spa-records-detail__title">{ selected.title }</h3>
					<p className="nvoos-pro-spa-page__subtitle">
						{ entity.singular } · { selected.status }
					</p>
					<dl className="nvoos-pro-spa-records-detail__grid">
						{ entity.columns.map( ( column ) => (
							<div key={ column.key } className="nvoos-pro-spa-records-detail__item">
								<dt>{ column.label }</dt>
								<dd>{ formatValue( selected.meta[ column.key ] ) }</dd>
							</div>
						) ) }
					</dl>
				</section>
			) }
		</div>
	);
}
