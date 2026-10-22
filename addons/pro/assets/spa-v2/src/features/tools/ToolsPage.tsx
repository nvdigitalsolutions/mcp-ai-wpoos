/**
 * ToolsPage — Tool registry browser.
 *
 * Lists all available tools with search/filter by name, description, category,
 * and required capability. Assistant-aware: reads the shared assistant store
 * (the same selection the chat sidebar writes) and scopes the list to that
 * assistant's selected tools — falling back to the site default assistant
 * (the store's initial value) or the full registry when none is selected.
 *
 * The list is a semantic data table (native `<table>`, W3C sortable-table
 * pattern: header buttons + `aria-sort`), with a sticky header, client-side
 * pagination (the tools route returns the full registry in one envelope) and
 * a live result count.
 */

import { useState, useMemo, useCallback } from 'react';
import type { JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useTools } from '../../hooks/useTools';
import { useBootstrap } from '../../hooks/useBootstrap';
import { useAssistantStore } from '../../stores/assistantStore';
import {
	DataTable,
	type SortDirection,
	type DataTableColumn,
	type DataTableRow,
} from '../../components/shared/DataTable';

type SortKey = 'name' | 'category' | 'capability';

const PAGE_SIZE_OPTIONS = [ 25, 50, 100 ] as const;
const DEFAULT_PAGE_SIZE = 50;

/**
 * Build the page-number sequence for the pager, collapsing long ranges with
 * ellipsis gaps around the current page (e.g. `1 … 4 5 6 … 35`).
 */
function buildPageList( current: number, total: number ): ( number | 'gap' )[] {
	if ( total <= 7 ) {
		return Array.from( { length: total }, ( _, i ) => i + 1 );
	}
	const pages = new Set< number >( [ 1, 2, total - 1, total, current - 1, current, current + 1 ] );
	const sorted = Array.from( pages )
		.filter( ( p ) => p >= 1 && p <= total )
		.sort( ( a, b ) => a - b );
	const list: ( number | 'gap' )[] = [];
	let previous = 0;
	for ( const page of sorted ) {
		if ( previous && page - previous > 1 ) {
			list.push( 'gap' );
		}
		list.push( page );
		previous = page;
	}
	return list;
}

export function ToolsPage(): JSX.Element {
	const { runtime, loading: bootstrapLoading } = useBootstrap();

	// The active assistant is the single source of truth for tool scoping —
	// its initial value is the server-resolved default assistant, so the
	// page shows the default assistant's tools until the user picks another
	// one in the chat sidebar (or the full registry when there is none).
	const assistantId = useAssistantStore( ( s ) => s.assistantId );
	const assistants = useAssistantStore( ( s ) => s.assistants );
	const activeAssistant = assistants.find( ( a ) => a.id === assistantId );

	const { tools, loading, error, fetchTools } = useTools(
		runtime
			? {
					endpoint: runtime.endpoints.tools,
					nonce: runtime.nonce,
					assistantId,
			  }
			: { endpoint: '', nonce: '' }
	);

	const [ search, setSearch ] = useState< string >( '' );
	const [ categoryFilter, setCategoryFilter ] = useState< string >( '' );
	const [ sortBy, setSortBy ] = useState< SortKey >( 'name' );
	const [ sortDir, setSortDir ] = useState< SortDirection >( 'ascending' );
	const [ page, setPage ] = useState< number >( 1 );
	const [ perPage, setPerPage ] = useState< number >( DEFAULT_PAGE_SIZE );

	const categories = useMemo< string[] >( () => {
		const set = new Set( tools.map( ( t ) => t.category ).filter( Boolean ) );
		return Array.from( set ).sort();
	}, [ tools ] );

	const toggleSort = useCallback(
		( key: string ) => {
			const next = key as SortKey;
			if ( sortBy === next ) {
				setSortDir( ( dir ) => ( dir === 'ascending' ? 'descending' : 'ascending' ) );
			} else {
				setSortBy( next );
				setSortDir( 'ascending' );
			}
			setPage( 1 );
		},
		[ sortBy ]
	);

	const filteredTools = useMemo( () => {
		const q = search.toLowerCase().trim();
		let result = tools;

		if ( q ) {
			result = result.filter(
				( t ) =>
					t.name.toLowerCase().includes( q ) ||
					t.description.toLowerCase().includes( q ) ||
					t.slug.toLowerCase().includes( q )
			);
		}

		if ( categoryFilter ) {
			result = result.filter( ( t ) => t.category === categoryFilter );
		}

		result = [ ...result ].sort( ( a, b ) => {
			const left = ( () => {
				switch ( sortBy ) {
					case 'category':
						return ( a.category || '' ).toLowerCase();
					case 'capability':
						return ( a.required_capability || '' ).toLowerCase();
					case 'name':
					default:
						return a.name.toLowerCase();
				}
			} )();
			const right = ( () => {
				switch ( sortBy ) {
					case 'category':
						return ( b.category || '' ).toLowerCase();
					case 'capability':
						return ( b.required_capability || '' ).toLowerCase();
					case 'name':
					default:
						return b.name.toLowerCase();
				}
			} )();
			const compared = left.localeCompare( right );
			return sortDir === 'ascending' ? compared : -compared;
		} );

		return result;
	}, [ tools, search, categoryFilter, sortBy, sortDir ] );

	const pageCount = Math.max( 1, Math.ceil( filteredTools.length / perPage ) );
	const currentPage = Math.min( page, pageCount );
	const pageStart = ( currentPage - 1 ) * perPage;
	const pageTools = filteredTools.slice( pageStart, pageStart + perPage );
	const pageList = buildPageList( currentPage, pageCount );
	const rangeStart = filteredTools.length === 0 ? 0 : pageStart + 1;
	const rangeEnd = Math.min( pageStart + perPage, filteredTools.length );

	const columns = useMemo< DataTableColumn[] >(
		() => [
			{
				key: 'name',
				label: __( 'Tool', 'nvoos-pro-spa' ),
				sortable: true,
				width: 'minmax(200px, 1.2fr)',
			},
			{
				key: 'category',
				label: __( 'Category', 'nvoos-pro-spa' ),
				sortable: true,
				width: '130px',
			},
			{
				key: 'capability',
				label: __( 'Required Capability', 'nvoos-pro-spa' ),
				sortable: true,
				width: '160px',
			},
			{
				key: 'description',
				label: __( 'Description', 'nvoos-pro-spa' ),
				className: 'nvoos-pro-spa-tools-list__cell--desc',
				width: 'minmax(280px, 2fr)',
			},
		],
		[]
	);

	const rows = useMemo< DataTableRow[] >(
		() =>
			pageTools.map( ( tool ) => ( {
				id: tool.slug,
				cells: [
					<>
						<strong
							className="nvoos-pro-spa-tools-list__name"
							title={ tool.name }
						>
							{ tool.name }
						</strong>
						<code
							className="nvoos-pro-spa-tools-list__slug"
							title={ tool.slug }
						>
							{ tool.slug }
						</code>
					</>,
					tool.category ? (
						<span
							className="nvoos-pro-spa-tools-list__badge"
							title={ tool.category }
						>
							{ tool.category }
						</span>
					) : (
						<span className="nvoos-pro-spa-tools-list__badge nvoos-pro-spa-tools-list__badge--empty">
							—
						</span>
					),
					tool.required_capability ? (
						<code
							className="nvoos-pro-spa-tools-list__capability"
							title={ tool.required_capability }
						>
							{ tool.required_capability }
						</code>
					) : (
						<span className="nvoos-pro-spa-tools-list__badge nvoos-pro-spa-tools-list__badge--empty">
							—
						</span>
					),
					<span
						className="nvoos-pro-spa-tools-list__desc-clamp"
						title={ tool.description || undefined }
					>
						{ tool.description || '—' }
					</span>,
				],
			} ) ),
		[ pageTools ]
	);

	const clearFilters = useCallback( () => {
		setSearch( '' );
		setCategoryFilter( '' );
		setPage( 1 );
	}, [] );

	const isFiltered = search.trim().length > 0 || categoryFilter.length > 0;

	// While the bootstrap is still reading the runtime config, show the
	// loading UI — showing the runtime-error state here would flash an
	// alarming message before the config is even read.
	if ( bootstrapLoading ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--loading"
				aria-busy="true"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Tools', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading tools…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( ! runtime ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--error"
				role="alert"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Tools', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ __(
						'Runtime configuration not available. Please reload the page.',
						'nvoos-pro-spa'
					) }
				</p>
			</div>
		);
	}

	if ( loading ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--loading"
				aria-busy="true"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Tools', 'nvoos-pro-spa' ) }
				</h2>
				<div className="nvoos-pro-spa-page__loader" role="status">
					<span className="nvoos-pro-spa-page__spinner" />
					{ __( 'Loading tools…', 'nvoos-pro-spa' ) }
				</div>
			</div>
		);
	}

	if ( error ) {
		return (
			<div
				className="nvoos-pro-spa-page nvoos-pro-spa-page--error"
				role="alert"
			>
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Tools', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__message nvoos-pro-spa-page__message--error">
					{ error }
				</p>
				<button
					type="button"
					className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--primary"
					onClick={ () => void fetchTools() }
				>
					{ __( 'Retry', 'nvoos-pro-spa' ) }
				</button>
			</div>
		);
	}

	return (
		<div className="nvoos-pro-spa-page nvoos-pro-spa-tools-page">
			<header className="nvoos-pro-spa-tools-page__header">
				<div>
					<h2 className="nvoos-pro-spa-page__title">
						{ __( 'Tools', 'nvoos-pro-spa' ) }
					</h2>
					<p className="nvoos-pro-spa-page__subtitle">
						{ activeAssistant ? (
							<>
								{ __( 'Tools available to', 'nvoos-pro-spa' ) }{ ' ' }
								<strong>{ activeAssistant.title }</strong>
							</>
						) : (
							__(
								'Browse registered tools available to AI assistants.',
								'nvoos-pro-spa'
							)
						) }
					</p>
				</div>
				<span
					className="nvoos-pro-spa-tools-page__count"
					role="status"
					aria-live="polite"
				>
					{ filteredTools.length }{ ' ' }
					{ filteredTools.length === 1
						? __( 'tool', 'nvoos-pro-spa' )
						: __( 'tools', 'nvoos-pro-spa' ) }
				</span>
			</header>

			<div
				className="nvoos-pro-spa-tools-page__controls"
				role="search"
				aria-label={ __( 'Filter tools', 'nvoos-pro-spa' ) }
			>
				<div className="nvoos-pro-spa-tools-page__search">
					<label
						htmlFor="nvoos-pro-spa-tools-search"
						className="nvoos-pro-spa-tools-page__label"
					>
						{ __( 'Search tools', 'nvoos-pro-spa' ) }
					</label>
					<input
						id="nvoos-pro-spa-tools-search"
						type="search"
						className="nvoos-pro-spa-tools-page__input"
						value={ search }
						onChange={ ( e ) => {
							setSearch( e.target.value );
							setPage( 1 );
						} }
						placeholder={ __(
							'Search by name, description, or slug…',
							'nvoos-pro-spa'
						) }
					/>
				</div>

				<div className="nvoos-pro-spa-tools-page__filter">
					<label
						htmlFor="nvoos-pro-spa-tools-category"
						className="nvoos-pro-spa-tools-page__label"
					>
						{ __( 'Category', 'nvoos-pro-spa' ) }
					</label>
					<select
						id="nvoos-pro-spa-tools-category"
						className="nvoos-pro-spa-tools-page__select"
						value={ categoryFilter }
						onChange={ ( e ) => {
							setCategoryFilter( e.target.value );
							setPage( 1 );
						} }
					>
						<option value="">
							{ __( 'All categories', 'nvoos-pro-spa' ) }
						</option>
						{ categories.map( ( cat ) => (
							<option key={ cat } value={ cat }>
								{ cat }
							</option>
						) ) }
					</select>
				</div>

				{ isFiltered ? (
					<button
						type="button"
						className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
						onClick={ clearFilters }
					>
						{ __( 'Clear filters', 'nvoos-pro-spa' ) }
					</button>
				) : null }
			</div>

			{ filteredTools.length === 0 ? (
				<div
					className="nvoos-pro-spa-tools-page__empty"
					role="status"
				>
					<p>
						{ isFiltered
							? __(
									'No tools match your search criteria.',
									'nvoos-pro-spa'
							  )
							: __( 'No tools registered.', 'nvoos-pro-spa' ) }
					</p>
					{ isFiltered ? (
						<button
							type="button"
							className="nvoos-pro-spa-page__retry"
							onClick={ clearFilters }
						>
							{ __( 'Clear filters', 'nvoos-pro-spa' ) }
						</button>
					) : null }
				</div>
			) : (
				<DataTable
					columns={ columns }
					rows={ rows }
					caption={ __( 'Tool registry', 'nvoos-pro-spa' ) }
					sortKey={ sortBy }
					sortDir={ sortDir }
					onSort={ toggleSort }
					footer={
						<div className="nvoos-pro-spa-tools-page__pager">
							<p
								className="nvoos-pro-spa-tools-page__pager-label"
								role="status"
								aria-live="polite"
							>
								{ __( 'Showing', 'nvoos-pro-spa' ) }{ ' ' }
								{ rangeStart }–{ rangeEnd }{ ' ' }
								{ __( 'of', 'nvoos-pro-spa' ) }{ ' ' }
								{ filteredTools.length }{ ' ' }
								{ __( 'tools', 'nvoos-pro-spa' ) }
							</p>

							<label
								className="nvoos-pro-spa-tools-page__pager-size"
							>
								<span className="nvoos-pro-spa-sr-only">
									{ __( 'Rows per page', 'nvoos-pro-spa' ) }
								</span>
								<select
									className="nvoos-pro-spa-tools-page__select"
									value={ perPage }
									onChange={ ( e ) => {
										setPerPage( Number( e.target.value ) );
										setPage( 1 );
									} }
								>
									{ PAGE_SIZE_OPTIONS.map( ( size ) => (
										<option key={ size } value={ size }>
											{ size }{ ' ' }
											{ __( 'per page', 'nvoos-pro-spa' ) }
										</option>
									) ) }
								</select>
							</label>

							<button
								type="button"
								className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
								disabled={ currentPage <= 1 }
								onClick={ () => setPage( currentPage - 1 ) }
							>
								{ __( 'Previous', 'nvoos-pro-spa' ) }
							</button>

							{ pageList.map( ( item, index ) =>
								item === 'gap' ? (
									<span
										key={ `gap-${ index }` }
										className="nvoos-pro-spa-tools-page__pager-ellipsis"
										aria-hidden="true"
									>
										…
									</span>
								) : (
									<button
										key={ item }
										type="button"
										className={ [
											'nvoos-pro-spa-tools-page__pager-page',
											item === currentPage
												? 'nvoos-pro-spa-tools-page__pager-page--active'
												: '',
										]
											.filter( Boolean )
											.join( ' ' ) }
										aria-current={
											item === currentPage ? 'page' : undefined
										}
										aria-label={ `${ __(
											'Page',
											'nvoos-pro-spa'
										) } ${ item }` }
										onClick={ () => setPage( item ) }
									>
										{ item }
									</button>
								)
							) }

							<button
								type="button"
								className="nvoos-pro-spa-page__retry nvoos-pro-spa-page__retry--small"
								disabled={ currentPage >= pageCount }
								onClick={ () => setPage( currentPage + 1 ) }
							>
								{ __( 'Next', 'nvoos-pro-spa' ) }
							</button>
						</div>
					}
				/>
			) }
		</div>
	);
}
