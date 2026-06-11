/**
 * NV oOS LibreChat — React SPA Entry Point
 *
 * Mounts the LibreChat React UI onto elements with the `.nvoos-librechat-root`
 * class. Reads configuration from `data-config` attribute and endpoints from
 * the global `NVOOS_LIBRECHAT` object (set via wp_localize_script).
 *
 * @package NV_oOS_LibreChat
 * @since   0.1.0
 */

import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';
import './styles/main.css';

/**
 * Global config injected by wp_localize_script.
 */
declare global {
	interface Window {
		NVOOS_LIBRECHAT: {
			apiUrl: string;
			nonce: string;
			config: {
				assistantId: number;
				theme: string;
				height: string;
				guest: boolean;
				features: {
					codeInterpreter: boolean;
					webSearch: boolean;
					speech: boolean;
					artifacts: boolean;
				};
			};
			endpoints: {
				chat: string;
				transcripts: string;
				memory: string;
				codeExecute: string;
				codeResult: string;
				speechTranscribe: string;
				speechSynthesize: string;
			};
			settings: {
				theme: string;
				codeInterpreterTimeout: number;
				maxExecutionsPerHour: number;
			};
		};
	}
}

function mountAll() {
	const containers = document.querySelectorAll<HTMLElement>( '.nvoos-librechat-root' );
	if ( containers.length === 0 ) {
		return;
	}

	const globalConfig = window.NVOOS_LIBRECHAT || {};

	containers.forEach( ( container ) => {
		if ( container.hasAttribute( 'data-nvoos-librechat-mounted' ) ) {
			return;
		}

		let instanceConfig = {};
		try {
			const raw = container.getAttribute( 'data-config' );
			if ( raw ) {
				instanceConfig = JSON.parse( raw );
			}
		} catch ( e ) {
			// Ignore parse errors.
		}

		const config = {
			...globalConfig.config,
			...instanceConfig,
		};

		const root = createRoot( container );
		root.render(
			React.createElement( App, {
				config,
				apiUrl: globalConfig.apiUrl || '',
				nonce: globalConfig.nonce || '',
				endpoints: globalConfig.endpoints || {},
			} )
		);

		container.setAttribute( 'data-nvoos-librechat-mounted', '1' );
	} );
}

// Mount on DOM ready.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountAll );
} else {
	mountAll();
}
