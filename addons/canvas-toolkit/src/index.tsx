/**
 * NV oOS Canvas Toolkit — entry point.
 *
 * Mounts the React app into every `.nvoos-canvas-toolkit-root[data-config]`
 * container on the page.
 *
 * @since 0.1.0
 */

import { createRoot } from 'react-dom/client';
import { App } from './App';
import type { CanvasMode } from './App';
import './styles/main.css';

interface BootstrapConfig {
	toolkit?: string;
	theme?: 'auto' | 'light' | 'dark';
	view?: string;
	height?: string;
	mode?: CanvasMode;
}

interface BootstrapGlobal {
	apiUrl: string;
	proApi: string;
	nonce: string;
	config: BootstrapConfig;
}

declare global {
	interface Window {
		NVOOS_CANVAS_TOOLKIT?: BootstrapGlobal;
	}
}

function mountAll(): void {
	const containers = document.querySelectorAll<HTMLElement>(
		'.nvoos-canvas-toolkit-root[data-config]'
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

