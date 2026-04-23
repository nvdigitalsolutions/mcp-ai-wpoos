/**
 * useCategories – Product categories hook
 *
 * Fetches product categories via `wooFetch()`. Routes through local Store
 * API or remote_wp_connection depending on the configured data source.
 * Results are cached in module scope for the lifetime of the Mini App session.
 *
 * Data loading is deferred until `authReady` (from TMAContext) is `true` so
 * that tool-execution requests carry the TMA session token.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useEffect } from 'react';
import { wooFetch } from '../api/client';
import { useTMA } from '../context/TMAContext';

/** @type {object[]|null} Simple module-level cache. */
let cached = null;

/**
 * @return {{ categories:object[], loading:boolean, error:string|null }}
 */
export function useCategories() {
	const { authReady } = useTMA();
	const [ categories, setCategories ] = useState( cached ?? [] );
	const [ loading, setLoading ] = useState( ! cached );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( cached || ! authReady ) {
			return;
		}
		let cancelled = false;
		wooFetch( 'get_wc_categories', { per_page: 50 } )
			.then( ( list ) => {
				if ( ! cancelled ) {
					const arr = Array.isArray( list ) ? list : [];
					cached = arr;
					setCategories( arr );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setError( err.message );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ authReady ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return { categories, loading, error };
}


/** @type {object[]|null} Simple module-level cache. */
