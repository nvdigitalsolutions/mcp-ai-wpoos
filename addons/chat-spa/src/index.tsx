/**
 * NV oOS Toolkit SPA — entry point.
 *
 * Mounts the React app into every matching root container on the page.
 */

import { createRoot } from 'react-dom/client';
import { App } from './App';
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

declare global {
	interface Window {
		// Each addon localizes its own global; the App reads window[GLOBAL_NAME].
		[key: string]: unknown;
	}
}

function mountAll() {
	const containers = document.querySelectorAll<HTMLElement>(
		// The root selector is templated per-addon by the scaffold script.
		// See the .nvoos-<slug>-root class in the shortcode renderer.
		'[class*="-root"][data-config]'
	);
	containers.forEach( ( container ) => {
		try {
			const raw = container.dataset.config ?? '{}';
			const config = JSON.parse( raw );
			const root = createRoot( container );
			root.render( <App config={ config } /> );
		} catch {
			// Invalid JSON in data-config — render fallback.
			container.textContent = 'Configuration error.';
		}
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountAll );
} else {
	mountAll();
}
