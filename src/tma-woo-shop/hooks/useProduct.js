/**
 * useProduct – Single product detail hook
 *
 * Fetches a single product by ID via the WooCommerce Store API (public,
 * no auth required for published products).
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useEffect } from '@wordpress/element';
import { storeApi } from '../api/client';

/**
 * @param {number|null} productId
 * @return {{ product:object|null, loading:boolean, error:string|null }}
 */
export function useProduct( productId ) {
	const [ product, setProduct ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! productId ) {
			return;
		}
		let cancelled = false;
		setLoading( true );
		setError( null );

		storeApi( `/wc/store/v1/products/${ productId }` )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setProduct( data );
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
	}, [ productId ] );

	return { product, loading, error };
}
