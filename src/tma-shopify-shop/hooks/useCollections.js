/**
 * useCollections – Shopify collections hook
 *
 * Fetches Shopify collections via the `shopify_products` tool with the
 * `collections` action. Results are cached in module scope for the lifetime
 * of the Mini App session.
 *
 * Data loading is deferred until `authReady` (from TMAContext) is `true` so
 * that tool-execution requests carry the TMA session token.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

import { useState, useEffect } from 'react';
import { getCollections } from '../api/client';
import { useTMA } from '../context/TMAContext';

/** @type {object[]|null} Simple module-level cache. */
let cached = null;

/**
 * @return {{ collections:object[], loading:boolean, error:string|null }}
 */
export function useCollections() {
	const { authReady } = useTMA();
	const [ collections, setCollections ] = useState( cached ?? [] );
	const [ loading, setLoading ] = useState( ! cached );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		if ( cached || ! authReady ) {
			return;
		}
		let cancelled = false;
		getCollections( { first: 25 } )
			.then( ( list ) => {
				if ( ! cancelled ) {
					const arr = Array.isArray( list ) ? list : [];
					cached = arr;
					setCollections( arr );
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
	}, [ authReady ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return { collections, loading, error };
}
