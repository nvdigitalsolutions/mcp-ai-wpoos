/**
 * NV oOS Pro SPA v2 — Entry Point.
 *
 * Mounts the React app into the #wp-mcp-ai-pro-spa-root container
 * in the WordPress admin. Supports multi-instance via data-config attribute.
 *
 * @since 2.0.0
 */

import { createRoot } from 'react-dom/client';
import { App } from './App';
import './styles/main.css';
import './styles/drawers.css';

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

function mountAll() {
	const containers = document.querySelectorAll< HTMLElement >(
		'#wp-mcp-ai-pro-spa-root, [class*="-root"][data-config]'
	);

	if ( containers.length === 0 ) {
		return;
	}

	containers.forEach( ( container ) => {
		try {
			const root = createRoot( container );
			root.render( <App /> );
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
