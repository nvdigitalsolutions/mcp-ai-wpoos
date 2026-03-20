/**
 * useProduct – Single product detail hook
 *
 * Fetches a single Shopify product by GID via the `shopify_products` tool.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useEffect } from '@wordpress/element';
import { getProduct } from '../api/client';

/**
 * @param {string|null} productId Shopify product GID.
 * @return {{ product:object|null, loading:boolean, error:string|null }}
 */
export function useProduct( productId ) {
	const [ product, setProduct ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ]     = useState( null );

	useEffect( () => {
		if ( ! productId ) {
			return;
		}
		let cancelled = false;
		setLoading( true );
		setError( null );

		getProduct( productId )
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
