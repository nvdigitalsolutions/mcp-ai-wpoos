/**
 * useCategories – Product categories hook
 *
 * Fetches product categories from the WooCommerce Store API.
 * Results are cached in module scope for the lifetime of the Mini App session.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useEffect } from '@wordpress/element';
import { storeApi } from '../api/client';

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
		storeApi( '/wc/store/v1/products/categories?per_page=50' )
			.then( ( data ) => {
				if ( ! cancelled ) {
					const list = Array.isArray( data ) ? data : [];
					cached = list;
					setCategories( list );
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
