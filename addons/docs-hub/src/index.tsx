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

function mount() {
	const containers = document.querySelectorAll<HTMLElement>( '.nvoos-docs-hub-root[data-config]' );

	containers.forEach( ( container ) => {
		// Parse config from data attribute and merge into the global.
		try {
			const rawConfig = container.dataset.config ?? '{}';
			const config = JSON.parse( rawConfig ) as Record<string, string>;

			// Expose config on window so API client / App can read it.
			if ( ! window.NVOOS_DOCS_HUB ) {
				// wp_localize_script should have set this; create a fallback.
				window.NVOOS_DOCS_HUB = {
					apiUrl: config.api_url ?? '/wp-json/nvoos-docs/v1',
					nonce: config.nonce ?? '',
					config: {
						section: config.section,
						theme: config.theme,
						search: config.search,
						sidebar: config.sidebar,
						home: config.home,
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
