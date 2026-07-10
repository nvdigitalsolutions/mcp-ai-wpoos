/**
 * useMediaLibrary — Hook for browsing WordPress Media Library items.
 *
 * Provides paginated, searchable access to the `/wp/v2/media` REST
 * endpoint.  Supports MIME-type filtering and lazy-loading of pages.
 *
 * @since 2.0.3
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
	MediaClient,
	type MediaItem,
	MEDIA_PER_PAGE,
} from '../api/media';

export interface UseMediaLibraryOptions {
	/** WordPress REST API root URL. */
	apiRoot: string;
	/** WordPress REST nonce. */
	nonce: string;
	/** Disables server calls when true. */
	disabled?: boolean;
}

export type MediaViewMode = 'grid' | 'list';

export interface UseMediaLibraryReturn {
	/** Loaded media items (null = not yet loaded). */
	items: MediaItem[] | null;
	/** Total number of media items available. */
	total: number;
	/** True while the initial or a subsequent page load is in progress. */
	isLoading: boolean;
	/** True while loading a subsequent page. */
	isLoadingMore: boolean;
	/** Generic transient error from the latest call. */
	error: string | null;
	/** True when the media endpoint is unavailable. */
	unavailable: boolean;
	/** Current page (1-based). */
	page: number;
	/** Whether more pages are available. */
	hasMore: boolean;
	/** Load the next page. */
	loadMore: () => void;
	/** Refresh / reload from page 1. */
	refresh: () => void;
	/** Current search term. */
	searchTerm: string;
	/** Set the search term (resets to page 1). */
	setSearchTerm: ( term: string ) => void;
	/** Current MIME-type filter. */
	mimeFilter: string;
	/** Set the MIME-type filter (resets to page 1). */
	setMimeFilter: ( mime: string ) => void;
}

const DEBOUNCE_MS = 300;

export function useMediaLibrary(
	options: UseMediaLibraryOptions
): UseMediaLibraryReturn {
	const { apiRoot, nonce, disabled = false } = options;

	const client = useMemo(
		() => new MediaClient( { apiRoot, nonce } ),
		[ apiRoot, nonce ]
	);

	const [ items, setItems ] = useState< MediaItem[] | null >( null );
	const [ total, setTotal ] = useState< number >( 0 );
	const [ totalPages, setTotalPages ] = useState< number >( 1 );
	const [ page, setPage ] = useState< number >( 1 );
	const [ isLoading, setIsLoading ] = useState< boolean >( false );
	const [ isLoadingMore, setIsLoadingMore ] = useState< boolean >( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ unavailable, setUnavailable ] = useState< boolean >( false );
	const [ searchTerm, setSearchTermRaw ] = useState< string >( '' );
	const [ mimeFilter, setMimeFilterRaw ] = useState< string >( '' );
	const [ debouncedSearch, setDebouncedSearch ] = useState< string >( '' );

	const abortRef = useRef< AbortController | null >( null );
	const debounceRef = useRef< ReturnType< typeof setTimeout > | null >( null );

	// Debounce search input.
	const setSearchTerm = useCallback( ( term: string ) => {
		setSearchTermRaw( term );
		if ( debounceRef.current ) {
			clearTimeout( debounceRef.current );
		}
		debounceRef.current = setTimeout( () => {
			setDebouncedSearch( term.trim() );
		}, DEBOUNCE_MS );
	}, [] );

	const setMimeFilter = useCallback( ( mime: string ) => {
		setMimeFilterRaw( mime );
	}, [] );

	// When search or filter changes, reset to page 1.
	useEffect( () => {
		if ( debouncedSearch !== '' || mimeFilter !== '' ) {
			setPage( 1 );
			setItems( null );
		}
	}, [ debouncedSearch, mimeFilter ] );

	// Fetch data.
	const fetchPage = useCallback(
		async ( pageNum: number, append: boolean ) => {
			if ( disabled ) {
				setItems( [] );
				return;
			}

			abortRef.current?.abort();
			const controller = new AbortController();
			abortRef.current = controller;

			if ( append ) {
				setIsLoadingMore( true );
			} else {
				setIsLoading( true );
			}
			setError( null );

			try {
				const data = await client.list(
					pageNum,
					debouncedSearch,
					mimeFilter,
					controller.signal
				);

				if ( controller.signal.aborted ) return;

				setItems( ( prev ) => {
					if ( append && prev ) {
						// Deduplicate by ID.
						const existingIds = new Set( prev.map( ( i ) => i.id ) );
						const newOnes = data.items.filter( ( i ) => ! existingIds.has( i.id ) );
						return [ ...prev, ...newOnes ];
					}
					return data.items;
				} );
				setTotal( data.total );
				setTotalPages( data.totalPages );
				setUnavailable( false );
			} catch ( err ) {
				if ( controller.signal.aborted ) return;
				const msg = err instanceof Error ? err.message : String( err );
				if ( msg.includes( '404' ) || msg.includes( 'not found' ) ) {
					setUnavailable( true );
					setItems( [] );
				} else {
					setError( msg );
					if ( ! append ) setItems( [] );
				}
			} finally {
				if ( ! controller.signal.aborted ) {
					setIsLoading( false );
					setIsLoadingMore( false );
				}
			}
		},
		[ client, disabled, debouncedSearch, mimeFilter ]
	);

	// Initial fetch + refetch when page / search / filter changes.
	useEffect( () => {
		void fetchPage( page, page > 1 );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ page, fetchPage ] );

	const loadMore = useCallback( () => {
		if ( ! isLoadingMore && ! isLoading && page < totalPages ) {
			setPage( ( p ) => p + 1 );
		}
	}, [ isLoadingMore, isLoading, page, totalPages ] );

	const refresh = useCallback( () => {
		abortRef.current?.abort();
		setPage( 1 );
		setItems( null );
		setError( null );
	}, [] );

	const hasMore = page < totalPages;

	// Cleanup on unmount.
	useEffect( () => {
		return () => {
			abortRef.current?.abort();
			if ( debounceRef.current ) clearTimeout( debounceRef.current );
		};
	}, [] );

	return {
		items,
		total,
		isLoading,
		isLoadingMore,
		error,
		unavailable,
		page,
		hasMore,
		loadMore,
		refresh,
		searchTerm,
		setSearchTerm,
		mimeFilter,
		setMimeFilter,
	};
}
