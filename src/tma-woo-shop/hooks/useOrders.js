/**
 * useOrders – Order history hook
 *
 * Fetches recent orders via `wooFetch()`. Routes through local WooCommerce
 * tools or remote_wp_connection depending on the configured data source.
 *
 * Data loading is deferred until `authReady` (from TMAContext) is `true` so
 * that tool-execution requests carry the TMA session token.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useEffect, useCallback } from 'react';
import { wooFetch } from '../api/client';
import { useTMA } from '../context/TMAContext';

/**
 * @param {{ perPage?: number }} params
 * @return {{ orders:object[], loading:boolean, error:string|null, reload:Function }}
 */
export function useOrders( params = {} ) {
	const { authReady } = useTMA();
	const [ orders, setOrders ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const load = useCallback( async () => {
		if ( ! authReady ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const list = await wooFetch( 'get_wc_orders', {
				per_page: params.perPage ?? 10,
			} );
			setOrders( Array.isArray( list ) ? list : [] );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setLoading( false );
		}
	}, [ authReady, params.perPage ] );

	useEffect( () => {
		load();
	}, [ load ] );

	return { orders, loading, error, reload: load };
}


/**
 * @param {{ perPage?: number }} params
 * @return {{ orders:object[], loading:boolean, error:string|null, reload:Function }}
 */
