/**
 * useProducts – Product catalog hook
 *
 * Fetches products via `wooFetch()`, which automatically routes through the
 * local WooCommerce tools or the `remote_wp_connection` tool depending on
 * the `wooSource` / `wooConnectionId` config set by PHP.
 *
 * Data loading is deferred until `authReady` (from TMAContext) is `true` so
 * that tool-execution requests carry the TMA session token.
 *
 * @package WP_MCP_AI
 * @since   1.1.5
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { wooFetch } from '../api/client';
import { useTMA } from '../context/TMAContext';

/**
 * @param {{ search?: string, categoryId?: number|string, perPage?: number, orderby?: string, order?: string }} params
 * @return {{ products:object[], loading:boolean, error:string|null, reload:Function }}
 */
export function useProducts( params = {} ) {
	const { authReady } = useTMA();
	const [ products, setProducts ] = useState( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const timer = useRef( null );

	const load = useCallback( () => {
		if ( ! authReady ) {
			return;
		}
		clearTimeout( timer.current );
		timer.current = setTimeout( async () => {
			setLoading( true );
			setError( null );
			try {
				const list = await wooFetch( 'get_wc_products', {
					per_page: params.perPage ?? 20,
					search:   params.search ?? '',
					category: params.categoryId ? String( params.categoryId ) : '',
					orderby:  params.orderby ?? '',
					order:    params.order ?? '',
				} );
				setProducts( Array.isArray( list ) ? list : [] );
			} catch ( err ) {
				setError( err.message );
			} finally {
				setLoading( false );
			}
		}, 350 );
	}, [ authReady, params.search, params.categoryId, params.perPage, params.orderby, params.order ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		load();
		return () => clearTimeout( timer.current );
	}, [ load ] );

	return { products, loading, error, reload: load };
}


/** @typedef {{ id:number, name:string, price:string, regular_price:string, sale_price:string, image:string, category:string, stock_status:string, short_description:string, type:string }} Product */

/**
 * @param {{ search?: string, categoryId?: number|string, perPage?: number, orderby?: string, order?: string }} params
 * @return {{ products:Product[], loading:boolean, error:string|null, reload:Function }}
 */
