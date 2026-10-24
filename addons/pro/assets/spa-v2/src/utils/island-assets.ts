/**
 * Island asset loader + hash guard for self-contained island apps.
 *
 * Media Studio and Document Editor ship as large self-contained IIFE bundles
 * (their own React/dependency trees — 2.7MB and 1.7MB respectively). They are
 * NOT inlined into the Pro SPA bundle: the pages load them lazily as
 * `<script>`/`<link>` tags from the asset URLs the runtime config supplies,
 * once per session.
 *
 * The guard keeps island-internal skip links (`a.nvoos-skip-link[href^="#"]`)
 * from writing the Pro SPA's `window.location.hash`.
 */

import { useEffect, type RefObject } from 'react';

const loadedScripts = new Set< string >();
const loadedStyles = new Set< string >();

/**
 * Load an island JS bundle exactly once per session.
 *
 * @param src Script URL.
 * @return Promise resolving when the script has executed (or was already loaded).
 */
export function loadIslandScript( src: string ): Promise< void > {
	return new Promise( ( resolve, reject ) => {
		if ( loadedScripts.has( src ) ) {
			resolve();
			return;
		}
		const script = document.createElement( 'script' );
		script.src = src;
		script.async = true;
		script.onload = () => {
			loadedScripts.add( src );
			resolve();
		};
		script.onerror = () => reject( new Error( `Failed to load ${ src }` ) );
		document.head.appendChild( script );
	} );
}

/**
 * Load an island stylesheet exactly once per session.
 *
 * @param href Stylesheet URL.
 */
export function loadIslandStyle( href: string ): void {
	if ( ! href || loadedStyles.has( href ) ) {
		return;
	}
	const link = document.createElement( 'link' );
	link.rel = 'stylesheet';
	link.href = href;
	document.head.appendChild( link );
	loadedStyles.add( href );
}

/**
 * Intercept island-internal skip links so they never write the Pro SPA's
 * `window.location.hash` (which would unmount the page). The link's target
 * element receives focus instead.
 *
 * @param ref Container ref the island renders into.
 */
export function useIslandSkipLinkGuard( ref: RefObject< HTMLElement | null > ): void {
	useEffect( () => {
		const container = ref.current;
		if ( ! container ) {
			return;
		}
		const onClick = ( event: MouseEvent ) => {
			const target = event.target as Element | null;
			const anchor = target?.closest?.( 'a.nvoos-skip-link[href^="#"]' );
			if ( ! anchor ) {
				return;
			}
			event.preventDefault();
			const id = anchor.getAttribute( 'href' )?.slice( 1 );
			if ( id ) {
				document.getElementById( id )?.focus();
			}
		};
		container.addEventListener( 'click', onClick, true );
		return () => container.removeEventListener( 'click', onClick, true );
	}, [ ref ] );
}
