/**
 * MediaTab — Main sidebar tab for browsing the WordPress Media Library.
 *
 * Supports Grid and List views, search, MIME-type filtering, and
 * selection of media items for attachment to chat messages.
 *
 * @since 2.0.3
 */

import {
	type JSX,
	useCallback,
	useEffect,
	useRef,
	useState,
} from 'react';
import { __, sprintf } from '@wordpress/i18n';

import { useMediaLibrary, type MediaViewMode } from '../../hooks/useMediaLibrary';
import type { MediaItem } from '../../api/media';
import { MediaGrid } from './MediaGrid';
import { MediaList } from './MediaList';

export interface MediaTabProps {
	/** WordPress REST API root URL. */
	apiRoot: string;
	/** WordPress REST nonce. */
	nonce: string;
	/** Callback when media items are selected for attachment. */
	onAttach?: ( item: MediaItem ) => void;
	/** Currently selected media IDs. */
	selectedIds: Set< number >;
}

const MIME_FILTERS = [
	{ value: '', label: () => __( 'All', 'nvoos-pro-spa' ) },
	{ value: 'image', label: () => __( 'Images', 'nvoos-pro-spa' ) },
	{ value: 'video', label: () => __( 'Videos', 'nvoos-pro-spa' ) },
	{ value: 'audio', label: () => __( 'Audio', 'nvoos-pro-spa' ) },
	{ value: 'application', label: () => __( 'Documents', 'nvoos-pro-spa' ) },
];

export function MediaTab( props: MediaTabProps ): JSX.Element {
	const { apiRoot, nonce, onAttach, selectedIds } = props;

	const [ viewMode, setViewMode ] = useState< MediaViewMode >( 'grid' );

	const {
		items,
		total,
		isLoading,
		isLoadingMore,
		error,
		unavailable,
		hasMore,
		loadMore,
		refresh,
		searchTerm,
		setSearchTerm,
		mimeFilter,
		setMimeFilter,
	} = useMediaLibrary( { apiRoot, nonce, disabled: ! apiRoot } );

	// Observer ref for infinite scroll.
	const sentinelRef = useRef< HTMLDivElement | null >( null );

	useEffect( () => {
		const sentinel = sentinelRef.current;
		if ( ! sentinel || ! hasMore ) return;

		const observer = new IntersectionObserver(
			( entries ) => {
				if ( entries[ 0 ]?.isIntersecting ) {
					loadMore();
				}
			},
			{ rootMargin: '200px' }
		);

		observer.observe( sentinel );
		return () => observer.disconnect();
	}, [ hasMore, loadMore ] );

	const handleSelect = useCallback(
		( item: MediaItem ) => {
			onAttach?.( item );
		},
		[ onAttach ]
	);

	const safeItems = Array.isArray( items ) ? items : [];
	const hasFilteredContent = searchTerm.length > 0 || mimeFilter.length > 0;

	return (
		<div className="nvoos-pro-spa-media-tab">
			{ /* ---- Toolbar ---- */ }
			<div className="nvoos-pro-spa-media-tab__toolbar">
				<div className="nvoos-pro-spa-media-tab__search">
					<label
						htmlFor="nvoos-pro-spa-media-search"
						className="nvoos-pro-spa-screen-reader-only"
					>
						{ __( 'Search media', 'nvoos-pro-spa' ) }
					</label>
					<input
						id="nvoos-pro-spa-media-search"
						type="search"
						className="nvoos-pro-spa-media-tab__search-input"
						placeholder={ __( 'Search media\u2026', 'nvoos-pro-spa' ) }
						value={ searchTerm }
						onChange={ ( e ) => setSearchTerm( e.target.value ) }
						aria-label={ __( 'Search media', 'nvoos-pro-spa' ) }
					/>
				</div>

				<div className="nvoos-pro-spa-media-tab__filters">
					<label
						htmlFor="nvoos-pro-spa-media-mime-filter"
						className="nvoos-pro-spa-screen-reader-only"
					>
						{ __( 'Filter by type', 'nvoos-pro-spa' ) }
					</label>
					<select
						id="nvoos-pro-spa-media-mime-filter"
						className="nvoos-pro-spa-media-tab__filter-select"
						value={ mimeFilter }
						onChange={ ( e ) => setMimeFilter( e.target.value ) }
					>
						{ MIME_FILTERS.map( ( f ) => (
							<option key={ f.value } value={ f.value }>
								{ f.label() }
							</option>
						) ) }
					</select>
				</div>

				<div className="nvoos-pro-spa-media-tab__view-toggle" role="radiogroup" aria-label={ __( 'View mode', 'nvoos-pro-spa' ) }>
					<button
						type="button"
						className={ [
							'nvoos-pro-spa-media-tab__view-btn',
							viewMode === 'grid' ? 'nvoos-pro-spa-media-tab__view-btn--active' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						role="radio"
						aria-checked={ viewMode === 'grid' }
						onClick={ () => setViewMode( 'grid' ) }
						aria-label={ __( 'Grid view', 'nvoos-pro-spa' ) }
						title={ __( 'Grid view', 'nvoos-pro-spa' ) }
					>
						{ '\u25A6' }
					</button>
					<button
						type="button"
						className={ [
							'nvoos-pro-spa-media-tab__view-btn',
							viewMode === 'list' ? 'nvoos-pro-spa-media-tab__view-btn--active' : '',
						]
							.filter( Boolean )
							.join( ' ' ) }
						role="radio"
						aria-checked={ viewMode === 'list' }
						onClick={ () => setViewMode( 'list' ) }
						aria-label={ __( 'List view', 'nvoos-pro-spa' ) }
						title={ __( 'List view', 'nvoos-pro-spa' ) }
					>
						{ '\u2630' }
					</button>
				</div>

				<button
					type="button"
					className="nvoos-pro-spa-media-tab__refresh-btn nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
					onClick={ refresh }
					aria-label={ __( 'Refresh media', 'nvoos-pro-spa' ) }
				>
					{ '\u21BB' }
				</button>
			</div>

			{ /* ---- Content ---- */ }
			<div className="nvoos-pro-spa-media-tab__body">
				{ isLoading && safeItems.length === 0 && (
					<div className="nvoos-pro-spa-media-tab__state">
						<span className="nvoos-pro-spa-media-tab__spinner" aria-hidden="true" />
						<p>{ __( 'Loading media\u2026', 'nvoos-pro-spa' ) }</p>
					</div>
				) }

				{ unavailable && (
					<div className="nvoos-pro-spa-media-tab__state nvoos-pro-spa-media-tab__state--notice">
						<p>{ __( 'Media Library is not available.', 'nvoos-pro-spa' ) }</p>
					</div>
				) }

				{ error && ! isLoading && (
					<div className="nvoos-pro-spa-media-tab__state nvoos-pro-spa-media-tab__state--error" role="alert">
						<p>{ error }</p>
						<button
							type="button"
							className="nvoos-pro-spa-btn nvoos-pro-spa-btn--small"
							onClick={ refresh }
						>
							{ __( 'Retry', 'nvoos-pro-spa' ) }
						</button>
					</div>
				) }

				{ ! isLoading && ! error && ! unavailable && safeItems.length === 0 && (
					<div className="nvoos-pro-spa-media-tab__state">
						<p>
							{ hasFilteredContent
								? __( 'No media match your search.', 'nvoos-pro-spa' )
								: __( 'No media items found.', 'nvoos-pro-spa' ) }
						</p>
					</div>
				) }

				{ safeItems.length > 0 && viewMode === 'grid' && (
					<MediaGrid
						items={ safeItems }
						onSelect={ handleSelect }
						selectedIds={ selectedIds }
					/>
				) }

				{ safeItems.length > 0 && viewMode === 'list' && (
					<MediaList
						items={ safeItems }
						onSelect={ handleSelect }
						selectedIds={ selectedIds }
					/>
				) }

				{ /* Infinite scroll sentinel */ }
				<div ref={ sentinelRef } className="nvoos-pro-spa-media-tab__sentinel" />

				{ isLoadingMore && (
					<div className="nvoos-pro-spa-media-tab__loading-more" aria-busy="true">
						<span className="nvoos-pro-spa-media-tab__spinner nvoos-pro-spa-media-tab__spinner--small" aria-hidden="true" />
						<span>{ __( 'Loading more\u2026', 'nvoos-pro-spa' ) }</span>
					</div>
				) }
			</div>

			{ /* ---- Footer ---- */ }
			{ safeItems.length > 0 && (
				<div className="nvoos-pro-spa-media-tab__footer">
					<p>
						{ sprintf(
							/* translators: %1$d: visible items, %2$d: total items */
							__( 'Showing %1$d of %2$d items', 'nvoos-pro-spa' ),
							safeItems.length,
							total
						) }
					</p>
				</div>
			) }
		</div>
	);
}
