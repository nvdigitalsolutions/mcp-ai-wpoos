/**
 * useProducts – Shopify product catalog hook
 *
 * Fetches products via the `shopify_products` tool through the TMA tools
 * endpoint. Debounces search queries by 350 ms.
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
 * @param {{ search?: string, first?: number }} params
 * @return {{ products:object[], loading:boolean, error:string|null, reload:Function }}
 */
export function useProducts( params = {} ) {
	const { authReady } = useTMA();
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
		if ( ! authReady ) {
			return;
		}
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
	}, [ authReady ] ); // stable – reads params from ref inside the timeout.

	useEffect( () => {
		// Re-trigger whenever search or first changes.
		load();
		return () => clearTimeout( timer.current );
	}, [ load, params.search, params.first ] );

	return { products, loading, error, reload: load };
}
