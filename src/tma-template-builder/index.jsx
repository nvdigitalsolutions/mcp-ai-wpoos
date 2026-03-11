/**
 * TMA Template Builder – Main Entry Point
 *
 * Renders the Mini App Template Builder admin UI inside WordPress.
 * The component embeds into the div#mcp-ai-tma-template-builder-root element
 * injected by the Chat Channels settings page.
 *
 * React Cosmos is used for isolated component development:
 *   npm run cosmos:tma   → opens Cosmos playground at http://localhost:5001
 *
 * @package WP_MCP_AI
 * @since   1.1.3
 */

import { createRoot } from '@wordpress/element';
import { TMATemplateBuilder } from './components/TMATemplateBuilder';
import './styles/tma-template-builder.css';

/**
 * Bootstrap the React app when the DOM is ready.
 */
const init = () => {
	const root = document.getElementById( 'mcp-ai-tma-template-builder-root' );
	if ( ! root ) {
		return;
	}

	// Config is passed from PHP via a data attribute on the mount point.
	const config = {
		ajaxUrl: root.dataset.ajaxUrl || '',
		nonce: root.dataset.nonce || '',
		templatesUrl: root.dataset.templatesUrl || '',
		saveUrl: root.dataset.saveUrl || '',
		activeTemplate: root.dataset.activeTemplate || 'default',
		previewBaseUrl: root.dataset.previewBaseUrl || '',
	};

	createRoot( root ).render(
		<TMATemplateBuilder config={ config } />
	);
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', init );
} else {
	init();
}
