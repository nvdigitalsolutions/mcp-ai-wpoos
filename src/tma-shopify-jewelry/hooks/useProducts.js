/**
 * useProducts – Shopify product catalog hook
 *
 * Fetches products via the `shopify_products` tool through the TMA tools
 * endpoint. Debounces search queries by 350 ms.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { getProducts } from '../api/client';

/**
 * @param {{ search?: string, first?: number }} params
 * @return {{ products:object[], loading:boolean, error:string|null, reload:Function }}
 */
export function useProducts( params = {} ) {
	const [ products, setProducts ] = useState( [] );
	const [ loading, setLoading ]   = useState( true );
	const [ error, setError ]       = useState( null );
	const timer                     = useRef( null );

	// Keep the latest params in a ref so the debounced callback always uses
	// the current values without needing to be recreated on every change.
	const paramsRef = useRef( params );
	useEffect( () => {
		paramsRef.current = params;
	} );

	const load = useCallback( () => {
		clearTimeout( timer.current );
		timer.current = setTimeout( async () => {
			const { search = '', first = 20 } = paramsRef.current;
			setLoading( true );
			setError( null );
			try {
				const list = await getProducts( { search, first } );
				setProducts( list );
			} catch ( err ) {
				setError( err.message );
			} finally {
				setLoading( false );
			}
		}, 350 );
	}, [] ); // stable – reads params from ref inside the timeout.

	useEffect( () => {
		// Re-trigger whenever search or first changes.
		load();
		return () => clearTimeout( timer.current );
	}, [ load, params.search, params.first ] );

	return { products, loading, error, reload: load };
}
