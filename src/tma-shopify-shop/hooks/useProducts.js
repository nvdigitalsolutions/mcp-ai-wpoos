/**
 * useProducts – Shopify product catalog hook
 *
 * Fetches products via the `shopify_products` tool, which routes through the
 * configured Shopify connection. Supports search with 350ms debounce and
 * collection/type filtering.
 *
 * Data loading is deferred until `authReady` (from TMAContext) is `true` so
 * that tool-execution requests carry the TMA session token.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { getProducts } from '../api/client';
import { useTMA } from '../context/TMAContext';

/**
 * @param {{ search?: string, first?: number, productType?: string }} params
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
				const list = await getProducts( {
					first:       params.first ?? 20,
					search:      params.search ?? '',
					productType: params.productType ?? '',
				} );
				setProducts( Array.isArray( list ) ? list : [] );
			} catch ( err ) {
				setError( err.message );
			} finally {
				setLoading( false );
			}
		}, 350 );
	}, [ authReady, params.search, params.first, params.productType ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		load();
		return () => clearTimeout( timer.current );
	}, [ load ] );

	return { products, loading, error, reload: load };
}
