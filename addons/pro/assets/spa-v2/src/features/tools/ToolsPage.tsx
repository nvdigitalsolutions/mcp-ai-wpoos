/**
 * ToolsPage — Tool registry browser.
 *
 * Lists all available tools with search/filter by name, description, category,
 * and required capability. Uses the useTools hook for data fetching and
 * useBootstrap for endpoint runtime config.
 */

import { useState, useMemo } from 'react';
import type { JSX } from 'react';
import { __ } from '@wordpress/i18n';
import { useTools } from '../../hooks/useTools';
import { useBootstrap } from '../../hooks/useBootstrap';

type SortKey = 'name' | 'category' | 'capability';

export function ToolsPage(): JSX.Element {
	const { runtime } = useBootstrap();

	const { tools, loading, error, fetchTools } = useTools(
		runtime
			? {
					endpoint: runtime.endpoints.tools,
					nonce: runtime.nonce,
			  }
			: { endpoint: '', nonce: '' }
	);

	const [ search, setSearch ] = useState< string >( '' );
	const [ categoryFilter, setCategoryFilter ] = useState< string >( '' );
	const [ sortBy, setSortBy ] = useState< SortKey >( 'name' );

	const categories = useMemo< string[] >( () => {
		const set = new Set( tools.map( ( t ) => t.category ).filter( Boolean ) );
		return Array.from( set ).sort();
	}, [ tools ] );

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
			switch ( sortBy ) {
				case 'category':
					return ( a.category || '' ).localeCompare( b.category || '' );
				case 'capability':
					return ( a.required_capability || '' ).localeCompare(
						b.required_capability || ''
					);
				case 'name':
				default:
					return a.name.localeCompare( b.name );
			}
		} );

		return result;
	}, [ tools, search, categoryFilter, sortBy ] );

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
					className="nvoos-pro-spa-page__retry"
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
				<h2 className="nvoos-pro-spa-page__title">
					{ __( 'Tools', 'nvoos-pro-spa' ) }
				</h2>
				<p className="nvoos-pro-spa-page__subtitle">
					{ __(
						'Browse registered tools available to AI assistants.',
						'nvoos-pro-spa'
					) }
				</p>
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
						onChange={ ( e ) => setSearch( e.target.value ) }
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
						onChange={ ( e ) => setCategoryFilter( e.target.value ) }
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

				<div className="nvoos-pro-spa-tools-page__sort">
					<label
						htmlFor="nvoos-pro-spa-tools-sort"
						className="nvoos-pro-spa-tools-page__label"
					>
						{ __( 'Sort by', 'nvoos-pro-spa' ) }
					</label>
					<select
						id="nvoos-pro-spa-tools-sort"
						className="nvoos-pro-spa-tools-page__select"
						value={ sortBy }
						onChange={ ( e ) =>
							setSortBy( e.target.value as SortKey )
						}
					>
						<option value="name">
							{ __( 'Name', 'nvoos-pro-spa' ) }
						</option>
						<option value="category">
							{ __( 'Category', 'nvoos-pro-spa' ) }
						</option>
						<option value="capability">
							{ __( 'Capability', 'nvoos-pro-spa' ) }
						</option>
					</select>
				</div>
			</div>

			{ filteredTools.length === 0 ? (
				<div
					className="nvoos-pro-spa-tools-page__empty"
					role="status"
				>
					<p>
						{ search || categoryFilter
							? __(
									'No tools match your search criteria.',
									'nvoos-pro-spa'
							  )
							: __(
									'No tools registered.',
									'nvoos-pro-spa'
							  ) }
					</p>
				</div>
			) : (
				<div
					className="nvoos-pro-spa-tools-list"
					role="list"
					aria-label={ __( 'Tool registry', 'nvoos-pro-spa' ) }
				>
					<div
						className="nvoos-pro-spa-tools-list__header"
						role="row"
					>
						<div
							className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head"
							role="columnheader"
						>
							{ __( 'Tool', 'nvoos-pro-spa' ) }
						</div>
						<div
							className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head"
							role="columnheader"
						>
							{ __( 'Category', 'nvoos-pro-spa' ) }
						</div>
						<div
							className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head"
							role="columnheader"
						>
							{ __( 'Required Capability', 'nvoos-pro-spa' ) }
						</div>
						<div
							className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--head"
							role="columnheader"
						>
							{ __( 'Description', 'nvoos-pro-spa' ) }
						</div>
					</div>
					{ filteredTools.map( ( tool ) => (
						<div
							key={ tool.slug }
							className="nvoos-pro-spa-tools-list__row"
							role="row"
						>
							<div
								className="nvoos-pro-spa-tools-list__cell"
								role="cell"
							>
								<strong className="nvoos-pro-spa-tools-list__name">
									{ tool.name }
								</strong>
								<code className="nvoos-pro-spa-tools-list__slug">
									{ tool.slug }
								</code>
							</div>
							<div
								className="nvoos-pro-spa-tools-list__cell"
								role="cell"
							>
								{ tool.category ? (
									<span className="nvoos-pro-spa-tools-list__badge">
										{ tool.category }
									</span>
								) : (
									<span className="nvoos-pro-spa-tools-list__badge nvoos-pro-spa-tools-list__badge--empty">
										—
									</span>
								) }
							</div>
							<div
								className="nvoos-pro-spa-tools-list__cell"
								role="cell"
							>
								{ tool.required_capability ? (
									<code className="nvoos-pro-spa-tools-list__capability">
										{ tool.required_capability }
									</code>
								) : (
									<span className="nvoos-pro-spa-tools-list__badge nvoos-pro-spa-tools-list__badge--empty">
										—
									</span>
								) }
							</div>
							<div
								className="nvoos-pro-spa-tools-list__cell nvoos-pro-spa-tools-list__cell--desc"
								role="cell"
							>
								{ tool.description || '—' }
							</div>
						</div>
					) ) }
				</div>
			) }

			<footer className="nvoos-pro-spa-tools-page__footer">
				<p>
					{ __( 'Showing', 'nvoos-pro-spa' ) }{ ' ' }
					{ filteredTools.length }{ ' ' }
					{ __( 'of', 'nvoos-pro-spa' ) }{ ' ' }
					{ tools.length }{ ' ' }
					{ __( 'tools', 'nvoos-pro-spa' ) }
				</p>
			</footer>
		</div>
	);
}
