/**
 * RecordsPage — the generic entity-records surface (Proposal 084 Phase 4,
 * W11 CRM command center pilot).
 *
 * One page parameterized by server-driven entity configs
 * (GET /mcp-ai-pro/v1/records/schema): entity switcher, search, list with
 * schema columns, and a detail panel. Data lives on core REST
 * (wp/v2/{post_type}) — the audit's wp-core storage strategy. W11 ships the
 * five CRM entities (company/customer/deal/lead/ticket).
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
	schemaError: string | null;
	client: RecordsClient;
} {
	const [ entities, setEntities ] = useState< RecordEntityConfig[] >( [] );
	const [ schemaError, setSchemaError ] = useState< string | null >( null );

	const client = useMemo(
		() => new RecordsClient( { schemaEndpoint, wpBase, nonce } ),
		[ schemaEndpoint, wpBase, nonce ]
	);

	useEffect( () => {
		if ( ! schemaEndpoint ) {
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
			} )
			.catch( ( error: unknown ) => {
				if ( cancelled ) {
					return;
				}
				setSchemaError(
					error instanceof Error ? error.message : __( 'Failed to load the record schema.', 'nvoos-pro-spa' )
				);
			} );
		return () => {
			cancelled = true;
			controller.abort();
		};
	}, [ client, schemaEndpoint ] );

	return { entities, schemaError, client };
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

export function RecordsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();
	const { entities, schemaError, client } = useRecords(
		runtime?.endpoints.recordsSchema ?? '',
		runtime?.endpoints.wpBase ?? '',
		runtime?.nonce ?? ''
	);

	const [ entityKey, setEntityKey ] = useState< string >( '' );
	const [ search, setSearch ] = useState( '' );
	const [ records, setRecords ] = useState< RecordSummary[] >( [] );
	const [ loading, setLoading ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ selected, setSelected ] = useState< RecordSummary | null >( null );

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

	if ( bootstrapLoading ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--loading" aria-busy="true">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'CRM', 'nvoos-pro-spa' ) }</h2>
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
				<h2 className="nvoos-pro-spa-page__title">{ __( 'CRM', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __( 'Runtime configuration not available. Please reload the page.', 'nvoos-pro-spa' ) }
				</p>
			</div>
		);
	}

	if ( schemaError ) {
		return (
			<div className="nvoos-pro-spa-page nvoos-pro-spa-page--error" role="alert">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'CRM', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ schemaError }
				</p>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<h2 className="nvoos-pro-spa-page__title">{ __( 'CRM', 'nvoos-pro-spa' ) }</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __( 'Companies, customers, deals, leads and tickets.', 'nvoos-pro-spa' ) }
				</p>
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

			{ entity && ! error && ! loading && records.length === 0 ? (
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
