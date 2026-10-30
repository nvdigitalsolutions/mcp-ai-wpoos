/**
 * NV oOS Media Studio — entry point.
 *
 * Mounts the React app into every `.nvoos-media-studio-root[data-config]`
 * container on the page.
 *
 * @since 0.1.0
 */

import { createRoot } from 'react-dom/client';
import { App } from './App';
import type { MediaMode } from './App';
import './styles/main.css';

// Load @axe-core/react in development builds for live accessibility audit output.
// esbuild replaces process.env.NODE_ENV with "production" in prod builds,
// making this block dead code that is eliminated by tree-shaking.
if ( process.env.NODE_ENV !== 'production' ) {
	Promise.all( [
		import( 'react' ),
		import( 'react-dom' ),
		import( '@axe-core/react' ),
	] ).then( ( [ React, ReactDOM, axe ] ) => {
		axe.default( React, ReactDOM, 1000 );
	} ).catch( () => { /* axe unavailable */ } );
}

interface BootstrapConfig {
	toolkit?: string;
	theme?: 'auto' | 'light' | 'dark';
	height?: string;
	mode?: MediaMode;
	src?: string;
	/**
	 * Host islands (the Pro SPA) set this so the entry point's auto-mount
	 * scan skips; the host mounts explicitly via mountMediaStudio().
	 */
	defer_mount?: boolean;
}

interface BootstrapGlobal {
	apiUrl: string;
	nonce: string;
	config: BootstrapConfig;
}

declare global {
	interface Window {
		NVOOS_MEDIA_STUDIO?: BootstrapGlobal;
	}
}

function mountInto( container: HTMLElement ): { unmount: () => void } {
	let config: BootstrapConfig = {};
	try {
		config = JSON.parse( container.dataset.config ?? '{}' ) as BootstrapConfig;
	} catch {
		container.textContent = 'Configuration error.';
		return { unmount: () => {} };
	}
	const root = createRoot( container );
	root.render( <App config={ config } /> );
	return { unmount: () => root.unmount() };
}

function mountAll(): void {
	if ( window.NVOOS_MEDIA_STUDIO?.config?.defer_mount ) {
		return;
	}
	document
		.querySelectorAll<HTMLElement>( '.nvoos-media-studio-root[data-config]' )
		.forEach( ( container ) => mountInto( container ) );
}

/**
 * Mount the Media Studio app into a host-provided container element.
 *
 * Imperative island API for host apps (the NV oOS Pro SPA): the host owns the
 * container's lifecycle and calls the returned `unmount` before removing it.
 * The host must inject `window.NVOOS_MEDIA_STUDIO` (apiUrl + nonce + optional
 * `authorization` for headless modes) before mounting.
 *
 * @param container Root element the app renders into.
 * @return Handle with `unmount` for React root teardown.
 */
export function mountMediaStudio( container: HTMLElement ): { unmount: () => void } {
	return mountInto( container );
}

// Expose the island API on the global for host apps (the built bundle is a
// plain IIFE with no module system; no esbuild `globalName` by design).
( globalThis as unknown as { NVoosMediaStudio?: unknown } ).NVoosMediaStudio = { mountMediaStudio };

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountAll );
} else {
	mountAll();
}

