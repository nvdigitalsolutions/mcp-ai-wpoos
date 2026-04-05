/**
 * useProduct – Single product detail hook
 *
 * Fetches a single product by ID via `wooFetch()`. Routes through local
 * Store API or remote_wp_connection depending on the configured data source.
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

/**
 * @param {number|null} productId
 * @return {{ product:object|null, loading:boolean, error:string|null }}
 */
export function useProduct( productId ) {
	const { authReady } = useTMA();
	const [ product, setProduct ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( ! productId || ! authReady ) {
			return;
		}
		let cancelled = false;
		setLoading( true );
		setError( null );

		wooFetch( 'get_wc_product', { product_id: productId } )
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
	}, [ productId, authReady ] );

	return { product, loading, error };
}


/**
 * @param {number|null} productId
 * @return {{ product:object|null, loading:boolean, error:string|null }}
 */
