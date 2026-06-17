/**
 * NV oOS Toolkit Shell — entry point.
 *
 * Mounts the React app into every `.nvoos-toolkit-shell-root[data-config]`
 * container on the page.
 *
 * @since 0.1.0
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

interface BootstrapConfig {
	toolkit?: string;
	theme?: 'auto' | 'light' | 'dark';
	view?: string;
	height?: string;
}

interface BootstrapGlobal {
	apiUrl: string;
	proApi: string;
	baseApi: string;
	nonce: string;
	config: BootstrapConfig;
}

declare global {
	interface Window {
		NVOOS_TOOLKIT_SHELL?: BootstrapGlobal;
	}
}

function mountAll(): void {
	const containers = document.querySelectorAll<HTMLElement>(
		'.nvoos-toolkit-shell-root[data-config]'
	);
	containers.forEach( ( container ) => {
		let config: BootstrapConfig = {};
		try {
			config = JSON.parse( container.dataset.config ?? '{}' ) as BootstrapConfig;
		} catch {
			container.textContent = 'Configuration error.';
			return;
		}
		const root = createRoot( container );
		root.render( <App config={ config } /> );
	} );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountAll );
} else {
	mountAll();
}
