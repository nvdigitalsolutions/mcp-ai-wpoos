/**
 * useOrders – Order history hook
 *
 * Fetches recent orders via the `get_woo_recent_orders` plugin tool.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import { executeTool } from '../api/client';

/**
 * @param {{ perPage?: number }} params
 * @return {{ orders:object[], loading:boolean, error:string|null, reload:Function }}
 */
export function useOrders( params = {} ) {
	const [ orders, setOrders ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );

	const load = useCallback( async () => {
		setLoading( true );
		setError( null );
		try {
			const data = await executeTool( 'get_woo_recent_orders', {
				per_page: params.perPage ?? 10,
			} );
			const list = data?.data?.orders ?? data?.orders ?? [];
			setOrders( list );
		} catch ( err ) {
			setError( err.message );
		} finally {
			setLoading( false );
		}
	}, [ params.perPage ] );

	useEffect( () => {
		load();
	}, [ load ] );

	return { orders, loading, error, reload: load };
}
