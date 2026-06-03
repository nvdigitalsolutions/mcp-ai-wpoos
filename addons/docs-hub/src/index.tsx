/**
 * Entry point — mounts the Docs Hub SPA into all matching root elements.
 *
 * The shortcode / block render a `<div class="nvoos-docs-hub-root" data-config="...">`.
 * This script finds all such elements and mounts a React root into each one.
 *
 * @since 1.0.0
 */

import { createRoot } from 'react-dom/client';
import App from './App';
import './styles/main.css';

// TypeScript: declare process.env for esbuild define replacement.
// esbuild replaces process.env.NODE_ENV with "production" in prod builds.
declare const process: { env: { NODE_ENV: string } };

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

function mount() {
	const containers = document.querySelectorAll<HTMLElement>( '.nvoos-docs-hub-root[data-config]' );

	containers.forEach( ( container ) => {
		// Parse config from data attribute and merge into the global.
		try {
			const rawConfig = container.dataset.config ?? '{}';
			const config = JSON.parse( rawConfig ) as Record<string, string>;

			// Expose config on window so API client / App can read it.
			if ( ! window.NVOOS_DOCS_HUB ) {
				// wp_localize_script should have set this; create a fallback
				// using the api_url baked into the data-config attribute by
				// the PHP shortcode. This ensures the correct REST base URL
				// is used even on sites with a custom REST prefix.
				window.NVOOS_DOCS_HUB = {
					apiUrl: config.api_url ?? `${ window.location.origin }/wp-json/nvoos-docs/v1`,
					nonce: config.nonce ?? '',
					config: {
						section: config.section,
						theme: config.theme,
						search: config.search,
						sidebar: config.sidebar,
						home: config.home,
						api_url: config.api_url,
					},
				};
			}
		} catch {
			// Invalid JSON in data-config — proceed with defaults.
		}

		const root = createRoot( container );
		root.render( <App /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
