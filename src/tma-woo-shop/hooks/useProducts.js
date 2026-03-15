/**
 * useProducts – Product catalog hook
 *
 * Fetches products via the `get_woo_products` tool. Supports search query,
 * category ID, per_page limit, and sort parameters.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { executeTool } from '../api/client';

/** @typedef {{ id:number, name:string, price:string, regular_price:string, sale_price:string, image:string, category:string, stock_status:string, short_description:string, type:string }} Product */

/**
 * @param {{ search?: string, categoryId?: number|string, perPage?: number, orderby?: string, order?: string }} params
 * @return {{ products:Product[], loading:boolean, error:string|null, reload:Function }}
 */
export function useProducts( params = {} ) {
	const [ products, setProducts ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	// Debounce rapid filter changes.
	const timer = useRef( null );

	const load = useCallback( () => {
		clearTimeout( timer.current );
		timer.current = setTimeout( async () => {
			setLoading( true );
			setError( null );
			try {
				const args = {
					limit: params.perPage ?? 20,
				};
				if ( params.search ) {
					args.search = params.search;
				}
				if ( params.categoryId ) {
					args.category = String( params.categoryId );
				}
				if ( params.orderby ) {
					args.orderby = params.orderby;
				}
				if ( params.order ) {
					args.order = params.order;
				}
				const data = await executeTool( 'get_woo_products', args );
				const list = data?.data?.products ?? data?.products ?? [];
				setProducts( list );
			} catch ( err ) {
				setError( err.message );
			} finally {
				setLoading( false );
			}
		}, 350 );
	}, [ params.search, params.categoryId, params.perPage, params.orderby, params.order ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		load();
		return () => clearTimeout( timer.current );
	}, [ load ] );

	return { products, loading, error, reload: load };
}
