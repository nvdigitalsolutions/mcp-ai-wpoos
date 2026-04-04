/**
 * useCategories – Product categories hook
 *
 * Fetches product categories via `wooFetch()`. Routes through local Store
 * API or remote_wp_connection depending on the configured data source.
 * Results are cached in module scope for the lifetime of the Mini App session.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useEffect } from '@wordpress/element';
import { wooFetch } from '../api/client';

/** @type {object[]|null} Simple module-level cache. */
let cached = null;

/**
 * @return {{ categories:object[], loading:boolean, error:string|null }}
 */
export function useCategories() {
	const [ categories, setCategories ] = useState( cached ?? [] );
	const [ loading, setLoading ] = useState( ! cached );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( cached ) {
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
	}, [] );

	return { categories, loading, error };
}


/** @type {object[]|null} Simple module-level cache. */
