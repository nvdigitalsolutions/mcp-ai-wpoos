/**
 * useOrders – Shopify order history hook
 *
 * Fetches recent orders via the `shopify_orders` tool.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useEffect, useCallback } from 'react';
import { getOrders } from '../api/client';

/**
 * @param {{ first?: number }} params
 * @return {{ orders:object[], loading:boolean, error:string|null, reload:Function }}
 */
export function useOrders( params = {} ) {
	const [ orders, setOrders ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ]     = useState( null );

	const load = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const list = await getOrders( { first: params.first ?? 10 } );
			setOrders( list );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setLoading( false );
		}
	}, [ params.first ] );

	useEffect( () => {
		load();
	}, [ load ] );

	return { orders, loading, error, reload: load };
}
