/**
 * NV oOS Pro SPA v2 — Entry Point.
 *
 * Mounts the React app into the #wp-mcp-ai-pro-spa-root container
 * in the WordPress admin. Supports multi-instance via data-config attribute:
 * the [nvoos_pro_spa] shortcode emits <div class="nvoos-pro-spa-root"
 * data-config="..."> and mounts the router-free EmbeddedApp, while the admin
 * page (no data-config) mounts the full App.
 *
 * @since 2.0.0
 */

import { createRoot } from 'react-dom/client';
import { App } from './App';
import { EmbeddedApp } from './features/embedded/EmbeddedApp';
import { readProSpaConfig, applyPerInstanceConfig } from './api/config';
import './styles/main.css';
import './styles/drawers.css';
import './styles/embedded.css';

declare global {
	interface Window {
		[ key: string ]: unknown;
	}
}

// Load @axe-core/react in development builds for live accessibility audit output.
// esbuild replaces process.env.NODE_ENV with "production" in prod builds,
// making this block dead code that is eliminated by tree-shaking.
if ( process.env.NODE_ENV !== 'production' ) {
	Promise.all( [
		import( 'react' ),
		import( 'react-dom' ),
		import( '@axe-core/react' ),
	] )
		.then( ( [ React, ReactDOM, axe ] ) => {
			axe.default( React, ReactDOM, 1000 );
		} )
		.catch( () => {
			/* axe unavailable — non-critical */
		} );
}

/**
 * Parse the container's data-config attribute (shortcode per-instance JSON).
 *
 * @param container Mount container element.
 * @return Parsed object or null when absent/malformed.
 */
function readPerInstanceConfig( container: HTMLElement ): unknown {
	const attr = container.getAttribute( 'data-config' );
	if ( ! attr ) {
		return null;
	}
	try {
		return JSON.parse( attr );
	} catch {
		return null;
	}
}

function mountAll() {
	const containers = document.querySelectorAll< HTMLElement >(
		'#wp-mcp-ai-pro-spa-root, [class*="-root"][data-config]'
	);

	if ( containers.length === 0 ) {
		return;
	}

	containers.forEach( ( container ) => {
		try {
			const perInstance = readPerInstanceConfig( container );

			// Overlay the shortcode's per-instance config onto the shared
			// NVOOS_PRO_SPA global so every hook sees the merged runtime.
			// (v1 supports a single embedded instance per page.)
			const runtime = perInstance
				? applyPerInstanceConfig( perInstance )
				: readProSpaConfig();

			const root = createRoot( container );

			if ( runtime?.config?.mode === 'embedded' ) {
				root.render( <EmbeddedApp /> );
			} else {
				root.render( <App /> );
			}
		} catch {
			container.textContent = 'Configuration error. Unable to mount Pro SPA.';
		}
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountAll );
} else {
	mountAll();
}
